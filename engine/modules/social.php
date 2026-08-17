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
 File: social.php
-----------------------------------------------------
 Use: Authorization through social networks
=====================================================
*/

if( !defined('DATALIFEENGINE') ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

$action = $_GET['action'] ?? $_REQUEST['action'] ?? '';
$provider = $_GET['provider'] ?? $_REQUEST['provider'] ?? '';
$allow_auth = $config['allow_social'] && $config['allow_registration'];

if( !isset($social_config) ) {
	include_once(ENGINE_DIR . '/data/socialconfig.php');
}

if( !empty($_GET['code']) && !empty($_SESSION['state']) && !empty($_GET['state']) && $_SESSION['state'] === $_GET['state'] ) {
	$action = 'getuser';	
}

$social = new SocialAuth($social_config, $provider);

if($allow_auth && $social->provider) {

	switch ($action) {

		case 'auth':

			if (!empty($_SERVER['HTTP_REFERER'])) {
				$ref_host = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
				if ($ref_host === $_SERVER['HTTP_HOST']) {
					$_SESSION['auth-referrer'] = htmlspecialchars($_SERVER['HTTP_REFERER'], ENT_QUOTES, 'UTF-8');
				}
			}
			
			header("Location: " . $social->getAuthUrl());
			die();
			break;

		case 'getuser':

			$social_user = $social->getuser();

			if (is_array($social_user)) {
				$social_user['sid'] = $db->safesql($social_user['sid']);

				$row = $db->super_query("SELECT * FROM " . USERPREFIX . "_social_login WHERE sid='{$social_user['sid']}'");

				if (isset($row['id']) && $row['id']) {
					
					if ($is_logged && $member_id['user_id'] && ($member_id['user_group'] != 1 || ($member_id['user_group'] == 1 && $config['allow_admin_social']))) {

						if ($row['uid'] != $member_id['user_id']) {
							$old_member = $db->super_query("SELECT user_id FROM " . USERPREFIX . "_users WHERE user_id='{$row['uid']}'");

							if (!isset($old_member['user_id'])) {

								$db->query("DELETE FROM " . USERPREFIX . "_social_login WHERE sid='{$social_user['sid']}'");
								$social->AttachUser();
							}

							msgbox($lang['all_err_1'], $lang['reg_err_45']);
						}
					}

					if($row['uid']) {
						$_TIME = time();
						$_IP = get_ip();

						$member_id = $db->super_query("SELECT * FROM " . USERPREFIX . "_users WHERE user_id='{$row['uid']}'");

						if (isset($member_id['user_id']) && $member_id['user_id']) {
							
							if ($member_id['user_group'] == 1 && !$config['allow_admin_social']) {

								msgbox($lang['all_err_1'], $lang['reg_err_42']);
								
							} else {
								
								if ($row['wait']) {
									$db->query("DELETE FROM " . USERPREFIX . "_social_login WHERE sid='{$social_user['sid']}'");
									$social->RegisterUser();
								}

								$social->AuthUser( $member_id );
								$social->Redirect();

							}

						} else {

							$member_id = array();
							$is_logged = false;
							$db->query("DELETE FROM " . USERPREFIX . "_social_login WHERE sid='{$social_user['sid']}'");
							$social->RegisterUser();
						}

					} else {
						$db->query("DELETE FROM " . USERPREFIX . "_social_login WHERE sid='{$social_user['sid']}'");
						$social->RegisterUser();
					}

				} else {

					if ($is_logged && $member_id['user_id'] && ($member_id['user_group'] != 1 || ($member_id['user_group'] == 1 && $config['allow_admin_social']))) {
						if (!empty($_SESSION['social_attach']) && $_SESSION['social_attach'] === session_id()) {
							unset($_SESSION['social_attach']);
							$social->AttachUser();
						} else {
							page_not_found();
						}
					} elseif (!$is_logged) {
						$social->RegisterUser();
					} else {
						page_not_found();
					}

				}

			} else {
				page_not_found();
			}

			break;
		
		case 'confirm':
			$social->CheckUser();
			break;

		case 'approve':
			$id = intval($_GET['id']);

			$row = $db->super_query("SELECT * FROM " . USERPREFIX . "_social_login WHERE id='{$id}'");
			
			if (isset($row['id']) && isset($_GET['key']) && $row['id'] && $row['wait'] && $row['password'] === $_GET['key'] ) {
				$member_id = $db->super_query("SELECT * FROM " . USERPREFIX . "_users WHERE user_id='{$row['uid']}'");

				if(isset($member_id['user_id']) && $member_id['user_id']) {
					$db->query("UPDATE " . USERPREFIX . "_social_login SET wait='0' WHERE id='{$row['id']}'");
					$social->AuthUser($member_id);
					$is_logged = true;
					$href = substr($_SERVER['SCRIPT_NAME'], 0, (int)strrpos($_SERVER['SCRIPT_NAME'], '/') + 1) ?: '/';
					
					msgbox($lang['all_info'], $lang['auth_social_ok'] . " <a href=\"{$href}\">{$lang['auth_next']}</a>");

				} else {
					$member_id = array();
					$is_logged = false;
					$db->query("DELETE FROM " . USERPREFIX . "_social_login WHERE id='{$row['id']}'");
					page_not_found();
				}

			} else {

				if( isset($row['id']) && isset($_GET['key']) && $row['id'] && $row['wait'] ) {
					$db->query("DELETE FROM " . USERPREFIX . "_social_login WHERE id='{$id}'");
				}
				page_not_found();
			}

			break;
			
		default:
			page_not_found();
			break;
	}

} else {
	page_not_found();
}

function page_not_found() {
	global $config, $lang;
	
	header("HTTP/1.0 404 Not Found");

	if ($config['own_404'] and file_exists(ROOT_DIR . '/404.html')) {

		header("Content-type: text/html; charset=utf-8");
		echo file_get_contents(ROOT_DIR . '/404.html');
		die();

	} else msgbox($lang['all_err_1'], $lang['news_err_27']);
}