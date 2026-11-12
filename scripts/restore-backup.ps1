param([switch]$VerifyOnly)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$backupRoot = Join-Path $projectRoot 'backup'
$snapshotName = (Get-Content -LiteralPath (Join-Path $backupRoot 'LATEST') -Raw).Trim()
if ($snapshotName -notmatch '^\d{8}-\d{6}-\d{3}$') { throw 'Identificador de backup invalido.' }
$snapshot = Join-Path $backupRoot $snapshotName
$manifest = Get-Content -LiteralPath (Join-Path $snapshot 'manifest.json') -Raw | ConvertFrom-Json
if ($manifest.format -ne 1) { throw 'Formato de backup nao suportado.' }
foreach ($file in @('database.sql', 'storage.tar.gz', 'environment.env', 'source.zip')) {
    $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $snapshot $file)).Hash
    if ($actual -ne $manifest.files.$file) { throw "Backup corrompido ou incompleto: $file" }
}
Write-Host 'Integridade dos quatro arquivos confirmada.'
if ($VerifyOnly) { return }

Set-Location $projectRoot
if (Test-Path -LiteralPath 'compose.override.yaml') { throw 'Ja existe compose.override.yaml. Nenhuma restauracao foi iniciada.' }
if (Test-Path -LiteralPath '.git') {
    $changes = @(& git status --porcelain --untracked-files=no)
    if ($LASTEXITCODE -ne 0 -or $changes.Count -gt 0) { throw 'Preserve as alteracoes locais do codigo antes de restaurar.' }
}
function Invoke-Docker {
    $dockerArguments = @($args)
    if ($dockerArguments[0] -eq 'compose') {
        $dockerArguments = @('compose', '--project-name', $projectName) + $dockerArguments[1..($dockerArguments.Count - 1)]
    }
    & docker @dockerArguments
    if ($LASTEXITCODE -ne 0) { throw "Docker falhou (codigo $LASTEXITCODE). Restauracao interrompida." }
}
Invoke-Docker info --format '{{.ServerVersion}}'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [System.IO.Compression.ZipFile]::OpenRead((Join-Path $snapshot 'source.zip'))
try {
    $prefix = [System.IO.Path]::GetFullPath($projectRoot).TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar
    # Validate every entry before writing any code from the snapshot.
    foreach ($entry in $archive.Entries) {
        $target = [System.IO.Path]::GetFullPath((Join-Path $projectRoot $entry.FullName))
        if (-not $target.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase) -or $entry.FullName -match '^(\.git|backup|backups)[/\\]') {
            throw 'Caminho inseguro no arquivo de codigo.'
        }
    }
    foreach ($entry in $archive.Entries) {
        $target = Join-Path $projectRoot $entry.FullName
        New-Item -ItemType Directory -Force -Path (Split-Path $target -Parent) | Out-Null
        [System.IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $target, $true)
    }
} finally { $archive.Dispose() }

if (Test-Path -LiteralPath '.env') {
    Copy-Item -LiteralPath '.env' -Destination (Join-Path $backupRoot ('destination-env-' + [guid]::NewGuid().ToString('N') + '.env'))
}
$environment = Get-Content -LiteralPath (Join-Path $snapshot 'environment.env') -Raw
$environment = [regex]::Replace($environment, '(?m)^COMPOSE_(FILE|PROJECT_NAME)=[^\r\n]*\r?\n?', '')
foreach ($setting in @{ APP_ENV = 'local'; APP_URL = 'http://127.0.0.1:8000'; DB_CONNECTION = 'mysql'; DB_HOST = 'db'; DB_PORT = '3306' }.GetEnumerator()) {
    $pattern = '(?m)^' + $setting.Key + '=[^\r\n]*'
    if ($environment -match $pattern) { $environment = [regex]::Replace($environment, $pattern, ($setting.Key + '=' + $setting.Value)) }
    else { $environment += "`n$($setting.Key)=$($setting.Value)`n" }
}
if ($environment -notmatch '(?m)^APP_KEY=\S+') { throw 'Backup sem APP_KEY. Restauracao interrompida.' }
[System.IO.File]::WriteAllText((Join-Path $projectRoot '.env'), $environment, (New-Object System.Text.UTF8Encoding($false)))

# Compose automatically uses this ignored override on subsequent starts.
# A unique project name always creates fresh volumes, preserving existing databases.
$projectName = 'contracts-local-' + [guid]::NewGuid().ToString('N').Substring(0, 12)
$env:COMPOSE_FILE = $null
$env:COMPOSE_PROJECT_NAME = $projectName
Add-Content -Encoding UTF8 -LiteralPath '.env' -Value "`nCOMPOSE_PROJECT_NAME=$projectName"
@"
name: $projectName
services:
  app:
    image: ${projectName}-app:latest
  worker:
    image: ${projectName}-app:latest
  scheduler:
    image: ${projectName}-app:latest
"@ | Set-Content -Encoding ASCII -LiteralPath 'compose.override.yaml'
Write-Host "Restaurando em volumes novos: $projectName"
Invoke-Docker compose build app
Invoke-Docker compose run --rm --no-deps app composer install --no-interaction --prefer-dist
Invoke-Docker compose up -d --wait db
Invoke-Docker compose cp (Join-Path $snapshot 'database.sql') db:/tmp/contracts-restore.sql
Invoke-Docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql --binary-mode=1 -u "$MYSQL_USER" "$MYSQL_DATABASE" < /tmp/contracts-restore.sql'
Invoke-Docker compose exec -T db rm -f /tmp/contracts-restore.sql
Invoke-Docker compose up -d app
Invoke-Docker compose cp (Join-Path $snapshot 'storage.tar.gz') app:/tmp/contracts-storage.tar.gz
Invoke-Docker compose exec -T app tar -xzf /tmp/contracts-storage.tar.gz -C /var/www/html
Invoke-Docker compose exec -T app rm -f /tmp/contracts-storage.tar.gz
Invoke-Docker compose exec -T app chown -R www-data:www-data storage bootstrap/cache
Invoke-Docker compose exec -T app php artisan optimize:clear
Invoke-Docker compose exec -T app php artisan migrate --force
Invoke-Docker compose run --rm --no-deps node npm ci
Invoke-Docker compose run --rm --no-deps node npm run build
if (Test-Path -LiteralPath 'public/hot') { Remove-Item -LiteralPath 'public/hot' }
Invoke-Docker compose up -d web worker scheduler
Write-Host 'Suite restaurada: http://127.0.0.1:8000. Use os mesmos usuarios e senhas.'
