<?php

/**
 * Mined CP/skill data: workspace JSON cache, then UESP exportJson.php (browser-style API).
 *
 * HTTP order: ext-curl → file_get_contents (openssl) → system curl/curl.exe.
 */

function uesp_eso_remote_mined_data_enabled()
{
	return defined('UESP_ESO_USE_REMOTE_MINED_DATA') && UESP_ESO_USE_REMOTE_MINED_DATA
		&& defined('UESP_ESO_REMOTE_EXPORT_JSON_URL') && UESP_ESO_REMOTE_EXPORT_JSON_URL !== '';
}


/**
 * @return bool True if TLS peer verification should be used for HTTPS (ext-curl / streams).
 */
function uesp_eso_remote_ssl_verify_peer()
{
	if (defined('UESP_ESO_REMOTE_SSL_RELAXED') && UESP_ESO_REMOTE_SSL_RELAXED) {
		return false;
	}
	return true;
}


/**
 * GET URL body via Windows curl.exe or POSIX curl (bypasses PHP openssl when extension is missing).
 *
 * @return string|null
 */
function uesp_eso_fetch_url_via_system_curl($url)
{
	if (!function_exists('proc_open')) {
		return null;
	}

	$isWin = (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows')
		|| (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
	$bin = $isWin ? 'curl.exe' : 'curl';

	$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0';

	$descriptorspec = array(
		0 => array('pipe', 'r'),
		1 => array('pipe', 'w'),
		2 => array('pipe', 'w'),
	);
	$pipes = array();

	if (PHP_VERSION_ID >= 70400) {
		$argv = array(
			$bin,
			'-sS',
			'-L',
			'--max-time',
			'180',
			'-A',
			$ua,
			'-H',
			'Accept: application/json',
			$url,
		);
		$proc = @proc_open(
			$argv,
			$descriptorspec,
			$pipes,
			null,
			null,
			array('bypass_shell' => true)
		);
	} else {
		$cmd = $bin
			. ' -sS -L --max-time 180'
			. ' -A ' . escapeshellarg($ua)
			. ' -H ' . escapeshellarg('Accept: application/json')
			. ' ' . escapeshellarg($url);
		$proc = @proc_open($cmd, $descriptorspec, $pipes, null, null);
	}

	if (!is_resource($proc)) {
		error_log('uesp_eso_fetch_url_via_system_curl: proc_open failed for ' . $bin);
		return null;
	}
	fclose($pipes[0]);
	$body = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$code = proc_close($proc);

	if ($code !== 0) {
		error_log('uesp_eso_fetch_url_via_system_curl: exit ' . $code . ' stderr: ' . trim($err));
		return null;
	}

	return $body !== false ? $body : null;
}


/**
 * @param array<string,mixed> $data
 * @param list<string> $tables
 */
function uesp_eso_export_payload_has_tables($data, array $tables)
{
	if (!is_array($data) || !empty($data['error'])) {
		return false;
	}
	foreach ($tables as $t) {
		if (!array_key_exists($t, $data) || !is_array($data[$t])) {
			return false;
		}
	}
	if (in_array('cp2Disciplines', $tables, true) && count($data['cp2Disciplines']) < 1) {
		return false;
	}
	if (in_array('minedSkills', $tables, true) && count($data['minedSkills']) < 1) {
		return false;
	}
	if (in_array('skillTree', $tables, true) && count($data['skillTree']) < 1) {
		return false;
	}
	return true;
}


/**
 * Read merged export JSON from workspace data/uesp-export/ (written by scripts/import-mined-data.php).
 *
 * @param list<string> $tables
 * @return array<string,mixed>|null
 */
function uesp_eso_try_workspace_export_cache(array $tables)
{
	$paths = array();
	if (defined('UESP_ESO_EXPORT_JSON_CACHE_PATH') && UESP_ESO_EXPORT_JSON_CACHE_PATH !== '') {
		$paths[] = UESP_ESO_EXPORT_JSON_CACHE_PATH;
	}
	$root = dirname(__DIR__);
	$paths[] = $root . '/data/uesp-export/mined-export.json';
	$paths[] = $root . '/data/uesp-export/mined-export-49.json';

	foreach (array_unique($paths) as $path) {
		if ($path === '' || !is_readable($path)) {
			continue;
		}
		$raw = @file_get_contents($path);
		if ($raw === false) {
			continue;
		}
		$t = ltrim($raw);
		if ($t === '' || ($t[0] !== '{' && $t[0] !== '[')) {
			continue;
		}
		$data = json_decode($raw, true);
		if (!is_array($data)) {
			continue;
		}
		if (uesp_eso_export_payload_has_tables($data, $tables)) {
			error_log('uesp_eso_try_workspace_export_cache: using ' . $path);
			return $data;
		}
	}

	return null;
}


/**
 * HTTP GET exportJson.php only (no workspace cache).
 *
 * @param list<string> $tables
 * @param bool $ignoreRemoteFlag CLI import script sets true to fetch even when UESP_ESO_USE_REMOTE_MINED_DATA is false
 * @return array<string,mixed>|null
 */
function uesp_eso_fetch_export_json_via_http($version, array $tables, $ignoreRemoteFlag = false)
{
	if (!$ignoreRemoteFlag && !uesp_eso_remote_mined_data_enabled()) {
		return null;
	}

	$params = array('table' => $tables);
	if ($version !== '') {
		$params['version'] = $version;
	}

	$url = rtrim(UESP_ESO_REMOTE_EXPORT_JSON_URL, '?&') . '?' . http_build_query($params);

	$body = null;
	$verifySsl = uesp_eso_remote_ssl_verify_peer();

	if (function_exists('curl_init')) {
		$ch = curl_init($url);
		$opts = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 180,
			CURLOPT_HTTPHEADER => array(
				'User-Agent: Mozilla/5.0 (compatible; ESOBuildEditorLocal/1.0)',
				'Accept: application/json',
			),
			CURLOPT_SSL_VERIFYPEER => $verifySsl,
			CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
		);
		curl_setopt_array($ch, $opts);
		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($body === false || $code !== 200) {
			error_log("uesp_eso_fetch_export_json_via_http: ext-curl HTTP $code for $url");
			$body = null;
		}
	}

	if ($body === null && extension_loaded('openssl') && filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
		$ctx = stream_context_create(array(
			'http' => array(
				'timeout' => 180,
				'header' => "User-Agent: Mozilla/5.0 (compatible; ESOBuildEditorLocal/1.0)\r\nAccept: application/json\r\n",
			),
			'ssl' => array(
				'verify_peer' => $verifySsl,
				'verify_peer_name' => $verifySsl,
			),
		));
		$try = @file_get_contents($url, false, $ctx);
		if ($try !== false) {
			$body = $try;
		} else {
			$last = error_get_last();
			error_log('uesp_eso_fetch_export_json_via_http: file_get_contents failed: ' . ($last['message'] ?? ''));
		}
	} elseif ($body === null && !extension_loaded('openssl')) {
		error_log('uesp_eso_fetch_export_json_via_http: PHP openssl not loaded; trying system curl');
	}

	if ($body === null) {
		$body = uesp_eso_fetch_url_via_system_curl($url);
		if ($body === null) {
			return null;
		}
	}

	$t = ltrim($body);
	if ($t === '' || ($t[0] !== '{' && $t[0] !== '[')) {
		error_log('uesp_eso_fetch_export_json_via_http: response is not JSON (blocked HTML or empty?)');
		return null;
	}

	$data = json_decode($body, true);
	if (!is_array($data)) {
		error_log('uesp_eso_fetch_export_json_via_http: json_decode failed');
		return null;
	}
	if (!empty($data['error'])) {
		error_log('uesp_eso_fetch_export_json_via_http: API error: ' . print_r($data['error'], true));
		return null;
	}

	return $data;
}


/**
 * Cache file first (data/uesp-export/mined-export.json), then HTTP if remote is enabled.
 *
 * @param list<string> $tables
 * @return array<string,mixed>|null
 */
function uesp_eso_get_mined_export_json($version, array $tables)
{
	$cached = uesp_eso_try_workspace_export_cache($tables);
	if ($cached !== null) {
		return $cached;
	}
	return uesp_eso_fetch_export_json_via_http($version, $tables);
}


/**
 * @deprecated Use uesp_eso_get_mined_export_json()
 */
function uesp_eso_fetch_export_json($version, array $tables)
{
	return uesp_eso_get_mined_export_json($version, $tables);
}
