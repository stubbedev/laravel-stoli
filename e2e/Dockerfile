# A real Laravel application with Stoli installed from this working copy, for the
# end-to-end scenarios in run.sh. Build from the repository root:
#
#     docker build -f e2e/Dockerfile --build-arg PHP_VERSION=8.4 --build-arg LARAVEL_VERSION=12 .

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

FROM node:${NODE_VERSION}-slim AS node

FROM php:${PHP_VERSION}-cli

ARG LARAVEL_VERSION=12
ARG TYPESCRIPT_VERSION=latest

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev procps \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1

WORKDIR /app

RUN composer create-project "laravel/laravel:^${LARAVEL_VERSION}.0" . --no-scripts --prefer-dist \
    && cp .env.example .env \
    && sed -i 's#^APP_URL=.*#APP_URL=http://127.0.0.1:8000#; s#^SESSION_DRIVER=.*#SESSION_DRIVER=array#; s#^CACHE_STORE=.*#CACHE_STORE=array#' .env \
    && php artisan key:generate \
    && npm install --no-audit --no-fund --silent prettier@3 chokidar@3 axios@1

COPY composer.json /package/composer.json
COPY src /package/src
COPY config /package/config
COPY resources /package/resources

RUN composer config repositories.stoli '{"type": "path", "url": "/package", "options": {"symlink": false, "versions": {"stubbedev/laravel-stoli": "1.0.0"}}}' \
    && composer require "stubbedev/laravel-stoli:1.0.0" --prefer-dist

COPY e2e/app /app/

COPY e2e/patch.php /tmp/patch.php
RUN php /tmp/patch.php

COPY e2e/ts /app/e2e-ts
RUN cd e2e-ts && npm install --no-audit --no-fund --silent && npm install --no-audit --no-fund --silent "typescript@${TYPESCRIPT_VERSION}"

COPY e2e/run.sh /run.sh

CMD ["bash", "/run.sh"]
