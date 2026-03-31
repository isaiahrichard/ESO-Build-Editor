# Local secrets (not committed)

- `esolog.secrets.php` — MySQL + `UESP_ESO_LOCAL_MINIMAL` (skip mined CP/skill DB when using seed-only `esobuilddata`).
- `esobuilddata.secrets.php` — build DB credentials (often same as esolog).
- `esochardata.secrets.php` — character DB (optional scripts).
- `uespservers.secrets.php` — stub for Memcached include chain.

Override defaults with env: `UESP_MYSQL_HOST`, `UESP_MYSQL_USER`, `UESP_MYSQL_PASSWORD`, `UESP_MYSQL_DATABASE`.

Import rules: `mysql -u root -p < uesp-esochardata/seed.sql`

Run: from `uesp-esochardata`, `php -S localhost:8080` then open `http://localhost:8080/testBuild.php`.
