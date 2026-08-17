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
 File: download.php
-----------------------------------------------------
 Use: Files download
=====================================================
*/

if( !defined( 'DATALIFEENGINE' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../' );
	die( "Hacking attempt!" );
}

if( isset($_REQUEST['mode']) && $_REQUEST['mode'] == 'error') {

	$lang['download_error'] = str_replace('{count}', $user_group[$member_id['user_group']]['max_downloads'], $lang['download_error'] );

	header("HTTP/1.0 403 Forbidden");
	msgbox($lang['all_err_1'], $lang['download_error']);

} else {

	if ($config['allow_registration']) {
		include_once(DLEPlugins::Check(ENGINE_DIR . '/modules/sitelogin.php'));
	}

	if (!$is_logged) {
		$member_id['user_group'] = 5;
	}

	require_once(DLEPlugins::Check(ENGINE_DIR . '/classes/download.class.php'));

	$id = (int) ($_REQUEST['id'] ?? 0);
	$viewonline = !empty($_REQUEST['viewonline']);

	$perm = true;
	$onlineview_ext = array("pdf", "doc", "docx", "docm", "dotm", "dotx", "xlsx", "xlsb", "xls", "xlsm", "pptx", "ppsx", "ppt", "pps", "pptm", "potm", "ppam", "potx", "ppsm", "odt", "odx");
	$full_link = $config['http_home_url'];

	if (isset($_REQUEST['area']) && $_REQUEST['area'] == "static") {

		$row = $db->super_query("SELECT * FROM " . PREFIX . "_static_files WHERE id ='{$id}'");

		if(isset($row['static_id']) && $row['static_id']) {
			$row_news = $db->super_query("SELECT id, name FROM " . PREFIX . "_static WHERE id ='{$row['static_id']}'");

			if (isset($row_news['id']) && $row_news['id']) {

				$full_link = DLEUrl::BuildUrl('static', ['page' => $row_news['name']]);
			}
		}
		
	} else {

		$row = $db->super_query("SELECT * FROM " . PREFIX . "_files WHERE id ='{$id}'");

		if (isset($row['news_id']) && $row['news_id'] && !$viewonline) {

			$row_news = $db->super_query("SELECT id, autor, date, category, alt_name, approve, access FROM " . PREFIX . "_post LEFT JOIN " . PREFIX . "_post_extras ON (" . PREFIX . "_post.id=" . PREFIX . "_post_extras.news_id) WHERE id ='{$row['news_id']}'");

			if (isset($row_news['id']) && $row_news['id']) {

				$row_news['date'] = strtotime($row_news['date']);
				
				$full_link = DLEUrl::BuildUrl('showfull', ['category' => get_url($row_news['category']), 'year' => date('Y', $row_news['date']), 'month' => date('m', $row_news['date']), 'day' => date('d', $row_news['date']), 'news_name' => $row_news['alt_name'], 'newsid' => $row_news['id']]);

				$options = news_permission($row_news['access']);
				if (isset($options[$member_id['user_group']]) && $options[$member_id['user_group']] && $options[$member_id['user_group']] != 3) $perm = true;
				if (isset($options[$member_id['user_group']]) && $options[$member_id['user_group']] == 3) $perm = false;

				if ($config['no_date'] && !$config['news_future'] && !$user_group[$member_id['user_group']]['allow_all_edit']) {

					if ($row_news['date'] > $_TIME) {
						$perm = false;
					}
				}

				$cat_list = explode(',', $row_news['category']);

				if (count($cat_list)) {

					$allow_list = explode(',', $user_group[$member_id['user_group']]['allow_cats']);
					$not_allow_cats = explode(',', $user_group[$member_id['user_group']]['not_allow_cats']);

					foreach ($cat_list as $element) {

						if ($allow_list[0] != "all" && !in_array($element, $allow_list)) $perm = false;

						if ($not_allow_cats[0] != "" && in_array($element, $not_allow_cats)) $perm = false;
					}
				}

				if (!$row_news['approve'] && $member_id['name'] != $row_news['autor'] && !$user_group[$member_id['user_group']]['allow_all_edit']) $perm = false;
			}

		}
		
	}

	if (!$perm) {
		header("HTTP/1.1 403 Forbidden");
		die("You don't have access to download this file");
	}

	if (!isset($row['name']) OR !isset($row['onserver']) OR !$row['name'] OR !$row['onserver']) {
		header("HTTP/1.1 404 Not Found");
		die("File not found");
	}

	$file_name = pathinfo($row['onserver']);

	if ($viewonline && in_array($file_name['extension'], $onlineview_ext)) {

		$config['files_antileech'] = false;
		$user_group[$member_id['user_group']]['allow_files'] = true;

	} else $viewonline = false;

	if (!$user_group[$member_id['user_group']]['allow_files']) {
		header("HTTP/1.1 403 Forbidden");
		die("You don't have access to download this file");
	}
	
	if ($config['files_antileech']) {
		foreach (['HTTP_REFERER', 'HTTP_HOST'] as $key) {
			$url = str_replace('\\', '/', (string)($_SERVER[$key] ?? ''));
			if ($url === '') {
				continue;
			}

			$normalizedUrl = str_contains($url, '://') ? $url : '//' . $url;
			$host = parse_url($normalizedUrl, PHP_URL_HOST);

			$_SERVER[$key] = str_ireplace('www.', '', (string)$host);
		}

		if ($_SERVER['HTTP_HOST'] !== $_SERVER['HTTP_REFERER']) {
			header("Location: {$full_link}", true, 302);
			die("You don't have access to download this file");
		}
	}

	if ($row['is_public']) $uploaded_path = 'public_files/';
	else $uploaded_path = 'files/';

	$file = new download($uploaded_path . $row['onserver'], $row['name'], $row['driver']);

	if ($user_group[$member_id['user_group']]['max_downloads'] && !$viewonline) {

		$today_time = strtotime('today midnight');

		if( $today_time ) {
			$db->query("DELETE FROM " . USERPREFIX . "_downloads_log WHERE date < '{$today_time}'");
		}

		$_IP = $db->safesql($_IP);

		if ($is_logged && $member_id['user_id']) {
			$where = "user_id ='{$member_id['user_id']}'";
		} else {
			$where = "ip ='{$_IP}'";
		}

		$down_log = $db->super_query("SELECT id FROM " . USERPREFIX . "_downloads_log WHERE file_id ='{$id}' AND {$where}");

		if (isset($down_log['id']) && $down_log['id']) $downloaded = true;
		else $downloaded = false;

		if (!$downloaded) {

			$down_log = $db->super_query("SELECT count(*) as count FROM " . USERPREFIX . "_downloads_log WHERE {$where}");

			if ($down_log['count'] >= $user_group[$member_id['user_group']]['max_downloads']) {

				header("HTTP/1.0 302 Found");
				header("Location: ?do=download&mode=error");
				die("Redirect");
			}

			if ($is_logged && $member_id['user_id']) {
				$db->query("INSERT INTO " . USERPREFIX . "_downloads_log (user_id, file_id, date) VALUES('{$member_id['user_id']}', '{$id}', '{$_TIME}')");
			} else {
				$db->query("INSERT INTO " . USERPREFIX . "_downloads_log (ip, file_id, date) VALUES('{$_IP}', '{$id}', '{$_TIME}')");
			}

		}
	}

	if (isset($_REQUEST['area']) && $_REQUEST['area'] == "static") {

		if ($config['files_count'] && !$file->is_range_request) {
			$db->query("UPDATE " . PREFIX . "_static_files SET dcount=dcount+1 WHERE id ='$id'");
		}

	} else {

		if ($config['files_count'] && !$file->is_range_request) {
			$db->query("UPDATE " . PREFIX . "_files SET dcount=dcount+1 WHERE id ='$id'");
		}
	}

	$db->close();
	session_write_close();

	$file->download_file( $viewonline );

	die();

}