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
 File: main.php
=====================================================
*/

if( !defined('DATALIFEENGINE') ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

$home_url = clean_url($config['http_home_url']);

if (isset($_SERVER['HTTP_HOST']) AND clean_url($_SERVER['HTTP_HOST']) AND $home_url AND clean_url($_SERVER['HTTP_HOST']) != $home_url ) {

	$replace_url = array ();
	$replace_url[0] = $home_url;
	$replace_url[1] = clean_url ( $_SERVER['HTTP_HOST'] );

} else $replace_url = false;

$tpl->load_template ( 'main.tpl' );

$tpl->set ( '{calendar}', isset($tpl->result['calendar']) ? $tpl->result['calendar'] : '' );
$tpl->set ( '{archives}', isset($tpl->result['archive']) ? $tpl->result['archive'] : '' );
$tpl->set ( '{tags}', isset($tpl->result['tags_cloud']) ? $tpl->result['tags_cloud'] : '' );
$tpl->set ( '{vote}', isset($tpl->result['vote']) ? $tpl->result['vote'] : '' );
$tpl->set ( '{login}', isset($tpl->result['login_panel']) ? $tpl->result['login_panel'] : '' );
$tpl->set ( '{speedbar}', isset($tpl->result['speedbar']) ? $tpl->result['speedbar'] : '' );

if ( $dle_module == "showfull" AND $news_found ) {

	if( $config['related_news'] AND defined('RELATED_IDS') AND RELATED_IDS AND strpos( $tpl->copy_template, "related-news" ) !== false ) {
		
		if( !is_string($related_buffer) ) {
			include_once(DLEPlugins::Check(ENGINE_DIR . '/modules/show.related.php'));
		}
		
		if ($related_buffer) {
			$tpl->set('[related-news]', "");
			$tpl->set('[/related-news]', "");
		} else $tpl->set_block("'\\[related-news\\](.*?)\\[/related-news\\]'si", "");

		$tpl->set('{related-news}', $related_buffer);

	} else {

		$tpl->set('{related-news}', '');
		$tpl->set_block("'\\[related-news\\](.*?)\\[/related-news\\]'si", "");
	}

	if( strpos( $tpl->copy_template, "[xf" ) !== false OR strpos( $tpl->copy_template, "[ifxf" ) !== false ) {

		$full = ['id' => 'main', 'xfields' => $xfieldsdata];
		DLEXFields::Compile($full, $tpl);

		if (stripos ( $tpl->copy_template, "[hide" ) !== false ) {
			
			$tpl->copy_template = preg_replace_callback ( "#\[hide(.*?)\](.+?)\[/hide\]#is", 
				function ($matches) use ($member_id, $user_group, $lang) {
					
					$matches[1] = str_replace(array("=", " "), "", $matches[1]);
					$matches[2] = $matches[2];
	
					if( $matches[1] ) {
						
						$groups = explode( ',', $matches[1] );
	
						if( in_array( $member_id['user_group'], $groups ) OR $member_id['user_group'] == "1") {
							return $matches[2];
						} else return "<div class=\"quote dlehidden\">" . $lang['news_regus'] . "</div>";
						
					} else {
						
						if( $user_group[$member_id['user_group']]['allow_hide'] ) return $matches[2]; else return "<div class=\"quote dlehidden\">" . $lang['news_regus'] . "</div>";
						
					}
	
			}, $tpl->copy_template );
		}

		if (stripos($tpl->copy_template, '<dlehide') !== false) {

			$pattern = '#<dlehide\b([^>]*)>(.*?)</dlehide>#is';

			while (preg_match($pattern, $tpl->copy_template)) {

				$tpl->copy_template = preg_replace_callback($pattern,
					function ($matches) use ($member_id, $user_group, $lang) {

						$attributes = $matches[1];
						$inner_html = $matches[2];

						$allowed_groups = '';

						if (preg_match('/data-allowed-groups=["\']([\d,\s]+)["\']/i', $attributes, $m)) {
							$allowed_groups = $m[1];
						}

						$is_allowed = false;

						if ($allowed_groups) {
							$allowed_groups = array_map('trim', explode(',', $allowed_groups));
							if (in_array($member_id['user_group'], $allowed_groups) OR $member_id['user_group'] == '1') $is_allowed = true;
						} else {
							if ($user_group[$member_id['user_group']]['allow_hide']) $is_allowed = true;
						}

						return $is_allowed ? '<div class="dleshowhidden">' . $inner_html . '</div>' : '<div class="quote dlehidden">' . $lang['news_regus'] . '</div>';
					}, $tpl->copy_template
				);
			}
		}

		if( $config['files_allow'] && strpos( $tpl->copy_template, "[attachment=" ) !== false ) {
			$tpl->copy_template = show_attach( $tpl->copy_template, NEWS_ID );
		}

	}
		
} else {
	
	if( strpos( $tpl->copy_template, "related-news" ) !== false ) {
		$tpl->set( '{related-news}', "" );
		$tpl->set_block( "'\\[related-news\\](.*?)\\[/related-news\\]'si", "" );
	}
	
	if( strpos( $tpl->copy_template, "[xf" ) !== false ) {
		$tpl->copy_template = preg_replace( "'\\[xfnotgiven_(.*?)\\](.*?)\\[/xfnotgiven_(.*?)\\]'is", "", $tpl->copy_template );
		$tpl->copy_template = preg_replace( "'\\[xfgiven_(.*?)\\](.*?)\\[/xfgiven_(.*?)\\]'is", "", $tpl->copy_template );
		$tpl->copy_template = preg_replace( "'\\[xfvalue_(.*?)\\]'i", "", $tpl->copy_template );
	}
	
	if( strpos( $tpl->copy_template, "[ifxfvalue" ) !== false ) {
		$tpl->copy_template = preg_replace( "#\\[ifxfvalue(.+?)\\](.+?)\\[/ifxfvalue\\]#is", "", $tpl->copy_template );
	}

}

if ($config['allow_skin_change']) {
	$tpl->set('{changeskin}', ChangeSkin($config['skin']));
	$tpl->set('[changeskin]', '');
	$tpl->set('[/changeskin]', '');
} else {
	$tpl->set('{changeskin}', '');
	$tpl->set_block("'\\[changeskin\\](.*?)\\[/changeskin\\]'si", "");
}

if (count ( $banners ) and $config['allow_banner']) {

	foreach ( $banners as $name => $value ) {
		$tpl->copy_template = str_replace ( "{banner_" . $name . "}", $value, $tpl->copy_template );
		if ( $value ) {
			$tpl->copy_template = str_replace ( "[banner_" . $name . "]", "", $tpl->copy_template );
			$tpl->copy_template = str_replace ( "[/banner_" . $name . "]", "", $tpl->copy_template );
		}
	}

}

$tpl->set_block ( "'{banner_(.*?)}'si", "" );
$tpl->set_block ( "'\\[banner_(.*?)\\](.*?)\\[/banner_(.*?)\\]'si", "" );

if ($config['rss_informer'] AND count ($informers) ) {
	foreach ( $informers as $name => $value ) {
		$tpl->copy_template = str_replace ( "{inform_" . $name . "}", $value, $tpl->copy_template );
	}
}

if (stripos ( $tpl->copy_template, "[category=" ) !== false) {
	$tpl->copy_template = preg_replace_callback ( "#\\[(category)=(.+?)\\](.*?)\\[/category\\]#is", "check_category", $tpl->copy_template );
}

if (stripos ( $tpl->copy_template, "[not-category=" ) !== false) {
	$tpl->copy_template = preg_replace_callback ( "#\\[(not-category)=(.+?)\\](.*?)\\[/not-category\\]#is", "check_category", $tpl->copy_template );
}

if (stripos ( $tpl->copy_template, "[static=" ) !== false) {
	$tpl->copy_template = preg_replace_callback ( "#\\[(static)=(.+?)\\](.*?)\\[/static\\]#is", "check_static", $tpl->copy_template );
}

if (stripos ( $tpl->copy_template, "[not-static=" ) !== false) {
	$tpl->copy_template = preg_replace_callback ( "#\\[(not-static)=(.+?)\\](.*?)\\[/not-static\\]#is", "check_static", $tpl->copy_template );
}

if ( ($allow_active_news AND $news_found AND $config['allow_change_sort'] AND $dle_module != "userinfo") OR defined('CUSTOMSORT')) {

	$tpl->set ( '[sort]', "" );
	$tpl->set ( '{sort}', news_sort ( $dle_module ) );
	$tpl->set ( '[/sort]', "" );

} else {

	$tpl->set_block ( "'\\[sort\\](.*?)\\[/sort\\]'si", "" );

}

$tpl->set('{topnews}', isset($tpl->result['topnews']) ? $tpl->result['topnews'] : '');

if( $vk_url ) {
	$tpl->set( '[vk]', "" );
	$tpl->set( '[/vk]', "" );
	$tpl->set( '{vk_url}', $vk_url );	
} else {
	$tpl->set_block( "'\\[vk\\](.*?)\\[/vk\\]'si", "" );
	$tpl->set( '{vk_url}', '' );	
}
if( $odnoklassniki_url ) {
	$tpl->set( '[odnoklassniki]', "" );
	$tpl->set( '[/odnoklassniki]', "" );
	$tpl->set( '{odnoklassniki_url}', $odnoklassniki_url );
} else {
	$tpl->set_block( "'\\[odnoklassniki\\](.*?)\\[/odnoklassniki\\]'si", "" );
	$tpl->set( '{odnoklassniki_url}', '' );	
}
if( $facebook_url ) {
	$tpl->set( '[facebook]', "" );
	$tpl->set( '[/facebook]', "" );
	$tpl->set( '{facebook_url}', $facebook_url );	
} else {
	$tpl->set_block( "'\\[facebook\\](.*?)\\[/facebook\\]'si", "" );
	$tpl->set( '{facebook_url}', '' );	
}
if( $google_url ) {
	$tpl->set( '[google]', "" );
	$tpl->set( '[/google]', "" );
	$tpl->set( '{google_url}', $google_url );
} else {
	$tpl->set_block( "'\\[google\\](.*?)\\[/google\\]'si", "" );
	$tpl->set( '{google_url}', '' );	
}
if( $mailru_url ) {
	$tpl->set( '[mailru]', "" );
	$tpl->set( '[/mailru]', "" );
	$tpl->set( '{mailru_url}', $mailru_url );	
} else {
	$tpl->set_block( "'\\[mailru\\](.*?)\\[/mailru\\]'si", "" );
	$tpl->set( '{mailru_url}', '' );	
}
if( $yandex_url ) {
	$tpl->set( '[yandex]', "" );
	$tpl->set( '[/yandex]', "" );
	$tpl->set( '{yandex_url}', $yandex_url );
} else {
	$tpl->set_block( "'\\[yandex\\](.*?)\\[/yandex\\]'si", "" );
	$tpl->set( '{yandex_url}', '' );
}

$config['http_home_url'] = substr($_SERVER['SCRIPT_NAME'], 0, (int)strrpos($_SERVER['SCRIPT_NAME'], '/') + 1) ?: '/';

if ( !$user_group[$member_id['user_group']]['allow_admin'] ) $config['admin_path'] = "";
if ($config['thumb_gallery'] AND ($dle_module == "showfull" OR $dle_module == "static")) $config['thumb_gallery'] = 1; else $config['thumb_gallery'] = 0;

$ajax .= <<<HTML
<script>
<!--
var dle_root       = '{$config['http_home_url']}';
var dle_admin      = '{$config['admin_path']}';
var dle_login_hash = '{$dle_login_hash}';
var dle_group      = {$member_id['user_group']};
var dle_skin       = '{$config['skin']}';
var dle_wysiwyg    = {$config['allow_comments_wysiwyg']};
var dle_pm_wysiwyg = {$config['allow_pm_wysiwyg']};
var dle_min_search = '{$config['search_length_min']}';
var dle_act_lang   = ["{$lang['p_yes']}", "{$lang['p_no']}", "{$lang['p_enter']}", "{$lang['p_cancel']}", "{$lang['p_save']}", "{$lang['p_del']}", "{$lang['ajax_info']}", "{$lang['pre_copy']}", "{$lang['pre_copied']}"];
var menu_short     = '{$lang['menu_short']}';
var menu_full      = '{$lang['menu_full']}';
var menu_profile   = '{$lang['menu_profile']}';
var menu_send      = '{$lang['menu_send']}';
var menu_uedit     = '{$lang['menu_uedit']}';
var dle_info       = '{$lang['p_info']}';
var dle_confirm    = '{$lang['p_confirm']}';
var dle_prompt     = '{$lang['p_prompt']}';
var dle_req_field  = ["{$lang['req_field_1']}", "{$lang['req_field_2']}", "{$lang['req_field_3']}"];
var dle_del_agree  = '{$lang['news_delcom']}';
var dle_spam_agree = '{$lang['mark_spam']}';
var dle_c_title    = '{$lang['complaint_title']}';
var dle_complaint  = '{$lang['add_to_complaint']}';
var dle_mail       = '{$lang['reply_mail']}';
var dle_big_text   = '{$lang['big_text']}';
var dle_orfo_title = '{$lang['orfo_title']}';
var dle_p_send     = '{$lang['p_send']}';
var dle_p_send_ok  = '{$lang['p_send_ok']}';
var dle_save_ok    = '{$lang['n_save_ok']}';
var dle_reply_title= '{$lang['reply_comments']}';
var dle_tree_comm  = '{$dle_tree_comments}';
var dle_del_news   = '{$lang['news_delnews']}';
var dle_sub_agree  = '{$lang['subscribe_info_3']}';
var dle_unsub_agree  = '{$lang['subscribe_info_4']}';
var dle_captcha_type  = '{$config['allow_recaptcha']}';
var dle_allow_edit_users  = '{$user_group[$member_id['user_group']]['admin_editusers']}';
var dle_share_interesting  = ["{$lang['share_i_1']}", "{$lang['share_i_2']}", "{$lang['share_i_3']}", "{$lang['share_i_4']}", "{$lang['share_i_5']}", "{$lang['share_i_6']}"];
var DLEPlayerLang     = {prev: '{$lang['player_prev']}',next: '{$lang['player_next']}',play: '{$lang['player_play']}',pause: '{$lang['player_pause']}',mute: '{$lang['player_mute']}', unmute: '{$lang['player_unmute']}', settings: '{$lang['player_settings']}', enterFullscreen: '{$lang['player_fullscreen']}', exitFullscreen: '{$lang['player_efullscreen']}', speed: '{$lang['player_speed']}', normal: '{$lang['player_normal']}', quality: '{$lang['player_quality']}', pip: '{$lang['player_pip']}'};
var DLEGalleryLang    = {CLOSE: '{$lang['thumb_closetitle']}', NEXT: '{$lang['thumb_nexttitle']}', PREV: '{$lang['thumb_previoustitle']}', ERROR: '{$lang['all_err_1']}', IMAGE_ERROR: '{$lang['thumb_imageerror']}', TOGGLE_AUTOPLAY: '{$lang['thumb_playtitle']}', TOGGLE_SLIDESHOW: '{$lang['thumb_playtitle']}', TOGGLE_FULLSCREEN: '{$lang['thumb_fullscreen']}', TOGGLE_THUMBS: '{$lang['thumb_thtoggle']}', TOGGLE_FULL: '{$lang['thumb_thzoom']}', ITERATEZOOM: '{$lang['thumb_thzoom']}', DOWNLOAD: '{$lang['thumb_thdownload']}' };
var DLEGalleryMode    = {$config['thumb_gallery']};
var DLELazyMode       = {$config['image_lazy']};\n
HTML;

if ($user_group[$member_id['user_group']]['allow_all_edit']) {

	$ajax .= <<<HTML
var dle_notice     = '{$lang['btn_notice']}';
var dle_p_text     = '{$lang['p_text']}';
var dle_del_msg    = '{$lang['p_message']}';
var allow_dle_delete_news   = true;\n
HTML;

} else {

	$ajax .= <<<HTML
var allow_dle_delete_news   = false;\n
HTML;

}

if ($config['fast_search'] AND $user_group[$member_id['user_group']]['allow_search']) {

	$ajax .= <<<HTML
var dle_search_delay   = false;
var dle_search_value   = '';
HTML;

	$onload_scripts[] = "FastSearch();";

}

if ( defined('DLE_PHP_MIN') AND !DLE_PHP_MIN ) {
	$lang['stat_phperror'] = str_replace('{minversion}', DLE_PHP_MIN_VERSION, $lang['stat_phperror']);
	$lang['stat_phperror'] = str_replace('{version}', PHP_VERSION, $lang['stat_phperror']);

	$onload_scripts[] = "DLEPush.error('{$lang['stat_phperror']}');";
}

$show_error_info = false;

if( $_SERVER['QUERY_STRING'] AND !$tpl->result['content'] AND !$tpl->result['info'] AND stripos ( $tpl->copy_template, "{content}" ) !== false ) {
	$show_error_info = true;
}

if($dle_module == "main" AND $config['start_site'] == 2 ) {
	$show_error_info = false;
}

if( $show_error_info ) {

	header( "HTTP/1.0 404 Not Found" );
	$need_404 = false;
	
	if( $config['own_404'] AND file_exists(ROOT_DIR . '/404.html') ) {
		header("Content-type: text/html; charset=utf-8");
		echo file_get_contents( ROOT_DIR . '/404.html' );
		die();
		
	} else msgbox( $lang['all_err_1'], $lang['news_err_27'] );

}

if($need_404) {
	@header( "HTTP/1.0 404 Not Found" );
}

if( is_array($tpl->onload_scripts) AND count($tpl->onload_scripts) ) {
	$onload_scripts = array_merge($onload_scripts, $tpl->onload_scripts);
}

if ( count($onload_scripts) ) {
	
	$onload_scripts =implode("\n", $onload_scripts);

	$ajax .= <<<HTML

jQuery(function($){
{$onload_scripts}
});
HTML;

} else $onload_scripts="";

$ajax .= <<<HTML

//-->
</script>
HTML;

if( stripos($tpl->copy_template, "{content}") !== false ) $make_main_navigation = true; else $make_main_navigation = false;

$tpl->set ( '{AJAX}', $ajax );
$tpl->set ( '{info}',  $tpl->result['info'] );
$tpl->set ( '{content}', $tpl->result['content'] );
$tpl->compile ( 'main' );

if (strpos($tpl->result['main'], "<pre") !== false) {

	$js_array[] = "public/prism/prism.js";
}

if ((strpos($tpl->result['main'], "highslide") !== false) AND $dle_module != "addnews") {

	$js_array[] = "public/fancybox/fancybox.js";
}

if (strpos($tpl->result['main'], "data-src=") !== false) {
	$js_array[] = "public/js/lazyload.js";
}

if (strpos($tpl->result['main'], "data-math=") !== false) {
	$css_array[] = "public/katex/katex.min.css";
	$js_array[] = "public/katex/katex.min.js";
}

if (strpos($tpl->result['main'], "share-content") !== false) {

	$js_array[] = "public/masha/masha.js";
}

if (strpos($tpl->result['main'], "dleplyrplayer") !== false) {
	if (strpos($tpl->result['main'], ".m3u8") !== false) {
		$js_array[] = "public/html5player/hls.js";
	}
	$css_array[] = "public/html5player/plyr.css";
	$js_array[] = "public/html5player/plyr.js";
} elseif (strpos($tpl->result['main'], "dleaudioplayer") !== false OR strpos($tpl->result['main'], "dlevideoplayer") !== false) {

	$css_array[] = "public/html5player/player.css";
	$js_array[] = "public/html5player/player.js";
}

if ($user_group[$member_id['user_group']]['allow_pm']) {
	$allow_comments_ajax = true;
}

if ($allow_comments_ajax AND $dle_module != "addnews") {
	$js_array[] = "public/editor/tiny_mce/tinymce.min.js";
}


$js_array = build_css($css_array, $config) . "\n" . build_js($js_array, $config);

$schema = DLESEO::CompileSchema();

if ($schema) {
	$js_array .= "\n<script type=\"application/ld+json\">" . $schema . "</script>";
}

if (stripos($tpl->result['main'], "{jsfiles}") !== false) {
	$tpl->result['main'] = preg_replace_callback('/\{headers\}/',
		function () use ($metatags) {
			return $metatags;
		}, $tpl->result['main'], 1
	);
	$tpl->result['main'] = preg_replace_callback('/\{jsfiles\}/',
		function () use ($js_array) {
			return $js_array;
		}, $tpl->result['main'], 1
	);
} else {
	$tpl->result['main'] = preg_replace_callback('/\{headers\}/',
		function () use ($metatags, $js_array) {
			return $metatags . "\n" . $js_array;
		}, $tpl->result['main'], 1
	);
}

$tpl->result['main'] = str_replace(array("_&#123;_", "_&#91;_"), array("{", "["), $tpl->result['main']);

$handlers = array();

if( stripos ( $tpl->result['main'], 'custom=' ) !== false) {
	
	$handlers['custom'] = function ($params, $content) use ($custom_blocks_names) {

		if (strpos($params, '|') !== false) {
			$blocks = explode('|', $params);
			foreach ($blocks as $block) {
				$block = trim($block);
				if (isset($custom_blocks_names[$block]) && $custom_blocks_names[$block]) {
					return $content;
				}
			}
			return '';
		}

		if (strpos($params, ',') !== false) {
			$blocks = explode(',', $params);
			foreach ($blocks as $block) {
				$block = trim($block);
				if (!isset($custom_blocks_names[$block]) || !$custom_blocks_names[$block]) {
					return '';
				}
			}
			return $content;
		}

		if (isset($custom_blocks_names[$params]) && $custom_blocks_names[$params]) {
			return $content;
		}

		return '';

	};
	
	$handlers['not-custom'] = function ($params, $content) use ($custom_blocks_names) {

		if (strpos($params, '|') !== false) {
			$blocks = explode('|', $params);
			foreach ($blocks as $block) {
				$block = trim($block);
				if (!isset($custom_blocks_names[$block]) || !$custom_blocks_names[$block]) {
					return $content;
				}
			}
			return '';
		}

		if (strpos($params, ',') !== false) {
			$blocks = explode(',', $params);
			foreach ($blocks as $block) {
				$block = trim($block);
				if (isset($custom_blocks_names[$block]) && $custom_blocks_names[$block]) {
					return '';
				}
			}
			return $content;
		}

		if (!isset($custom_blocks_names[$params]) || !$custom_blocks_names[$params]) {
			return $content;
		}

		return '';

	};
}

if (stripos($tpl->result['main'], 'customcomments=') !== false) {

	$handlers['customcomments'] = function ($params, $content) use ($custom_comments_blocks_names) {

		if (strpos($params, '|') !== false) {
			$blocks = explode('|', $params);
			foreach ($blocks as $block) {
				$block = trim($block);
				if (isset($custom_comments_blocks_names[$block]) && $custom_comments_blocks_names[$block]) {
					return $content;
				}
			}
			return '';
		}

		if (strpos($params, ',') !== false) {
			$blocks = explode(',', $params);
			foreach ($blocks as $block) {
				$block = trim($block);
				if (!isset($custom_comments_blocks_names[$block]) || !$custom_comments_blocks_names[$block]) {
					return '';
				}
			}
			return $content;
		}

		if (isset($custom_comments_blocks_names[$params]) && $custom_comments_blocks_names[$params]) {
			return $content;
		}

		return '';
	};

	$handlers['not-customcomments'] = function ($params, $content) use ($custom_comments_blocks_names) {

		if (strpos($params, '|') !== false) {
			$blocks = explode('|', $params);
			foreach ($blocks as $block) {
				$block = trim($block);
				if (!isset($custom_comments_blocks_names[$block]) || !$custom_comments_blocks_names[$block]) {
					return $content;
				}
			}
			return '';
		}

		if (strpos($params, ',') !== false) {
			$blocks = explode(',', $params);
			foreach ($blocks as $block) {
				$block = trim($block);
				if (isset($custom_comments_blocks_names[$block]) && $custom_comments_blocks_names[$block]) {
					return '';
				}
			}
			return $content;
		}

		if (!isset($custom_comments_blocks_names[$params]) || !$custom_comments_blocks_names[$params]) {
			return $content;
		}

		return '';
	};

}

if (count($handlers)) {
	$tpl->result['main'] = $tpl->process_recursive_tags($tpl->result['main'], $handlers);
}

if( $is_logged AND stripos ( $tpl->result['main'], "-favorites-" ) !== false) {
	
	$fav_arr = explode(',', $member_id['favorites'] );
	
	foreach( $fav_arr as $fav_id ) {
		$tpl->result['main'] = str_replace ( "{-favorites-{$fav_id}}", "<a data-fav-id=\"{$fav_id}\" class=\"favorite-link del-favorite\" href=\"{$_SERVER['SCRIPT_NAME']}?do=favorites&amp;doaction=del&amp;id={$fav_id}\"><img src=\"{$config['http_home_url']}templates/{$config['skin']}/dleimages/minus_fav.gif\" onclick=\"doFavorites('{$fav_id}', 'minus', 0); return false;\" title=\"{$lang['news_minfav']}\" alt=\"\"></a>", $tpl->result['main'] );
		$tpl->result['main'] = str_replace ( "[del-favorites-{$fav_id}]", "<span data-favorites-del=\"{$fav_id}\" style=\"display:none\"></span><a onclick=\"doFavorites('{$fav_id}', 'minus', 1, 'short'); return false;\" href=\"{$_SERVER['SCRIPT_NAME']}?do=favorites&amp;doaction=del&amp;id={$fav_id}\">", $tpl->result['main'] );
		$tpl->result['main'] = str_replace ( "[/del-favorites-{$fav_id}]", "</a>", $tpl->result['main'] );
		$tpl->result['main'] = preg_replace( "'\\[add-favorites-{$fav_id}\\](.*?)\\[/add-favorites-{$fav_id}\\]'is", "<span data-favorites-add=\"{$fav_id}\" style=\"display:none\"></span>", $tpl->result['main'] );
	}
	
	$tpl->result['main'] = preg_replace( "'\\{-favorites-(\d+)\\}'i", "<a data-fav-id=\"\\1\" class=\"favorite-link add-favorite\" href=\"{$_SERVER['SCRIPT_NAME']}?do=favorites&amp;doaction=add&amp;id=\\1\"><img src=\"{$config['http_home_url']}templates/{$config['skin']}/dleimages/plus_fav.gif\" onclick=\"doFavorites('\\1', 'plus', 0); return false;\" title=\"{$lang['news_addfav']}\" alt=\"\"></a>", $tpl->result['main'] );
	$tpl->result['main'] = preg_replace( "'\\[add-favorites-(\d+)\\]'i", "<span data-favorites-add=\"\\1\" style=\"display:none\"></span><a onclick=\"doFavorites('\\1', 'plus', 1, 'short'); return false;\" href=\"{$_SERVER['SCRIPT_NAME']}?do=favorites&amp;doaction=add&amp;id=\\1\">", $tpl->result['main'] );
	$tpl->result['main'] = preg_replace( "'\\[/add-favorites-(\d+)\\]'i", "</a>", $tpl->result['main'] );
	$tpl->result['main'] = preg_replace( "'\\[del-favorites-(\d+)\\](.*?)\\[/del-favorites-(\d+)\\]'si", "<span data-favorites-del=\"\\1\" style=\"display:none\"></span>", $tpl->result['main'] );

}

if ( ($tpl->result['content'] AND isset($tpl->result['navigation']) AND $tpl->result['navigation']) OR defined('CUSTOMNAVIGATION') ) {
	
	$tpl->result['main'] = str_replace('[navigation]', '', $tpl->result['main']);
	$tpl->result['main'] = str_replace('[/navigation]', '', $tpl->result['main']);
	$tpl->result['main'] = preg_replace("'\\[not-navigation\\](.*?)\\[/not-navigation\\]'is", '', $tpl->result['main']);

	if (stripos($tpl->result['main'], "{navigation}") !== false) {

		$tpl->result['main'] = str_replace('{newsnavigation}', '', $tpl->result['main']);

		if (isset($tpl->result['navigation']) AND $tpl->result['navigation'] AND $make_main_navigation) {
			$tpl->result['main'] = str_replace('{navigation}', $tpl->result['navigation'], $tpl->result['main']);
		} else {
			$tpl->result['main'] = str_replace('{navigation}', $custom_navigation, $tpl->result['main']);
		}

	} else {

		if (isset($tpl->result['navigation']) AND $tpl->result['navigation'] AND $make_main_navigation) {
			$tpl->result['main'] = str_replace('{newsnavigation}', $tpl->result['navigation'], $tpl->result['main']);
		} else {
			$tpl->result['main'] = str_replace('{newsnavigation}', $custom_navigation, $tpl->result['main']);
		}
	}
	
} else {

	$tpl->result['main'] = str_replace('{navigation}', '', $tpl->result['main']);
	$tpl->result['main'] = str_replace('[not-navigation]', '', $tpl->result['main']);
	$tpl->result['main'] = str_replace('[/not-navigation]', '', $tpl->result['main']);
	$tpl->result['main'] = preg_replace("'\\[navigation\\](.*?)\\[/navigation\\]'is", '', $tpl->result['main']);

}

$tpl->result['main'] = str_ireplace('{THEME}', $config['http_home_url'] . 'templates/' . $config['skin'], $tpl->result['main']);

if ($config['allow_links'] and isset($replace_links['all']) ) $tpl->result['main'] = replace_links ( $tpl->result['main'], $replace_links['all'] );

if ( is_array($replace_url) ) $tpl->result['main'] = str_replace ( $replace_url[0]."/", $replace_url[1]."/", $tpl->result['main'] );

if($remove_canonical) {
	$tpl->result['main'] = preg_replace( "#<link rel=['\"]canonical['\"](.+?)>#i", "", $tpl->result['main'] );
}
if( isset($_SERVER['HTTP_HOST']) AND $_SERVER['HTTP_HOST'] ) {
	$tpl->result['main'] = str_replace('src="http://' . $_SERVER['HTTP_HOST'] . '/', 'src="/', $tpl->result['main']);
	$tpl->result['main'] = str_replace('srcset="http://' . $_SERVER['HTTP_HOST'] . '/', 'srcset="/', $tpl->result['main']);
	$tpl->result['main'] = str_replace('src="https://' . $_SERVER['HTTP_HOST'] . '/', 'src="/', $tpl->result['main']);
	$tpl->result['main'] = str_replace('srcset="https://' . $_SERVER['HTTP_HOST'] . '/', 'srcset="/', $tpl->result['main']);
}

echo $tpl->result['main'];

$tpl->global_clear();

$db->close();

echo "\n<!-- DataLife Engine Copyright SoftNews Media Group (https://dle-news.ru) -->\r\n";

GzipOut();
