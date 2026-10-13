# Test-only image; build the local app image first with docker compose build app.
FROM contracts-app:latest

RUN pecl install pcov-1.0.12 && docker-php-ext-enable pcov
