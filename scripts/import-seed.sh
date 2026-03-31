#!/usr/bin/env bash
# Import uesp-esochardata/seed.sql into local MySQL (creates database esobuilddata).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SQL="$ROOT/uesp-esochardata/seed.sql"
if [[ ! -f "$SQL" ]]; then
  echo "Missing $SQL" >&2
  exit 1
fi
echo "Importing $SQL (you will be prompted for MySQL password unless configured for socket auth)..."
mysql -u root -p < "$SQL"
echo "Done. Start PHP from uesp-esochardata: php -S localhost:8080"
