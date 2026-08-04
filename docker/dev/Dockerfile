FROM php:8.4-cli-alpine

RUN set -eux; \
    apk add --no-cache bash git icu-libs libpq; \
    apk add --no-cache --virtual .build-deps icu-dev libpq-dev linux-headers; \
    docker-php-ext-install -j"$(getconf _NPROCESSORS_ONLN)" intl opcache pdo_pgsql; \
    apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

EXPOSE 8080
