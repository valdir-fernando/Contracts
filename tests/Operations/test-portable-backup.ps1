$ErrorActionPreference = 'Stop'
$root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$fixture = Join-Path ([System.IO.Path]::GetTempPath()) ('contracts-portable-test-' + [guid]::NewGuid().ToString('N'))
$snapshot = Join-Path $fixture 'backup/20261112-120000-000'
New-Item -ItemType Directory -Path $snapshot -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $fixture 'scripts') | Out-Null
Copy-Item -LiteralPath (Join-Path $root 'scripts/restore-backup.ps1') -Destination (Join-Path $fixture 'scripts/restore-backup.ps1')
$script = Join-Path $fixture 'scripts/restore-backup.ps1'
function Assert-Verification([bool]$shouldSucceed) {
    $ErrorActionPreference = 'Continue'
    $output = & powershell -NoProfile -ExecutionPolicy Bypass -File $script -VerifyOnly 2>&1
    $exitCode = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
    if (($exitCode -eq 0) -ne $shouldSucceed) { throw "Resultado inesperado: $output" }
}
try {
    $files = @{}
    foreach ($file in @('database.sql', 'storage.tar.gz', 'environment.env', 'source.zip')) {
        'synthetic-test-only' | Set-Content -Encoding ASCII -LiteralPath (Join-Path $snapshot $file)
        $files[$file] = (Get-FileHash -LiteralPath (Join-Path $snapshot $file)).Hash
    }
    @{ format = 1; files = $files } | ConvertTo-Json | Set-Content -Encoding UTF8 -LiteralPath (Join-Path $snapshot 'manifest.json')
    '20261112-120000-000' | Set-Content -Encoding ASCII -LiteralPath (Join-Path $fixture 'backup/LATEST')
    Assert-Verification $true
    'corrupted' | Add-Content -LiteralPath (Join-Path $snapshot 'database.sql')
    Assert-Verification $false
    'synthetic-test-only' | Set-Content -Encoding ASCII -LiteralPath (Join-Path $snapshot 'database.sql')
    Remove-Item -LiteralPath (Join-Path $snapshot 'storage.tar.gz')
    Assert-Verification $false
    '../../outside' | Set-Content -Encoding ASCII -LiteralPath (Join-Path $fixture 'backup/LATEST')
    Assert-Verification $false
    Write-Host 'PASS: pacote valido aceito; corrupcao, arquivo ausente e caminho externo recusados. Nenhum Docker ou banco usado.'
} finally {
    $resolved = [System.IO.Path]::GetFullPath($fixture)
    $tempRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath()).TrimEnd('\') + '\'
    if (-not $resolved.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase) -or (Split-Path $resolved -Leaf) -notmatch '^contracts-portable-test-[a-f0-9]{32}$') {
        throw 'Diretorio temporario fora do escopo de limpeza.'
    }
    Remove-Item -LiteralPath $resolved -Recurse -Force
}
