<?php
/**
 * Local MySQL credentials for uesp-esochardata / uesp-esolog.
 *
 * Read side: used by editBuild (rules), viewCps, viewSkills (normally mined tables).
 * Write side: used by UpdateEsoPageViews when ENABLE_ESO_PAGEVIEW_UPDATES is true (disabled in local esoCommon).
 *
 * Seed-only DB (no local cp2 or minedSkills tables):
 * - Set UESP_ESO_LOCAL_MINIMAL false (or omit) so the editor tries to render CP/skills.
 * - Set UESP_ESO_USE_REMOTE_MINED_DATA true (default below) so PHP loads those tables from
 *   UESP exportJson.php over HTTPS (same data the live wiki uses). Requires network access.
 * - Windows PHP often ships without ext-curl and without ext-openssl; the loader falls back to
 *   system curl.exe (or curl) so HTTPS still works. Enabling extension=openssl in php.ini is optional.
 * - Optional: UESP_ESO_REMOTE_SSL_RELAXED true relaxes SSL verify for ext-curl / streams only (not system curl).
 * - Optional: UESP_ESO_EXPORT_JSON_CACHE_PATH = full path to a mined-export.json (otherwise data/uesp-export/mined-export.json).
 * - Full mined data: mysql ... < uesp-esochardata/sql/mined_tables_schema.sql then php scripts/import-mined-data.php
 *   (or save browser JSON to data/uesp-export/mined-export.json — see scripts/import-mined-data.php).
 * - Keep UESP_ESO_LOCAL_MINIMAL true only if you explicitly want empty CP/skill panels (rare); otherwise
 *   failed remote + missing local tables throw a clear error instead of rendering empty stubs.
 */
if (!defined('UESP_ESO_LOCAL_MINIMAL')) {
	define('UESP_ESO_LOCAL_MINIMAL', false);
}

if (!defined('UESP_ESO_USE_REMOTE_MINED_DATA')) {
	define('UESP_ESO_USE_REMOTE_MINED_DATA', true);
}
if (!defined('UESP_ESO_REMOTE_EXPORT_JSON_URL')) {
	define('UESP_ESO_REMOTE_EXPORT_JSON_URL', 'https://esolog.uesp.net/exportJson.php');
}

$uespEsoLogReadDBHost = getenv('UESP_MYSQL_HOST') ?: '127.0.0.1';
$uespEsoLogReadUser   = getenv('UESP_MYSQL_USER') ?: 'root';
$uespEsoLogReadPW     = getenv('UESP_MYSQL_PASSWORD') !== false ? getenv('UESP_MYSQL_PASSWORD') : '';
$uespEsoLogDatabase   = getenv('UESP_MYSQL_DATABASE') ?: 'esobuilddata';

$uespEsoLogWriteDBHost = $uespEsoLogReadDBHost;
$uespEsoLogWriteUser   = $uespEsoLogReadUser;
$uespEsoLogWritePW     = $uespEsoLogReadPW;
