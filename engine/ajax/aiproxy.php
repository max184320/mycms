<?php
/*
=====================================================
 DataLife Engine - by SoftNews Media Group 
-----------------------------------------------------
 https://dle-news.ru/
-----------------------------------------------------
 Copyright (c) 2004-2026 SoftNews Media Group
=====================================================
 This code is protected by copyright
=====================================================
 File: aiproxy.php
-----------------------------------------------------
 Use: Proxy for AI requests
=====================================================
*/

if (!defined('DATALIFEENGINE')) {
	die("Hacking attempt!");
}
function sendJsonError($message, $statusCode = 500) {
	$isStream = headers_sent();
	
	if (!$isStream) {
		http_response_code($statusCode);
		header('Content-Type: application/json');
		echo json_encode([
			'error' => [
				'message' => $message,
				'type' => 'proxy_error'
			]
		]);
	} else {
		echo "\nevent: error\n";
		echo "data: " . json_encode(['error' => $message]) . "\n\n";
	}
	exit;
}

if (!$config['enable_ai'] OR !in_array($member_id['user_group'] ?? 5, array_map('intval', explode(',', trim($config['ai_groups'] ?? ''))))) {
	sendJsonError('AI Not Allowed', 403);
}

set_time_limit(0);
ignore_user_abort(false);

if (function_exists('apache_setenv')) @apache_setenv('no-gzip', 1);
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
@ini_set('output_buffering', 0);

while (ob_get_level() > 0) {
	ob_end_clean();
}
ob_implicit_flush(true);

header('Content-Type: text/event-stream; charset=utf-8');
header('Connection: keep-alive');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no');
header('Surrogate-Control: no-store');
header('Content-Encoding: none');

$upstreamUrl = $config['ai_endpoint'] ?? '';
$apiKey = $config['ai_key'] ?? '';

if (empty($upstreamUrl) || empty($apiKey)) {
	sendJsonError('AI Configuration Error', 500);
}


$body = file_get_contents('php://input', false, null, 0, 2_000_000);
if ($body === false || $body === '' || strlen($body) > 2_000_000) sendJsonError('Content too large', 413);

$reqHeaders = ['Content-Type: application/json'];

$isGoogle = str_contains($upstreamUrl, 'google');
$isAnthropic = str_contains($upstreamUrl, 'anthropic');
$isYandex = str_contains($upstreamUrl, 'yandex');

if ($isGoogle) {
	$separator = str_contains($upstreamUrl, '?') ? '&' : '?';
	$upstreamUrl .= "{$separator}key={$apiKey}";

	if (!str_contains($upstreamUrl, 'alt=sse')) {
		$upstreamUrl .= '&alt=sse';
	}
} elseif ($isAnthropic) {
	$reqHeaders[] = 'x-api-key: ' . $apiKey;
	$reqHeaders[] = 'anthropic-version: 2023-06-01';
} elseif ($isYandex) {
	$reqHeaders[] = 'Authorization: Api-Key ' . $apiKey;
} else {
	$reqHeaders[] = 'Authorization: Bearer ' . $apiKey;
}

$ch = curl_init($upstreamUrl);

curl_setopt_array($ch, [
	CURLOPT_POST => true,
	CURLOPT_HTTPHEADER => $reqHeaders,
	CURLOPT_POSTFIELDS => $body,
	CURLOPT_HEADER => false,
	CURLOPT_RETURNTRANSFER => false,
	CURLOPT_BUFFERSIZE => 1024,
	CURLOPT_CONNECTTIMEOUT => 10,
	CURLOPT_TIMEOUT => 0,
	CURLOPT_ENCODING => '',
	CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) {
		$len = strlen($headerLine);
		$header = trim($headerLine);
		if (empty($header)) return $len;

		if (str_starts_with(strtoupper($header), 'HTTP/')) {
			$p = explode(' ', $header, 3);
			http_response_code((int)($p[1] ?? 200));
			return $len;
		}

		$parts = explode(':', $header, 2);
		if (count($parts) < 2) return $len;

		$name = strtolower(trim($parts[0]));

		$blocked = ['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailers', 'transfer-encoding', 'upgrade', 'content-encoding', 'content-length', 'host'];

		if (!in_array($name, $blocked)) {
			header($header, false);
		}
		return $len;
	},
	CURLOPT_WRITEFUNCTION => function ($curl, $chunk) {
		echo $chunk;
		flush();
		return strlen($chunk);
	},
]);

curl_exec($ch);

if (curl_errno($ch)) {
	sendJsonError('Proxy Connection Failed: ' . curl_error($ch), 502);
}

unset($ch);