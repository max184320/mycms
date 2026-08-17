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
 File: newsletter_templates.php
-----------------------------------------------------
 Use: Newsletter Templates
=====================================================
*/

if(!defined('DATALIFEENGINE')) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}
function json_response($data, int $status = 200): void {
	if (function_exists('http_response_code')) {
		http_response_code($status);
	}

	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function json_error(string $message, int $status = 400): void {
	json_response(['success' => false, 'error' => $message], $status);
}

function nullable_category_id($name) {
	if (!array_key_exists($name, $_POST)) {
		return null;
	}

	$value = trim((string) $_POST[$name]);

	if ($value === '') {
		return null;
	}

	$id = (int)$value;

	return $id > 0 ? $id : null;
}

function check_category($category_id, $check_locked = false) {
	global $db, $member_id;

	$category_id = (int)$category_id;

	$row = $db->super_query( "SELECT id, title, locked FROM " . PREFIX . "_newsletter_template_categories WHERE id = '{$category_id}' AND created_by = '{$member_id['user_id']}' LIMIT 1");

	if (empty($row['id'])) {
		json_error('Category not found', 404);
	}

	if ($check_locked && (int)$row['locked'] === 1) {
		json_error('Category is locked', 403);
	}
}

function get_template($template_id, $check_locked = false) {
	global $db, $member_id;

	$template_id = (int)$template_id;

	$row = $db->super_query("SELECT t.id, t.title, t.content, t.locked, t.category_id, c.locked AS category_locked FROM " . PREFIX . "_newsletter_template_items t LEFT JOIN " . PREFIX . "_newsletter_template_categories c ON c.id = t.category_id WHERE t.id = '{$template_id}' AND t.created_by = '{$member_id['user_id']}' LIMIT 1");

	if (empty($row['id'])) {
		json_error('Template not found', 404);
	}

	$is_locked = (int)$row['locked'] === 1 || (int)$row['category_locked'] === 1;

	if ($check_locked && $is_locked) {
		json_error('Template is locked', 403);
	}

	return $row;
}

if (!$is_logged OR !$user_group[$member_id['user_group']]['admin_newsletter']) json_error ("error", 403);

if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) json_error ("error", 403);

$action = trim(str_replace("\0", '', (string)($_REQUEST['action'] ?? '')));

$allowed_actions = [
	'list',
	'get_template',
	'create_category',
	'create_template',
	'rename_category',
	'rename_template',
	'delete_template',
	'delete_category',
	'move_template',
	'move_category_items'
];

if (!in_array($action, $allowed_actions, true)) {
	json_error('Action Error', 404);
}

switch ($action) {
	case 'list':
		$categories = [];
		$category_index = [];

		$db->query("SELECT id, title, locked FROM " . PREFIX . "_newsletter_template_categories WHERE created_by = '{$member_id['user_id']}' ORDER BY sort_order ASC, title ASC");

		while ($row = $db->get_row()) {
			$category_index[$row['id']] = count($categories);

			$categories[] = [
				'id' => (string)$row['id'],
				'title' => $row['title'],
				'locked' => (int)$row['locked'] === 1,
				'items' => []
			];
		}

		$root_templates = [];

		$db->query("SELECT id, category_id, title, locked FROM " . PREFIX . "_newsletter_template_items WHERE created_by = '{$member_id['user_id']}' ORDER BY category_id ASC, sort_order ASC, title ASC");

		while ($row = $db->get_row()) {
			$template = [
				'id' => (string)$row['id'],
				'title' => $row['title'],
				'locked' => (int)$row['locked'] === 1
			];

			$category_id = $row['category_id'];

			if ($category_id > 0 && isset($category_index[$category_id])) {
				$categories[$category_index[$category_id]]['items'][] = $template;
			} else {
				$root_templates[] = $template;
			}
		}

		json_response(array_merge($categories, $root_templates));

		break;

	case 'get_template':
		$template_id = (int)($_POST['id'] ?? 0);

		if ($template_id < 1) {
			json_error('Invalid template ID');
		}

		$row = get_template($template_id);

		json_response([
			'id' => (string) (int) $row['id'],
			'title' => $row['title'],
			'content' => $row['content']
		]);

		break;

	case 'create_category':

		$title = trim(strip_tags(str_replace("\0", '', (string)($_POST['title'] ?? ''))));

		if ($title === '') {
			json_error('Category name is empty');
		}

		$title = $db->safesql(dle_substr($title, 0, 191));

		$db->query("INSERT INTO " . PREFIX . "_newsletter_template_categories (title, locked, sort_order, created_at, created_by) VALUES ('{$title}', '0', '{$_TIME}', '{$_TIME}', '{$member_id['user_id']}')");

		$id = $db->insert_id();

		json_response(['id' => (string)$id]);

		break;

	case 'create_template':
		
		$parse = new ParseFilter();
		$parse->allow_code = false;

		$title = trim(strip_tags(str_replace("\0", '', (string)($_POST['title'] ?? ''))));

		if ($title === '') {
			json_error('Category name is empty');
		}

		$title = $db->safesql(dle_substr($title, 0, 191));
		$content = $db->safesql(stripslashes($parse->process($_POST['content'] ?? '')));
		
		$category_id = nullable_category_id('categoryId');

		if ($content === '') {
			json_error('Template content is empty');
		}

		if ($category_id !== null) {
			check_category($category_id, true);
		}

		$category_sql = $category_id !== null ? "'" . (int)$category_id . "'" : "NULL";

		$db->query("INSERT INTO " . PREFIX . "_newsletter_template_items (category_id, title, content, locked, sort_order, created_at, created_by) VALUES ({$category_sql}, '{$title}', '{$content}', '0', '{$_TIME}', '{$_TIME}', '{$member_id['user_id']}')");

		$id = $db->insert_id();

		json_response(['id' => (string)$id]);

		break;

	case 'rename_category':
		$category_id = (int)($_POST['id'] ?? 0);
		
		if ($category_id < 1) {
			json_error('Invalid category ID');
		}

		$title = trim(strip_tags(str_replace("\0", '', (string)($_POST['title'] ?? ''))));

		if ($title === '') {
			json_error('Category name is empty');
		}

		$title = $db->safesql(dle_substr($title, 0, 191));

		check_category($category_id, true);

		$db->query("UPDATE " . PREFIX . "_newsletter_template_categories SET title = '{$title}' WHERE id = '{$category_id}' AND created_by = '{$member_id['user_id']}'");

		json_response(new stdClass());

		break;

	case 'rename_template':

		$template_id = (int)($_POST['id'] ?? 0);

		if ($template_id < 1) {
			json_error('Invalid template ID');
		}
		
		$title = trim(strip_tags(str_replace("\0", '', (string)($_POST['title'] ?? ''))));

		if ($title === '') {
			json_error('Category name is empty');
		}

		$title = $db->safesql(dle_substr($title, 0, 191));

		get_template($template_id, true);

		$db->query("UPDATE " . PREFIX . "_newsletter_template_items SET title = '{$title}' WHERE id = '{$template_id}' AND created_by = '{$member_id['user_id']}'");

		json_response(new stdClass());

		break;

	case 'delete_template':
		$template_id = (int)($_POST['id'] ?? 0);

		if ($template_id < 1) {
			json_error('Invalid template ID');
		}

		get_template($template_id, true);

		$db->query("DELETE FROM " . PREFIX . "_newsletter_template_items WHERE id = '{$template_id}' AND created_by = '{$member_id['user_id']}'");

		json_response(new stdClass());
		break;

	case 'delete_category':
		$category_id = (int)($_POST['id'] ?? 0);

		if ($category_id < 1) {
			json_error('Invalid category ID');
		}

		check_category($category_id, true);

		$delete_items = $db->query( "DELETE FROM " . PREFIX . "_newsletter_template_items WHERE category_id = '{$category_id}' AND created_by = '{$member_id['user_id']}'");

		$delete_category = $delete_items !== false && $db->query("DELETE FROM " . PREFIX . "_newsletter_template_categories WHERE id = '{$category_id}' AND created_by = '{$member_id['user_id']}'");

		if ($delete_category === false) {
			json_error('Failed to delete category', 500);
		}

		json_response(new stdClass());
		break;

	case 'move_template':
		$template_id = (int)($_POST['templateId'] ?? 0);
		$new_category_id = nullable_category_id('newCategoryId');

		if ($template_id < 1) {
			json_error('Invalid template ID');
		}

		$template = get_template($template_id, true);

		if ($new_category_id !== null) {
			check_category($new_category_id, true);
		}

		if ((int) $template['category_id'] === (int) $new_category_id) {
			json_response(new stdClass());
		}

		$category_sql = $new_category_id !== null ? "'" . (int) $new_category_id . "'" : "NULL";

		$db->query("UPDATE " . PREFIX . "_newsletter_template_items SET category_id = {$category_sql} WHERE id = '{$template_id}' AND created_by = '{$member_id['user_id']}'");

		json_response(new stdClass());
		break;

	case 'move_category_items':
		$old_category_id = (int)($_POST['oldCategoryId'] ?? 0);
		$new_category_id = nullable_category_id('newCategoryId');

		if ($old_category_id < 1) {
			json_error('Invalid category ID');
		}

		check_category($old_category_id, true);

		if ($new_category_id !== null) {
			check_category($new_category_id, true);
		}

		if ($old_category_id === (int) $new_category_id) {
			json_response(new stdClass());
		}

		$category_sql = $new_category_id !== null ? "'" . (int)$new_category_id . "'" : "NULL";

		$result = $db->query("UPDATE " . PREFIX . "_newsletter_template_items SET category_id = {$category_sql} WHERE category_id = '{$old_category_id}' AND created_by = '{$member_id['user_id']}'");

		if ($result === false) {
			json_error('Failed to move category items', 500);
		}

		json_response(new stdClass());
		break;
}