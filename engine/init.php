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
 File: init.php
-----------------------------------------------------
 Use: Initialization
=====================================================
*/

if( !defined( 'DATALIFEENGINE' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../' );
	die( "Hacking attempt!" );
}

include_once (DLEPlugins::Check(ENGINE_DIR . '/modules/functions.php'));

if($config['allow_alt_url']) {
	DLEUrl::Route();
}

dle_session();
check_xss();

if( $config['date_adjust'] ) {
	date_default_timezone_set ( $config['date_adjust'] );
}

$Timer = new microTimer();
$cron = false;
$_TIME = time();
$ajax = "";
$allow_comments_ajax = false;
$_DOCUMENT_DATE = false;
$_CLOUDSTAG = false;
$user_query = "";
$static_result = array ();
$is_logged = false;
$member_id = array ();
$related_buffer = false;
$banners = array ();
$banner_in_news = array ();
$xfields_in_news = array ();
$js_array = array ();
$css_array = array ();
$replace_links = array ();
$custom_news = false;
$dle_tree_comments = 0;
$attachments = array ();
$view_template = false;
$short_news_cache = false;
$onload_scripts = array();
$remove_canonical = false;
$smartphone_detected = false;
$vk_url = false;
$odnoklassniki_url = false;
$facebook_url = false;
$google_url = false;
$mailru_url = false;
$yandex_url = false;
$need_404 = false;
$xfieldsdata = "";
$xfields = array();
$custom_navigation = false;
$custom_blocks_names = array();
$custom_comments_blocks_names = array();
$news_found = false;
$showed_news_ids = array();

$metatags = array ( 'title' => $config['home_title'], 'description' => $config['description'], 'keywords' => $config['keywords'], 'header_title' => "" );
$config['charset'] = isset($config['charset']) ? strtolower(trim($config['charset'])) : 'utf-8';

$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = getSafeScriptName();

if ( $config['allow_cache'] AND $config['cache_type'] ) {
	if( $config['cache_type'] == "2" ) {		
		include_once (DLEPlugins::Check(ENGINE_DIR . '/classes/redis.class.php'));		
	} else {		
		include_once (DLEPlugins::Check(ENGINE_DIR . '/classes/memcache.class.php'));		
	}
	$dlefastcache = new dle_fastcache($config);
}

if ( !$config['http_home_url'] ) {
	$config['http_home_url'] = (isSSL() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . substr($_SERVER['SCRIPT_NAME'], 0, (int)strrpos($_SERVER['SCRIPT_NAME'], '/') + 1) ?: '/';
}

if( isSSL() AND stripos( $config['http_home_url'], 'http://' ) !== false ) {
	$config['http_home_url'] = str_replace( "http://", "https://", $config['http_home_url'] );
}

$config['http_home_url'] = rtrim($config['http_home_url'], '/\\') . '/';

if (isset ( $_GET['year'] )) {

	$year = min(2200, max(1970, intval($_GET['year'])));

} else $year = '';

if (isset ( $_GET['month'] )) {
	
	$month = min(12, max(1, intval($_GET['month'])));
	
	$month = sprintf("%02d", $month );
	
} else $month = '';

if (isset ( $_GET['day'] )) {
	$day = min(31, max(1, intval($_GET['day'])));
	
	$day = sprintf("%02d", $day );
	
} else $day = '';

if (isset ( $_GET['catalog'] )) {

	$catalog = strip_tags ( str_replace ( '/', '', urldecode ( (string)$_GET['catalog'] ) ) );
	$catalog = $db->safesql ( dle_substr ( trim($catalog), 0, 3 ) );

} else $catalog = '';

if (isset ( $_GET['user'] )) {

	$user = strip_tags ( str_replace ( '/', '', urldecode ( (string)$_GET['user'] ) ) );
	$user = $db->safesql ( $user );

	if( preg_match( "/[\||\'|\<|\>|\"|\!|\?|\$|\@|\#|\/|\\\|\&\~\*\+]/", $user ) ) $user = '';

} else $user = '';

$news_name  = isset($_GET['news_name']) ? $db->safesql(strip_tags(str_replace('/', '', (string)$_GET['news_name']))) : '';
$newsid     = intval($_GET['newsid'] ?? 0);
$news_page  = intval($_GET['news_page'] ?? 0);
$cstart = min(9999999, max(0, intval($_GET['cstart'] ?? 0)));

match ($_REQUEST['action'] ?? '') {
	'mobiledisable' => [$_SESSION['mobile_disable'] = 1, $_SESSION['mobile_enable'] = 0],
	'mobile'        => [$_SESSION['mobile_enable'] = 1, $_SESSION['mobile_disable'] = 0],
	default         => null
};

$_SESSION['mobile_disable'] ??= 0;
$_SESSION['mobile_enable']  ??= 0;

$do        = totranslit(isset($do) ? $do : ($_REQUEST['do'] ?? ''));
$subaction = totranslit(isset($subaction) ? $subaction : ($_REQUEST['subaction'] ?? ''));
$doaction  = totranslit($_REQUEST['doaction'] ?? '');

if ($do == "tags" && !($_GET['tag'] ?? '')) $do = "alltags";

$dle_module = $do;

if (!$dle_module && !$subaction && $year) $dle_module = "date";
elseif (!$dle_module && isset($_GET['catalog'])) $dle_module = "catalog";
elseif (!$dle_module) $dle_module = $subaction;
if (!$dle_module && ($newsid || $news_name)) $dle_module = "showfull";

$dle_module = $dle_module ?: "main";

if ($config['start_site'] == 3 && $dle_module == "main" && ($_GET['mod'] ?? '') != "rss") {
	$_GET['do'] = "static";
	$_REQUEST['do'] = "static";
	$_GET['page'] = "main";
	$_REQUEST['page'] = "main";
	$do = "static";
}

//################# Definition of user groups
$user_group = get_vars ( "usergroup" );

if (!is_array( $user_group )) {
	$user_group = array ();

	$db->query ( "SELECT * FROM " . USERPREFIX . "_usergroups ORDER BY id ASC" );

	while ( $row = $db->get_row () ) {

		$user_group[$row['id']] = array ();

		foreach ( $row as $key => $value ) {
			$user_group[$row['id']][$key] = stripslashes($value);
		}

	}
	set_vars ( "usergroup", $user_group );
	$db->free ();
}

//####################################################################################################################
//     Definition of categories
//####################################################################################################################
$cat_info = get_vars ( "category" );

if (!is_array ( $cat_info )) {
	$cat_info = array ();

	$db->query ( "SELECT * FROM " . PREFIX . "_category ORDER BY posi ASC" );
	
	while ( $row = $db->get_row () ) {

		if( !$row['active'] ) continue;
	
		$cat_info[$row['id']] = array ();

		foreach ( $row as $key => $value ) {
			$cat_info[$row['id']][$key] = stripslashes ( $value );
		}
		
		$cat_info[$row['id']]['newscount'] = 0;

	}

	set_vars ( "category", $cat_info );
	$db->free ();
}

$category_skin = "";

if ( isset($_GET['category']) AND $_GET['category'] ) {
	$_GET['category'] = (string)$_GET['category'];
	if (substr($_GET['category'], -1, 1) == '/') $_GET['category'] = substr($_GET['category'], 0, -1);

	$category = $db->safesql(strip_tags($_GET['category']));
	$category_id = get_ID($_GET['category']);

	if ($category_id) $category_skin = $cat_info[$category_id]['skin'];
	
} else {
	$category = '';
	$category_id = false;
}


$config['speedbar_separator'] = htmlspecialchars_decode( $config['speedbar_separator'], ENT_QUOTES);
$config['category_separator'] = htmlspecialchars_decode( $config['category_separator'], ENT_QUOTES);
$config['tags_separator'] = htmlspecialchars_decode( $config['tags_separator'], ENT_QUOTES);

if( $do == "download" ) {

	if( !isset($_REQUEST['mode']) OR $_REQUEST['mode'] != 'error') {
		include_once(DLEPlugins::Check(ENGINE_DIR . '/download.php'));
		die();
	}

} elseif($do == "go") {
	include_once (DLEPlugins::Check(ENGINE_DIR . '/go.php'));
	die();
} elseif(isset($_GET['mod']) AND $_GET['mod'] == "rss") {
	include_once (DLEPlugins::Check(ENGINE_DIR . '/rss.php'));
	die();
}

if( $config['allow_redirects'] ) {
	
	$redirects = get_vars( "redirects" );
	
	if( !is_array( $redirects ) ) {
		$redirects = array ();

		$db->query( "SELECT * FROM " . PREFIX . "_redirects WHERE enabled=1 ORDER BY id DESC" );
		
		while ( $row = $db->get_row() ) {
			
			if( strpos ( $row['from'], "*" ) !== false ) {
				
				$row['from'] = preg_quote(urldecode($row['from']), '%');
				$row['from'] = '%^'.str_replace('\*', '(.*)', $row['from']).'%i';
				$redirects['regex'][$row['from']] = $row['to'];
			
			} else {
				$row['from'] = urldecode($row['from']);
				$redirects['simple'][$row['from']] = urldecode($row['to']);
			}
		
		}
		
		set_vars( "redirects", $redirects );
		$db->free();
	}
	
	$uri = preg_replace( '#[/]+#i', '/', urldecode($_SERVER['REQUEST_URI']) );

	if(isset($redirects['simple']) AND is_array($redirects['simple']) AND count($redirects['simple']) AND isset($redirects['simple'][$uri]) ) {

		if( !check_same_domain($redirects['simple'][$uri]) OR !isset($_SESSION['is_redirect']) ) {
			
			$_SESSION['is_redirect'] = true;
			header("HTTP/1.0 301 Moved Permanently");
			header("Location: ". $redirects['simple'][$uri] );
			die("301 Redirect");
			
		}

	}
	
	if(isset($redirects['regex']) AND  is_array($redirects['regex']) AND count($redirects['regex']) ) {
		
		foreach ($redirects['regex'] as $key => $value) {
			
			if(preg_match($key, $uri)){
				
				if( !check_same_domain($value) OR !isset($_SESSION['is_redirect']) ) {
					
					$_SESSION['is_redirect'] = true;
					header("HTTP/1.0 301 Moved Permanently");
					header("Location: ". $value );
					die("301 Redirect");
					
				}
		    }
		}
	}
	
	unset($_SESSION['is_redirect']);

}

if( $config['only_ssl'] AND !isSSL() AND !isset($_SESSION['is_redirect']) ) {
	$_SESSION['is_redirect'] = true;
	
	$_SERVER['REQUEST_URI'] = htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8' );
	$_SERVER['REQUEST_URI'] = str_replace("&amp;", "&", $_SERVER['REQUEST_URI'] );

	if( $config['www_redirect'] AND stripos($_SERVER['HTTP_HOST'], 'www.') === 0 ) {
		$_SERVER['HTTP_HOST'] = substr($_SERVER['HTTP_HOST'], 4);
	}

	header("HTTP/1.0 301 Moved Permanently");
	header("Location: https://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
	die("Redirect");

} elseif( isset($_SESSION['is_redirect']) ) { unset($_SESSION['is_redirect']); }

if( $config['www_redirect'] AND stripos($_SERVER['HTTP_HOST'], 'www.') === 0 AND !isset($_SESSION['is_redirect']) ) {
	$_SESSION['is_redirect'] = true;
	
	$_SERVER['REQUEST_URI'] = htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8' );
	$_SERVER['REQUEST_URI'] = str_replace("&amp;", "&", $_SERVER['REQUEST_URI'] );

	$_SERVER['HTTP_HOST'] = substr($_SERVER['HTTP_HOST'], 4);

	header("HTTP/1.0 301 Moved Permanently");
	header("Location: //".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
	die("Redirect");

} elseif( isset($_SESSION['is_redirect']) ) { unset($_SESSION['is_redirect']); }

$cron_time = get_vars ( "cron" );

if( isset($cron_time['locked']) AND $cron_time['locked'] AND $cron_time['time'] ) {

	$cron_time['lasttime'] = $cron_time['time'];	
	$cron_time['time'] = $cron_time['successtime'];

}

if( !isset($cron_time['time']) ) $cron = 2;
elseif( isset($cron_time['time']) AND date ( "Y-m-d", $cron_time['time'] ) != date ( "Y-m-d", $_TIME )) $cron = 2;
elseif( isset($cron_time['time']) AND ( ($cron_time['time'] + (3600 * 2) ) < $_TIME) ) $cron = 1;

if ($cron) {
	
	include_once (DLEPlugins::Check(ENGINE_DIR . '/modules/cron.php'));
	
}

//####################################################################################################################
//    meta tags and titles for pages
//####################################################################################################################
$custom_metatags = array ();
$page_header_info = array();

if( $config['allow_own_meta'] ) {
	$custom_metatags = get_vars( "metatags" );
	
	if( !is_array( $custom_metatags ) ) {
		$custom_metatags = array ();

		$db->query( "SELECT * FROM " . PREFIX . "_metatags WHERE enabled=1 ORDER BY id DESC" );
		
		while ( $row = $db->get_row() ) {
			
			if( strpos ( $row['url'], "*" ) !== false ) {

				$row['url'] = preg_quote(urldecode($row['url']), '%');
				$row['url'] = '%^'.str_replace('\*', '(.*)', $row['url']).'%i';
				
				$custom_metatags['regex'][$row['url']] = array('title' => $row['title'], 'description' => $row['description'], 'keywords' => $row['keywords'], 'page_title' => $row['page_title'], 'robots' => $row['robots'], 'page_description' => stripslashes($row['page_description']));

			} else {

				$row['url'] = urldecode($row['url']);
				$custom_metatags['simple'][$row['url']] = array('title' => $row['title'], 'description' => $row['description'], 'keywords' => $row['keywords'], 'page_title' => $row['page_title'], 'robots' => $row['robots'], 'page_description' => stripslashes($row['page_description']));

			}
		
		}
		
		set_vars( "metatags", $custom_metatags );
		$db->free();
	}

	$r_uri = preg_replace( '#[/]+#i', '/', urldecode($_SERVER['REQUEST_URI']) );

	$url_charset = detect_encoding($r_uri);

	if ( $url_charset AND $url_charset != 'utf-8' ) {

		if( function_exists( 'mb_convert_encoding' ) ) {
	
			$r_uri = mb_convert_encoding( $r_uri, 'UTF-8', $url_charset );
	
		} elseif( function_exists( 'iconv' ) ) {
		
			$r_uri = iconv($url_charset, 'UTF-8', $r_uri);
		
		}

	}

	if(isset($custom_metatags['simple']) AND is_array($custom_metatags['simple']) AND count($custom_metatags['simple']) AND isset($custom_metatags['simple'][$r_uri]) AND $custom_metatags['simple'][$r_uri] ) {
		if( $custom_metatags['simple'][$r_uri]['page_title'] ) $page_header_info['title'] = $custom_metatags['simple'][$r_uri]['page_title'];
		if( $custom_metatags['simple'][$r_uri]['page_description'] ) $page_header_info['description'] = $custom_metatags['simple'][$r_uri]['page_description'];
	}
	
	if(isset($custom_metatags['regex']) AND is_array($custom_metatags['regex']) AND count($custom_metatags['regex'])) {	
		foreach ($custom_metatags['regex'] as $key => $value) {
			if(preg_match($key, $r_uri)){
				if( $value['page_title'] ) $page_header_info['title'] = $value['page_title'];
				if( $value['page_description'] ) $page_header_info['description'] = $value['page_description'];
		    }
		}
	}
	
}

//####################################################################################################################
//     Counting the number of news categories
//####################################################################################################################
if( $config['category_newscount'] ) {

	$news_count_in_array = dle_cache ( "news", "newscountcacheincats" );
	
	if( $news_count_in_array ) {
	
			$news_count_in_array = json_decode($news_count_in_array, true);
	
			if ( !is_array($news_count_in_array) ) $news_count_in_array = array();
	
	} else {
	
		$news_count_in_array = array();
		
		if( $config['no_date'] AND !$config['news_future'] ) {
			$thisdate = date( "Y-m-d H:i:s", $_TIME );
			$where_date = " AND date < '" . $thisdate . "'";
		} else $where_date = "";
		
		$db->query( "SELECT category, COUNT(*) AS count FROM " . PREFIX . "_post WHERE approve=1" . $where_date . " GROUP BY category" );
		$skip_parent_count = array();
		
		while ( $row = $db->get_row() ) {
			
			if(!$row['category']) continue;
		
			$cat_array = $temp_cat_array = explode(",", $row['category']);
			
			foreach ( $temp_cat_array as $value ) {
				
				if(!isset($news_count_in_array[$value])) $news_count_in_array[$value] = $row['count'];
				else $news_count_in_array[$value] = $news_count_in_array[$value] + $row['count'];
		
				$sub_count = $config['show_sub_cats'];
	
				if( $sub_count ) {

					$temp_parent = isset($cat_info[$value]['parentid']) ? $cat_info[$value]['parentid'] : 0;

					while ( $temp_parent ) {

						if( !in_array($temp_parent, $cat_array) ) {
					
							if(!isset($news_count_in_array[$temp_parent])) $news_count_in_array[$temp_parent] = $row['count'];
							else $news_count_in_array[$temp_parent] = $news_count_in_array[$temp_parent] + $row['count'];
							
							$cat_array[] = $temp_parent;

							if($cat_info[$temp_parent]['show_sub'] == 2) {
								
								if(!isset($skip_parent_count[$temp_parent])) $skip_parent_count[$temp_parent] = $row['count'];
								else $skip_parent_count[$temp_parent] = $skip_parent_count[$temp_parent] + $row['count'];
								
							}

						}

						$temp_parent = $cat_info[$temp_parent]['parentid'];
					}
				}

			}
			
		}
		
		if( count( $skip_parent_count ) ) {
			foreach ( $skip_parent_count as $key => $value ) {
				$news_count_in_array[$key] = $news_count_in_array[$key] - $value;
			}
		}

		create_cache ( "news", json_encode($news_count_in_array), "newscountcacheincats" );
		unset($temp_parent, $temp_cat_array, $cat_array);
	}

	foreach ( $news_count_in_array as $key => $value ) {
		if(isset($cat_info[$key]['id']) AND $cat_info[$key]['id']) $cat_info[$key]['newscount'] = $value;
	}
	
	unset($news_count_in_array);
}

//####################################################################################################################
//    The definition of banned users and IP
//####################################################################################################################
$banned_info = get_vars("banned");

if (!is_array ( $banned_info )) {

	$banned_info = array ();

	$db->query ( "SELECT * FROM " . USERPREFIX . "_banned" );
	while ( $row = $db->get_row () ) {

		if ($row['users_id']) {

			$banned_info['users_id'][$row['users_id']] = array (
																'users_id' => $row['users_id'],
																'descr' => stripslashes ( $row['descr'] ),
																'date' => $row['date'],
															    'banned_from' => $row['banned_from']
															   );

		} else {

			if (count ( explode ( ".", $row['ip'] ) ) == 4 OR filter_var( $row['ip'] , FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) OR strpos($row['ip'], ":") !== false )
				$banned_info['ip'][$row['ip']] = array (
														'ip' => $row['ip'],
														'descr' => stripslashes ( $row['descr'] ),
														'date' => $row['date'],
														'banned_from' => $row['banned_from']
														);
			elseif (strpos ( $row['ip'], "@" ) !== false)
				$banned_info['email'][$row['ip']] = array (
															'email' => $row['ip'],
															'descr' => stripslashes ( $row['descr'] ),
															'date' => $row['date'],
															'banned_from' => $row['banned_from']
														  );
			else $banned_info['name'][$row['ip']] = array (
															'name' => $row['ip'],
															'descr' => stripslashes ( $row['descr'] ),
															'date' => $row['date'],
															'banned_from' => $row['banned_from']
														  );

		}

	}
	set_vars ( "banned", $banned_info );
	$db->free ();
}

// #################################
if ($dle_module == "showfull" AND ($news_name OR $newsid) ) {

	$allow_sql_skin = false;

	foreach ( $cat_info as $cats ) {
		if ( $cats['skin'] ) $allow_sql_skin = true;
	}

	if ($allow_sql_skin) {

		if ( !$newsid ) {
			if ($year and $month and $day) {
				$where_date = " AND date >= '{$year}-{$month}-{$day}' AND date < '{$year}-{$month}-{$day}' + INTERVAL 24 HOUR";
			} elseif ($year and $month) {
				$where_date = " AND date >= '{$year}-{$month}-01' AND date < '{$year}-{$month}-01' + INTERVAL 1 MONTH";
			} elseif ($year) {
				$where_date = " AND date >= '{$year}-01-01' AND date < '{$year}-01-01' + INTERVAL 1 YEAR";
			} else $where_date = "";

			$sql_skin = $db->super_query ( "SELECT category FROM " . PREFIX . "_post WHERE alt_name ='{$news_name}'{$where_date} LIMIT 1" );

		} else $sql_skin = $db->super_query ( "SELECT category FROM " . PREFIX . "_post WHERE id = '{$newsid}'" );

		if( isset( $sql_skin['category'] ) AND $sql_skin['category'] ) {
			
			$base_skin = explode ( ',', $sql_skin['category'] );
	
			$category_skin = $cat_info[$base_skin[0]]['skin'];
		
		}

		unset ( $sql_skin );
		unset ( $base_skin );

	}

}

if (isset($_GET['do']) AND $_GET['do'] == "static") {

	$name = $db->safesql( $_GET['page'] );
	
	$static_result = $db->super_query ( "SELECT * FROM " . PREFIX . "_static WHERE name='{$name}'" );
	
	if ( isset($static_result['template_folder']) AND $static_result['template_folder'] ) {
		
		$category_skin = $static_result['template_folder'];
		
	} else $category_skin = '';

}

if ($category_skin) {

	$category_skin = trim( totranslit($category_skin, false, false) );

	if ($category_skin AND is_dir ( ROOT_DIR . '/templates/' . $category_skin )) {
		$config['skin'] = $category_skin;
	}

} elseif (isset ( $_REQUEST['action_skin_change'] )) {

	$_REQUEST['skin_name'] = trim( totranslit($_REQUEST['skin_name'], false, false) );

	if ($_REQUEST['skin_name'] AND is_dir ( ROOT_DIR . '/templates/' . $_REQUEST['skin_name'] ) ) {
		$config['skin'] = $_REQUEST['skin_name'];
		set_cookie ( "dle_skin", $_REQUEST['skin_name'], 365 );
	}

} elseif (isset ( $_COOKIE['dle_skin'] ) ) {

	$_COOKIE['dle_skin'] = trim( totranslit($_COOKIE['dle_skin'], false, false) );

	if ($_COOKIE['dle_skin'] != '' AND is_dir ( ROOT_DIR . '/templates/' . $_COOKIE['dle_skin'] )) {
		$config['skin'] = $_COOKIE['dle_skin'];
	}
}

if (isset ( $config["lang_" . $config['skin']] ) AND $config["lang_" . $config['skin']] != '' AND file_exists( DLEPlugins::Check(ROOT_DIR . '/language/' . $config["lang_" . $config['skin']] . '/website.lng') ) ) {

	include_once (DLEPlugins::Check(ROOT_DIR . '/language/' . $config["lang_" . $config['skin']] . '/website.lng'));
	
} else {

	include_once (DLEPlugins::Check(ROOT_DIR . '/language/' . $config['langs'] . '/website.lng'));

}

$allowed_sort = array('date', 'editdate', 'rating', 'news_read', 'comm_num', 'title');

if (!$config['allow_comments']) unset($allowed_sort[4]);
if (!in_array($config['news_sort'], $allowed_sort)) $config['news_sort'] = 'date';
if (!in_array($config['catalog_sort'], $allowed_sort)) $config['catalog_sort'] = 'date';

if (isset ( $_POST['set_new_sort'] ) AND $config['allow_change_sort']) {

	$find_sort = str_replace ( ".", "", totranslit ( $_POST['set_new_sort'] ) );
	$direction_sort = str_replace ( ".", "", totranslit ( $_POST['set_direction_sort'] ) );

	if (in_array($_POST['dlenewssortby'], $allowed_sort) AND stripos($find_sort, "dle_sort_") === 0) {

		if ($_POST['dledirection'] == "desc" or $_POST['dledirection'] == "asc") {

			$_SESSION[$find_sort] = $_POST['dlenewssortby'];
			$_SESSION[$direction_sort] = $_POST['dledirection'];
			$_SESSION['dle_sort_global'] = $_POST['dlenewssortby'];
			$_SESSION['dle_direction_global'] = $_POST['dledirection'];
			$_SESSION['dle_no_cache'] = "1";

		}

	}

}

$tpl = new dle_template();

if ( ($config['allow_smartphone'] AND !$_SESSION['mobile_disable'] AND $tpl->smartphone) OR $_SESSION['mobile_enable'] ) {

	if ( is_dir(ROOT_DIR . '/templates/smartphone') ) {

		$config['skin'] = "smartphone";
		$smartphone_detected = true;

	}

}

$tpl->dir = ROOT_DIR . '/templates/' . totranslit($config['skin'], false, false);

define ( 'TEMPLATE_DIR', $tpl->dir );

if ( $config['allow_registration'] ) {

	include_once (DLEPlugins::Check(ENGINE_DIR . '/modules/sitelogin.php'));
	
	if ( isset($_SESSION['twofactor_auth']) AND $_SESSION['twofactor_auth'] ) {

		if( $_SESSION['twofactor_type'] == 2 ) $lang['twofactor_alert'] = $lang['twofactor_alert_1'];

		$onload_scripts[] = <<<HTML
var wtw = 500 * getBaseSize();
if (wtw > ($(window).width() * 0.95)) { wtw = $(window).width() * 0.95; }

var hasVerticalScroll = $(document).height() > $(window).height();

$('#modal-overlay').remove();
$('body').prepend('<div id="modal-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #000; z-index: 980; display:none;opacity: 0;"></div>');
$('#modal-overlay').show();
$("#modal-overlay").css("transition", "opacity .5s ease");
$("#modal-overlay").css("opacity", ".5");

$('#twofactor').remove();
$('body').append("<div id=\"twofactor\" title=\"{$lang['twofactor_title']}\" style=\"display:none;\">{$lang['twofactor_alert']}<p><input id=\"twofactor_token\" type=\"text\" spellcheck=\"false\" autocomplete=\"off\" autocorrect=\"off\" autocapitalize=\"off\" name=\"twofactor_token\" inputmode=\"numeric\" pattern=\"[0-9]*\" style=\"width:100%;\" value=\"\"></p><div id=\"twofactor_response\" style=\"color:red\"></div></div>");


$('#twofactor').dialog({
	autoOpen: true,
	show: 'fade',
	hide: 'fade',
	width: wtw,
	resizable: false,
	dialogClass: "dle-popup-twofactor",
	buttons: {
		"{$lang['p_cancel']}" : function() { 
			$(this).dialog("close");						
		}, 
		"{$lang['p_send']}": function() {
			if ( $("#twofactor_token").val().length < 1) {
				 $("#twofactor_token").addClass('ui-state-error');
			} else {
				var pin = $("#twofactor_token").val();
				$.post(dle_root + "index.php?controller=ajax&mod=twofactor", { pin: pin, skin: dle_skin }, function(data){
				
					if ( data.success ) {
					
						window.location = window.location.pathname + window.location.search;
						
					} else if (data.error) {
						
						$("#twofactor_response").html(data.errorinfo);
						$(".dle-popup-twofactor").css('max-height', '');
						$("#twofactor").css('height', 'auto');
						
					}
					
				}, "json");

			}		
		}
	},
	open: function () {
		if (hasVerticalScroll && $(window).width() > 800 && $(window).height() > 500) {
			$("body").css("overflow", "hidden");
			$("html").css("scrollbar-gutter", "stable");
		}
	},
	close: function () {
		if (hasVerticalScroll) {
			$("body").css("overflow", "");
			$("html").css("scrollbar-gutter", "");
		}
		$(this).dialog('destroy');
		$("#modal-overlay").css("transition", "none");
		$('#modal-overlay').fadeOut(function () {
			$('#modal-overlay').remove();
		});
	}	
});
HTML;

	} else {
		
		if ($is_logged) {
	
			set_cookie ( "dle_newpm", $member_id['pm_unread'], 365 );
			
			if( !isset($_COOKIE['dle_newpm']) ) $_COOKIE['dle_newpm'] = 0;
			
			if ( $member_id['pm_unread'] > intval ( $_COOKIE['dle_newpm'] ) ) {

				$lang['pm_alert'] = str_replace("{user}", $member_id['name'], str_replace("{num}", intval($member_id['pm_unread']), $lang['pm_alert']));
				$lang['pm_alert'] = declination(array('', $member_id['pm_unread'], $lang['pm_alert'])) . '.';

				$onload_scripts[] = "DLEPush.info('{$lang['pm_alert']} <a href=\"{$_SERVER['SCRIPT_NAME']}?do=pm\">{$lang['pm_aread']}</a>', null, 60000);";
	
			}
	
		}
		
	}

	if( isset( $_SESSION['social_auth'] ) ) {
		
		$social_auth = json_decode($_SESSION['social_auth'], true);

		if(isset($social_auth['wait_mail_approve']) && !$is_logged) {
			$onload_scripts[] = "DLEPush.warning('{$lang['reg_err_36']}', null, 30000);";
			unset($_SESSION['social_auth']);
		} elseif(!$is_logged) {

			$social_auth['nickname'] = htmlspecialchars($social_auth['nickname'], ENT_QUOTES, 'UTF-8');

			$email_input = "<input type='hidden' name='dle-promt-email' id='dle-promt-email' value='{$social_auth['email']}'>";
			$social_auth_input = "<input type='hidden' name='dle-social-auth' id='dle-social-auth' value='" . htmlspecialchars($_SESSION['social_auth'], ENT_QUOTES, 'UTF-8') . "'>";

			if (empty($social_auth['email'])) {
				$email_input = "<br><br>{$lang['social_auth_3']}<br><input type='text' dir='auto' name='dle-promt-email' id='dle-promt-email' style='width:100%;' value=''>";
			}

			$onload_scripts[] = <<<HTML
var wtw = 550 * getBaseSize();
if (wtw > ($(window).width() * 0.95)) { wtw = $(window).width() * 0.95; }
var hasVerticalScroll = $(document).height() > $(window).height();

$('#modal-overlay').remove();
$('body').prepend('<div id="modal-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #000; z-index: 980; display:none;opacity: 0;"></div>');
$('#modal-overlay').show();
$("#modal-overlay").css("transition", "opacity .5s ease");
$("#modal-overlay").css("opacity", ".5");

$('#popup-social-auth').remove();
$('body').append("<div id='popup-social-auth' class='dle-popup-social-auth' title='{$lang['social_auth_1']}' style='display:none'>{$lang['social_auth_2']}<br><input type='text' dir='auto' name='dle-promt-name' id='dle-promt-name' style='width:100%;' value='{$social_auth['nickname']}'>{$email_input}{$social_auth_input}</div>");

$('#popup-social-auth').dialog({
	autoOpen: true,
	show: 'fade',
	hide: 'fade',
	width: wtw,
	resizable: false,
	dialogClass: "modalfixed dle-popup-social-auth",
	classes: {
		"ui-dialog": "modalfixed dle-popup-social-auth"
	},
	buttons: {
		"{$lang['p_cancel']}" : function() { 
			$(this).dialog("close");						
		}, 
		"{$lang['btn_continue']}": function() {
			if ( $("#dle-promt-name").val().length < 1) {
				 $("#dle-promt-name").addClass('ui-state-error');
			} else if ( $("#dle-promt-email").val().length < 1) {
				 $("#dle-promt-email").addClass('ui-state-error');
			} else {
				var name = $("#dle-promt-name").val();
				var email = $("#dle-promt-email").val();
				var social_auth = $("#dle-social-auth").val();
				
				ShowLoading('');

				$.post(dle_root + "?do=auth-social&action=confirm&provider={$social_auth['provider']}", { name: name, email: email, social_auth: social_auth}, function(data){
					
					HideLoading();
					
					if ( data.success ) {

						location.reload(true);

					} else if (data.error) {
						DLEPush.error(data.error);
					}
					
				}, "json");

			}		
		}
	},
	open: function () {
		if (hasVerticalScroll && $(window).width() > 800 && $(window).height() > 500) {
			$("body").css("overflow", "hidden");
			$("html").css("scrollbar-gutter", "stable");
		}
	},
	close: function () {
		if (hasVerticalScroll) {
			$("body").css("overflow", "");
			$("html").css("scrollbar-gutter", "");
		}
		$(this).dialog('destroy');
		$("#modal-overlay").css("transition", "none");
		$('#modal-overlay').fadeOut(function () {
			$('#modal-overlay').remove();
		});
	}	
});
HTML;

			unset($_SESSION['social_auth']);
		} else {
			unset($_SESSION['social_auth']);
		}
	}

} else {

	$_IP = get_ip();
	$dle_login_hash = sha1(SECURE_AUTH_KEY . $_SERVER['HTTP_USER_AGENT']);
	
}


if (!$is_logged) {
	$member_id['user_group'] = 5;
	$member_id['name'] = '';
}

if ( isset( $banned_info['ip'] ) ) $blockip = check_ip ( $banned_info['ip'] );  else $blockip = false;

$block_country = false;

$protected_groups   = array_filter(array_map('trim', explode(',', (string)($config['protected_groups'] ?? ''))));
$is_group_protected = in_array((string)$member_id['user_group'], $protected_groups);
$is_bot_allowed     = $config['allow_bots'] && isBotDetected();

if (isset($config['allowed_country']) && trim($config['allowed_country']) && !$is_bot_allowed && !$is_group_protected) {
	if( !DLECountry::Check($config['allowed_country']) ) {

		$block_country = true;
	
	} elseif($config['block_vpn'] && isset($_COOKIE['dle_possible_vpn'])) {

		$dle_possible_vpn = json_decode($_COOKIE['dle_possible_vpn'], true);

		if (is_array($dle_possible_vpn) && isset($dle_possible_vpn['site'])) {
			$block_country = true;
		}

	}
}

if (isset($config['declined_country']) && trim($config['declined_country']) && !$is_bot_allowed && !$is_group_protected) {

	if( DLECountry::Check($config['declined_country']) ) {

		$block_country = true;

	} elseif ($config['block_vpn'] && isset($_COOKIE['dle_possible_vpn'])) {

		$dle_possible_vpn = json_decode($_COOKIE['dle_possible_vpn'], true);

		if (is_array($dle_possible_vpn) && isset($dle_possible_vpn['site'])) {
			$block_country = true;
		}
	}

}

if ( ($is_logged AND $member_id['banned'] == "yes") OR $blockip OR $block_country) {
	
	include_once (DLEPlugins::Check(ENGINE_DIR . '/modules/banned.php'));
	
}

if ( !defined('BANNERS') AND $config['allow_banner'] AND $dle_module != "showfull" ) {
	include_once (DLEPlugins::Check(ENGINE_DIR . '/modules/banners.php'));
}

if( $do == "preview" ) {
	
	include_once (DLEPlugins::Check(ENGINE_DIR . '/preview.php'));
	die();
	
} elseif(isset($_GET['mod']) AND $_GET['mod'] == "print") {
	
	include_once (DLEPlugins::Check(ENGINE_DIR . '/print.php'));
	die();
}

if ($config['site_offline']) include_once (DLEPlugins::Check(ENGINE_DIR . '/modules/offline.php'));

if ($config['rss_informer']) include_once (DLEPlugins::Check(ENGINE_DIR . '/modules/rssinform.php'));

if ($config['allow_links']) include_once (DLEPlugins::Check(ENGINE_DIR . '/modules/links.php'));

if ($config['allow_social'] and $config['allow_registration'] and (!$is_logged or ($is_logged and $dle_module == 'userinfo' and $user == $member_id['name']))) {

	include_once(ENGINE_DIR . '/data/socialconfig.php');

	if ($social_config['vk']) {
		$vk_url = $_SERVER['SCRIPT_NAME'] . '?do=auth-social&action=auth&provider=vk';
	}
	if ($social_config['od']) {
		$odnoklassniki_url = $_SERVER['SCRIPT_NAME'] . '?do=auth-social&action=auth&provider=od';
	}
	if ($social_config['fc']) {
		$facebook_url = $_SERVER['SCRIPT_NAME'] . '?do=auth-social&action=auth&provider=fc';
	}

	if ($social_config['google']) {
		$google_url = $_SERVER['SCRIPT_NAME'] . '?do=auth-social&action=auth&provider=google';
	}

	if ($social_config['mailru']) {
		$mailru_url = $_SERVER['SCRIPT_NAME'] . '?do=auth-social&action=auth&provider=mailru';
	}

	if ($social_config['yandex']) {
		$yandex_url = $_SERVER['SCRIPT_NAME'] . '?do=auth-social&action=auth&provider=yandex';
	}
}

include_once (DLEPlugins::Check(ROOT_DIR . '/engine/engine.php'));

if ($config['allow_calendar'] OR $config['allow_archives']) include_once(DLEPlugins::Check(ENGINE_DIR . '/modules/calendar.php'));

$tpl->load_template('login.tpl');

$tpl->set('{login-method}', $config['auth_metod'] ? "E-Mail:" : $lang['login_metod']);
$tpl->set('{registration-link}', $_SERVER['SCRIPT_NAME'] . "?do=register");
$tpl->set('{lostpassword-link}', $_SERVER['SCRIPT_NAME'] . "?do=lostpassword");
$tpl->set('{logout-link}', $_SERVER['SCRIPT_NAME'] . "?action=logout");
$tpl->set('{pm-link}', $_SERVER['SCRIPT_NAME'] . "?do=pm");
$tpl->set('{group}', $user_group[$member_id['user_group']]['group_prefix'] . $user_group[$member_id['user_group']]['group_name'] . $user_group[$member_id['user_group']]['group_suffix']);

if ($is_logged) {

	$tpl->set('{login}', $member_id['name']);
	$tpl->set('{new-pm}', $member_id['pm_unread']);
	$tpl->set('{all-pm}', $member_id['pm_all']);

	if ($member_id['favorites']) {
		$tpl->set('{favorite-count}', count(explode(",", $member_id['favorites'])));
	} else $tpl->set('{favorite-count}', '0');

	if (filter_var($member_id['foto'], FILTER_VALIDATE_EMAIL)) {

		$tpl->set('{foto}', 'https://www.gravatar.com/avatar/' . md5(trim($member_id['foto'])) . '?s=' . intval($user_group[$member_id['user_group']]['max_foto']));

	} else {

		if ($member_id['foto']) {

			if (strpos($member_id['foto'], "//") === 0) $avatar = "http:" . $member_id['foto'];
			else $avatar = $member_id['foto'];

			$avatar = @parse_url($avatar);

			if (isset($avatar['host']) AND $avatar['host']) {
				$tpl->set('{foto}', $member_id['foto']);
			} else $tpl->set('{foto}', $config['http_home_url'] . "uploads/fotos/" . $member_id['foto']);

			unset($avatar);
		} else $tpl->set('{foto}', "{THEME}/dleimages/noavatar.png");
	}

	$tpl->set('{profile-link}', DLEUrl::BuildUrl('user', ['user' => urlencode($member_id['name'])]) );

} else {

	$tpl->set('{login}', '');
	$tpl->set('{new-pm}', '0');
	$tpl->set('{all-pm}', '0');
	$tpl->set('{favorite-count}', '0');
	$tpl->set('{foto}', "{THEME}/dleimages/noavatar.png");
	$tpl->set('{profile-link}', '');

}

$social_providers = ['vk' => $vk_url, 'odnoklassniki' => $odnoklassniki_url, 'facebook' => $facebook_url, 'google' => $google_url, 'mailru' => $mailru_url, 'yandex' => $yandex_url];

foreach ($social_providers as $provider => $url) {
	if ($url) {
		$tpl->set("[{$provider}]", "");
		$tpl->set("[/{$provider}]", "");
		$tpl->set("{{$provider}_url}", $url);
	} else {
		$tpl->set_block("'\\[{$provider}\\](.*?)\\[/{$provider}\\]'si", "");
		$tpl->set("{{$provider}_url}", '');
	}
}

if ($user_group[$member_id['user_group']]['icon']) $tpl->set('{group-icon}', "<img src=\"" . $user_group[$member_id['user_group']]['icon'] . "\" alt=\"\">");
else $tpl->set('{group-icon}', "");

if ($user_group[$member_id['user_group']]['allow_admin']) {
	$tpl->set('[admin-link]', "");
	$tpl->set('[/admin-link]', "");
	$tpl->set('{admin-link}', $config['http_home_url'] . $config['admin_path'] . "?mod=main");
} else {
	$tpl->set('{admin-link}', "");
	$tpl->set_block("'\\[admin-link\\](.*?)\\[/admin-link\\]'si", "");
}

$tpl->set('{stats-link}', DLEUrl::BuildUrl('statistics', []) );
$tpl->set('{addnews-link}', DLEUrl::BuildUrl('addnews', []) );
$tpl->set('{favorites-link}', DLEUrl::BuildUrl('favorites', []) );
$tpl->set('{newposts-link}', DLEUrl::BuildUrl('newposts', []) );

if ( strpos($tpl->copy_template, "[xf") !== false OR strpos($tpl->copy_template, "[ifxf") !== false ) {
	$xf = [];
	$xf['xfields'] = stripslashes($member_id['xfields']);

	DLEUserXFields::Compile($xf, $tpl);
}

$tpl->compile('login_panel');
$tpl->clear();

if ($config['allow_topnews']) include_once (DLEPlugins::Check(ENGINE_DIR . '/modules/topnews.php'));

if ($config['allow_votes'] ) include_once (DLEPlugins::Check(ENGINE_DIR . '/modules/vote.php'));

if ($config['allow_tags']) include_once (DLEPlugins::Check(ENGINE_DIR . '/modules/tagscloud.php'));

include_once (DLEPlugins::Check(ENGINE_DIR . '/modules/main.php'));