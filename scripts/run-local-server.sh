#!/usr/bin/env bash
# Serves uesp-esochardata at http://127.0.0.1:8080 — open testBuild.php
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/uesp-esochardata"

export UESP_MYSQL_HOST="${UESP_MYSQL_HOST:-127.0.0.1}"
export UESP_MYSQL_USER="${UESP_MYSQL_USER:-root}"
export UESP_MYSQL_PASSWORD="${UESP_MYSQL_PASSWORD:-esobuildlocal}"
export UESP_MYSQL_DATABASE="${UESP_MYSQL_DATABASE:-esobuilddata}"

echo "Serving:  http://127.0.0.1:8080/testBuild.php"
echo "MySQL:    ${UESP_MYSQL_USER}@${UESP_MYSQL_HOST} db=${UESP_MYSQL_DATABASE}"
if ! php -r 'exit(extension_loaded("openssl") || function_exists("curl_init") ? 0 : 1);'; then
	echo "Note: PHP has neither openssl nor ext-curl. For mined CP/skills use local MySQL:"
	echo "  mysql ... < uesp-esochardata/sql/mined_tables_schema.sql && php scripts/import-mined-data.php"
fi
echo "Stop with Ctrl+C"
exec php -S 127.0.0.1:8080
