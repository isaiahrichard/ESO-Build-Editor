#!/usr/bin/env bash
# Serves uesp-esochardata at http://127.0.0.1:8080 — open testBuild.php
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/uesp-esochardata"

export UESP_MYSQL_HOST="${UESP_MYSQL_HOST:-127.0.0.1}"
export UESP_MYSQL_USER="${UESP_MYSQL_USER:-root}"
export UESP_MYSQL_PASSWORD="${UESP_MYSQL_PASSWORD:-esobuildlocal}"
export UESP_MYSQL_DATABASE="${UESP_MYSQL_DATABASE:-esobuilddata}"
# Same-origin CDN proxy is usually blocked by Cloudflare (403 to PHP/curl). Default off; browser loads CDNs.
export ESO_LOCAL_UESP_CDN_PROXY="${ESO_LOCAL_UESP_CDN_PROXY:-0}"
# esolog JSON APIs: local runs sibling uesp-esolog/*.php + MySQL (CORS); proxy hits live esolog (often CF 403 from PHP).
export ESO_LOCAL_ESOLOG_API="${ESO_LOCAL_ESOLOG_API:-local}"

echo "Serving:  http://127.0.0.1:8080/testBuild.php"
echo "MySQL:    ${UESP_MYSQL_USER}@${UESP_MYSQL_HOST} db=${UESP_MYSQL_DATABASE}"
echo "Item/set picker: php scripts/import-mined-data.php --only-item-search (see script header if HTTP fetch fails)"
if ! php -r 'exit(extension_loaded("openssl") || function_exists("curl_init") ? 0 : 1);'; then
	echo "Note: PHP has neither openssl nor ext-curl. For mined CP/skills use local MySQL:"
	echo "  mysql ... < uesp-esochardata/sql/mined_tables_schema.sql && php scripts/import-mined-data.php"
fi
echo "Stop with Ctrl+C"
# Router serves /_esolog_res/* from ../uesp-esolog/resources (same-origin; avoids UESP CDN 403 on Referer 127.0.0.1)
exec php -S 127.0.0.1:8080 local-server-router.php
