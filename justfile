current_dir := justfile_directory()

image := "laravel-stoli"

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
# Depends on build: the image holds the sources, so it has to exist and be current.
# Test filtering goes through the environment: FILTER_TEST_OPTIONS=--filter=x just test
test: build test-js
    docker run --rm -v {{current_dir}}:/app -w /app {{image}} vendor/bin/phpunit ${FILTER_TEST_OPTIONS:-} --testdox

# The published stub is plain JS, so it is checked with node instead of phpunit.
test-js:
    node {{current_dir}}/tests/routeservice.test.mjs

# Show the next major/minor/patch versions.
release-preview:
    #!/usr/bin/env bash
    set -euo pipefail
    v="$(git tag --list 'v*' --sort=-v:refname | head -n 1)"
    v="${v:-v0.0.0}"
    IFS=. read -r maj min pat <<<"${v#v}"
    echo "current: $v"
    echo "patch:   v$maj.$min.$((pat + 1))"
    echo "minor:   v$maj.$((min + 1)).0"
    echo "major:   v$((maj + 1)).0.0"

release-patch: (release "patch")
release-minor: (release "minor")
release-major: (release "major")

# The latest tag is the single source of truth for the version: composer.json
# carries none and Packagist publishes from the tag.
# Bump it by `level`, run the gates, tag and push.
release level:
    #!/usr/bin/env bash
    set -euo pipefail
    if ! git diff --quiet || ! git diff --cached --quiet; then
        echo "working tree is dirty — commit or stash first" >&2
        exit 1
    fi
    git fetch --tags --quiet
    v="$(git tag --list 'v*' --sort=-v:refname | head -n 1)"
    v="${v:-v0.0.0}"
    IFS=. read -r maj min pat <<<"${v#v}"
    case "{{ level }}" in
        patch) new="$maj.$min.$((pat + 1))" ;;
        minor) new="$maj.$((min + 1)).0" ;;
        major) new="$((maj + 1)).0.0" ;;
        *) echo "unknown level: {{ level }}" >&2; exit 1 ;;
    esac
    echo "releasing $v -> v$new"
    just test
    git tag "v$new"
    git push origin HEAD
    git push origin "v$new"
    echo "released v$new"
