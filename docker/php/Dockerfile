FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev libonig-dev libsqlite3-dev libpng-dev \
    && docker-php-ext-install -j$(nproc) pdo_mysql pdo_sqlite mbstring intl zip bcmath opcache gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

RUN git config --system --add safe.directory /var/www/html

WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /usr/local/bin/contracts-entrypoint
ENTRYPOINT ["contracts-entrypoint"]
CMD ["php-fpm"]
