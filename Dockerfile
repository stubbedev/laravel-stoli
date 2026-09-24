FROM php:8.4-cli

# node type-checks the generated TypeScript, see tests/Integration/GeneratedTypeScriptTest.php
RUN apt-get update \
    && apt-get install -y --no-install-recommends nodejs npm \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY . /app
