#Requires -Version 5.1
# Builds the static site in dist/ from the local WordPress, and optionally deploys it to Vercel.
#
#   powershell -ExecutionPolicy Bypass -File bin/publish.ps1            # export only; preview: node bin/serve-dist.js
#   powershell -ExecutionPolicy Bypass -File bin/publish.ps1 -Deploy    # back up, export, deploy to Vercel (production)
#
# First deploy only: `npm install -g vercel`, `vercel login`, then
# `vercel link --yes --project terra-thread-studio --scope kaizerrrs-projects` in this folder
# (creates .vercel/, which is copied into dist/ so the deploy lands on the same project).
# .env holds STATIC_URL (the live address, see .env.example).
param([switch]$Deploy)

# Not 'Stop': in PowerShell 5.1 that turns native stderr output into errors.
$ErrorActionPreference = 'Continue'
$Root = Split-Path $PSScriptRoot -Parent
Set-Location $Root

$cfg = @{}
if (Test-Path .env) {
    foreach ($line in Get-Content .env) {
        if ($line -match '^([A-Z0-9_]+)=(.*)$') { $cfg[$Matches[1]] = $Matches[2].Trim() }
    }
}

if (-not (Test-Path database\terra-thread.sqlite)) { throw 'No local site yet: run `docker compose up -d` (or bin\setup.ps1) first.' }

# If the Docker site is running, use it: it serves the same database and uploads, and its
# WordPress does the date roll. Otherwise a private host PHP server is started below.
$dockerUp = $false
if (Get-Command docker -ErrorAction SilentlyContinue) {
    $dockerUp = @(docker compose ps --status running --services 2>$null) -contains 'web'
}
$sitePort = if ($cfg.TT_PORT) { $cfg.TT_PORT } else { '8090' }

if ($dockerUp) {
    docker compose exec -T -u www-data web php bin/roll-dates.php
} else {
    php bin/roll-dates.php
}
if ($LASTEXITCODE -ne 0) { throw 'Could not update workshop dates.' }

if ($Deploy) {
    # Back up the database and uploads; keep the newest 10 automatic backups.
    $dest = "backups\auto-$(Get-Date -Format 'yyyy-MM-dd_HHmmss')"
    New-Item -ItemType Directory -Force $dest | Out-Null
    Copy-Item database\terra-thread.sqlite $dest
    if (Test-Path public\wp-content\uploads) { Copy-Item public\wp-content\uploads $dest -Recurse }
    if (-not (Test-Path "$dest\terra-thread.sqlite")) { throw 'Backup failed; nothing was deployed.' }
    Get-ChildItem backups -Directory -Filter 'auto-*' | Sort-Object Name -Descending | Select-Object -Skip 10 | Remove-Item -Recurse -Force
    Write-Host "Backed up to $dest"
}

# Export source: the running Docker site, or a private host server just for the export
# (so it doesn't depend on bin/serve.ps1 running).
$server = $null
if ($dockerUp) {
    $source = "http://localhost:$sitePort"
    Write-Host "Exporting from the Docker site at $source"
} else {
    $source = 'http://localhost:8091'
    $server = Start-Process php -ArgumentList '-S', 'localhost:8091', '-t', 'public', 'bin/router.php' -WorkingDirectory $Root -WindowStyle Hidden -PassThru
}
try {
    $up = $false
    for ($i = 0; $i -lt 30 -and -not $up; $i++) {
        try { Invoke-WebRequest "$source/" -UseBasicParsing -TimeoutSec 5 *> $null; $up = $true } catch { Start-Sleep 1 }
    }
    if (-not $up) { throw "The site at $source did not respond." }

    $exportArgs = @('bin/export-static.php', "--source=$source")
    if ($cfg.STATIC_URL) {
        $exportArgs += "--url=$($cfg.STATIC_URL)"
    } else {
        Write-Host 'STATIC_URL is not set in .env: links will be root-relative and canonical tags relative.'
    }
    php @exportArgs
    if ($LASTEXITCODE -ne 0) { throw 'Static export failed; nothing was deployed.' }
} finally {
    if ($server) { Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue }
}

if ($Deploy) {
    if (-not (Test-Path .vercel\project.json)) { throw 'Not linked to Vercel: run `vercel link` in this folder first.' }
    Copy-Item .vercel dist\ -Recurse -Force
    vercel deploy dist --prod --yes
    if ($LASTEXITCODE -ne 0) { throw 'Vercel deploy failed.' }
    Write-Host "`nDeployed: $($cfg.STATIC_URL)" -ForegroundColor Green
}
