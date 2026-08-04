FROM php:8.4-cli-alpine

RUN set -eux; \
    apk add --no-cache bash git icu-libs libpq libzip oniguruma; \
    apk add --no-cache --virtual .build-deps \
        icu-dev \
        linux-headers \
        oniguruma-dev \
        postgresql-dev \
        libzip-dev; \
    docker-php-ext-install -j"$(getconf _NPROCESSORS_ONLN)" intl mbstring opcache pdo_pgsql pgsql zip; \
    apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

USER www-data

EXPOSE 8080
