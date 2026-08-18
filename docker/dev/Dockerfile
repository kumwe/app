# php:8.5-cli-alpine, pinned by digest so a mutable tag cannot change the toolchain underneath us.
FROM php@sha256:0554eb53778b5316f6b9a3447c9dfa3cf2141c0c02ff816c42cdc9aa240a34aa

RUN set -eux; \
    apk add --no-cache bash git icu-libs libpq libzip oniguruma; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        linux-headers \
        oniguruma-dev \
        postgresql-dev \
        libzip-dev; \
    docker-php-ext-install -j"$(getconf _NPROCESSORS_ONLN)" intl mbstring pcntl pdo_mysql pdo_pgsql zip; \
    pecl install redis-6.3.0; \
    docker-php-ext-enable redis; \
    php -r 'foreach (["intl", "mbstring", "Zend OPcache", "pcntl", "pdo_mysql", "pdo_pgsql", "redis", "zip"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: {$extension}\n"); exit(1); } }'; \
    apk del .build-deps

# composer:2, pinned by digest for the same reason as the base image.
COPY --from=composer@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

USER www-data

EXPOSE 8080
