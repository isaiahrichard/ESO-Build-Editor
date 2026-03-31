<?php
/**
 * Router for `php -S` local dev:
 * - /_esolog_res/* — sibling uesp-esolog/resources (same-origin; avoids CDN 403 on Referer 127.0.0.1).
 * - /_uesp_cdn_proxy/{esobuilds-static|esoicons|esolog-static}/* — server-side fetch from UESP CDNs
 *   so the browser never hits CORP / hotlink blocks (see ESO_LOCAL_UESP_CDN_PROXY in editBuild.class.php).
 * - /_esolog_api/{esoItemSearchPopup|exportJson|getSetItemData}.php — same-origin JSON APIs for local php -S.
 *   Live esolog only whitelists CORS for *uesp.net; browsers block reading JSON from 127.0.0.1. Cloudflare
 *   often blocks server-side proxying to esolog, so default ESO_LOCAL_ESOLOG_API=local runs sibling PHP + MySQL.
 */
declare(strict_types=1);

/**
 * Fetch a URL for the local CDN mirror. Many Windows PHP builds ship without ext-curl and without
 * ext-openssl; HTTPS then fails inside PHP. Fall back to the system curl binary (Windows 10+ and
 * Git Bash include it).
 *
 * @return array{body: string, status: int, contentType: ?string}|null
 */
function uesp_cdn_proxy_http_get(string $upstreamUrl, string $userAgent): ?array
{
	$headers = [
		'Accept: */*',
		'Referer: https://en.uesp.net/',
	];

	if (function_exists('curl_init')) {
		$ch = curl_init($upstreamUrl);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_USERAGENT => $userAgent,
			CURLOPT_HTTPHEADER => ['Accept: */*', 'Referer: https://en.uesp.net/'],
		]);
		$body = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: null;
		curl_close($ch);
		if ($body !== false && $status > 0) {
			return ['body' => (string) $body, 'status' => $status, 'contentType' => $contentType];
		}
	}

	if (in_array('https', stream_get_wrappers(), true)) {
		$hdrLines = implode("\r\n", array_merge(["User-Agent: $userAgent"], $headers));
		$ctx = stream_context_create([
			'http' => [
				'method' => 'GET',
				'timeout' => 30,
				'follow_location' => 1,
				'header' => $hdrLines . "\r\n",
			],
			'ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
			],
		]);
		unset($http_response_header);
		$body = @file_get_contents($upstreamUrl, false, $ctx);
		$status = 0;
		$contentType = null;
		if (isset($http_response_header) && is_array($http_response_header)) {
			foreach ($http_response_header as $line) {
				if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
					$status = (int) $m[1];
				}
				if (stripos($line, 'Content-Type:') === 0) {
					$contentType = trim(substr($line, strlen('Content-Type:')));
				}
			}
		}
		if ($body !== false && $status > 0) {
			return ['body' => (string) $body, 'status' => $status, 'contentType' => $contentType];
		}
	}

	// System curl (no PHP openssl/curl required)
	if (!function_exists('proc_open')) {
		return null;
	}
	$curlBin = 'curl';
	if (PHP_OS_FAMILY === 'Windows' && is_file('C:/Windows/System32/curl.exe')) {
		$curlBin = 'C:/Windows/System32/curl.exe';
	}
	$cmd = [
		$curlBin,
		'-sS',
		'-L',
		'--max-time', '30',
		'-A', $userAgent,
		'-H', 'Accept: */*',
		'-H', 'Referer: https://en.uesp.net/',
		'-w', "\n__UESP_HTTP_STATUS__%{http_code}",
		$upstreamUrl,
	];
	$descriptorspec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
	$proc = @proc_open($cmd, $descriptorspec, $pipes, null, null, ['bypass_shell' => true]);
	if (!is_resource($proc)) {
		return null;
	}
	fclose($pipes[0]);
	$raw = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($proc);
	if (!is_string($raw) || $raw === '') {
		return null;
	}
	$marker = "\n__UESP_HTTP_STATUS__";
	$pos = strrpos($raw, $marker);
	if ($pos === false) {
		return null;
	}
	$body = substr($raw, 0, $pos);
	$status = (int) substr($raw, $pos + strlen($marker));
	if ($status <= 0) {
		return null;
	}
	return ['body' => $body, 'status' => $status, 'contentType' => null];
}

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

$esologApiPrefix = '/_esolog_api/';
if (str_starts_with($uri, $esologApiPrefix)) {
	$script = substr($uri, strlen($esologApiPrefix));
	if ($script === '' || !preg_match('/^[a-zA-Z0-9_]+\.php$/', $script)) {
		http_response_code(403);
		header('Content-Type: text/plain; charset=UTF-8');
		echo 'Invalid script';
		return true;
	}
	$allowedEsoLogApi = ['esoItemSearchPopup.php', 'exportJson.php', 'getSetItemData.php'];
	if (!in_array($script, $allowedEsoLogApi, true)) {
		http_response_code(404);
		header('Content-Type: text/plain; charset=UTF-8');
		echo 'Not found';
		return true;
	}

	$esologApiMode = getenv('ESO_LOCAL_ESOLOG_API');
	if ($esologApiMode === false || $esologApiMode === '') {
		$esologApiMode = PHP_SAPI === 'cli-server' ? 'local' : 'proxy';
	}

	if ($esologApiMode === 'local') {
		$esologDir = realpath(__DIR__ . '/../uesp-esolog');
		if ($esologDir === false || !is_file($esologDir . '/' . $script)) {
			http_response_code(500);
			header('Content-Type: text/plain; charset=UTF-8');
			echo 'Local esolog scripts not found';
			return true;
		}
		chdir($esologDir);
		require $esologDir . '/' . $script;
		return true;
	}

	if ($esologApiMode !== 'proxy') {
		http_response_code(500);
		header('Content-Type: text/plain; charset=UTF-8');
		echo 'Invalid ESO_LOCAL_ESOLOG_API (use local or proxy)';
		return true;
	}

	$query = $_SERVER['QUERY_STRING'] ?? '';
	$upstreamUrl = 'https://esolog.uesp.net/' . $script . ($query !== '' ? '?' . $query : '');
	$userAgent = 'Mozilla/5.0 (Windows NT 10.0; rv:109.0) Gecko/20100101 Firefox/115.0';
	$result = uesp_cdn_proxy_http_get($upstreamUrl, $userAgent);
	if ($result === null) {
		http_response_code(502);
		header('Content-Type: text/plain; charset=UTF-8');
		echo 'Upstream fetch failed';
		return true;
	}
	$status = $result['status'];
	$body = $result['body'];
	$contentType = $result['contentType'];
	if ($status < 200 || $status >= 400) {
		http_response_code($status >= 400 && $status < 600 ? $status : 502);
		header('Content-Type: text/plain; charset=UTF-8');
		echo 'Upstream HTTP ' . $status . ($status === 403 ? ' (try ESO_LOCAL_ESOLOG_API=local)' : '');
		return true;
	}
	if ($contentType !== null && $contentType !== '') {
		header('Content-Type: ' . $contentType);
	} else {
		header('Content-Type: application/json; charset=UTF-8');
	}
	echo $body;
	return true;
}

$cdnPrefix = '/_uesp_cdn_proxy/';
if (str_starts_with($uri, $cdnPrefix)) {
	$cdnBases = [
		'esobuilds-static' => 'https://esobuilds-static.uesp.net',
		'esoicons' => 'https://esoicons.uesp.net',
		'esolog-static' => 'https://esolog-static.uesp.net',
	];
	$rest = substr($uri, strlen($cdnPrefix));
	$rest = str_replace('\\', '/', $rest);
	if ($rest === '' || str_contains($rest, '..')) {
		http_response_code(403);
		header('Content-Type: text/plain; charset=UTF-8');
		echo 'Invalid path';
		return true;
	}
	$slash = strpos($rest, '/');
	if ($slash === false) {
		http_response_code(404);
		header('Content-Type: text/plain; charset=UTF-8');
		echo 'Not found';
		return true;
	}
	$key = substr($rest, 0, $slash);
	$path = substr($rest, $slash + 1);
	if ($path === '' || !isset($cdnBases[$key])) {
		http_response_code(404);
		header('Content-Type: text/plain; charset=UTF-8');
		echo 'Not found';
		return true;
	}
	$path = preg_replace('#/+#', '/', trim($path, '/'));
	if ($path === '') {
		http_response_code(404);
		header('Content-Type: text/plain; charset=UTF-8');
		echo 'Not found';
		return true;
	}
	if (strlen($path) > 2048 || !preg_match('#^[a-zA-Z0-9/_\-.]+$#', $path)) {
		http_response_code(403);
		header('Content-Type: text/plain; charset=UTF-8');
		echo 'Invalid path';
		return true;
	}

	$upstreamUrl = $cdnBases[$key] . '/' . $path;
	$userAgent = 'Mozilla/5.0 (Windows NT 10.0; rv:109.0) Gecko/20100101 Firefox/115.0';

	$result = uesp_cdn_proxy_http_get($upstreamUrl, $userAgent);

	if ($result === null) {
		http_response_code(502);
		header('Content-Type: text/plain; charset=UTF-8');
		echo 'Upstream fetch failed. Enable php-openssl and/or php-curl, or ensure `curl` is on PATH (Windows: built-in curl.exe).';
		return true;
	}

	$body = $result['body'];
	$status = $result['status'];
	$contentType = $result['contentType'];

	if ($status < 200 || $status >= 400) {
		http_response_code($status >= 400 && $status < 600 ? $status : 502);
		header('Content-Type: text/plain; charset=UTF-8');
		// 403 often means Cloudflare bot challenge — turn off ESO_LOCAL_UESP_CDN_PROXY and load CDNs in the browser.
		echo 'Upstream HTTP ' . $status . ($status === 403 ? ' (try ESO_LOCAL_UESP_CDN_PROXY=0)' : '');
		return true;
	}

	if ($contentType !== null && $contentType !== '') {
		header('Content-Type: ' . $contentType);
	} else {
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$mime = [
			'png' => 'image/png',
			'jpg' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif' => 'image/gif',
			'webp' => 'image/webp',
			'svg' => 'image/svg+xml',
			'woff' => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf' => 'font/ttf',
		][$ext] ?? 'application/octet-stream';
		header('Content-Type: ' . $mime);
	}

	header('Cache-Control: public, max-age=86400');
	echo $body;
	return true;
}

$esologResources = realpath(__DIR__ . '/../uesp-esolog/resources');
if ($esologResources === false) {
	return false;
}

$prefix = '/_esolog_res/';
if (str_starts_with($uri, $prefix)) {
	$rel = substr($uri, strlen($prefix));
	$rel = str_replace('\\', '/', $rel);
	if ($rel === '' || str_contains($rel, '..')) {
		http_response_code(403);
		header('Content-Type: text/plain; charset=UTF-8');
		echo 'Invalid path';
		return true;
	}

	$path = realpath($esologResources . DIRECTORY_SEPARATOR . $rel);
	if ($path === false || !is_file($path)) {
		http_response_code(404);
		header('Content-Type: text/plain; charset=UTF-8');
		echo 'Not found';
		return true;
	}

	$esologNorm = str_replace('\\', '/', $esologResources);
	$pathNorm = str_replace('\\', '/', $path);
	if (!str_starts_with(strtolower($pathNorm), strtolower($esologNorm))) {
		http_response_code(403);
		header('Content-Type: text/plain; charset=UTF-8');
		echo 'Forbidden';
		return true;
	}

	$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
	$mimes = [
		'css' => 'text/css; charset=UTF-8',
		'js' => 'application/javascript; charset=UTF-8',
		'svg' => 'image/svg+xml',
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif' => 'image/gif',
		'woff' => 'font/woff',
		'woff2' => 'font/woff2',
		'ttf' => 'font/ttf',
		'map' => 'application/json',
	];
	$mime = $mimes[$ext] ?? 'application/octet-stream';
	header('Content-Type: ' . $mime);
	readfile($path);
	return true;
}

return false;
