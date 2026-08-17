<?php

if( !defined( 'DATALIFEENGINE' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../../' );
	die( "Hacking attempt!" );
}

$config['version_id'] = '20.0';
$config['cache_id'] = clear_static_cache_id(false);

$config['ai_proxy'] = '0';
$config['enable_ai_comments'] = '0';
$config['ai_endpoint_comments'] = '';
$config['ai_model_comments'] = '';
$config['ai_key_comments'] = '';
$config['comment_ai_action'] = '1';
$config['comment_ai_down'] = '1';
$config['ai_comments_groups'] = '4,5';
$config['sep_meta_title'] = ' &raquo; ';
$config['short_meta_title'] = $config['home_title'];

$tableSchema = array();

$tableSchema[] = "DROP TABLE IF EXISTS " . PREFIX . "_newsletter_template_categories";
$tableSchema[] = "CREATE TABLE " . PREFIX . "_newsletter_template_categories (
						`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
						`title` VARCHAR(191) NOT NULL DEFAULT '',
						`locked` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0',
						`sort_order` INT(11) UNSIGNED NOT NULL DEFAULT '0',
						`created_at` INT(11) UNSIGNED NOT NULL DEFAULT '0',
						`created_by` INT(11) UNSIGNED NOT NULL DEFAULT '0',
						PRIMARY KEY (`id`),
						KEY `idx_sort` (`sort_order`, `title`),
						KEY `created_by` (`created_by`),
						KEY `locked` (`locked`)
					) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";

$tableSchema[] = "DROP TABLE IF EXISTS " . PREFIX . "_newsletter_template_items";
$tableSchema[] = "CREATE TABLE " . PREFIX . "_newsletter_template_items (
						`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
						`category_id` INT(11) UNSIGNED DEFAULT NULL,
						`title` VARCHAR(191) NOT NULL DEFAULT '',
						`content` MEDIUMTEXT NOT NULL,
						`locked` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0',
						`sort_order` INT(11) UNSIGNED NOT NULL DEFAULT '0',
						`created_at` INT(11) UNSIGNED NOT NULL DEFAULT '0',
						`created_by` INT(11) UNSIGNED NOT NULL DEFAULT '0',
						PRIMARY KEY (`id`),
						KEY `idx_sort` (`category_id`, `sort_order`, `title`),
						KEY `created_at` (`created_at`),
						KEY `locked` (`locked`)
					) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";

foreach ($tableSchema as $table) {
	$db->query($table, false);
}

@file_put_contents(ENGINE_DIR . '/data/config.php', "<?php \n\n//System Configurations\n\n\$config = " . var_export($config, true) . ';', LOCK_EX);

listdir(ENGINE_DIR . '/classes/mobiledetect/Exception');
listdir(ENGINE_DIR . '/classes/composer');
listdir(ENGINE_DIR . '/classes/fastroute/DataGenerator');
listdir(ENGINE_DIR . '/classes/fastroute/Dispatcher');
listdir(ENGINE_DIR . '/classes/fastroute/RouteParser');
listdir(ENGINE_DIR . '/public/fonts/inter');
@unlink(ENGINE_DIR . '/classes/fastroute/BadRouteException.php');
@unlink(ENGINE_DIR . '/classes/fastroute/bootstrap.php');
@unlink(ENGINE_DIR . '/classes/fastroute/DataGenerator.php');
@unlink(ENGINE_DIR . '/classes/fastroute/Dispatcher.php');
@unlink(ENGINE_DIR . '/classes/fastroute/functions.php');
@unlink(ENGINE_DIR . '/classes/fastroute/Route.php');
@unlink(ENGINE_DIR . '/classes/fastroute/RouteCollector.php');
@unlink(ENGINE_DIR . '/classes/fastroute/RouteParser.php');

@unlink(ROOT_DIR . '/public/emoticons/emoji.json');
@unlink(ROOT_DIR . '/public/fonts/verdana.ttf');
@unlink(ROOT_DIR . '/public/fileuploader/plupload/plupload.ui.min.js');