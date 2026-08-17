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
 File: topnews.php
-----------------------------------------------------
 Use: view of the rating of articles
=====================================================
*/

if( !defined('DATALIFEENGINE') ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

$tpl->result['topnews'] = dle_cache( "topnews", $config['skin'], true );

if( $tpl->result['topnews'] === false ) {
	
	$attachments = [];
	$this_month = date( 'Y-m-d H:i:s', $_TIME );
	$tpl->result['topnews'] = '';
	$tpl->load_template( 'topnews.tpl' );

	$config['top_number'] = intval($config['top_number']);
	if ($config['top_number'] < 1 ) $config['top_number'] = 10;
	
	$db->query( "SELECT p.id, p.date, p.short_story, p.xfields, p.title, p.category, p.alt_name FROM " . PREFIX . "_post p LEFT JOIN " . PREFIX . "_post_extras e ON (p.id=e.news_id) WHERE p.approve=1 AND p.date >= '$this_month' - INTERVAL 1 MONTH AND p.date < '$this_month' ORDER BY rating DESC, comm_num DESC, news_read DESC, date DESC LIMIT 0,{$config['top_number']}" );
	
	while ( $row = $db->get_row() ) {
		
		$attachments[] = $row['id'];
		$row['date'] = strtotime( $row['date'] );

		if( ! $row['category'] ) {
			$my_cat = "---";
			$my_cat_link = "---";
		} else {
			
			$my_cat = array ();
			$my_cat_link = array ();
			$cat_list = explode( ',', $row['category'] );
		 
			if( count( $cat_list ) == 1 ) {
				
				if( $cat_info[$cat_list[0]]['id'] ) {
					$my_cat[] = $cat_info[$cat_list[0]]['name'];
					$my_cat_link = get_categories( $cat_list[0], $config['category_separator']);
				} else {
					$my_cat_link = "---";
				}
			
			} else {
				
				foreach ( $cat_list as $element ) {
					if( $element AND isset($cat_info[$element]['id']) AND $cat_info[$element]['id'] ) {
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

		$full_link = DLEUrl::BuildUrl('showfull', ['category' => get_url($row['category']), 'year' => date('Y', $row['date']), 'month' => date('m', $row['date']), 'day' => date('d', $row['date']), 'news_name' => $row['alt_name'], 'newsid' => $row['id']]);
		
		$row['category'] = intval( $row['category'] );
		
		$compare_date = compare_days_date( $row['date'], $config['allow_cache'] );

		if( !$compare_date ) {
			
			$tpl->set( '{date}', $lang['time_heute'] . langdate( ", H:i", $row['date'] ) );
		
		} elseif( $compare_date == 1 ) {
			
			$tpl->set( '{date}', $lang['time_gestern'] . langdate( ", H:i", $row['date'] ) );
		
		} else {
			
			$tpl->set( '{date}', langdate( $config['timestamp_active'], $row['date'] ) );
		
		}

		$news_date = $row['date'];
		$tpl->copy_template = preg_replace_callback ( "#\{date=(.+?)\}#i", "formdate", $tpl->copy_template );

		$tpl->set( '{category}', $my_cat );
		$tpl->set( '{link-category}', $my_cat_link );

		$row['title'] = stripslashes( $row['title'] );

		$row['title'] = str_replace( "{", "&#123;", $row['title'] );

		$tpl->set( '{title}', str_replace("&amp;amp;", "&amp;", htmlspecialchars( $row['title'], ENT_QUOTES, 'UTF-8' ) ) );
		
		if ( preg_match( "#\\{title limit=['\"](.+?)['\"]\\}#i", $tpl->copy_template, $matches ) ) {
			$tpl->set( $matches[0], clear_content($row['title'], $matches[1]) );
		}


		$tpl->set( '{link}', $full_link );

		$row['short_story'] = stripslashes( $row['short_story'] );
		$row['xfields'] = stripslashes( $row['xfields'] );
		
		if (stripos ( $row['short_story'], "[hide" ) !== false ) {
			
			$row['short_story'] = preg_replace_callback ( "#\[hide(.*?)\](.+?)\[/hide\]#is", 
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
	
			}, $row['short_story'] );
		}

		if (stripos($row['short_story'], '<dlehide') !== false) {

			$pattern = '#<dlehide\b([^>]*)>(.*?)</dlehide>#is';

			while (preg_match($pattern, $row['short_story'])) {

				$row['short_story'] = preg_replace_callback($pattern,
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
					}, $row['short_story']
				);
			}
		}

		if (stripos ( $tpl->copy_template, "{image-" ) !== false) {

			$images = array();
			preg_match_all('/(img|src)=("|\')[^"\'>]+/i', $row['short_story'].$row['xfields'], $media);
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
					$tpl->copy_template = str_replace( '{image-'.$i.'}', $url, $tpl->copy_template );
					$tpl->copy_template = str_replace( '[image-'.$i.']', "", $tpl->copy_template );
					$tpl->copy_template = str_replace( '[/image-'.$i.']', "", $tpl->copy_template );
					$tpl->copy_template = preg_replace( "#\[not-image-{$i}\](.+?)\[/not-image-{$i}\]#is", "", $tpl->copy_template );
				}

			}

			$tpl->copy_template = preg_replace( "#\[image-(.+?)\](.+?)\[/image-(.+?)\]#is", "", $tpl->copy_template );
			$tpl->copy_template = preg_replace( "#\\{image-(.+?)\\}#i", "{THEME}/dleimages/no_image.jpg", $tpl->copy_template );
			$tpl->copy_template = preg_replace( "#\[not-image-(.+?)\]#i", "", $tpl->copy_template );
			$tpl->copy_template = preg_replace( "#\[/not-image-(.+?)\]#i", "", $tpl->copy_template );
			
		}

		if ($config['image_lazy']) $row['short_story'] = preg_replace_callback ( "#<(img|iframe)(.+?)>#i", "enable_lazyload", $row['short_story'] );
		
		$tpl->set( '{text}', $row['short_story'] );

		if ( preg_match( "#\\{text limit=['\"](.+?)['\"]\\}#i", $tpl->copy_template, $matches ) ) {
			$tpl->set( $matches[0], clear_content($row['short_story'], $matches[1]) );
		}
		
		$xfields_in_news = array();
		DLEXFields::Compile($row, $tpl, $xfields_in_news);

		$tpl->compile( 'topnews' );

		if (count($xfields_in_news)) {

			if (stripos($tpl->result['content'], "[xf") !== false) {

				foreach ($xfields_in_news as $key => $value) {
					$tpl->result['content'] = str_replace($key, $value, $tpl->result['content']);
				}
			}
		}
				
	}

	$tpl->clear();	
	$db->free();

	if ($config['files_allow'] && count($attachments) && strpos($tpl->result['topnews'], "[attachment=") !== false) {
		$tpl->result['topnews'] = show_attach($tpl->result['topnews'], $attachments);
	}

	if ($config['allow_links'] && function_exists('replace_links') && isset($replace_links['news'])){
		$tpl->result['topnews'] = replace_links($tpl->result['topnews'], $replace_links['news']);
	}

	create_cache( "topnews", $tpl->result['topnews'], $config['skin'], true );
}
