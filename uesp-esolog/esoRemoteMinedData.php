<?php

/**
 * Mined CP/skill data: workspace JSON cache, then UESP exportJson.php (browser-style API).
 *
 * HTTP order: ext-curl → file_get_contents (openssl) → system curl/curl.exe.
 */

/**
 * Timestamped line to STDERR when running under CLI (import scripts). fflush helps on Windows.
 */
function uesp_eso_cli_progress_log($message)
{
	if (php_sapi_name() !== 'cli') {
		return;
	}
	fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
	fflush(STDERR);
}

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
 * Max seconds for a single exportJson HTTP download (PHP curl, streams, system curl).
 * Large tables (e.g. minedItemSummary) often exceed 180s on modest links — override with env if needed.
 *
 * @return int Clamped 30–7200; default 900
 */
function uesp_eso_export_http_timeout_seconds()
{
	$raw = getenv('UESP_ESO_EXPORT_HTTP_TIMEOUT');
	if ($raw !== false && $raw !== '') {
		$n = (int) $raw;
		if ($n >= 30 && $n <= 7200) {
			return $n;
		}
	}
	return 900;
}


/**
 * How many times to run system curl for one URL (helps exit 18 / 56 from CDN or flaky links).
 *
 * @return int 1–10, default 3
 */
function uesp_eso_export_system_curl_max_attempts()
{
	$raw = getenv('UESP_ESO_EXPORT_CURL_RETRIES');
	if ($raw !== false && $raw !== '') {
		$n = (int) $raw;
		if ($n >= 1 && $n <= 10) {
			return $n;
		}
	}
	return 3;
}


/**
 * GET URL body via Windows curl.exe or POSIX curl (bypasses PHP openssl when extension is missing).
 *
 * Uses HTTP/1.1 (--http1.1) to reduce exit 18 "transfer closed with outstanding read data" on some HTTPS paths.
 * Retries a few times on transient curl codes (18 partial file, 52 empty reply, 56 recv failure).
 *
 * @param int|null $timeoutSeconds null = uesp_eso_export_http_timeout_seconds()
 * @return string|null
 */
function uesp_eso_fetch_url_via_system_curl($url, $timeoutSeconds = null)
{
	if (!function_exists('proc_open')) {
		return null;
	}

	if ($timeoutSeconds === null) {
		$timeoutSeconds = uesp_eso_export_http_timeout_seconds();
	}
	$maxTime = (string) max(30, (int) $timeoutSeconds);
	$maxAttempts = uesp_eso_export_system_curl_max_attempts();
	$retryExitCodes = array(18, 52, 56);

	$isWin = (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows')
		|| (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
	$bin = $isWin ? 'curl.exe' : 'curl';

	$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0';

	$descriptorspec = array(
		0 => array('pipe', 'r'),
		1 => array('pipe', 'w'),
		2 => array('pipe', 'w'),
	);

	for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
		if ($attempt > 1) {
			$wait = min(5 + ($attempt - 2) * 5, 25);
			if (php_sapi_name() === 'cli') {
				uesp_eso_cli_progress_log("system curl: retry $attempt/$maxAttempts after " . $wait . 's pause...');
			}
			sleep($wait);
		}

		$pipes = array();

		if (PHP_VERSION_ID >= 70400) {
			$argv = array(
				$bin,
				'-sS',
				'-L',
				'--http1.1',
				'--max-time',
				$maxTime,
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
				. ' -sS -L --http1.1 --max-time ' . escapeshellarg($maxTime)
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

		if ($code === 0) {
			return $body !== false ? $body : null;
		}

		error_log('uesp_eso_fetch_url_via_system_curl: exit ' . $code . ' stderr: ' . trim($err));

		if (php_sapi_name() === 'cli' && $code === 28) {
			uesp_eso_cli_progress_log(
				'system curl: timed out (exit 28). Partial downloads are discarded. '
				. 'Increase seconds: UESP_ESO_EXPORT_HTTP_TIMEOUT=' . max(1200, (int) $maxTime + 300)
				. ' (current --max-time was ' . $maxTime . 's)'
			);
		}
		if (php_sapi_name() === 'cli' && $code === 18) {
			uesp_eso_cli_progress_log(
				'system curl: exit 18 (connection closed before full body — often HTTP/2/CDN). Using --http1.1; '
				. 'set UESP_ESO_EXPORT_CURL_RETRIES=5 to try more times or use --from-file.'
			);
		}

		$willRetry = in_array($code, $retryExitCodes, true) && $attempt < $maxAttempts;
		if (!$willRetry) {
			if (php_sapi_name() === 'cli' && $code === 18) {
				uesp_eso_cli_progress_log('system curl: exit 18 after ' . $maxAttempts . ' attempt(s). Save JSON in browser → --from-file.');
			}
			return null;
		}
	}

	return null;
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
 * @param array<string,scalar> $extraQueryParams Merged into the query string after table/version (e.g. array('ids' => '1,2,3') for minedItem)
 * @return array<string,mixed>|null
 */
function uesp_eso_fetch_export_json_via_http($version, array $tables, $ignoreRemoteFlag = false, array $extraQueryParams = array())
{
	if (!$ignoreRemoteFlag && !uesp_eso_remote_mined_data_enabled()) {
		return null;
	}

	$params = array('table' => $tables);
	if ($version !== '') {
		$params['version'] = $version;
	}
	if (count($extraQueryParams) > 0) {
		$params = array_merge($params, $extraQueryParams);
	}

	$url = rtrim(UESP_ESO_REMOTE_EXPORT_JSON_URL, '?&') . '?' . http_build_query($params);

	$httpTimeout = uesp_eso_export_http_timeout_seconds();
	uesp_eso_cli_progress_log('exportJson HTTP: tables=' . implode(', ', $tables) . ' (this can take minutes for large exports)');
	uesp_eso_cli_progress_log('exportJson HTTP: GET ' . UESP_ESO_REMOTE_EXPORT_JSON_URL . ' (query len ' . strlen(http_build_query($params)) . ' bytes, timeout ' . $httpTimeout . 's — set UESP_ESO_EXPORT_HTTP_TIMEOUT to override)');

	$body = null;
	$verifySsl = uesp_eso_remote_ssl_verify_peer();

	if (function_exists('curl_init')) {
		uesp_eso_cli_progress_log('exportJson HTTP: trying PHP curl extension (timeout ' . $httpTimeout . 's)...');
		$ch = curl_init($url);
		$opts = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => $httpTimeout,
			CURLOPT_HTTPHEADER => array(
				'User-Agent: Mozilla/5.0 (compatible; ESOBuildEditorLocal/1.0)',
				'Accept: application/json',
			),
			CURLOPT_SSL_VERIFYPEER => $verifySsl,
			CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
		);
		if (defined('CURL_HTTP_VERSION_1_1')) {
			$opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
		}
		curl_setopt_array($ch, $opts);
		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$bytes = is_string($body) ? strlen($body) : 0;
		uesp_eso_cli_progress_log('exportJson HTTP: PHP curl finished, HTTP ' . $code . ', body ' . $bytes . ' bytes');
		if ($body === false || $code !== 200) {
			error_log("uesp_eso_fetch_export_json_via_http: ext-curl HTTP $code for $url");
			$body = null;
		}
	} else {
		uesp_eso_cli_progress_log('exportJson HTTP: PHP curl extension not available');
	}

	if ($body === null && extension_loaded('openssl') && filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
		uesp_eso_cli_progress_log('exportJson HTTP: trying file_get_contents (timeout ' . $httpTimeout . 's)...');
		$ctx = stream_context_create(array(
			'http' => array(
				'timeout' => $httpTimeout,
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
			uesp_eso_cli_progress_log('exportJson HTTP: file_get_contents OK, ' . strlen($body) . ' bytes');
		} else {
			$last = error_get_last();
			uesp_eso_cli_progress_log('exportJson HTTP: file_get_contents failed: ' . ($last['message'] ?? '(no message)'));
			error_log('uesp_eso_fetch_export_json_via_http: file_get_contents failed: ' . ($last['message'] ?? ''));
		}
	} elseif ($body === null && !extension_loaded('openssl')) {
		error_log('uesp_eso_fetch_export_json_via_http: PHP openssl not loaded; trying system curl');
		uesp_eso_cli_progress_log('exportJson HTTP: openssl not loaded; will try system curl');
	}

	if ($body === null) {
		uesp_eso_cli_progress_log('exportJson HTTP: trying system curl.exe/curl (max-time ' . $httpTimeout . 's)...');
		$body = uesp_eso_fetch_url_via_system_curl($url, $httpTimeout);
		if ($body === null) {
			uesp_eso_cli_progress_log('exportJson HTTP: system curl failed or returned nothing');
			return null;
		}
		uesp_eso_cli_progress_log('exportJson HTTP: system curl OK, ' . strlen($body) . ' bytes');
	}

	$t = ltrim($body);
	if ($t === '' || ($t[0] !== '{' && $t[0] !== '[')) {
		uesp_eso_cli_progress_log('exportJson HTTP: response does not look like JSON (first 120 chars): ' . substr($t, 0, 120));
		error_log('uesp_eso_fetch_export_json_via_http: response is not JSON (blocked HTML or empty?)');
		return null;
	}

	uesp_eso_cli_progress_log('exportJson HTTP: json_decode starting (' . strlen($body) . ' bytes — large payloads can sit here a while)...');
	$data = json_decode($body, true);
	uesp_eso_cli_progress_log('exportJson HTTP: json_decode finished');
	if (!is_array($data)) {
		error_log('uesp_eso_fetch_export_json_via_http: json_decode failed');
		return null;
	}
	if (!empty($data['error'])) {
		error_log('uesp_eso_fetch_export_json_via_http: API error: ' . print_r($data['error'], true));
		return null;
	}

	foreach ($tables as $t) {
		if (array_key_exists($t, $data) && is_array($data[$t])) {
			uesp_eso_cli_progress_log('exportJson payload: ' . $t . ' => ' . count($data[$t]) . ' rows');
		} else {
			uesp_eso_cli_progress_log('exportJson payload: ' . $t . ' => (missing or not array)');
		}
	}

	return $data;
}


/**
 * Fetch minedItemSummary and minedItem as two separate exportJson HTTP requests.
 *
 * One combined response can be very large (slow system curl, huge json_decode). Splitting gives
 * smaller transfers, separate timeouts per table, and CLI progress between requests.
 *
 * If the minedItem request fails (some API builds require id/ids for minedItem), the result may
 * still contain minedItemSummary only.
 *
 * @return array<string,mixed>|null Null if minedItemSummary could not be loaded
 */
function uesp_eso_fetch_item_search_tables_http(string $version, bool $ignoreRemoteFlag = true)
{
	$merged = array();
	$order = array('minedItemSummary', 'minedItem');

	foreach ($order as $t) {
		uesp_eso_cli_progress_log("item search HTTP: request " . ($t === 'minedItemSummary' ? '1/2' : '2/2') . " — table={$t}");
		$chunk = uesp_eso_fetch_export_json_via_http($version, array($t), $ignoreRemoteFlag);
		if ($chunk === null) {
			uesp_eso_cli_progress_log("item search HTTP: request failed for {$t}");
			if ($t === 'minedItemSummary') {
				return null;
			}
			continue;
		}
		if (array_key_exists($t, $chunk) && is_array($chunk[$t])) {
			$merged[$t] = $chunk[$t];
			uesp_eso_cli_progress_log('item search HTTP: merged ' . $t . ' (' . count($chunk[$t]) . ' rows)');
		} elseif ($t === 'minedItemSummary') {
			uesp_eso_cli_progress_log("item search HTTP: response missing minedItemSummary array");
			return null;
		}
	}

	return count($merged) > 0 ? $merged : null;
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
