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
 File: videoconfig.php
-----------------------------------------------------
 Use: configure video players
=====================================================
*/

if( !defined( 'DATALIFEENGINE' ) OR !defined( 'LOGGED_IN' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

if( $member_id['user_group'] != 1 ) {
	msg( "error", $lang['index_denied'], $lang['index_denied'] );
}

require_once (ENGINE_DIR . '/data/videoconfig.php');

if( $action == "save" ) {

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die( "Hacking attempt! User not found" );
	
	}

	$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '78', '')" );
	
	$save_con = isset($_POST['save_con']) ? $_POST['save_con'] : array();
	$save_con['preload'] = isset($save_con['preload']) ? intval($save_con['preload']) : 0;

	$keys = array_map(function ($key) {
		return totranslit($key, true, false);
	}, array_keys($save_con));

	$values = array_map(function ($value) {
		$value = str_replace("\r", '', $value);
		$value = str_replace("\n", '', $value);
		$value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
		return $value;
	}, array_values($save_con));

	$save_con = array_combine($keys, $values);

	@file_put_contents(ENGINE_DIR . '/data/videoconfig.php', "<?php \n\n//Videoplayers Configurations\n\n\$video_config = " . var_export($save_con, true) . ';');
	
	if (function_exists('opcache_reset')) {
		opcache_reset();
	}
	
	msg( "success", $lang['opt_sysok'], $lang['opt_sysok_1'], "?mod=videoconfig" );
}



	echoheader( "<i class=\"fa fa-play-circle-o position-left\"></i><span class=\"text-semibold\">{$lang['header_me_1']}</span>", $lang['opt_vconf'] );

function showRow($title = "", $description = "", $field = "", $class = "") {
	echo "<tr>
       <td class=\"col-xs-6 col-sm-6 col-md-7\"><div class=\"media-heading text-semibold\">{$title}</div><span class=\"text-muted text-size-small hidden-xs\">{$description}</span></td>
       <td class=\"col-xs-6 col-sm-6 col-md-5\">{$field}</td>
       </tr>";
}
	
function makeDropDown($options, $name, $selected) {
	$output = "<select class=\"uniform\" name=\"$name\">\r\n";
	foreach ( $options as $value => $description ) {
		$output .= "<option value=\"$value\"";
		if( $selected == $value ) {
			$output .= " selected ";
		}
		$output .= ">$description</option>\n";
	}
	$output .= "</select>";
	return $output;
}

function makeCheckBox($name, $selected) {
	$selected = $selected ? "checked" : "";
	
	return "<input class=\"switch\" type=\"checkbox\" name=\"$name\" value=\"1\" {$selected}>";
}


echo <<<HTML
<form action="?mod=videoconfig&action=save" name="conf" id="conf" method="post">
<div class="panel panel-default">
  <div class="panel-heading">
    {$lang['opt_vconf']}
  </div>
  <div class="table-responsive">
  <table class="table table-striped">
      <thead>
      <tr>
        <th>{$lang['vconf_title']}</th>
        <th></th>
      </tr>
      </thead>
HTML;

	showRow( $lang['vconf_widht'], $lang['vconf_widhtd'], "<input type=\"text\" name=\"save_con[width]\" value=\"{$video_config['width']}\" class=\"form-control\" style=\"max-width:10.42em; text-align: center;\">", "white-line" );
	showRow( $lang['vconf_awidht'], $lang['vconf_awidhtd'], "<input type=\"text\" name=\"save_con[audio_width]\" value=\"{$video_config['audio_width']}\" class=\"form-control\" style=\"max-width:10.42em; text-align: center;\">" );
	showRow( $lang['vconf_theme'], $lang['vconf_themed'], makeDropDown( array ("light" => "Light", "dark" => "Dark", "dark" => "Dark", "blue" => "Blue", "red" => "Red", "green" => "Green", "pink" => "Pink" ), "save_con[theme]", "{$video_config['theme']}" ) );

	showRow( $lang['opt_sys_preload'], $lang['opt_sys_preloadd'], makeCheckBox( "save_con[preload]", "{$video_config['preload']}" ) );


echo <<<HTML
</table></div></div>
<div style="margin-bottom:30px;">
<input type="hidden" name="user_hash" value="{$dle_login_hash}" />
<button type="submit" class="btn bg-teal btn-raised position-left"><i class="fa fa-floppy-o position-left"></i>{$lang['user_save']}</button>
</div>

</form>
HTML;

if(!is_writable(ENGINE_DIR . '/data/videoconfig.php')) {

	$lang['stat_system'] = str_replace ("{file}", "engine/data/videoconfig.php", $lang['stat_system']);

	echo "<div class=\"alert alert-warning alert-styled-left alert-arrow-left alert-component\">{$lang['stat_system']}</div>";

}

echofooter();