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
 File: adminfunction.php
-----------------------------------------------------
 Use: Adminpanel AJAX functions
=====================================================
*/

if(!defined('DATALIFEENGINE')) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

$_REQUEST['action'] = isset($_REQUEST['action']) ? $_REQUEST['action'] : "";

if ($_REQUEST['action'] == "selfdelete") {

	if (!$is_logged) {
		die("{\"error\":\"{$lang['err_notlogged']}\"}");
	}

	if (!isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash) {

		die("{\"error\":\"{$lang['err_notlogged']}\"}");
	}

	if ( !$user_group[$member_id['user_group']]['self_delete']) {
		die("{\"error\":\"{$lang['err_selfdel_1']}\"}");
	}

	if (($member_id['user_group'] == 1)) {
		die("{\"error\":\"{$lang['err_selfdel_2']}\"}");
	}

	if (!$member_id['user_id'] OR !$member_id['password'] OR !isset($_POST['password']) OR !$_POST['password'] OR !password_verify($_POST['password'], $member_id['password'])) {
		die("{\"error\":\"{$lang['password_incorect']}\"}");
	}

	if($user_group[$member_id['user_group']]['self_delete'] == 1 ){
		
		$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '137', '')");
		
		if( $user_group[$member_id['user_group']]['allow_delc'] OR $user_group[$member_id['user_group']]['del_allc']) {
	
			deletecommentsbyuserid($member_id['user_id']);
			clear_cache(array('news_', 'comm_', 'full_'));
	
		}

		deleteuserbyid($member_id['user_id']);
		clear_cache(array('stats'));
		
		if (isset($_COOKIE) and is_array($_COOKIE) and count($_COOKIE)) {

			foreach ($_COOKIE as $key => $value) {
				set_cookie($key, '', 0);
			}
		}

		session_unset();
		session_destroy();

		die("{\"status\":\"deleted\"}");
	
	} else {

		$row = $db->super_query("SELECT user_id FROM " . USERPREFIX . "_users_delete WHERE user_id='{$member_id['user_id']}'");

		if(isset($row['user_id']) AND $row['user_id']) {
			die("{\"error\":\"{$lang['err_selfdel_3']}\"}");
		}

		$db->query("INSERT INTO " . USERPREFIX . "_users_delete (user_id) values ('{$member_id['user_id']}')");

		die("{\"status\":\"wait\"}");
	}

}

if ($_REQUEST['action'] == "findcommentspage") {

	if (!isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash) {

		die("error");
	}

	function build_comments_tree($data) {

		$tree = array();
		foreach ($data as $id => &$node) {
			if ($node['parent'] === false) {
				$tree[$id] = &$node;
			} else {
				if (!isset($data[$node['parent']]['children'])) $data[$node['parent']]['children'] = array();
				$data[$node['parent']]['children'][$id] = &$node;
			}
		}

		return $tree;
	}

	function searchByFieldValue($array, $field, $value) {

		foreach ($array as $item) {
			
			if (isset($item[$field]) AND $item[$field] == $value) {
			
				return true;
			
			}

			if (isset($item['children']) AND is_array($item['children'])) {
				if (searchByFieldValue($item['children'], $field, $value)) {
					return true;
				}
			}

		}

		return false;
	}

	$post_id = intval($_REQUEST['post_id']);
	$comment_id = intval($_REQUEST['comment_id']);

	if($post_id AND $comment_id) {

		$row = $db->super_query("SELECT id, date, category, alt_name FROM " . PREFIX . "_post WHERE id='{$post_id}'");

		if( isset( $row['id'] ) AND $row['id'] )  {
			$row['date'] = strtotime($row['date']);
			
			$full_link = DLEUrl::BuildUrl('showfull', ['category' => get_url($row['category']), 'year' => date('Y', $row['date']), 'month' => date('m', $row['date']), 'day' => date('d', $row['date']), 'news_name' => $row['alt_name'], 'newsid' => $row['id']]);
			$comments_navigation_link = DLEUrl::BuildUrl('showfull.page.newscomments', ['category' => get_url($row['category']), 'year' => date('Y', $row['date']), 'month' => date('m', $row['date']), 'day' => date('d', $row['date']), 'news_name' => $row['alt_name'], 'newsid' => $row['id'], 'news_page' => 1]);

		} else {
			$return = array(
				'status' => 'error',
				'url' => '',
			);

			$return = json_encode($return, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

			die($return);
		}

		$rows = array();
		
		if ($config['allow_cmod']) $where_approve = " AND " . PREFIX . "_comments.approve=1";
		else $where_approve = "";

		$sql_result = $db->query("SELECT " . PREFIX . "_comments.id, " . PREFIX . "_comments.parent FROM " . PREFIX . "_comments WHERE " . PREFIX . "_comments.post_id = '{$post_id}'{$where_approve}  ORDER BY " . PREFIX . "_comments.id ASC");

		while ($row = $db->get_row($sql_result)) {
			$rows[$row['id']] = array();

			foreach ($row as $key => $value) {
				if ($key == "parent" AND ($value == 0 OR !$config['tree_comments'])) $value = false;
				$rows[$row['id']][$key] = $value;
			}
		}

		$db->free($sql_result);
		unset($row);

		if( count($rows ) ) {
			$rows = build_comments_tree($rows);

			if ($config['comm_msort'] == "DESC") $rows = array_reverse($rows, true);

			$rows = array_chunk($rows, intval($config['comm_nummers']) );

			$page = 1;
			$page_found = false;

			foreach ($rows as $arr ) {

				if( searchByFieldValue($arr, 'id', $comment_id) ){
					$page_found = true;
					break;
				}

				$page ++;
			}

			if($page && $page_found) {

				if( $page > 1 ) {
					$full_link = str_replace('{cstart}', $page, $comments_navigation_link);
				}

				$return = array(
					'status' => 'ok',
					'url' => DLEUrl::ClearDomain($full_link)
				);

				$return = json_encode($return, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

				die($return);

			} 

		}

	}

	$return = array(
		'status' => 'error',
		'url' => DLEUrl::ClearDomain($full_link),
	);

	$return = json_encode($return, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

	die($return);
}

if ($_REQUEST['action'] == "newsread") {
	
	if( !$config['allow_read_count'] ) {
		die ("error");
	}
	
	$id = intval($_REQUEST['id']);
	
	$row = $db->super_query( "SELECT news_id FROM " . PREFIX . "_post_extras WHERE news_id = '{$id}'" );
	
	if(isset($row['news_id']) AND $row['news_id']) {
		
		if ( $config['allow_read_count'] == 2 ) {
	
			$readcount = $db->super_query( "SELECT count(*) as count FROM " . PREFIX . "_read_log WHERE news_id='{$row['news_id']}' AND ip='{$_IP}'" );
	
			if( !$readcount['count'] ) {
	
				if( $config['cache_count'] ) $db->query( "INSERT INTO " . PREFIX . "_views (news_id) VALUES ('{$row['news_id']}')" );
				else $db->query( "UPDATE " . PREFIX . "_post_extras SET news_read=news_read+1 WHERE news_id='{$row['news_id']}'" );
	
				$db->query( "INSERT INTO " . PREFIX . "_read_log (news_id, ip) VALUES ('{$row['news_id']}', '{$_IP}')" );
			}
	
		} else {
	
			if( $config['cache_count'] ) $db->query( "INSERT INTO " . PREFIX . "_views (news_id) VALUES ('{$row['news_id']}')" );
			else $db->query( "UPDATE " . PREFIX . "_post_extras SET news_read=news_read+1 WHERE news_id='{$row['news_id']}'" );
		}

	}
	
	die('ok');
	
}

if ($_REQUEST['action'] == "bannersviews") {

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}
	
	$ids = $uniq_ids_ip = $max_views = array();
	
	foreach ($_REQUEST['ids'] as $id) {

		$id = intval($id);

		if($id < 1 ) die ("error");

		$ids[$id] = $id;
		
	}
	
	if( !count($ids) ) die ("error");

	$db->query( "SELECT id, allow_views, max_views, views FROM " . PREFIX . "_banners WHERE id='".implode("' OR id='", $ids)."'" );
	
	while ( $row = $db->get_row() ) {
		if($row['allow_views'] == 2 ) $uniq_ids_ip[$row['id']] = $row['id'];
		if( $row['max_views'] ) $max_views[$row['id']] = array('max_views' => $row['max_views'], 'views' => $row['views']);
	}
	
	if( count($uniq_ids_ip) ) {
		$db->query( "SELECT bid FROM " . PREFIX . "_banners_logs WHERE (bid='".implode("' OR bid='", $uniq_ids_ip)."') AND ip='{$_IP}'" );
		while ( $row = $db->get_row() ) {
			unset($ids[$row['bid']]);
			unset($uniq_ids_ip[$row['bid']]);
		}
	}
	
	if( count($ids) ) {
		$db->query( "UPDATE " . PREFIX . "_banners SET views=views+1 WHERE id='".implode("' OR id='", $ids)."'" );
		foreach ($ids as $id) {
			if($max_views[$id]['max_views'] AND ($max_views[$id]['views']+1) >= $max_views[$id]['max_views'] ) {
				@unlink( ENGINE_DIR . '/cache/system/banners.json' );
			}
		}
	}
	
	if( count($uniq_ids_ip) ) {
		foreach ($uniq_ids_ip as $id) {
			$db->query( "INSERT INTO " . PREFIX . "_banners_logs (bid, ip) VALUES ('{$id}', '{$_IP}')" );
		}
	}
	
	die( "ok" );
}

if ($_REQUEST['action'] == "bannersclick") {
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		die ("error");
	}
	
	$id = intval($_REQUEST['id']);
	
	if($id < 1 ) die ("error");
	
	$row = $db->super_query( "SELECT allow_counts, max_counts, clicks FROM " . PREFIX . "_banners WHERE id='{$id}'" );
	$max_counts = $row['max_counts'];
	$clicks = $row['clicks'];
	
	if( !$row['allow_counts'] ) die ("error");
	
	if( $row['allow_counts'] == 2 ) {
		
		$row = $db->super_query( "SELECT id, click FROM " . PREFIX . "_banners_logs WHERE bid='{$id}' AND ip='{$_IP}'" );
		
		if(!$row['click']) {
			$db->query( "UPDATE " . PREFIX . "_banners SET clicks=clicks+1 WHERE id='{$id}'" );
			
			if($max_counts AND ($clicks+1) >= $max_counts ) {
				@unlink( ENGINE_DIR . '/cache/system/banners.json' );
			}
		}
		
		if($row['id']) $db->query( "UPDATE " . PREFIX . "_banners_logs SET click='1' WHERE id='{$row['id']}'" );
		else $db->query( "INSERT INTO " . PREFIX . "_banners_logs (bid, click, ip) VALUES ('{$id}', '1', '{$_IP}')" );
		
	} else {
		
		$db->query( "UPDATE " . PREFIX . "_banners SET clicks=clicks+1 WHERE id='{$id}'" );
		
		if($max_counts AND ($clicks+1) >= $max_counts ) {
			@unlink( ENGINE_DIR . '/cache/system/banners.json' );
		}

	}
	
	die( "ok" );	
}

if ($_REQUEST['action'] == "del_social") {
	
	$id = intval($_REQUEST['id']);

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash OR !$member_id['user_id'] OR !$id) {
		echo $lang['err_notlogged'];
		die ();
	}
	
	if (!$is_logged) {
		echo $lang['err_notlogged'];
		die();
	}

	if( $member_id['user_group'] == 1 ) {
		$db->query("DELETE FROM " . USERPREFIX . "_social_login WHERE id='{$id}'");
	} else {
		$db->query("DELETE FROM " . USERPREFIX . "_social_login WHERE uid='{$member_id['user_id']}' AND id='{$id}'");
	}
	
	echo $lang['del_from_social_2'];
	die();
}

if ($_REQUEST['action'] == "add_ignore") {
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash OR !$member_id['user_id']) {
		die("{\"error\":\"{$lang['err_notlogged']}\"}");
	}

	if (isset($_REQUEST['skin']) AND $_REQUEST['skin']) {
		$_REQUEST['skin'] = $_REQUEST['dle_skin'] = trim(totranslit($_REQUEST['skin'], false, false));
	}

	if (isset($_REQUEST['dle_skin']) AND $_REQUEST['dle_skin']) {

		$_REQUEST['dle_skin'] = trim(totranslit($_REQUEST['dle_skin'], false, false));

		if ($_REQUEST['dle_skin'] and @is_dir(ROOT_DIR . '/templates/' . $_REQUEST['dle_skin'])) {

			$config['skin'] = $_REQUEST['dle_skin'];
		}

		if (isset($config['lang_' . $config['skin']]) AND $config['lang_' . $config['skin']] AND file_exists(DLEPlugins::Check(ROOT_DIR . '/language/' . $config['lang_' . $config['skin']] . '/website.lng'))) {

			include_once(DLEPlugins::Check(ROOT_DIR . '/language/' . $config["lang_" . $config['skin']] . '/website.lng'));
		}
	}

	$id = intval($_GET['id']);
	
	$row = $db->super_query( "SELECT name, user_id, user_group FROM " . USERPREFIX . "_users WHERE user_id='{$id}'" );
	
	if( !isset($row['user_id']) ) {
		die("{\"error\":\"Operation not Allowed\"}");
	}
	
	if ($row['user_id'] == $member_id['user_id']) {
		die("{\"error\":\"{$lang['ignore_error']}\"}");
	}
	
	if ($user_group[$row['user_group']]['admin_editusers']) {
		die("{\"error\":\"{$lang['ignore_error_2']}\"}");
	}
	
	$db->query( "SELECT id FROM " . USERPREFIX . "_ignore_list WHERE user_from='{$row['name']}' AND user='{$member_id['user_id']}'" );

	if ($db->num_rows()) {
		die("{\"error\":\"{$lang['ignore_error_1']}\"}");
		
	}
	
	$db->query( "INSERT INTO " . USERPREFIX . "_ignore_list (user, user_from) values ('{$member_id['user_id']}', '{$row['name']}')" );
	
	die("{\"success\":\"{$lang['ignore_ok']}\"}");
	
}

if ($_REQUEST['action'] == "del_ignore") {
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash OR !$member_id['user_id']) {
		die ( $lang['err_notlogged'] );
	}
	
	if (isset($_REQUEST['skin']) and $_REQUEST['skin']) {
		$_REQUEST['skin'] = $_REQUEST['dle_skin'] = trim(totranslit($_REQUEST['skin'], false, false));
	}

	if (isset($_REQUEST['dle_skin']) and $_REQUEST['dle_skin']) {

		$_REQUEST['dle_skin'] = trim(totranslit($_REQUEST['dle_skin'], false, false));

		if ($_REQUEST['dle_skin'] and @is_dir(ROOT_DIR . '/templates/' . $_REQUEST['dle_skin'])) {

			$config['skin'] = $_REQUEST['dle_skin'];
		}

		if (isset($config['lang_' . $config['skin']]) and $config['lang_' . $config['skin']] and file_exists(DLEPlugins::Check(ROOT_DIR . '/language/' . $config['lang_' . $config['skin']] . '/website.lng'))) {

			include_once(DLEPlugins::Check(ROOT_DIR . '/language/' . $config["lang_" . $config['skin']] . '/website.lng'));
		}
	}

	$id = intval($_GET['id']);

	$row = $db->super_query( "SELECT * FROM " . USERPREFIX . "_ignore_list WHERE id='{$id}'" );

	if (isset($row['id']) AND ($row['user'] == $member_id['user_id'] OR $user_group[$member_id['user_group']]['admin_editusers'] ) ) {
		
		$db->query( "DELETE FROM " . USERPREFIX . "_ignore_list WHERE id = '{$row['id']}'" );
		
		die($lang['ignore_del_ok']);
	
	}

	die("Operation not Allowed");

}

if ($_REQUEST['action'] == "saveeditnews") {
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash OR !$member_id['user_id']) {
		die ( "{\"error\":\"{$lang['err_notlogged']}\"}" );
	}
	
	$id = intval($_REQUEST['id']);

	$row = $db->super_query( "SELECT id, autor, date, category  FROM " . PREFIX . "_post WHERE id = '{$id}'" );
	
	if( isset($row['id']) AND $row['id']) {
		
		$have_perm = 0;
		
		$cat_list = explode(',', $row['category']);

		if( $user_group[$member_id['user_group']]['allow_edit'] AND $row['autor'] == $member_id['name'] ) {
			$have_perm = 1;
		}
	
		if( $user_group[$member_id['user_group']]['allow_all_edit'] ) {
			$have_perm = 1;
			
			if($member_id['cat_add']) $allow_list = explode( ',', $member_id['cat_add'] );
			else $allow_list = explode( ',', $user_group[$member_id['user_group']]['cat_add'] );
	
			foreach ( $cat_list as $selected ) {
				if( $allow_list[0] != "all" and !in_array( $selected, $allow_list ) ) $have_perm = 0;
			}
		}
	
		if( $user_group[$member_id['user_group']]['max_edit_days'] ) {
			$newstime = strtotime( $row['date'] );
			$maxedittime = $_TIME - ($user_group[$member_id['user_group']]['max_edit_days'] * 3600 * 24);
			if( $maxedittime > $newstime ) $have_perm = 0;
		}
		
		if( ($member_id['user_group'] == 1) ) {
			$have_perm = 1;
		}
	
		if( !$have_perm ) {
			die ( "{\"error\":\"Operation not Allowed\"}" );
		}
		
		$db->query( "UPDATE " . PREFIX . "_post_extras SET edited_now='".$db->safesql(json_encode(array('name' => $member_id['name'], 'time' => time() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ))."' WHERE news_id='{$row['id']}'" );
		
		die ( "{\"success\":\"1\"}" );
		
	}
	
	die ( "{\"error\":\"Operation not Allowed\"}" );

}

if ($_REQUEST['action'] == "viewshared") {
	
	if( !$is_logged ) {
		die ( "{\"error\":\"{$lang['err_notlogged']}\"}" );
	}
	
	if( !$user_group[$member_id['user_group']]['allow_image_upload'] AND !$user_group[$member_id['user_group']]['allow_file_upload'] ) {
	
		die ( "{\"error\":\"{$lang['err_noupload']}\"}" );
	
	}
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash OR !$member_id['user_id']) {
		die ( "{\"error\":\"{$lang['err_notlogged']}\"}" );
	}
	
	$allowed_extensions = array ("gif", "jpg", "png", "jpeg", "webp" , "bmp", "avif");
	$userdir = '';
	
	if( isset($_REQUEST['userdir']) AND $_REQUEST['userdir'] ) {
		
		$userdir = cleanpath( $_REQUEST['userdir'] );
		
	}
	
	$driver = null;

	if ( intval($config['shared_remote']) > -1 ) $driver = intval($config['shared_remote']);

	DLEFiles::init( $driver );

	$files = DLEFiles::ListDirectory( 'shared/'.$userdir, $allowed_extensions );
	
	if( DLEFiles::$error ) {
		
		$return_array = array (
			'success' => false,
			'error' => DLEFiles::$error
		);
		
		$error = json_encode($return_array, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		
		die($error);
	}

	$folder_list = array();
	
	$folder_list[] = '<div class="file-preview-box">';
	
	if( $userdir ) {
		
		$prev_link = explode("/", $userdir);
		array_pop($prev_link);
		$prev_link = implode("/", $prev_link);
		
		$folder_list[] = <<<HTML
<div class="folder-preview-card">
	<div class="file-content" onclick="get_shared_list('{$prev_link}'); return false;">
		<i class="mediaupload-icon mediaupload-icon-prev"></i>
	</div>
	<div class="file-footer">
		<div class="file-footer-caption">
			<div class="file-caption-info">{$lang['files_prev']}</div>
		</div>
	</div>
</div>
HTML;

	}
	
	foreach ( $files['dirs'] as $entryname) {
		
		$folder = $entryname['name'];
		
		if($folder == 'medium' OR $folder == 'thumbs') continue;
		
		if( $userdir ) $link = $userdir."/".$folder; else $link = $folder;
		
		if($link == "files") continue;
		
		$folder_list[] = <<<HTML
<div class="folder-preview-card">
	<div class="file-content" onclick="get_shared_list('{$link}'); return false;">
		<img src="{$config['http_home_url']}public/adminpanel/images/folder.png" class="file-preview-image">
	</div>
	<div class="file-footer">
		<div class="file-footer-caption">
			<div class="file-caption-info">{$folder}</div>
		</div>
	</div>
</div>
HTML;

	}
	
	
	foreach ( $files['files'] as $entryname ) {

		$http_url = DLEFiles::GetBaseURL() . 'shared/';
			
		if($userdir) {
			$img_url = $http_url . $userdir . "/" . $entryname['name'];
			$path = $userdir . "/" . $entryname['name'];
			$medium_path = $userdir . "/medium/" . $entryname['name'];
			$thumb_path = $userdir . "/thumbs/" . $entryname['name'];
		} else {
			$img_url = $http_url . $entryname['name'];
			$path = $entryname['name'];
			$medium_path = "medium/" . $entryname['name'];
			$thumb_path = "thumbs/" . $entryname['name'];
		}
		
		$base_name = pathinfo($entryname['name'], PATHINFO_FILENAME);
		$file_type = explode(".", $entryname['name']);
		$file_type = totranslit(end($file_type));

		if( DLEFiles::FileExists('shared/'.$medium_path) ) $medium_data = "yes"; else $medium_data = "no";
			
		if( DLEFiles::FileExists('shared/'.$thumb_path) ) $thumb_data = "yes"; else $thumb_data = "no";
			
		$folder_list[] = <<<HTML
<div class="file-preview-card" data-type="image" data-area="shared" data-url="{$img_url}" data-thumb="{$thumb_data}" data-path="{$path}" data-medium="{$medium_data}">
	<div class="active-ribbon"><span><i class="mediaupload-icon mediaupload-icon-ok"></i></span></div>
	<div class="file-content">
		<div class="file-ext">{$file_type}</div>
		<img src="{$img_url}" class="file-preview-image">
	</div>
	<div class="file-footer">
		<div class="file-footer-caption">
			<div class="file-caption-info">{$base_name}</div>
		</div>
		<div class="file-footer-bottom">
			<div class="file-preview">
				<a href="{$img_url}" data-highslide="single" rel="tooltip" title="{$lang['up_im_expand']}" target="_blank"><i class="mediaupload-icon mediaupload-icon-zoom"></i></a>
				<a class="clipboard-copy-link" href="#" rel="tooltip" title="{$lang['up_im_copy']}"><i class="mediaupload-icon mediaupload-icon-copy"></i></a>	
			</div>
		</div>
	</div>
</div>
HTML;
	
	}
	
	
	$folder_list = implode('', $folder_list);
	
	$return_array = array (
		'success' => true,
		'response' => $folder_list
	);
	
	echo json_encode($return_array, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	die();
	
}

if( !$is_logged OR !$user_group[$member_id['user_group']]['allow_admin'] ) { die ("error"); }

$buffer = "";

function parseJsonArray($jsonArray, $parentID = 0) {
  $return = array();
  foreach ($jsonArray as $subArray) {
     $returnSubSubArray = array();
     if (isset($subArray['children'])) {
       $returnSubSubArray = parseJsonArray($subArray['children'], $subArray['id']);
     }
     $return[] = array('id' => $subArray['id'], 'parentid' => $parentID);
     $return = array_merge($return, $returnSubSubArray);
  }

  return $return;
}

if ($_REQUEST['action'] == "relatedids") {

	if (!$user_group[$member_id['user_group']]['admin_addnews']) die("error");

	if (!isset($_REQUEST['user_hash']) or !$_REQUEST['user_hash'] or $_REQUEST['user_hash'] != $dle_login_hash) {
		die("error");
	}

	$id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
	$_REQUEST['short_txt'] = isset($_REQUEST['short_txt']) ? $_REQUEST['short_txt'] : '';
	$_REQUEST['full_txt'] = isset($_REQUEST['full_txt']) ? $_REQUEST['full_txt'] : '';
	$category = isset($_POST['category']) ? $_POST['category'] : array();
	
	if (!is_array($category)) $category = array();

	if (!count($category)) $category[] = '0';

	$category_list = array();

	foreach ($category as $value) {
		$category_list[] = intval($value);
	}
	
	$category_list = implode(',', $category_list);

	if (strlen($_REQUEST['full_txt']) < strlen($_REQUEST['short_txt'])) $body = $_REQUEST['short_txt'];
	else $body = $_REQUEST['full_txt'];

	$buffer = get_related_ids($_REQUEST['title'] . " " . $body, $id, $category_list);

	if($buffer == '0') $buffer = '';

}

if ($_REQUEST['action'] == "newsspam") {

	if ( !$user_group[$member_id['user_group']]['allow_all_edit']) die ("error");

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}
	
	$id = intval( $_REQUEST['id'] );
	
	if( $id < 1 ) die( "error" );

	$row = $db->super_query( "SELECT id, autor, approve FROM " . PREFIX . "_post WHERE id = '{$id}'" );

	if ($row['id'])	{

		$author = $db->safesql($row['autor']);

		if( $row['approve'] ) die ("error");

		$row = $db->super_query( "SELECT user_id, user_group FROM " . USERPREFIX . "_users WHERE name = '{$author}'" );

		$user_id = intval($row['user_id']);

		if ($user_group[$row['user_group']]['allow_admin']) die ($lang['mark_spam_error']);

		$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '87', '{$author}')" );

		$result = $db->query( "SELECT id FROM " . PREFIX . "_post WHERE autor='{$author}' AND approve='0'" );
			
		while ( $row = $db->get_array( $result ) ) {
			deletenewsbyid( $row['id'] );
		}

		$db->free( $result );
		$db->query( "UPDATE " . USERPREFIX . "_users SET restricted='3', restricted_days='0' WHERE user_id ='{$user_id}'" );
		clear_cache( array('news_', 'tagscloud_', 'archives_', 'calendar_', 'topnews_', 'rss', 'stats') );
		$buffer = $lang['mark_spam_ok_2'];

	} else die ("error");

}

if ($_REQUEST['action'] == "timeout") {

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}
	
	set_cookie( "timeout_session", '1', 365 );
	$_SESSION['timeout_session'] = 1;
	
	die( "ok" );

}

if ($_REQUEST['action'] == "clearpoll") {

	if ( !$user_group[$member_id['user_group']]['allow_all_edit']) die ("error");

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}

	$id = intval( $_REQUEST['id'] );
	
	if( $id < 1 ) die( "error" );
	
	$db->query( "UPDATE  " . PREFIX . "_poll SET  votes='0', answer='' WHERE news_id = '{$id}'" );
	$db->query( "DELETE FROM " . PREFIX . "_poll_log WHERE news_id='{$id}'" );
	
	$buffer = $lang['clear_poll_2'];

}

if ($_REQUEST['action'] == "commentspublic") {

	if ( !$user_group[$member_id['user_group']]['admin_comments']) die ("error");

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}
	
	$c_id = intval( $_REQUEST['id'] );

	$row = $db->super_query("SELECT id, post_id, approve FROM " . PREFIX . "_comments WHERE id = '{$c_id}'");

	if (!isset($row['id']) OR $row['approve']) {
		die("error");
	}

	$post_id = $row['post_id'];

	$db->query( "UPDATE " . PREFIX . "_comments SET approve='1' WHERE id='{$c_id}'" );
	$db->query( "UPDATE " . PREFIX . "_post SET comm_num=comm_num+1 WHERE id='{$post_id}'" );

	$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '19', '')" );

	if ($config['allow_alt_url'] and !$config['seo_type']) $cprefix = "full_"; else $cprefix = "full_" . $post_id;

	clear_cache(array('news_', 'comm_' . $post_id, $cprefix, 'stats'));

	if ( $config['allow_subscribe'] ) {

		$row = $db->super_query( "SELECT autor, text, parent FROM " . PREFIX . "_comments WHERE id = '{$c_id}'" );

		$name = $row['autor'];
		$body = $row['text'];
		$parent = $row['parent'];

		$row = $db->super_query( "SELECT id, short_story, title, date, alt_name, category FROM ".PREFIX."_post WHERE id = '{$post_id}'" );

		$row['date'] = strtotime( $row['date'] );

		$full_link = DLEUrl::BuildUrl('showfull', ['category' => get_url($row['category']), 'year' => date('Y', $row['date']), 'month' => date('m', $row['date']), 'day' => date('d', $row['date']), 'news_name' => $row['alt_name'], 'newsid' => $row['id']]);
	
		$title = stripslashes($row['title']);
		
		$row = $db->super_query( "SELECT * FROM " . PREFIX . "_email WHERE name='comments' LIMIT 0,1" );
		$mail = new dle_mail( $config, $row['use_html'] );

		$row['template'] = stripslashes( $row['template'] );
		$row['template'] = str_replace( "{%username%}", $name, $row['template'] );
		$row['template'] = str_replace( "{%date%}", langdate( "j F Y H:i", $_TIME, true ), $row['template'] );
		$row['template'] = str_replace( "{%link%}", $full_link, $row['template'] );
		$row['template'] = str_replace( "{%title%}", $title, $row['template'] );

		$body = str_replace( '\n', "", $body );
		$body = str_replace( '\r', "", $body );
			
		$body = stripslashes( stripslashes( $body ) );
		$body = str_replace( "<br />", "\n", $body );
		$body = strip_tags( $body );
			
		if( $row['use_html'] ) {
			$body = str_replace("\n", "<br />", $body );
		}
					
		$row['template'] = str_replace( "{%text%}", $body, $row['template'] );
		$row['template'] = str_replace( "{%ip%}", "--", $row['template'] );
		
		$found_news_author_subscribe = false;
		$found_reply_author_subscribe = false;
		
		$news_author_subscribe = $db->super_query( "SELECT " . USERPREFIX . "_users.user_id, " . USERPREFIX . "_users.name, " . USERPREFIX . "_users.email, " . USERPREFIX . "_users.news_subscribe FROM " . PREFIX . "_post_extras LEFT JOIN " . USERPREFIX . "_users ON " . PREFIX . "_post_extras.user_id=" . USERPREFIX . "_users.user_id WHERE " . PREFIX . "_post_extras.news_id='{$post_id}'" );
		
		if( $parent ) {
			
			$reply_author_subscribe = $db->super_query( "SELECT " . USERPREFIX . "_users.user_id, " . USERPREFIX . "_users.name, " . USERPREFIX . "_users.email, " . USERPREFIX . "_users.comments_reply_subscribe FROM " . PREFIX . "_comments LEFT JOIN " . USERPREFIX . "_users ON " . PREFIX . "_comments.user_id=" . USERPREFIX . "_users.user_id WHERE " . PREFIX . "_comments.id='{$parent}'" );
			
		} else $reply_author_subscribe = array();

		if( !$parent ) {
			
			$db->query( "SELECT user_id, name, email, hash FROM " . PREFIX . "_subscribe WHERE news_id='{$post_id}'" );
	
			while($rec = $db->get_row())
			{
				if( $rec['user_id'] == $news_author_subscribe['user_id'] ) {
					$found_news_author_subscribe = true;
				}
					
				if( $parent AND $rec['user_id'] == $reply_author_subscribe['user_id'] ) {
					$found_reply_author_subscribe = true;
				}
					
				if ($rec['user_id'] != $member_id['user_id'] ) {
			
					$body = str_replace( "{%username_to%}", $rec['name'], $row['template'] );
					$body = str_replace( "{%unsubscribe%}", $config['http_home_url'] . "index.php?do=unsubscribe&post_id=" . $post_id . "&user_id=" . $rec['user_id'] . "&hash=" . $rec['hash'], $body );
					$mail->send( $rec['email'], $lang['mail_comments'], $body );
	
				}
	
			}
			
		}
		
		if($news_author_subscribe['news_subscribe'] AND !$found_news_author_subscribe) {
			
			$body = str_replace( "{%username_to%}", $news_author_subscribe['name'], $row['template'] );
			
			$body = str_replace( "{%unsubscribe%}", DLEUrl::BuildUrl('user', ['user' => urlencode($news_author_subscribe['name'])]), $body );
			
			$mail->send( $news_author_subscribe['email'], $lang['mail_comments'], $body );
			
			$last_send = $news_author_subscribe['user_id'];
			
		} else $last_send = false;
		
		if($parent AND $reply_author_subscribe['comments_reply_subscribe'] AND !$found_reply_author_subscribe AND $reply_author_subscribe['user_id'] != $last_send) {
			
			$body = str_replace( "{%username_to%}", $reply_author_subscribe['name'], $row['template'] );
			$body = str_replace( "{%unsubscribe%}", DLEUrl::BuildUrl('user', ['user' => urlencode($reply_author_subscribe['name'])]), $body );
			
			$mail->send( $reply_author_subscribe['email'], $lang['mail_comments'], $body );
		}

		$db->free();
	}
	die("ok");
}

if ($_REQUEST['action'] == "commentsspam") {

	if ( !$user_group[$member_id['user_group']]['del_allc']) die ("error");

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}
	
	$id = intval( $_REQUEST['id'] );
	
	if( $id < 1 ) die( "error" );

	$row = $db->super_query( "SELECT id, user_id, autor, email, ip, is_register FROM " . PREFIX . "_comments WHERE id = '{$id}'" );

	if ($row['id'])	{

		$user_id = intval($row['user_id']);
		$author = $db->safesql($row['autor']);
		$email = $db->safesql($row['email']);
		$is_register = $row['is_register'];
		$ip = $db->safesql($row['ip']);

		if ( $is_register ) {

			$row = $db->super_query( "SELECT user_group FROM " . USERPREFIX . "_users WHERE user_id = '{$user_id}'" );

			if ($user_group[$row['user_group']]['allow_admin']) die ($lang['mark_spam_error']);

			$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '87', '{$author}')" );

			$db->query( "UPDATE " . USERPREFIX . "_users SET comm_num='0', restricted='3', restricted_days='0' WHERE user_id ='{$user_id}'" );
			
			deletecommentsbyuserid($user_id);


		} else {

			$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '88', '{$author}')" );

			deletecommentsbyuserid(0, $ip);
			
			$banned_from = $db->safesql($member_id['name']);

			$db->query( "INSERT INTO " . USERPREFIX . "_banned (descr, date, days, ip, banned_from) values ('{$lang['mark_spam_ok_1']}', '0', '0', '{$ip}', '{$banned_from}')" );
			@unlink( ENGINE_DIR . '/cache/system/banned.json' );

		}
		
		clear_cache(array('news_', 'rss', 'stats', 'comm_', 'full_'));

		if ( $email AND strlen($config['spam_api_key']) > 3 ) {
		
			$sfs = new StopSpam($config['spam_api_key'], $config['sec_addnews']);
			$args = array('ip_addr' => $ip, 'username' => $author, 'email' => $email );
			$sfs->add( $args );
		
		}

		$buffer = $lang['mark_spam_ok'];		

	} else die ("error");
}

if ($_REQUEST['action'] == "clearcache") {

	if ( $member_id['user_group'] != 1 ) die ("error");
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}
	
	clear_all_caches();
	clear_static_cache_id();

	if (isSSL()) header('Clear-Site-Data: "cache"');
	$buffer = $lang['clear_cache'];

}


if ($_REQUEST['action'] == "clearsubscribe") {

	if ( $member_id['user_group'] != 1 ) die ("error");
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		die ("error");
	}

	$db->query("TRUNCATE TABLE " . PREFIX . "_subscribe");

	$buffer = $lang['clear_subscribe'];

}

if ($_REQUEST['action'] == "clearsubscribenews") {

	if ( $member_id['user_group'] != 1 ) die ("error");
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		die ("error");
	}
	
	$id = intval( $_REQUEST['id'] );
	
	if( $id < 1 ) die( "error" );
	
	$db->query( "DELETE FROM " . PREFIX . "_subscribe WHERE news_id='{$id}'" );

	$buffer = $lang['clear_subscribe'];

}

if ($_REQUEST['action'] == "sendnotice") {
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}
	
	$row = $db->super_query( "SELECT id FROM " . PREFIX . "_notice WHERE user_id = '{$member_id['user_id']}'" );
	
	$notice = $_POST['notice'];
	
	$notice = $db->safesql( $notice );
	
	if( dle_strlen( $notice ) > 65000 ) {
		die( "error" );
	}
	
	if( isset($row['id']) AND $row['id'] ) {
		
		$db->query( "UPDATE " . PREFIX . "_notice SET notice='{$notice}' WHERE user_id = '{$member_id['user_id']}'" );
	
	} else {
		
		$db->query( "INSERT INTO " . PREFIX . "_notice (user_id, notice) values ('{$member_id['user_id']}', '{$notice}')" );
	
	}

	$buffer = $lang['saved'];

}

if ($_REQUEST['action'] == "savelang") {
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		echo "{\"error\":\"error\"}";
		die ();
	
	}
	
	$_REQUEST['lang'] = totranslit( $_REQUEST['lang'], false, false );

	if ($_REQUEST['lang'] AND @is_dir ( ROOT_DIR . '/language/' . $_REQUEST['lang'] )) {
		
		set_cookie ( "selected_language", $_REQUEST['lang'], 365 );
		

	}
	
	echo "{\"ok\":\"ok\"}";
	die();
	
}

if ($_REQUEST['action'] == "savetheme") {
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}
	
	$file = md5(SECURE_AUTH_KEY.$member_id['user_id']);
	
	if( !is_dir( ENGINE_DIR . "/cache/system/adminpanel" ) ) {
			
		@mkdir( ENGINE_DIR . "/cache/system/adminpanel", 0777 );
		@chmod( ENGINE_DIR . "/cache/system/adminpanel", 0777 );

	}
	
	if( !is_dir( ENGINE_DIR . "/cache/system/adminpanel") ) {

		echo "{\"error\":\"{$lang['stat_cache']}\"}";
		die();
	}
	
	if( !is_writable( ENGINE_DIR . "/cache/system/adminpanel" ) ) {

		echo "{\"error\":\"{$lang['upload_error_1']} /engine/cache/system/adminpanel/ {$lang['upload_error_2']}\"}";
		die();
		
	}

	if( file_exists( ENGINE_DIR . "/cache/system/adminpanel/" . $file ) AND !is_writable( ENGINE_DIR . "/cache/system/adminpanel/" . $file ) ) {
		
		$lang['stat_system'] = str_replace ("{file}", "/engine/cache/system/adminpanel/" . $file, $lang['stat_system']);
		
		echo "{\"error\":\"{$lang['stat_system']}\"}";
		die();
		
	}
	
	$allowed_themes = array('dle_theme_a', 'dle_theme_b','dle_theme_c','dle_theme_d','dle_theme_e','dle_theme_f','dle_theme_g','dle_theme_h','dle_theme_i', 'dle_theme_j','dle_theme_dark','sidebar-xs','layout-boxed','input-classic','auto_dark_theme','style-smoothing','htmlfontsize-50','htmlfontsize-75', 'htmlfontsize-80', 'htmlfontsize-85', 'htmlfontsize-90', 'htmlfontsize-95', 'htmlfontsize-100', 'htmlfontsize-105', 'htmlfontsize-110', 'htmlfontsize-115', 'htmlfontsize-120', 'htmlfontsize-125', 'htmlfontsize-130', 'htmlfontsize-135', 'htmlfontsize-140', 'htmlfontsize-145', 'htmlfontsize-150','htmlfontsize-175', 'htmlfontsize-200', 'editor-style-light', 'editor-autoheight' );
	$theme = array();
	
	if( strpos ( $_REQUEST['theme'], "saved_dle_theme_" ) OR strpos ( $_REQUEST['theme'], "auto_night_mode" ) ) {
		$_REQUEST['theme'] = str_replace('saved_dle_theme_', 'dle_theme_', $_REQUEST['theme']);
		$_REQUEST['theme'] = str_replace('dle_theme_dark', '', $_REQUEST['theme']);
		$_REQUEST['theme'] = trim($_REQUEST['theme']);
	}
	
	$themes = explode(" ", $_REQUEST['theme']);
	
	foreach($themes as $temp) {
		if( trim($temp) AND in_array($temp, $allowed_themes) ) {
			$theme[] = trim($temp);
		}
	}
	
	if( count($theme) ) {
		
		$theme = implode(" ", $theme);
		
		file_put_contents (ENGINE_DIR . "/cache/system/adminpanel/" . $file, $theme, LOCK_EX);
		@chmod( ENGINE_DIR . "/cache/system/adminpanel/" . $file, 0666 );
	
	} else {
		
		@unlink( ENGINE_DIR . "/cache/system/adminpanel/" . $file );
		
	}
	
	echo "{\"ok\":\"ok\"}";
	die();
	
}

if ($_REQUEST['action'] == "deletemodules") {

	if ( $member_id['user_group'] != 1 ) die ("error");
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}
	
	$id = intval($_REQUEST['id']);

	if ( $id ) {
		$db->query( "DELETE FROM " . PREFIX . "_admin_sections WHERE id = '{$id}'" );
	
		die("ok");
	}

}

if ($_REQUEST['action'] == "catsort") {

	if( !$user_group[$member_id['user_group']]['admin_categories'] ) die ("error");
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}

	$_POST['list'] = json_decode(stripslashes($_POST['list']), true);

	if ( !is_array($_POST['list']) ) die ("error");
	
	$_POST['list'] = parseJsonArray($_POST['list']);
	
	$i= 0;

	foreach ( $_POST['list'] as $value ) {
		$i++;

		$id = intval($value['id']);
		$parentid = intval($value['parentid']);
		
		if ( $id ) {

			$db->query( "UPDATE " . PREFIX . "_category SET parentid='{$parentid}', posi='{$i}' WHERE id = '{$id}'" );

		}
	}

	clear_all_caches();
	$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '11', '')" );

	die("ok");

}

if ($_REQUEST['action'] == "catchangestatus") {

	if( !$user_group[$member_id['user_group']]['admin_categories'] ) die ("error");
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}

	$id = intval($_POST['id']);

	if( !$id OR $id < 1) {
		
		die ("error");
	
	}
	
	if( $_POST['status'] == 'off' ) {
		$db->query( "UPDATE " . PREFIX . "_category SET active='0' WHERE id = '{$id}'" );
		$logs = 120;
	} else {
		$db->query( "UPDATE " . PREFIX . "_category SET active='1' WHERE id = '{$id}'" );
		$logs = 121;
	}

	clear_all_caches();
	$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '{$logs}', '{$id}')" );

	die("ok");

}

if ($_REQUEST['action'] == "pluginsort") {

	if( $member_id['user_group'] != 1 ) die ("error");
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}

	$_POST['list'] = json_decode(stripslashes($_POST['list']), true);

	if ( !is_array($_POST['list']) ) die ("error");
	
	$i= 0;

	foreach ( $_POST['list'] as $value ) {
		$i++;

		$id = intval($value);
		
		if ( $id ) {

			$db->query( "UPDATE " . PREFIX . "_plugins SET posi='{$i}' WHERE id = '{$id}'" );

		}
	}

	clear_all_caches();
	$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '119', '')" );

	die("ok");

}

if ($_REQUEST['action'] == "storagesort") {

	if ($member_id['user_group'] != 1) die("error");

	if (!isset($_REQUEST['user_hash']) or !$_REQUEST['user_hash'] or $_REQUEST['user_hash'] != $dle_login_hash) {

		die("error");
	}

	$_POST['list'] = json_decode(stripslashes($_POST['list']), true);

	if (!is_array($_POST['list'])) die("error");

	$i = 0;

	foreach ($_POST['list'] as $value) {
		$i++;

		$id = intval($value);

		if ($id) {

			$db->query("UPDATE " . PREFIX . "_storage SET posi='{$i}' WHERE id = '{$id}'");
		}
	}

	clear_all_caches();
	$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '119', '')");

	die("ok");
}

if ($_REQUEST['action'] == "friendlyurlsort") {

	if ($member_id['user_group'] != 1) {
		echo "{\"error\":\"User Not Allowed\"}";
		die();
	}

	if (!isset($_REQUEST['user_hash']) or !$_REQUEST['user_hash'] or $_REQUEST['user_hash'] != $dle_login_hash) {

		echo "{\"error\":\"User Not Allowed\"}";
		die();
	}

	$_POST['list'] = json_decode(stripslashes($_POST['list']), true);

	if (!is_array($_POST['list'])){
		echo "{\"error\":\"URL Not Match\"}";
		die();
	}
	
	DLEUrl::Init();
	$sorted_rules = [];

	foreach ($_POST['list'] as $value) {
		$sorted_rules[$value]  = DLEUrl::$rules[$value];
	}

	if(count($sorted_rules) == count(DLEUrl::$rules)) {

		DLEUrl::SaveRules($sorted_rules);
		clear_cache();
		$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '140', '')");

		if( DLEUrl::CheckRoutes($sorted_rules) === null ) {
			echo "{\"ok\":\"ok\"}";
		} else {

			$return = array(
				'warning' => $lang['friendly_fl_20']
			);
			$return = json_encode($return, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			die($return);
		}

	} else {
		echo "{\"error\":\"URL Not Match\"}";
		die();
	}

}

if ($_REQUEST['action'] == "friendlyurladd") {
	if ($member_id['user_group'] != 1) {
		echo "{\"error\":\"User Not Allowed\"}";
		die();
	}

	if (!isset($_REQUEST['user_hash']) or !$_REQUEST['user_hash'] or $_REQUEST['user_hash'] != $dle_login_hash) {

		echo "{\"error\":\"User Not Allowed\"}";
		die();
	}

	$remove_symbols = ["'", '"'];
	
	$seo_key = totranslit($_POST['key']);
	$seo_url = trim(str_replace($remove_symbols, '', (string)strip_tags($_POST['seo_url'])));
	$real_url = trim(str_replace($remove_symbols, '', (string)strip_tags($_POST['real_url'])));

	if (!$seo_url or !$real_url) {
		echo "{\"error\":\"{$lang['friendly_fl_21']}\"}";
		die();
	}

	if ($seo_url[0] != '/') $seo_url = '/' . $seo_url;
	if ($real_url[0] != '/') $real_url = '/' . $real_url;

	if ($seo_key = DLEUrl::AddRule($seo_key, $seo_url, $real_url)) {
		clear_cache();
		$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '141', '{$seo_url}')");

		if (DLEUrl::CheckRoutes() === null) {

			$return = array(
				'ok' => 'ok',
				'seo_key'  => $seo_key,
				'seo_url'  => $seo_url,
				'real_url' => $real_url
			);

			$return = json_encode($return, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			die($return);
		} else {

			$return = array(
				'warning'  => 'warning',
				'seo_key'  => $seo_key,
				'seo_url'  => $seo_url,
				'real_url' => $real_url
			);

			$return = json_encode($return, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			die($return);
		}
	} else {
		echo "{\"error\":\"Rule Not Found\"}";
		die();
	}
}

if ($_REQUEST['action'] == "friendlyurledit") {
	if ($member_id['user_group'] != 1) {
		echo "{\"error\":\"User Not Allowed\"}";
		die();
	}

	if (!isset($_REQUEST['user_hash']) or !$_REQUEST['user_hash'] or $_REQUEST['user_hash'] != $dle_login_hash) {

		echo "{\"error\":\"User Not Allowed\"}";
		die();
	}
	
	$remove_symbols = ["'", '"'];

	$seo_url = trim(str_replace($remove_symbols, '', (string)strip_tags($_POST['seo_url'])));
	$real_url = trim(str_replace($remove_symbols, '', (string)strip_tags($_POST['real_url'])));

	if(!$seo_url OR !$real_url) {
		echo "{\"error\":\"{$lang['friendly_fl_21']}\"}";
		die();
	}

	if($seo_url[0] != '/') $seo_url = '/'. $seo_url;
	if($real_url[0] != '/') $real_url = '/'. $real_url;

	if( DLEUrl::EditRule($_POST['key'], $seo_url, $real_url) ) {
		clear_cache();
		$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '142', '{$seo_url}')");

		if (DLEUrl::CheckRoutes() === null) {
			
			$return = array(
				'ok' => 'ok',
				'seo_url' => $seo_url,
				'real_url' => $real_url
			);

			$return = json_encode($return, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			die($return);

		} else {

			$return = array(
				'warning' => 'warning',
				'seo_url' => $seo_url,
				'real_url' => $real_url
			);

			$return = json_encode($return, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			die($return);
		}

	} else {
		echo "{\"error\":\"Rule Not Found\"}";
		die();
	}

}

if ($_REQUEST['action'] == "friendlyurlreset") {

	if ($member_id['user_group'] != 1){
		echo "{\"error\":\"User Not Allowed\"}";
		die();
	}

	if (!isset($_REQUEST['user_hash']) or !$_REQUEST['user_hash'] or $_REQUEST['user_hash'] != $dle_login_hash) {

		echo "{\"error\":\"User Not Allowed\"}";
		die();
	}
	
	DLEUrl::SaveDefaultsRules();
	clear_cache();
	$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '143', '')");

	echo "{\"ok\":\"ok\"}";
	die();
}
if ($_REQUEST['action'] == "friendlyurlcheck") {

	if ($member_id['user_group'] != 1) {
		echo "{\"error\":\"User Not Allowed\"}";
		die();
	}

	if (!isset($_REQUEST['user_hash']) or !$_REQUEST['user_hash'] or $_REQUEST['user_hash'] != $dle_login_hash) {

		echo "{\"error\":\"User Not Allowed\"}";
		die();
	}

	if (DLEUrl::CheckRoutes() === null) {
		echo "{\"ok\":\"ok\"}";
	} else {
		$return = array(
			'error' => DLEUrl::$error
		);
		$return = json_encode($return, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		die($return);
	}

}
if ($_REQUEST['action'] == "friendlyurldel") {

	if ($member_id['user_group'] != 1) {
		echo "{\"error\":\"User Not Allowed\"}";
		die();
	}

	if (!isset($_REQUEST['user_hash']) or !$_REQUEST['user_hash'] or $_REQUEST['user_hash'] != $dle_login_hash) {

		echo "{\"error\":\"User Not Allowed\"}";
		die();
	}

	DLEUrl::DeleteRule($_POST['key']);
	clear_cache();

	$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '144', '" . $db->safesql(htmlspecialchars($_POST['key'])) . "')");
	echo "{\"ok\":\"ok\"}";
}

if ($_REQUEST['action'] == "friendlyurlrestore") {

	if ($member_id['user_group'] != 1) {
		echo "{\"error\":\"User Not Allowed\"}";
		die();
	}

	if (!isset($_REQUEST['user_hash']) or !$_REQUEST['user_hash'] or $_REQUEST['user_hash'] != $dle_login_hash) {

		echo "{\"error\":\"User Not Allowed\"}";
		die();
	}

	$default = DLEUrl::RestoreRule($_POST['key']);
	clear_cache();
	
	if( is_array($default) ) {
		$return = array(
			'ok' => 'ok',
			'seo_url' => $default[0],
			'real_url' => $default[1]
		);

		$return = json_encode($return, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		die($return);
	} else {
		$return = array(
			'error' => $lang['friendly_fl_26']
		);
		$return = json_encode($return, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		die($return);
	}
}

if ($_REQUEST['action'] == "xfrubricsort") {
	
	if( !$user_group[$member_id['user_group']]['admin_xfields'] ) die ("error");

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}
	$_POST['list'] = json_decode(stripslashes($_POST['list']), true);

	if (!is_array($_POST['list'])) die("error");

	DLEXFields::SortRubrics($_POST['list']);

	die("ok");
}

if ($_REQUEST['action'] == "xfsort") {

	if( !$user_group[$member_id['user_group']]['admin_xfields'] ) die ("error");

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}

	$_POST['list'] = json_decode(stripslashes($_POST['list']), true);

	if ( !is_array($_POST['list']) ) die ("error");

	DLEXFields::SortFields($_POST['list']);

	die("ok");

}

if ($_REQUEST['action'] == "userxfsort") {

	if( !$user_group[$member_id['user_group']]['admin_userfields'] ) die ("error");

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die ("error");
	
	}

	$_POST['list'] = json_decode(stripslashes($_POST['list']), true);

	if ( !is_array($_POST['list']) ) die ("error");

	DLEUserXFields::SortFields($_POST['list']);

	die("ok");
}

if ($_REQUEST['action'] == "disableconfig") {

	if ($member_id['user_group'] != 1) die("error");

	if (!isset($_REQUEST['user_hash']) or !$_REQUEST['user_hash'] or $_REQUEST['user_hash'] != $dle_login_hash) {

		die("error");
	}
	
	include(ENGINE_DIR . '/data/config.php');
	$disableconfig = $_POST['disableconfig'];
	$disablevalue = intval($_POST['disablevalue']);

	if( !isset($config[$disableconfig]) ) {
		die($lang['disable_config_2']);
	}

	if (is_writable(ENGINE_DIR . '/data/config.php')) {
		
		$config[$disableconfig] = $disablevalue;

		@file_put_contents(ENGINE_DIR . '/data/config.php', "<?php \n\n//System Configurations\n\n\$config = " . var_export($config, true) . ';', LOCK_EX);

		clear_cache();

		if (function_exists('opcache_reset')) {
			opcache_reset();
		}
		
	} else {

		echo str_replace("{file}", "engine/data/config.php", $lang['stat_system']);
		die();

	}


	die('ok');
}


echo $buffer;
