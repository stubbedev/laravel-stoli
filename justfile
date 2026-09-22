current_dir := justfile_directory()

image := "php-price-engine"

composer_image := "composer:2.3.7"

# Default is the same as `make` used to be: build the docker image.
default: build

# Build the docker image used to run the test suite.
build: deps
    docker build -t {{image}} .

# Remove the docker image.
clean:
    docker rmi {{image}}

# Run composer install inside the composer container.
composer-install:
    @just composer install

# Run composer update inside the composer container.
composer-update:
    @just composer update

# Run composer require interactively inside the composer container.
composer-require:
    @just composer require '-ti --interactive'

# Run composer inside its container, mounted at /app as the host user. The second
# argument is extra docker run flags, e.g. `just composer update ''`.
composer composer_args='install' docker_flags='':
    docker run --rm {{docker_flags}} --volume {{current_dir}}:/app --user $(id -u):$(id -g) \
        {{composer_image}} {{composer_args}} \
            --ignore-platform-reqs \
            --no-ansi

# Refresh composer dependencies before running the tests.
deps: composer-install

# Run phpunit inside the built image and check the published stub with node.
# Test filtering goes through the environment: FILTER_TEST_OPTIONS=--filter=x just test
test: composer-install test-js
    docker run --rm -v {{current_dir}}:/app -w /app {{image}} vendor/bin/phpunit ${FILTER_TEST_OPTIONS:-} --testdox

# The published stub is plain JS, so it is checked with node instead of phpunit.
test-js:
    node {{current_dir}}/tests/routeservice.test.mjs

# Tag the current commit as the next patch release and push the tag.
release-patch: (release "patch")

# Tag the current commit as the next minor release and push the tag.
release-minor: (release "minor")

# Tag the current commit as the next major release and push the tag.
release-major: (release "major")

# Bump the latest vX.Y.Z tag by `part`, tag HEAD and push the tag. Packagist
# picks the release up from the tag, so this is the whole release.
release part='patch':
    #!/usr/bin/env bash
    set -euo pipefail
    if [ -n "$(git status --porcelain)" ]; then
        echo "Working tree is dirty - commit or stash first." >&2
        exit 1
    fi
    git fetch --tags --quiet
    latest=$(git tag --list 'v*' --sort=-v:refname | head -n 1)
    latest=${latest:-v0.0.0}
    IFS=. read -r major minor patch <<< "${latest#v}"
    case "{{part}}" in
        patch) patch=$((patch + 1)) ;;
        minor) minor=$((minor + 1)); patch=0 ;;
        major) major=$((major + 1)); minor=0; patch=0 ;;
        *) echo "Unknown release part: {{part}}" >&2; exit 1 ;;
    esac
    next="v$major.$minor.$patch"
    echo "Releasing $next (previous $latest)"
    git tag -a "$next" -m "$next"
    git push origin "$next"
