param([int]$Port = 8090, [string]$HostName = 'localhost')
$Root = Split-Path -Parent $PSScriptRoot
Write-Host "Terra & Thread Studio -> http://${HostName}:$Port  (admin: http://${HostName}:$Port/wordpress/wp-admin/)" -ForegroundColor DarkYellow
php -S "${HostName}:$Port" -t (Join-Path $Root 'public') (Join-Path $PSScriptRoot 'router.php')
