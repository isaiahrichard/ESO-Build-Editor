#!/usr/bin/env php
<?php

/**
 * One-shot import of mined CP + skill tables into local MySQL, and optional JSON cache for the editor.
 *
 * Usage:
 *   mysql -u USER -p DB < uesp-esochardata/sql/mined_tables_schema.sql
 *   php scripts/import-mined-data.php
 *   php scripts/import-mined-data.php --version=49
 *
 * If server-side HTTPS gets Cloudflare HTML, save JSON from your browser (same query the API uses) to
 *   data/uesp-export/mined-export.json
 * then:
 *   php scripts/import-mined-data.php --from-file=data/uesp-export/mined-export.json
 *
 * Split exports (large tables in separate files): pass --from-file once per file. Each file may contain
 * any subset of the table keys below; later files override the same table name if repeated.
 * Example (paths relative to repo root):
 *   php scripts/import-mined-data.php \
 *     --from-file=cp2.json \
 *     --from-file=minedSkills.json \
 *     --from-file=skills-skilltree.json \
 *     --from-file=skills-skillToolTips.json
 *
 * Browser URL (adjust host if needed; combine all table[] params):
 *   https://esolog.uesp.net/exportJson.php?version=49&table%5B%5D=cp2Disciplines&table%5B%5D=cp2ClusterRoots
 *   &table%5B%5D=cp2SkillLinks&table%5B%5D=cp2Skills&table%5B%5D=cp2SkillDescriptions
 *   &table%5B%5D=minedSkills&table%5B%5D=skillTree&table%5B%5D=skillTooltips
 *
 * If minedSkills is too large / Cloudflare blocks bulk download, use:
 *   python scripts/fetch-mined-skills-batched.py -o minedSkills.json
 * (see that script for the exact API URL and cookie options), then import with --from-file=minedSkills.json
 * or merge via multiple --from-file (see above).
 *
 * Omit minedSkills (large / separate import): do not load, validate, cache, or replace that table.
 *   php scripts/import-mined-data.php --skip-mined-skills --from-file=cp2.json --from-file=skills-skilltree.json ...
 *
 * Large exports: default memory_limit is raised for this process. Skip writing data/uesp-export/mined-export.json
 * (saves a full json_encode of the payload) with --no-cache if you still hit limits or only care about MySQL.
 *
 * With --from-file only: each run imports whatever non-empty tables appear in your JSON (others are not truncated).
 * Example: --from-file=minedSkills.json updates minedSkills only; combine multiple --from-file to merge tables first.
 *
 * Local item search / set picker (/_esolog_api/ + minedItemSummary + minedItem):
 *   php scripts/import-mined-data.php --only-item-search
 * If Cloudflare blocks PHP, save JSON from a logged-in browser, then:
 *   php scripts/import-mined-data.php --only-item-search --from-file=data/uesp-export/items.json
 * Browser URL:
 *   https://esolog.uesp.net/exportJson.php?version=49&table%5B%5D=minedItemSummary&table%5B%5D=minedItem
 * After a normal import, add items without re-fetching CP/skills:
 *   php scripts/import-mined-data.php --with-item-search
 * Optional: --item-from-file=items.json with --with-item-search (uses file instead of HTTP for item tables).
 */

if (php_sapi_name() !== 'cli') {
	fwrite(STDERR, "CLI only.\n");
	exit(1);
}

if (function_exists('ini_set')) {
	$mem = getenv('IMPORT_MINED_DATA_MEMORY_LIMIT');
	if ($mem !== false && $mem !== '') {
		ini_set('memory_limit', $mem);
	} else {
		ini_set('memory_limit', '8192M');
	}
}

$root = dirname(__DIR__);
require_once $root . '/secrets/esolog.secrets.php';
require_once $root . '/uesp-esolog/esoRemoteMinedData.php';

$version = '49';
$fromFiles = array();
$itemFromFiles = array();
$skipMinedSkills = false;
$noCache = false;
$onlyItemSearch = false;
$withItemSearch = false;

foreach (array_slice($argv, 1) as $arg) {
	if (preg_match('/^--version=(.+)$/', $arg, $m)) {
		$version = $m[1];
	} elseif ($arg === '--skip-mined-skills') {
		$skipMinedSkills = true;
	} elseif ($arg === '--no-cache') {
		$noCache = true;
	} elseif ($arg === '--only-item-search') {
		$onlyItemSearch = true;
	} elseif ($arg === '--with-item-search') {
		$withItemSearch = true;
	} elseif (preg_match('/^--from-file=(.+)$/', $arg, $m)) {
		$path = $m[1];
		if ($path[0] !== '/' && !preg_match('#^[A-Za-z]:\\\\#', $path)) {
			$path = $root . '/' . ltrim(str_replace('\\', '/', $path), '/');
		}
		$fromFiles[] = $path;
	} elseif (preg_match('/^--item-from-file=(.+)$/', $arg, $m)) {
		$path = $m[1];
		if ($path[0] !== '/' && !preg_match('#^[A-Za-z]:\\\\#', $path)) {
			$path = $root . '/' . ltrim(str_replace('\\', '/', $path), '/');
		}
		$itemFromFiles[] = $path;
	} elseif ($arg === '-h' || $arg === '--help') {
		echo file_get_contents(__FILE__, false, null, 0, 4200);
		exit(0);
	}
}

if ($onlyItemSearch && $withItemSearch) {
	fwrite(STDERR, "Use either --only-item-search or --with-item-search, not both.\n");
	exit(1);
}

/**
 * @param list<string> $cols
 */
function uesp_sql_backtick_cols(array $cols)
{
	$out = array();
	foreach ($cols as $c) {
		$out[] = '`' . str_replace('`', '``', $c) . '`';
	}
	return implode(',', $out);
}

function uesp_sql_value(mysqli $db, $v)
{
	if ($v === null) {
		return 'NULL';
	}
	if (is_bool($v)) {
		return $v ? '1' : '0';
	}
	if (is_int($v)) {
		return (string) $v;
	}
	if (is_float($v)) {
		if (!is_finite($v)) {
			return 'NULL';
		}
		return (string) $v;
	}
	return "'" . $db->real_escape_string((string) $v) . "'";
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function uesp_normalize_export_rows(array $rows)
{
	if (empty($rows)) {
		return array();
	}
	$keySet = array();
	foreach ($rows as $r) {
		foreach (array_keys($r) as $k) {
			$keySet[$k] = true;
		}
	}
	$cols = array_keys($keySet);
	sort($cols);
	$out = array();
	foreach ($rows as $r) {
		$row = array();
		foreach ($cols as $c) {
			$row[$c] = array_key_exists($c, $r) ? $r[$c] : null;
		}
		$out[] = $row;
	}
	return $out;
}

/**
 * Create minedItemSummary / minedItem if missing (schema derived from export column names).
 *
 * @param list<array<string,mixed>> $rows
 */
function uesp_ensure_mined_item_table(mysqli $db, string $table, array $rows): void
{
	$norm = uesp_normalize_export_rows($rows);
	if (empty($norm)) {
		return;
	}
	$cols = array_keys($norm[0]);
	$parts = array();
	foreach ($cols as $c) {
		$esc = '`' . str_replace('`', '``', $c) . '`';
		if ($table === 'minedItemSummary' && $c === 'itemId') {
			$parts[] = "$esc BIGINT NOT NULL PRIMARY KEY";
		} elseif ($table === 'minedItem' && $c === 'id') {
			$parts[] = "$esc BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY";
		} else {
			$parts[] = "$esc MEDIUMTEXT";
		}
	}
	$tesc = '`' . str_replace('`', '``', $table) . '`';
	$sql = "CREATE TABLE IF NOT EXISTS $tesc (" . implode(',', $parts) . ") ENGINE=MyISAM DEFAULT CHARSET=utf8mb4";
	if (!$db->query($sql)) {
		fwrite(STDERR, "CREATE TABLE $table failed: " . $db->error . "\n");
		exit(1);
	}
}

/**
 * @param list<array<string,mixed>> $rows
 */
function uesp_bulk_insert(mysqli $db, string $table, array $rows, int $batchSize = 12)
{
	if (empty($rows)) {
		echo "  (no rows for $table)\n";
		return;
	}
	$cols = array_keys($rows[0]);
	$colSql = uesp_sql_backtick_cols($cols);
	$tableEsc = '`' . str_replace('`', '``', $table) . '`';

	$db->query("TRUNCATE TABLE $tableEsc");

	$batch = array();
	$n = 0;
	foreach ($rows as $row) {
		$vals = array();
		foreach ($cols as $c) {
			$vals[] = uesp_sql_value($db, array_key_exists($c, $row) ? $row[$c] : null);
		}
		$batch[] = '(' . implode(',', $vals) . ')';
		++$n;
		if (count($batch) >= $batchSize) {
			$sql = "INSERT INTO $tableEsc ($colSql) VALUES " . implode(',', $batch);
			if (!$db->query($sql)) {
				fwrite(STDERR, "INSERT batch failed ($table): " . $db->error . "\n");
				exit(1);
			}
			$batch = array();
		}
	}
	if (count($batch) > 0) {
		$sql = "INSERT INTO $tableEsc ($colSql) VALUES " . implode(',', $batch);
		if (!$db->query($sql)) {
			fwrite(STDERR, "INSERT failed ($table): " . $db->error . "\n");
			exit(1);
		}
	}
	echo "Inserted $n rows into $table\n";
}

/**
 * @param list<string> $itemFromFiles
 * @return array<string,mixed>|null
 */
function uesp_load_item_search_payload(string $root, string $version, array $itemFromFiles)
{
	$itemKeys = array('minedItemSummary', 'minedItem');
	$data = array();

	if (count($itemFromFiles) > 0) {
		foreach ($itemFromFiles as $fromFile) {
			if (!is_readable($fromFile)) {
				fwrite(STDERR, "Cannot read file: $fromFile\n");
				exit(1);
			}
			$chunk = json_decode(file_get_contents($fromFile), true);
			if (!is_array($chunk)) {
				fwrite(STDERR, "Invalid JSON in $fromFile\n");
				exit(1);
			}
			foreach ($itemKeys as $t) {
				if (array_key_exists($t, $chunk) && is_array($chunk[$t]) && count($chunk[$t]) > 0) {
					$data[$t] = $chunk[$t];
				}
			}
			echo "Loaded item table keys from $fromFile\n";
		}
		return count($data) > 0 ? $data : null;
	}

	if (!defined('UESP_ESO_REMOTE_EXPORT_JSON_URL') || UESP_ESO_REMOTE_EXPORT_JSON_URL === '') {
		define('UESP_ESO_REMOTE_EXPORT_JSON_URL', 'https://esolog.uesp.net/exportJson.php');
	}
	echo "Fetching minedItemSummary + minedItem from UESP (version=$version)...\n";
	return uesp_eso_fetch_export_json_via_http($version, $itemKeys, true);
}

function uesp_import_item_search_tables(mysqli $db, string $root, string $version, array $itemFromFiles): void
{
	$data = uesp_load_item_search_payload($root, $version, $itemFromFiles);
	if ($data === null || empty($data['minedItemSummary']) || !is_array($data['minedItemSummary'])) {
		fwrite(STDERR, "Item search import failed: need non-empty minedItemSummary.\n");
		fwrite(STDERR, "Save JSON from a browser to data/uesp-export/items.json:\n");
		fwrite(STDERR, "  https://esolog.uesp.net/exportJson.php?version=$version&table%5B%5D=minedItemSummary&table%5B%5D=minedItem\n");
		fwrite(STDERR, "Then: php scripts/import-mined-data.php --only-item-search --from-file=data/uesp-export/items.json\n");
		exit(1);
	}
	if (empty($data['minedItem']) || !is_array($data['minedItem'])) {
		echo "Warning: minedItem missing or empty — item search works; equipping some sets may fail until minedItem is imported.\n";
	}

	uesp_ensure_mined_item_table($db, 'minedItemSummary', $data['minedItemSummary']);
	uesp_bulk_insert($db, 'minedItemSummary', uesp_normalize_export_rows($data['minedItemSummary']), 25);

	if (!empty($data['minedItem']) && is_array($data['minedItem'])) {
		uesp_ensure_mined_item_table($db, 'minedItem', $data['minedItem']);
		uesp_bulk_insert($db, 'minedItem', uesp_normalize_export_rows($data['minedItem']), 6);
	}
}

if ($onlyItemSearch) {
	global $uespEsoLogReadDBHost, $uespEsoLogReadUser, $uespEsoLogReadPW, $uespEsoLogDatabase;

	$db = new mysqli($uespEsoLogReadDBHost, $uespEsoLogReadUser, $uespEsoLogReadPW, $uespEsoLogDatabase);
	if ($db->connect_error) {
		fwrite(STDERR, 'MySQL connect failed: ' . $db->connect_error . "\n");
		exit(1);
	}
	$db->set_charset('utf8mb4');

	$filesForItems = count($itemFromFiles) > 0 ? $itemFromFiles : $fromFiles;
	uesp_import_item_search_tables($db, $root, $version, $filesForItems);
	$db->close();
	echo "Item search tables ready. Restart php -S and try the set/item picker again.\n";
	exit(0);
}

$allTables = array(
	'cp2Disciplines',
	'cp2ClusterRoots',
	'cp2SkillLinks',
	'cp2Skills',
	'cp2SkillDescriptions',
	'minedSkills',
	'skillTree',
	'skillTooltips',
);

$requiredTables = $skipMinedSkills
	? array_values(array_diff($allTables, array('minedSkills')))
	: $allTables;

if (count($fromFiles) > 0) {
	$data = array();
	foreach ($fromFiles as $fromFile) {
		if (!is_readable($fromFile)) {
			fwrite(STDERR, "Cannot read file: $fromFile\n");
			exit(1);
		}
		$raw = file_get_contents($fromFile);
		$chunk = json_decode($raw, true);
		if (!is_array($chunk)) {
			fwrite(STDERR, "Invalid JSON in $fromFile\n");
			exit(1);
		}
		$n = 0;
		foreach ($allTables as $t) {
			if ($skipMinedSkills && $t === 'minedSkills') {
				continue;
			}
			if (array_key_exists($t, $chunk) && is_array($chunk[$t])) {
				$data[$t] = $chunk[$t];
				++$n;
			}
		}
		echo "Loaded $n table(s) from $fromFile\n";
	}
} else {
	if (!defined('UESP_ESO_REMOTE_EXPORT_JSON_URL') || UESP_ESO_REMOTE_EXPORT_JSON_URL === '') {
		define('UESP_ESO_REMOTE_EXPORT_JSON_URL', 'https://esolog.uesp.net/exportJson.php');
	}
	echo "Fetching export from UESP (version=$version)...\n";
	$data = uesp_eso_fetch_export_json_via_http($version, $requiredTables, true);
	if ($data === null || !uesp_eso_export_payload_has_tables($data, $requiredTables)) {
		fwrite(STDERR, "HTTP fetch failed or incomplete. Save JSON from a browser to data/uesp-export/mined-export.json and run:\n");
		fwrite(STDERR, "  php scripts/import-mined-data.php --from-file=data/uesp-export/mined-export.json\n");
		exit(1);
	}
}

$partialFromFiles = count($fromFiles) > 0;
if ($partialFromFiles) {
	$tablesToImport = array();
	foreach ($allTables as $t) {
		if ($skipMinedSkills && $t === 'minedSkills') {
			continue;
		}
		if (!empty($data[$t]) && is_array($data[$t]) && count($data[$t]) > 0) {
			$tablesToImport[] = $t;
		}
	}
	if (count($tablesToImport) === 0) {
		fwrite(STDERR, "No non-empty tables found in --from-file payload.\n");
		exit(1);
	}
	$validateTables = $tablesToImport;
	echo "Importing only tables present in files: " . implode(', ', $tablesToImport) . "\n";
} else {
	$tablesToImport = $requiredTables;
	$validateTables = $requiredTables;
}

if (!uesp_eso_export_payload_has_tables($data, $validateTables)) {
	fwrite(STDERR, "JSON is missing required non-empty tables: " . implode(', ', $validateTables) . "\n");
	exit(1);
}

if ($skipMinedSkills) {
	echo "Skipping minedSkills: not imported; existing minedSkills rows in MySQL are left unchanged.\n";
}

global $uespEsoLogReadDBHost, $uespEsoLogReadUser, $uespEsoLogReadPW, $uespEsoLogDatabase;

$db = new mysqli($uespEsoLogReadDBHost, $uespEsoLogReadUser, $uespEsoLogReadPW, $uespEsoLogDatabase);
if ($db->connect_error) {
	fwrite(STDERR, 'MySQL connect failed: ' . $db->connect_error . "\n");
	exit(1);
}
$db->set_charset('utf8mb4');

$exportDir = $root . '/data/uesp-export';
if (!$noCache) {
	if (!is_dir($exportDir)) {
		mkdir($exportDir, 0777, true);
	}
	$cacheFile = $exportDir . '/mined-export.json';
	file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
	echo "Wrote cache: $cacheFile\n";
} else {
	echo "Skipped mined-export.json (--no-cache).\n";
}

echo "Importing into MySQL database: $uespEsoLogDatabase\n";

$insertBatchSizes = array(
	'minedSkills' => 8,
	'skillTree' => 15,
	'skillTooltips' => 20,
);
foreach ($tablesToImport as $t) {
	if ($skipMinedSkills && $t === 'minedSkills') {
		continue;
	}
	$bs = array_key_exists($t, $insertBatchSizes) ? $insertBatchSizes[$t] : 12;
	uesp_bulk_insert($db, $t, uesp_normalize_export_rows($data[$t]), $bs);
}

if ($withItemSearch) {
	echo "Importing item search tables (minedItemSummary / minedItem)...\n";
	uesp_import_item_search_tables($db, $root, $version, $itemFromFiles);
}

$db->close();

echo "Done. Restart the PHP server and open testBuild.php — local MySQL is used first; mined-export.json is a fallback if tables are empty.\n";
