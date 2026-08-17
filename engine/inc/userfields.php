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
 File: userfields.php
-----------------------------------------------------
 Use: profile xfields
=====================================================
*/

if (!defined('DATALIFEENGINE')) {
	header("HTTP/1.1 403 Forbidden");
	header('Location: ../../');
	die("Hacking attempt!");
}

if (!$user_group[$member_id['user_group']]['admin_userfields']) {
	msg("error", $lang['index_denied'], $lang['index_denied']);
}

if ($_GET['action'] == "delete") {

	if (!isset($_REQUEST['user_hash']) or !$_REQUEST['user_hash'] or $_REQUEST['user_hash'] != $dle_login_hash) {
		die("Hacking attempt! User not found");
	}

	$name = DLEUserXFields::DeleteField($_GET['name']);

	if ($name) {
		$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '71', '{$name}')");

		header("Location: ?mod=userfields");
		die();
	} else {
		msg("error", $lang['xfield_error'], $lang['xfield_err_5'], "javascript:history.go(-1)");
	}
}

if ($_REQUEST['action'] == "doadd" or $_REQUEST['action'] == "doedit") {

	$editedxfield = isset($_POST['editedxfield']) ? $_POST['editedxfield'] : [];
	
	if (!is_array($editedxfield) or !count($editedxfield)) {
		msg("error", $lang['xfield_error'], $lang['xfield_err_8'], "javascript:history.go(-1)");
	}

	DLEUserXFields::Init();
	$editedxfield['name'] = totranslit(trim($editedxfield['name']));
	$editedxfield['description'] = strip_tags(stripslashes(trim($editedxfield['description'])));

	if (!$editedxfield['name'] OR !$editedxfield['description']) {
		msg("error", $lang['xfield_error'], $lang['xfield_err_12'], "javascript:history.go(-1)");
	}

	if( $_REQUEST['action'] == "doadd" AND isset( DLEUserXFields::$fields['fields'][$editedxfield['name']]) ) {
		msg("error", $lang['xfield_error'], $lang['xfield_err_9'], "javascript:history.go(-1)");
	}

	if ($_REQUEST['action'] == "doedit" AND (!isset($_REQUEST['editedname']) OR !isset(DLEUserXFields::$fields['fields'][$_REQUEST['editedname']] ) ) ) {
		msg("error", $lang['xfield_error'], $lang['xfield_err_8'], "javascript:history.go(-1)");
	}

	$editedxfield['type'] = totranslit(trim($editedxfield['type']));

	$editedxfield['registration'] = isset($editedxfield['registration']) ? intval($editedxfield['registration']) : 0;
	$editedxfield['allow_change'] = isset($editedxfield['allow_change']) ? intval($editedxfield['allow_change']) : 0;
	$editedxfield['private'] = isset($editedxfield['private']) ? intval($editedxfield['private']) : 0;
	$editedxfield['safe_mode'] = isset($editedxfield['safe_mode']) ? intval($editedxfield['safe_mode']) : 0;
	
	if ($editedxfield['type'] == "select") {

		$options = array();
		$editedxfield["default_select"] = str_replace("\r", '', $editedxfield["default_select"]);

		foreach (explode("\n", $editedxfield["default_select"]) as $name => $value) {
			$value = trim($value);
			if (!in_array($value, $options)) {
				$options[] = $value;
			}
		}

		if (count($options) < 2) {
			msg("error", $lang['xfield_error'], $lang['xfield_err_10'], "javascript:history.go(-1)");
		}

		$editedxfield['default'] = implode("\n", $options);
	}

	unset($editedxfield["default_select"]);
	
	if ($editedxfield['type'] == "datetime") {
		$editedxfield['date_format'] = intval($editedxfield['date_format']);
		$editedxfield['date_view_format'] = strip_tags(stripslashes(trim($editedxfield['date_view_format'])));
		$editedxfield['date_local'] = isset($editedxfield['date_local']) ? intval($editedxfield['date_local']) : 0;
		$editedxfield['date_declension'] = isset($editedxfield['date_declension']) ? intval($editedxfield['date_declension']) : 0;
	} else {
		$editedxfield['date_format'] = '';
		$editedxfield['date_view_format'] = '';
		$editedxfield['date_local'] = '';
		$editedxfield['date_declension'] = '';
	}

	DLEUserXFields::SaveField($editedxfield['name'], $editedxfield);

	if ($_REQUEST['action'] == "doedit" and $editedxfield['name'] != $_REQUEST['editedname']) {
		DLEUserXFields::DeleteField($_REQUEST['editedname']);
	}

	$db->query("INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('" . $db->safesql($member_id['name']) . "', '{$_TIME}', '{$_IP}', '72', '{$editedxfield['name']}')");
	
	header("Location: ?mod=userfields");
	die();

}

if ($_REQUEST['action'] == "add" or $_REQUEST['action'] == "edit") {
	
	$type_selected = array('text' => '', 'textarea' => '','select' => '', 'yesorno' => '', 'datetime' => '');
	$condition_selected = array('', '');
	$date_format_selected = array('', '', '');

	if ($_REQUEST['action'] == 'edit') {

		$lang['xfield_title'] = $lang['xfield_etitle'];
		$editedxfield = DLEUserXFields::GETField($_GET['name']);
		
		if (!count($editedxfield)) {
			msg("error", $lang['xfield_error'], $lang['xfield_err_9'], "javascript:history.go(-1)");
		}

		$checked = $editedxfield['safe_mode'] ? "checked" : "";
		$checked2 = $editedxfield['registration'] ? "checked" : "";
		$checked3 = $editedxfield['allow_change'] ? "checked" : "";
		$checked4 = $editedxfield['private'] ? "checked" : "";
		$checked5 = $editedxfield['date_local'] ? "checked" : "";
		$checked6 = $editedxfield['date_declension'] ? "checked" : "";

		$editedxfield['name'] = htmlspecialchars($editedxfield['name'], ENT_QUOTES, 'UTF-8');
		$editedxfield['description'] = htmlspecialchars($editedxfield['description'], ENT_QUOTES, 'UTF-8');
		$editedxfield['date_view_format'] = htmlspecialchars($editedxfield['date_view_format'], ENT_QUOTES, 'UTF-8');
		$type_selected[$editedxfield['type']] = 'selected';
		$condition_selected[$editedxfield['condition']] = 'selected';
		$date_format_selected[$editedxfield['date_format']] = 'selected';
		
		if ($editedxfield['type'] == "select") $defalult_select = htmlspecialchars($editedxfield['default'], ENT_QUOTES, 'UTF-8'); else $defalult_select = '';

	} else {
		
		$checked = "checked";
		$checked2 = "checked";
		$checked3 = "checked";
		$checked4 = "";
		$checked5 = "checked";
		$checked6 = "checked";
		
		$editedxfield['name'] = '';
		$editedxfield['description'] = '';
		$editedxfield['date_view_format'] = '';
		$type_selected['text'] = 'selected';
		$defalult_select = '';
		$condition_selected[0] = 'selected';
		$date_format_selected[0] = 'selected';

	}

	echoheader("<i class=\"fa fa-list position-left\"></i><span class=\"text-semibold\">{$lang['header_uf_1']}</span>", array('?mod=userfields'  => $lang['header_nf_1'], '' => $lang['xfield_title'] ) );

	echo <<<HTML
<form method="post" name="xfieldsform" class="form-horizontal">
	<input type="hidden" name="mod" value="userfields">
	<input type="hidden" name="user_hash" value="{$dle_login_hash}">
	<input type="hidden" name="action" value="do{$_REQUEST['action']}">
	<input type="hidden" name="action" value="do{$_REQUEST['action']}">
	<input type="hidden" name="editedname" value="{$editedxfield['name']}">

	<div class="panel panel-default">
		<div class="panel-heading">
			{$lang['xfield_title']}
		</div>
		<div class="panel-body">
			<div class="form-group">
				<label class="control-label col-md-2 col-sm-3">{$lang['xfield_xname']}</label>
				<div class="col-md-10 col-sm-9">
					<input class="form-control width-350" maxlength="30" type="text" dir="auto" name="editedxfield[name]" value="{$editedxfield['name']}"><span class="text-muted text-size-small"><i class="fa fa-exclamation-circle position-left position-right"></i>{$lang['xf_lat']}</span>
				</div>
			</div>
			<div class="form-group">
				<label class="control-label col-md-2 col-sm-3">{$lang['xfield_xdescr']}</label>
				<div class="col-md-10 col-sm-9">
					<input class="form-control width-350" maxlength="100" type="text" dir="auto" name="editedxfield[description]" value="{$editedxfield['description']}">
				</div>
			</div>
			<div class="form-group">
				<label class="control-label col-sm-2">{$lang['xfield_xtype']}</label>
				<div class="col-sm-10">
					<select class="uniform" name="editedxfield[type]" id="type" onchange="onTypeChange(this.value);">
						<option value="text" {$type_selected['text']}>{$lang['xfield_xstr']}</option>
						<option value="textarea" {$type_selected['textarea']}>{$lang['xfield_xarea']}</option>
						<option value="select" {$type_selected['select']}>{$lang['xfield_xsel']}</option>
						<option value="yesorno" {$type_selected['yesorno']}>{$lang['xfield_xyesorno']}</option>
						<option value="datetime" {$type_selected['datetime']}>{$lang['xfield_xdatetime']}</option>
					</select>
				</div>
			</div>
			<div class="form-group" id="select_options">
				<label class="control-label col-md-2 col-sm-3">{$lang['xfield_xfaul']}</label>
				<div class="col-md-10 col-sm-9">
					<textarea class="classic width-400" dir="auto" rows="5" name="editedxfield[default_select]">{$defalult_select}</textarea>
					<div class="text-muted text-size-small">{$lang['xfield_xfsel']}</div>
				</div>
			</div>
			<div id="optional" class="form-group">
				<label class="control-label col-sm-2">{$lang['xfield_xfaul']}</label>
				<div class="col-sm-10">
					<select class="uniform" name="editedxfield[condition]">
						<option value="0" {$condition_selected[0]}>{$lang['xfsel_off']}</option>
						<option value="1" {$condition_selected[1]}>{$lang['xfsel_on']}</option>
					</select>
				</div>
			</div>

			<div id="optional1">
				<div class="form-group">
					<label class="control-label col-sm-2">{$lang['xfield_xinput']}</label>
					<div class="col-sm-10">
						<select class="uniform" name="editedxfield[date_format]">
							<option value="0" {$date_format_selected[0]}>{$lang['xfield_xdatetime']}</option>
							<option value="1" {$date_format_selected[1]}>{$lang['xfsel_date']}</option>
							<option value="2" {$date_format_selected[2]}>{$lang['xfsel_time']}</option>
						</select>
					</div>
				</div>

				<div class="form-group mb-20">
					<label class="control-label col-sm-2">{$lang['xfield_xoutput']}</label>
					<div class="col-sm-10">
						<input class="form-control" style="width:100%;max-width: 13.89em;" type="text" dir="auto" name="editedxfield[date_view_format]" value="{$editedxfield['date_view_format']}"> <a onclick="javascript:Help('date'); return false;" href="#">{$lang['opt_sys_and']}</a>
					</div>
				</div>
			</div>

			<div id="optional4" class="form-group">
				<div class="form-group">
					<label class="control-label col-md-4 col-sm-6">{$lang['xfield_xlocaldate']}</label>
					<div class="col-md-8 col-sm-6">
						<input class="switch" type="checkbox" name="editedxfield[date_local]" value="1" {$checked5}><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xhelplocal']}"></i>
					</div>
				</div>
				<div class="form-group">
					<label class="control-label col-md-4 col-sm-6">{$lang['xfield_xdecldate']}</label>
					<div class="col-md-8 col-sm-6">
						<input class="switch" type="checkbox" name="editedxfield[date_declension]" value="1" {$checked6}><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xfield_xhelpdec']}"></i>
					</div>
				</div>
			</div>

			<div class="form-group" id="optional3">
				<label class="control-label col-md-4 col-sm-6">{$lang['opt_sys_sxfield']}</label>
				<div class="col-md-8 col-sm-6">
					<input class="switch" type="checkbox" name="editedxfield[safe_mode]" value="1" {$checked}><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['opt_sys_sxfieldd']}"></i>
				</div>
			</div>
			<div class="form-group">
				<label class="control-label col-md-4 col-sm-6">{$lang['xp_reg']}</label>
				<div class="col-md-8 col-sm-6">
					<input class="switch" type="checkbox" name="editedxfield[registration]" value="1" {$checked2}><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xp_reg_hint']}"></i>
				</div>
			</div>
			<div class="form-group">
				<label class="control-label col-md-4 col-sm-6">{$lang['xp_edit']}</label>
				<div class="col-md-8 col-sm-6">
					<input class="switch" type="checkbox" name="editedxfield[allow_change]" value="1" {$checked3}><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xp_edit_hint']}"></i>
				</div>
			</div>
			<div class="form-group">
				<label class="control-label col-md-4 col-sm-6">{$lang['xp_privat']}</label>
				<div class="col-md-8 col-sm-6">
					<input class="switch" type="checkbox" name="editedxfield[private]" value="1" {$checked4}><i class="help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right position-left" data-rel="popover" data-trigger="hover" data-placement="auto right" data-content="{$lang['xp_privat_hint']}"></i>
				</div>
			</div>

		</div>
		<div class="panel-footer">
			<button type="submit" class="btn bg-teal btn-sm btn-raised position-left"><i class="fa fa-floppy-o position-left"></i>{$lang['user_save']}</button>
		</div>
	</div>
	<script>
		function ShowOrHideEx(id, show) {
			item = document.getElementById(id);
			
			if (item && item.style) {
				item.style.display = show ? "" : "none";
			}
		}

		function onTypeChange(value) {
			ShowOrHideEx("select_options", value == "select");
			ShowOrHideEx("optional", value == "yesorno");
			ShowOrHideEx("optional1", value == "datetime");
			ShowOrHideEx("optional3", value == "text" || value == "textarea");
			ShowOrHideEx("optional4", value == "datetime");
		}

		onTypeChange(document.getElementById("type").value);
	</script>
</form>
HTML;

	echofooter();
	die();
}


$xfields =  DLEUserXFields::GETFields();

$js_array[] = "public/js/sortable.js";

echoheader("<i class=\"fa fa-list position-left\"></i><span class=\"text-semibold\">{$lang['header_uf_1']}</span>", array('?mod=userfields'  => $lang['header_nf_1'], '' => $lang['header_uf_2']));
if (!count($xfields)) {

	$x_list = "<div class=\"panel-body\"><center><br>{$lang['xfield_xnof']}<br><br></center></div>";

} else {

	$x_list = "";

	foreach ($xfields as $name => $value) {

		$p1 = $value['registration'] != 0 ? $lang['opt_sys_yes'] : $lang['opt_sys_no'];
		$p2 = $value['allow_change'] != 0 ? $lang['opt_sys_yes'] : $lang['opt_sys_no'];
		$p3 = $value['private'] != 0 ? $lang['opt_sys_yes'] : $lang['opt_sys_no'];

		if ($value['type'] == "text") $type = $lang['xfield_xstr'];
		elseif ($value['type'] == "textarea") $type = $lang['xfield_xarea'];
		elseif ($value['type'] == "select") $type = $lang['xfield_xsel'];
		elseif ($value['type'] == "yesorno") $type = $lang['xfield_xyesorno'];
		elseif ($value['type'] == "datetime") $type = $lang['xfield_xdatetime'];

		$menu_link = <<<HTML
		<div class="btn-group">
			<a href="#" class="dropdown-toggle nocolor" data-toggle="dropdown" aria-expanded="true"><i class="fa fa-bars"></i><span class="caret"></span></a>
			<ul class="dropdown-menu dropdown-menu-right">
				<li><a href="?mod=userfields&action=edit&name={$name}"><i class="fa fa-pencil-square-o"></i> {$lang['group_sel1']}</a></li>
				<li class="divider"></li>
				<li><a onclick="javascript:xfdelete('{$name}'); return false;" href="#"><i class="fa fa-trash-o text-danger"></i> {$lang['xfield_xfid']}</a></li>
			</ul>
		</div>
HTML;
		
if ($value['description']) {
			$description = "<div class=\"text-muted text-size-small\">{$value['description']}</div>";
		} else $description = '';

		$x_list .= "
		<tr class=\"drag-bg allow-drag\" data-id=\"{$name}\">
			<td class=\"dd-handles\"></td>
			<td class=\"cursor-pointer\" onclick=\"document.location = '?mod=userfields&action=edit&name={$name}'; return false;\">{$name}{$description}</td>
			<td class=\"cursor-pointer\" onclick=\"document.location = '?mod=userfields&action=edit&name={$name}'; return false;\">{$type}</td>
			<td class=\"cursor-pointer\" onclick=\"document.location = '?mod=userfields&action=edit&name={$name}'; return false;\">{$p1}</td>
			<td class=\"cursor-pointer\" onclick=\"document.location = '?mod=userfields&action=edit&name={$name}'; return false;\">{$p2}</td>
			<td class=\"cursor-pointer\" onclick=\"document.location = '?mod=userfields&action=edit&name={$name}'; return false;\">{$p3}</td>		
			<td class=\"text-center\">{$menu_link}</td>
		</tr>";
	}
	if ($x_list) {
		$th_head = <<<HTML
      <tr>
        <th style="width: 2.22em;"></th>
        <th>{$lang['xfield_xname']}</th>
		<th>{$lang['xfield_xtype']}</th>
		<th style="width: 11.11em;">{$lang['xp_regh']}</th>
        <th style="width: 11.11em;">{$lang['xp_edith']}</th>
		<th style="width: 11.11em;">{$lang['xp_privath']}</th>
        <th style="width: 4.86em">&nbsp;</th>
      </tr>
HTML;

	}

	$x_list = <<<HTML
	<table class="table table-xs table-hover" style="table-layout:fixed;">
		<thead>
			{$th_head}
		</thead>
		<tbody id="xflist">
			{$x_list}
		</tbody>	
	</table>
HTML;

}

echo <<<HTML
<div class="panel panel-default">
  <div class="panel-heading">
    {$lang['xp_xlist']}
  </div>
  <div class="table-responsive">
	{$x_list}
  </div>
	<div class="panel-footer">
		<div class="pull-left">
		<button type="button" onclick="document.location='?mod=userfields&action=add'" class="btn bg-teal btn-sm btn-raised position-left"><i class="fa fa-plus position-left"></i>{$lang['b_create']}</button>
		</div>
		<div class="pull-right">
		<a onclick="javascript:Help('xprofile'); return false;" href="#">{$lang['xfield_xhelp']}</a>
		</div>
	</div>
</div>
<script>
jQuery(function($){

    if( document.getElementById('xflist') ) {
		var xf_sort = new Sortable(document.getElementById('xflist'), {
			group: "xfield",
			animation: 150,
			ghostClass: 'drop-bg',
			handle: '.dd-handles',
			draggable: '.allow-drag',
			onChoose(evt) {
        		evt.item.classList.add('is-sorting');
    		},
    		onEnd(evt) {
        		evt.item.classList.remove('is-sorting');
    		},			
			onSort: function (evt) {
				ShowLoading('');

				$.post('index.php?controller=ajax&mod=adminfunction', {'action': 'userxfsort', 'list': window.JSON.stringify(xf_sort.toArray()), user_hash: '{$dle_login_hash}'}, function(data){

					HideLoading('');

					if (data != 'ok') {
						console.log();
						DLEPush.error('{$lang['cat_sort_fail']}');

					} else {
						
						location.reload();
						
					}

				});

			}

		});
	}

});

function xfdelete(id){

	DLEconfirmDelete( '{$lang['xfield_err_6']}', '{$lang['p_confirm']}', function () {
		document.location='?mod=userfields&action=delete&name=' + id +'&user_hash={$dle_login_hash}';
	} );
}
</script>
HTML;

echofooter();