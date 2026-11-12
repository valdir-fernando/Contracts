$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)

function Invoke-Docker {
    & docker @args
    if ($LASTEXITCODE -ne 0) { throw "Docker falhou (codigo $LASTEXITCODE). Backup incompleto." }
}

if (-not (Test-Path -LiteralPath '.env')) { throw 'Arquivo .env ausente.' }
$environment = Get-Content -LiteralPath '.env' -Raw
if ($environment -notmatch '(?m)^DB_HOST=db\s*$' -or $environment -notmatch '(?m)^APP_ENV=local\s*$') {
    throw 'Este script atende somente ao desenvolvimento local com DB_HOST=db e APP_ENV=local.'
}
Invoke-Docker compose config --quiet
$running = @(& docker compose ps --services --status running)
if ($LASTEXITCODE -ne 0) { throw 'Nao foi possivel consultar os containers.' }
if ('db' -notin $running -or 'app' -notin $running) { throw 'Inicie db e app antes do backup.' }
$paused = @($running | Where-Object { $_ -in @('web', 'worker', 'scheduler') })
$snapshot = Join-Path (Join-Path $PWD 'backup') (Get-Date -Format 'yyyyMMdd-HHmmss-fff')
New-Item -ItemType Directory -Path $snapshot | Out-Null
$temporary = '/tmp/contracts-transfer-' + [guid]::NewGuid().ToString('N')
$completed = $false
try {
    if ($paused.Count -gt 0) { Invoke-Docker compose stop @paused }
    Write-Host 'Exportando banco (incluindo PDFs binarios)...'
    Invoke-Docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump -u "$MYSQL_USER" --single-transaction --routines --triggers --no-tablespaces --hex-blob --set-gtid-purged=OFF "$MYSQL_DATABASE" > "$1"' sh "$temporary.sql"
    Invoke-Docker compose cp "db:$temporary.sql" (Join-Path $snapshot 'database.sql')
    Write-Host 'Copiando arquivos privados...'
    Invoke-Docker compose exec -T app tar -czf "$temporary.tar.gz" -C /var/www/html storage
    Invoke-Docker compose cp "app:$temporary.tar.gz" (Join-Path $snapshot 'storage.tar.gz')
    Copy-Item -LiteralPath '.env' -Destination (Join-Path $snapshot 'environment.env')

    Write-Host 'Copiando codigo atual e referencias locais...'
    $previousEncoding = [Console]::OutputEncoding
    try {
        [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
        $sourceFiles = @(& git -c core.quotepath=false ls-files --cached --others --exclude-standard)
        if ($LASTEXITCODE -ne 0) { throw 'Nao foi possivel listar o codigo pelo Git.' }
    } finally { [Console]::OutputEncoding = $previousEncoding }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::Open((Join-Path $snapshot 'source.zip'), 'Create')
    try {
        foreach ($file in $sourceFiles) {
            if ($file -match '^(backup|backups|build)/' -or -not (Test-Path -LiteralPath $file -PathType Leaf)) { continue }
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, (Join-Path $PWD $file), $file) | Out-Null
        }
    } finally { $archive.Dispose() }

    $checksums = [ordered]@{}
    foreach ($file in @('database.sql', 'storage.tar.gz', 'environment.env', 'source.zip')) {
        $path = Join-Path $snapshot $file
        if ((Get-Item -LiteralPath $path).Length -eq 0) { throw "Arquivo vazio: $file" }
        $checksums[$file] = (Get-FileHash -Algorithm SHA256 -LiteralPath $path).Hash
    }
    $revision = & git rev-parse HEAD
    if ($LASTEXITCODE -ne 0) { throw 'Nao foi possivel identificar a revisao do codigo.' }
    @{ format = 1; created_at = [DateTime]::UtcNow.ToString('o'); revision = $revision; files = $checksums } |
        ConvertTo-Json -Depth 5 | Set-Content -Encoding UTF8 -LiteralPath (Join-Path $snapshot 'manifest.json')
    Copy-Item -LiteralPath 'scripts/restore-backup.ps1' -Destination 'backup/restaurar.ps1' -Force
    @'
@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0restaurar.ps1"
pause
'@ | Set-Content -Encoding ASCII -LiteralPath 'backup/RESTAURAR.cmd'
    Split-Path $snapshot -Leaf | Set-Content -Encoding ASCII -LiteralPath 'backup/LATEST'
    @'
COPIE ESTA PASTA backup INTEIRA PARA A RAIZ DO PROJETO NA OUTRA MAQUINA.
Instale/abra o Docker Desktop com containers Linux.
De duplo clique em RESTAURAR.cmd, dentro desta pasta.
Ou execute no PowerShell:
No PowerShell, na raiz do projeto, execute:
powershell -ExecutionPolicy Bypass -File .\backup\restaurar.ps1

Inclui banco, PDFs binarios, storage privado, .env/chave e codigo local.
Os hashes sao conferidos antes da restauracao. Volumes novos isolam o banco.
O codigo local do destino deve estar sem alteracoes e sem compose.override.yaml.
Proteja esta pasta: contem dados pessoais e segredos. Nao envie ao GitHub.
'@ | Set-Content -Encoding UTF8 -LiteralPath 'backup/LEIA-ME.txt'
    $completed = $true
} finally {
    # Only the uniquely named temporary files created above are removed.
    & docker compose exec -T db rm -f "$temporary.sql" | Out-Null
    & docker compose exec -T app rm -f "$temporary.tar.gz" | Out-Null
    if ($paused.Count -gt 0) { Invoke-Docker compose start @paused }
}
if ($completed) { Write-Host "Backup concluido: $snapshot. Copie a pasta backup inteira." }
