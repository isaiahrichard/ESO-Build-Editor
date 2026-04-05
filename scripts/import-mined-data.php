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
 * Slow HTTPS / system curl timeouts: export JSON uses UESP_ESO_EXPORT_HTTP_TIMEOUT (seconds, default 900, max 7200).
 * Example: UESP_ESO_EXPORT_HTTP_TIMEOUT=1800 php scripts/import-mined-data.php --only-item-search
 * If system curl fails with exit 18 (transfer closed early), we use HTTP/1.1 and retry; tune UESP_ESO_EXPORT_CURL_RETRIES (1–10, default 3).
 *
 * With --from-file only: each run imports whatever non-empty tables appear in your JSON (others are not truncated).
 * Example: --from-file=minedSkills.json updates minedSkills only; combine multiple --from-file to merge tables first.
 *
 * Local item search / set picker (/_esolog_api/ + minedItemSummary + minedItem):
 *   php scripts/import-mined-data.php --only-item-search
 * HTTP import uses two sequential exportJson requests (summary, then minedItem) to avoid one huge download.
 * If Cloudflare blocks PHP, save JSON from a logged-in browser, then:
 *   php scripts/import-mined-data.php --only-item-search --from-file=data/uesp-export/items.json
 * If minedItem never appears in MySQL, see scripts/MINED_ITEMS.md (browser JSON, mysqldump, PHP curl).
 *
 * Browser URL (single file with both tables is fine for --from-file):
 *   https://esolog.uesp.net/exportJson.php?version=49&table%5B%5D=minedItemSummary&table%5B%5D=minedItem
 * After a normal import, add items without re-fetching CP/skills:
 *   php scripts/import-mined-data.php --with-item-search
 * Optional: --item-from-file=items.json with --with-item-search (uses file instead of HTTP for item tables).
 *
 * Fill minedItem using itemIds from local minedItemSummary (exportJson requires ids= for minedItem):
 *   php scripts/import-mined-data.php --backfill-mined-item-from-summary
 * Optional: --backfill-batch-size=80 --backfill-sleep-ms=250
 * Env: UESP_ESO_MINED_ITEM_ID_BATCH (default 80), UESP_ESO_MINED_ITEM_BACKFILL_SLEEP_MS
 *
 * Scribing (crafted grimoires + focus/signature/affix scripts):
 *   php scripts/import-mined-data.php --only-crafted
 * Or save JSON from a browser (same host as exportJson) then:
 *   php scripts/import-mined-data.php --only-crafted --from-file=data/uesp-export/crafted-export.json
 * Browser URL (version 49 example):
 *   https://esolog.uesp.net/exportJson.php?version=49&table%5B%5D=craftedSkills&table%5B%5D=craftedScripts&table%5B%5D=craftedScriptDescriptions
 * Requires exportJson.php to whitelist those tables (see uesp-esolog/exportJson.php). Until the public API
 * includes them, use a saved JSON file from an environment that has the data, or run exportJson locally
 * against a MySQL database that already contains the rows.
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
$backfillMinedItemFromSummary = false;
$backfillBatchSize = 0;
$backfillSleepMs = -1;
$onlyCrafted = false;

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
	} elseif ($arg === '--backfill-mined-item-from-summary') {
		$backfillMinedItemFromSummary = true;
	} elseif ($arg === '--only-crafted') {
		$onlyCrafted = true;
	} elseif (preg_match('/^--backfill-batch-size=(\d+)$/', $arg, $m)) {
		$backfillBatchSize = (int) $m[1];
	} elseif (preg_match('/^--backfill-sleep-ms=(\d+)$/', $arg, $m)) {
		$backfillSleepMs = (int) $m[1];
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

if ($onlyCrafted && ($onlyItemSearch || $withItemSearch || $backfillMinedItemFromSummary)) {
	fwrite(STDERR, "Do not combine --only-crafted with item-search or backfill options.\n");
	exit(1);
}

if ($backfillMinedItemFromSummary && ($onlyItemSearch || $withItemSearch)) {
	fwrite(STDERR, "Do not combine --backfill-mined-item-from-summary with --only-item-search or --with-item-search.\n");
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
function uesp_bulk_insert(mysqli $db, string $table, array $rows, int $batchSize = 12, bool $doTruncate = true)
{
	if (empty($rows)) {
		echo "  (no rows for $table)\n";
		uesp_eso_cli_progress_log("bulk_insert: $table — no rows, skipping");
		return;
	}
	$rowCount = count($rows);
	uesp_eso_cli_progress_log("bulk_insert: $table — $rowCount rows, batch size $batchSize");
	$cols = array_keys($rows[0]);
	$colSql = uesp_sql_backtick_cols($cols);
	$tableEsc = '`' . str_replace('`', '``', $table) . '`';

	if ($doTruncate) {
		uesp_eso_cli_progress_log("bulk_insert: $table — TRUNCATE...");
		if (!$db->query("TRUNCATE TABLE $tableEsc")) {
			fwrite(STDERR, "TRUNCATE failed ($table): " . $db->error . "\n");
			exit(1);
		}
	}
	uesp_eso_cli_progress_log("bulk_insert: $table — inserting...");

	$batch = array();
	$n = 0;
	$batchesDone = 0;
	$progressEvery = max(1, (int) (5000 / max(1, $batchSize)));
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
			++$batchesDone;
			$batch = array();
			if ($batchesDone % $progressEvery === 0) {
				uesp_eso_cli_progress_log("bulk_insert: $table — $n / $rowCount rows committed");
			}
		}
	}
	if (count($batch) > 0) {
		$sql = "INSERT INTO $tableEsc ($colSql) VALUES " . implode(',', $batch);
		if (!$db->query($sql)) {
			fwrite(STDERR, "INSERT failed ($table): " . $db->error . "\n");
			exit(1);
		}
	}
	uesp_eso_cli_progress_log("bulk_insert: $table — done, $n rows total");
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
		uesp_eso_cli_progress_log('item search: reading ' . count($itemFromFiles) . ' JSON file(s) (no HTTP)');
		foreach ($itemFromFiles as $fromFile) {
			if (!is_readable($fromFile)) {
				fwrite(STDERR, "Cannot read file: $fromFile\n");
				exit(1);
			}
			uesp_eso_cli_progress_log('item search: loading ' . $fromFile . ' ...');
			$rawFile = file_get_contents($fromFile);
			uesp_eso_cli_progress_log('item search: ' . basename($fromFile) . ' read, ' . strlen($rawFile) . ' bytes, json_decode...');
			$chunk = json_decode($rawFile, true);
			if (!is_array($chunk)) {
				fwrite(STDERR, "Invalid JSON in $fromFile\n");
				exit(1);
			}
			foreach ($itemKeys as $t) {
				if (array_key_exists($t, $chunk) && is_array($chunk[$t]) && count($chunk[$t]) > 0) {
					$data[$t] = $chunk[$t];
				}
			}
			uesp_eso_cli_progress_log('item search: parsed ' . basename($fromFile));
			echo "Loaded item table keys from $fromFile\n";
		}
		return count($data) > 0 ? $data : null;
	}

	if (!defined('UESP_ESO_REMOTE_EXPORT_JSON_URL') || UESP_ESO_REMOTE_EXPORT_JSON_URL === '') {
		define('UESP_ESO_REMOTE_EXPORT_JSON_URL', 'https://esolog.uesp.net/exportJson.php');
	}
	echo "Fetching minedItemSummary + minedItem from UESP (version=$version)...\n";
	uesp_eso_cli_progress_log('item search: two HTTP requests (summary, then minedItem) — see exportJson lines below');
	return uesp_eso_fetch_item_search_tables_http($version, true);
}

function uesp_import_item_search_tables(mysqli $db, string $root, string $version, array $itemFromFiles): void
{
	uesp_eso_cli_progress_log('item search: fetching payload...');
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
		fwrite(STDERR, "How to get minedItem: see scripts/MINED_ITEMS.md\n");
		fwrite(STDERR, '  - Browser: save JSON from exportJson (confirm the file contains a large "minedItem" array).' . "\n");
		fwrite(STDERR, "  - Or mysqldump minedItem+minedItemSummary from any MySQL that has them, then mysql < dump.sql\n");
	}

	$summaryCount = count($data['minedItemSummary']);
	$itemCount = (!empty($data['minedItem']) && is_array($data['minedItem'])) ? count($data['minedItem']) : 0;
	uesp_eso_cli_progress_log("item search: got minedItemSummary=$summaryCount rows, minedItem=$itemCount rows");

	uesp_eso_cli_progress_log('item search: normalizing minedItemSummary (can take a while)...');
	$normSummary = uesp_normalize_export_rows($data['minedItemSummary']);
	uesp_eso_cli_progress_log('item search: CREATE TABLE minedItemSummary if needed...');
	uesp_ensure_mined_item_table($db, 'minedItemSummary', $data['minedItemSummary']);
	uesp_bulk_insert($db, 'minedItemSummary', $normSummary, 25);

	if (!empty($data['minedItem']) && is_array($data['minedItem'])) {
		uesp_eso_cli_progress_log('item search: normalizing minedItem...');
		$normItem = uesp_normalize_export_rows($data['minedItem']);
		uesp_eso_cli_progress_log('item search: CREATE TABLE minedItem if needed...');
		uesp_ensure_mined_item_table($db, 'minedItem', $data['minedItem']);
		uesp_bulk_insert($db, 'minedItem', $normItem, 6);
	}
}

/**
 * Populate minedItem via exportJson using distinct itemId values from local minedItemSummary.
 * exportJson requires ids= for minedItem; bulk table=minedItem alone returns "Missing required item id!".
 */
function uesp_backfill_mined_item_from_summary(mysqli $db, string $version, int $batchSize, int $sleepMs): void
{
	if (!defined('UESP_ESO_REMOTE_EXPORT_JSON_URL') || UESP_ESO_REMOTE_EXPORT_JSON_URL === '') {
		define('UESP_ESO_REMOTE_EXPORT_JSON_URL', 'https://esolog.uesp.net/exportJson.php');
	}

	$res = $db->query('SELECT DISTINCT itemId FROM minedItemSummary WHERE itemId > 0 ORDER BY itemId');
	if ($res === false) {
		fwrite(STDERR, 'backfill: query failed: ' . $db->error . "\n");
		exit(1);
	}
	$ids = array();
	while ($row = $res->fetch_assoc()) {
		$ids[] = (int) $row['itemId'];
	}
	$res->free();

	if (count($ids) === 0) {
		fwrite(STDERR, "backfill: no itemIds in minedItemSummary. Run --only-item-search first.\n");
		exit(1);
	}

	$nIds = count($ids);
	uesp_eso_cli_progress_log("backfill minedItem: $nIds distinct itemIds, batch size $batchSize");

	$chunks = array_chunk($ids, max(1, $batchSize));
	$nChunks = count($chunks);
	$tableEnsured = false;
	$firstWrite = true;
	$totalRows = 0;

	for ($ci = 0; $ci < $nChunks; ++$ci) {
		$chunk = $chunks[$ci];
		$idList = implode(',', $chunk);
		uesp_eso_cli_progress_log('backfill minedItem: chunk ' . ($ci + 1) . "/$nChunks (" . count($chunk) . ' ids)...');

		$data = uesp_eso_fetch_export_json_via_http($version, array('minedItem'), true, array('ids' => $idList));
		if ($data === null) {
			fwrite(STDERR, 'backfill: HTTP failed on chunk ' . ($ci + 1) . ". Try smaller --backfill-batch-size or use browser + --from-file.\n");
			exit(1);
		}
		if (empty($data['minedItem']) || !is_array($data['minedItem'])) {
			uesp_eso_cli_progress_log('backfill minedItem: chunk ' . ($ci + 1) . ' — no rows (skipped)');
			if ($sleepMs > 0 && $ci + 1 < $nChunks) {
				usleep($sleepMs * 1000);
			}
			continue;
		}

		$rawRows = $data['minedItem'];
		$norm = uesp_normalize_export_rows($rawRows);
		if (!$tableEnsured) {
			uesp_ensure_mined_item_table($db, 'minedItem', $rawRows);
			$tableEnsured = true;
		}
		$totalRows += count($norm);
		uesp_bulk_insert($db, 'minedItem', $norm, 6, $firstWrite);
		$firstWrite = false;

		if ($sleepMs > 0 && $ci + 1 < $nChunks) {
			usleep($sleepMs * 1000);
		}
	}

	if (!$tableEnsured) {
		fwrite(STDERR, "backfill: API returned no minedItem rows for any chunk.\n");
		exit(1);
	}

	echo "Backfill minedItem finished: about $totalRows row(s) inserted in $nChunks chunk(s).\n";
}

if ($onlyCrafted) {
	global $uespEsoLogReadDBHost, $uespEsoLogReadUser, $uespEsoLogReadPW, $uespEsoLogDatabase;

	$craftedTables = array('craftedSkills', 'craftedScripts', 'craftedScriptDescriptions');
	$data = null;

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
			foreach ($craftedTables as $t) {
				if (array_key_exists($t, $chunk) && is_array($chunk[$t]) && count($chunk[$t]) > 0) {
					$data[$t] = $chunk[$t];
				}
			}
			echo "Loaded crafted table keys from $fromFile\n";
		}
	} else {
		if (!defined('UESP_ESO_REMOTE_EXPORT_JSON_URL') || UESP_ESO_REMOTE_EXPORT_JSON_URL === '') {
			define('UESP_ESO_REMOTE_EXPORT_JSON_URL', 'https://esolog.uesp.net/exportJson.php');
		}
		echo "Fetching craftedSkills + craftedScripts + craftedScriptDescriptions from UESP (version=$version)...\n";
		$data = uesp_eso_fetch_export_json_via_http($version, $craftedTables, true);
	}

	if ($data === null || !uesp_eso_export_payload_has_crafted_tables($data)) {
		fwrite(STDERR, "Crafted import failed: need non-empty craftedSkills, craftedScripts, and craftedScriptDescriptions.\n");
		fwrite(STDERR, "If the API returned errors, exportJson on the server must allow these tables (uesp-esolog/exportJson.php).\n");
		fwrite(STDERR, "Save JSON from a browser to data/uesp-export/crafted-export.json, then:\n");
		fwrite(STDERR, "  php scripts/import-mined-data.php --only-crafted --from-file=data/uesp-export/crafted-export.json\n");
		exit(1);
	}

	uesp_eso_cli_progress_log('--only-crafted: version=' . $version);
	uesp_eso_cli_progress_log('MySQL: connecting to ' . $uespEsoLogReadDBHost . ' database ' . $uespEsoLogDatabase . ' ...');

	$db = new mysqli($uespEsoLogReadDBHost, $uespEsoLogReadUser, $uespEsoLogReadPW, $uespEsoLogDatabase);
	if ($db->connect_error) {
		fwrite(STDERR, 'MySQL connect failed: ' . $db->connect_error . "\n");
		exit(1);
	}
	$db->set_charset('utf8mb4');
	uesp_eso_cli_progress_log('MySQL: connected');

	$craftedBatch = array('craftedSkills' => 8, 'craftedScripts' => 15, 'craftedScriptDescriptions' => 10);
	foreach ($craftedTables as $t) {
		$bs = array_key_exists($t, $craftedBatch) ? $craftedBatch[$t] : 12;
		uesp_bulk_insert($db, $t, uesp_normalize_export_rows($data[$t]), $bs);
	}
	$db->close();

	echo "Crafted/scribing tables imported (" . implode(', ', $craftedTables) . ").\n";
	echo "If scribed skills still show wrong tooltips, ensure minedSkills includes rows with isCrafted=1 and id>50000000 (full skills import).\n";
	exit(0);
}

if ($backfillMinedItemFromSummary) {
	global $uespEsoLogReadDBHost, $uespEsoLogReadUser, $uespEsoLogReadPW, $uespEsoLogDatabase;

	$batchSz = $backfillBatchSize > 0 ? $backfillBatchSize : (int) (getenv('UESP_ESO_MINED_ITEM_ID_BATCH') ?: 80);
	if ($batchSz < 1) {
		$batchSz = 80;
	}
	if ($batchSz > 250) {
		fwrite(STDERR, "Warning: batch size $batchSz may hit URL/proxy limits; try 80–150 if requests fail.\n");
	}

	$slp = $backfillSleepMs >= 0 ? $backfillSleepMs : (int) (getenv('UESP_ESO_MINED_ITEM_BACKFILL_SLEEP_MS') ?: 0);
	if ($slp < 0) {
		$slp = 0;
	}

	uesp_eso_cli_progress_log('--backfill-mined-item-from-summary: version=' . $version);
	uesp_eso_cli_progress_log('MySQL: connecting to ' . $uespEsoLogReadDBHost . ' database ' . $uespEsoLogDatabase . ' ...');

	$db = new mysqli($uespEsoLogReadDBHost, $uespEsoLogReadUser, $uespEsoLogReadPW, $uespEsoLogDatabase);
	if ($db->connect_error) {
		fwrite(STDERR, 'MySQL connect failed: ' . $db->connect_error . "\n");
		exit(1);
	}
	$db->set_charset('utf8mb4');
	uesp_eso_cli_progress_log('MySQL: connected');

	uesp_backfill_mined_item_from_summary($db, $version, $batchSz, $slp);
	$db->close();
	exit(0);
}

if ($onlyItemSearch) {
	global $uespEsoLogReadDBHost, $uespEsoLogReadUser, $uespEsoLogReadPW, $uespEsoLogDatabase;

	uesp_eso_cli_progress_log("--only-item-search: version=$version");
	uesp_eso_cli_progress_log('MySQL: connecting to ' . $uespEsoLogReadDBHost . ' database ' . $uespEsoLogDatabase . ' ...');

	$db = new mysqli($uespEsoLogReadDBHost, $uespEsoLogReadUser, $uespEsoLogReadPW, $uespEsoLogDatabase);
	if ($db->connect_error) {
		fwrite(STDERR, 'MySQL connect failed: ' . $db->connect_error . "\n");
		exit(1);
	}
	$db->set_charset('utf8mb4');
	uesp_eso_cli_progress_log('MySQL: connected');

	$filesForItems = count($itemFromFiles) > 0 ? $itemFromFiles : $fromFiles;
	if (count($filesForItems) > 0) {
		uesp_eso_cli_progress_log('item search: using file(s): ' . implode(', ', $filesForItems));
	}
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
