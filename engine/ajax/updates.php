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
 File: updates.php
-----------------------------------------------------
 Use: Check for new versions
=====================================================
*/

if(!defined('DATALIFEENGINE')) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

if(($member_id['user_group'] != 1)) {die ("error");}

if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {

	echo $lang['sess_error'];
	die();

}

$response = array(
	'status' => false,
	'need_update' => false,
	'error' => '',
);

$_REQUEST['versionid'] = isset($_REQUEST['versionid']) ? htmlspecialchars( strip_tags($_REQUEST['versionid']), ENT_QUOTES, 'UTF-8') : '';
$_REQUEST['build'] = isset($_REQUEST['versionid']) ? htmlspecialchars( strip_tags($_REQUEST['build']), ENT_QUOTES, 'UTF-8') : '';

$data = http_get_contents("https://dle-news.ru/extras/updates.php?version_id=" . $_REQUEST['versionid'] . "&build=" . $_REQUEST['build'] . "&key=" . $config['key'] . "&lang=" . $lang['language_code']. "&format=json");

$data = json_decode($data, true);

if (json_last_error() !== JSON_ERROR_NONE OR !is_array($data)) {
	$response['error'] = $lang['no_update'];
	die(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$response = $data;

die(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));