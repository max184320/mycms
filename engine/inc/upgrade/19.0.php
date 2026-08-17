<?php

if( !defined( 'DATALIFEENGINE' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../../' );
	die( "Hacking attempt!" );
}

$config['version_id'] = '19.1';
$config['cache_id'] = clear_static_cache_id(false);

$config['related_date'] = '90';
$config['allow_pm_wysiwyg'] = '1';

$tableSchema = array();

$tableSchema[] = "ALTER TABLE `" . PREFIX . "_usergroups` ADD `rating_n_day` SMALLINT NOT NULL DEFAULT '0', ADD `rating_c_day` SMALLINT NOT NULL DEFAULT '0', ADD `allow_rating_change` TINYINT(1) NOT NULL DEFAULT '1', ADD `allow_crating_change` TINYINT(1) NOT NULL DEFAULT '1'";

foreach ($tableSchema as $table) {
	$db->query($table, false);
}

@file_put_contents(ENGINE_DIR . '/data/config.php', "<?php \n\n//System Configurations\n\n\$config = " . var_export($config, true) . ';', LOCK_EX);
