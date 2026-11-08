# Toolchain image for scripts/check.sh: PHP 8.4 CLI with the extensions Pelican
# Panel needs for composer install, PHPStan and its Pest test suite.
FROM php:8.4-cli

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip rsync jq \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions bcmath gd intl zip pdo_mysql pdo_sqlite \
    && git config --system --add safe.directory '*'

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1
