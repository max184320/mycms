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
 File: wordfilter.php
-----------------------------------------------------
 Use: words filter
=====================================================
*/

if( !defined( 'DATALIFEENGINE' ) OR !defined( 'LOGGED_IN' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

if( ! $user_group[$member_id['user_group']]['admin_wordfilter'] ) {
	msg( "error", $lang['index_denied'], $lang['index_denied'] );
}

$word_id = isset($_REQUEST['word_id']) ? intval( $_REQUEST['word_id'] ) : 0;

$parse = new ParseFilter();
$parse->filter_mode = false;
$all_items = load_json(ENGINE_DIR . '/data/wordfilter.json');

if( $action == "add" ) {

	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die( "Hacking attempt! User not found" );
	
	}

	$word_find = isset($_POST['word_find']) ? trim( strip_tags( stripslashes( (string)$_POST['word_find'] ) ) ) : '';
	$word_replace = isset($_POST['word_replace']) ? trim(stripslashes($parse->BB_Parse($parse->process($_POST['word_replace']), false))) : '';
	$_POST['type'] = isset($_POST['type']) ? intval($_POST['type']) : 0;
	$_POST['register'] = isset($_POST['register']) ? intval($_POST['register']) : 0;
	$_POST['filter_search'] = isset($_POST['filter_search']) ? intval($_POST['filter_search']) : 0;
	$_POST['filter_action'] = isset($_POST['filter_action']) ? intval($_POST['filter_action']) : 0;

	if(!$word_find) {
		msg( "error", $lang['word_error'], $lang['word_word'], "?mod=wordfilter" );
	}

	foreach ($all_items as $value ) {
		if($value['find'] == $word_find) {
			msg("error", $lang['word_error'], $lang['word_ar'], "?mod=wordfilter");
		}
	}

	$all_items[] = array(
		'find' => $word_find,
		'replace' => $word_replace,
		'type' => $_POST['type'],
		'use_case' => $_POST['register'],
		'filter_search' => $_POST['filter_search'],
		'filter_action' => $_POST['filter_action'],
	);
	
	save_json(ENGINE_DIR . '/data/wordfilter.json', $all_items);

	$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '79', '".htmlspecialchars($word_find, ENT_QUOTES, 'UTF-8')."')" );
	
	header("Location: ?mod=wordfilter");
	die();

} elseif( $action == "remove" ) {
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
		die( "Hacking attempt! User not found" );
	
	}
	
	if( !isset($all_items[$word_id]) ) {
		msg( "error", $lang['word_error'], $lang['word_nof'], "?mod=wordfilter" );
	}
	
	unset($all_items[$word_id]);
	save_json(ENGINE_DIR . '/data/wordfilter.json', $all_items);

	$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '80', '')");
	
	header("Location: ?mod=wordfilter");
	die();

} elseif( $action == "edit" ) {
	
	if( !isset($all_items[$word_id]) ) {
		msg( "error", $lang['word_error'], $lang['word_nof'], "?mod=wordfilter" );
	}

	echoheader( "<i class=\"fa fa-filter position-left\"></i><span class=\"text-semibold\">{$lang['word_head']}</span>", $lang['header_fi_1'] );
	
	$selected_2 = array('', '', '');
	$selected_3 = array('', '');

	$find = htmlspecialchars($all_items[$word_id]['find'], ENT_QUOTES, 'UTF-8');
	$replace = $parse->decodeBBCodes($all_items[$word_id]['replace'], false);

	if( $all_items[$word_id]['type'] ) $selected = "selected";
	else $selected = "";

	if( $all_items[$word_id]['use_case'] ) $selected_1 = "selected";
	else $selected_1 = "";

	$selected_2[$all_items[$word_id]['filter_search']] = "selected";
	$selected_3[$all_items[$word_id]['filter_action']] = "selected";

	echo <<<HTML
<form method="post" class="form-horizontal">
<div class="panel panel-default">
  <div class="panel-heading">
    {$lang['word_edit_head']}
  </div>
  <div class="panel-body">
  
		<div class="form-group">
		  <label class="control-label col-md-2 col-sm-3">{$lang['word_word']}</label>
		  <div class="col-md-10 col-sm-9">
			<input dir="auto" class="form-control width-350" value="{$find}" type="text" name="word_find">
		  </div>
		 </div>
		<div class="form-group">
		  <label class="control-label col-md-2 col-sm-3">{$lang['word_lred']}</label>
		  <div class="col-md-10 col-sm-9">
			<input dir="auto" class="form-control width-350" type="text" name="word_replace" value="{$replace}"  title="{$lang['word_help_1']}">
			<div class="text-muted text-size-small hidden-sm hidden-xs">{$lang['word_help_2']}</div>
		  </div>
		 </div>
		<div class="form-group">
		  <label class="control-label col-md-2 col-sm-3">{$lang['filter_type']}</label>
		  <div class="col-md-10 col-sm-9">
			<select class="uniform" name="type"><option value="0">{$lang['filter_type_1']}</option><option value="1" {$selected}>{$lang['filter_type_2']}</option></select>
		  </div>
		 </div>
		<div class="form-group">
		  <label class="control-label col-md-2 col-sm-3">{$lang['filter_register']}</label>
		  <div class="col-md-10 col-sm-9">
			<select name="register" class="uniform" style="min-width:6.94em;"><option value="0">{$lang['opt_sys_no']}</option><option value="1" {$selected_1}>{$lang['opt_sys_yes']}</option></select>
		  </div>
		 </div>
		<div class="form-group">
		  <label class="control-label col-md-2 col-sm-3">{$lang['filter_search']}</label>
		  <div class="col-md-10 col-sm-9">
			<select name="filter_search" class="uniform"><option value="0" {$selected_2[0]}>{$lang['filter_search_0']}</option><option value="1" {$selected_2[1]}>{$lang['filter_search_1']}</option><option value="2" {$selected_2[2]}>{$lang['filter_search_2']}</option></select>
		  </div>
		 </div>		 
		<div class="form-group">
		  <label class="control-label col-md-2 col-sm-3">{$lang['filter_action']}</label>
		  <div class="col-md-10 col-sm-9">
			<select name="filter_action" class="uniform"><option value="0" {$selected_3[0]}>{$lang['filter_action_0']}</option><option value="1" {$selected_3[1]}>{$lang['filter_action_1']}</option></select>
		  </div>
		 </div>	

	</div>
<div class="panel-footer">
	<button class="btn bg-teal btn-sm btn-raised" type="submit"><i class="fa fa-floppy-o position-left"></i>{$lang['user_save']}</button>
</div>
</div>
<input type="hidden" name="action" value="doedit">
<input type="hidden" name="word_id" value="{$word_id}">
<input type="hidden" name="mod" value="wordfilter">
<input type="hidden" name="user_hash" value="$dle_login_hash">
</form>
HTML;
	
	echofooter();
	die();

} elseif( $action == "doedit" ) {
	
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		die( "Hacking attempt! User not found" );
	}
	
	if (!isset($all_items[$word_id])) {
		msg("error", $lang['word_error'], $lang['word_nof'], "?mod=wordfilter");
	}

	$word_find = isset($_POST['word_find']) ? trim(strip_tags(stripslashes((string)$_POST['word_find']))) : '';
	$word_replace = isset($_POST['word_replace']) ? trim(stripslashes($parse->BB_Parse($parse->process($_POST['word_replace']), false))) : '';
	$_POST['type'] = isset($_POST['type']) ? intval($_POST['type']) : 0;
	$_POST['register'] = isset($_POST['register']) ? intval($_POST['register']) : 0;
	$_POST['filter_search'] = isset($_POST['filter_search']) ? intval($_POST['filter_search']) : 0;
	$_POST['filter_action'] = isset($_POST['filter_action']) ? intval($_POST['filter_action']) : 0;

	if (!$word_find) {
		msg("error", $lang['word_error'], $lang['word_word'], "?mod=wordfilter");
	}

	$all_items[$word_id] = array(
		'find' => $word_find,
		'replace' => $word_replace,
		'type' => $_POST['type'],
		'use_case' => $_POST['register'],
		'filter_search' => $_POST['filter_search'],
		'filter_action' => $_POST['filter_action'],
	);

	save_json(ENGINE_DIR . '/data/wordfilter.json', $all_items);

	$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '81', '".htmlspecialchars($word_find, ENT_QUOTES, 'UTF-8')."')" );

	header("Location: ?mod=wordfilter");
	die();
}


echoheader( "<i class=\"fa fa-filter position-left\"></i><span class=\"text-semibold\">{$lang['word_head']}</span>", $lang['header_fi_1'] );

echo <<<HTML
<div class="modal fade" name="advancedadd" id="advancedadd">
	<div class="modal-dialog modal-m" role="document">
		<div class="modal-content">
			<div class="modal-header ui-dialog-titlebar">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				<span class="ui-dialog-title">{$lang['word_new']}</span>
			</div>
			<form method="post" class="form-horizontal">
			<input type="hidden" name="action" value="add">
			<input type="hidden" name="mod" value="wordfilter">
			<input type="hidden" name="user_hash" value="$dle_login_hash">

			<div class="modal-body">

				<div class="form-group">
				<label class="control-label col-md-3 col-sm-3">{$lang['word_word']}</label>
				<div class="col-md-9 col-sm-9">
					<input type="text" dir="auto" class="form-control" name="word_find" title="{$lang['word_help']}" >
				</div>
				</div>
				<div class="form-group">
				<label class="control-label col-md-3 col-sm-3">{$lang['word_lred']}</label>
				<div class="col-md-9 col-sm-9">
					<input class="form-control" type="text" dir="auto" name="word_replace" title="{$lang['word_help_1']}">
					<div class="text-muted text-size-small">{$lang['word_help_2']}</div>
				</div>
				</div>
				<div class="form-group">
				<label class="control-label col-md-3 col-sm-3">{$lang['filter_type']}</label>
				<div class="col-md-9 col-sm-9">
					<select class="uniform" name="type"><option value="0">{$lang['filter_type_1']}</option><option value="1">{$lang['filter_type_2']}</option></select>
				</div>
				</div>
				<div class="form-group">
				<label class="control-label col-md-3 col-sm-3">{$lang['filter_register']}</label>
				<div class="col-md-9 col-sm-9">
					<select name="register" class="uniform" style="min-width:6.94em;"><option value="0">{$lang['opt_sys_no']}</option><option value="1">{$lang['opt_sys_yes']}</option></select>
				</div>
				</div>
				<div class="form-group">
				<label class="control-label col-md-3 col-sm-3">{$lang['filter_search']}</label>
				<div class="col-md-9 col-sm-9">
					<select name="filter_search" class="uniform"><option value="0">{$lang['filter_search_0']}</option><option value="1">{$lang['filter_search_1']}</option><option value="2">{$lang['filter_search_2']}</option></select>
				</div>
				</div>		 
				<div class="form-group">
				<label class="control-label col-md-3 col-sm-3">{$lang['filter_action']}</label>
				<div class="col-md-9 col-sm-9">
					<select name="filter_action" class="uniform"><option value="0">{$lang['filter_action_0']}</option><option value="1">{$lang['filter_action_1']}</option></select>
				</div>
				</div>	
			</div>
			<div class="modal-footer" style="margin-top:-1.39em;">
				<button type="button" class="btn bg-grey-400 btn-sm btn-raised" data-dismiss="modal">{$lang['p_cancel']}</button>
				<button type="submit" class="btn bg-teal btn-sm btn-raised position-left"><i class="fa fa-floppy-o position-left"></i>{$lang['user_save']}</button>
			</div>
			</form>
	</div>
</div>
</div>

<div class="panel panel-default">
  <div class="panel-heading">
    {$lang['word_worte']}
	<div class="heading-elements not-collapsible">
		<ul class="icons-list">
			<li><a href="#" data-toggle="modal" data-target="#advancedadd"><i class="fa fa-plus-circle position-left"></i><span class="visible-lg-inline visible-md-inline visible-sm-inline">{$lang['word_new_1']}</span></a></li>
		</ul>
	</div>	
  </div>
HTML;

$result = "";

foreach ($all_items as $key => $value ) {
	
	$value['find'] = htmlspecialchars($value['find'], ENT_QUOTES, 'UTF-8');

	$result .= "<tr><td>{$value['find']}</td><td>";
	
	if( !$value['replace'] ) {
		$result .= "<span class=\"text-danger\">{$lang['word_del']}</span>";
	} else {
		$result .= htmlspecialchars($value['replace'], ENT_QUOTES, 'UTF-8');
	}
	
	$type = $value['type'] ? $lang['filter_type_2'] : $lang['filter_type_1'];
	$register =  $value['use_case'] ? $lang['opt_sys_yes'] : $lang['opt_sys_no'];

	$menu_link = <<<HTML
		<div class="btn-group">
			<a href="#" class="dropdown-toggle nocolor" data-toggle="dropdown" aria-expanded="true"><i class="fa fa-bars"></i><span class="caret"></span></a>
			<ul class="dropdown-menu dropdown-menu-right">
				<li><a href="?mod=wordfilter&action=edit&word_id={$key}"><i class="fa fa-pencil-square-o"></i> {$lang['group_sel1']}</a></li>
				<li class="divider"></li>
				<li><a onclick="javascript:confirmDelete('{$key}'); return false;" href="#"><i class="fa fa-trash-o text-danger"></i> {$lang['xfield_xfid']}</a></li>
			</ul>
		</div>
HTML;

	$result .= "</td><td>{$register}</td><td>{$type}</td><td>{$lang['filter_search_'.$value['filter_search']]}</td><td>{$lang['filter_action_'.$value['filter_action']]}</td><td class=\"text-right\">{$menu_link}</td></tr>";
}

if( !$result ) {
	echo <<<HTML
<div class="panel-body">
<table width="100%">
    <tr>
        <td style="height:3.47em;"><div align="center">{$lang['word_empty']}</div></td>
    </tr>
</table>
</div>
HTML;

} else {

echo <<<HTML
  <div class="table-responsive">
	<table class="table table-xs table-hover">
      <thead>
      <tr>
        <td>{$lang['word_worte']}</td>
        <td>{$lang['word_lred']}</td>
        <td style="width: 10.42em">{$lang['filter_register']}</td>
        <td style="width: 13.89em">{$lang['filter_type']}</td>
        <td style="width: 13.89em">{$lang['filter_search']}</td>
		<td style="width: 13.89em">{$lang['filter_action']}</td>
        <td style="width: 6.94em"></td>
      </tr>
      </thead>
	  <tbody>
		{$result}
	  </tbody>
	</table>
   </div>	  
HTML;

}


echo <<<HTML
	<div class="panel-footer">
		<div class="pull-left">
			<button type="button" data-toggle="modal" data-target="#advancedadd" class="btn bg-teal btn-sm btn-raised position-left"><i class="fa fa-plus position-left"></i>{$lang['word_new_1']}</button>
		</div>
	</div>   
</div>
<script>
<!--

function confirmDelete(id){

	DLEconfirmDelete( '{$lang['del_filter']}', '{$lang['p_confirm']}', function () {

		document.location='?mod=wordfilter&action=remove&user_hash={$dle_login_hash}&word_id='+id;

	} );

}
//-->
</script>
HTML;

echofooter();