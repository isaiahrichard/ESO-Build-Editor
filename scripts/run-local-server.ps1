# Serves uesp-esochardata at http://127.0.0.1:8080 — open testBuild.php
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location (Join-Path $Root "uesp-esochardata")

if (-not $env:UESP_MYSQL_HOST) { $env:UESP_MYSQL_HOST = "127.0.0.1" }
if (-not $env:UESP_MYSQL_USER) { $env:UESP_MYSQL_USER = "root" }
if (-not $env:UESP_MYSQL_PASSWORD) { $env:UESP_MYSQL_PASSWORD = "esobuildlocal" }
if (-not $env:UESP_MYSQL_DATABASE) { $env:UESP_MYSQL_DATABASE = "esobuilddata" }

Write-Host "Serving:  http://127.0.0.1:8080/testBuild.php"
Write-Host "MySQL:    $($env:UESP_MYSQL_USER)@$($env:UESP_MYSQL_HOST) db=$($env:UESP_MYSQL_DATABASE)"
php -S 127.0.0.1:8080
