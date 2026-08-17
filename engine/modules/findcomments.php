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
 File: findcomments.php
-----------------------------------------------------
 Use: Find Comments on the website
=====================================================
*/

if( !defined('DATALIFEENGINE') ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

$comment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$post_id = isset($_GET['postid']) ? intval($_GET['postid']) : 0;

if (strpos($config['http_home_url'], "//") === 0) $config['http_home_url'] = isSSL() ? "https:" . $config['http_home_url'] : "http:" . $config['http_home_url'];
elseif (strpos($config['http_home_url'], "/") === 0) $config['http_home_url'] = isSSL() ? "https://" . $_SERVER['HTTP_HOST'] . $config['http_home_url'] : "http://" . $_SERVER['HTTP_HOST'] . $config['http_home_url'];

if(!$comment_id OR !$comment_id) {
	header("HTTP/1.0 301 Moved Permanently");
	header("Location: {$config['http_home_url']}");
	die("Redirect");
}

function build_comments_tree($data){

	$tree = array();
	foreach ($data as $id => &$node) {
		if ($node['parent'] === false) {
			$tree[$id] = &$node;
		} else {
			if (!isset($data[$node['parent']]['children'])) $data[$node['parent']]['children'] = array();
			$data[$node['parent']]['children'][$id] = &$node;
		}
	}

	return $tree;
}

function searchByFieldValue($array, $field, $value){

	foreach ($array as $item) {

		if (isset($item[$field]) and $item[$field] == $value) {

			return true;
		}

		if (isset($item['children']) and is_array($item['children'])) {
			if (searchByFieldValue($item['children'], $field, $value)) {
				return true;
			}
		}
	}

	return false;
}

$rows = array();

if ($config['allow_cmod']) $where_approve = " AND " . PREFIX . "_comments.approve=1";
else $where_approve = "";

$sql_result = $db->query("SELECT " . PREFIX . "_comments.id, " . PREFIX . "_comments.parent FROM " . PREFIX . "_comments WHERE " . PREFIX . "_comments.post_id = '{$post_id}'{$where_approve}  ORDER BY " . PREFIX . "_comments.id ASC");

while ($row = $db->get_row($sql_result)) {
	$rows[$row['id']] = array();

	foreach ($row as $key => $value) {
		if ($key == "parent" and ($value == 0 or !$config['tree_comments'])) $value = false;
		$rows[$row['id']][$key] = $value;
	}
}

$db->free($sql_result);
unset($row);

if (count($rows)) {
	$rows = build_comments_tree($rows);

	if ($config['comm_msort'] == "DESC") $rows = array_reverse($rows, true);

	$rows = array_chunk($rows, intval($config['comm_nummers']));

	$page = 1;
	$page_found = false;

	foreach ($rows as $arr) {

		if (searchByFieldValue($arr, 'id', $comment_id)) {
			$page_found = true;
			break;
		}

		$page++;
	}

	if ($page && $page_found) {
		
		$row = $db->super_query("SELECT id, short_story, title, date, alt_name, category FROM " . PREFIX . "_post WHERE id = '{$post_id}'");

		$row['date'] = strtotime($row['date']);

		if( $page > 1 ) {

			$full_link = DLEUrl::BuildUrl('showfull.page.newscomments', ['category' => get_url($row['category']), 'year' => date('Y', $row['date']), 'month' => date('m', $row['date']), 'day' => date('d', $row['date']), 'news_name' => $row['alt_name'], 'newsid' => $row['id'], 'news_page' => 1, 'cstart' => $page]);

		} else {
			$full_link = DLEUrl::BuildUrl('showfull', ['category' => get_url($row['category']), 'year' => date('Y', $row['date']), 'month' => date('m', $row['date']), 'day' => date('d', $row['date']), 'news_name' => $row['alt_name'], 'newsid' => $row['id']]);
		}

		$full_link .= '#findcomment' . $comment_id;

		header("HTTP/1.0 301 Moved Permanently");
		header("Location: {$full_link}");
		die("Redirect");
	}
}

header("HTTP/1.0 301 Moved Permanently");
header("Location: {$config['http_home_url']}");
die("Redirect");
