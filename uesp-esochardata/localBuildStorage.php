<?php
/**
 * Local-only storage for testBuild.php (php -S): saves JSON blob in MySQL `editor_local_builds`.
 * Restricted to loopback clients. Table is created if missing (see also seed.sql).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if ($remote !== '127.0.0.1' && $remote !== '::1') {
	http_response_code(403);
	echo json_encode(['success' => false, 'errors' => ['localBuildStorage: only loopback allowed']]);
	exit;
}

require_once __DIR__ . '/localBuildMysql.inc.php';

$db = local_editor_builds_connect();
if ($db === null) {
	http_response_code(500);
	echo json_encode(['success' => false, 'errors' => ['Could not connect to MySQL (check secrets/esobuilddata.secrets.php and UESP_MYSQL_*)']]);
	exit;
}
if (!local_editor_builds_ensure_table($db)) {
	http_response_code(500);
	$db->close();
	echo json_encode(['success' => false, 'errors' => ['Could not create or verify editor_local_builds table']]);
	exit;
}

function local_builds_next_id(mysqli $db): int
{
	$res = $db->query('SELECT MAX(`id`) AS m FROM `editor_local_builds`');
	if ($res === false) {
		return 1;
	}
	$row = $res->fetch_assoc();
	$res->free();
	$max = isset($row['m']) ? (int) $row['m'] : 0;

	return $max + 1;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($method === 'GET' && ($action === 'list' || $action === '')) {
	$builds = [];
	$res = $db->query('SELECT `id`, `name`, `modified` FROM `editor_local_builds` ORDER BY `modified` DESC');
	if ($res !== false) {
		while ($row = $res->fetch_assoc()) {
			$builds[] = [
				'id' => (int) $row['id'],
				'name' => (string) $row['name'],
				'modified' => date('c', strtotime((string) $row['modified'])),
			];
		}
		$res->free();
	}
	$db->close();
	echo json_encode(['success' => true, 'builds' => $builds]);
	exit;
}

if ($method === 'GET' && $action === 'load') {
	$id = (int) ($_GET['id'] ?? 0);
	if ($id <= 0) {
		$db->close();
		echo json_encode(['success' => false, 'errors' => ['Invalid id']]);
		exit;
	}
	$stmt = $db->prepare('SELECT `savedata` FROM `editor_local_builds` WHERE `id` = ? LIMIT 1');
	if ($stmt === false) {
		$db->close();
		echo json_encode(['success' => false, 'errors' => ['Query failed']]);
		exit;
	}
	$stmt->bind_param('i', $id);
	$stmt->execute();
	$result = $stmt->get_result();
	$row = $result ? $result->fetch_assoc() : null;
	$stmt->close();
	$db->close();
	if ($row === null || $row['savedata'] === null || $row['savedata'] === '') {
		echo json_encode(['success' => false, 'errors' => ['Build not found']]);
		exit;
	}
	$data = json_decode((string) $row['savedata'], true);
	if (!is_array($data)) {
		echo json_encode(['success' => false, 'errors' => ['Invalid JSON in database']]);
		exit;
	}
	echo json_encode(['success' => true, 'savedata' => $data]);
	exit;
}

if ($method === 'POST' && $action === 'save') {
	$savedata = $_POST['savedata'] ?? '';
	$id = (int) ($_POST['id'] ?? 0);
	$copy = !empty($_POST['copy']);

	$parsed = json_decode($savedata, true);
	if (!is_array($parsed)) {
		$db->close();
		echo json_encode(['success' => false, 'errors' => ['Invalid savedata JSON']]);
		exit;
	}

	$isNew = false;
	if ($id <= 0 || $copy) {
		$id = local_builds_next_id($db);
		$isNew = true;
	}

	$name = 'Build ' . $id;
	if (!empty($parsed['Build']['buildName'])) {
		$name = (string) $parsed['Build']['buildName'];
	} elseif (!empty($parsed['Build']['name'])) {
		$name = (string) $parsed['Build']['name'];
	}

	if (empty($parsed['Build']) || !is_array($parsed['Build'])) {
		$parsed['Build'] = [];
	}
	$parsed['Build']['id'] = $id;

	$json = json_encode($parsed, JSON_UNESCAPED_UNICODE);
	if ($json === false) {
		$db->close();
		echo json_encode(['success' => false, 'errors' => ['Failed to encode JSON']]);
		exit;
	}

	$modifiedSql = date('Y-m-d H:i:s');
	$stmt = $db->prepare(
		'INSERT INTO `editor_local_builds` (`id`, `name`, `modified`, `savedata`) VALUES (?, ?, ?, ?)
		 ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `modified` = VALUES(`modified`), `savedata` = VALUES(`savedata`)'
	);
	if ($stmt === false) {
		$db->close();
		echo json_encode(['success' => false, 'errors' => ['Prepare failed']]);
		exit;
	}
	$stmt->bind_param('isss', $id, $name, $modifiedSql, $json);
	$ok = $stmt->execute();
	$stmt->close();
	$db->close();
	if (!$ok) {
		echo json_encode(['success' => false, 'errors' => ['Failed to save build']]);
		exit;
	}

	echo json_encode(['success' => true, 'id' => $id, 'isnew' => $isNew]);
	exit;
}

if ($method === 'POST' && $action === 'delete') {
	$id = (int) ($_POST['id'] ?? 0);
	if ($id <= 0) {
		$db->close();
		echo json_encode(['success' => false, 'errors' => ['Invalid id']]);
		exit;
	}
	$stmt = $db->prepare('DELETE FROM `editor_local_builds` WHERE `id` = ?');
	if ($stmt === false) {
		$db->close();
		echo json_encode(['success' => false, 'errors' => ['Prepare failed']]);
		exit;
	}
	$stmt->bind_param('i', $id);
	$stmt->execute();
	$stmt->close();
	$db->close();
	echo json_encode(['success' => true]);
	exit;
}

$db->close();
http_response_code(400);
echo json_encode(['success' => false, 'errors' => ['Unknown action']]);
