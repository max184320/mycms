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
 File: profile.php
-----------------------------------------------------
 Use: User profile
=====================================================
*/

if (!defined('DATALIFEENGINE')) {
	header("HTTP/1.1 403 Forbidden");
	header('Location: ../../');
	die("Hacking attempt!");
}

if (!isset($_REQUEST['user_hash']) or !$_REQUEST['user_hash'] or $_REQUEST['user_hash'] != $dle_login_hash) {
	
	echo json_encode(array("error" => $lang['sess_error']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	die();
}


$tpl = new dle_template();
$tpl->dir = ROOT_DIR . '/templates/' . $config['skin'];
define('TEMPLATE_DIR', $tpl->dir);
$pm_allowed = true;

if (isset($_GET['name'])) $name = @$db->safesql(strip_tags(urldecode($_GET['name'])));
else $name = '';

if (!$name) die("Hacking attempt!");

if (preg_match("/[\||\'|\<|\>|\"|\!|\?|\$|\@|\/|\\\|\&\~\*\+]/", $name)) die("Not allowed user name!");

$row = $db->super_query("SELECT * FROM " . USERPREFIX . "_users WHERE name = '{$name}'");

if (!isset($row['user_id']) OR !$row['user_id']) {
	
	echo json_encode(array("warning" => $lang['news_err_26']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	die();

} else {

	if($member_id['user_group'] == 5 OR !$user_group[$member_id['user_group']]['allow_pm'] OR !$user_group[$row['user_group']]['allow_pm'] OR $row['banned'] ) {
		$pm_allowed = false;
	}
	
	$tpl->load_template('profile_popup.tpl');
	
	if (($is_logged AND $user_group[$member_id['user_group']]['admin_editusers']) OR ($is_logged AND $member_id['user_id'] == $row['user_id'])) {
		$is_own = true;
	} else $is_own = false;

	$row['xfields'] = stripslashes($row['xfields']);
	DLEUserXFields::Compile($row, $tpl, $is_own);

	if ($row['foto'] and count(explode("@", $row['foto'])) == 2) {

		$tpl->set('{foto}', 'https://www.gravatar.com/avatar/' . md5(trim($row['foto'])) . '?s=' . intval($user_group[$row['user_group']]['max_foto']));
	} else {

		if ($row['foto']) {

			if (strpos($row['foto'], "//") === 0) $avatar = "http:" . $row['foto'];
			else $avatar = $row['foto'];

			$avatar = @parse_url($avatar);

			if (isset($avatar['host']) AND $avatar['host']) {
				$tpl->set('{foto}', $row['foto']);
			} else $tpl->set('{foto}', $_ROOT_DLE_URL . "uploads/fotos/" . $row['foto']);
			
		} else $tpl->set('{foto}', "{THEME}/dleimages/noavatar.png");
	}

	if (stripos($tpl->copy_template, "[profile-user-group=") !== false) {
		$tpl->copy_template = preg_replace_callback(
			'#\\[profile-user-group=(.+?)\\](.*?)\\[/profile-user-group\\]#is',
			function ($matches) {
				global $row;

				$groups = $matches[1];
				$block = $matches[2];

				$groups = explode(',', $groups);

				if (!in_array($row['user_group'], $groups)) return "";

				return $block;
			},
			$tpl->copy_template
		);
	}

	if (stripos($tpl->copy_template, "[not-profile-user-group=") !== false) {
		$tpl->copy_template = preg_replace_callback(
			'#\\[not-profile-user-group=(.+?)\\](.*?)\\[/not-profile-user-group\\]#is',
			function ($matches) {
				global $row;

				$groups = $matches[1];
				$block = $matches[2];

				$groups = explode(',', $groups);

				if (in_array($row['user_group'], $groups)) return "";

				return $block;
			},
			$tpl->copy_template
		);
	}

	if ($row['banned'] == 'yes' and $banned_info['users_id'][$row['user_id']]['date'] and $banned_info['users_id'][$row['user_id']]['date'] < $_TIME) $row['banned'] = '';

	if ($row['banned'] == 'yes') {

		$user_group[$row['user_group']]['group_name'] = $lang['user_ban'];
		$tpl->set('[banned]', "");
		$tpl->set('[/banned]', "");
		$tpl->set('{ban-description}', $banned_info['users_id'][$row['user_id']]['descr']);

		if ($banned_info['users_id'][$row['user_id']]['date']) {

			$endban = langdate("j F Y H:i", $banned_info['users_id'][$row['user_id']]['date'], true);
		} else $endban = $lang['banned_info'];

		$tpl->set('{ban-date}', $endban);

		$tpl->set_block("'\\[not-banned\\](.*?)\\[/not-banned\\]'si", "");
	} else {

		$tpl->set('[not-banned]', "");
		$tpl->set('[/not-banned]', "");
		$tpl->set('{ban-description}', '');
		$tpl->set('{ban-date}', '');
		$tpl->set_block("'\\[banned\\](.*?)\\[/banned\\]'si", "");
	}

	$tpl->set('{status}',  $user_group[$row['user_group']]['group_prefix'] . $user_group[$row['user_group']]['group_name'] . $user_group[$row['user_group']]['group_suffix']);

	if ($row['lastdate']) {

		if ($is_logged and $member_id['user_id'] == $row['user_id']) {
			$row['lastdate'] = $_TIME;
		}

		$tpl->set('{lastdate}', difflangdate("j F Y, H:i", $row['lastdate']));

		$news_date = $row['lastdate'];
		$tpl->copy_template = preg_replace_callback("#\{lastdate=(.+?)\}#i", "formdate", $tpl->copy_template);
	} else {

		$tpl->set('{lastdate}', '--');
	}

	if ($row['reg_date']) {

		$tpl->set('{registration}', difflangdate("j F Y, H:i", $row['reg_date']));

		$news_date = $row['reg_date'];
		$tpl->copy_template = preg_replace_callback("#\{registration=(.+?)\}#i", "formdate", $tpl->copy_template);
		
	} else $tpl->set('{registration}', '--');

	if (($row['lastdate'] + 1200) > $_TIME AND !$row['banned']) {

		$tpl->set('[online]', "");
		$tpl->set('[/online]', "");
		$tpl->set_block("'\\[offline\\](.*?)\\[/offline\\]'si", "");
	} else {
		$tpl->set('[offline]', "");
		$tpl->set('[/offline]', "");
		$tpl->set_block("'\\[online\\](.*?)\\[/online\\]'si", "");
	}

	$tpl->set('{usertitle}', $row['name']);

	if ($row['fullname']) {
		$tpl->set('[fullname]', "");
		$tpl->set('[/fullname]', "");
		$tpl->set('{fullname}', stripslashes($row['fullname']));
		$tpl->set_block("'\\[not-fullname\\](.*?)\\[/not-fullname\\]'si", "");
	} else {
		$tpl->set_block("'\\[fullname\\](.*?)\\[/fullname\\]'si", "");
		$tpl->set('{fullname}', "");
		$tpl->set('[not-fullname]', "");
		$tpl->set('[/not-fullname]', "");
	}

	if ($row['land']) {
		$tpl->set('[land]', "");
		$tpl->set('[/land]', "");
		$tpl->set('{land}', stripslashes($row['land']));
		$tpl->set_block("'\\[not-land\\](.*?)\\[/not-land\\]'si", "");
	} else {
		$tpl->set_block("'\\[land\\](.*?)\\[/land\\]'si", "");
		$tpl->set('{land}', "");
		$tpl->set('[not-land]', "");
		$tpl->set('[/not-land]', "");
	}

	if ($is_logged and $member_id['user_id'] == $row['user_id']) {
		$tpl->set('[own-profile]', "");
		$tpl->set('[/own-profile]', "");
		$tpl->set_block("'\\[not-own-profile\\](.*?)\\[/not-own-profile\\]'si", "");
	} else {
		$tpl->set('[not-own-profile]', "");
		$tpl->set('[/not-own-profile]', "");
		$tpl->set_block("'\\[own-profile\\](.*?)\\[/own-profile\\]'si", "");
	}

	if ($row['info']) {
		$tpl->set('[info]', "");
		$tpl->set('[/info]', "");
		$tpl->set('{info}', stripslashes($row['info']));
		$tpl->set_block("'\\[not-info\\](.*?)\\[/not-info\\]'si", "");
	} else {
		$tpl->set_block("'\\[info\\](.*?)\\[/info\\]'si", "");
		$tpl->set('{info}', "");
		$tpl->set('[not-info]', "");
		$tpl->set('[/not-info]', "");
	}

	if ($config['rating_type'] == "1") {
		$tpl->set('[rating-type-2]', "");
		$tpl->set('[/rating-type-2]', "");
		$tpl->set_block("'\\[rating-type-1\\](.*?)\\[/rating-type-1\\]'si", "");
		$tpl->set_block("'\\[rating-type-3\\](.*?)\\[/rating-type-3\\]'si", "");
		$tpl->set_block("'\\[rating-type-4\\](.*?)\\[/rating-type-4\\]'si", "");
	} elseif ($config['rating_type'] == "2") {
		$tpl->set('[rating-type-3]', "");
		$tpl->set('[/rating-type-3]', "");
		$tpl->set_block("'\\[rating-type-1\\](.*?)\\[/rating-type-1\\]'si", "");
		$tpl->set_block("'\\[rating-type-2\\](.*?)\\[/rating-type-2\\]'si", "");
		$tpl->set_block("'\\[rating-type-4\\](.*?)\\[/rating-type-4\\]'si", "");
	} elseif ($config['rating_type'] == "3") {
		$tpl->set('[rating-type-4]', "");
		$tpl->set('[/rating-type-4]', "");
		$tpl->set_block("'\\[rating-type-1\\](.*?)\\[/rating-type-1\\]'si", "");
		$tpl->set_block("'\\[rating-type-2\\](.*?)\\[/rating-type-2\\]'si", "");
		$tpl->set_block("'\\[rating-type-3\\](.*?)\\[/rating-type-3\\]'si", "");
	} else {
		$tpl->set('[rating-type-1]', "");
		$tpl->set('[/rating-type-1]', "");
		$tpl->set_block("'\\[rating-type-4\\](.*?)\\[/rating-type-4\\]'si", "");
		$tpl->set_block("'\\[rating-type-3\\](.*?)\\[/rating-type-3\\]'si", "");
		$tpl->set_block("'\\[rating-type-2\\](.*?)\\[/rating-type-2\\]'si", "");
	}

	if ($config['comments_rating_type'] == "1") {
		$tpl->set('[comments-rating-type-2]', "");
		$tpl->set('[/comments-rating-type-2]', "");
		$tpl->set_block("'\\[comments-rating-type-1\\](.*?)\\[/comments-rating-type-1\\]'si", "");
		$tpl->set_block("'\\[comments-rating-type-3\\](.*?)\\[/comments-rating-type-3\\]'si", "");
		$tpl->set_block("'\\[comments-rating-type-4\\](.*?)\\[/comments-rating-type-4\\]'si", "");
	} elseif ($config['comments_rating_type'] == "2") {
		$tpl->set('[comments-rating-type-3]', "");
		$tpl->set('[/comments-rating-type-3]', "");
		$tpl->set_block("'\\[comments-rating-type-1\\](.*?)\\[/comments-rating-type-1\\]'si", "");
		$tpl->set_block("'\\[comments-rating-type-2\\](.*?)\\[/comments-rating-type-2\\]'si", "");
		$tpl->set_block("'\\[comments-rating-type-4\\](.*?)\\[/comments-rating-type-4\\]'si", "");
	} elseif ($config['comments_rating_type'] == "3") {
		$tpl->set('[comments-rating-type-4]', "");
		$tpl->set('[/comments-rating-type-4]', "");
		$tpl->set_block("'\\[comments-rating-type-1\\](.*?)\\[/comments-rating-type-1\\]'si", "");
		$tpl->set_block("'\\[comments-rating-type-2\\](.*?)\\[/comments-rating-type-2\\]'si", "");
		$tpl->set_block("'\\[comments-rating-type-3\\](.*?)\\[/comments-rating-type-3\\]'si", "");
	} else {
		$tpl->set('[comments-rating-type-1]', "");
		$tpl->set('[/comments-rating-type-1]', "");
		$tpl->set_block("'\\[comments-rating-type-4\\](.*?)\\[/comments-rating-type-4\\]'si", "");
		$tpl->set_block("'\\[comments-rating-type-3\\](.*?)\\[/comments-rating-type-3\\]'si", "");
		$tpl->set_block("'\\[comments-rating-type-2\\](.*?)\\[/comments-rating-type-2\\]'si", "");
	}

	$tpl->set('{user-id}', $row['user_id']);

	if (stripos($tpl->copy_template, "{rate}") !== false or stripos($tpl->copy_template, "{ratingscore}") !== false) {
		$tpl->set('{rate}', userrating($row['user_id']));
		$tpl->set('{ratingscore}', $global_news_user_ratingscore);
	}

	if (stripos($tpl->copy_template, "{commentsrate}") !== false or stripos($tpl->copy_template, "{commentsratingscore}") !== false) {
		$tpl->set('{commentsrate}', commentsuserrating($row['user_id']));
		$tpl->set('{commentsratingscore}', $global_comments_user_ratingscore);
	}

	if ($row['signature'] and $user_group[$row['user_group']]['allow_signature']) {

		$tpl->set_block("'\\[signature\\](.*?)\\[/signature\\]'si", "\\1");
		$tpl->set('{signature}', stripslashes($row['signature']));
	} else {

		$tpl->set_block("'\\[signature\\](.*?)\\[/signature\\]'si", "");
	}

	if ($user_group[$row['user_group']]['icon']) $tpl->set('{group-icon}', "<img src=\"" . $user_group[$row['user_group']]['icon'] . "\" border=\"0\" />");
	else $tpl->set('{group-icon}', "");

	if ($row['news_num']) {

		$tpl->set('{news}', "<a href=\"" . DLEUrl::ClearDomain( DLEUrl::BuildUrl('user.news', ['user' => urlencode($row['name'])])) . "\">" . $lang['all_user_news'] . "</a>");
		$tpl->set('[rss]', "<a href=\"" . DLEUrl::ClearDomain( DLEUrl::BuildUrl('user.rss', ['user' => urlencode($row['name'])])) . "\" title=\"" . $lang['rss_user'] . "\">");
		$tpl->set('[/rss]', "</a>");

		$tpl->set('{news-num}', number_format($row['news_num'], 0, ',', ' '));
		$tpl->set('[news-num]', "");
		$tpl->set('[/news-num]', "");
		$tpl->set_block("'\\[not-news-num\\](.*?)\\[/not-news-num\\]'si", "");
	} else {

		$tpl->set('{news}', $lang['all_user_news']);
		$tpl->set_block("'\\[rss\\](.*?)\\[/rss\\]'si", "");
		$tpl->set('{news-num}', 0);
		$tpl->set_block("'\\[news-num\\](.*?)\\[/news-num\\]'si", "");
		$tpl->set('[not-news-num]', "");
		$tpl->set('[/not-news-num]', "");
	}

	if ($row['comm_num']) {

		$tpl->set('{comments}', "<a href=\"{$_SERVER['SCRIPT_NAME']}?do=lastcomments&amp;userid=" . $row['user_id'] . "\">" . $lang['last_comm'] . "</a>");

		$tpl->set('[comm-num]', "");
		$tpl->set('[/comm-num]', "");
		$tpl->set('{comm-num}', number_format($row['comm_num'], 0, ',', ' '));
		$tpl->set_block("'\\[not-comm-num\\](.*?)\\[/not-comm-num\\]'si", "");
	} else {

		$tpl->set('{comments}', $lang['last_comm']);
		$tpl->set('{comm-num}', 0);
		$tpl->set_block("'\\[comm-num\\](.*?)\\[/comm-num\\]'si", "");
		$tpl->set('[not-comm-num]', "");
		$tpl->set('[/not-comm-num]', "");
	}

	if ($is_logged AND $member_id['user_id'] != $row['user_id'] AND !$user_group[$row['user_group']]['admin_editusers']) {

		$tpl->set('[ignore]', "<a href=\"javascript:AddIgnorePM('" . $row['user_id'] . "', '" . $lang['add_to_ignore'] . "')\">");
		$tpl->set('[/ignore]', "</a>");
	} else {

		$tpl->set_block("'\\[ignore\\](.*?)\\[/ignore\\]'si", "");
	}

	$tpl->compile('content', true, false);

	$tpl->result['content'] = str_replace('{THEME}', $_ROOT_DLE_URL . 'templates/' . $config['skin'], $tpl->result['content']);
	
	echo json_encode(array("success" => true, "pm_allowed" => $pm_allowed, "content" => "<div id='dleprofilepopup' title='{$lang['p_user']} {$row['name']}' style='display:none'>{$tpl->result['content']}</div>"), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
