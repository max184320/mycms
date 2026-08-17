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
 File: rebuild.php
-----------------------------------------------------
 Use: News rebuild
=====================================================
*/

if(!defined('DATALIFEENGINE')) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

if(($member_id['user_group'] != 1)) {die ("error");}

if (!isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash) {

	  die ("error");

}

$_POST['area'] = isset($_POST['area']) ? $_POST['area'] : '';

if ($_POST['area'] == "related" ) {
	$db->query( "UPDATE " . PREFIX . "_post_extras SET related_ids=''" );
	clear_cache(array("full_", 'related_'));
    echo "{\"status\": \"ok\"}";
	die();
}

$startfrom = isset($_POST['startfrom']) ? intval($_POST['startfrom']) : 0;
$buffer = "";
$step = 0;
$count_per_step = 100;

if ($_POST['area'] == "comments" ) {
	$count_per_step = 500;
}

if( isset($_POST['count_per_step']) AND intval($_POST['count_per_step']) > 0 ) {
	$count_per_step = intval($_POST['count_per_step']);
}

if ($_POST['area'] == "static" ) {

	$parse = new ParseFilter();
	$parse->edit_mode = false;
	$parse->allow_code = false;

	$result = $db->query("SELECT id, template, allow_br FROM " . PREFIX . "_static WHERE allow_br !='2' LIMIT ".$startfrom.", ".$count_per_step);

	while($row = $db->get_row($result))
	{
			
		$row['template'] = $parse->decodeBBCodes( $row['template'], true, true );

		$template = $parse->process( $row['template'] );

		$template = $db->safesql($parse->BB_Parse( $template ));

		$db->query( "UPDATE " . PREFIX . "_static SET template='$template' WHERE id='{$row['id']}'" );

		$step++;
	}

	$rebuildcount = $startfrom + $step;
	$buffer = "{\"status\": \"ok\",\"rebuildcount\": {$rebuildcount}}";
	echo $buffer;
	
} elseif ($_POST['area'] == "comments" ) {
	
	if( $config['allow_comments_wysiwyg'] ) {

		$allowed_tags = array('dlehide[class|data-allowed-groups|contenteditable]', 'div[id|align|style|class|data-commenttime|data-commentuser|data-commentid|data-commentpostid|data-commentgast|contenteditable]', 'span[style|class|data-userurl|data-username|contenteditable]', 'p[align|style|class]', 'pre[class]', 'code', 'br', 'strong', 'em', 'ul', 'li', 'ol', 'b', 'u', 'i', 's', 'hr');
		
		if( $user_group[$member_id['user_group']]['allow_url'] ) $allowed_tags[] = 'a[href|target|style|class|title|data-encode]';
		if( $user_group[$member_id['user_group']]['allow_image'] ) $allowed_tags[] = 'img[style|class|src|srcset|alt|width|height]';
		
		$parse = new ParseFilter( $allowed_tags );
		$parse->wysiwyg = true;
		$parse->allow_code = false;
		$use_html = true;
	
	} else {
		
		$parse = new ParseFilter();
		$use_html = false;
		$parse->allowbbcodes = false;
		
	}
	
	$parse->safe_mode = true;
	$parse->remove_html = false;
	$parse->edit_mode = false;
	$parse->allow_url = $user_group[$member_id['user_group']]['allow_url'];
	$parse->allow_image = $user_group[$member_id['user_group']]['allow_image'];

	$result = $db->query("SELECT id, text FROM " . PREFIX . "_comments LIMIT ".$startfrom.", ".$count_per_step);
	
	while($row = $db->get_row($result)) {
		
		if( !$config['allow_comments_wysiwyg'] ) {
			
			$row['text'] = $parse->decodeBBCodes( $row['text'], false );
			
		} else {
			$row['text'] = $parse->decodeBBCodes( $row['text'], true, true );
		}

		$row['text'] = $db->safesql( $parse->BB_Parse($parse->process( $row['text'] ), $use_html) );
		
		$db->query( "UPDATE " . PREFIX . "_comments SET text='{$row['text']}' WHERE id='{$row['id']}'" );
		
		$step++;
	}
	
	clear_cache();
	$rebuildcount = $startfrom + $step;
	$buffer = "{\"status\": \"ok\",\"rebuildcount\": {$rebuildcount}}";
	echo $buffer;
	
} else {


	$parse = new ParseFilter();
	$parse->edit_mode = false;
	$parse->allow_code = false;
	
	$result = $db->query("SELECT p.id, p.short_story, p.full_story, p.xfields, p.title, p.category, p.approve, p.allow_br, p.tags, e.news_id FROM " . PREFIX . "_post p LEFT JOIN " . PREFIX . "_post_extras e ON (p.id=e.news_id) LIMIT ".$startfrom.", ".$count_per_step);
	
	while($row = $db->get_row($result)) {
	
		$row['short_story'] = $parse->decodeBBCodes( $row['short_story'], true, true );
		$row['full_story'] = $parse->decodeBBCodes( $row['full_story'], true, true );
	
		$short_story = $parse->process( $row['short_story'] );
		$full_story = $parse->process( $row['full_story'] );
		$_POST['title'] = $row['title'];
			
		$full_story = $db->safesql( $parse->BB_Parse( $full_story ) );
		$short_story = $db->safesql( $parse->BB_Parse( $short_story ) );
		
		$xf_search_words = array ();
		$tags_cloud = array();
		
		if( $row['tags'] ) {

			$row['tags'] = html_entity_decode($row['tags'], ENT_QUOTES | ENT_XML1, 'UTF-8');

			if (@preg_match("/[\||\<|\>]/", $row['tags'])) $row['tags'] = "";
			else $row['tags'] = htmlspecialchars(strip_tags(stripslashes(trim($row['tags']))), ENT_COMPAT, 'UTF-8');

			if ($row['tags'] ) {

				$temp_array = explode(',', $row['tags'] );

				if (count($temp_array)) {

					foreach ($temp_array as $value) {
						if ( trim($value) ) $tags_cloud[] = $db->safesql( trim($value) );
					}
				}

				if ( count($tags_cloud) ) {
					
					$tags_cloud = array_unique($tags_cloud);
					$row['tags'] = implode(", ", $tags_cloud);

				} else $row['tags'] = "";

			}

		}

		if ($row['xfields']) {
			
			DLEXFields::Init();

			if( isset(DLEXFields::$fields['fields']) AND is_array(DLEXFields::$fields['fields']) AND count(DLEXFields::$fields['fields']) ) {
				
				$_POST['xfield'] = DLEXFields::xfieldsdataload($row['xfields']);

				foreach (DLEXFields::$fields['fields'] as $value) {
					if ($value['type'] != "select" AND $value['type'] != "image" AND $value['type'] != "imagegalery" AND $value['type'] != "video" AND $value['type'] != "audio" AND $value['type'] != "file" AND $value['type'] != "htmljs" AND $value['type'] != "datetime" AND !$value['safe_mode'] AND !$value['use_as_links'] AND isset($_POST['xfield'][$value['name']]) AND $_POST['xfield'][$value['name']]) {
						$_POST['xfield'][$value['name']] = $parse->decodeBBCodes($_POST['xfield'][$value['name']], true, true);
					}
				}

				$_POST['category'] = explode(",", $row['category']);
				$parsed_fields = DLEXFields::Parse($row['xfields']);

				$xf_search_words = $parsed_fields['search_words'];
				$filecontents = $parsed_fields['filecontents'];
				
			} else	$filecontents = '';
	
		} else	$filecontents = '';

		$db->query( "UPDATE " . PREFIX . "_post SET short_story='{$short_story}', full_story='{$full_story}', xfields='{$filecontents}', tags='{$row['tags']}' WHERE id='{$row['id']}'" );

		if ( !$row['news_id'] ) $db->query( "INSERT INTO " . PREFIX . "_post_extras (news_id, allow_rate) VALUES('{$row['id']}', '1')" );

		$db->query( "DELETE FROM " . PREFIX . "_post_extras_cats WHERE news_id = '{$row['id']}'" );

		if( $row['category'] AND $row['approve'] ) {

			$cat_ids = array ();

			$cat_ids_arr = explode( ",", $row['category'] );

			foreach ( $cat_ids_arr as $value ) {

				$cat_ids[] = "('" . $row['id'] . "', '" . intval( $value ) . "')";
				
			}

			$cat_ids = implode( ", ", $cat_ids );
			$db->query( "INSERT INTO " . PREFIX . "_post_extras_cats (news_id, cat_id) VALUES " . $cat_ids );

		}
		
		$db->query( "DELETE FROM " . PREFIX . "_xfsearch WHERE news_id = '{$row['id']}'" );

		if ( count($xf_search_words) AND $row['approve'] ) {
			
			$temp_array = array();
			
			foreach ( $xf_search_words as $value ) {
				
				$temp_array[] = "('" . $row['id'] . "', '" . $value[0] . "', '" . $value[1] . "')";
			}
			
			$xf_search_words = implode( ", ", $temp_array );
			$db->query( "INSERT INTO " . PREFIX . "_xfsearch (news_id, tagname, tagvalue) VALUES " . $xf_search_words );
		}

		$db->query( "DELETE FROM " . PREFIX . "_tags WHERE news_id = '{$row['id']}'" );
		
		if (count($tags_cloud) AND $row['approve']) {

			$temp_array = array();

			foreach ($tags_cloud as $value) {

				$temp_array[] = "('" . $row['id'] . "', '" . $value . "')";
			}

			$tags_cloud = implode(", ", $temp_array);
			$db->query("INSERT INTO " . PREFIX . "_tags (news_id, tag) VALUES " . $tags_cloud);
		}

		$step++;
	}
	
	clear_cache();
	$rebuildcount = $startfrom + $step;
	$buffer = "{\"status\": \"ok\",\"rebuildcount\": {$rebuildcount}}";
	echo $buffer;
}
?>