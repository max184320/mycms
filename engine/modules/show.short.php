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
 File: show.short.php
-----------------------------------------------------
 Use:  view short news
=====================================================
*/

if( !defined('DATALIFEENGINE') ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

if( $allow_active_news ) {

	$news_count = $cstart;
	$global_news_count = 0;
	$news_found = false;
	$fill_opengraph = true;
	$tpl->news_mode = true;
	$page_description = "";
	$page_keywords = "";

	if( $view_template != "rss" ) {
		if( $category_id and $cat_info[$category_id]['short_tpl'] != '' ) $tpl->load_template( $cat_info[$category_id]['short_tpl'] . '.tpl' );
		else $tpl->load_template( 'shortstory.tpl' );
	}

	if( $config['allow_banner'] AND count( $banners ) AND isset( $ban_short ) ) {
		
		$news_c = 1;
		$banners_topz = $banners_cenz = $banners_downz = '';
		
		if ( isset($ban_short['top']) AND is_array($ban_short['top']) AND count($ban_short['top']) ) {
			for($indx = 0, $max = sizeof( $ban_short['top'] ), $banners_topz = ''; $indx < $max; $indx ++) {
				if( isset($ban_short['top'][$indx]['zakr']) AND $ban_short['top'][$indx]['zakr'] ) {
					$banners_topz .= $ban_short['top'][$indx]['text'];
					unset( $ban_short['top'][$indx] );
				}
			}
		}

		if ( isset($ban_short['cen']) AND is_array($ban_short['cen']) AND count($ban_short['cen']) ) {		
			for($indx = 0, $max = sizeof( $ban_short['cen'] ), $banners_cenz = ''; $indx < $max; $indx ++) {
				if( isset($ban_short['cen'][$indx]['zakr']) AND $ban_short['cen'][$indx]['zakr'] ) {
					$banners_cenz .= $ban_short['cen'][$indx]['text'];
					unset( $ban_short['cen'][$indx] );
				}
			}
		}
		
		if ( isset($ban_short['down']) AND is_array($ban_short['down']) AND count($ban_short['down']) ) {		
			for($indx = 0, $max = sizeof( $ban_short['down'] ), $banners_downz = ''; $indx < $max; $indx ++) {
				if( isset($ban_short['down'][$indx]['zakr']) AND $ban_short['down'][$indx]['zakr'] ) {
					$banners_downz .= $ban_short['down'][$indx]['text'];
					unset( $ban_short['down'][$indx] );
				}
			}
		}
		
		$middle = floor( $config['news_number'] / 2 ) + 1;
		
		if($middle < 2 ) $middle = 2;
		
		$middle_s = round( $middle / 2 );
		
		if($middle_s < 2 ) $middle_s = 2;
		
		if($middle_s == $middle ) {
			if( (is_array($ban_short['cen']) AND count($ban_short['cen'])) OR  $banners_cenz )  $middle_s = 0;
		}
		
		$middle_e = floor( $middle + (($config['news_number'] - $middle) / 2) + 1 );
		
		if($middle AND $middle_e == $middle ) {
			if( (is_array($ban_short['cen']) AND count($ban_short['cen'])) OR  $banners_cenz )  $middle_e = 0;
		}
		
		if($middle_s AND $middle_e == $middle_s ) {
			if( (is_array($ban_short['top']) AND count($ban_short['top'])) OR  $banners_topz )  $middle_e = 0;
		}
		
	}

	$sql_result = $db->query( $sql_select );
	
	$attachments = [];
	
	while ( $row = $db->get_row( $sql_result ) ) {
		
		$news_found = true;
		$attachments[] = $row['id'];
		$row['date'] = strtotime( $row['date'] );
		$news_count++;
		$global_news_count++;

		if (isset($showed_news_ids) AND is_array($showed_news_ids)) {
			$showed_news_ids[] = $row['id'];
		}

		if( $row['editdate'] AND $row['editdate'] > $_DOCUMENT_DATE ) $_DOCUMENT_DATE = $row['editdate'];
		elseif( $row['date'] > $_DOCUMENT_DATE ) $_DOCUMENT_DATE = $row['date'];
		
		if( $config['allow_banner'] AND count( $banners ) ) {
			
			foreach ( $banners as $name => $value ) {
				$tpl->copy_template = str_replace( "{banner_" . $name . "}", $value, $tpl->copy_template );

				if ( $value ) {
					$tpl->copy_template = str_replace ( "[banner_" . $name . "]", "", $tpl->copy_template );
					$tpl->copy_template = str_replace ( "[/banner_" . $name . "]", "", $tpl->copy_template );
				}
			}
		}

		$tpl->set_block( "'{banner_(.*?)}'si", "" );
		$tpl->set_block ( "'\\[banner_(.*?)\\](.*?)\\[/banner_(.*?)\\]'si", "" );

		if( isset( $middle ) ) {

			if( $news_c == $middle_s ) {
				$tpl->copy_template = bannermass( $banners_topz, $ban_short['top'] ).$tpl->copy_template;
			} else if( $news_c == $middle ) {
				$tpl->copy_template = bannermass( $banners_cenz, $ban_short['cen'] ).$tpl->copy_template;
			} else if( $news_c == $middle_e ) {
				$tpl->copy_template = bannermass( $banners_downz, $ban_short['down'] ).$tpl->copy_template;
			}
			
			$news_c ++;
		}

		$allow_comments_in_cat = true;

		if( !$row['category'] ) {
			
			$my_cat = "---";
			$my_cat_link = "---";
			
			$tpl->set( '[not-has-category]', "" );
			$tpl->set( '[/not-has-category]', "" );
			$tpl->set_block( "'\\[has-category\\](.*?)\\[/has-category\\]'si", "" );
			
		} else {
			
			$my_cat = array ();
			$my_cat_link = array ();
			$cat_list = $row['cats'] = explode( ',', $row['category'] );

			$tpl->set( '[has-category]', "" );
			$tpl->set( '[/has-category]', "" );
			$tpl->set_block( "'\\[not-has-category\\](.*?)\\[/not-has-category\\]'si", "" );
			
			if( count( $cat_list ) == 1 ) {
				
				if( isset($cat_info[$cat_list[0]]['id']) AND $cat_info[$cat_list[0]]['id'] ) {
					
					$my_cat[] = $cat_info[$cat_list[0]]['name'];
					$my_cat_link = get_categories( $cat_list[0], $config['category_separator']);

					if ( $cat_info[$cat_list[0]]['disable_comments'] ) $allow_comments_in_cat = false;
					
				} else $my_cat_link = "---";
			
			} else {
				
				foreach ( $cat_list as $element ) {
					
					if( $element AND isset($cat_info[$element]['id']) AND $cat_info[$element]['id'] ) {

						if ($cat_info[$element]['disable_comments']) $allow_comments_in_cat = false;

						$my_cat[] = $cat_info[$element]['name'];

						$my_cat_link[] = "<a href=\"" . DLEUrl::BuildUrl('category', ['category' => get_url($element)]) . "\">{$cat_info[$element]['name']}</a>";

					}
					
				}
				
				if( count( $my_cat_link ) ) {
					$my_cat_link = implode( $config['category_separator'], $my_cat_link );
				} else $my_cat_link = "---";

			}
			
			if( count( $my_cat ) ) {
				if ($view_template == "rss") {
					$my_cat = implode(', ', $my_cat);
				} else $my_cat = implode($config['category_separator'], $my_cat);
			} else $my_cat = "---";
			
		}

		$url_cat = $category_id;
	
		if (stripos ( $tpl->copy_template, "[category=" ) !== false) {
			$tpl->copy_template = preg_replace_callback ( "#\\[(category)=(.+?)\\](.*?)\\[/category\\]#is", "check_category", $tpl->copy_template );
		}
		
		if (stripos ( $tpl->copy_template, "[not-category=" ) !== false) {
			$tpl->copy_template = preg_replace_callback ( "#\\[(not-category)=(.+?)\\](.*?)\\[/not-category\\]#is", "check_category", $tpl->copy_template );
		}
	
		$category_id = $row['category'];
	
		if( strpos( $tpl->copy_template, "[catlist=" ) !== false ) {
			$tpl->copy_template = preg_replace_callback ( "#\\[(catlist)=(.+?)\\](.*?)\\[/catlist\\]#is", "check_category", $tpl->copy_template );
		}
								
		if( strpos( $tpl->copy_template, "[not-catlist=" ) !== false ) {
			$tpl->copy_template = preg_replace_callback ( "#\\[(not-catlist)=(.+?)\\](.*?)\\[/not-catlist\\]#is", "check_category", $tpl->copy_template );
		}
	
		$temp_rating = $config['rating_type'];
		$config['rating_type'] = if_category_rating( $row['category'] );
		
		if ( $config['rating_type'] === false ) {
			$config['rating_type'] = $temp_rating;
		}
		
		$category_id = $url_cat;
		
		$full_link = DLEUrl::BuildUrl('showfull', ['category' => get_url($row['category']), 'year' => date('Y', $row['date']), 'month' => date('m', $row['date']), 'day' => date('d', $row['date']), 'news_name' => $row['alt_name'], 'newsid' => $row['id'] ]);

		if ( $row['category'] ) {
			
			$tpl->set('{category-url}', DLEUrl::BuildUrl('category', ['category' => get_url($row['category'])]));
			
		} else $tpl->set( '{category-url}', "#" );	
	

		$row['category'] = intval( $row['category'] );
		
		if( !$allow_comments_in_cat ) $row['comm_num'] = 0;

		$news_find = array ('{comments-num}' => number_format($row['comm_num'], 0, ',', ' '), '{views}' => number_format($row['news_read'], 0, ',', ' '), '{category}' => $my_cat, '{link-category}' => $my_cat_link, '{news-id}' => $row['id'] );
		
		$tpl->set( '', $news_find );
	
		if( $row['category'] AND isset($cat_info[$row['category']]['icon']) AND $cat_info[$row['category']]['icon'] ) {
			
			$tpl->set( '{category-icon}', $cat_info[$row['category']]['icon'] );
			$tpl->set( '[category-icon]', "" );
			$tpl->set( '[/category-icon]', "" );
			$tpl->set_block( "'\\[not-category-icon\\](.*?)\\[/not-category-icon\\]'si", "" );
			
		} else {
			
			$tpl->set( '{category-icon}', "{THEME}/dleimages/no_icon.gif" );
			$tpl->set( '[not-category-icon]', "" );
			$tpl->set( '[/not-category-icon]', "" );
			$tpl->set_block( "'\\[category-icon\\](.*?)\\[/category-icon\\]'si", "" );
		}

		$compare_date = compare_days_date($row['date'],  $short_news_cache);

		if( !$compare_date ) {
			
			$tpl->set( '{date}', $lang['time_heute'] . langdate( ", H:i", $row['date'], $short_news_cache ) );
		
		} elseif( $compare_date == 1 ) {
			
			$tpl->set( '{date}', $lang['time_gestern'] . langdate( ", H:i", $row['date'], $short_news_cache ) );
		
		} else {
			
			$tpl->set( '{date}', langdate( $config['timestamp_active'], $row['date'], $short_news_cache ) );
		
		}

		$news_date = $row['date'];
		$tpl->copy_template = preg_replace_callback ( "#\{date=(.+?)\}#i", "formdate", $tpl->copy_template );

		if (strpos($tpl->copy_template, "[new]") !== false OR strpos($tpl->copy_template, "[not-new]") !== false ) {

			if( $config['post_new'] AND compare_days_date($row['date'],  $short_news_cache, true) < $config['post_new'] ) {
				$tpl->set('[new]', "");
				$tpl->set('[/new]', "");
				$tpl->set_block("'\\[not-new\\](.*?)\\[/not-new\\]'si", "");
			} else {
				$tpl->set('[not-new]', "");
				$tpl->set('[/not-new]', "");
				$tpl->set_block("'\\[new\\](.*?)\\[/new\\]'si", "");
			}

		}

		if (strpos($tpl->copy_template, "[updated]") !== false or strpos($tpl->copy_template, "[not-updated]") !== false) {

			if ($config['post_updated'] AND $row['editdate'] AND $row['view_edit'] AND compare_days_date($row['date'],  $short_news_cache, true) > $config['post_new'] AND compare_days_date($row['editdate'],  $short_news_cache, true) < $config['post_updated'] ) {
				$tpl->set('[updated]', "");
				$tpl->set('[/updated]', "");
				$tpl->set_block("'\\[not-updated\\](.*?)\\[/not-updated\\]'si", "");
			} else {
				$tpl->set('[not-updated]', "");
				$tpl->set('[/not-updated]', "");
				$tpl->set_block("'\\[updated\\](.*?)\\[/updated\\]'si", "");
			}
		}

		if (strpos ( $tpl->copy_template, "[newscount=" ) !== false) {
			$tpl->copy_template = preg_replace_callback ( "#\\[(newscount)=(.+?)\\](.*?)\\[/newscount\\]#is", "check_newscount", $tpl->copy_template );
		}

		if (strpos ( $tpl->copy_template, "[not-newscount=" ) !== false) {
			$tpl->copy_template = preg_replace_callback ( "#\\[(not-newscount)=(.+?)\\](.*?)\\[/not-newscount\\]#is", "check_newscount", $tpl->copy_template );
		}

		if ( $row['fixed'] ) {

			$tpl->set( '[fixed]', "" );
			$tpl->set( '[/fixed]', "" );
			$tpl->set_block( "'\\[not-fixed\\](.*?)\\[/not-fixed\\]'si", "" );

		} else {

			$tpl->set( '[not-fixed]', "" );
			$tpl->set( '[/not-fixed]', "" );
			$tpl->set_block( "'\\[fixed\\](.*?)\\[/fixed\\]'si", "" );
		}
		
		if ( $row['comm_num'] ) {
			
			$tpl->set( '[comments]', "" );
			$tpl->set( '[/comments]', "" );
			$tpl->set_block( "'\\[not-comments\\](.*?)\\[/not-comments\\]'si", "" );

		} else {
				
			$tpl->set( '[not-comments]', "" );
			$tpl->set( '[/not-comments]', "" );
			$tpl->set_block( "'\\[comments\\](.*?)\\[/comments\\]'si", "" );
		}

		if ( $row['votes'] ) {

			$tpl->set( '[poll]', "" );
			$tpl->set( '[/poll]', "" );
			$tpl->set_block( "'\\[not-poll\\](.*?)\\[/not-poll\\]'si", "" );

		} else {

			$tpl->set( '[not-poll]', "" );
			$tpl->set( '[/not-poll]', "" );
			$tpl->set_block( "'\\[poll\\](.*?)\\[/poll\\]'si", "" );
		}		

		if( strpos( $tpl->copy_template, "{poll}" ) !== false AND $view_template != "rss" ) {
	
			if( $row['votes'] ) {
	
				include (DLEPlugins::Check(ENGINE_DIR . '/modules/poll.php'));
	
				$tpl->set( '{poll}', $tpl->result['poll'] );
	
			} else {
	
				$tpl->set( '{poll}', '' );
	
			}
		}

		if( $row['view_edit'] and $row['editdate'] ) {

			$compare_date = compare_days_date($row['editdate'],  $short_news_cache);

			if( !$compare_date ) {
				
				$tpl->set( '{edit-date}', $lang['time_heute'] . langdate( ", H:i", $row['editdate'], $short_news_cache ) );
			
			} elseif( $compare_date == 1 ) {
				
				$tpl->set( '{edit-date}', $lang['time_gestern'] . langdate( ", H:i", $row['editdate'], $short_news_cache ) );
			
			} else {
				
				$tpl->set( '{edit-date}', langdate( $config['timestamp_active'], $row['editdate'], $short_news_cache ) );
			
			}

			$news_date = $row['editdate'];
			$tpl->copy_template = preg_replace_callback("#\{edit-date=(.+?)\}#i", "formdate", $tpl->copy_template);

			$tpl->set( '{editor}', $row['editor'] );
			$tpl->set( '{edit-reason}', $row['reason'] );
			
			if( $row['reason'] ) {
				
				$tpl->set( '[edit-reason]', "" );
				$tpl->set( '[/edit-reason]', "" );
			
			} else $tpl->set_block( "'\\[edit-reason\\](.*?)\\[/edit-reason\\]'si", "" );
			
			$tpl->set( '[edit-date]', "" );
			$tpl->set( '[/edit-date]', "" );
		
		} else {
			
			$tpl->set( '{edit-date}', "" );
			$tpl->set( '{editor}', "" );
			$tpl->set( '{edit-reason}', "" );
			$tpl->set_block( "'\\[edit-date\\](.*?)\\[/edit-date\\]'si", "" );
			$tpl->set_block( "'\\[edit-reason\\](.*?)\\[/edit-reason\\]'si", "" );
		}
		
		if( $config['allow_tags'] AND $row['tags'] ) {
			
			$tpl->set( '[tags]', "" );
			$tpl->set( '[/tags]', "" );
			
			$tags = array ();
			
			$row['tags'] = explode( ",", $row['tags'] );
			
			foreach ( $row['tags'] as $value ) {
				
				$value = trim( $value );
				$url_tag = str_replace(array("&#039;", "&quot;", "&amp;", "/"), array("'", '"', "&", "&frasl;"), $value);

				$tags[] = "<a href=\"" . DLEUrl::BuildUrl('tags', ['tag' => rawurlencode(dle_strtolower($url_tag))]) . "\">" . $value . "</a>";
			
			}
			
			$tpl->set( '{tags}', implode( $config['tags_separator'], $tags ) );
		
		} else {
			
			$tpl->set_block( "'\\[tags\\](.*?)\\[/tags\\]'si", "" );
			$tpl->set( '{tags}', "" );
		
		}
		
		if ( $config['rating_type'] == "1" ) {
				$tpl->set( '[rating-type-2]', "" );
				$tpl->set( '[/rating-type-2]', "" );
				$tpl->set_block( "'\\[rating-type-1\\](.*?)\\[/rating-type-1\\]'si", "" );
				$tpl->set_block( "'\\[rating-type-3\\](.*?)\\[/rating-type-3\\]'si", "" );
				$tpl->set_block( "'\\[rating-type-4\\](.*?)\\[/rating-type-4\\]'si", "" );
		} elseif ( $config['rating_type'] == "2" ) {
				$tpl->set( '[rating-type-3]', "" );
				$tpl->set( '[/rating-type-3]', "" );
				$tpl->set_block( "'\\[rating-type-1\\](.*?)\\[/rating-type-1\\]'si", "" );
				$tpl->set_block( "'\\[rating-type-2\\](.*?)\\[/rating-type-2\\]'si", "" );
				$tpl->set_block( "'\\[rating-type-4\\](.*?)\\[/rating-type-4\\]'si", "" );
		} elseif ( $config['rating_type'] == "3" ) {
				$tpl->set( '[rating-type-4]', "" );
				$tpl->set( '[/rating-type-4]', "" );
				$tpl->set_block( "'\\[rating-type-1\\](.*?)\\[/rating-type-1\\]'si", "" );
				$tpl->set_block( "'\\[rating-type-2\\](.*?)\\[/rating-type-2\\]'si", "" );
				$tpl->set_block( "'\\[rating-type-3\\](.*?)\\[/rating-type-3\\]'si", "" );
		} else {
				$tpl->set( '[rating-type-1]', "" );
				$tpl->set( '[/rating-type-1]', "" );
				$tpl->set_block( "'\\[rating-type-4\\](.*?)\\[/rating-type-4\\]'si", "" );
				$tpl->set_block( "'\\[rating-type-3\\](.*?)\\[/rating-type-3\\]'si", "" );
				$tpl->set_block( "'\\[rating-type-2\\](.*?)\\[/rating-type-2\\]'si", "" );	
		}
		
		if( $row['allow_rate'] ) {
			
			if( $config['short_rating'] AND $user_group[$member_id['user_group']]['allow_rating'] ) {
				
				$tpl->set( '{rating}', ShowRating( $row['id'], $row['rating'], $row['vote_num'], 1 ) );
				
				if ( $config['rating_type'] ) {
					
					$tpl->set( '[rating-plus]', "<a href=\"#\" onclick=\"doRate('plus', '{$row['id']}'); return false;\" >" );
					$tpl->set( '[/rating-plus]', '</a>' );
					
					if ( $config['rating_type'] == "2" OR $config['rating_type'] == "3" ) {
						
						$tpl->set( '[rating-minus]', "<a href=\"#\" onclick=\"doRate('minus', '{$row['id']}'); return false;\" >" );
						$tpl->set( '[/rating-minus]', '</a>' );
						
					} else {
						$tpl->set_block( "'\\[rating-minus\\](.*?)\\[/rating-minus\\]'si", "" );
					}
					
				} else {
					$tpl->set_block( "'\\[rating-plus\\](.*?)\\[/rating-plus\\]'si", "" );
					$tpl->set_block( "'\\[rating-minus\\](.*?)\\[/rating-minus\\]'si", "" );
				}
				
			} else {
				
				$tpl->set( '{rating}', ShowRating( $row['id'], $row['rating'], $row['vote_num'], 0 ) );
				$tpl->set_block( "'\\[rating-plus\\](.*?)\\[/rating-plus\\]'si", "" );
				$tpl->set_block( "'\\[rating-minus\\](.*?)\\[/rating-minus\\]'si", "" );
				
			}
			
			if( $row['vote_num'] ) $ratingscore = str_replace( ',', '.', round( ($row['rating'] / $row['vote_num']), 1 ) );
			else $ratingscore = 0;

			$tpl->set( '{ratingscore}', $ratingscore );
			
			$dislikes = ($row['vote_num'] - $row['rating'])/2;
			$likes = $row['vote_num'] - $dislikes;
			
			$tpl->set( '{likes}', "<span data-likes-id=\"".$row['id']."\">".$likes."</span>" );
			$tpl->set( '{dislikes}', "<span data-dislikes-id=\"".$row['id']."\">".$dislikes."</span>" );
			$tpl->set( '{vote-num}', "<span data-vote-num-id=\"".$row['id']."\">".$row['vote_num']."</span>" );
			$tpl->set( '[rating]', "" );
			$tpl->set( '[/rating]', "" );
		
		} else {
			
			$tpl->set( '{rating}', "" );
			$tpl->set( '{vote-num}', "" );
			$tpl->set( '{likes}', "" );
			$tpl->set( '{dislikes}', "" );
			$tpl->set( '{ratingscore}', "" );
			$tpl->set_block( "'\\[rating\\](.*?)\\[/rating\\]'si", "" );
			$tpl->set_block( "'\\[rating-plus\\](.*?)\\[/rating-plus\\]'si", "" );
			$tpl->set_block( "'\\[rating-minus\\](.*?)\\[/rating-minus\\]'si", "" );
		}
		
		$config['rating_type'] = $temp_rating;
		
		$go_page = DLEUrl::BuildUrl('user', ['user' => rawurlencode($row['autor']) ]);

		$tpl->set('[day-news]', "<a href=\"" . DLEUrl::BuildUrl('date.day', ['year' => date('Y', $row['date']), 'month' => date('m', $row['date']), 'day' => date('d', $row['date'])]) . "\">");
		$tpl->set( '[/day-news]', "</a>" );
		$tpl->set( '[profile]', "<a href=\"" . $go_page . "\">" );
		$tpl->set( '[/profile]', "</a>" );
		$tpl->set_block( "'\\[not-news\\](.*?)\\[/not-news\\]'si", "" );

		$tpl->set( '{login}', $row['autor'] );
		
		$tpl->set( '{author}', "<a onclick=\"ShowProfile('" . rawurlencode( $row['autor'] ) . "', '" . $go_page . "', '" . $user_group[$member_id['user_group']]['admin_editusers'] . "'); return false;\" href=\"" . $go_page . "\">" . $row['autor'] . "</a>" );
		
		$_SESSION['referrer'] = htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8');

		if( $allow_userinfo AND ($member_id['name'] == $row['autor'] AND !$user_group[$member_id['user_group']]['allow_all_edit']) ) {

			$tpl->set( '[edit]', "<a href=\"" . $config['http_home_url'] . "index.php?do=addnews&id=" . $row['id'] . "\" >" );
			$tpl->set( '[/edit]', "</a>" );

		} elseif( $is_logged AND (($member_id['name'] == $row['autor'] AND $user_group[$member_id['user_group']]['allow_edit']) OR $user_group[$member_id['user_group']]['allow_all_edit']) ) {
			
			if( $member_id['name'] == $row['autor'] AND $user_group[$member_id['user_group']]['allow_edit'] AND $user_group[$member_id['user_group']]['moderation'] ) {
				$allow_only_this_delete = true;
			} else { $allow_only_this_delete = false; }

			$tpl->set( '[edit]', "<a onclick=\"return dropdownmenu(this, event, MenuNewsBuild('" . $row['id'] . "', 'short', " . $allow_only_this_delete . "), '170px')\" href=\"#\">" );
			$tpl->set( '[/edit]', "</a>" );
			$allow_comments_ajax = true;

		} else $tpl->set_block( "'\\[edit\\](.*?)\\[/edit\\]'si", "" );

		if( $is_logged AND $user_group[$member_id['user_group']]['moderation'] AND (($member_id['name'] == $row['autor'] AND $user_group[$member_id['user_group']]['allow_edit']) OR $user_group[$member_id['user_group']]['allow_all_edit']) ) {
			$tpl->set('[del]', "<a onclick=\"dle_news_delete ('" . $row['id'] . "'); return false;\" href=\"#\">");
			$tpl->set('[/del]', "</a>");
		} else {
			$tpl->set_block("'\\[del\\](.*?)\\[/del\\]'si", "");
		}

		if( !$row['full_story'] AND $config['hide_full_link'] ) {
			$tpl->set_block("'\\[full-link\\](.*?)\\[/full-link\\]'si", "");
		} else {	
			$tpl->set( '[full-link]', "<a href=\"" . $full_link . "\">" );
			$tpl->set( '[/full-link]', "</a>" );
		}
		
		$tpl->set( '{full-link}', $full_link );
		
		if( $row['allow_comm'] OR (!$row['allow_comm'] AND $row['comm_num']) ) {
			
			$tpl->set( '[com-link]', "<a href=\"" . $full_link . "#comment\">" );
			$tpl->set( '[/com-link]', "</a>" );
		
		} else $tpl->set_block( "'\\[com-link\\](.*?)\\[/com-link\\]'si", "" );
		
		if( $is_logged ) {
			
				$tpl->set( '{favorites}', "{-favorites-{$row['id']}}" );
				$tpl->set( '[add-favorites]', "[add-favorites-{$row['id']}]" );
				$tpl->set( '[/add-favorites]', "[/add-favorites-{$row['id']}]" );
				$tpl->set( '[del-favorites]', "[del-favorites-{$row['id']}]" );
				$tpl->set( '[/del-favorites]', "[/del-favorites-{$row['id']}]" );
		
		} else {
			
			$tpl->set( '{favorites}', "" );
			$tpl->set_block( "'\\[add-favorites\\](.*?)\\[/add-favorites\\]'si", "" );
			$tpl->set_block( "'\\[del-favorites\\](.*?)\\[/del-favorites\\]'si", "" );
			
		}

		if ($user_group[$member_id['user_group']]['allow_complaint_news']) {
			$tpl->set('[complaint]', "<a href=\"javascript:AddComplaint('" . $row['id'] . "', 'news')\">");
			$tpl->set('[/complaint]', "</a>");
		} else {
			$tpl->set_block("'\\[complaint\\](.*?)\\[/complaint\\]'si", "");
		}
		
		if( $allow_userinfo) {
			
			$tpl->set( '{approve}', $lang['approve'] );
		
		} else $tpl->set( '{approve}', "" );
		
		$row['short_story'] = stripslashes($row['short_story']);
		$row['xfields'] = stripslashes($row['xfields']);

		$images = array();
		preg_match_all('/(img|src)=("|\')[^"\'>]+/i', $row['short_story'] . $row['xfields'], $media);
		$data = preg_replace('/(img|src)("|\'|="|=\')(.*)/i', "$3", $media[0]);
		$img_arr = array('jpg', 'jpeg', 'gif', 'png', 'bmp', 'webp', 'avif', 'svg');

		foreach ($data as $url) {
			$info = pathinfo($url);
			if (isset($info['extension'])) {
				if ($info['filename'] == "spoiler-plus" or $info['filename'] == "spoiler-minus" or strpos($info['dirname'], 'public/emoticons') !== false) continue;
				$info['extension'] = strtolower($info['extension']);
				if (in_array($info['extension'], $img_arr)) array_push($images, $url);
			}
		}

		if (count($images) AND (!isset($social_tags['image']) OR !$social_tags['image']) ) {
			$social_tags['image'] = str_replace("/thumbs/", "/", $images[0]);
			$social_tags['image'] = str_replace("/medium/", "/", $social_tags['image']);
		}

		if (stripos($tpl->copy_template, "image-") !== false) {

			if (count($images)) {
				$i_count = 0;
				foreach ($images as $url) {
					$i_count++;
					$tpl->copy_template = str_replace('{image-' . $i_count . '}', $url, $tpl->copy_template);
					$tpl->copy_template = str_replace('[image-' . $i_count . ']', "", $tpl->copy_template);
					$tpl->copy_template = str_replace('[/image-' . $i_count . ']', "", $tpl->copy_template);
					$tpl->copy_template = preg_replace("#\[not-image-{$i_count}\](.+?)\[/not-image-{$i_count}\]#is", "", $tpl->copy_template);
				}
				$social_tags['image'] = str_replace("/thumbs/", "/", $images[0]);
				$social_tags['image'] = str_replace("/medium/", "/", $social_tags['image']);
			}

			$tpl->copy_template = preg_replace("#\[image-(.+?)\](.+?)\[/image-(.+?)\]#is", "", $tpl->copy_template);
			$tpl->copy_template = preg_replace("#\\{image-(.+?)\\}#i", "{THEME}/dleimages/no_image.jpg", $tpl->copy_template);
			$tpl->copy_template = preg_replace("#\[not-image-(.+?)\]#i", "", $tpl->copy_template);
			$tpl->copy_template = preg_replace("#\[/not-image-(.+?)\]#i", "", $tpl->copy_template);
		}

		$all_xf_content = array();
		$xfields_in_news = array();
		
		if( $fill_opengraph ) DLEXFields::$fill_opengraph = true;

		DLEXFields::Compile($row, $tpl, $xfields_in_news, $all_xf_content);
		DLEXFields::$fill_opengraph = false;
		
		if( isset($social_tags['image']) AND $social_tags['image'] ) $fill_opengraph = false;

		if( !$page_description ) {
			
			if (count($all_xf_content)) $all_xf_content = implode(" ", $all_xf_content);
			else $all_xf_content = "";

			$page_description = clear_content( $row['short_story']." ".$all_xf_content, 0, false );
			$page_keywords = get_keywords_from_clear_content( $page_description );

			if (dle_strlen($page_description) > 300) {

				$page_description = dle_substr($page_description, 0, 300);

				if (($temp_dmax = dle_strrpos($page_description, ' '))) $page_description = dle_substr($page_description, 0, $temp_dmax);
			}

		}
		
		unset($all_xf_content);
		
		$row['title'] = stripslashes( $row['title'] );
		$tpl->set( '{title}', str_replace("&amp;amp;", "&amp;",  htmlspecialchars( $row['title'], ENT_QUOTES, 'UTF-8' ) ) );

		if ( preg_match( "#\\{title limit=['\"](.+?)['\"]\\}#i", $tpl->copy_template, $matches ) ) {
			$tpl->set( $matches[0], clear_content($row['title'], $matches[1]) );
		}

		if( $view_template == "rss" ) {
			
			$tpl->set( '{rsslink}', $full_link );
			$tpl->set( '{rssauthor}', $row['autor'] );
			$tpl->set( '{rssdate}', date( "r", $row['date'] ) );
			
			$row['full_story'] = stripslashes( $row['full_story'] );
			if( strlen($row['full_story']) < 13 ) $row['full_story'] = $row['short_story'];

			$images = array();
			preg_match_all('/(img|src)=("|\')[^"\'>]+/i', $row['full_story'], $media);
			$data=preg_replace('/(img|src)("|\'|="|=\')(.*)/i',"$3",$media[0]);
			$img_arr = array('jpg', 'jpeg', 'gif', 'png', 'bmp', 'webp', 'avif', 'svg');

			foreach($data as $url) {
				$info = pathinfo($url);
				if (isset($info['extension'])) {
					if ($info['filename'] == "spoiler-plus" OR $info['filename'] == "spoiler-minus" OR strpos($info['dirname'], 'public/emoticons') !== false) continue;
					$info['extension'] = strtolower($info['extension']);
					if ( in_array($info['extension'], $img_arr) ) { if($info['extension'] == 'jpg') $info['extension'] ='jpeg'; array_push($images, "<enclosure url=\"{$url}\" type=\"image/{$info['extension']}\" />"); }
				}
			}

			if ( count($images) ) {

				$tpl->set( '{images}', "\n".implode("\n", $images) );

			} else { $tpl->set( '{images}', '' ); }

			$row['short_story'] = clear_rss_content($row['short_story'], $rssmode );
			$row['full_story'] = clear_rss_content($row['full_story'], $rssmode);

			$tpl->set('{short-story}', $row['short_story']);
			$tpl->set('{full-story}', $row['full_story']);

			if ( preg_match( "#\\{full-story limit=['\"](.+?)['\"]\\}#i", $tpl->copy_template, $matches ) ) {
				$tpl->set( $matches[0], clear_content($row['full_story'], $matches[1]) );
			}

		} else {

			if ($smartphone_detected) {

				if (!$config['allow_smart_format']) {

						$row['short_story'] = strip_tags( $row['short_story'], '<p><div><br><a>' );

				} else {


					if ( !$config['allow_smart_images'] ) {
	
						$row['short_story'] = preg_replace( "#<!--TBegin(.+?)<!--TEnd-->#is", "", $row['short_story'] );
						$row['short_story'] = preg_replace( "#<!--MBegin(.+?)<!--MEnd-->#is", "", $row['short_story'] );
						$row['short_story'] = preg_replace( "#<img(.+?)>#is", "", $row['short_story'] );
	
					}
	
					if ( !$config['allow_smart_video'] ) {
	
						$row['short_story'] = preg_replace( "#<!--dle_video_begin(.+?)<!--dle_video_end-->#is", "", $row['short_story'] );
						$row['short_story'] = preg_replace( "#<!--dle_audio_begin(.+?)<!--dle_audio_end-->#is", "", $row['short_story'] );
						$row['short_story'] = preg_replace( "#<!--dle_media_begin(.+?)<!--dle_media_end-->#is", "", $row['short_story'] );
	
					}

				}

			}

			if ($config['image_lazy'] AND $view_template != "rss" ) $row['short_story'] = preg_replace_callback ( "#<(img|iframe)(.+?)>#i", "enable_lazyload", $row['short_story'] );
			
			$tpl->set( '{short-story}', $row['short_story'] );
		
		}

		if ( preg_match( "#\\{short-story limit=['\"](.+?)['\"]\\}#i", $tpl->copy_template, $matches ) ) {
			$tpl->set( $matches[0], clear_content($row['short_story'], $matches[1]) );
		}

		if( $config['user_in_news'] ) {
			include (DLEPlugins::Check(ENGINE_DIR . '/modules/profile_innews.php'));
		}
		
		$tpl->compile( 'content', true, false );

		if(count($xfields_in_news) ) {
			
			if (stripos ( $tpl->result['content'], "[xf" ) !== false ) {
				
				foreach ( $xfields_in_news as $key => $value) {
					$tpl->result['content'] = str_replace ( $key, $value, $tpl->result['content'] );
				}
				
			}
		}
	
	}

	if (stripos ( $tpl->result['content'], "[hide" ) !== false ) {
		
		$tpl->result['content'] = preg_replace_callback ( "#\[hide(.*?)\](.+?)\[/hide\]#is", 
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

		}, $tpl->result['content'] );
	}

	if (stripos($tpl->result['content'], '<dlehide') !== false) {

		$pattern = '#<dlehide\b([^>]*)>(.*?)</dlehide>#is';

		while (preg_match($pattern, $tpl->result['content'])) {

			$tpl->result['content'] = preg_replace_callback($pattern,
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
				}, $tpl->result['content']
			);
		}
	}
	
	if ($config['files_allow'] && count($attachments) && strpos($tpl->result['content'], "[attachment=") !== false) {
		$tpl->result['content'] = show_attach($tpl->result['content'], $attachments);
	}

	$attachments = [];

	$tpl->result['content'] = str_ireplace( "{PAGEBREAK}", '', $tpl->result['content'] );

	if ( $config['allow_banner'] && count($banner_in_news) && !$view_template ){

		foreach ( $banner_in_news as $name) {
			$tpl->result['content'] = str_replace( "{banner_" . $name . "}", $banners[$name], $tpl->result['content'] );

			if( $banners[$name] ) {
				$tpl->result['content'] = str_replace ( "[banner_" . $name . "]", "", $tpl->result['content'] );
				$tpl->result['content'] = str_replace ( "[/banner_" . $name . "]", "", $tpl->result['content'] );
			}
		}

		$tpl->result['content'] = preg_replace( "'\\[banner_(.*?)\\](.*?)\\[/banner_(.*?)\\]'si", '', $tpl->result['content'] );
	
	} elseif ( $view_template ) {

		$tpl->result['content'] = preg_replace( "'{banner_(.*?)}'si", '', $tpl->result['content'] );
		$tpl->result['content'] = preg_replace( "'\\[banner_(.*?)\\](.*?)\\[/banner_(.*?)\\]'si", '', $tpl->result['content'] );

	}
	
	if ($config['allow_links'] && $view_template != "rss" && function_exists('replace_links') && isset($replace_links['news'])) {
		$tpl->result['content'] = replace_links($tpl->result['content'], $replace_links['news']);
	}

	$tpl->news_mode = false;
	$tpl->clear();
	$db->free( $sql_result );
	
	if( $news_found AND !$view_template ) {
		
		$count_all = get_count_from_cache( $sql_count );

		if( !$count_all ) {
			
			if ( $do == 'cat' AND isset($_GET['category']) AND $_GET['category'] ) {

				$show_cat = (int)get_ID($_GET['category']);

			} else $show_cat = 0;

			if ( $do == 'cat' AND  $show_cat AND isset($cat_info[$show_cat]['newscount']) AND $cat_info[$show_cat]['newscount'] ) {
				
				$count_all = array( 'count' => $cat_info[$show_cat]['newscount'] );
				
			} else {
				
				$count_all = $db->super_query( $sql_count.$where_date );
				
			}
						
			if( !$count_all['count'] ) {
				$db->query("ANALYZE TABLE `" . PREFIX . "_post`, `" . PREFIX . "_post_extras`");
				$count_all = $db->super_query( $sql_count.$where_date );
			}
			
			$count_all = $count_all['count'];
			
			if( $count_all ) {
				set_count_to_cache( $sql_count,  $count_all);
			}
		}
	
	} else $count_all = 0;

	if( !$view_template AND $count_all AND $config['news_navigation'] ) {
		
		$tpl->load_template( 'navigation.tpl' );
		
		//----------------------------------
		// Previous link
		//----------------------------------
		
		$no_prev = false;
		$no_next = false;
		
		if( isset( $cstart ) and $cstart and $cstart > 0 ) {
			$prev = isset($_GET['cstart']) ?  intval($_GET['cstart']) - 1 : 1;

			if ($prev < 1) $prev = 1;
			if ($prev > 9999999) $prev = 9999999;

			if ($prev == 1) $prev_page = $navigation_first_page;
			else $prev_page = str_replace('{cstart}', $prev, $url_page);

			$tpl->set_block("'\[prev-link\](.*?)\[/prev-link\]'si", "<a href=\"" . $prev_page . "\">\\1</a>");
		
		} else {
			$tpl->set_block( "'\[prev-link\](.*?)\[/prev-link\]'si", "<span>\\1</span>" );
			$no_prev = TRUE;
		}
		
		//----------------------------------
		// Pages
		//----------------------------------
		if( $config['news_number'] ) {

			$pages = "";
			
			if( $count_all > $config['news_number'] ) {
				
				$enpages_count = @ceil( $count_all / $config['news_number'] );
				
				$cstart = ($cstart / $config['news_number']) + 1;
				
				if ($tpl->smartphone) {
					$max_pages = 5;
				} else {
					$max_pages = 10;
				}

				if( $enpages_count <= $max_pages ) {
					
					for($j = 1; $j <= $enpages_count; $j ++) {
						
						if( $j != $cstart ) {

							if ($j == 1) $pages .= "<a href=\"" . $navigation_first_page . "\">$j</a> ";
							else $pages .= "<a href=\"" . str_replace('{cstart}', $j, $url_page) . "\">$j</a> ";

						} else {
							
							$pages .= "<span>$j</span> ";
							
						}
					
					}
				
				} else {

					$nav_prefix = "<span class=\"nav_ext\">{$lang['nav_trennen']}</span> ";

					if($tpl->smartphone) {

						$start = 1;
						$end = 3;

						if ($cstart > 0) {

							if ($cstart > 2) {

								$start = $cstart - 1;
								$end = $start + 2;

								if ($end >= $enpages_count ) {
									$start = $enpages_count - 2;
									$end = $enpages_count - 1;
								}
							}
						}

					} else {

						$start = 1;
						$end = 10;

						if ($cstart > 0) {

							if ($cstart > 6) {

								$start = $cstart - 4;
								$end = $start + 8;

								if ($end >= $enpages_count - 1) {
									$start = $enpages_count - 9;
									$end = $enpages_count - 1;
								}
							}
						}

					}
					
					if( $end >= $enpages_count-1 ) $nav_prefix = ""; else $nav_prefix = "<span class=\"nav_ext\">{$lang['nav_trennen']}</span> ";
					
					if( $start >= 2 ) {

						if( $start >= 3 ) $before_prefix = "<span class=\"nav_ext\">{$lang['nav_trennen']}</span> "; else $before_prefix = "";

						$pages .= "<a href=\"" . $navigation_first_page . "\">1</a> " . $before_prefix;
					
					} 
					
					for($j = $start; $j <= $end; $j ++) {
						
						if( $j != $cstart ) {

							if ($j == 1) $pages .= "<a href=\"" . $navigation_first_page . "\">$j</a> ";
							else $pages .= "<a href=\"" . str_replace('{cstart}', $j, $url_page) . "\">$j</a> ";
						
						} else {
							
							$pages .= "<span>$j</span> ";
						}
					
					}
					
					if( $cstart != $enpages_count ) {
							
						$pages .= $nav_prefix . "<a href=\"" . str_replace('{cstart}', $enpages_count, $url_page) . "\">{$enpages_count}</a>";
					
					} else $pages .= "<span>{$enpages_count}</span> ";
				
				}
			
			}

			$tpl->set( '{pages}', $pages );

		}
		
		//----------------------------------
		// Next link
		//----------------------------------
		if( $config['news_number'] AND $config['news_number'] < $count_all AND $news_count < $count_all ) {

			$next_page = isset($_GET['cstart']) ? intval($_GET['cstart']) + 1 : 2;

			if ($next_page < 2) $next_page = 2;
			if ($next_page > 9999999) $next_page = 9999999;

			$next = str_replace('{cstart}', $next_page, $url_page);
			$tpl->set_block( "'\[next-link\](.*?)\[/next-link\]'si", "<a href=\"" . $next . "\">\\1</a>" );

		} else {
			$tpl->set_block( "'\[next-link\](.*?)\[/next-link\]'si", "<span>\\1</span>" );
			$no_next = TRUE;
		}
		
		if( !$no_prev OR !$no_next ) {
			
			$tpl->compile( 'navigation' );
			
			switch ( $config['news_navigation'] ) {

				case "2" :
					
					$tpl->result['content'] = '{newsnavigation}'.$tpl->result['content'];
					break;

				case "3" :
					
					$tpl->result['content'] = '{newsnavigation}'.$tpl->result['content'].'{newsnavigation}';
					break;

				default :
					$tpl->result['content'] .= '{newsnavigation}';
					break;
			
			}
			
		} else $tpl->result['navigation'] = "";
		
		$tpl->clear();
		
	} else $tpl->result['navigation'] = "";
	
}
