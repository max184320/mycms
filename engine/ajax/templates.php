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
 File: templates.php
-----------------------------------------------------
 Use: AJAX template edit
=====================================================
*/

if(!defined('DATALIFEENGINE')) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}


if(($member_id['user_group'] != 1)) {
	die ("error");
}

$allowed_extensions = array ("tpl", "css", "js", "json");
$_POST['action'] = isset($_POST['action']) ? $_POST['action'] : '';
DLEFiles::init(0, false, 'templates');

function clear_url_dir($path, $only_folders = false) {
	$path = trim(str_replace(chr(0), '', (string)$path));
	$path = str_replace(array('/', '\\'), '/', $path);

	if (!$path) return '';

	if (preg_match('#\p{C}+#u', $path)) {
		return '';
	}

	$path_parts = pathinfo($path);

	$filename = $path_parts['basename'];

	$parts = array_filter(explode('/', $path_parts['dirname']), 'strlen');

	$absolutes = array();

	foreach ($parts as $part) {
		$part = trim($part);

		if ('.' == $part or '..' == $part or !$part) continue;

		$absolutes[] = totranslit($part, false, false);
	}

	$path = implode('/', $absolutes);

	if ($path) {
		$path = $path . '/';
	}

	if( !$only_folders ) {
		if ($filename) {
			$path .= totranslit($filename, false, true, true);
		}
	}

	return $path;

}

if($_POST['action'] == "create") {

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		die ("error");
	}
	
	if( !check_referer( $config['http_home_url'].$config['admin_path']."?mod=templates") ) {
		echo $lang['no_referer'];
		die ();
	}
	
	$template = trim( totranslit($_POST['template'], false, false) );
	$root = ROOT_DIR . '/templates/';
	
	$url = @parse_url($template.'/'.$_POST['file']);

	$file_name = clear_url_dir($url['path']);
	$filename_arr = explode(".", $file_name);
	$type = '';

	if(count($filename_arr) > 1) $type = end($filename_arr);

	if (!$file_name OR !$template) {
		echo $lang['template_create_err2'];
		die();
	}

	if( $_POST['type'] == 'folder' ) {
		if (count(explode("/", $file_name)) > 5) {
			echo $lang['template_create_err3'];
			die();
		}
		DLEFiles::CreateDirectory($file_name);

		if( DLEFiles::$error ) {
			echo DLEFiles::$error;
			die();	
		}

		$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '139', '{$file_name}')");

	} else {

		if ( !in_array($type, $allowed_extensions) ) {
			echo $lang['template_create_err2'];
			die();
		}

		if (DLEFiles::FileExists($file_name)) {
			echo $lang['template_create_err'];
			die();
		}

		DLEFiles::Save($file_name, '');
		
		if (DLEFiles::$error) {
			echo DLEFiles::$error;
			die();
		}

		$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '69', '{$file_name}')");
	}

	echo "ok"; die();

} elseif($_POST['action'] == "save") {

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		die ("error");
	}
	
	if( !check_referer( $config['http_home_url'].$config['admin_path']."?mod=templates") ) {
		echo $lang['no_referer'];
		die ();
	}

	$_POST['file'] = trim(str_replace( "..", "", urldecode($_POST['file']) ));
	
	if(!$_POST['file']) { die ("error"); }
	
	$url = @parse_url ( $_POST['file'] );

	$file_name = clear_url_dir($url['path']);
	$filename_arr = explode(".", $file_name);
	$type = '';

	if (count($filename_arr) > 1) $type = end($filename_arr);
	
	if(!in_array( $type, $allowed_extensions ) ) {
		echo $lang['template_create_err2'];
		die();
	}

	if(!is_writable(ROOT_DIR . '/templates/' . $file_name)) {
		echo $lang['template_edit_fail'];
		die();
	}

	if (!DLEFiles::FileExists($file_name)) {
		die('File Not Exist');
	}
	
	DLEFiles::Save($file_name, $_POST['content']);

	if (DLEFiles::$error) {
		echo DLEFiles::$error;
		die();
	}

	if ($type == "css" OR $type == "js") {

		clear_all_caches();
		clear_static_cache_id();

	} else {

		clear_cache();
		
	}

	$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '70', '{$file_name}')");

	echo "ok"; die();

} elseif ($_POST['action'] == "move") {
	if (!isset($_REQUEST['user_hash']) or !$_REQUEST['user_hash'] or $_REQUEST['user_hash'] != $dle_login_hash) {
		die("error");
	}

	if (!check_referer($config['http_home_url'] . $config['admin_path'] . "?mod=templates")) {
		echo $lang['no_referer'];
		die();
	}

	$_POST['file'] = trim(str_replace("..", "", urldecode($_POST['file'])));

	if (!$_POST['file']) {
		die("error");
	}

	$url = @parse_url($_POST['file']);

	$file_name = clear_url_dir($url['path']);
	$filename_arr = explode(".", $file_name);
	$type = '';

	if (count($filename_arr) > 1) $type = end($filename_arr);

	if (!in_array($type, $allowed_extensions)) {
		echo $lang['template_create_err2'];
		die();
	}

	$_POST['movefolder'] = trim(str_replace("..", "", urldecode($_POST['movefolder'])));
	$url = @parse_url($_POST['movefolder']);
	$movefile = clear_url_dir($url['path'], false).'/'.basename($file_name);
	
	DLEFiles::Rename($file_name, $movefile);
	
	if (DLEFiles::$error) {
		echo DLEFiles::$error;
		die();
	}

	if ($type == "css" or $type == "js") {

		clear_all_caches();
		clear_static_cache_id();
	} else {

		clear_cache();
	}
	
	$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '145', '{$movefile}')");

	echo "ok";
	die();
} elseif ($_POST['action'] == "delete") {

	if (!isset($_REQUEST['user_hash']) or !$_REQUEST['user_hash'] or $_REQUEST['user_hash'] != $dle_login_hash) {
		die("error");
	}

	if (!check_referer($config['http_home_url'] . $config['admin_path'] . "?mod=templates")) {
		echo $lang['no_referer'];
		die();
	}

	$_POST['file'] = trim(str_replace("..", "", urldecode($_POST['file'])));

	if (!$_POST['file']) {
		die("error");
	}

	$url = @parse_url($_POST['file']);

	$file_name = clear_url_dir($url['path']);
	$filename_arr = explode(".", $file_name);
	$type = '';

	if (count($filename_arr) > 1) $type = end($filename_arr);

	if (!in_array($type, $allowed_extensions)) {
		echo $lang['template_create_err2'];
		die();
	}

	if (!DLEFiles::FileExists($file_name)) {
		die('File Not Exist');
	}
	
	DLEFiles::Delete($file_name);

	if (DLEFiles::$error) {
		echo DLEFiles::$error;
		die();
	}

	$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '138', '{$file_name}')");

	if ($type == "css" or $type == "js") {

		clear_all_caches();
		clear_static_cache_id();
	} else {

		clear_cache();
	}

	echo "ok";
	die();


} elseif($_POST['action'] == "load") {

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		die (json_encode(['error' => 'auth error']));
	}

	$_POST['file'] = trim(str_replace( "..", "", urldecode($_POST['file'] ?? '') ));

	if(!$_POST['file']) { die (json_encode(['error' => 'empty file'])); }

	$url = @parse_url ( $_POST['file'] );

	$root = ROOT_DIR . '/templates/';

	$file_name = clear_url_dir($url['path']);
	$filename_arr = explode(".", $file_name);
	$type = '';

	if (count($filename_arr) > 1) $type = end($filename_arr);

	if (!in_array($type, $allowed_extensions)) {
		die(json_encode(['error' => $lang['template_create_err2']]));
	}

	if (!DLEFiles::FileExists($file_name)) {
		die(json_encode(['error' => 'File Not Exist']));
	}

	$content = DLEFiles::Read($file_name);

	if (DLEFiles::$error) {
		die(json_encode(['error' => DLEFiles::$error]));
	}

	echo json_encode([
		'content'  => (string)$content,
		'file'     => $file_name,
		'type'     => $type,
		'writable' => is_writable($root . $file_name)
	]);
	die();

} else {

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}
	
	$_POST['dir'] = isset($_POST['dir']) ? $_POST['dir'] : 'Default';

	$path = @parse_url($_POST['dir']);
	$path = clear_url_dir($path['path']);

	$files = DLEFiles::ListDirectory($path, $allowed_extensions);

	if (DLEFiles::$error) {
		echo DLEFiles::$error;
		die();
	}

	echo "<ul class=\"jqueryFileTree\" style=\"display: none;\">";
	
	if( is_array($files['dirs']) AND count($files['dirs']) ) {
		foreach ($files['dirs'] as $file) {
			echo "<li class=\"directory collapsed\"><a href=\"#\" rel=\"" . $file['path'] . "\">" . $file['name'] . "</a></li>";
		}
	}

	if (is_array($files['files']) AND count($files['files'])) {
		foreach ($files['files'] as $file) {
			$serverfile_arr = explode(".", $file['name']);
			$ext = totranslit(end($serverfile_arr));
			echo "<li class=\"file ext_$ext\"><a href=\"#\" rel=\"" . $file['path'] . "\">" . $file['name'] . "</a></li>";
		}
	}

	echo "</ul>";

}

?>