param([switch]$HotReload)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)

function Invoke-Docker {
    & docker @args
    if ($LASTEXITCODE -ne 0) {
        throw "Docker falhou: docker $($args -join ' ')"
    }
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Instale o Docker Desktop, habilite os containers Linux e abra novamente o terminal.'
}

Invoke-Docker info

if (-not (Test-Path -LiteralPath '.env')) {
    Copy-Item -LiteralPath '.env.example' -Destination '.env'
}

Invoke-Docker compose config --quiet
Invoke-Docker compose build app
Invoke-Docker compose run --rm --no-deps app sh -c 'mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs && composer install --no-interaction --prefer-dist && chown -R www-data:www-data storage bootstrap/cache'

$keyLine = Get-Content -LiteralPath '.env' | Where-Object { $_ -match '^APP_KEY=' } | Select-Object -First 1
if (-not $keyLine -or $keyLine -match '^APP_KEY=\s*$') {
    Invoke-Docker compose run --rm --no-deps app php artisan key:generate --ansi
}

Invoke-Docker compose up -d db app
Invoke-Docker compose exec -T app php artisan migrate --force
Invoke-Docker compose run --rm --no-deps node npm install
if ($HotReload) {
    Invoke-Docker compose up -d web node
} else {
    Invoke-Docker compose stop node
    Invoke-Docker compose run --rm --no-deps node npm run build
    if (Test-Path -LiteralPath 'public/hot') {
        Remove-Item -LiteralPath 'public/hot'
    }
    Invoke-Docker compose up -d web
}

Write-Host 'Projeto disponível em http://127.0.0.1:8000'
