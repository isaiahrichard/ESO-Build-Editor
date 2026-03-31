#!/usr/bin/env bash
# Serves uesp-esochardata at http://127.0.0.1:8080 — open testBuild.php
#
# Usage:
#   ./scripts/run-local-server.sh              # item APIs use local MySQL (default)
#   ./scripts/run-local-server.sh proxy        # item APIs fetch live esolog.uesp.net (no local mined rows needed)
#   ESO_LOCAL_ESOLOG_API=proxy ./scripts/run-local-server.sh   # same as "proxy" arg
#
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/uesp-esochardata"

export UESP_MYSQL_HOST="${UESP_MYSQL_HOST:-127.0.0.1}"
export UESP_MYSQL_USER="${UESP_MYSQL_USER:-root}"
export UESP_MYSQL_PASSWORD="${UESP_MYSQL_PASSWORD:-esobuildlocal}"
export UESP_MYSQL_DATABASE="${UESP_MYSQL_DATABASE:-esobuilddata}"
# Same-origin CDN proxy is usually blocked by Cloudflare (403 to PHP/curl). Default off; browser loads CDNs.
export ESO_LOCAL_UESP_CDN_PROXY="${ESO_LOCAL_UESP_CDN_PROXY:-0}"
# esolog JSON APIs (item search + exportJson for setName / stats):
#   local — uesp-esolog/*.php + secrets/esolog.secrets.php MySQL. Only items present in YOUR mined tables work.
#   proxy — router fetches https://esolog.uesp.net/… (no full local minedItem DB). May 502 if Cloudflare blocks curl.
if [[ "${1:-}" == "proxy" ]]; then
	export ESO_LOCAL_ESOLOG_API="proxy"
	shift || true
fi
export ESO_LOCAL_ESOLOG_API="${ESO_LOCAL_ESOLOG_API:-local}"

echo "Serving:  http://127.0.0.1:8080/testBuild.php"
echo "MySQL:    ${UESP_MYSQL_USER}@${UESP_MYSQL_HOST} db=${UESP_MYSQL_DATABASE}"
echo "Esolog item API: ESO_LOCAL_ESOLOG_API=${ESO_LOCAL_ESOLOG_API} (set to proxy to use live UESP without local mined rows)"
echo "Item/set DB import (optional): php scripts/import-mined-data.php --only-item-search"
if ! php -r 'exit(extension_loaded("openssl") || function_exists("curl_init") ? 0 : 1);'; then
	echo "Note: PHP has neither openssl nor ext-curl. For mined CP/skills use local MySQL:"
	echo "  mysql ... < uesp-esochardata/sql/mined_tables_schema.sql && php scripts/import-mined-data.php"
fi
echo "Stop with Ctrl+C"
# Router serves /_esolog_res/* from ../uesp-esolog/resources (same-origin; avoids UESP CDN 403 on Referer 127.0.0.1)
exec php -S 127.0.0.1:8080 local-server-router.php
