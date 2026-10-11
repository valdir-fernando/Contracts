FROM contracts-app:latest AS dependencies
COPY . /var/www/html
RUN mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions storage/logs bootstrap/cache \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

FROM node:22-bookworm-slim AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
COPY --from=dependencies /var/www/html/vendor ./vendor
RUN npm run build

FROM contracts-app:latest AS app
COPY --from=dependencies /var/www/html /var/www/html
COPY --from=assets /build/public/build /var/www/html/public/build
COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
ENV APP_ENV=production APP_DEBUG=false

FROM nginx:stable-alpine AS web
COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
