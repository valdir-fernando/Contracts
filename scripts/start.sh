#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f .env ] || cp .env.example .env
docker compose build app
docker compose run --rm --no-deps app composer install --no-interaction
docker compose run --rm --no-deps app sh -c 'if ! grep -Eq "^APP_KEY=.+" .env; then php artisan key:generate; fi'
docker compose up -d --wait db
docker compose run --rm --no-deps app php artisan migrate --force
docker compose run --rm --no-deps node sh -c 'npm ci && npm run build'
docker compose up -d app web worker scheduler
printf 'Aplicação disponível em http://127.0.0.1:8000\n'
