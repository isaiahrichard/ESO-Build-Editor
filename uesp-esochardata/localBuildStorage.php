<?php
/**
 * Local-only JSON storage for testBuild.php (php -S). Writes under local-builds/.
 * Restricted to loopback clients.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if ($remote !== '127.0.0.1' && $remote !== '::1') {
	http_response_code(403);
	echo json_encode(['success' => false, 'errors' => ['localBuildStorage: only loopback allowed']]);
	exit;
}

$baseDir = __DIR__ . '/local-builds';
if (!is_dir($baseDir)) {
	if (!mkdir($baseDir, 0755, true)) {
		http_response_code(500);
		echo json_encode(['success' => false, 'errors' => ['Could not create local-builds directory']]);
		exit;
	}
}

$manifestPath = $baseDir . '/manifest.json';

function local_builds_read_manifest(string $manifestPath): array
{
	if (!is_file($manifestPath)) {
		return ['builds' => []];
	}
	$raw = file_get_contents($manifestPath);
	$j = json_decode($raw === false ? 'null' : $raw, true);
	return is_array($j) ? $j : ['builds' => []];
}

function local_builds_write_manifest(string $manifestPath, array $data): void
{
	file_put_contents($manifestPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function local_builds_next_id(array $manifest): int
{
	$max = 0;
	foreach ($manifest['builds'] as $b) {
		$id = (int) ($b['id'] ?? 0);
		if ($id > $max) {
			$max = $id;
		}
	}
	return $max + 1;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($method === 'GET' && ($action === 'list' || $action === '')) {
	$m = local_builds_read_manifest($manifestPath);
	echo json_encode(['success' => true, 'builds' => $m['builds']]);
	exit;
}

if ($method === 'GET' && $action === 'load') {
	$id = (int) ($_GET['id'] ?? 0);
	if ($id <= 0) {
		echo json_encode(['success' => false, 'errors' => ['Invalid id']]);
		exit;
	}
	$path = $baseDir . '/' . $id . '.json';
	if (!is_file($path)) {
		echo json_encode(['success' => false, 'errors' => ['Build not found']]);
		exit;
	}
	$raw = file_get_contents($path);
	$data = json_decode($raw === false ? 'null' : $raw, true);
	if (!is_array($data)) {
		echo json_encode(['success' => false, 'errors' => ['Invalid JSON file']]);
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
		echo json_encode(['success' => false, 'errors' => ['Invalid savedata JSON']]);
		exit;
	}

	$m = local_builds_read_manifest($manifestPath);
	$isNew = false;

	if ($id <= 0 || $copy) {
		$id = local_builds_next_id($m);
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

	$path = $baseDir . '/' . $id . '.json';
	if (file_put_contents($path, json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
		echo json_encode(['success' => false, 'errors' => ['Failed to write file']]);
		exit;
	}

	$now = date('c');
	$found = false;
	foreach ($m['builds'] as &$b) {
		if ((int) ($b['id'] ?? 0) === $id) {
			$b['name'] = $name;
			$b['modified'] = $now;
			$found = true;
			break;
		}
	}
	unset($b);
	if (!$found) {
		$m['builds'][] = ['id' => $id, 'name' => $name, 'modified' => $now];
	}
	local_builds_write_manifest($manifestPath, $m);

	echo json_encode(['success' => true, 'id' => $id, 'isnew' => $isNew]);
	exit;
}

if ($method === 'POST' && $action === 'delete') {
	$id = (int) ($_POST['id'] ?? 0);
	if ($id <= 0) {
		echo json_encode(['success' => false, 'errors' => ['Invalid id']]);
		exit;
	}
	$path = $baseDir . '/' . $id . '.json';
	if (is_file($path)) {
		@unlink($path);
	}
	$m = local_builds_read_manifest($manifestPath);
	$m['builds'] = array_values(array_filter($m['builds'], static function ($b) use ($id) {
		return (int) ($b['id'] ?? 0) !== $id;
	}));
	local_builds_write_manifest($manifestPath, $m);
	echo json_encode(['success' => true]);
	exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'errors' => ['Unknown action']]);
