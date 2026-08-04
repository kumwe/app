FROM php:8.5-cli-alpine

RUN set -eux; \
    apk add --no-cache bash git icu-libs libpq libzip oniguruma; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        linux-headers \
        oniguruma-dev \
        postgresql-dev \
        libzip-dev; \
    docker-php-ext-install -j"$(getconf _NPROCESSORS_ONLN)" intl mbstring pdo_mysql pdo_pgsql zip; \
    pecl install redis-6.3.0; \
    docker-php-ext-enable redis; \
    php -r 'foreach (["intl", "mbstring", "Zend OPcache", "pdo_mysql", "pdo_pgsql", "redis", "zip"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: {$extension}\n"); exit(1); } }'; \
    apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

USER www-data

EXPOSE 8080
