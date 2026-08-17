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
 File: quote.php
-----------------------------------------------------
 Use: comments quote
=====================================================
*/

if(!defined('DATALIFEENGINE')) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
	die ("error");
}

$id = isset($_GET['id']) ? intval( $_GET['id'] ) : 0;
$mode =  isset($_GET['mode']) ? $_GET['mode'] : '';

if(!$id) die( "error" );

if( ($config['allow_comments_wysiwyg'] AND $mode != 'pm') OR ($config['allow_pm_wysiwyg'] AND $mode =='pm') ) {

	$allowed_tags = array('dlehide[class|data-allowed-groups|contenteditable]', 'div[id|align|style|class|data-commenttime|data-commentuser|data-commentid|data-commentpostid|data-commentgast|contenteditable]', 'span[style|class|data-userurl|data-username|contenteditable]', 'p[align|style|class]', 'pre[class]', 'code', 'br', 'strong', 'em', 'ul', 'li', 'ol', 'b', 'u', 'i', 's', 'hr');
	
	if( $user_group[$member_id['user_group']]['allow_url'] ) $allowed_tags[] = 'a[href|target|style|class|title|data-encode]';
	if( $user_group[$member_id['user_group']]['allow_image'] ) $allowed_tags[] = 'img[style|class|src|srcset|alt|width|height]';
	
	$parse = new ParseFilter( $allowed_tags );
	$parse->wysiwyg = true;
	
} else {
	$parse = new ParseFilter();
}

$parse->safe_mode = true;
$parse->remove_html = false;

function reinit_spoiler_ids($html) {
	if (preg_match_all('/data-id="(sp[a-z0-9]+)"/i', $html, $matches)) {

		$unique_ids = array_unique($matches[1]);
		foreach ($unique_ids as $old_id) {
			$new_id = 'sp' . substr(md5(uniqid(mt_rand(), true)), 0, 18);

			$html = str_replace(
				[
					'data-id="' . $old_id . '"',
					'id="' . $old_id . '"',
					'id="svg-' . $old_id . '"'
				],
				[
					'data-id="' . $new_id . '"',
					'id="' . $new_id . '"',
					'id="svg-' . $new_id . '"'
				],
				$html
			);
		}
	}
	return $html;
}

if( $mode != 'pm' ) {

	$row = $db->super_query("SELECT post_id, date, autor, text, is_register FROM " . PREFIX . "_comments WHERE id = '{$id}'");
	
	if (!isset($row['post_id']) or !$row['post_id']) die("error");

	$row_news = $db->super_query("SELECT allow_comm, approve, access FROM " . PREFIX . "_post LEFT JOIN " . PREFIX . "_post_extras ON (" . PREFIX . "_post.id=" . PREFIX . "_post_extras.news_id) WHERE id='{$row['post_id']}'");
	$options = news_permission($row_news['access']);

	if ((!$user_group[$member_id['user_group']]['allow_addc'] and isset($options[$member_id['user_group']]) and $options[$member_id['user_group']] != 2) or (isset($options[$member_id['user_group']]) and $options[$member_id['user_group']] == 1)) die("error");

	if (!$row_news['allow_comm'] or !$row_news['approve']) {
		die("error");
	}
	
	$row['date'] = strtotime($row['date']);

} else {
	$row = $db->super_query("SELECT m.content AS text, m.created_at AS date, u.name AS autor FROM " . USERPREFIX ."_conversations_messages m JOIN " . USERPREFIX ."_conversation_users cu ON m.conversation_id = cu.conversation_id LEFT JOIN " . USERPREFIX . "_users u ON m.sender_id = u.user_id WHERE m.id = '{$id}' AND cu.user_id ='{$member_id['user_id']}'");
}

if (!isset($row['text']) OR !$row['text']) die( "error" );

if (stripos ($row['text'], "[hide" ) !== false ) {

	$row['text'] = preg_replace_callback ( "#\[hide(.*?)\](.+?)\[/hide\]#is", 
		function ($matches) use ($member_id, $user_group) {
			
			$matches[1] = str_replace(array("=", " "), "", $matches[1]);
			$matches[2] = $matches[2];

			if( $matches[1] ) {
				
				$groups = explode( ',', $matches[1] );

				if( in_array( $member_id['user_group'], $groups ) OR $member_id['user_group'] == "1") {
					return $matches[0];
				} else return "";
				
			} else {
				
				if( $user_group[$member_id['user_group']]['allow_hide'] ) return $matches[0]; else return "";
				
			}

	}, $row['text'] );
	
}

if (stripos($row['text'], '<dlehide') !== false) {

	$row['text'] = preg_replace_callback('#<dlehide\b([^>]*)>(.*?)</dlehide>#is',
		function ($matches) use ($member_id, $user_group) {

			$attributes = $matches[1];

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

			return $is_allowed ? $matches[0] : '';
		}, $row['text']
	);
}

if( (!$config['allow_comments_wysiwyg'] AND $mode != 'pm') OR ($mode == 'pm' AND !$config['allow_pm_wysiwyg']) ) {
	
	$text = $parse->decodeBBCodes( $row['text'], false );
	$text = str_replace( "&#58;", ":", $text );
	$text = str_replace( "&#91;", "[", $text );
	$text = str_replace( "&#93;", "]", $text );
	$text = str_replace( "&#123;", "{", $text );
	$text = str_replace( "&#39;", "'", $text );
	$text = "[quote={$row['autor']}]{$text}[/quote]";

} else {

	$c_q = '';

	if ( $mode != 'pm' ) {
		if ($row['is_register']) $row['is_register'] = 0; else $row['is_register'] = 1;
		$c_q = " data-commentid=\"{$id}\" data-commentpostid=\"{$row['post_id']}\" data-commentgast=\"{$row['is_register']}\"";
	}

	$text = remove_quotes_from_text(stripslashes($row['text']));
	$text = reinit_spoiler_ids($text);
	
	$text = "<div class=\"quote_block noncontenteditable\"><div class=\"title_quote\" data-commenttime=\"{$row['date']}\" data-commentuser=\"{$row['autor']}\"{$c_q}>". difflangdate($config['timestamp_comment'], $row['date']).",  {$row['autor']} {$lang['user_says']}</div><div class=\"quote\"><div class=\"quote_body contenteditable\">".trim($text)."</div></div></div>";

	$parse->wysiwyg = true;
	$text = $parse->decodeBBCodes(addslashes($text), true, true);

	$count_start = substr_count($text, "[quote");
	$count_end = substr_count($text, "[/quote]");

	if ($count_start and $count_start == $count_end) {
		$text = str_ireplace("[quote]", "&lt;div class=\"quote\"&gt;", $text);
		$text = preg_replace("#\[quote=(.*?)\]#i", "&lt;div class=\"title_quote\"&gt;{$lang['i_quote']} \\1&lt;/div&gt;&lt;div class=\"quote\"&gt;", $text);
		$text = str_ireplace("[/quote]", "&lt;/div&gt;", $text);
	}

}

echo $text;

?>