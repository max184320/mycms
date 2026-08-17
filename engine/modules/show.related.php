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
 File: show.related.php
-----------------------------------------------------
 Use:  view related news
=====================================================
*/

if( !defined('DATALIFEENGINE') ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

if ( $allow_full_cache ) $related_buffer = dle_cache( "related", NEWS_ID.$config['skin'], true ); else $related_buffer = false;

if( $related_buffer === false ) {

	$db->query( "SELECT id, date, short_story, xfields, title, category, alt_name FROM " . PREFIX . "_post WHERE id IN(". RELATED_IDS .") AND approve=1 ORDER BY FIND_IN_SET(id, '". RELATED_IDS ."') LIMIT " . $config['related_number'] );

	$tpl2 = new dle_template();
	$tpl2->dir = TEMPLATE_DIR;
	$tpl2->load_template( 'relatednews.tpl' );
					
	while ( $related = $db->get_row() ) {

		if (isset($showed_news_ids) AND is_array($showed_news_ids)) {
			$showed_news_ids[] = $related['id'];
		}

		$related['date'] = strtotime( $related['date'] );

		if( ! $related['category'] ) {
			$my_cat = "---";
			$my_cat_link = "---";
		} else {
			
			$my_cat = array ();
			$my_cat_link = array ();
			$rel_cat_list = explode( ',', $related['category'] );
			
			if( count( $rel_cat_list ) == 1 ) {
				
				if( $cat_info[$rel_cat_list[0]]['id'] ) {
					$my_cat[] = $cat_info[$rel_cat_list[0]]['name'];
					$my_cat_link = get_categories( $rel_cat_list[0], $config['category_separator'] );
				} else {
					$my_cat_link = "---";
				}
	
			} else {
				
				foreach ( $rel_cat_list as $element ) {
					if( $element AND $cat_info[$element]['id'] ) {
						$my_cat[] = $cat_info[$element]['name'];

						$my_cat_link[] = "<a href=\"" . DLEUrl::BuildUrl('category', ['category' => get_url($element)]) . "\">{$cat_info[$element]['name']}</a>";

					}
				}
				
				if( count( $my_cat_link ) ) {
					$my_cat_link = implode( $config['category_separator'], $my_cat_link );
				} else $my_cat_link = "---";
	
			}
			
			if( count( $my_cat ) ) {
				$my_cat = implode( $config['category_separator'], $my_cat );
			} else $my_cat = "---";
			
		}

		$rel_full_link = DLEUrl::BuildUrl('showfull', ['category' => get_url($related['category']), 'year' => date('Y', $related['date']), 'month' => date('m', $related['date']), 'day' => date('d', $related['date']), 'news_name' => $related['alt_name'], 'newsid' => $related['id']]);
		
		$related['category'] = intval( $related['category'] );
		
		$related['title'] = strip_tags( stripslashes( $related['title'] ) );

		$tpl2->set( '{title}', str_replace("&amp;amp;", "&amp;", htmlspecialchars( $related['title'], ENT_QUOTES, 'UTF-8' ) ) );
		$tpl2->set( '{link}', $rel_full_link );
		$tpl2->set( '{category}', $my_cat );
		$tpl2->set( '{link-category}', $my_cat_link );
	
		$compare_date = compare_days_date($related['date']);

		if( !$compare_date ) {
			
			$tpl2->set( '{date}', $lang['time_heute'] . langdate( ", H:i", $related['date'] ) );
		
		} elseif( $compare_date == 1 ) {
			
			$tpl2->set( '{date}', $lang['time_gestern'] . langdate( ", H:i", $related['date'] ) );
		
		} else {
			
			$tpl2->set( '{date}', langdate( $config['timestamp_active'], $related['date'] ) );
		
		}
		$news_date = $related['date'];
		$tpl2->copy_template = preg_replace_callback ( "#\{date=(.+?)\}#i", "formdate", $tpl2->copy_template );

		$related['short_story'] = stripslashes( $related['short_story'] );
		
		if (stripos ( $related['short_story'], "[hide" ) !== false ) {
			
			$related['short_story'] = preg_replace_callback ( "#\[hide(.*?)\](.+?)\[/hide\]#is", 
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
	
			}, $related['short_story'] );
		}

		if (stripos($related['short_story'], '<dlehide') !== false) {

			$pattern = '#<dlehide\b([^>]*)>(.*?)</dlehide>#is';

			while (preg_match($pattern, $related['short_story'])) {

				$related['short_story'] = preg_replace_callback($pattern,
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
					}, $related['short_story']
				);
			}
		}

		if (stripos ( $tpl2->copy_template, "image-" ) !== false) {

			$images = array();
			preg_match_all('/(img|src)=("|\')[^"\'>]+/i', $related['short_story'], $media);
			$data=preg_replace('/(img|src)("|\'|="|=\')(.*)/i',"$3",$media[0]);
			$img_arr = array('jpg', 'jpeg', 'gif', 'png', 'bmp', 'webp', 'avif', 'svg');

			foreach($data as $url) {
				$info = pathinfo($url);
				if (isset($info['extension'])) {
					if ($info['filename'] == "spoiler-plus" OR $info['filename'] == "spoiler-minus" OR strpos($info['dirname'], 'public/emoticons') !== false) continue;
					$info['extension'] = strtolower($info['extension']);
					if ( in_array($info['extension'], $img_arr) ) array_push($images, $url);
				}
			}

			if ( count($images) ) {
				$i=0;
				foreach($images as $url) {
					$i++;
					$tpl2->copy_template = str_replace( '{image-'.$i.'}', $url, $tpl2->copy_template );
					$tpl2->copy_template = str_replace( '[image-'.$i.']', "", $tpl2->copy_template );
					$tpl2->copy_template = str_replace( '[/image-'.$i.']', "", $tpl2->copy_template );
					$tpl2->copy_template = preg_replace( "#\[not-image-{$i}\](.+?)\[/not-image-{$i}\]#is", "", $tpl2->copy_template );
				}

			}

			$tpl2->copy_template = preg_replace( "#\[image-(.+?)\](.+?)\[/image-(.+?)\]#is", "", $tpl2->copy_template );			
			$tpl2->copy_template = preg_replace( "#\\{image-(.+?)\\}#i", "{THEME}/dleimages/no_image.jpg", $tpl2->copy_template );
			$tpl2->copy_template = preg_replace( "#\[not-image-(.+?)\]#i", "", $tpl2->copy_template );
			$tpl2->copy_template = preg_replace( "#\[/not-image-(.+?)\]#i", "", $tpl2->copy_template );

		}

		if ( preg_match( "#\\{text limit=['\"](.+?)['\"]\\}#i", $tpl2->copy_template, $matches ) ) {
			$tpl2->set( $matches[0], clear_content($related['short_story'], $matches[1]) );
		} else $tpl2->set( '{text}', $related['short_story'] );

		if ( preg_match( "#\\{title limit=['\"](.+?)['\"]\\}#i", $tpl2->copy_template, $matches ) ) {
			$tpl2->set( $matches[0], clear_content($related['title'], $matches[1]) );
		}

		$xfields_in_news = array();
		DLEXFields::Compile($related, $tpl2, $xfields_in_news);

		$tpl2->compile( 'content' );

		if (count($xfields_in_news)) {

			if (stripos($tpl2->result['content'], "[xf") !== false) {

				foreach ($xfields_in_news as $key => $value) {
					$tpl2->result['content'] = str_replace($key, $value, $tpl2->result['content']);
				}
			}
		}
	}

	$related_buffer = $tpl2->result['content'];
	unset($tpl2);
	$db->free();

	if ( $allow_full_cache ) create_cache( "related", $related_buffer, NEWS_ID.$config['skin'], true );
}