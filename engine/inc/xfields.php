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
 File: xfields.php
-----------------------------------------------------
 Use: manage extra fields
=====================================================
*/

if(!defined('DATALIFEENGINE')) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

if ( !$user_group[$member_id['user_group']]['admin_xfields'] ) {
	msg("error", $lang['index_denied'], $lang['index_denied']);
	die();
}

if( $_POST['action'] == "addrubric" ) {
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		die( "Hacking attempt! User not found" );
	}

	$rubric = DLEXFields::AddRubric($_POST['title'], $_POST['description']);

	if( !$rubric ) {
		msg("error", $lang['addnews_error'], DLEXFields::$error, "javascript:history.go(-1)");
	}
	
	$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '146', '{$rubric}')" );
	
	header( "Location: ?mod=xfields");
	die();
	
}
if( $_POST['action'] == "editrubric" ) {
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		die( "Hacking attempt! User not found" );
	}

	$rubric = DLEXFields::EditRubric($_POST['editrubricid'], $_POST['title'], $_POST['description']);
	
	if (!$rubric) {
		msg("error", $lang['addnews_error'], DLEXFields::$error, "javascript:history.go(-1)");
	}

	$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '147', '{$rubric}')" );
	
	header("Location: ?mod=xfields");
	die();
}

if ($_GET['action'] == "deleterubric") {

	if (!isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash) {
		die("Hacking attempt! User not found");
	}

	$name = DLEXFields::DeleteRubric($_GET['rid']);

	if ($name) {
		$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '148', '{$name}')");

		header("Location: ?mod=xfields");
		die();
	}

}

if ($_GET['action'] == "delete") {

	if (!isset($_REQUEST['user_hash']) or !$_REQUEST['user_hash'] or $_REQUEST['user_hash'] != $dle_login_hash) {
		die("Hacking attempt! User not found");
	}

	$name = DLEXFields::DeleteField($_GET['name']);
	$rubric = isset($_GET['rubric']) ? totranslit($_GET['rubric'], true, false) : '';

	if($name) {
		$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '73', '{$name}')");

		header("Location: ?mod=xfields&rubric=" . $rubric);
		die();
	} else {
		msg("error", $lang['xfield_error'], $lang['xfield_err_5'], "javascript:history.go(-1)");
	}
}
if ($_GET['action'] == "off") {

	if (!isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash) {
		die("Hacking attempt! User not found");
	}

	$name = DLEXFields::DisableField($_GET['name']);
	$rubric = isset($_GET['rubric']) ? totranslit($_GET['rubric'], true, false) : '';

	if ($name) {
		$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '149', '{$name}')");

		header("Location: ?mod=xfields&rubric=" . $rubric);
		die();
	} else {
		msg("error", $lang['xfield_error'], $lang['xfield_err_9'], "javascript:history.go(-1)");
	}
}

if ($_GET['action'] == "on") {

	if (!isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash) {
		die("Hacking attempt! User not found");
	}

	$name = DLEXFields::EnableField($_GET['name']);
	$rubric = isset($_GET['rubric']) ? totranslit($_GET['rubric'], true, false) : '';

	if ($name) {
		$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '150', '{$name}')");

		header("Location: ?mod=xfields&rubric=" . $rubric);
		die();
	} else {
		msg("error", $lang['xfield_error'], $lang['xfield_err_9'], "javascript:history.go(-1)");
	}
}

if ($_REQUEST['action'] == "doadd" or $_REQUEST['action'] == "doedit") {
	
	$editedxfield = isset($_POST['editedxfield']) ? $_POST['editedxfield'] : [];
	
	if(!is_array($editedxfield) OR !count($editedxfield)) {
		msg("error", $lang['xfield_error'], $lang['xfield_err_8'], "javascript:history.go(-1)");
	}
	
	DLEXFields::Init();

	$editedxfield['name'] = totranslit(trim($editedxfield['name']));
	$editedxfield['description'] = strip_tags(stripslashes(trim($editedxfield['description'])));

	if (!$editedxfield['name'] OR !$editedxfield['description']) {
		msg("error", $lang['xfield_error'], $lang['xfield_err_12'], "javascript:history.go(-1)");
	}

	if( $_REQUEST['action'] == "doadd" AND isset( DLEXFields::$fields['fields'][$editedxfield['name']]) ) {
		msg("error", $lang['xfield_error'], $lang['xfield_err_9'], "javascript:history.go(-1)");
	}

	if ($_REQUEST['action'] == "doedit" AND (!isset($_REQUEST['editedname']) OR !isset(DLEXFields::$fields['fields'][$_REQUEST['editedname']] ) ) ) {
		msg("error", $lang['xfield_error'], $lang['xfield_err_8'], "javascript:history.go(-1)");
	}

	if( $editedxfield['group'] AND !isset(DLEXFields::$fields['groups'][$editedxfield['group']]) ) {
		msg("error", $lang['xfield_error'], $lang['xf_err_3'], "javascript:history.go(-1)");
	}
	
	$editedxfield['hint'] = strip_tags(stripslashes(trim($editedxfield['hint'])));
	$editedxfield['links_separator'] = clear_js($editedxfield['links_separator']);

	if(!isset($editedxfield['category'])) $editedxfield['category'] = array();

	if (!is_array($editedxfield['category'])) $editedxfield['category'][0] = "";
	elseif (!count($editedxfield['category'])) $editedxfield['category'][0] = "";
	elseif (is_array($editedxfield['category']) AND count($editedxfield['category']) > 1 AND !$editedxfield['category'][0]) unset($editedxfield['category'][0]);

	$category_list = array();

	foreach ($editedxfield['category'] as $catval) {
		if ($catval) $category_list[] = intval($catval);
	}

	$editedxfield['category'] = implode(',', $category_list);

	$editedxfield['type'] = totranslit(trim($editedxfield['type']));

	if ($editedxfield['type'] == "select") {

		$options = array();
		$editedxfield["default_select"] = str_replace("\r", '', $editedxfield["default_select"]);

		foreach (explode("\n", $editedxfield["default_select"]) as $name => $value) {
			$value = trim($value);
			if (!in_array($value, $options)) {
				$options[] = $value;
			}
		}

		if (count($options) < 2) {
			msg("error", $lang['xfield_error'], $lang['xfield_err_10'], "javascript:history.go(-1)");
		}

		$editedxfield['default'] = implode("\n", $options);
	} else {

		if ($editedxfield['type'] == "htmljs") {
			$editedxfield['default'] = $editedxfield["default_textarea"];
		} else {
			$editedxfield['default'] = $editedxfield["default_{$editedxfield['type']}"];
		}
	}

	unset($editedxfield["default_text"], $editedxfield["default_textarea"], $editedxfield["default_select"]);

	if ($editedxfield['type'] == "select") {
		$editedxfield['allow_multi'] = isset($editedxfield['allow_multi']) && $editedxfield['allow_multi'] == "on" ? 1 : 0;
		$editedxfield['select_separator'] = clear_js($editedxfield['select_separator']);
	} else {
		$editedxfield['allow_multi'] = 0;
		$editedxfield['select_separator'] = '';
	}

	$editedxfield['not_required'] = isset($editedxfield['not_required']) && $editedxfield['not_required'] == "on" ? 1 : 0;

	if ($editedxfield['type'] == "text" or $editedxfield['type'] == "select" or $editedxfield['type'] == "datetime") {
		$editedxfield['use_as_links'] = isset($editedxfield['use_as_links']) && $editedxfield['use_as_links'] == "on" ? 1 : 0;
	} else $editedxfield['use_as_links'] = 0;

	if ($editedxfield['type'] == "textarea") {
		$editedxfield['use_editor'] = isset($editedxfield['use_editor']) && $editedxfield['use_editor'] == "on" ? 1 : 0;
	} else $editedxfield['use_editor'] = 0;

	if ($editedxfield['type'] == "text" OR $editedxfield['type'] == "textarea") {
		$editedxfield['safe_mode'] = isset($editedxfield['safe_mode']) && $editedxfield['safe_mode'] == "on" ? 1 : 0;
		if (intval($editedxfield['min']) > 0) $editedxfield['min'] = intval($editedxfield['min']);
		else $editedxfield['min'] = '';
		if (intval($editedxfield['max']) > 0) $editedxfield['max'] = intval($editedxfield['max']);
		else $editedxfield['max'] = '';

	} else {
		$editedxfield['safe_mode'] = 0;
		$editedxfield['min'] = '';
		$editedxfield['max'] = '';
	}

	if ($editedxfield['type'] == "image" or $editedxfield['type'] == "imagegalery") {

		$size = explode("x", $editedxfield['image_size']);

		if (count($size) == 2) {
			$editedxfield['image_size'] = intval($size[0]) . "x" . intval($size[1]);
		} elseif (intval($size[0]) > 0) {
			$editedxfield['image_size'] = intval($size[0]);
		} else $editedxfield['image_size'] = '';

		if (intval($editedxfield['image_max_size']) > 0) {
			$editedxfield['image_max_size'] = intval($editedxfield['image_max_size']);
		} else $editedxfield['image_max_size'] = '';

		$editedxfield['make_watermark'] = ($editedxfield['make_watermark'] == "on" ? 1 : 0);
		$editedxfield['make_thumb'] = ($editedxfield['make_thumb'] == "on" ? 1 : 0);

		$size = explode("x", $editedxfield['thumb_size']);

		if (count($size) == 2) {
			$editedxfield['thumb_size'] = intval($size[0]) . "x" . intval($size[1]);
		} elseif (intval($size[0]) > 0) {
			$editedxfield['thumb_size'] = intval($size[0]);
		} else $editedxfield['thumb_size'] = '';

		$size = explode("x", $editedxfield['image_sizes']);

		if (count($size) == 2) {
			$editedxfield['image_sizes'] = intval($size[0]) . "x" . intval($size[1]);
		} elseif (intval($size[0]) > 0) {
			$editedxfield['image_sizes'] = intval($size[0]);
		} else $editedxfield['image_sizes'] = '';

		$editedxfield['use_opengraph'] = ($editedxfield['use_opengraph'] == "on" ? 1 : 0);
		$editedxfield['image_side'] = intval($editedxfield['image_side']);
		$editedxfield['thumb_side'] = intval($editedxfield['thumb_side']);
		
	} else {
		$editedxfield['make_watermark'] = 0;
		$editedxfield['make_thumb'] = 0;
		$editedxfield['image_size'] = '';
		$editedxfield['image_max_size'] = '';
		$editedxfield['thumb_size'] = '';
		$editedxfield['image_sizes'] = '';
		$editedxfield['use_opengraph'] = '';
		$editedxfield['image_side'] = '';
		$editedxfield['thumb_side'] = '';
	}

	if ($editedxfield['type'] == "imagegalery") {
		if (intval($editedxfield['max_images']) > 0) {
			$editedxfield['max_images'] = intval($editedxfield['max_images']);
		} else $editedxfield['max_images'] = 0;
	} else $editedxfield['max_images'] = '';

	if ($editedxfield['type'] == "image" OR $editedxfield['type'] == "imagegalery" OR $editedxfield['type'] == "video" OR $editedxfield['type'] == "audio" OR $editedxfield['type'] == "file") {
		$editedxfield['storage'] = intval($editedxfield['storage']);
	} else $editedxfield['storage'] = '';

	if ($editedxfield['type'] == "video" OR $editedxfield['type'] == "audio") {
		if (intval($editedxfield['max_size']) > 0) {
			$editedxfield['max_size'] = intval($editedxfield['max_size']);
		} else $editedxfield['max_size'] = '';

		if (intval($editedxfield['max_files']) > 0) {
			$editedxfield['max_files'] = intval($editedxfield['max_files']);
		} else $editedxfield['max_files'] = 0;
	} else {
		$editedxfield['max_files'] = '';
		$editedxfield['max_size'] = '';
	}

	if ($editedxfield['type'] == "file") {

		if ($editedxfield['files_ext']) {

			$files_type = explode(",", $editedxfield['files_ext']);
			$items = array();

			foreach ($files_type as $item) {
				$items[] = totranslit(trim($item), true, false);
			}

			$editedxfield['files_ext'] = implode(",", $items);
		}

		if (intval($editedxfield['file_max_size']) > 0) {
			$editedxfield['file_max_size'] = intval($editedxfield['file_max_size']);
		} else $editedxfield['file_max_size'] = '';

		$editedxfield['is_public'] = ($editedxfield['is_public'] == "on" ? 1 : 0);

	} else {
		$editedxfield['files_ext'] = '';
		$editedxfield['file_max_size'] = '';
		$editedxfield['is_public'] = '';
	}

	if ($editedxfield['type'] == "yesorno") {
		if (intval($editedxfield['condition']) > 0) {
			$editedxfield['condition'] = 1;
		} else $editedxfield['condition'] = 0;
	} else $editedxfield['condition'] = '';
	
	if (!isset($editedxfield['allow_add_usergroups'])) $editedxfield['allow_add_usergroups'] = array();

	if (!count($editedxfield['allow_add_usergroups'])) $editedxfield['allow_add_usergroups'][0] = "";
	elseif (count($editedxfield['allow_add_usergroups']) > 1 AND !$editedxfield['allow_add_usergroups'][0]) unset($editedxfield['allow_add_usergroups'][0]);

	$list = array();

	if (count($editedxfield['allow_add_usergroups'])) {
		foreach ($editedxfield['allow_add_usergroups'] as $val) {
			if ($val) $list[] = intval($val);
		}
	}

	$editedxfield['allow_add_usergroups'] = implode(',', $list);

	if (!isset($editedxfield['allow_view_usergroups'])) $editedxfield['allow_view_usergroups'] = array();

	if (!count($editedxfield['allow_view_usergroups'])) $editedxfield['allow_view_usergroups'][0] = "";
	elseif (count($editedxfield['allow_view_usergroups']) > 1 AND !$editedxfield['allow_view_usergroups'][0]) unset($editedxfield['allow_view_usergroups'][0]);

	$list = array();

	if (count($editedxfield['allow_view_usergroups'])) {
		foreach ($editedxfield['allow_view_usergroups'] as $val) {
			if ($val) $list[] = intval($val);
		}
	}

	$editedxfield['allow_view_usergroups'] = implode(',', $list);

	if ($editedxfield['type'] == "datetime") {
		$editedxfield['date_format'] = intval($editedxfield['date_format']);
		$editedxfield['date_view_format'] = strip_tags(stripslashes(trim($editedxfield['date_view_format'])));
		$editedxfield['date_local'] = ($editedxfield['date_local'] == "on" ? 1 : 0);
		$editedxfield['date_declension'] = ($editedxfield['date_declension'] == "on" ? 1 : 0);
	} else {
		$editedxfield['date_format'] = '';
		$editedxfield['date_view_format'] = '';
		$editedxfield['date_local'] = '';
		$editedxfield['date_declension'] = '';
	}
	
	$editedxfield['allow_in_news'] = ($editedxfield['allow_in_news'] == "on" ? 1 : 0);

	if ($editedxfield['type'] == "textarea" OR $editedxfield['type'] == "image" OR $editedxfield['type'] == "imagegalery") {
		$editedxfield['lazy_load'] = ($editedxfield['lazy_load'] == "on" ? 1 : 0);
	} else $editedxfield['lazy_load'] = '';

	DLEXFields::SaveField($editedxfield['name'], $editedxfield);
	
	if($_REQUEST['action'] == "doedit" AND $editedxfield['name'] != $_REQUEST['editedname']) {
		DLEXFields::DeleteField($_REQUEST['editedname']);
	}

	$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '74', '{$editedxfield['name']}')");

	header("Location: ?mod=xfields&rubric=". $editedxfield['group']);
	die();

}

if ($_REQUEST['action'] == "add" OR $_REQUEST['action'] == "edit") {
	
	$rubrics = DLEXFields::GetRubricsArray();

	if (isset($_GET['rubric']) and $_GET['rubric'] and isset($rubrics[$_GET['rubric']])) {
		$rubric = totranslit($_GET['rubric'], true, false);
		$bread = array('?mod=xfields' => $lang['header_nf_1'], '?mod=xfields&rubric=' . $rubric => $rubrics[$rubric]['title']);
	} else {
		$rubric = '';
		$bread = array('?mod=xfields'  => $lang['header_nf_1'], '' => $lang['header_nf_2']);
	}
	
	$editedxfield = [];

	$type_selected = array('text' => '', 'textarea' =>'', 'htmljs' =>'', 'select' =>'', 'image' =>'', 'imagegalery' =>'', 'video' =>'', 'audio' =>'', 'file' =>'', 'yesorno' =>'', 'datetime' => '');
	$side_selected = array('', '', '');
	$thumb_side_selected = array('', '', '');
	$condition_selected = array('', '');
	$date_format_selected = array('', '', '');
	$disabled = '';

	if( $_REQUEST['action'] == "edit"  ) {
		$lang['xfield_title'] = $lang['xfield_etitle'];
		
		$editedxfield = DLEXFields::GETField($_GET['name']);

		if( !count($editedxfield) ) {
			msg("error", $lang['xfield_error'], $lang['xfield_err_9'], "javascript:history.go(-1)");
		}

		$checked = $editedxfield['not_required'] ? "checked" : "";
		$checked2 = $editedxfield['use_as_links'] ? "checked" : "";
		$checked3 = $editedxfield['use_editor'] ? "checked" : "";
		$checked4 = $editedxfield['safe_mode'] ? "checked" : "";
		$checked11 = $editedxfield['make_watermark'] ? "checked" : "";
		$checked12 = $editedxfield['make_thumb'] ? "checked" : "";
		$checked13 = $editedxfield['date_local'] ? "checked" : "";
		$checked14 = $editedxfield['date_declension'] ? "checked" : "";
		$checked15 = $editedxfield['is_public'] ? "checked" : "";
		$checked16 = $editedxfield['allow_in_news'] ? " checked" : "";
		$checked17 = $editedxfield['use_opengraph'] ? "checked" : "";
		$checked18 = $editedxfield['lazy_load'] ? " checked" : "";
		$checked19 = $editedxfield['allow_multi'] ? "checked" : "";

		if(isset($editedxfield['disabled']) AND $editedxfield['disabled']){
			$disabled = '<input type="hidden" name="editedxfield[disabled]" value="1">';
		}

		$editedxfield['name'] = htmlspecialchars($editedxfield['name'], ENT_QUOTES, 'UTF-8');
		$editedxfield['description'] = htmlspecialchars($editedxfield['description'], ENT_QUOTES, 'UTF-8');
		$editedxfield['hint'] = htmlspecialchars($editedxfield['hint'], ENT_QUOTES, 'UTF-8');
		$type_selected[$editedxfield['type']] = 'selected';
		$side_selected[$editedxfield['image_side']] = 'selected';
		$thumb_side_selected[$editedxfield['thumb_side']] = 'selected';
		$condition_selected[$editedxfield['condition']] = 'selected';
		$date_format_selected[$editedxfield['date_format']] = 'selected';

		$editedxfield['min'] = htmlspecialchars($editedxfield['min'], ENT_QUOTES, 'UTF-8');
		$editedxfield['max'] = htmlspecialchars($editedxfield['max'], ENT_QUOTES, 'UTF-8');
		$editedxfield['max_files'] = htmlspecialchars($editedxfield['max_files'], ENT_QUOTES, 'UTF-8');
		$editedxfield['max_size'] = htmlspecialchars($editedxfield['max_size'], ENT_QUOTES, 'UTF-8');
		$editedxfield['image_sizes'] = htmlspecialchars($editedxfield['image_sizes'], ENT_QUOTES, 'UTF-8');
		$editedxfield['image_size'] = htmlspecialchars($editedxfield['image_size'], ENT_QUOTES, 'UTF-8');
		$editedxfield['image_max_size'] = htmlspecialchars($editedxfield['image_max_size'], ENT_QUOTES, 'UTF-8');
		$editedxfield['thumb_size'] = htmlspecialchars($editedxfield['thumb_size'], ENT_QUOTES, 'UTF-8');
		$editedxfield['max_images'] = htmlspecialchars($editedxfield['max_images'], ENT_QUOTES, 'UTF-8');
		$editedxfield['files_ext'] = htmlspecialchars($editedxfield['files_ext'], ENT_QUOTES, 'UTF-8');
		$editedxfield['file_max_size'] = htmlspecialchars($editedxfield['file_max_size'], ENT_QUOTES, 'UTF-8');
		$editedxfield['date_view_format'] = htmlspecialchars($editedxfield['date_view_format'], ENT_QUOTES, 'UTF-8');
		$editedxfield['select_separator'] = htmlspecialchars($editedxfield['select_separator'], ENT_QUOTES, 'UTF-8');
		$editedxfield['links_separator'] = htmlspecialchars($editedxfield['links_separator'], ENT_QUOTES, 'UTF-8');

		if ($editedxfield['type'] == "text") $defalult_text = htmlspecialchars($editedxfield['default'], ENT_QUOTES, 'UTF-8'); else $defalult_text = '';
		if ($editedxfield['type'] == "textarea" OR $editedxfield['type'] == "htmljs") $default_textarea = htmlspecialchars($editedxfield['default'], ENT_QUOTES, 'UTF-8'); else $default_textarea = '';
		
		if (isset($editedxfield['default']) && is_string($editedxfield['default']) && (substr($editedxfield['default'], 0, 1) === "\n" or substr($editedxfield['default'], 0, 1) === "\r")) {
			$editedxfield['default'] = "\n" . $editedxfield['default'];
		}

		if ($editedxfield['type'] == "select") $defalult_select = htmlspecialchars($editedxfield['default'], ENT_QUOTES, 'UTF-8'); else $defalult_select = '';

	} else {

		$checked = $checked2 = $checked3 = $checked4 = $checked11 = $checked12 = $checked13 = $checked14 = $checked15 = $checked16 = $checked17 = $checked18 = $checked19 = '';
		$editedxfield['name'] = '';
		$editedxfield['description'] = '';
		$editedxfield['hint'] = '';
		$editedxfield['category'] = '';
		$editedxfield['allow_add_usergroups'] = '';
		$editedxfield['allow_view_usergroups'] = '';
		$editedxfield['storage'] = -1;
		$type_selected['text'] = 'selected';
		$defalult_text = '';
		$default_textarea = '';
		$defalult_select = '';
		$editedxfield['min'] = '';
		$editedxfield['max'] = '';
		$editedxfield['max_files'] = '';
		$editedxfield['max_size'] = '';
		$editedxfield['image_sizes'] = '';
		$editedxfield['image_size'] = '';
		$editedxfield['image_max_size'] = '';
		$editedxfield['thumb_size'] = '';
		$editedxfield['max_images'] = '';
		$side_selected[0] = 'selected';
		$thumb_side_selected[0] = 'selected';
		$condition_selected[0] = 'selected';
		$date_format_selected[0] = 'selected';
		$editedxfield['files_ext'] ='';
		$editedxfield['file_max_size'] ='';
		$editedxfield['date_view_format'] = '';
		$editedxfield['select_separator'] = '';
		$editedxfield['links_separator'] = '';
		$editedxfield['group'] = $rubric;
	}

	$cat_options = CategoryNewsSelection(explode(',', $editedxfield['category']), 0, FALSE);
	if (!$editedxfield['category']) $cats_value = "selected";
	else $cats_value = "";

	$groups_add = get_groups(explode(',', $editedxfield['allow_add_usergroups']));
	if (!$editedxfield['allow_add_usergroups']) $groups_add_value = "selected";
	else $groups_add_value = "";

	$groups_view = get_groups(explode(',', $editedxfield['allow_view_usergroups']));
	if (!$editedxfield['allow_view_usergroups']) $groups_view_value = "selected";
	else $groups_view_value = "";

	$storages_list = DLEFiles::getStorages();

	if (count($storages_list)) {
		$storages_list['-1'] = $lang['storage_default'];
		$storages_list['0'] = $lang['opt_sys_imfs_1'];
	} else $storages_list['-1'] = $lang['storage_default'];

	ksort($storages_list);

	if (!isset($editedxfield['storage'])) $editedxfield['storage'] = -1;
	$storages_select = "<select class=\"uniform\" name=\"editedxfield[storage]\">\r\n";

	foreach ($storages_list as $value => $sdescription) {

		$storages_select .= "<option value=\"{$value}\"";

		if ($value == $editedxfield['storage']) {
			$storages_select .= " selected ";
		}

		$storages_select .= ">{$sdescription}</option>\n";
	}

	$storages_select .= "</select>";

	$rubric_select = "<select class=\"uniform\" name=\"editedxfield[group]\">\r\n";
	
	$rubric_select .= "<option value=\"\"";

	if (!$editedxfield['group']) {
		$rubric_select .= " selected ";
	}

	$rubric_select .= ">{$lang['xfield_xno']}</option>\n";

	foreach ($rubrics as $key => $value) {

		$rubric_select .= "<option value=\"{$key}\"";

		if ($key == $editedxfield['group']) {
			$rubric_select .= " selected ";
		}

		$rubric_select .= ">{$value['title']}</option>\n";
	}

	$rubric_select .= "</select>";
	
	echoheader("<i class=\"fa fa-list position-left\"></i><span class=\"text-semibold\">{$lang['header_nf_1']}</span>", $bread);

	echo <<<HTML
<form method="post" name="xfieldsform" class="form-horizontal">
	<script language="javascript">

	function ShowOrHideEx(id, show) {
		var item = null;
		if (document.getElementById) {
			item = document.getElementById(id);
		} else if (document.all) {
			item = document.all[id];
		} else if (document.layers){
			item = document.layers[id];
		}
		if (item && item.style) {
			item.style.display = show ? "" : "none";
		}
	};

	function onTypeChange(value) {
		ShowOrHideEx("default_text", value == "text");
		ShowOrHideEx("optional2", value == "text" || value == "select" || value == "datetime");
		ShowOrHideEx("optional7", value == "text");
		ShowOrHideEx("default_textarea", value == "textarea" || value == "htmljs");
		ShowOrHideEx("optional3", value == "textarea");
		ShowOrHideEx("optional4", value == "text" || value == "textarea");
		ShowOrHideEx("select_options", value == "select");
		ShowOrHideEx("optional",  value != "yesorno");
		ShowOrHideEx("default_image", value == "image" || value == "imagegalery");
		ShowOrHideEx("default_playlist", value == "video" || value == "audio");
		ShowOrHideEx("default_storage", value == "video" || value == "audio" || value == "image" || value == "imagegalery" || value == "file");
		ShowOrHideEx("default_min_max", value == "textarea" || value == "text");	

		ShowOrHideEx("optional5", value == "imagegalery");
		ShowOrHideEx("optional6", value == "yesorno");
		ShowOrHideEx("optional8", value == "datetime");
		ShowOrHideEx("optional9", value == "datetime");
		ShowOrHideEx("default_file", value == "file");
		ShowOrHideEx("default_htmljs", value == "htmljs");
		ShowOrHideEx("default_select", value == "select");
		ShowOrHideEx("optional10", value == "textarea" || value == "image" || value == "imagegalery");
	};

	function onCategoryChange(value) {
		ShowOrHideEx("category_custom", value == "custom");
	}
	</script>
	<input type="hidden" name="mod" value="xfields">
	<input type="hidden" name="user_hash" value="{$dle_login_hash}">
	<input type="hidden" name="action" value="do{$_REQUEST['action']}">
	<input type="hidden" name="action" value="do{$_REQUEST['action']}">
	<input type="hidden" name="editedname" value="{$editedxfield['name']}">
	{$disabled}
	<div class="panel panel-default">
		<div class="panel-heading">{$lang['xfield_title']}</div>
		<div class="panel-body">

			<div class="form-group">
				<label class="control-label col-sm-3">{$lang['xfield_xname']}</label>
				<div class="col-sm-9">
					<input class="form-control width-200" maxlength="30" type="text" dir="auto" name="editedxfield[name]" value="{$editedxfield['name']}"><span class="text-muted text-size-small"><i class="fa fa-exclamation-circle position-left position-right"></i>{$lang['xf_lat']}</span>
				</div>
			</div>

			<div class="form-group">
				<label class="control-label col-sm-3">{$lang['xfield_xdescr']}</label>
				<div class="col-sm-9">
					<input class="form-control width-400" maxlength="100" type="text" dir="auto" name="editedxfield[description]" value="{$editedxfield['description']}">
				</div>
			</div>

			<div class="form-group">
				<label class="control-label col-sm-3">{$lang['xfield_hint']}</label>
				<div class="col-sm-9">
					<input class="form-control width-400" maxlength="200" type="text" dir="auto" name="editedxfield[hint]" value="{$editedxfield['hint']}" placeholder="{$lang['xfield_hint_1']}">
				</div>
			</div>

			<div class="form-group">
				<label class="control-label col-sm-3">{$lang['xf_rubric']}</label>
				<div class="col-sm-9">{$rubric_select}</div>
			</div>

			<div class="form-group">
				<label class="control-label col-sm-3">{$lang['xfield_xcat']}</label>
				<div class="col-sm-9">
					<select name="editedxfield[category][]" id="category" class="categoryselect" data-placeholder="{$lang['addnews_cat_sel']}" style="width:350px;;height:100px;" multiple><option value="" {$cats_value}>{$lang['xfield_xall']}</option>{$cat_options}</select>
				</div>
			</div>

			<div class="form-group">
				<label class="control-label col-sm-3">{$lang['xf_group_add']}</label>
				<div class="col-sm-9">
					<select name="editedxfield[allow_add_usergroups][]" id="groups_add" class="categoryselect" data-placeholder="{$lang['group_select_1']}" style="width:350px;;height:100px;" multiple><option value="" {$groups_add_value}>{$lang['xfield_xall']}</option>{$groups_add}</select>
				</div>
			</div>

			<div class="form-group">
				<label class="control-label col-sm-3">{$lang['xf_group_view']}</label>
				<div class="col-sm-9">
					<select name="editedxfield[allow_view_usergroups][]" id="groups_view" class="categoryselect" data-placeholder="{$lang['group_select_1']}" style="width:350px;;height:100px;" multiple><option value="" {$groups_view_value}>{$lang['xfield_xall']}</option>{$groups_view}</select>
				</div>
			</div>

			<div class="form-group">
				<label class="control-label col-sm-3">{$lang['xfield_xtype']}</label>
				<div class="col-sm-9">
					<select class="uniform" name="editedxfield[type]" id="type" onchange="onTypeChange(this.value);">
						<option value="text" {$type_selected['text']}>{$lang['xfield_xstr']}</option>
						<option value="textarea" {$type_selected['textarea']}>{$lang['xfield_xarea']}</option>
						<option value="htmljs" {$type_selected['htmljs']}>{$lang['xfield_xhtmljs']}</option>
						<option value="select" {$type_selected['select']}>{$lang['xfield_xsel']}</option>
						<option value="image" {$type_selected['image']}>{$lang['xfield_ximage']}</option>
						<option value="imagegalery" {$type_selected['imagegalery']}>{$lang['xfield_ximagegalery']}</option>
						<option value="video" {$type_selected['video']}>{$lang['xfield_xvideo']}</option>
						<option value="audio" {$type_selected['audio']}>{$lang['xfield_xaudio']}</option>
						<option value="file" {$type_selected['file']}>{$lang['xfield_xfile']}</option>
						<option value="yesorno" {$type_selected['yesorno']}>{$lang['xfield_xyesorno']}</option>
						<option value="datetime" {$type_selected['datetime']}>{$lang['xfield_xdatetime']}</option>
					</select>
				</div>
			</div>

			<div class="form-group" id="default_text">
				<label class="control-label col-sm-3">{$lang['xfield_xfaul']}</label>
				<div class="col-sm-9">
					<input class="form-control width-550" type="text" dir="auto" name="editedxfield[default_text]" value="{$defalult_text}">
				</div>
			</div>

			<div class="form-group" id="default_textarea">
				<label class="control-label col-sm-3">{$lang['xfield_xfaul']}</label>
				<div class="col-sm-9">
					<textarea dir="auto" class="classic" style="width:100%;" rows="15" name="editedxfield[default_textarea]">{$default_textarea}</textarea><div id="default_htmljs" class="text-muted text-size-small">{$lang['xfield_xhtmljs_1']}</div>
				</div>
			 </div>	

			<div class="form-group" id="select_options">
				<label class="control-label col-sm-3">{$lang['xfield_xfaul']}</label>
				<div class="col-sm-9">
					<textarea dir="auto" class="classic" style="width:100%;max-width:27.78em;" rows="5" name="editedxfield[default_select]">{$defalult_select}</textarea><div class="text-muted text-size-small">{$lang['xfield_xfsel']}</div>
				</div>
			</div>

			<div id="default_min_max">
				<div class="form-group">
					<label class="control-label col-sm-3">{$lang['xfield_f36']}</label>
					<div class="col-sm-9">
						<input class="form-control text-center" style="width:100%;max-width: 6.94em;" type="text" dir="auto" name="editedxfield[min]" value="{$editedxfield['min']}"><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xi36']}" ></i>
					</div>
				</div>
				<div class="form-group mb-20">
					<label class="control-label col-sm-3">{$lang['xfield_f37']}</label>
					<div class="col-sm-9">
						<input class="form-control text-center" style="width:100%;max-width: 6.94em;" type="text" dir="auto" name="editedxfield[max]" value="{$editedxfield['max']}"><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xi37']}" ></i>
					</div>
				</div>
			</div>

			<div id="default_storage">
				<div class="form-group">
					<label class="control-label col-sm-3">{$lang['storage_upload']}</label>
					<div class="col-sm-9">{$storages_select}</div>
				</div>
			</div>

			<div id="default_playlist">
				<div class="form-group mt-20">
					<label class="control-label col-sm-3">{$lang['xfield_xi12']}</label>
					<div class="col-sm-9">
						<input class="form-control text-center" style="width:100%;max-width: 6.94em;" type="text" dir="auto" name="editedxfield[max_files]" value="{$editedxfield['max_files']}"><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xi13']}" ></i>
					</div>
				</div>
				<div class="form-group mb-20">
					<label class="control-label col-sm-3">{$lang['opt_sys_maxfile']}</label>
					<div class="col-sm-9">
						<input class="form-control text-center" style="width:100%;max-width: 6.94em;" type="text" dir="auto" name="editedxfield[max_size]" value="{$editedxfield['max_size']}"><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['opt_sys_maxfiled']}" ></i>
					</div>
				</div>
			</div>

			<div id="default_image">
				<div class="form-group mt-20">
					<label class="control-label col-sm-3">{$lang['opt_sys_minside']}</label>
					<div class="col-sm-9">
						<input class="form-control text-center" style="width:100%;max-width: 6.94em;" type="text" dir="auto" name="editedxfield[image_sizes]" value="{$editedxfield['image_sizes']}"><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xi22']}" ></i>
					</div>
				</div>

				<div class="form-group">
					<label class="control-label col-sm-3">{$lang['xfield_xi1']}</label>
					<div class="col-sm-9">
						<input class="form-control text-center position-left" style="width:100%;max-width: 6.94em;" type="text" dir="auto" name="editedxfield[image_size]" value="{$editedxfield['image_size']}">
						<select name="editedxfield[image_side]" class="uniform">
							<option value="0" {$side_selected[0]}>{$lang['upload_t_seite_1']}</option>
							<option value="1" {$side_selected[1]}>{$lang['upload_t_seite_2']}</option>
							<option value="2" {$side_selected[2]}>{$lang['upload_t_seite_3']}</option>
						</select>
						<i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xi2']}"></i>
					</div>
				</div>

				<div class="form-group">
			  		<label class="control-label col-sm-3">{$lang['xfield_xi3']}</label>
					<div class="col-sm-9">
						<input class="form-control text-center" style="width:100%;max-width: 6.94em;" type="text" dir="auto" name="editedxfield[image_max_size]" value="{$editedxfield['image_max_size']}"><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xi4']}"></i>
					</div>
				</div>

				<div class="form-group">
					<label class="control-label col-sm-3">{$lang['xfield_xi7']}</label>
					<div class="col-sm-9">
						<input class="form-control text-center position-left" style="width:100%;max-width: 6.94em;" type="text" dir="auto" name="editedxfield[thumb_size]" value="{$editedxfield['thumb_size']}">
						<select name="editedxfield[thumb_side]" class="uniform">
							<option value="0" {$thumb_side_selected[0]}>{$lang['upload_t_seite_1']}</option>
							<option value="1" {$thumb_side_selected[1]}>{$lang['upload_t_seite_2']}</option>
							<option value="2" {$thumb_side_selected[2]}>{$lang['upload_t_seite_3']}</option>
						</select>				
						<i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xi8']}"></i>
					</div>
				</div>

				<div id="optional5" class="form-group">
					<label class="control-label col-sm-3">{$lang['xfield_xi9']}</label>
					<div class="col-sm-9">
						<input class="form-control text-center" style="width:100%;max-width: 6.94em;" type="text" dir="auto" name="editedxfield[max_images]" value="{$editedxfield['max_images']}"><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xi10']}"></i>
					</div>
				</div>
			
				<div class="form-group">
					<label class="control-label col-sm-3"></label>
					<div class="col-sm-9">
						<div class="checkbox"><label><input  class="icheck" type="checkbox" name="editedxfield[make_thumb]" {$checked12}>{$lang['xfield_xi6']}</label></div>
						<div class="checkbox"><label><input  class="icheck" type="checkbox" name="editedxfield[make_watermark]"	{$checked11}>{$lang['xfield_xi5']}</label></div>
						<div class="checkbox"><label><input  class="icheck" type="checkbox" name="editedxfield[use_opengraph]"	{$checked17}>{$lang['xfield_xi11']}</label></div>
					</div>
				</div>
			</div>

			<div id="default_file">
				<div class="form-group mt-20">
					<label class="control-label col-sm-3">{$lang['xfield_xf1']}</label>
					<div class="col-sm-9">
						<input class="form-control width-350" type="text" dir="auto" name="editedxfield[files_ext]" value="{$editedxfield['files_ext']}"><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xf2']}"></i>
					</div>
				</div>
			
				<div class="form-group">
					<label class="control-label col-sm-3">{$lang['opt_sys_maxfile']}</label>
					<div class="col-sm-9">
						<input class="form-control text-center" style="width:100%;max-width: 6.94em;" type="text" dir="auto" name="editedxfield[file_max_size]" value="{$editedxfield['file_max_size']}"><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['opt_sys_maxfiled']}"></i>
					</div>
				</div>
			
				<div class="form-group">
					<label class="control-label col-sm-3"></label>
					<div class="col-sm-9">
						<div class="checkbox"><label><input class="icheck" type="checkbox" name="editedxfield[is_public]" {$checked15}>{$lang['xfield_xpublic']}<i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xhelppub']}"></i></label></div>
					</div>
				</div>
			</div>

			<div id="optional6" class="form-group">
				<label class="control-label col-sm-3">{$lang['xfield_xfaul']}</label>
				<div class="col-sm-9">
					<select class="uniform" name="editedxfield[condition]">
						<option value="0" {$condition_selected[0]}>{$lang['xfsel_off']}</option>
						<option value="1" {$condition_selected[1]}>{$lang['xfsel_on']}</option>
					</select>
				</div>
			</div>

			<div id="optional8">
				<div class="form-group">
					<label class="control-label col-sm-3">{$lang['xfield_xinput']}</label>
					<div class="col-sm-9">
						<select class="uniform" name="editedxfield[date_format]">
							<option value="0" {$date_format_selected[0]}>{$lang['xfield_xdatetime']}</option>
							<option value="1" {$date_format_selected[1]}>{$lang['xfsel_date']}</option>
							<option value="2" {$date_format_selected[2]}>{$lang['xfsel_time']}</option>
						</select>
					</div>
				</div>

				<div class="form-group mb-20">
					<label class="control-label col-sm-3">{$lang['xfield_xoutput']}</label>
					<div class="col-sm-9">
						<input class="form-control" style="width:100%;max-width: 200px;" type="text" dir="auto" name="editedxfield[date_view_format]" value="{$editedxfield['date_view_format']}"> <a onclick="javascript:Help('date'); return false;" href="#">{$lang['opt_sys_and']}</a>
					</div>
				</div>
			</div>			

			<div id="default_select" class="form-group">
				<label class="control-label col-sm-3"></label>
				<div class="col-sm-9">
						<div class="checkbox display-inline-block"><label><input class="icheck" type="checkbox" name="editedxfield[allow_multi]" {$checked19}>{$lang['xfield_asm']}</label></div><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_asm1']}"></i>
				</div>

				<label class="control-label col-sm-3">{$lang['xfield_separator_3']}</label>
				<div class="col-sm-9">
					<input class="form-control width-300" type="text" dir="auto" name="editedxfield[select_separator]" value="{$editedxfield['select_separator']}"><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_separator_2']}"></i>
				</div>
			</div>

			<div id="optional7" class="form-group">
				<label class="control-label col-sm-3">{$lang['xfield_separator']}</label>
				<div class="col-sm-9">
					<input class="form-control width-300" type="text" dir="auto" name="editedxfield[links_separator]" value="{$editedxfield['links_separator']}"><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_separator_1']}"></i>
				</div>
			</div>

			<div class="form-group">
				<label class="control-label col-sm-3"></label>
				<div class="col-sm-9">
		
					<div id="optional">
						<div class="checkbox"><label><input class="icheck" type="checkbox" name="editedxfield[not_required]" {$checked} id="editxfive">{$lang['xfield_xw']}</label></div>
					</div>
			
					<div id="optional9">
						<div class="checkbox"><label><input class="icheck" type="checkbox" name="editedxfield[date_local]" {$checked13}>{$lang['xfield_xlocaldate']}<i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xhelplocal']}"></i></label></div>
						<div class="checkbox"><label><input class="icheck" type="checkbox" name="editedxfield[date_declension]" {$checked14}>{$lang['xfield_xdecldate']}<i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xhelpdec']}"></i></label></div>
					</div>
			
					<div id="optional4">
						<div class="checkbox display-inline-block"><label><input class="icheck" type="checkbox" name="editedxfield[safe_mode]" {$checked4} id="editx8">{$lang['opt_sys_sxfield']}</label></div><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['opt_sys_sxfieldd']}"></i>
					</div>
			
					<div id="optional3">
						<div class="checkbox"><label><input  class="icheck" type="checkbox" name="editedxfield[use_editor]" {$checked3} id="editx7" >{$lang['xfield_xw4']}</label></div>
					</div>
			
					<div id="optional2">
						<div class="checkbox display-inline-block"><label><input class="icheck" type="checkbox" name="editedxfield[use_as_links]" {$checked2} id="editxsixt">{$lang['xfield_xw2']}</label></div><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xw3']}"></i>
					</div>
			
					<div id="optional10">
						<div class="checkbox"><label><input  class="icheck" type="checkbox" name="editedxfield[lazy_load]" {$checked18}>{$lang['opt_sys_laz']}<i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['opt_sys_lazd']}"></i></label></div>
					</div>
			
					<div class="checkbox"><label><input class="icheck" type="checkbox" name="editedxfield[allow_in_news]" {$checked16}>{$lang['xfield_xinnews']}<i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xhelpnws']}"></i></label></div>

				</div>
			 </div>

		</div>
		<div class="panel-footer">
			<button type="submit" class="btn bg-teal btn-sm btn-raised position-left"><i class="fa fa-floppy-o position-left"></i>{$lang['user_save']}</button>
		</div>
	</div>
</form>
<script>
	var item_type = document.getElementById("type");
	var item_category = document.getElementById("category");

	if (item_type) {
		onTypeChange(item_type.value);
		onCategoryChange(item_category.value);
	}

	$(function(){
		$('.categoryselect').chosen({allow_single_deselect:true, no_results_text: '{$lang['addnews_cat_fault']}'});
	});
</script>	
HTML;

	echofooter();
	die();
}


$rubrics = DLEXFields::GetRubricsArray();

if(isset($_GET['rubric']) AND $_GET['rubric'] AND isset($rubrics[$_GET['rubric']]) ) {
	$rubric = totranslit($_GET['rubric'], true, false);
	$bread = array('?mod=xfields' => $lang['header_nf_1'], '?mod=xfields&rubric=' . $rubric => $rubrics[$rubric]['title']);
} else {
	$rubric = '';
	$bread = array('?mod=xfields'  => $lang['header_nf_1'], '' => $lang['header_nf_2']);
}

$xfields =  DLEXFields::GETFieldByRubric($rubric);

$js_array[] = "public/js/sortable.js";

echoheader("<i class=\"fa fa-list position-left\"></i><span class=\"text-semibold\">{$lang['header_nf_1']}</span>", $bread );

$r_list = '';

if( !$rubric ) {
	$i = 0;

	foreach ($rubrics as $key => $value) {

		$menu_link = <<<HTML
		<div class="btn-group">
			<a href="#" class="dropdown-toggle nocolor" data-toggle="dropdown" aria-expanded="true"><i class="fa fa-bars"></i><span class="caret"></span></a>
			<ul class="dropdown-menu dropdown-menu-right">
				<li><a uid="{$key}" href="?mod=xfields" class="editlink"><i class="fa fa-pencil-square-o"></i> {$lang['group_sel1']}</a></li>
				<li class="divider"></li>
				<li><a onclick="javascript:confirm_rubric_delete('{$key}'); return false;" href="#"><i class="fa fa-trash-o text-danger"></i> {$lang['xfield_xfid']}</a></li>
			</ul>
		</div>
HTML;

		$r_list .= "
		<tr class=\"dd-item dd-item-table drag-bg\" data-id=\"{$key}\">
			<td class=\"dd-handles\"></td>
			<td class=\"cursor-pointer\" onclick=\"document.location = '?mod=xfields&rubric={$key}'; return false;\"><h6 id=\"title_{$key}\" class=\"media-heading text-semibold\">{$value['title']}</h6><div class=\"text-muted text-size-small\">{$value['description']}</div><textarea dir=\"auto\" id=\"descr_{$key}\" style=\"display:none;\">{$value['description']}</textarea></td>
			<td class=\"text-center\" style=\"width:4.86em\">{$menu_link}</td>
		</tr>";

		$i++;
	}

	if ($r_list) $r_list = '<table class="table table-xs"><tbody class="dd-list dd-table" id="rubriclist">' . $r_list . '</tbody></table>';
}

if (!count($xfields) AND !$r_list) {
	
	$x_list = "<div class=\"panel-body\"><center><br>{$lang['xfield_xnof']}<br><br></center></div>";

} else {

	$x_list = "";

	foreach ($xfields as $name => $value) {

		if ($value['category']) {

			$cats_v = array();
			$cat_list = explode(',', $value['category'] );

			foreach ($cat_list as $element) {
				$element = trim ($element);
				if (isset($cat_info[$element]['name']) and $cat_info[$element]['name'] and $element) $cats_v[] = $cat_info[$element]['name'];
			}

			if (count($cats_v)) $cats_v = implode(', ', $cats_v);
			else $cats_v = $lang['xfield_xall'];

		} else $cats_v = $lang['xfield_xall'];


		if ($value['type'] == "text") $type = $lang['xfield_xstr'];
		elseif ($value['type'] == "textarea") $type = $lang['xfield_xarea'];
		elseif ($value['type'] == "select") $type = $lang['xfield_xsel'];
		elseif ($value['type'] == "image") $type = $lang['xfield_ximage'];
		elseif ($value['type'] == "imagegalery") $type = $lang['xfield_ximagegalery'];
		elseif ($value['type'] == "file") $type = $lang['xfield_xfile'];
		elseif ($value['type'] == "yesorno") $type = $lang['xfield_xyesorno'];
		elseif ($value['type'] == "htmljs") $type = $lang['xfield_xhtmljs'];
		elseif ($value['type'] == "datetime") $type = $lang['xfield_xdatetime'];
		elseif ($value['type'] == "video") $type = $lang['xfield_xvideo'];
		elseif ($value['type'] == "audio") $type = $lang['xfield_xaudio'];

		$req = $value['not_required'] != 0 ? $lang['opt_sys_yes'] : $lang['opt_sys_no'];
		
		if( $value['description'] ) {
			$description = "<div class=\"text-muted text-size-small\">{$value['description']}</div>";
		} else $description = '';

		if (isset($value['disabled']) AND $value['disabled']) {
			$status = "<span title=\"{$lang['xfield_off']}\" class=\"text-danger tip\"><b><i class=\"fa fa-exclamation-circle\"></i></b></span>";
			$lang['led_active'] = $lang['all_enable'];
			$led_action = "on";
		} else {
			$status = "<span title=\"{$lang['xfield_on']}\" class=\"text-success tip\"><b><i class=\"fa fa-check-circle\"></i></b></span>";
			$lang['led_active'] = $lang['opt_sys_r1'];
			$led_action = "off";
		}

		$menu_link = <<<HTML
		<div class="btn-group">
			<a href="#" class="dropdown-toggle nocolor" data-toggle="dropdown" aria-expanded="true"><i class="fa fa-bars"></i><span class="caret"></span></a>
			<ul class="dropdown-menu dropdown-menu-right">
				<li><a href="?mod=xfields&action=edit&name={$name}&rubric={$rubric}"><i class="fa fa-pencil-square-o"></i> {$lang['group_sel1']}</a></li>
				<li><a href="?mod=xfields&action={$led_action}&name={$name}&rubric={$rubric}&user_hash={$dle_login_hash}"><i class="fa fa-eye"></i> {$lang['led_active']}</a></li>
				<li class="divider"></li>
				<li><a onclick="javascript:xfdelete('{$name}'); return false;" href="#"><i class="fa fa-trash-o text-danger"></i> {$lang['xfield_xfid']}</a></li>
			</ul>
		</div>
HTML;

		$x_list .= "
		<tr class=\"drag-bg allow-drag\" data-id=\"{$name}\">
			<td class=\"dd-handles\"></td>
			<td class=\"cursor-pointer\" onclick=\"document.location = '?mod=xfields&action=edit&name={$name}&rubric={$rubric}'; return false;\">{$name}{$description}</td>
			<td class=\"cursor-pointer\" onclick=\"document.location = '?mod=xfields&action=edit&name={$name}&rubric={$rubric}'; return false;\">{$cats_v}</td>
			<td class=\"cursor-pointer\" onclick=\"document.location = '?mod=xfields&action=edit&name={$name}&rubric={$rubric}'; return false;\">{$type}</td>
			<td class=\"cursor-pointer\" onclick=\"document.location = '?mod=xfields&action=edit&name={$name}&rubric={$rubric}'; return false;\">{$req}</td>
			<td class=\"text-center\">{$status}{$menu_link}</td>
		</tr>";


	}


	if ($x_list) {
		$th_head = <<<HTML
      <tr>
        <th style="width: 2.22em;"></th>
        <th>{$lang['xfield_xname']}</th>
		<th style="width: 16.66em;">{$lang['xfield_xcat']}</th>
		<th style="width: 18.88em;">{$lang['xfield_xtype']}</th>
        <th style="width: 8.88em;">{$lang['xfield_xwt']}</th>
        <th style="width: 5.5em">&nbsp;</th>
      </tr>
HTML;

	}

	$x_list =<<<HTML
	<table class="table table-xs table-hover" style="table-layout:fixed;">
		<thead>
			{$th_head}
		</thead>
		<tbody id="xflist">
			{$x_list}
		</tbody>		
	</table>
HTML;

}

if($rubric) {
	$lang['xfield_xlist'] .= ': '.$rubrics[$rubric]['title'];
}

echo <<<HTML
<div class="panel panel-default">
  <div class="panel-heading">
    {$lang['xfield_xlist']}
  </div>
  <div class="table-responsive">
	{$r_list}
	{$x_list}
  </div>
	<div class="panel-footer">
		<div class="pull-left">
		<button type="button" onclick="document.location='?mod=xfields&action=add&rubric={$rubric}'" class="btn bg-teal btn-sm btn-raised position-left"><i class="fa fa-plus position-left"></i>{$lang['b_create']}</button>
		<button type="button" onclick="addRubric(); return false;" class="btn bg-slate-600 btn-sm btn-raised position-left"><i class="fa fa-plus position-left"></i>{$lang['xf_rubric_1']}</button>
		</div>
		<div class="pull-right">
		<a onclick="javascript:Help('xfields'); return false;" href="#">{$lang['xfield_xhelp']}</a>
		</div>
	</div>
</div>
<script>
function addRubric() {
	var b = {};
	var ww = 600 * getBaseSize();

	if(ww > ( $(window).width() * 0.95 ) )  { ww = $(window).width() * 0.95;  }

	b[dle_act_lang[3]] = function() { 
					$(this).dialog("close");						
			    };
	
	b['{$lang['news_add']}'] = function() { 
					if ( $("#dle-promt-title").val().length < 1) {
						 $("#dle-promt-title").addClass('ui-state-error');
					} else {
						$("#addrubric").submit();
					}			
				};

	$("#dlepopup").remove();

	$("body").append("<div id='dlepopup' title='{$lang['xf_rubric_4']}' style='display:none'><form id='addrubric' method='post'>{$lang['xf_rubric_2']}<input type='hidden' name='mod' value='xfields'><input type='hidden' name='action' value='addrubric'><input type='hidden' name='user_hash' value='{$dle_login_hash}'><br><input type='text' dir='auto' name='title' id='dle-promt-title' class='form-control' style='width:100%;' value=''><br><br>{$lang['xf_rubric_3']}<br><textarea dir='auto' name='description' id='dle-promt-descr' class='classic' style='width:100%;' rows='3'></textarea></form></div>");
	
	$('#dlepopup').dialog({
		autoOpen: true,
		width: ww,
		resizable: false,
		buttons: b
	});

};

function confirm_rubric_delete(id){
	    DLEconfirmDelete( '{$lang['rubric_del_1']}', '{$lang['p_confirm']}', function () {
			document.location="?mod=xfields&action=deleterubric&user_hash={$dle_login_hash}&rid="+id;
		} );
}

jQuery(function($){

	$('.editlink').click(function(){

		var rid = $(this).attr('uid');
		var title = $('#title_'+$(this).attr('uid')).text();
		title = title.replace(/'/g, "&#039;");
		var description = $('#descr_'+rid).val();
			
			var b = {};

			var ww = 600 * getBaseSize();

			if(ww > ( $(window).width() * 0.95 ) )  { ww = $(window).width() * 0.95;  }

			b[dle_act_lang[3]] = function() { 
							$(this).dialog("close");						
					    };
		
			b['{$lang['news_save']}'] = function() { 
						if ( $("#dle-promt-title").val().length < 1) {
							 $("#dle-promt-title").addClass('ui-state-error');
						} else {
							$("#editrubric").submit();
						}					
					};
	
			$("#dlepopup").remove();

		$("body").append("<div id='dlepopup' title='{$lang['xf_rubric_5']}' style='display:none'><form id='editrubric' method='post'>{$lang['xf_rubric_2']}<input type='hidden' name='mod' value='xfields'><input type='hidden' name='action' value='editrubric'><input type='hidden' name='user_hash' value='{$dle_login_hash}'><input type='hidden' name='editrubricid' value='"+rid+"'><br><input type='text' dir='auto' name='title' id='dle-promt-title' class='form-control' style='width:100%;' value='"+title+"'><br><br>{$lang['xf_rubric_3']}<br><textarea dir='auto' name='description' id='dle-promt-descr' class='classic' style='width:100%;' rows='3'>"+description+"</textarea></form></div>");
		
			$('#dlepopup').dialog({
				autoOpen: true,
				width: ww,
				resizable: false,
				buttons: b
			});

			return false;
	});
	
    if( document.getElementById('rubriclist') ) {
		var rubric_sort = new Sortable(document.getElementById('rubriclist'), {
			group: "rubric",
			animation: 150,
			ghostClass: 'drop-bg',
			handle: '.dd-handles',
			onChoose(evt) {
        		evt.item.classList.add('is-sorting');
    		},
    		onEnd(evt) {
        		evt.item.classList.remove('is-sorting');
    		},			
			onSort: function (evt) {
				ShowLoading('');

				$.post('index.php?controller=ajax&mod=adminfunction', {'action': 'xfrubricsort', 'list': window.JSON.stringify(rubric_sort.toArray()), user_hash: '{$dle_login_hash}'}, function(data){

					HideLoading('');

					if (data != 'ok') {

						DLEPush.error('{$lang['cat_sort_fail']}');

					} else {
						
						location.reload();
						
					}

				});

			}

		});
	}

    if( document.getElementById('xflist') ) {
		var xf_sort = new Sortable(document.getElementById('xflist'), {
			group: "xfield",
			animation: 150,
			ghostClass: 'drop-bg',
			handle: '.dd-handles',
			draggable: '.allow-drag',
			onChoose(evt) {
        		evt.item.classList.add('is-sorting');
    		},
    		onEnd(evt) {
        		evt.item.classList.remove('is-sorting');
    		},
			onSort: function (evt) {
				ShowLoading('');

				$.post('index.php?controller=ajax&mod=adminfunction', {'action': 'xfsort', 'rubric': '{$rubric}', 'list': window.JSON.stringify(xf_sort.toArray()), user_hash: '{$dle_login_hash}'}, function(data){

					HideLoading('');

					if (data != 'ok') {
						console.log();
						DLEPush.error('{$lang['cat_sort_fail']}');

					} else {
						
						location.reload();
						
					}

				});

			}

		});
	}

});
function xfdelete(id){
	
	DLEconfirmDelete( '{$lang['xfield_err_6']}', '{$lang['p_confirm']}', function () {
		document.location='?mod=xfields&action=delete&name=' + id +'&rubric={$rubric}&user_hash={$dle_login_hash}';
	} );
}
</script>
HTML;

echofooter();