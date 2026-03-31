<?php
/**
 * Router for `php -S` local dev: serve sibling repo uesp-esolog/resources under /_esolog_res/
 * so assets are same-origin (Referer 127.0.0.1) and not blocked by UESP/Cloudflare hotlink rules.
 */
declare(strict_types=1);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

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
