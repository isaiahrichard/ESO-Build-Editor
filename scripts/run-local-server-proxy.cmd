@echo off
REM Live esolog item API (no local minedItem rows required). From repo root:
REM   scripts\run-local-server-proxy.cmd
set "ESO_LOCAL_ESOLOG_API=proxy"
if not defined UESP_MYSQL_HOST set "UESP_MYSQL_HOST=127.0.0.1"
if not defined UESP_MYSQL_USER set "UESP_MYSQL_USER=root"
if not defined UESP_MYSQL_PASSWORD set "UESP_MYSQL_PASSWORD=esobuildlocal"
if not defined UESP_MYSQL_DATABASE set "UESP_MYSQL_DATABASE=esobuilddata"
cd /d "%~dp0..\uesp-esochardata"
echo Esolog item API: proxy (live UESP). Open http://127.0.0.1:8080/testBuild.php
php -S 127.0.0.1:8080 local-server-router.php
