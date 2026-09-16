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
