#!/usr/bin/env php
<?php
/**
 * Run a .sql file against local MySQL using secrets/esolog.secrets.php (same as the editor).
 *
 * Usage (from repo root):
 *   php scripts/run-sql-file.php data/crafted-from-wiki-import.sql
 *
 * Env overrides: UESP_MYSQL_HOST, UESP_MYSQL_USER, UESP_MYSQL_PASSWORD, UESP_MYSQL_DATABASE
 */

if (php_sapi_name() !== 'cli') {
	fwrite(STDERR, "CLI only.\n");
	exit(1);
}

$path = $argv[1] ?? '';
if ($path === '' || $path === '-h' || $path === '--help') {
	fwrite(STDERR, "Usage: php scripts/run-sql-file.php <path-to.sql>\n");
	exit($path === '' ? 1 : 0);
}

$root = dirname(__DIR__);
if ($path[0] !== '/' && !preg_match('#^[A-Za-z]:\\\\#', $path)) {
	$path = $root . '/' . ltrim(str_replace('\\', '/', $path), '/');
}

if (!is_readable($path)) {
	fwrite(STDERR, "Cannot read file: $path\n");
	exit(1);
}

require_once $root . '/secrets/esolog.secrets.php';

$sql = file_get_contents($path);
if ($sql === false || $sql === '') {
	fwrite(STDERR, "Empty or missing SQL.\n");
	exit(1);
}

$mysqli = new mysqli($uespEsoLogReadDBHost, $uespEsoLogReadUser, $uespEsoLogReadPW, $uespEsoLogDatabase);
if ($mysqli->connect_error) {
	fwrite(STDERR, 'MySQL connect failed: ' . $mysqli->connect_error . "\n");
	exit(1);
}
$mysqli->set_charset('utf8mb4');

if (!$mysqli->multi_query($sql)) {
	fwrite(STDERR, 'SQL error: ' . $mysqli->error . "\n");
	$mysqli->close();
	exit(1);
}

do {
	if ($result = $mysqli->store_result()) {
		$result->free();
	}
} while ($mysqli->more_results() && $mysqli->next_result());

if ($mysqli->error) {
	fwrite(STDERR, 'SQL error after batch: ' . $mysqli->error . "\n");
	$mysqli->close();
	exit(1);
}

$mysqli->close();
echo "OK: executed " . basename($path) . " on database {$uespEsoLogDatabase}\n";
exit(0);
