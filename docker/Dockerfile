ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_sqlite

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && echo "xdebug.mode=coverage" >> /usr/local/etc/php/conf.d/xdebug.ini

RUN git config --global --add safe.directory '*'

WORKDIR /app

COPY composer.json ./

ARG DBAL_CONSTRAINT=^4.0
ARG ORM_CONSTRAINT=^3.0

RUN composer require --no-interaction --no-progress --no-scripts \
        "doctrine/dbal:${DBAL_CONSTRAINT}" \
        "doctrine/orm:${ORM_CONSTRAINT}" \
    && composer install --prefer-dist --no-progress --no-interaction

COPY . .
