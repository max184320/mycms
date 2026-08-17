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
 File: go.php
-----------------------------------------------------
 Use: Forwarding links
=====================================================
*/

if (!defined('DATALIFEENGINE')) {
	header("HTTP/1.1 403 Forbidden");
	header('Location: ../');
	die("Hacking attempt!");
}

function deny() {

	$url = substr($_SERVER['SCRIPT_NAME'], 0, (int)strrpos($_SERVER['SCRIPT_NAME'], '/') + 1) ?: '/';

	header('Location: ' . $url, true, 302);
	exit('Access denied');
}

function check_encode_url_part($value) {
	$result = preg_replace_callback('/[^\x21-\x7E]/u', function ($matches) {
		return rawurlencode($matches[0]);
	}, $value);

	if (!is_string($result)) return false;

	return $result;
}

if (empty($_SERVER['HTTP_REFERER'])) {
	deny();
}

$ref = parse_url($_SERVER['HTTP_REFERER'] ?? '');
if (!$ref || empty($ref['host'])) {
	deny();
}

$currentHost = strtolower(preg_replace('/^www\./i', '', $_SERVER['HTTP_HOST']));
$refHost     = strtolower(preg_replace('/^www\./i', '', $ref['host']));

if ($currentHost !== $refHost) {
	deny();
}

$area         = $_GET['area'] ?? '';
$raw          = $_GET['url'] ?? '';
$encryptedurl = $_GET['encryptedurl'] ?? '';
$crc          = $_GET['crc'] ?? '';
$secret_key   = substr(hash('sha256', SECURE_AUTH_KEY), 0, 6);

if (!empty($encryptedurl)) {

	$url = decrypt_link($encryptedurl, $secret_key, $crc);

	if ($url === false || $url === '') {
		deny();
	}
} else {

	if ($raw === '') {
		deny();
	}

	$url = base64_decode($raw, true);

	if ($url === false || empty($url) ) {
		deny();
	}
}

if (($area == 'comments' && $config['comm_noreferrer']) || (!$area && $config['news_noreferrer'])) {
	header('Referrer-Policy: no-referrer');
}

$url = trim(str_replace(["\r", "\n", "\0", "%0d", "%0a", "%0D", "%0A"], '', $url));

$parsed = parse_url($url);

if (!$parsed || !isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
	deny();
}

$host = strtolower($parsed['host'] ?? '');

if (function_exists('idn_to_ascii')) {
	$idnaInfo = [];

	$asciiHost = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46, $idnaInfo);

	if ($asciiHost === false || !empty($idnaInfo['errors'])) {
		deny();
	}

} else $asciiHost = $host;

if (!$host || $host === $currentHost || $asciiHost === $currentHost) {
	deny();
}

$RedirectUrl = $parsed['scheme'] . '://' . $asciiHost;

if (isset($parsed['port'])) {
	$port = (int) $parsed['port'];

	if ($port < 1 || $port > 65535) {
		deny();
	}

	$RedirectUrl .= ':' . $port;
}

if (isset($parsed['path'])) {
	$path = check_encode_url_part((string) $parsed['path']);

	if ($path === false) {
		deny();
	}

	$RedirectUrl .= $path;

}

if (isset($parsed['query'])) {
	$query = check_encode_url_part((string) $parsed['query']);

	if ($query === false) {
		deny();
	}

	$RedirectUrl .= '?' . $query;
}

if (isset($parsed['fragment'])) {
	$fragment = check_encode_url_part((string) $parsed['fragment']);

	if ($fragment === false) {
		deny();
	}

	$RedirectUrl .= '#' . $fragment;
}

if (!filter_var($RedirectUrl, FILTER_VALIDATE_URL)) {
	deny();
}

header('Location: ' . $RedirectUrl, true, 302);
exit;