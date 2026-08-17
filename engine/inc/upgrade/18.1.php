<?php

if( !defined( 'DATALIFEENGINE' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../../' );
	die( "Hacking attempt!" );
}

$config['version_id'] = '19.0';
$config['cache_id'] = clear_static_cache_id(false);
unset($config['js_min']);

$tableSchema = array();

$tableSchema[] = "UPDATE `" . PREFIX . "_post` SET `short_story`=REPLACE(`short_story`,'/engine/data/emoticons/','/public/emoticons/')";
$tableSchema[] = "UPDATE `" . PREFIX . "_post` SET `full_story`=REPLACE(`full_story`,'/engine/data/emoticons/','/public/emoticons/')";
$tableSchema[] = "UPDATE `" . PREFIX . "_post` SET `xfields`=REPLACE(`xfields`,'/engine/data/emoticons/','/public/emoticons/')";
$tableSchema[] = "UPDATE `" . PREFIX . "_comments` SET `text`=REPLACE(`text`,'/engine/data/emoticons/','/public/emoticons/')";

foreach ($tableSchema as $table) {
	$db->query($table, false);
}

if (file_exists(ENGINE_DIR . '/data/xfields.txt')) {
	$filecontents = file(ENGINE_DIR . '/data/xfields.txt');
	$fields = array();
	$tmp_arr = array();
	$converted_fields = ['fields' => []];

	if (is_array($filecontents) and count($filecontents)) {

		foreach ($filecontents as $name => $value) {

			if (trim($value)) {

				$tmp_arr = explode("|", trim($value, "\t\n\r\0\x0B"));

				foreach ($tmp_arr as $name2 => $value2) {
					$value2 = str_replace("&#124;", "|", $value2);
					$value2 = str_replace("__NEWL__", "\n", $value2);
					$value2 = html_entity_decode($value2, ENT_QUOTES, 'UTF-8');
					$fields[$name][$name2] = $value2;
				}
			}
		}

		foreach ($fields as $value) {
			$converted_fields['fields'][$value[0]] = [
				'name' => $value[0],
				'description' => $value[1],
				'category' => $value[2],
				'type' => $value[3],
				'default' => $value[4],
				'not_required' => $value[5],
				'use_as_links' => $value[6],
				'use_editor' => $value[7],
				'safe_mode' => $value[8],
				'image_size' => $value[9],
				'image_max_size' => $value[10],
				'make_watermark' => $value[11],
				'make_thumb' => $value[12],
				'thumb_size' => $value[13],
				'files_ext' => $value[14],
				'file_max_size' => $value[15],
				'max_images' => $value[16],
				'condition' => $value[17],
				'hint' => $value[18],
				'allow_add_usergroups' => $value[19],
				'allow_view_usergroups' => $value[20],
				'links_separator' => $value[21],
				'image_sizes' => $value[22],
				'date_format' => $value[23],
				'date_view_format' => $value[24],
				'date_local' => $value[25],
				'date_declension' => $value[26],
				'is_public' => $value[27],
				'allow_in_news' => $value[28],
				'use_opengraph' => $value[29],
				'lazy_load' => $value[30],
				'max_files' => $value[31],
				'max_size' => $value[32],
				'storage' => $value[33],
				'allow_multi' => $value[34],
				'select_separator' => $value[35],
				'min' => $value[36],
				'max' => $value[37],
				'image_side' => $value[38],
				'thumb_side' => $value[39],
				'group' => ''
			];
		}
	}

	@file_put_contents(ENGINE_DIR . '/data/xfields.json', json_encode($converted_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
	@chmod(ENGINE_DIR .  '/data/xfields.json', 0666);
	@unlink(ENGINE_DIR . '/cache/system/xfields.php');
	@unlink(ENGINE_DIR . '/data/xfields.txt');
	
}

if (file_exists(ENGINE_DIR . '/data/xprofile.txt')) {
	$filecontents = file(ENGINE_DIR . '/data/xprofile.txt');
	$fields = array();
	$tmp_arr = array();
	$converted_fields = ['fields' => []];

	if (is_array($filecontents) and count($filecontents)) {

		foreach ($filecontents as $name => $value) {

			if (trim($value)) {

				$tmp_arr = explode("|", trim($value, "\t\n\r\0\x0B"));

				foreach ($tmp_arr as $name2 => $value2) {
					$value2 = str_replace("&#124;", "|", $value2);
					$value2 = str_replace("__NEWL__", "\n", $value2);
					$value2 = html_entity_decode($value2, ENT_QUOTES, 'UTF-8');
					$fields[$name][$name2] = $value2;
				}
			}
		}

		foreach ($fields as $value) {
			$converted_fields['fields'][$value[0]] = [
				'name' => $value[0],
				'description' => $value[1],
				'registration' => $value[2],
				'type' => $value[3],
				'allow_change' => $value[4],
				'private' => $value[5],
				'default' => $value[6],
				'safe_mode' => $value[7],
				'condition' => '',
				'date_format' => '',
				'date_view_format' => '',
				'date_local' => '',
				'date_declension' => ''
			];
		}
	}

	@file_put_contents(ENGINE_DIR . '/data/userxfields.json', json_encode($converted_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
	@chmod(ENGINE_DIR .  '/data/userxfields.json', 0666);
	@unlink(ENGINE_DIR . '/cache/system/userxfields.php');
	@unlink(ENGINE_DIR . '/data/xprofile.txt');
}

if (file_exists(ENGINE_DIR . '/data/wordfilter.db.php')) {
	$filecontents = file(ENGINE_DIR . '/data/wordfilter.db.php');
	$fields = array();
	$tmp_arr = array();
	$converted_fields = array();

	if (is_array($filecontents) and count($filecontents)) {

		foreach ($filecontents as $name => $value) {

			if (trim($value)) {

				$tmp_arr = explode("|", trim($value, "\t\n\r\0\x0B"));

				foreach ($tmp_arr as $name2 => $value2) {
					$value2 = str_replace("&#124;", "|", $value2);
					$value2 = str_replace("__NEWL__", "\n", $value2);
					$value2 = html_entity_decode($value2, ENT_QUOTES, 'UTF-8');
					$fields[$name][$name2] = $value2;
				}
			}
		}

		foreach ($fields as $value) {
			$converted_fields[] = [
				'find' => $value[1],
				'replace' => $value[2],
				'type' => $value[3],
				'use_case' => $value[4],
				'filter_search' => $value[5],
				'filter_action' => $value[6]
			];
		}
	}

	@file_put_contents(ENGINE_DIR . '/data/wordfilter.json', json_encode($converted_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
	@chmod(ENGINE_DIR .  '/data/wordfilter.json', 0666);
	@unlink(ENGINE_DIR . '/data/wordfilter.db.php');
}


@file_put_contents(ENGINE_DIR . '/data/config.php', "<?php \n\n//System Configurations\n\n\$config = " . var_export($config, true) . ';', LOCK_EX);

listdir(ENGINE_DIR . '/classes/calendar');
listdir(ENGINE_DIR . '/classes/fancybox');
listdir(ENGINE_DIR . '/classes/highlight');
listdir(ENGINE_DIR . '/classes/html5player');
listdir(ENGINE_DIR . '/classes/js');
listdir(ENGINE_DIR . '/classes/masha');
listdir(ENGINE_DIR . '/classes/uploads/html5');
listdir(ENGINE_DIR . '/data/emoticons');
listdir(ENGINE_DIR . '/editor/jscripts');
listdir(ENGINE_DIR . '/editor/css');
listdir(ENGINE_DIR . '/modules/antibot');
listdir(ENGINE_DIR . '/skins/fonts');
listdir(ENGINE_DIR . '/classes/min');
listdir(ENGINE_DIR . '/skins/images');
listdir(ENGINE_DIR . '/skins/javascripts');
listdir(ENGINE_DIR . '/skins/stylesheets');

@unlink(ENGINE_DIR . '/editor/emotions.php');
@unlink(ENGINE_DIR . '/modules/.htaccess');
@unlink(ENGINE_DIR . '/classes/composer/.htaccess');
@unlink(ENGINE_DIR . '/inc/.htaccess');
@unlink(ENGINE_DIR . '/data/.htaccess');
@unlink(ENGINE_DIR . '/cache/.htaccess');
@unlink(ENGINE_DIR . '/cache/system/.htaccess');
@unlink(ENGINE_DIR . '/api/.htaccess');