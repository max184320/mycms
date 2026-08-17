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
 File: social.php
-----------------------------------------------------
 Use: Setup social networking
=====================================================
*/

if( !defined( 'DATALIFEENGINE' ) OR !defined( 'LOGGED_IN' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

if($member_id['user_group'] != 1) {

	msg("error", $lang['index_denied'], $lang['index_denied']);

}

require_once (ENGINE_DIR . '/data/socialconfig.php');

function showRow($title = "", $description = "", $field = "", $class = "") {
	echo "<tr>
       <td class=\"col-xs-6 col-sm-6 col-md-7\"><div class=\"media-heading text-semibold\">{$title}</div><div class=\"text-muted text-size-small hidden-xs\">{$description}</div></td>
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

if( $action == "save" ) {

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die( "Hacking attempt! User not found" );
	
	}

	$save_con = isset($_POST['save_con']) ? $_POST['save_con'] : array();
	$save_con['vk'] = isset($save_con['vk']) ? intval($save_con['vk']) : 0;
	$save_con['od'] = isset($save_con['od']) ? intval($save_con['od']) : 0;
	$save_con['fc'] = isset($save_con['fc']) ? intval($save_con['fc']) : 0;
	$save_con['google'] = isset($save_con['google']) ? intval($save_con['google']) : 0;
	$save_con['mailru'] = isset($save_con['mailru']) ? intval($save_con['mailru']) : 0;
	$save_con['yandex'] = isset($save_con['yandex']) ? intval($save_con['yandex']) : 0;

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

	@file_put_contents(ENGINE_DIR . '/data/socialconfig.php', "<?php \n\n//Social Configurations\n\n\$social_config = " . var_export($save_con, true) . ';');

	if (function_exists('opcache_reset')) {
		opcache_reset();
	}

	msg( "success", $lang['opt_sysok'], $lang['opt_sysok_1'], "?mod=social" );


}

echoheader("<i class=\"fa fa-facebook-official position-left\"></i><span class=\"text-semibold\">{$lang['opt_social']}</span>", $lang['opt_socialc1']);

if (!$config['allow_social']) {

	$lang['hint_social3'] = "<br><br><span class=\"text-danger\">{$lang['hint_social3']}</span>";

} else {

	$lang['hint_social3'] = "";

}

echo "<div class=\"alert alert-info alert-styled-left alert-arrow-left alert-component\">{$lang['hint_social']} {$lang['hint_social3']}</div>";


echo <<<HTML
<form action="?mod=social&action=save" name="conf" id="conf" method="post">
<div class="panel panel-default">
  <div class="panel-heading">
    {$lang['opt_social']}
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

$lang['sconf_vkd']  .= "<div class=\"mt-10 text-muted text-size-small hidden-xs\">".str_replace(['{applink}', '{link}'], ['<a href="https://id.vk.ru/business/go/" target="_blank">https://id.vk.ru/business/go/</a>', $config['http_home_url'].'index.php'], $lang['sconf_rules'] )."</div>";
showRow( $lang['sconf_vk'], $lang['sconf_vkd'], makeCheckBox( "save_con[vk]", "{$social_config['vk']}" ) );
showRow( $lang['sconf_vk1'], $lang['sconf_vk1d'], "<input type=\"text\" dir=\"auto\" class=\"form-control\" name=\"save_con[vkid]\" value=\"{$social_config['vkid']}\" >" );
showRow( $lang['sconf_vk2'], $lang['sconf_vk2d'], "<input type=\"text\" dir=\"auto\" class=\"form-control\" name=\"save_con[vksecret]\" value=\"{$social_config['vksecret']}\" >" );

$lang['sconf_fcd']  .= "<div class=\"mt-10 text-muted text-size-small hidden-xs\">" . str_replace(['{applink}', '{link}'], ['<a href="https://developers.facebook.com/apps" target="_blank">https://developers.facebook.com/apps</a>', $config['http_home_url'] . 'index.php?do=auth-social&provider=fc'], $lang['sconf_rules']) . "</div>";
showRow( $lang['sconf_fc'], $lang['sconf_fcd'], makeCheckBox( "save_con[fc]", "{$social_config['fc']}" ) );
showRow( $lang['sconf_fc1'], $lang['sconf_fc1d'], "<input type=\"text\" dir=\"auto\" class=\"form-control\" name=\"save_con[fcid]\" value=\"{$social_config['fcid']}\" >" );
showRow( $lang['sconf_fc2'], $lang['sconf_fc2d'], "<input type=\"text\" dir=\"auto\" class=\"form-control\" name=\"save_con[fcsecret]\" value=\"{$social_config['fcsecret']}\" >" );

$lang['sconf_googled']  .= "<div class=\"mt-10 text-muted text-size-small hidden-xs\">" . str_replace(['{applink}', '{link}'], ['<a href="https://code.google.com/apis/console/" target="_blank">https://code.google.com/apis/console/</a>', $config['http_home_url'] . 'index.php?do=auth-social&provider=google'], $lang['sconf_rules']) . "</div>";
showRow( $lang['sconf_google'], $lang['sconf_googled'], makeCheckBox( "save_con[google]", "{$social_config['google']}" ) );
showRow( $lang['sconf_google1'], $lang['sconf_google1d'], "<input type=\"text\" dir=\"auto\" class=\"form-control\" name=\"save_con[googleid]\" value=\"{$social_config['googleid']}\" >" );
showRow( $lang['sconf_google2'], $lang['sconf_google2d'], "<input type=\"text\" dir=\"auto\" class=\"form-control\" name=\"save_con[googlesecret]\" value=\"{$social_config['googlesecret']}\" >" );

$lang['sconf_mailrud']  .= "<div class=\"mt-10 text-muted text-size-small hidden-xs\">" . str_replace(['{applink}', '{link}'], ['<a href="https://o2.mail.ru/app/new/" target="_blank">https://o2.mail.ru/app/new/</a>', $config['http_home_url'] . 'index.php?do=auth-social&provider=mailru'], $lang['sconf_rules']) . "</div>";
showRow( $lang['sconf_mailru'], $lang['sconf_mailrud'], makeCheckBox( "save_con[mailru]", "{$social_config['mailru']}" ) );
showRow( $lang['sconf_mailru1'], $lang['sconf_mailru1d'], "<input type=\"text\" dir=\"auto\" class=\"form-control\" name=\"save_con[mailruid]\" value=\"{$social_config['mailruid']}\" >" );
showRow( $lang['sconf_mailru2'], $lang['sconf_mailru2d'], "<input type=\"text\" dir=\"auto\" class=\"form-control\" name=\"save_con[mailrusecret]\" value=\"{$social_config['mailrusecret']}\" >" );

$lang['sconf_yandexd']  .= "<div class=\"mt-10 text-muted text-size-small hidden-xs\">" . str_replace(['{applink}', '{link}'], ['<a href="https://oauth.yandex.ru/client/new" target="_blank">https://oauth.yandex.ru/client/new</a>', $config['http_home_url'] . 'index.php?do=auth-social&provider=yandex'], $lang['sconf_rules']) . "</div>";
showRow( $lang['sconf_yandex'], $lang['sconf_yandexd'], makeCheckBox( "save_con[yandex]", "{$social_config['yandex']}" ) );
showRow( $lang['sconf_yandex1'], $lang['sconf_yandex1d'], "<input type=\"text\" dir=\"auto\" class=\"form-control\" name=\"save_con[yandexid]\" value=\"{$social_config['yandexid']}\" >" );
showRow( $lang['sconf_yandex2'], $lang['sconf_yandex2d'], "<input type=\"text\" dir=\"auto\" class=\"form-control\" name=\"save_con[yandexsecret]\" value=\"{$social_config['yandexsecret']}\" >" );

showRow( $lang['sconf_od'], $lang['sconf_odd'], makeCheckBox( "save_con[od]", "{$social_config['od']}" ) );
showRow( $lang['sconf_od1'], $lang['sconf_od1d'], "<input type=\"text\" dir=\"auto\" class=\"form-control\" name=\"save_con[odid]\" value=\"{$social_config['odid']}\" >" );
showRow( $lang['sconf_od3'], $lang['sconf_od3d'], "<input type=\"text\" dir=\"auto\" class=\"form-control\" name=\"save_con[odpublic]\" value=\"{$social_config['odpublic']}\" >" );
showRow( $lang['sconf_od2'], $lang['sconf_od2d'], "<input type=\"text\" dir=\"auto\" class=\"form-control\" name=\"save_con[odsecret]\" value=\"{$social_config['odsecret']}\" >" );

echo <<<HTML
</table></div></div>
<div style="margin-bottom:30px;">
<input type="hidden" name="user_hash" value="{$dle_login_hash}" />
<button type="submit" class="btn bg-teal btn-raised position-left"><i class="fa fa-floppy-o position-left"></i>{$lang['user_save']}</button>
</div>

</form>
HTML;


if(!is_writable(ENGINE_DIR . '/data/socialconfig.php')) {

	$lang['stat_system'] = str_replace ("{file}", "engine/data/socialconfig.php", $lang['stat_system']);

	echo "<div class=\"alert alert-warning alert-styled-left alert-arrow-left alert-component\">{$lang['stat_system']}</div>";

}

echofooter();