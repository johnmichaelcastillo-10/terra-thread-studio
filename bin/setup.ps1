<#
.SYNOPSIS
  Downloads WordPress core + the SQLite Database Integration plugin, installs the
  drop-in, then installs and seeds the site. Safe to re-run.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File bin/setup.ps1
  powershell -ExecutionPolicy Bypass -File bin/setup.ps1 -Fresh   # wipe the database and reseed
#>
param(
  [switch]$Fresh,
  [string]$Url = 'http://localhost:8090',
  [string]$WpVersion = $(if ($env:TT_WP_VERSION) { $env:TT_WP_VERSION } else { 'latest' }),
  [string]$SqliteVersion = $(if ($env:TT_SQLITE_VERSION) { $env:TT_SQLITE_VERSION } else { '3.1.0' })
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$Root    = Split-Path -Parent $PSScriptRoot
$Cache   = Join-Path $Root '.cache'
$Public  = Join-Path $Root 'public'
$Content = Join-Path $Public 'wp-content'
$Plugins = Join-Path $Content 'plugins'

function Step($msg) { Write-Host "`n==> $msg" -ForegroundColor DarkYellow }

Step 'Checking PHP'
if (-not (Get-Command php -ErrorAction SilentlyContinue)) { throw 'PHP 8.1+ is required on PATH.' }
$mods = php -m
foreach ($m in 'pdo_sqlite', 'mbstring', 'openssl') {
  if ($mods -notcontains $m) { throw "PHP extension '$m' is not enabled. Enable it in php.ini." }
}
php -r "exit(version_compare(PHP_VERSION, '8.1', '>=') ? 0 : 1);"
if ($LASTEXITCODE -ne 0) { throw 'PHP 8.1 or newer is required.' }

New-Item -ItemType Directory -Force $Cache, (Join-Path $Root 'database'), (Join-Path $Root 'logs'), (Join-Path $Root 'config') | Out-Null

Step "WordPress core ($WpVersion)"
if (-not (Test-Path (Join-Path $Public 'wordpress/wp-settings.php'))) {
  $wpZip = Join-Path $Cache "wordpress-$WpVersion.zip"
  $wpUrl = if ($WpVersion -eq 'latest') { 'https://wordpress.org/latest.zip' } else { "https://wordpress.org/wordpress-$WpVersion.zip" }
  if (-not (Test-Path $wpZip)) { Invoke-WebRequest -UseBasicParsing $wpUrl -OutFile $wpZip }
  Expand-Archive $wpZip -DestinationPath $Public -Force   # archive root is "wordpress/"
  # Core's bundled wp-content is unused (WP_CONTENT_DIR points at public/wp-content).
  Remove-Item -Recurse -Force (Join-Path $Public 'wordpress/wp-content')
} else { Write-Host '    already present' }

Step "SQLite Database Integration ($SqliteVersion)"
if (-not (Test-Path (Join-Path $Plugins 'sqlite-database-integration/load.php'))) {
  $sqZip = Join-Path $Cache "sqlite-database-integration-$SqliteVersion.zip"
  $sqUrl = if ($SqliteVersion -eq 'latest') { 'https://downloads.wordpress.org/plugin/sqlite-database-integration.zip' } else { "https://downloads.wordpress.org/plugin/sqlite-database-integration.$SqliteVersion.zip" }
  if (-not (Test-Path $sqZip)) { Invoke-WebRequest -UseBasicParsing $sqUrl -OutFile $sqZip }
  Expand-Archive $sqZip -DestinationPath $Plugins -Force
} else { Write-Host '    already present' }

Step 'Installing db.php drop-in'
php (Join-Path $PSScriptRoot 'dropin.php')
if ($LASTEXITCODE -ne 0) { throw 'Could not install the SQLite drop-in.' }

if ($Fresh) {
  Step 'Fresh install requested: removing database and uploads'
  Get-ChildItem (Join-Path $Root 'database') -Filter '*.sqlite*' | Remove-Item -Force
  $uploads = Join-Path $Content 'uploads'
  if (Test-Path $uploads) { Remove-Item -Recurse -Force $uploads }
}

Step 'Installing WordPress and seeding studio data'
$env:TT_URL = $Url
php (Join-Path $PSScriptRoot 'install.php')
if ($LASTEXITCODE -ne 0) { throw 'Install failed. See logs/debug.log.' }

Write-Host "`nDone. Start the server with:" -ForegroundColor Green
Write-Host '    powershell -ExecutionPolicy Bypass -File bin/serve.ps1'
