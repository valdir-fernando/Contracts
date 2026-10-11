#!/bin/sh
set -eu
mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions storage/logs storage/app/private bootstrap/cache
if [ "$(id -u)" = 0 ]; then
    chown -R www-data:www-data storage bootstrap/cache
fi
exec docker-php-entrypoint "$@"
