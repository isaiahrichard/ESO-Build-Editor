<?php
/**
 * MySQL storage for testBuild.php local editor saves (table editor_local_builds).
 * Uses secrets/esobuilddata.secrets.php (UESP_MYSQL_* via getenv in that file).
 *
 * Important: secrets must be loaded at file scope (not inside a function), or PHP
 * assigns $uespEsoBuildDataRead* only in that function's local scope and mysqli gets
 * empty user/password.
 */
declare(strict_types=1);

require_once __DIR__ . '/../secrets/esobuilddata.secrets.php';

/** @return mysqli|null */
function local_editor_builds_connect()
{
	global $uespEsoBuildDataReadDBHost, $uespEsoBuildDataReadUser, $uespEsoBuildDataReadPW, $uespEsoBuildDataDatabase;

	try {
		$db = new mysqli(
			$uespEsoBuildDataReadDBHost,
			$uespEsoBuildDataReadUser,
			$uespEsoBuildDataReadPW,
			$uespEsoBuildDataDatabase
		);
	} catch (Throwable $e) {
		return null;
	}
	if ($db->connect_error) {
		return null;
	}
	$db->set_charset('utf8mb4');

	return $db;
}

function local_editor_builds_ensure_table(mysqli $db): bool
{
	$sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `editor_local_builds` (
  `id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(512) NOT NULL DEFAULT '',
  `modified` DATETIME NOT NULL,
  `savedata` LONGTEXT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_editor_local_modified` (`modified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

	return $db->query($sql) === true;
}

/**
 * @return array<string,mixed>|null Full savedata object for ApplyEsoLocalBuildSaveData
 */
function local_editor_builds_load_savedata(int $id): ?array
{
	if ($id <= 0) {
		return null;
	}
	$db = local_editor_builds_connect();
	if ($db === null || !local_editor_builds_ensure_table($db)) {
		return null;
	}
	$stmt = $db->prepare('SELECT `savedata` FROM `editor_local_builds` WHERE `id` = ? LIMIT 1');
	if ($stmt === false) {
		$db->close();
		return null;
	}
	$stmt->bind_param('i', $id);
	$stmt->execute();
	$res = $stmt->get_result();
	$row = $res ? $res->fetch_assoc() : null;
	$stmt->close();
	$db->close();
	if ($row === null || empty($row['savedata'])) {
		return null;
	}
	$data = json_decode((string) $row['savedata'], true);

	return is_array($data) ? $data : null;
}
