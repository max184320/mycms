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
 File: xfields.class.php
-----------------------------------------------------
 Use: DLE Extra Fields Class
=====================================================
*/

if( !defined( 'DATALIFEENGINE' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

abstract class DLEXFields {

	public static $fields = null;
	public static $error = null;
	public static $fill_opengraph = false;
	public static $video_config = array();

	public static function Init() {
		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}
	}

	public static function FieldsList($row = null, $xfieldmode = '') {
		global $member_id, $config, $parse, $dark_theme, $lang, $dle_login_hash;

		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		$_ROOT_DLE_URL = substr($_SERVER['SCRIPT_NAME'], 0, (int)strrpos($_SERVER['SCRIPT_NAME'], '/') + 1) ?: '/';

		$xfieldinput = array();
		$fields_list = array();
		$js_scripts = array();
		$js_functions = array();
		$js_functions[] = self::category_filter_js();

		$dark_theme = isset($dark_theme) ? $dark_theme : '';

		$config['file_chunk_size'] =  number_format(floatval($config['file_chunk_size']), 1, '.', '');
		if ($config['file_chunk_size'] < 1) $config['file_chunk_size'] = '1.5';

		if( $row === null ) {
			$author = urlencode($member_id['name']);
			$news_id = 0;
			$xfieldsdata = [];
		} else {
			$author = urlencode($row['autor']);
			$news_id = $row['id'];
			$xfieldsdata = self::xfieldsdataload($row['xfields']);
		}

		foreach (self::$fields['fields'] as $name => $value) {
			$fieldname = $name;
			$fieldcount = md5($name);
			$holderid = "xfield_holder_$name";

			if ($value['allow_add_usergroups']) {

				$value['allow_add_usergroups'] = explode(',', $value['allow_add_usergroups']);

				if ($value['allow_add_usergroups'][0] AND !in_array($member_id['user_group'], $value['allow_add_usergroups'])) {
					continue;
				}
			}

			$value['description'] = htmlspecialchars($value['description'], ENT_QUOTES, 'UTF-8');
			$value['hint'] = htmlspecialchars($value['hint'], ENT_QUOTES, 'UTF-8');
			
			if ($value['allow_in_news']) {
				$value['allow_in_news'] = "<a class=\"clipboard-copy-link position-right\" onclick=\"DLECopyText('{$fieldname}'); return false;\" href=\"#\" rel=\"tooltip\" title=\"{$lang['xf_copy']}\"><svg xmlns=\"http://www.w3.org/2000/svg\" style=\"width: 1.11em; height: 1.11em;\" viewBox=\"0 0 16 16\"><path fill=\"none\" stroke=\"currentcolor\" d=\"M11 1.5h2.5v12a1 1 0 0 1-1 1h-10a1 1 0 0 1-1-1v-12H4m1 7l2 2l3.5-4m-6-6h6v2a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1z\" stroke-width=\"1\"></svg></a>";
			} else $value['allow_in_news'] = '';

			if ($xfieldmode == "site") {
				if ($value['hint']) $value['hint'] = "<div class=\"xfieldsnote\">{$value['hint']}</div>";

			} else {

				if ($value['hint']) {
					$value['hint'] = "<i class=\"help-button visible-lg-inline-block text-primary-600 fa fa-question-circle position-right\" data-rel=\"popover\" data-trigger=\"hover\" data-placement=\"right\" data-content=\"{$value['hint']}\"></i>";
				}
			}
			
			$fieldvalue = '';
			$value['disabled'] = isset($value['disabled']) ? $value['disabled'] : false;

			if ( $news_id ) {

				$fieldvalue = isset($xfieldsdata[$value['name']]) ? (string)$xfieldsdata[$value['name']] : '';

				$fieldvalue = str_ireplace("&#123;title", "{title", $fieldvalue);
				$fieldvalue = str_ireplace("&#123;short-story", "{short-story", $fieldvalue);
				$fieldvalue = str_ireplace("&#123;full-story", "{full-story", $fieldvalue);

				if ($value['safe_mode'] OR $value['use_as_links'] OR $value['type'] == "image" OR $value['type'] == "imagegalery" OR $value['type'] == "video" OR $value['type'] == "audio" OR $value['type'] == "file" OR $value['type'] == "select") {
					$fieldvalue = str_replace("&#44;", "&amp;#44;", $fieldvalue);
					$fieldvalue = str_replace("&#124;", "&amp;#124;", $fieldvalue);
					$fieldvalue = html_entity_decode(stripslashes($fieldvalue), ENT_QUOTES, 'UTF-8');
					$fieldvalue = htmlspecialchars($fieldvalue, ENT_QUOTES, 'UTF-8');
				} elseif ($value['type'] == "htmljs") {

					$fieldvalue = htmlspecialchars($fieldvalue, ENT_QUOTES, 'UTF-8');
				} elseif ($value['type'] == "datetime") {

					if ($fieldvalue) {

						$fieldvalue = str_replace("&#58;", ":", $fieldvalue);
						$fieldvalue = @strtotime($fieldvalue);

						if ($fieldvalue !== -1 and $fieldvalue) {

							if ($value['date_format'] == 1) $fieldvalue = date("Y-m-d", $fieldvalue);
							elseif ($value['date_format'] == 2) $fieldvalue = date("H:i", $fieldvalue);
							else $fieldvalue = date("Y-m-d H:i", $fieldvalue);

						} else $fieldvalue = "";
					}
				} else {
					$fieldvalue = $parse->decodeBBCodes($fieldvalue, true, true);
				}
				
				$fieldvalue = str_replace(array("{", "["), array("&#123;", "&#91;"), $fieldvalue);

			} elseif ($value['type'] != "select" and $value['type'] != "image" and $value['type'] != "imagegalery" and $value['type'] != "video" and $value['type'] != "audio" and $value['type'] != "file" and $value['type'] != "yesorno") {
				if( isset($value['default']) AND !$value['disabled'] ) $fieldvalue = htmlspecialchars($value['default'], ENT_QUOTES, 'UTF-8');
			}

			if( $value['disabled'] AND (!$news_id OR $fieldvalue === '') ) {
				continue;
			}

			if ($value['type'] == "textarea") {

				$params = "";
				$noborder = "";

				if ($value['use_editor']) {
					$params = "class=\"wysiwygeditor\" ";
				} else {
					$params = "class=\"classic quick-edit-textarea\" ";
					$noborder = " no-border";
				}

				if (!$value['not_required'] AND !$value['disabled']) {
					$uid = "uid=\"essential\" ";
					$params .= "rel=\"essential\" ";
				} else {
					$uid = "";
				}

				if ($value['min']) {
					$uid .= "data-blockminlen=\"true\" ";
					$params .= "data-minlen=\"{$value['min']}\" ";
				}

				if ($value['max']) {
					$uid .= "data-blockmaxlen=\"true\" ";
					$params .= "maxlength=\"{$value['max']}\" data-maxlen=\"{$value['max']}\" ";
				}

				$fid = preg_replace('#[\-]+#i', '_', $fieldname);

				if ($xfieldmode == "site") {

					if ($value['use_editor']) {

						$output = <<<HTML
<div id="{$holderid}" class="xfieldsrow" {$uid}>{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional] {$value['allow_in_news']}
	<div class="wseditor {$dark_theme}"><textarea dir="auto" name="xfield[{$fieldname}]" id="xf_{$fid}" data-alert="{$value['description']}" {$params}>{$fieldvalue}</textarea>{$value['hint']}</div>
</div>
HTML;

						$xfieldinput[$fieldname] = "<div class=\"wseditor\"><textarea dir=\"auto\" name=\"xfield[$fieldname]\" id=\"xf_$fid\" data-alert=\"{$value['description']}\" {$params}>{$fieldvalue}</textarea></div>";

					} else {

						$output = <<<HTML
<div id="{$holderid}" class="xfieldsrow" {$uid}>{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional] {$value['allow_in_news']}
	<textarea dir="auto" name="xfield[{$fieldname}]" id="xf_{$fid}" data-alert="{$value['description']}" {$params}>{$fieldvalue}</textarea>{$value['hint']}
</div>
HTML;
						$xfieldinput[$fieldname] = "<textarea dir=\"auto\" name=\"xfield[$fieldname]\" id=\"xf_{$fid}\" data-alert=\"{$value['description']}\" {$params}>{$fieldvalue}</textarea>";
					}
				} else {

					$output = <<<HTML
<div id="{$holderid}" class="form-group editor-group" {$uid}>
  <label class="control-label col-md-2">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional]{$value['allow_in_news']}{$value['hint']}</label>
  <div class="col-md-10">
     <div class="editor-panel{$noborder}"><textarea dir="auto" style="width:100%;height:300px;" name="xfield[$fieldname]" id="xf_$fid" data-alert="{$value['description']}" {$params}>{$fieldvalue}</textarea></div>
  </div>
</div>
HTML;
				}

			} elseif ($value['type'] == "htmljs") {

				$params = "";

				if (!$value['not_required'] AND !$value['disabled']) {
					$uid = "uid=\"essential\" ";
					$params .= "rel=\"essential\" ";
				} else {
					$uid = "";
				}

				if ($xfieldmode == "site") {

					$output = <<<HTML
<div id="{$holderid}" class="xfieldsrow" {$uid}>{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional] {$value['allow_in_news']}
	<textarea dir="auto" name="xfield[$fieldname]" id="xf_$fieldname" data-alert="{$value['description']}" class="quick-edit-textarea" {$params}>{$fieldvalue}</textarea>{$value['hint']}
</div>
HTML;
					$xfieldinput[$fieldname] = "<textarea dir=\"auto\" name=\"xfield[$fieldname]\" id=\"xf_$fieldname\" data-alert=\"{$value['description']}\" {$params}>{$fieldvalue}</textarea>";
				} else {

					$output = <<<HTML
<div id="{$holderid}" class="form-group editor-group" {$uid}>
  <label class="control-label col-md-2">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional]{$value['allow_in_news']}{$value['hint']}</label>
  <div class="col-md-10">
     <textarea dir="auto" class="classic" style="width:100%;height:300px;max-width: 950px;" name="xfield[{$fieldname}]" id="xf_{$fieldname}" data-alert="{$value['description']}" {$params}>{$fieldvalue}</textarea>
  </div>
</div>
HTML;
				}
			} elseif ($value['type'] == "text") {

				if (!$value['not_required'] AND !$value['disabled']) {
					$params = "rel=\"essential\" ";
					$uid = "uid=\"essential\" ";
				} else {

					$params = "";
					$uid = "";
				}

				if ($value['use_as_links']) {
					$params .= "data-rel=\"links\" ";
				}

				if ($value['min']) {
					$uid .= "data-blockminlen=\"true\" ";
					$params .= "data-minlen=\"{$value['min']}\" ";
				}

				if ($value['max']) {
					$uid .= "data-blockmaxlen=\"true\" ";
					$params .= "maxlength=\"{$value['max']}\" data-maxlen=\"{$value['max']}\" ";
				}

				if ($xfieldmode == "site") {

					$output = <<<HTML
<div id="{$holderid}" class="xfieldsrow" {$uid}>
	<div class="xfieldscolleft">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional] {$value['allow_in_news']}</div>
	<div class="xfieldscolright"><input type="text" name="xfield[{$fieldname}]" id="xf_{$fieldname}" data-alert="{$value['description']}" value="{$fieldvalue}" class="quick-edit-text" {$params}>{$value['hint']}</div>
</div>
HTML;

					$xfieldinput[$fieldname] = "<input type=\"text\" dir=\"auto\" name=\"xfield[$fieldname]\" data-alert=\"{$value['description']}\" id=\"xf_$fieldname\" value=\"$fieldvalue\" {$params}>";
				} else {

					$output = <<<HTML
<div id="{$holderid}" class="form-group" {$uid}>
  <label class="control-label col-sm-2">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional]</label>
  <div class="col-sm-10">
     <input type="text" dir="auto" class="form-control width-500" name="xfield[{$fieldname}]" id="xf_{$fieldname}" data-alert="{$value['description']}" value="{$fieldvalue}" {$params}>{$value['allow_in_news']}{$value['hint']}
  </div>
</div>
HTML;
				}
			} elseif ($value['type'] == "datetime") {

				if (!$value['not_required'] AND !$value['disabled']) {
					$params = "rel=\"essential\" ";
					$uid = "uid=\"essential\" ";
				} else {

					$params = "";
					$uid = "";
				}


				if ($value['date_format'] == 1) {
					$params .= "data-rel=\"calendardate\" ";
				} elseif ($value['date_format'] == 2) {
					$params .= "data-rel=\"calendartime\" ";
				} else {
					$params .= "data-rel=\"calendardatetime\" ";
				}

				if ($xfieldmode == "site") {

					$output = <<<HTML
<div id="{$holderid}" class="xfieldsrow" {$uid}>
	<div class="xfieldscolleft">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional] {$value['allow_in_news']}</div>
	<div class="xfieldscolright"><input type="text" name="xfield[{$fieldname}]" id="xf_{$fieldname}" data-alert="{$value['description']}" autocomplete="off" value="{$fieldvalue}" class="quick-edit-datetime" {$params}>{$value['hint']}</div>
</div>
HTML;

					$xfieldinput[$fieldname] = "<input type=\"text\" dir=\"auto\" name=\"xfield[{$fieldname}]\" id=\"xf_{$fieldname}\" data-alert=\"{$value['description']}\" autocomplete=\"off\" value=\"{$fieldvalue}\" {$params}>";
				} else {

					$output = <<<HTML
<div id="{$holderid}" class="form-group" {$uid}>
  <label class="control-label col-sm-2">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional]</label>
  <div class="col-sm-10">
     <input type="text" dir="auto" class="form-control" style="width:200px;" name="xfield[{$fieldname}]" id="xf_{$fieldname}" data-alert="{$value['description']}" autocomplete="off" value="{$fieldvalue}" {$params}>{$value['allow_in_news']}{$value['hint']}
  </div>
</div>
HTML;
				}

			} elseif ($value['type'] == "select") {

				if (!$value['not_required']  AND !$value['disabled']) {
					$params = "rel=\"essential\" ";
					$uid = "uid=\"essential\" ";
				} else {

					$params = "";
					$uid = "";
				}

				if ($value['allow_multi']) {
					$sel_multiple = "data-alert=\"{$value['description']}\" data-placeholder=\" \" class=\"categoryselect quick-edit-select\" multiple";
				} else {
					$sel_multiple = "data-alert=\"{$value['description']}\" class=\"uniform quick-edit-select\"";
				}

				if ($xfieldmode == "site") {
					$select = "<select name=\"xfield[$fieldname][]\" {$sel_multiple} {$params}>";
				} else {
					$select = "<select name=\"xfield[$fieldname][]\" style=\"width:100%;max-width:350px;\" {$sel_multiple} {$params}>";
				}

				if (!isset($fieldvalue)) $fieldvalue = "";

				$fieldvalue = str_replace('&amp;', '&', $fieldvalue);
				$fieldvalue = explode(',', $fieldvalue);
				$fieldvalue = array_map('clear_select', $fieldvalue);
				
				$value['default'] = str_replace("\r", '', $value['default']);

				foreach (explode("\n", htmlspecialchars($value['default'], ENT_QUOTES, 'UTF-8')) as $index1 => $value1) {

					$value1 = explode("|", $value1);
					if (count($value1) < 2) $value1[1] = $value1[0];

					$select .= "<option value=\"$index1\"" . (in_array($value1[0], $fieldvalue) ? " selected" : "") . ">{$value1[1]}</option>\r\n";
				}

				$select .= "</select>";

				if ($xfieldmode == "site") {

					$output = <<<HTML
<div id="{$holderid}" class="xfieldsrow" {$uid}>
	<div class="xfieldscolleft">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional] {$value['allow_in_news']}</div>
	<div class="xfieldscolright">{$select}{$value['hint']}</div>
</div>
HTML;

					$xfieldinput[$fieldname] = $select;
				} else {

					$output = <<<HTML
<div id="{$holderid}" class="form-group" {$uid}>
  <label class="control-label col-sm-2">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional]</label>
  <div class="col-sm-10">{$select}{$value['allow_in_news']}{$value['hint']}
  </div>
</div>
HTML;
				}
			} elseif ($value['type'] == "yesorno") {

				if (!isset($fieldvalue) or $fieldvalue === '') $fieldvalue = $value['condition'];

				$fieldvalue = intval($fieldvalue);
				$selected = $fieldvalue ? " checked" : "";

				if ($xfieldmode == "site") {

					$output = <<<HTML
<div id="{$holderid}" class="xfieldsrow">
	<div class="xfieldscolleft">{$value['description']}: {$value['allow_in_news']}</div>
	<div class="xfieldscolright"><label class="form-check-label"><input class="form-check-input" type="checkbox" name="xfield[{$fieldname}]" value="1"{$selected}><span>{$value['hint']}</span></label></div>
</div>
HTML;

					$xfieldinput[$fieldname] = "<label class=\"form-check-label\"><input class=\"form-check-input\" type=\"checkbox\" name=\"xfield[{$fieldname}]\" value=\"1\"{$selected}></label>";
				} else {

					$output = <<<HTML
<div id="{$holderid}" class="form-group">
  <label class="control-label col-sm-2">{$value['description']}:</label>
  <div class="col-sm-10"><input class="switch" type="checkbox" name="xfield[{$fieldname}]" value="1"{$selected}>{$value['allow_in_news']}{$value['hint']}
  </div>
</div>
HTML;
				}

			} elseif ($value['type'] == "image") {

				$max_file_size = (int)$value['image_max_size'] * 1024;

				if ($fieldvalue) {

					$temp_array = explode('|', $fieldvalue);

					if (count($temp_array) == 1 or count($temp_array) == 5) {

						$temp_alt = '';
						$temp_value = implode('|', $temp_array);
					} else {

						$temp_alt = $temp_array[0];
						unset($temp_array[0]);
						$temp_value =  implode('|', $temp_array);
					}

					$dataimage = get_uploaded_image_info($temp_value);

					if ($value['make_thumb'] and $dataimage->thumb) {
						$img_url = 	$dataimage->thumb;
					} else {
						$img_url = 	$dataimage->url;
					}

					$filename = explode("_", $dataimage->name);
					if (count($filename) > 1 and strlen($filename[0]) == 10) unset($filename[0]);
					$filename = implode("_", $filename);

					$base_name = pathinfo($filename, PATHINFO_FILENAME);
					$file_type = explode(".", $filename);
					$file_type = totranslit(end($file_type));

					$xf_id = md5($temp_value);

					$up_image = "<div class=\"file-preview-card uploadedfile\" id=\"xf_{$xf_id}\" data-id=\"{$temp_value}\" data-alt=\"{$temp_alt}\"><div class=\"active-ribbon\"><span><i class=\"mediaupload-icon mediaupload-icon-ok\"></i></span></div><div class=\"file-content select-disable\"><div class=\"file-ext\">{$file_type}</div><img src=\"{$img_url}\" class=\"file-preview-image\"></div><div class=\"file-footer\"><div class=\"file-footer-caption\"><div class=\"file-caption-info\" rel=\"tooltip\" title=\"{$filename}\">{$base_name}</div><div class=\"file-size-info\">{$dataimage->dimension} ({$dataimage->size})</div></div><div class=\"file-footer-bottom\"><div class=\"file-preview\"><a onclick=\"xfaddalt('" . $xf_id . "', '" . $fieldname . "');return false;\" href=\"#\" rel=\"tooltip\" title=\"{$lang['xf_img_descr']}\"><i class=\"mediaupload-icon mediaupload-icon-edit\"></i></a></div><div class=\"file-delete\"><a onclick=\"xfimagedelete('" . $fieldname . "','" . $temp_value . "');return false;\" href=\"#\"><i class=\"mediaupload-icon mediaupload-icon-trash\"></i></a></div></div></div></div>";
				} else $up_image = "";

				if (!$value['not_required']  AND !$value['disabled'] ) {
					$params = "rel=\"essential\" ";
					$uid = "uid=\"essential\" ";
				} else {

					$params = "";
					$uid = "";
				}

				$max_file_size = number_format($max_file_size, 0, '', '');


				$uploadscript = <<<HTML

file_uploaders['{$fieldname}'] = new plupload.Uploader({

    runtimes : 'html5',
    file_data_name: "qqfile",
    browse_button: 'upload_button_{$fieldname}',
    container: document.getElementById('xfupload_{$fieldname}'),
	drop_element: document.getElementById('xfupload_{$fieldname}'),
    url: "{$_ROOT_DLE_URL}index.php?controller=ajax&mod=upload",
	multipart_params: {"subaction" : "upload", "news_id" : "{$news_id}", "area" : "xfieldsimage", "author" : "{$author}", "xfname" : "{$fieldname}", "user_hash" : "{$dle_login_hash}"},
	multi_selection: false,
	chunk_size: '{$config['file_chunk_size']}mb',
     
    filters : {
        max_file_size : '{$max_file_size}',
        mime_types: [
            {title : "Image files", extensions : "gif,jpg,jpeg,png,bmp,webp,avif"}
        ]
    },
     
 
    init: {
 
        FilesAdded: function(up, files) {
		
            plupload.each(files, function(file) {
				$('<div id="uploadfile-'+file.id+'" class="file-box"><span class="qq-upload-file-status">{$lang['media_upload_st6']}</span><span class="qq-upload-file">'+file.name+'</span><span class="qq-status"><span class="qq-upload-spinner"></span><span class="qq-upload-size"></span></span><div class="progress"><div class="progress-bar progress-blue" style="width: 0%"><span>0%</span></div></div></div>').appendTo('#xfupload_{$fieldname}');
            });
			
			up.start();
        },
 
        UploadProgress: function(up, file) {
		
			  $('#uploadfile-'+file.id+' .qq-upload-size').text(plupload.formatSize(file.loaded) + ' {$lang['media_upload_st8']} ' + plupload.formatSize(file.origSize));
			  $('#uploadfile-'+file.id+' .progress-bar').css( "width", file.percent + '%' );
			  $('#uploadfile-'+file.id+' .qq-upload-spinner').css( "display", "inline-block");

        },
		
		FileUploaded: function(up, file, result) {
		
				try {
				   var response = JSON.parse(result.response);
				} catch (e) {
					var response = '';
				}
				
				if( result.status == 200 ) {
				
					if ( response.success ) {
					
						var returnbox = response.returnbox;
						var returnval = response.xfvalue;

						returnbox = returnbox.replace(/&lt;/g, "<");
						returnbox = returnbox.replace(/&gt;/g, ">");
						returnbox = returnbox.replace(/&amp;/g, "&");

						$('#uploadfile-'+file.id+' .qq-status').html('{$lang['media_upload_st9']}');
						$('#uploadedfile_{$fieldname}').html( returnbox );
						$('#xf_{$fieldname}').val(returnval);

						$('#upload_button_{$fieldname}').attr("disabled","disabled");
						
						up.disableBrowse(true);
						
						setTimeout(function() {
						
							$('#uploadfile-'+file.id).fadeOut('slow', function() { $(this).remove(); up.refresh();});
							
						}, 1000);
						
						$('#mediaupload').remove();

					} else {
					
						$('#uploadfile-'+file.id+' .qq-status').html('{$lang['media_upload_st10']}');

						if( response.error ) $('#uploadfile-'+file.id+' .qq-status').append( '<br><span class="text-danger">' + response.error + '</span>' );

						setTimeout(function() {
							$('#uploadfile-'+file.id).fadeOut('slow', function() { $(this).remove(); up.refresh(); });
						}, 10000);
					}
						
				} else {
				
					$('#uploadfile-'+file.id+' .qq-status').append( '<br><span class="text-danger">HTTP Error:' + result.status + '</span>' );
					
					setTimeout(function() {
						$('#uploadfile-'+file.id).fadeOut('slow', function() { $(this).remove(); up.refresh(); });
					}, 10000);
				}

				up.refresh();
				
        },
		
        Error: function(up, err) {

			var type_err = '{$lang['media_upload_st11']}';
			var size_err = '{$lang['media_upload_st12']}';
			
			type_err = type_err.replace('{file}', err.file.name);
			type_err = type_err.replace('{extensions}', up.settings.filters.mime_types[0].extensions);
			size_err = size_err.replace('{file}', err.file.name);
			size_err = size_err.replace('{sizeLimit}', plupload.formatSize(up.settings.filters.max_file_size));
			
			if(err.code == '-600') {
			
				DLEPush.error(size_err);
				
			} else if(err.code == '-601') {
			
				DLEPush.error(type_err);
				
			} else {
			
				if( err.response ) {
				
					try {
					   var response = JSON.parse(err.response);
					} catch (e) {
						var response = '';
					}
					
					if( response.error ){
					
						DLEPush.error(response.error);
						
					} else {
					
						DLEPush.error(err.message);
						
					}

				} else {
					DLEPush.error(err.message);
				}
				
			}
		
        }
    }
});

file_uploaders['{$fieldname}'].init();

if($('#xf_{$fieldname}').val() != "" ) {
	$('#upload_button_{$fieldname}').attr("disabled","disabled");
	setTimeout(function() {
		file_uploaders['{$fieldname}'].disableBrowse(true);
	}, 100);
}

if ( typeof Sortable != "undefined"  ) {

	var sortable_{$fieldcount} = Sortable.create(document.getElementById('uploadedfile_{$fieldname}'), {
		group: {
		name: 'xfuploadedimages',
		put: function (to, from) {

			if(from.options.group.name != to.options.group.name ){
				return false;
			}

			return to.el.children.length < 1;
		}
		},
		handle: '.file-content',
		draggable: '.uploadedfile',
		onSort: function (evt) {
			
			if( sortable_{$fieldcount}.el.children.length ) {
				$('#upload_button_{$fieldname}').attr("disabled","disabled");
				file_uploaders['{$fieldname}'].disableBrowse(true);
			} else {
				$('#upload_button_{$fieldname}').removeAttr('disabled');
				file_uploaders['{$fieldname}'].disableBrowse(false);
			}
			
			xfsinc('{$fieldname}');
			file_uploaders['{$fieldname}'].refresh();
		},
		animation: 150
	});
	
}
	
HTML;

				$js_scripts[] = <<<HTML
if ($('#xfupload_{$fieldname}').length){
	{$uploadscript}
}
HTML;

				if ($xfieldmode == "site") {

					$output = <<<HTML
<div id="{$holderid}" class="xfieldsrow" {$uid}>
	<div class="xfieldscolleft">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional] {$value['allow_in_news']}</div>
	<div class="xfieldscolright"><div id="xfupload_{$fieldname}"><div class="qq-uploader"><div id="uploadedfile_{$fieldname}">{$up_image}</div><div id="upload_button_{$fieldname}" class="qq-upload-button btn btn-green bg-teal btn-sm btn-raised" style="width: auto;">{$lang['xfield_xfim']}</div></div></div><input type="hidden" name="xfield[$fieldname]" id="xf_{$fieldname}" data-alert="{$value['description']}" value="{$fieldvalue}" {$params}>{$value['hint']}</div>
</div>
HTML;

					$xfieldinput[$fieldname] = "<div id=\"xfupload_{$fieldname}\"><div class=\"qq-uploader\"><div id=\"uploadedfile_{$fieldname}\">{$up_image}</div><div id=\"upload_button_{$fieldname}\" class=\"qq-upload-button btn btn-green bg-teal btn-sm btn-raised\" style=\"width: auto;\">{$lang['xfield_xfim']}</div></div></div><input type=\"hidden\" name=\"xfield[$fieldname]\" id=\"xf_$fieldname\" data-alert=\"{$value['description']}\" value=\"{$fieldvalue}\" {$params}/>";
				} else {

					$output = <<<HTML
<div id="{$holderid}" class="form-group" {$uid}>
  <label class="control-label col-sm-2">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional]</label>
  <div class="col-sm-10"><div id="xfupload_{$fieldname}"><div class="qq-uploader"><div id="uploadedfile_{$fieldname}">{$up_image}</div><div id="upload_button_{$fieldname}" class="qq-upload-button btn btn-green bg-teal btn-sm btn-raised" style="width: auto;">{$lang['xfield_xfim']}</div>{$value['allow_in_news']}{$value['hint']}</div></div><input type="hidden" name="xfield[{$fieldname}]" id="xf_{$fieldname}" data-alert="{$value['description']}" value="{$fieldvalue}" {$params}>
  </div>
</div>
HTML;
				}
			} elseif ($value['type'] == "imagegalery") {

				$max_file_size = (int)$value['image_max_size'] * 1024;

				if ($fieldvalue) {
					$fieldvalue_arr = explode(',', $fieldvalue);
					$up_image = array();

					foreach ($fieldvalue_arr as $temp_value) {

						$temp_value = trim($temp_value);

						if ($temp_value == "") continue;

						$temp_array = explode('|', $temp_value);

						if (count($temp_array) == 1 or count($temp_array) == 5) {

							$temp_alt = '';
							$temp_value = implode('|', $temp_array);
						} else {

							$temp_alt = $temp_array[0];
							unset($temp_array[0]);
							$temp_value =  implode('|', $temp_array);
						}

						$dataimage = get_uploaded_image_info($temp_value);

						if ($value['make_thumb'] and $dataimage->thumb) {
							$img_url = 	$dataimage->thumb;
						} else {
							$img_url = 	$dataimage->url;
						}

						$filename = explode("_", $dataimage->name);
						if (count($filename) > 1 and strlen($filename[0]) == 10) unset($filename[0]);
						$filename = implode("_", $filename);

						$base_name = pathinfo($filename, PATHINFO_FILENAME);
						$file_type = explode(".", $filename);
						$file_type = totranslit(end($file_type));

						$xf_id = md5($temp_value);

						$up_image[] = "<div class=\"file-preview-card uploadedfile\" id=\"xf_{$xf_id}\" data-id=\"{$temp_value}\" data-alt=\"{$temp_alt}\"><div class=\"active-ribbon\"><span><i class=\"mediaupload-icon mediaupload-icon-ok\"></i></span></div><div class=\"file-content select-disable\"><div class=\"file-ext\">{$file_type}</div><img src=\"{$img_url}\" class=\"file-preview-image\"></div><div class=\"file-footer\"><div class=\"file-footer-caption\"><div class=\"file-caption-info\" rel=\"tooltip\" title=\"{$filename}\">{$base_name}</div><div class=\"file-size-info\">{$dataimage->dimension} ({$dataimage->size})</div></div><div class=\"file-footer-bottom\"><div class=\"file-preview\"><a onclick=\"xfaddalt('" . $xf_id . "', '" . $fieldname . "');return false;\" href=\"#\" rel=\"tooltip\" title=\"{$lang['xf_img_descr']}\"><i class=\"mediaupload-icon mediaupload-icon-edit\"></i></a></div><div class=\"file-delete\"><a onclick=\"xfimagegalerydelete_{$fieldcount}('" . $fieldname . "','" . $temp_value . "', '" . $xf_id . "');return false;\" href=\"#\"><i class=\"mediaupload-icon mediaupload-icon-trash\"></i></a></div></div></div></div>";
					}

					$totaluploadedfiles = count($up_image);
					$up_image = implode($up_image);
				} else {
					$up_image = "";
					$totaluploadedfiles = 0;
				}

				if (!$value['not_required']  AND !$value['disabled']) {
					$params = "rel=\"essential\" ";
					$uid = "uid=\"essential\" ";
				} else {

					$params = "";
					$uid = "";
				}

				$del_function = <<<HTML
	var maxallowfiles_{$fieldcount} = {$value['max_images']};
	var totaluploaded_{$fieldcount} = {$totaluploadedfiles};
	var totalqueue_{$fieldcount} = 0;
	
	function xfimagegalerydelete_{$fieldcount} ( xfname, xfvalue, id )
	{
		DLEconfirmDelete( '{$lang['image_delete']}', '{$lang['p_info']}', function () {
		
			ShowLoading('');
	
			$.post('{$_ROOT_DLE_URL}index.php?controller=ajax&mod=upload', { subaction: 'deluploads', user_hash: '{$dle_login_hash}', news_id: '{$news_id}', author: '{$author}', 'images[]' : xfvalue }, function(data){
	
				HideLoading('');

				$('#xf_'+id).remove();
				totaluploaded_{$fieldcount} --;
				xfsinc('{$fieldname}');
				
				$('#xfupload_' + xfname + ' .qq-upload-button').removeAttr('disabled');
				
				if (typeof file_uploaders[xfname] !== 'undefined') {
					file_uploaders[xfname].disableBrowse(false);
					file_uploaders[xfname].refresh();
				}
				
				$('#mediaupload').remove();
				
			});
			
		} );
		
		return false;

	};
HTML;

				$max_file_size = number_format($max_file_size, 0, '', '');

				$uploadscript = <<<HTML

file_uploaders['{$fieldname}'] = new plupload.Uploader({

    runtimes : 'html5',
    file_data_name: "qqfile",
    browse_button: 'upload_button_{$fieldname}',
    container: document.getElementById('xfupload_{$fieldname}'),
	drop_element: document.getElementById('xfupload_{$fieldname}'),
    url: "{$_ROOT_DLE_URL}index.php?controller=ajax&mod=upload",
	multipart_params: {"subaction" : "upload", "news_id" : "{$news_id}", "area" : "xfieldsimagegalery", "author" : "{$author}", "xfname" : "{$fieldname}", "user_hash" : "{$dle_login_hash}"},

	chunk_size: '{$config['file_chunk_size']}mb',
     
    filters : {
        max_file_size : '{$max_file_size}',
        mime_types: [
            {title : "Image files", extensions : "gif,jpg,jpeg,png,bmp,webp,avif"}
        ]
    },
     
 
    init: {
 
        FilesAdded: function(up, files) {
		
            plupload.each(files, function(file) {
			
				totalqueue_{$fieldcount} ++;
				
				if(maxallowfiles_{$fieldcount} && (totaluploaded_{$fieldcount} + totalqueue_{$fieldcount} ) > maxallowfiles_{$fieldcount} ) {
					totalqueue_{$fieldcount} --;
				
					$('#xfupload_{$fieldname} .qq-upload-button').attr("disabled","disabled");
					
					up.disableBrowse(true);
					up.removeFile(file);

				} else {
					$('<div id="uploadfile-'+file.id+'" class="file-box"><span class="qq-upload-file-status">{$lang['media_upload_st6']}</span><span class="qq-upload-file">'+file.name+'</span><span class="qq-status"><span class="qq-upload-spinner"></span><span class="qq-upload-size"></span></span><div class="progress"><div class="progress-bar progress-blue" style="width: 0%"><span>0%</span></div></div></div>').appendTo('#xfupload_{$fieldname}');
				}
					
            });
			up.start();
			up.refresh();
        },
 
        UploadProgress: function(up, file) {
		
			  $('#uploadfile-'+file.id+' .qq-upload-size').text(plupload.formatSize(file.loaded) + ' {$lang['media_upload_st8']} ' + plupload.formatSize(file.origSize));
			  $('#uploadfile-'+file.id+' .progress-bar').css( "width", file.percent + '%' );
			  $('#uploadfile-'+file.id+' .qq-upload-spinner').css( "display", "inline-block");

        },
		
		FileUploaded: function(up, file, result) {
		
				try {
				   var response = JSON.parse(result.response);
				} catch (e) {
					var response = '';
				}
				
				totalqueue_{$fieldcount} --;
				
				if( result.status == 200 ) {
				
					if ( response.success ) {
					
						totaluploaded_{$fieldcount} ++;

						var fieldvalue = $('#xf_{$fieldname}').val();
					
						var returnbox = response.returnbox;
						var returnval = response.xfvalue;

						returnbox = returnbox.replace(/&lt;/g, "<");
						returnbox = returnbox.replace(/&gt;/g, ">");
						returnbox = returnbox.replace(/&amp;/g, "&");

						$('#uploadfile-'+file.id+' .qq-status').html('{$lang['media_upload_st9']}');
						$('#uploadedfile_{$fieldname}').append( returnbox );
						
						if (fieldvalue == "") {
							$('#xf_{$fieldname}').val(returnval);
						} else {
							fieldvalue += ',' +returnval;
							$('#xf_{$fieldname}').val(fieldvalue);
						}

						if(maxallowfiles_{$fieldcount} && totaluploaded_{$fieldcount} == maxallowfiles_{$fieldcount} ) {
								$('#xfupload_{$fieldname} .qq-upload-button').attr("disabled","disabled");
								up.disableBrowse(true);
						}

						setTimeout(function() {
							$('#uploadfile-'+file.id).fadeOut('slow', function() { $(this).remove(); up.refresh(); });
						}, 1000);
						
						$('#mediaupload').remove();

					} else {
					
						$('#uploadfile-'+file.id+' .qq-status').html('{$lang['media_upload_st10']}');

						if( response.error ) $('#uploadfile-'+file.id+' .qq-status').append( '<br><span class="text-danger">' + response.error + '</span>' );

						setTimeout(function() {
							$('#uploadfile-'+file.id).fadeOut('slow', function() { $(this).remove(); up.refresh(); });
						}, 10000);
					}
						
				} else {
				
					$('#uploadfile-'+file.id+' .qq-status').append( '<br><span class="text-danger">HTTP Error:' + result.status + '</span>' );
					
					setTimeout(function() {
						$('#uploadfile-'+file.id).fadeOut('slow', function() { $(this).remove(); up.refresh(); });
					}, 10000);
				}

				up.refresh();
				
        },
		
        Error: function(up, err) {
			var type_err = '{$lang['media_upload_st11']}';
			var size_err = '{$lang['media_upload_st12']}';
			
			type_err = type_err.replace('{file}', err.file.name);
			type_err = type_err.replace('{extensions}', up.settings.filters.mime_types[0].extensions);
			size_err = size_err.replace('{file}', err.file.name);
			size_err = size_err.replace('{sizeLimit}', plupload.formatSize(up.settings.filters.max_file_size));
			
			if(err.code == '-600') {
			
				DLEPush.error(size_err);
				
			} else if(err.code == '-601') {
			
				DLEPush.error(type_err);
				
			} else {
			
				if( err.response ) {
				
					try {
					   var response = JSON.parse(err.response);
					} catch (e) {
						var response = '';
					}
					
					if( response.error ){
					
						DLEPush.error(response.error);
						
					} else {
					
						DLEPush.error(err.message);
						
					}

				} else {
					DLEPush.error(err.message);
				}
				
			}
		
        }
    }
});

file_uploaders['{$fieldname}'].init();
	
	if(maxallowfiles_{$fieldcount} && totaluploaded_{$fieldcount} >=  maxallowfiles_{$fieldcount} ) {
		$('#xfupload_{$fieldname} .qq-upload-button').attr("disabled","disabled");
		setTimeout(function() {
			file_uploaders['{$fieldname}'].disableBrowse(true);
		}, 100);
	}
	
	if ( typeof Sortable != "undefined"  ) {
	
		var sortable_{$fieldcount} = Sortable.create(document.getElementById('uploadedfile_{$fieldname}'), {
		  group: {
			name: 'xfuploadedimages',
			put: function (to, from) {

				if(from.options.group.name != to.options.group.name ){
					return false;
				}

				if(maxallowfiles_{$fieldcount} && totaluploaded_{$fieldcount} >= maxallowfiles_{$fieldcount} ) {
					return false;
				} else {return true;}

			}
		  },
		  handle: '.file-content',
		  draggable: '.uploadedfile',
		  onSort: function (evt) {
				totaluploaded_{$fieldcount} = sortable_{$fieldcount}.el.children.length;
				
				if(maxallowfiles_{$fieldcount} && totaluploaded_{$fieldcount} >= maxallowfiles_{$fieldcount} ) {
					$('#xfupload_{$fieldname} .qq-upload-button').attr("disabled","disabled");
					file_uploaders['{$fieldname}'].disableBrowse(true);
				} else {
					$('#xfupload_{$fieldname} .qq-upload-button').removeAttr('disabled');
					file_uploaders['{$fieldname}'].disableBrowse(false);
				}
				
				xfsinc('{$fieldname}');
				file_uploaders['{$fieldname}'].refresh();
		  },
		  animation: 150
		});
		
	}
HTML;
				$js_scripts[] = <<<HTML
if ($('#xfupload_{$fieldname}').length){
	{$uploadscript}
}
HTML;
				$js_functions[] = $del_function;

				if ($xfieldmode == "site") {

					$output = <<<HTML
<div id="{$holderid}" class="xfieldsrow" {$uid}>
	<div class="xfieldscolleft">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional] {$value['allow_in_news']}</div>
	<div class="xfieldscolright"><div id="xfupload_{$fieldname}"><div class="qq-uploader"><div id="uploadedfile_{$fieldname}" style="min-height: 2px;">{$up_image}</div><div id="upload_button_{$fieldname}" class="qq-upload-button btn btn-green bg-teal btn-sm btn-raised" style="width: auto;">{$lang['xfield_xfimg']}</div></div></div><input type="hidden" name="xfield[{$fieldname}]" id="xf_{$fieldname}" data-alert="{$value['description']}" value="{$fieldvalue}" {$params}>{$value['hint']}</div>
</div>
HTML;
					$xfieldinput[$fieldname] = "<div id=\"xfupload_{$fieldname}\"><div class=\"qq-uploader\"><div id=\"uploadedfile_{$fieldname}\" style=\"min-height: 2px;\">{$up_image}</div><div id=\"upload_button_{$fieldname}\" class=\"qq-upload-button btn btn-green bg-teal btn-sm btn-raised\" style=\"width: auto;\">{$lang['xfield_xfimg']}</div></div></div><input type=\"hidden\" name=\"xfield[$fieldname]\" id=\"xf_{$fieldname}\" value=\"{$fieldvalue}\" data-alert=\"{$value['description']}\" {$params}><script>{$del_function}</script>";
				} else {

					$output = <<<HTML
<div id="$holderid" class="form-group" {$uid}>
  <label class="control-label col-sm-2">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional]</label>
  <div class="col-sm-10"><div id="xfupload_{$fieldname}"><div class="qq-uploader"><div id="uploadedfile_{$fieldname}" style="min-height: 2px;">{$up_image}</div><div id="upload_button_{$fieldname}" class="qq-upload-button btn btn-green bg-teal btn-sm btn-raised" style="width: auto;">{$lang['xfield_xfimg']}</div>{$value['allow_in_news']}{$value['hint']}</div></div><input type="hidden" name="xfield[{$fieldname}]" id="xf_{$fieldname}" data-alert="{$value['description']}" value="{$fieldvalue}" {$params}>
  </div>
</div>
HTML;
				}
			} elseif ($value['type'] == "video" or $value['type'] == "audio") {

				$max_file_size = (int)$value['max_size'] * 1024;

				if ($fieldvalue) {

					$fieldvalue_arr = explode(',', $fieldvalue);
					$up_files = array();

					foreach ($fieldvalue_arr as $temp_value) {

						$temp_value = trim($temp_value);

						if (!$temp_value) continue;

						$temp_array = explode('|', $temp_value);

						if (count($temp_array) < 4) {

							$temp_alt = '';
							$temp_id = $temp_array[1];
							$temp_size = $temp_array[2];
							$temp_url = $temp_array[0];
							$temp_value = implode('|', $temp_array);
						} else {

							$temp_alt = $temp_array[0];
							$temp_id = $temp_array[2];
							$temp_size = $temp_array[3];
							$temp_url = $temp_array[1];
							unset($temp_array[0]);
							$temp_value =  implode('|', $temp_array);
						}

						$filename = pathinfo($temp_url, PATHINFO_BASENAME);
						$filename = explode("_", $filename);
						if (count($filename) > 1 and strlen($filename[0]) == 10) unset($filename[0]);
						$filename = implode("_", $filename);

						$base_name = pathinfo($filename, PATHINFO_FILENAME);
						$file_type = explode(".", $filename);
						$file_type = totranslit(end($file_type));

						if (in_array($file_type, array('mp3', 'flac', 'aac', 'ogg'))) {
							$file_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 56.054 66.35" width="66" height="76" class="file-icon file-ext-' . $file_type . '"><g transform="translate(-240.5 -297.644)"><g transform="translate(196.745 265.397)"><path d="M82.585,33.746H53.6a8.342,8.342,0,0,0-8.342,8.342V88.754A8.342,8.342,0,0,0,53.6,97.1H89.966a8.342,8.342,0,0,0,8.342-8.342V49.469Z" fill="#fff" stroke="#ffa734" stroke-miterlimit="10" stroke-width="3"></path><path d="M204.77,33.746v9.866a7.156,7.156,0,0,0,7.156,7.156h9.866Z" transform="translate(-123.189)" fill="#ffa734"></path></g><path d="M23.3-6.68H21.372l-.759-3.432a.778.778,0,0,0-.723-.574.778.778,0,0,0-.722.571l-1.179,5.2-1.225-8.7a.765.765,0,0,0-.735-.636.761.761,0,0,0-.737.653L14.2-4.319,12.61-15.984a.764.764,0,0,0-.735-.64.764.764,0,0,0-.735.64L9.551-4.318,8.456-13.6a.761.761,0,0,0-.737-.654.764.764,0,0,0-.735.638L5.76-4.908,4.582-10.114a.778.778,0,0,0-.722-.572.778.778,0,0,0-.723.573L2.378-6.68H.445A.445.445,0,0,0,0-6.234v.594A.445.445,0,0,0,.445-5.2H2.972a.772.772,0,0,0,.719-.575l.173-.74L5.215-.573A.719.719,0,0,0,5.966,0h.008a.769.769,0,0,0,.7-.637l.983-7.027L8.762,1.721a.742.742,0,0,0,1.473.013L11.875-10.3l1.64,12.037a.742.742,0,0,0,1.473-.013L16.1-7.664l.983,7.026a.771.771,0,0,0,.7.638.717.717,0,0,0,.755-.573L19.886-6.51l.173.739a.772.772,0,0,0,.72.576H23.3a.445.445,0,0,0,.445-.445v-.594A.445.445,0,0,0,23.3-6.68Z" transform="translate(256.344 339.5)" fill="#ffa734"></path></g></svg>';
							$b_color = '#fff6ea';
						} else {
							$file_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 56.054 66.35" width="66" height="76" class="file-icon file-ext-' . $file_type . '"><g transform="translate(-586.74 -502.325)"><g transform="translate(542.985 470.079)"><path d="M82.585,33.746H53.6a8.342,8.342,0,0,0-8.342,8.342V88.754A8.342,8.342,0,0,0,53.6,97.1H89.966a8.342,8.342,0,0,0,8.342-8.342V49.469Z" fill="#fff" stroke="#04a0b2" stroke-miterlimit="10" stroke-width="3"></path><path d="M204.77,33.746v9.866a7.156,7.156,0,0,0,7.156,7.156h9.866Z" transform="translate(-123.189)" fill="#04a0b2"></path></g><g transform="translate(0.887 3.384)"><g transform="translate(603.613 524.116)"><path d="M3,16a3,3,0,0,1-3-3V3A3,3,0,0,1,3,0h8.3a3,3,0,0,1,3,3V5.943L20.471,2.1A1,1,0,0,1,22,2.944V13.055a1,1,0,0,1-1.529.849L14.3,10.057V13a3,3,0,0,1-3,3Z" fill="#04a0b2"></path></g></g></g></svg>';
							$b_color = '#e5f5f7';
						}

						$xf_id = md5($temp_value);

						$up_files[] = "<div class=\"file-preview-card uploadedfile\" id=\"xf_{$xf_id}\" data-id=\"{$temp_value}\" data-alt=\"{$temp_alt}\"><div class=\"active-ribbon\"><span><i class=\"mediaupload-icon mediaupload-icon-ok\"></i></span></div><div class=\"file-content select-disable\" style=\"background-color: {$b_color};\"><div class=\"file-ext\">{$file_type}</div>{$file_icon}</div><div class=\"file-footer\"><div class=\"file-footer-caption\"><div class=\"file-caption-info\" rel=\"tooltip\" title=\"{$filename}\">{$base_name}</div><div class=\"file-size-info\">({$temp_size})</div></div><div class=\"file-footer-bottom\"><div class=\"file-preview\"><a onclick=\"xfaddalt('" . $xf_id . "', '" . $fieldname . "');return false;\" href=\"#\" rel=\"tooltip\" title=\"{$lang['xf_img_descr']}\"><i class=\"mediaupload-icon mediaupload-icon-edit\"></i></a></div><div class=\"file-delete\"><a onclick=\"xfplaylistdelete_{$fieldcount}('" . $fieldname . "','" . $temp_id . "', '" . $xf_id . "');return false;\" href=\"#\"><i class=\"mediaupload-icon mediaupload-icon-trash\"></i></a></div></div></div></div>";
					}

					$totaluploadedfiles = count($up_files);
					$up_files = implode($up_files);
				} else {
					$up_files = "";
					$totaluploadedfiles = 0;
				}

				if (!$value['not_required'] AND !$value['disabled']) {
					$params = "rel=\"essential\" ";
					$uid = "uid=\"essential\" ";
				} else {

					$params = "";
					$uid = "";
				}

				$del_function = <<<HTML
	var maxallowfiles_{$fieldcount} = {$value['max_files']};
	var totaluploaded_{$fieldcount} = {$totaluploadedfiles};
	var totalqueue_{$fieldcount} = 0;
	
	function xfplaylistdelete_{$fieldcount} ( xfname, xfvalue, id )
	{
		DLEconfirmDelete( '{$lang['file_delete']}', '{$lang['p_info']}', function () {

			ShowLoading('');
	
			$.post('{$_ROOT_DLE_URL}index.php?controller=ajax&mod=upload', { subaction: 'deluploads', user_hash: '{$dle_login_hash}', news_id: '{$news_id}', author: '{$author}', 'files[]' : xfvalue }, function(data){
	
				HideLoading('');

				$('#xf_'+id).remove();
				totaluploaded_{$fieldcount} --;
				xfsinc('{$fieldname}');
				
				$('#xfupload_' + xfname + ' .qq-upload-button').removeAttr('disabled');
				
				if (typeof file_uploaders[xfname] !== 'undefined') {
					file_uploaders[xfname].disableBrowse(false);
					file_uploaders[xfname].refresh();
				}
				
				$('#mediaupload').remove();
				
			});
			
		} );
		
		return false;

	};
HTML;

				$max_file_size = number_format($max_file_size, 0, '', '');

				if ($value['type'] == "audio") {

					$allowed_files = "mp3,flac,aac,ogg";
					$button_text = $lang['xfield_xfaudio'];
				} else {

					$button_text = $lang['xfield_xfvideo'];
					$allowed_files = "mp4,m4v,m4a,mov,webm,m3u8,mkv";
				}

				$uploadscript = <<<HTML

file_uploaders['{$fieldname}'] = new plupload.Uploader({

    runtimes : 'html5',
    file_data_name: "qqfile",
    browse_button: 'upload_button_{$fieldname}',
    container: document.getElementById('xfupload_{$fieldname}'),
	drop_element: document.getElementById('xfupload_{$fieldname}'),
    url: "{$_ROOT_DLE_URL}index.php?controller=ajax&mod=upload",
	multipart_params: {"subaction" : "upload", "news_id" : "{$news_id}", "area" : "xfields{$value['type']}", "author" : "{$author}", "xfname" : "{$fieldname}", "user_hash" : "{$dle_login_hash}"},

	chunk_size: '{$config['file_chunk_size']}mb',
     
    filters : {
        max_file_size : '{$max_file_size}',
        mime_types: [
            {title : "Files", extensions : "{$allowed_files}"}
        ]
    },
 
    init: {
 
        FilesAdded: function(up, files) {
		
            plupload.each(files, function(file) {
			
				totalqueue_{$fieldcount} ++;
				
				if(maxallowfiles_{$fieldcount} && (totaluploaded_{$fieldcount} + totalqueue_{$fieldcount} ) > maxallowfiles_{$fieldcount} ) {
					totalqueue_{$fieldcount} --;
				
					$('#xfupload_{$fieldname} .qq-upload-button').attr("disabled","disabled");
					
					up.disableBrowse(true);
					up.removeFile(file);

				} else {
					$('<div id="uploadfile-'+file.id+'" class="file-box"><span class="qq-upload-file-status">{$lang['media_upload_st6']}</span><span class="qq-upload-file">'+file.name+'</span><span class="qq-status"><span class="qq-upload-spinner"></span><span class="qq-upload-size"></span></span><div class="progress"><div class="progress-bar progress-blue" style="width: 0%"><span>0%</span></div></div></div>').appendTo('#xfupload_{$fieldname}');
				}
					
            });
			up.start();
			up.refresh();
        },
 
        UploadProgress: function(up, file) {
		
			  $('#uploadfile-'+file.id+' .qq-upload-size').text(plupload.formatSize(file.loaded) + ' {$lang['media_upload_st8']} ' + plupload.formatSize(file.origSize));
			  $('#uploadfile-'+file.id+' .progress-bar').css( "width", file.percent + '%' );
			  $('#uploadfile-'+file.id+' .qq-upload-spinner').css( "display", "inline-block");

        },
		
		FileUploaded: function(up, file, result) {
		
				try {
				   var response = JSON.parse(result.response);
				} catch (e) {
					var response = '';
				}
				
				totalqueue_{$fieldcount} --;
				
				if( result.status == 200 ) {
				
					if ( response.success ) {
					
						totaluploaded_{$fieldcount} ++;

						var fieldvalue = $('#xf_{$fieldname}').val();
					
						var returnbox = response.returnbox;
						var returnval = response.xfvalue;

						returnbox = returnbox.replace(/&lt;/g, "<");
						returnbox = returnbox.replace(/&gt;/g, ">");
						returnbox = returnbox.replace(/&amp;/g, "&");

						$('#uploadfile-'+file.id+' .qq-status').html('{$lang['media_upload_st9']}');
						$('#uploadedfile_{$fieldname}').append( returnbox );
						
						if (fieldvalue == "") {
							$('#xf_{$fieldname}').val(returnval);
						} else {
							fieldvalue += ',' +returnval;
							$('#xf_{$fieldname}').val(fieldvalue);
						}

						if(maxallowfiles_{$fieldcount} && totaluploaded_{$fieldcount} == maxallowfiles_{$fieldcount} ) {
								$('#xfupload_{$fieldname} .qq-upload-button').attr("disabled","disabled");
								up.disableBrowse(true);
						}

						setTimeout(function() {
							$('#uploadfile-'+file.id).fadeOut('slow', function() { $(this).remove(); up.refresh(); });
						}, 1000);
						
						$('#mediaupload').remove();

					} else {
					
						$('#uploadfile-'+file.id+' .qq-status').html('{$lang['media_upload_st10']}');

						if( response.error ) $('#uploadfile-'+file.id+' .qq-status').append( '<br><span class="text-danger">' + response.error + '</span>' );

						setTimeout(function() {
							$('#uploadfile-'+file.id).fadeOut('slow', function() { $(this).remove(); up.refresh(); });
						}, 10000);
					}
						
				} else {
				
					$('#uploadfile-'+file.id+' .qq-status').append( '<br><span class="text-danger">HTTP Error:' + result.status + '</span>' );
					
					setTimeout(function() {
						$('#uploadfile-'+file.id).fadeOut('slow', function() { $(this).remove(); up.refresh(); });
					}, 10000);
				}

				up.refresh();
				
        },
		
        Error: function(up, err) {
			var type_err = '{$lang['media_upload_st11']}';
			var size_err = '{$lang['media_upload_st12']}';
			
			type_err = type_err.replace('{file}', err.file.name);
			type_err = type_err.replace('{extensions}', up.settings.filters.mime_types[0].extensions);
			size_err = size_err.replace('{file}', err.file.name);
			size_err = size_err.replace('{sizeLimit}', plupload.formatSize(up.settings.filters.max_file_size));
			
			if(err.code == '-600') {
			
				DLEPush.error(size_err);
				
			} else if(err.code == '-601') {
			
				DLEPush.error(type_err);
				
			} else {
			
				if( err.response ) {
				
					try {
					   var response = JSON.parse(err.response);
					} catch (e) {
						var response = '';
					}
					
					if( response.error ){
					
						DLEPush.error(response.error);
						
					} else {
					
						DLEPush.error(err.message);
						
					}

				} else {
					DLEPush.error(err.message);
				}
				
			}
		
        }
    }
});

file_uploaders['{$fieldname}'].init();
	
	if(maxallowfiles_{$fieldcount} && totaluploaded_{$fieldcount} >=  maxallowfiles_{$fieldcount} ) {
		$('#xfupload_{$fieldname} .qq-upload-button').attr("disabled","disabled");
		setTimeout(function() {
			file_uploaders['{$fieldname}'].disableBrowse(true);
		}, 100);
	}
	
	if ( typeof Sortable != "undefined"  ) {
	
		var sortable_{$fieldcount} = Sortable.create(document.getElementById('uploadedfile_{$fieldname}'), {
		  group: {
			name: 'xfuploaded{$value['type']}',
			put: function (to, from) {

				if(from.options.group.name != to.options.group.name ){
					return false;
				}

				if(maxallowfiles_{$fieldcount} && totaluploaded_{$fieldcount} >= maxallowfiles_{$fieldcount} ) {
					return false;
				} else {return true;}
			}
		  },
		  handle: '.file-content',
		  draggable: '.uploadedfile',
		  onSort: function (evt) {
				totaluploaded_{$fieldcount} = sortable_{$fieldcount}.el.children.length;

				if(maxallowfiles_{$fieldcount} && totaluploaded_{$fieldcount} >= maxallowfiles_{$fieldcount} ) {
					$('#xfupload_{$fieldname} .qq-upload-button').attr("disabled","disabled");
					file_uploaders['{$fieldname}'].disableBrowse(true);
				} else {
					$('#xfupload_{$fieldname} .qq-upload-button').removeAttr('disabled');
					file_uploaders['{$fieldname}'].disableBrowse(false);
				}
				
				xfsinc('{$fieldname}');
				file_uploaders['{$fieldname}'].refresh();
		  },
		  animation: 150
		});
		
	}
HTML;
				$js_scripts[] = <<<HTML
if ($('#xfupload_{$fieldname}').length){
	{$uploadscript}
}
HTML;
				$js_functions[] = $del_function;

				if ($xfieldmode == "site") {

					$output = <<<HTML
<div id="{$holderid}" class="xfieldsrow" {$uid}>
	<div class="xfieldscolleft">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional] {$value['allow_in_news']}</div>
	<div class="xfieldscolright"><div id="xfupload_{$fieldname}"><div class="qq-uploader"><div id="uploadedfile_{$fieldname}" style="min-height: 2px;">{$up_files}</div><div id="upload_button_{$fieldname}" class="qq-upload-button btn btn-green bg-teal btn-sm btn-raised" style="width: auto;">{$button_text}</div></div></div><input type="hidden" name="xfield[{$fieldname}]" id="xf_{$fieldname}" data-alert="{$value['description']}" value="{$fieldvalue}" {$params}>{$value['hint']}</div>
</div>
HTML;

					$xfieldinput[$fieldname] = "<div id=\"xfupload_{$fieldname}\"><div class=\"qq-uploader\"><div id=\"uploadedfile_{$fieldname}\" style=\"min-height: 2px;\">{$up_files}</div><div id=\"upload_button_{$fieldname}\" class=\"qq-upload-button btn btn-green bg-teal btn-sm btn-raised\" style=\"width: auto;\">{$button_text}</div></div></div><input type=\"hidden\" name=\"xfield[{$fieldname}]\" id=\"xf_{$fieldname}\" value=\"{$fieldvalue}\" data-alert=\"{$value['description']}\" {$params}/><script>{$del_function}</script>";
				} else {

					$output = <<<HTML
<div id="{$holderid}" class="form-group" {$uid}>
  <label class="control-label col-sm-2">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional]</label>
  <div class="col-sm-10"><div id="xfupload_{$fieldname}"><div class="qq-uploader"><div id="uploadedfile_{$fieldname}" style="min-height: 2px;">{$up_files}</div><div id="upload_button_{$fieldname}" class="qq-upload-button btn btn-green bg-teal btn-sm btn-raised" style="width: auto;">{$button_text}</div>{$value['allow_in_news']}{$value['hint']}</div></div><input type="hidden" name="xfield[{$fieldname}]" id="xf_{$fieldname}" data-alert="{$value['description']}" value="{$fieldvalue}" {$params}>
  </div>
</div>
HTML;
				}

			} elseif ($value['type'] == "file") {

				$max_file_size = (int)$value['file_max_size'] * 1024;
				$allowed_files = strtolower($value['files_ext']);

				$fieldvalue = str_replace('&amp;', '&', $fieldvalue);

				if (!$value['not_required'] AND !$value['disabled']) {
					$params = "rel=\"essential\" ";
					$uid = "uid=\"essential\" ";
				} else {

					$params = "";
					$uid = "";
				}

				if ($fieldvalue) {

					if ($value['is_public']) {
						$fileid = parse_url($fieldvalue, PHP_URL_PATH);
						$fileid = explode('/', $fileid);
						$fileid = array_slice($fileid, -2);
						$fileid = implode('/', $fileid);
					} else {
						if (preg_match('/attachment=(\d+):/', $fieldvalue, $matches)) {
							$fileid = intval($matches[1]);
						} else $fileid = 0;
					}

					$fileid = "<button class=\"qq-upload-button btn btn-sm btn-red bg-danger btn-raised\" onclick=\"xffiledelete('" . $fieldname . "','" . $fileid . "');return false;\">{$lang['xfield_xfid']}</button>";

					$show = "display:inline-block;";
				} else {
					$show = "display:none;";
					$fileid = "";
				}

				$max_file_size = number_format($max_file_size, 0, '', '');

				$uploadscript = <<<HTML

file_uploaders['{$fieldname}'] = new plupload.Uploader({

    runtimes : 'html5',
    file_data_name: "qqfile",
    browse_button: 'upload_button_{$fieldname}',
    container: document.getElementById('xfupload_{$fieldname}'),
	drop_element: document.getElementById('xfupload_{$fieldname}'),
    url: "{$_ROOT_DLE_URL}index.php?controller=ajax&mod=upload",
	multipart_params: {"subaction" : "upload", "news_id" : "{$news_id}", "area" : "xfieldsfile", "author" : "{$author}", "xfname" : "{$fieldname}", "user_hash" : "{$dle_login_hash}"},
	multi_selection: false,
	chunk_size: '{$config['file_chunk_size']}mb',
     
    filters : {
        max_file_size : '{$max_file_size}',
        mime_types: [
            {title : "Files", extensions : "{$allowed_files}"}
        ]
    },
     
 
    init: {
 
        FilesAdded: function(up, files) {
		
            plupload.each(files, function(file) {
				$('<div id="uploadfile-'+file.id+'" class="file-box"><span class="qq-upload-file-status">{$lang['media_upload_st6']}</span><span class="qq-upload-file">'+file.name+'</span><span class="qq-status"><span class="qq-upload-spinner"></span><span class="qq-upload-size"></span></span><div class="progress"><div class="progress-bar progress-blue" style="width: 0%"><span>0%</span></div></div></div>').appendTo('#xfupload_{$fieldname}');
            });
			
			up.start();
			up.refresh();
        },
 
        UploadProgress: function(up, file) {
		
			  $('#uploadfile-'+file.id+' .qq-upload-size').text(plupload.formatSize(file.loaded) + ' {$lang['media_upload_st8']} ' + plupload.formatSize(file.origSize));
			  $('#uploadfile-'+file.id+' .progress-bar').css( "width", file.percent + '%' );
			  $('#uploadfile-'+file.id+' .qq-upload-spinner').css( "display", "inline-block");

        },
		
		FileUploaded: function(up, file, result) {
		
				try {
				   var response = JSON.parse(result.response);
				} catch (e) {
					var response = '';
				}
				
				if( result.status == 200 ) {
				
					if ( response.success ) {
					
						var returnbox = response.returnbox;
						var returnval = response.xfvalue;

						returnbox = returnbox.replace(/&lt;/g, "<");
						returnbox = returnbox.replace(/&gt;/g, ">");
						returnbox = returnbox.replace(/&amp;/g, "&");

						$('#uploadfile-'+file.id+' .qq-status').html('{$lang['media_upload_st9']}');
						$('#xf_{$fieldname}').show();
						$('#uploadedfile_{$fieldname}').html( returnbox );
						$('#xf_{$fieldname}').val(returnval);
						$('#xfupload_{$fieldname} .qq-upload-button').attr("disabled","disabled");
						
						up.disableBrowse(true);
						
						setTimeout(function() {
							$('#uploadfile-'+file.id).fadeOut('slow', function() { $(this).remove(); up.refresh(); });
						}, 1000);
						
						$('#mediaupload').remove();

					} else {
					
						$('#uploadfile-'+file.id+' .qq-status').html('{$lang['media_upload_st10']}');

						if( response.error ) $('#uploadfile-'+file.id+' .qq-status').append( '<br><span class="text-danger">' + response.error + '</span>' );

						setTimeout(function() {
							$('#uploadfile-'+file.id).fadeOut('slow', function() { $(this).remove(); });
						}, 10000);
					}
						
				} else {
				
					$('#uploadfile-'+file.id+' .qq-status').append( '<br><span class="text-danger">HTTP Error:' + result.status + '</span>' );
					
					setTimeout(function() {
						$('#uploadfile-'+file.id).fadeOut('slow', function() { $(this).remove(); up.refresh(); });
					}, 10000);
				}

				up.refresh();
				
        },
		
        Error: function(up, err) {
			var type_err = '{$lang['media_upload_st11']}';
			var size_err = '{$lang['media_upload_st12']}';
			
			type_err = type_err.replace('{file}', err.file.name);
			type_err = type_err.replace('{extensions}', up.settings.filters.mime_types[0].extensions);
			size_err = size_err.replace('{file}', err.file.name);
			size_err = size_err.replace('{sizeLimit}', plupload.formatSize(up.settings.filters.max_file_size));
			
			if(err.code == '-600') {
			
				DLEPush.error(size_err);
				
			} else if(err.code == '-601') {
			
				DLEPush.error(type_err);
				
			} else {
			
				if( err.response ) {
				
					try {
					   var response = JSON.parse(err.response);
					} catch (e) {
						var response = '';
					}
					
					if( response.error ){
					
						DLEPush.error(response.error);
						
					} else {
					
						DLEPush.error(err.message);
						
					}

				} else {
					DLEPush.error(err.message);
				}
				
			}
		
        }
    }
});

file_uploaders['{$fieldname}'].init();
	
if($('#xf_{$fieldname}').val() != "" ) {

	$('#xfupload_{$fieldname} .qq-upload-button').attr("disabled","disabled");
	setTimeout(function() {
		file_uploaders['{$fieldname}'].disableBrowse(true);
		file_uploaders['{$fieldname}'].refresh();
	}, 100);
}
	
HTML;

				$js_scripts[] = <<<HTML
if ($('#xfupload_{$fieldname}').length){
	{$uploadscript}
}
HTML;

				if ($xfieldmode == "site") {

					$output = <<<HTML
<div id="{$holderid}" class="xfieldsrow" {$uid}>
	<div class="xfieldscolleft">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional] {$value['allow_in_news']}</div>
	<div class="xfieldscolright"><input style="{$show}" class="quick-edit-text" type="text" dir="auto" name="xfield[{$fieldname}]" id="xf_{$fieldname}" data-alert="{$value['description']}" value="{$fieldvalue}" {$params}/><span id="uploadedfile_{$fieldname}">{$fileid}</span><div id="xfupload_{$fieldname}"><div class="qq-uploader"><div style="position: relative;"><div id="upload_button_{$fieldname}" class="qq-upload-button btn btn-green bg-teal btn-sm btn-raised" style="width: auto;">{$lang['xfield_xfif']}</div></div></div></div>{$value['hint']}</div>
</div>
HTML;

					$xfieldinput[$fieldname] = "<input style=\"{$show}\" type=\"text\" dir=\"auto\" name=\"xfield[{$fieldname}]\" id=\"xf_{$fieldname}\" data-alert=\"{$value['description']}\" value=\"{$fieldvalue}\" {$params}/><span id=\"uploadedfile_{$fieldname}\">{$fileid}</span><div id=\"xfupload_{$fieldname}\"><div class=\"qq-uploader\"><div style=\"position: relative;\"><div id=\"upload_button_{$fieldname}\" class=\"qq-upload-button btn btn-green bg-teal btn-sm btn-raised\" style=\"width: auto;\">{$lang['xfield_xfif']}</div></div></div></div>";
				} else {

					$output = <<<HTML
<div id="$holderid" class="form-group" {$uid}>
  <label class="control-label col-sm-2">{$value['description']}:[not-optional] <span style="color:red;">*</span>[/not-optional]</label>
  <div class="col-sm-10"><input class="form-control width-350 position-left" style="margin-bottom:5px;{$show}" type="text" dir="auto" name="xfield[{$fieldname}]" id="xf_{$fieldname}" data-alert="{$value['description']}" value="{$fieldvalue}" {$params}><span id="uploadedfile_{$fieldname}">{$fileid}</span><div id="xfupload_{$fieldname}"><div class="qq-uploader"><div style="position: relative;"><div id="upload_button_{$fieldname}" class="qq-upload-button btn btn-green bg-teal btn-sm btn-raised" style="width: auto;">{$lang['xfield_xfif']}</div>{$value['allow_in_news']}{$value['hint']}</div></div></div>
  </div>
</div>
HTML;
				}
			}

			if(isset($output)) {
				$output = preg_replace("'\\[not-optional\\](.*?)\\[/not-optional\\]'s", ($value['not_required'] || $value['disabled']) ? "" : "\\1", $output);
				$fields_list['fields'][$fieldname] = $output;
				unset($output);
			}
		}

		if ($xfieldmode == "site") {

			$js_scripts[] = <<<HTML
	onCategoryChange($('#category'));
	jQuery.datetimepicker.setLocale('{$lang['language_code']}');
HTML;
		} else {

			$js_scripts[] = <<<HTML
	onCategoryChange($('#category'));
HTML;

		}

		$fields_list['custom'] = $xfieldinput;
		$fields_list['js_functions'] = implode("\n", $js_functions);
		$fields_list['js_scripts'] = implode("\n", $js_scripts);
		
		if( !isset($fields_list['fields']) ) $fields_list['fields'] = array();

		return $fields_list;

	}

	public static function xfieldsdataload($xfvalues) {

		$xfvalues = (string)$xfvalues;

		if( !$xfvalues ) return [];
		
		$xfieldsdata = explode( "||", $xfvalues );
		$data =[];

		foreach ( $xfieldsdata as $xfielddata ) {
			list ( $xfielddataname, $xfielddatavalue ) = explode( "|", $xfielddata );
			
			$xfielddataname = str_replace( "&#124;", "|", $xfielddataname );
			$xfielddataname = str_replace( "__NEWL__", "\n", $xfielddataname );

			$xfielddatavalue = str_replace( "&#124;", "|", $xfielddatavalue );
			$xfielddatavalue = str_replace( "__NEWL__", "\n", $xfielddatavalue );

			$data[$xfielddataname] = $xfielddatavalue;
		}
		
		return $data;
	}

	private static function category_filter_js() {
		global $cat_info, $lang;
		
		$xf_tabs = [];
		$xfrubrics = self::GetRubricsArray();

		if (count($xfrubrics)) {

			foreach ($xfrubrics as $key => $xfrubric) {
				$r_fields = self::GETFieldByRubric($key);
				if( count($r_fields) ) $xf_tabs[] = $key;
			}
		}

	if( count($xf_tabs) ) {
		
		$xf_tabs = implode("','", $xf_tabs);

		$tabfilter = <<<HTML
var tabs = ['{$xf_tabs}'];

tabs.forEach(function(tab) {
	
	var tabId = '#xf_' + tab;
	var tabBody = $(tabId);
	
	if (tabBody.length) { 
		
		var totalFields = tabBody.find('.form-group').length - tabBody.find('.form-group[data-hidden="true"]').length;

		if( totalFields ) {
			$('a[href="' + tabId +'"][data-toggle="tab"]').parent().show();
		} else {
			$('a[href="' + tabId +'"][data-toggle="tab"]').parent().hide();
		}

	}

});

HTML;

	} else $tabfilter = '';


		$categoryfilter = <<<HTML
function ShowOrHideEx(id, show) {
	if($('#' + id).length) {
		if (show) {
			$('#' + id ).show();
			$('#' + id ).removeAttr('data-hidden');
		} else {
			$( '#' + id ).hide();
			$('#' + id).attr('data-hidden', 'true');
		}
	}
	
}

function DLECopyText ( copytext ){

   try {
	
		copytext = '[' + 'xfvalue_' + copytext + ']';
		const elem = document.createElement('textarea');
		elem.value = copytext;
		elem.setAttribute('readonly', '');
		elem.style.position = 'absolute';
		elem.style.left = '-9999px';
		document.body.appendChild(elem);
		elem.select();
		document.execCommand('copy');
		document.body.removeChild(elem);
		
		DLEPush.info('{$lang['up_im_copy1']}', '', 2000);
	
  } catch (err) {
  
    console.log('Unable to copy');
	
  }

}

function onCategoryChange(obj) {

	var value = $(obj).val();
	var totaldzendisabled = 0;
	var founddzencount = 0;
	var totalmaindisabled = 0;
	var totalcommdisabled = 0;
	var totalratdisabled = 0;

	valuecount = 0;

	if (Array.isArray(value)) {

		valuecount = value.length
		
HTML;


		foreach (self::$fields['fields'] as $value) {

			if ($value['category']) {

				$categories = explode(",", $value['category']);
				$temp_array = array();

				foreach ($categories as $temp_value) {

					$temp_array[] = "jQuery.inArray('{$temp_value}', value) != -1";
				}

				$categories = implode(" || ", $temp_array);

				$categoryfilter .= "ShowOrHideEx(\"xfield_holder_{$value['name']}\", {$categories} );\r\n";
			}
		}

		foreach ($cat_info as $value) {
			if ($value['disable_main']) {
				$categoryfilter .= "if( jQuery.inArray('{$value['id']}', value) != -1 ) { totalmaindisabled = true; } \r\n";
			}
			if ($value['disable_comments']) {
				$categoryfilter .= "if( jQuery.inArray('{$value['id']}', value) != -1 ) { totalcommdisabled = true; } \r\n";
			}
			if ($value['disable_rating']) {
				$categoryfilter .= "if( jQuery.inArray('{$value['id']}', value) != -1 ) { totalratdisabled = true; } \r\n";
			}

			if (!$value['enable_dzen']) {
				$categoryfilter .= "totaldzendisabled ++; if( jQuery.inArray('{$value['id']}', value) != -1 ) { founddzencount ++; } \r\n";
			}
		}


		$categoryfilter .= <<<HTML

	} else {

		valuecount = 1;

HTML;

		foreach (self::$fields['fields'] as $value) {
			$categories = str_replace(",", " || value==", $value['category']);
			if ($categories) {
				$categoryfilter .= "ShowOrHideEx(\"xfield_holder_{$value['name']}\", value == $categories);\r\n";
			}
		}

		foreach ($cat_info as $value) {
			if ($value['disable_main']) {
				$categoryfilter .= "if( value == {$value['id']} ) { totalmaindisabled = true; } \r\n";
			}
			if ($value['disable_comments']) {
				$categoryfilter .= "if( value == {$value['id']} ) { totalcommdisabled = true; } \r\n";
			}
			if ($value['disable_rating']) {
				$categoryfilter .= "if( value == {$value['id']} ) { totalratdisabled = true; } \r\n";
			}

			if (!$value['enable_dzen']) {
				$categoryfilter .= "totaldzendisabled ++; if( value == {$value['id']} ) { founddzencount ++; } \r\n";
			}
		}

		$categoryfilter .= <<<HTML
	}
	
	{$tabfilter}
	
	ShowOrHideEx("opt_holder_main", totalmaindisabled == 0 );
	ShowOrHideEx("opt_holder_comments", totalcommdisabled == 0 );
	ShowOrHideEx("opt_holder_rating", totalratdisabled == 0 );
	
	if( totaldzendisabled && $('#allow_rss_dzen').length ) {
		$('#allow_rss_dzen').prop('checked', valuecount != founddzencount);
	}

	if (typeof file_uploaders != 'undefined') {

		setTimeout(function() {

			for(var refresh in file_uploaders) {

				if (typeof file_uploaders[refresh].refresh === 'function') {
					file_uploaders[refresh].refresh();
				}
			}
			
		}, 100);
	}

	setTimeout(function() {
		$.each($('.bootstrap-select.uniform button span').first(), function() {
			$(this).text( $(this).text().toString().trim() );
			$(this).parent().attr('title', $(this).text().toString().trim() );
		});
	}, 1);
}
HTML;
		
		return $categoryfilter;

	}

	public static function Parse($xf_existing = '') {
		global $db, $member_id, $lang, $parse;

		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}
		
		self::$error = [];

		$postedxfields = isset($_POST['xfield']) ? $_POST['xfield'] : array();
		$category = isset($_POST['category']) ? $_POST['category'] : array();
		
		if (!is_array($category)) $category = array();
		if (!count($category)) $category[] = '0';

		$xf_existing = $xf_existing ? self::xfieldsdataload($xf_existing) : [];

		$newpostedxfields = array();
		$filecontents = array();
		$xf_search_words = array();
		$xf_not_allowed = array();
		$all_xf_content = array();

		foreach (self::$fields['fields'] as $value) {

			if ($value['category'] != "" AND !self::CheckCategory($category, explode(",", $value['category']) ) ) {
				continue;
			}

			if ($value['allow_add_usergroups']) {

				$value['allow_add_usergroups'] = explode(',', $value['allow_add_usergroups']);

				if ($value['allow_add_usergroups'][0] and !in_array($member_id['user_group'], $value['allow_add_usergroups'])) {
					$xf_not_allowed[] = $value['name'];
					continue;
				}
			}

			if ($value['type'] == "yesorno") {

				$postedxfields[$value['name']] = isset($postedxfields[$value['name']]) ? intval($postedxfields[$value['name']]) : 0;
			}

			if ($value['type'] == "datetime" and isset($postedxfields[$value['name']]) AND $postedxfields[$value['name']]) {

				$postedxfields[$value['name']] = @strtotime($postedxfields[$value['name']]);

				if ($postedxfields[$value['name']] !== -1 and $postedxfields[$value['name']]) {

					if ($value['date_format'] == 1) $postedxfields[$value['name']] = date("Y-m-d", $postedxfields[$value['name']]);
					elseif ($value['date_format'] == 2) $postedxfields[$value['name']] = date("H:i", $postedxfields[$value['name']]);
					else $postedxfields[$value['name']] = date("Y-m-d H:i", $postedxfields[$value['name']]);

				} else $postedxfields[$value['name']] = "";
			}

			if ($value['type'] == "select") {

				if( isset( $postedxfields[$value['name']] ) AND is_string($postedxfields[$value['name']]) AND $postedxfields[$value['name']] ) {
					
					$temp_arr =  explode(",",$postedxfields[$value['name']]);
					$postedxfields[$value['name']] = array();
					foreach (explode("\n", htmlspecialchars($value['default'], ENT_QUOTES, 'UTF-8')) as $index1 => $value1) {

						$value1 = explode("|", $value1);
						if (count($value1) < 2) $value1[1] = $value1[0];
						
						if( !empty($value1[0]) AND in_array($value1[0], $temp_arr ) ) {
							$postedxfields[$value['name']][] = $index1;
						}
					}

				}

				if (isset($postedxfields[$value['name']]) AND is_array($postedxfields[$value['name']]) AND count($postedxfields[$value['name']])) {
					$value['default'] = str_replace("\r", '', $value['default']);
					$options = explode("\n", $value['default']);
					$temp_arr = [];
					
					foreach ($postedxfields[$value['name']] as $tempval) {
						$tempval = explode("|", $options[$tempval]);
						if(!empty($tempval[0]) ) $temp_arr[] =  str_replace(',', '&#x2C;', $tempval[0]);
					}

					$postedxfields[$value['name']] = implode(',', $temp_arr);

				} else  $postedxfields[$value['name']] = '';

			}

			if (!isset($value['disabled']) OR !$value['disabled'] ) {

				if ($value['not_required'] == 0 AND (!isset($postedxfields[$value['name']]) OR $postedxfields[$value['name']] === "") ) {
					self::$error[] = str_replace('{field}', $value['description'], $lang['addnews_xf_alert_1']);
				}

				if ($value['min'] AND isset($postedxfields[$value['name']]) AND $postedxfields[$value['name']] AND dle_strlen(strip_tags($postedxfields[$value['name']])) < $value['min']) {
					$error_text = str_replace('{field}', $value['description'], $lang['addnews_xf_alert_2']);
					$error_text = str_replace('{count}', $value['min'], $error_text);
					
					self::$error[] = $error_text;
				}

				if ($value['max'] AND isset($postedxfields[$value['name']]) AND $postedxfields[$value['name']] AND dle_strlen(strip_tags($postedxfields[$value['name']])) > $value['max']) {

					$error_text = str_replace('{field}', $value['description'], $lang['addnews_xf_alert_3']);
					$error_text = str_replace('{count}', $value['max'], $error_text);

					self::$error[] = $error_text;
				}

			}

			if ($value['type'] == "datetime" and isset($postedxfields[$value['name']]) and $postedxfields[$value['name']] != "") {

				$newpostedxfields[$value['name']] = str_replace(":", "&#58;", (string)$postedxfields[$value['name']]);

			} elseif ($value['type'] == "yesorno") {

				$newpostedxfields[$value['name']] = $postedxfields[$value['name']];

			} elseif ($value['type'] == "htmljs" and isset($postedxfields[$value['name']]) and $postedxfields[$value['name']] != "") {

				$newpostedxfields[$value['name']] = $postedxfields[$value['name']];

			} elseif (($value['safe_mode'] == 1 or $value['use_as_links'] == 1 or $value['type'] == "select" or $value['type'] == "image" or $value['type'] == "imagegalery" or $value['type'] == "video" or $value['type'] == "audio" or $value['type'] == "file") and isset($postedxfields[$value['name']]) and $postedxfields[$value['name']] !== "") {

				$newpostedxfields[$value['name']] = str_replace("&#44;", "&amp;#44;", (string)$postedxfields[$value['name']]);
				$newpostedxfields[$value['name']] = str_replace("&#124;", "&amp;#124;", $newpostedxfields[$value['name']]);
				$newpostedxfields[$value['name']] = str_replace("&#x2C;", "&amp;#x2C;", $newpostedxfields[$value['name']]);

				$newpostedxfields[$value['name']] = html_entity_decode($newpostedxfields[$value['name']], ENT_QUOTES, 'UTF-8');
				$newpostedxfields[$value['name']] = trim(htmlspecialchars(strip_tags(stripslashes($newpostedxfields[$value['name']])), ENT_QUOTES, 'UTF-8'));

				if ($value['type'] == "image" or $value['type'] == "imagegalery" or $value['type'] == "video" or $value['type'] == "audio") {

					$f_arr = explode(',', $newpostedxfields[$value['name']]);

					foreach ($f_arr as $t_val) {

						$t_a = explode('|', $t_val);

						if (count($t_a) == 1 or count($t_a) == 5) {

							$t_v = implode('|', $t_a);
						} else {

							unset($t_a[0]);
							$t_v = implode('|', $t_a);
						}

						if (preg_match("/[?&;<]/", $t_v) or stripos($t_v, ".php") !== false) $newpostedxfields[$value['name']] = "";
					}
				}

				$newpostedxfields[$value['name']] = str_replace(array("{", "["), array("&#123;", "&#91;"), $newpostedxfields[$value['name']]);
				$newpostedxfields[$value['name']] = preg_replace(array('/data:/i', '/about:/i', '/vbscript:/i', '/javascript:/i'), array("d&#1072;ta&#58;", "&#1072;bout&#58;", "vbscript&#58;", "j&#1072;vascript&#58;"), $newpostedxfields[$value['name']]);

				if ($value['type'] == "file") {

					$newpostedxfields[$value['name']] = str_replace(array("&#91;"), array("["), $newpostedxfields[$value['name']]);

					if (!$value['is_public']) {
						if (strpos($newpostedxfields[$value['name']], "[attachment=") === false) $newpostedxfields[$value['name']] = "";
					}
				}

			} elseif (isset($postedxfields[$value['name']]) and $postedxfields[$value['name']] != "") {


				$newpostedxfields[$value['name']] = $parse->BB_Parse($parse->process( (string)$postedxfields[$value['name']]));

				if ($value['type'] == 'text') {
					$newpostedxfields[$value['name']] = str_replace('&amp;', '&', $newpostedxfields[$value['name']]);
				}

				$all_xf_content[] =	$newpostedxfields[$value['name']];
			}

			if ( !isset($newpostedxfields[$value['name']]) ) $newpostedxfields[$value['name']] = '';

			$newpostedxfields[$value['name']] = str_ireplace("{title", "&#123;title", $newpostedxfields[$value['name']]);
			$newpostedxfields[$value['name']] = str_ireplace("{short-story", "&#123;short-story", $newpostedxfields[$value['name']]);
			$newpostedxfields[$value['name']] = str_ireplace("{full-story", "&#123;full-story", $newpostedxfields[$value['name']]);

			if ($value['type'] == "textarea" and $newpostedxfields[$value['name']] == '<p><br></p>') {
				$newpostedxfields[$value['name']] = '';
			}

			if ($value['use_as_links'] and !empty($newpostedxfields[$value['name']])) {
				$temp_array = explode(",", $newpostedxfields[$value['name']]);

				foreach ($temp_array as $value2) {
					$value2 = trim($value2);
					$value2 = str_replace('&amp;#x2C;', ',', $value2);

					if ($value2) {
						$xf_search_words[] = array($db->safesql($value['name']), $db->safesql($value2));
					}
				}
			}

		}

		if( count(self::$error) ) {
			self::$error = implode('<br><br>', self::$error);
		} else self::$error = null;

		$postedxfields = $newpostedxfields;

		if (count($xf_not_allowed) AND count($xf_existing)) {
			foreach ($xf_not_allowed as $defxf) {
				if (isset($xf_existing[$defxf]) and $xf_existing[$defxf]) $postedxfields[$defxf] = $xf_existing[$defxf];
			}
		}

		if ( count($postedxfields) ) {
			foreach ($postedxfields as $xfielddataname => $xfielddatavalue) {

				if ($xfielddatavalue === "") {
					continue;
				}

				$xfielddataname = str_replace("|", "&#124;", $xfielddataname);
				$xfielddataname = str_replace("\r", "", $xfielddataname);
				$xfielddataname = str_replace("\n", "__NEWL__", $xfielddataname);

				$xfielddatavalue = str_replace("|", "&#124;", $xfielddatavalue);
				$xfielddatavalue = str_replace("\r", "", $xfielddatavalue);
				$xfielddatavalue = str_replace("\n", "__NEWL__", $xfielddatavalue);
				$filecontents[] = "$xfielddataname|$xfielddatavalue";
			}

			if (count($filecontents)) $filecontents = $db->safesql(implode("||", $filecontents));
			else $filecontents = '';

		} else $filecontents = '';

		if (count($all_xf_content)) $all_xf_content = implode(" ", $all_xf_content);
		else $all_xf_content = "";

		return ['filecontents' => $filecontents, 'search_words' => $xf_search_words, 'all_xf_content' => $all_xf_content];

	}

	private static function CheckCategory($category, $allowed_cats) {
		$category = array_map('intval', $category);
		$allowed_cats = array_map('intval', $allowed_cats);

		foreach($allowed_cats as $cat){
			if(in_array($cat, $category)) return true;
		}

		return false;
	}

	private static function normalizePlayerWidth($width){
		$default = "600px";
		$width = trim($width);

		$pattern = '/^\d+(\.\d+)?(px|%|em|rem|vh|vw|pt|cm|mm|in|pc)?$/';

		if (preg_match($pattern, $width, $matches)) {
			if (!isset($matches[2]) || $matches[2] === '') {
				return $width . "px";
			}
			return $width;
		}

		return $default;
	}

	public static function Compile( &$row, &$tpl, &$xfields_in_news = array(), &$all_xf_content = array() ) {
		global $member_id, $lang, $config, $customlangdate, $page_description, $view_template, $social_tags, $rssmode;

		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		if( !isset(self::$fields['fields']) OR !count(self::$fields['fields']) ) {
			$row['xfields_array'] = [];
			return;
		}

		$row['xfields_array'] = self::xfieldsdataload($row['xfields']);
		
		$xfieldsdata = $row['xfields_array'];

		$tpl->copy_template = preg_replace_callback(
			"#\\[ifxf(set|notset) fields=['\"](.+?)['\"]\\](.+?)\[/ifxf\\1\]#is",
			function ($matches) use ($xfieldsdata) {

				if (!isset($matches[1]) or !isset($matches[2]) or !isset($matches[3]) or !$matches[1] or !$matches[2] or !$matches[3]) {
					return $matches[0];
				}

				$matches[2] = trim($matches[2]);
				$fields_arr = explode(',', $matches[2]);
				$found = 0;

				foreach ($fields_arr as $field) {
					$field  = trim($field);

					if ($matches[1] == 'set') {

						if (isset($xfieldsdata[$field]) and strlen(trim((string)$xfieldsdata[$field])) > 0 and trim((string)$xfieldsdata[$field]) != '<p><br></p>') $found++;
					} elseif ($matches[1] == 'notset') {

						if (!isset($xfieldsdata[$field]) or strlen(trim((string)$xfieldsdata[$field])) < 1 or trim((string)$xfieldsdata[$field]) == '<p><br></p>') $found++;
					}
				}

				if ($found == count($fields_arr)) return $matches[3];
				else return '';
			},
			$tpl->copy_template
		);

		foreach (self::$fields['fields'] as $value) {

			$preg_safe_name = preg_quote($value['name'], "'");

			if ($value['allow_view_usergroups']) {

				$value['allow_view_usergroups'] = explode(',', $value['allow_view_usergroups']);

				if ($value['allow_view_usergroups'][0] and !in_array($member_id['user_group'], $value['allow_view_usergroups'])) {
					$xfieldsdata[$value['name']] = "";
				}
			}

			if ($value['type'] == "yesorno") {

				if (isset($xfieldsdata[$value['name']]) and intval($xfieldsdata[$value['name']])) {
					$xfgiven = true;
					$xfieldsdata[$value['name']] = $lang['xfield_xyes'];
				} else {
					$xfgiven = false;
					$xfieldsdata[$value['name']] = $lang['xfield_xno'];
				}
			} else {

				if (isset($xfieldsdata[$value['name']]) and $xfieldsdata[$value['name']]) $xfgiven = true;
				else $xfgiven = false;
			}

			if (!$xfgiven) {
				$tpl->copy_template = preg_replace("'\\[xfgiven_{$preg_safe_name}\\](.*?)\\[/xfgiven_{$preg_safe_name}\\]'is", "", $tpl->copy_template);
				$tpl->copy_template = str_ireplace("[xfnotgiven_{$value['name']}]", "", $tpl->copy_template);
				$tpl->copy_template = str_ireplace("[/xfnotgiven_{$value['name']}]", "", $tpl->copy_template);
			} else {
				$tpl->copy_template = preg_replace("'\\[xfnotgiven_{$preg_safe_name}\\](.*?)\\[/xfnotgiven_{$preg_safe_name}\\]'is", "", $tpl->copy_template);
				$tpl->copy_template = str_ireplace("[xfgiven_{$value['name']}]", "", $tpl->copy_template);
				$tpl->copy_template = str_ireplace("[/xfgiven_{$value['name']}]", "", $tpl->copy_template);
			}

			if (strpos($tpl->copy_template, "[ifxfvalue {$value['name']}") !== false) {

				$tpl->copy_template = preg_replace_callback("#\\[ifxfvalue(.+?)\\](.+?)\\[/ifxfvalue\\]#is", function ($matches) use ($xfieldsdata, $preg_safe_name, $value) {
					
					$matches[1] = trim($matches[1]);
					$check_values = array();

					if (($value['type'] == "select" OR $value['use_as_links']) and isset($xfieldsdata[$value['name']]) and $xfieldsdata[$value['name']]) {
						$field_value = explode(",", $xfieldsdata[$value['name']]);
						$field_value = array_map('trim', $field_value);
					} elseif (isset($xfieldsdata[$value['name']])) {
						$field_value = $xfieldsdata[$value['name']];
					} else $field_value = '';

					if (preg_match("#^{$preg_safe_name}\s*\!\=\s*['\"](.+?)['\"]#i", $matches[1], $match)) {

						$check_values = array_map('trim', explode(",", trim($match[1])));

						if (is_array($field_value)) {

							$found = false;

							foreach ($field_value as $tenp_value) {
								if (in_array($tenp_value, $check_values)) {
									$found = true;
								}
							}

							if ($found) return "";
							else return $matches[2];
						} else {

							if (!in_array($field_value, $check_values)) {
								return $matches[2];
							} else return "";
						}
					}

					if (preg_match("#^{$preg_safe_name}\s*\=\s*['\"](.+?)['\"]#i", $matches[1], $match)) {

						$check_values = array_map('trim', explode(",", trim($match[1])));

						if (is_array($field_value)) {

							$found = false;

							foreach ($field_value as $tenp_value) {
								if (in_array($tenp_value, $check_values)) {
									$found = true;
								}
							}

							if ($found) {
								return $matches[2];
							} else return "";
						} else {

							if (in_array($field_value, $check_values)) {
								return $matches[2];
							} else return "";
						}
					}

					return $matches[0];

				}, $tpl->copy_template);
			}

			if ($value['type'] == "select" AND isset($xfieldsdata[$value['name']]) AND $xfieldsdata[$value['name']] AND !$value['use_as_links']) {
				
				if (empty($value['select_separator'])) $value['select_separator'] = ", ";
				
				$xfieldsdata[$value['name']] = implode($value['select_separator'], explode(',', $xfieldsdata[$value['name']]));
			}

			if ($value['use_as_links'] AND !empty($xfieldsdata[$value['name']])) {
				$temp_array = explode(",", $xfieldsdata[$value['name']]);
				$value3 = array();

				foreach ($temp_array as $value2) {

					$value2 = trim($value2);
					$value2 = str_replace('&amp;#x2C;', ',', $value2);

					if ($value2) {

						$value4 = str_replace(array("&#039;", "&quot;", "&amp;", "&#123;", "&#91;", "&#58;", "/"), array("'", '"', "&", "{", "[", ":", "&frasl;"), $value2);

						if ($value['type'] == "datetime") {

							$value2 = strtotime($value4);

							if (!trim($value['date_view_format'])) $value['date_view_format'] = $config['timestamp_active'];

							if (strpos($tpl->copy_template, "[xfvalue_{$value['name']} format=") !== false) {

								$tpl->copy_template = preg_replace_callback(
									"#\\[xfvalue_{$preg_safe_name} format=['\"](.*?)['\"]\\]#i",
									function ($matches) use ($value, $value2, $value4, $customlangdate) {

										$matches[1] = trim($matches[1]);

										if ($value['date_local']) {

											if ($value['date_declension']) $value2 = langdate($matches[1], $value2);
											else return $value2 = langdate($matches[1], $value2, false, $customlangdate);
										} else $value2 = date($matches[1], $value2);

										return "<a href=\"" . DLEUrl::BuildUrl('xfsearch', ['xf' => $value['name'] . "/" . rawurlencode(dle_strtolower($value4))]) . "\">" . $value2 . "</a>";
									},
									$tpl->copy_template
								);
							}

							if ($value['date_local']) {

								if ($value['date_declension']) $value2 = langdate($value['date_view_format'], $value2);
								else $value2 = langdate($value['date_view_format'], $value2, false, $customlangdate);
							} else $value2 = date($value['date_view_format'], $value2);
						}

						$value3[] = "<a href=\"" . DLEUrl::BuildUrl('xfsearch', ['xf' => $value['name'] . "/" . rawurlencode(dle_strtolower($value4))]) . "\">" . $value2 . "</a>";
					}
				}

				if ($value['type'] == "select" AND $value['select_separator']) {
					$value['links_separator'] = $value['select_separator'];
				}

				if (empty($value['links_separator'])) $value['links_separator'] = ", ";

				$xfieldsdata[$value['name']] = implode($value['links_separator'], $value3);

				unset($temp_array);
				unset($value2);
				unset($value3);
				unset($value4);
			} elseif ($value['type'] == "datetime" AND !empty($xfieldsdata[$value['name']])) {

				$xfieldsdata[$value['name']] = strtotime(str_replace("&#58;", ":", $xfieldsdata[$value['name']]));

				if (!trim($value['date_view_format'])) $value['date_view_format'] = $config['timestamp_active'];

				if (strpos($tpl->copy_template, "[xfvalue_{$value['name']} format=") !== false) {

					$tpl->copy_template = preg_replace_callback(
						"#\\[xfvalue_{$preg_safe_name} format=['\"](.*?)['\"]\\]#i",
						function ($matches) use ($value, $xfieldsdata, $customlangdate) {

							$matches[1] = trim($matches[1]);

							if ($value['date_local']) {

								if ($value['date_declension']) return langdate($matches[1], $xfieldsdata[$value['name']]);
								else return langdate($matches[1], $xfieldsdata[$value['name']], false, $customlangdate);
							} else return date($matches[1], $xfieldsdata[$value['name']]);
						},
						$tpl->copy_template
					);
				}

				if ($value['date_local']) {

					if ($value['date_declension']) $xfieldsdata[$value['name']] = langdate($value['date_view_format'], $xfieldsdata[$value['name']]);
					else $xfieldsdata[$value['name']] = langdate($value['date_view_format'], $xfieldsdata[$value['name']], false, $customlangdate);
				} else $xfieldsdata[$value['name']] = date($value['date_view_format'], $xfieldsdata[$value['name']]);
			}

			if ($value['type'] == "select" and isset($xfieldsdata[$value['name']]) and $xfieldsdata[$value['name']]) {
				$xfieldsdata[$value['name']] = str_replace('&amp;#x2C;', ',', $xfieldsdata[$value['name']]);
			}

			if ($value['type'] == "image" and isset($xfieldsdata[$value['name']]) and $xfieldsdata[$value['name']]) {

				$temp_array = explode('|', $xfieldsdata[$value['name']]);

				if (count($temp_array) == 1 or count($temp_array) == 5) {

					$temp_alt = '';
					$temp_value = implode('|', $temp_array);
				} else {

					$temp_alt = $temp_array[0];
					$temp_alt = str_replace("&amp;#44;", "&#44;", $temp_alt);
					$temp_alt = str_replace("&amp;#124;", "&#124;", $temp_alt);

					unset($temp_array[0]);
					$temp_value =  implode('|', $temp_array);
				}

				$path_parts = get_uploaded_image_info($temp_value);
				
				if (self::$fill_opengraph AND (!isset($social_tags['image']) OR !$social_tags['image'] OR $value['use_opengraph']) ) {
					$social_tags['image'] = $path_parts->url;
					self::$fill_opengraph = false;
				}

				if ($value['make_thumb'] AND $path_parts->thumb) {

					$tpl->set("[xfvalue_thumb_url_{$value['name']}]", $path_parts->thumb);
					$xfieldsdata[$value['name']] = "<a href=\"{$path_parts->url}\" data-highslide=\"single\" target=\"_blank\"><img class=\"xfieldimage {$value['name']}\" src=\"{$path_parts->thumb}\" alt=\"{$temp_alt}\"></a>";
				} else {

					$tpl->set("[xfvalue_thumb_url_{$value['name']}]", $path_parts->url);
					$xfieldsdata[$value['name']] = "<img class=\"xfieldimage {$value['name']}\" src=\"{$path_parts->url}\" alt=\"{$temp_alt}\">";
				}

				$tpl->set("[xfvalue_image_url_{$value['name']}]", $path_parts->url);
				$tpl->set("[xfvalue_image_description_{$value['name']}]", $temp_alt);

				if ($value['allow_in_news']) {

					if (!$path_parts->thumb) $path_parts->thumb = $path_parts->url;

					$xfields_in_news['[xfvalue_image_url_' . $value['name'] . ']'] = $path_parts->url;
					$xfields_in_news['[xfvalue_image_description_' . $value['name'] . ']'] = $temp_alt;
					$xfields_in_news['[xfvalue_thumb_url_' . $value['name'] . ']'] = $path_parts->thumb;
				}
			}

			$xfieldsdata[$value['name']] = isset($xfieldsdata[$value['name']]) ? $xfieldsdata[$value['name']] : '';

			if ($value['type'] == "image" and !$xfieldsdata[$value['name']]) {
				$tpl->set("[xfvalue_thumb_url_{$value['name']}]", "");
				$tpl->set("[xfvalue_image_url_{$value['name']}]", "");
				$tpl->set("[xfvalue_image_description_{$value['name']}]", "");
			}

			if (($value['type'] == "video" OR $value['type'] == "audio") and $xfieldsdata[$value['name']]) {

				$fieldvalue_arr = explode(',', $xfieldsdata[$value['name']]);
				$playlist = array();
				$playlist_single = array();
				$xf_playlist_count = 0;
				$xf_playlist_media_count = 0;

				if ($value['type'] == "audio") {
					$xftag = "audio";
					$xftype = "audio/mp3";
				} else {
					$xftag = "video";
					$xftype = "video/mp4";
				}

				if (!count(self::$video_config)) {

					include(ENGINE_DIR . '/data/videoconfig.php');

					if (is_array($video_config) AND count($video_config)) {
						self::$video_config = $video_config;
					} else {
						self::$video_config = array(
							'width' => '600',
							'audio_width' => '600',
							'theme' => 'light',
							'preload' => '1',
						);
					}
				}

				if (self::$video_config['preload']) $preload = "metadata"; else $preload = "none";
				
				if ($value['type'] == "audio") {
					$playlist_width =  self::normalizePlayerWidth(self::$video_config['audio_width']);
				} else {
					$playlist_width =  self::normalizePlayerWidth(self::$video_config['width']);
				}
				

				$playlist_width = "style=\"width:100%;max-width:{$playlist_width};\"";

				foreach ($fieldvalue_arr as $temp_value) {

					$xf_playlist_count++;

					$temp_value = trim($temp_value);

					if (!$temp_value) continue;

					$xf_playlist_media_count++;

					$temp_array = explode('|', $temp_value);

					if (count($temp_array) < 4) {

						$temp_alt = '';
						$temp_url = $temp_array[0];
					} else {

						$temp_alt = $temp_array[0];
						$temp_url = $temp_array[1];
					}

					$filename = pathinfo($temp_url, PATHINFO_FILENAME);
					$filename = explode("_", $filename);
					if (count($filename) > 1 and intval($filename[0])) unset($filename[0]);
					$filename = implode("_", $filename);

					if (!$temp_alt) $temp_alt = $filename;

					$media_preload = $xf_playlist_media_count == 1 ? $preload : "none";

					$playlist[] = "<{$xftag} title=\"{$temp_alt}\" preload=\"{$media_preload}\" controls><source type=\"{$xftype}\" src=\"{$temp_url}\"></{$xftag}>";
					$playlist_single['[xfvalue_' . $value['name'] . ' ' . $xftag . '="' . $xf_playlist_count . '"]'] = "<div class=\"dleplyrplayer\" {$playlist_width} theme=\"" . self::$video_config['theme'] . "\"><{$xftag} title=\"{$temp_alt}\" preload=\"{$preload}\" controls><source type=\"{$xftype}\" src=\"{$temp_url}\"></{$xftag}></div>";

					$playlist_single['[xfvalue_' . $value['name'] . ' ' . $xftag . '-description="' . $xf_playlist_count . '"]'] = $temp_alt;
					$playlist_single['[xfvalue_' . $value['name'] . ' ' . $xftag . '-url="' . $xf_playlist_count . '"]'] = $temp_url;

					$tpl->copy_template = str_ireplace('[xfgiven_' . $value['name'] . ' ' . $xftag . '="' . $xf_playlist_count . '"]', "", $tpl->copy_template);
					$tpl->copy_template = str_ireplace('[/xfgiven_' . $value['name'] . ' ' . $xftag . '="' . $xf_playlist_count . '"]', "", $tpl->copy_template);
					$tpl->copy_template = preg_replace("'\\[xfnotgiven_{$preg_safe_name} {$xftag}=\"{$xf_playlist_count}\"\\](.*?)\\[/xfnotgiven_{$preg_safe_name} {$xftag}=\"{$xf_playlist_count}\"\\]'is", "", $tpl->copy_template);
				}

				if (count($playlist_single)) {

					foreach ($playlist_single as $temp_key => $temp_value) {

						$tpl->set($temp_key, $temp_value);

						if ($value['allow_in_news']) {
							$xfields_in_news[$temp_key] = $temp_value;
						}
					}
				}

				$xfieldsdata[$value['name']] = "<div class=\"dleplyrplayer\" {$playlist_width} theme=\"" . self::$video_config['theme'] . "\">" . implode($playlist) . "</div>";
			}

			if ($value['type'] == "imagegalery" and $xfieldsdata[$value['name']]) {

				$fieldvalue_arr = explode(',', $xfieldsdata[$value['name']]);
				$gallery_image = array();
				$gallery_single_image = array();
				$xf_image_count = 0;

				foreach ($fieldvalue_arr as $temp_value) {

					$xf_image_count++;

					$temp_value = trim($temp_value);

					if (!$temp_value) continue;

					$temp_array = explode('|', $temp_value);

					if (count($temp_array) == 1 or count($temp_array) == 5) {

						$temp_alt = '';
						$temp_value = implode('|', $temp_array);
					} else {

						$temp_alt = $temp_array[0];
						$temp_alt = str_replace("&amp;#44;", "&#44;", $temp_alt);
						$temp_alt = str_replace("&amp;#124;", "&#124;", $temp_alt);

						unset($temp_array[0]);
						$temp_value =  implode('|', $temp_array);
					}

					$path_parts = get_uploaded_image_info($temp_value);

					if (self::$fill_opengraph AND (!isset($social_tags['image']) OR (isset($social_tags['image']) AND !$social_tags['image']) OR $value['use_opengraph']) ) {
						$social_tags['image'] = $path_parts->url;
						self::$fill_opengraph = false;
					}

					if ($value['make_thumb'] and $path_parts->thumb) {

						$gallery_image[] = "<li><a href=\"{$path_parts->url}\" data-highslide=\"xf_{$row['id']}_{$value['name']}\" target=\"_blank\"><img src=\"{$path_parts->thumb}\" alt=\"{$temp_alt}\"></a></li>";
						$gallery_single_image['[xfvalue_' . $value['name'] . ' image="' . $xf_image_count . '"]'] = "<a href=\"{$path_parts->url}\" data-highslide=\"single\" target=\"_blank\"><img class=\"xfieldimage {$value['name']}\" src=\"{$path_parts->thumb}\" alt=\"{$temp_alt}\"></a>";
					} else {

						$gallery_image[] = "<li><img src=\"{$path_parts->url}\" alt=\"{$temp_alt}\"></li>";
						$gallery_single_image['[xfvalue_' . $value['name'] . ' image="' . $xf_image_count . '"]'] = "<img class=\"xfieldimage {$value['name']}\" src=\"{$path_parts->url}\" alt=\"{$temp_alt}\">";
					}

					if (!$path_parts->thumb) $path_parts->thumb = $path_parts->url;

					$gallery_single_image['[xfvalue_' . $value['name'] . ' image-description="' . $xf_image_count . '"]'] = $temp_alt;
					$gallery_single_image['[xfvalue_' . $value['name'] . ' image-thumb-url="' . $xf_image_count . '"]'] = $path_parts->thumb;
					$gallery_single_image['[xfvalue_' . $value['name'] . ' image-url="' . $xf_image_count . '"]'] = $path_parts->url;

					$tpl->copy_template = str_ireplace('[xfgiven_' . $value['name'] . ' image="' . $xf_image_count . '"]', "", $tpl->copy_template);
					$tpl->copy_template = str_ireplace('[/xfgiven_' . $value['name'] . ' image="' . $xf_image_count . '"]', "", $tpl->copy_template);
					$tpl->copy_template = preg_replace("'\\[xfnotgiven_{$preg_safe_name} image=\"{$xf_image_count}\"\\](.*?)\\[/xfnotgiven_{$preg_safe_name} image=\"{$xf_image_count}\"\\]'is", "", $tpl->copy_template);
				}

				if (count($gallery_single_image)) {

					foreach ($gallery_single_image as $temp_key => $temp_value) {

						$tpl->set($temp_key, $temp_value);

						if ($value['allow_in_news']) {
							$xfields_in_news[$temp_key] = $temp_value;
						}
					}
				}

				$xfieldsdata[$value['name']] = "<ul class=\"xfieldimagegallery {$value['name']}\">" . implode($gallery_image) . "</ul>";
			}

			$tpl->copy_template = preg_replace("'\\[xfgiven_{$preg_safe_name} (image|video|audio)=\"(\d+)\"\\](.*?)\\[/xfgiven_{$preg_safe_name} (image|video|audio)=\"(\d+)\"\\]'is", "", $tpl->copy_template);
			$tpl->copy_template = preg_replace("'\\[xfnotgiven_{$preg_safe_name} (image|video|audio)=\"(\d+)\"\\]'i", "", $tpl->copy_template);
			$tpl->copy_template = preg_replace("'\\[/xfnotgiven_{$preg_safe_name} (image|video|audio)=\"(\d+)\"\\]'i", "", $tpl->copy_template);

			if ($value['lazy_load'] AND function_exists('enable_lazyload') AND (!isset($view_template) OR $view_template != "rss") ) $xfieldsdata[$value['name']] = preg_replace_callback("#<(img|iframe)(.+?)>#i", "enable_lazyload", $xfieldsdata[$value['name']]);

			if (isset($view_template) AND $view_template == "rss") {
				$xfieldsdata[$value['name']] = clear_rss_content($xfieldsdata[$value['name']], $rssmode);
			}

			$tpl->set("[xfvalue_{$value['name']}]", $xfieldsdata[$value['name']]);

			if ($value['allow_in_news']) {
				$xfields_in_news['[xfvalue_' . $value['name'] . ']'] = $xfieldsdata[$value['name']];
			}

			if (isset($page_description) AND !$page_description) {
				if (($value['type'] == "text" or $value['type'] == "textarea") and $xfieldsdata[$value['name']]) {
					$all_xf_content[] = $xfieldsdata[$value['name']];
				}
			}

			if (preg_match("#\\[xfvalue_{$preg_safe_name} limit=['\"](.+?)['\"]\\]#i", $tpl->copy_template, $matches)) {
				$tpl->set($matches[0], clear_content($xfieldsdata[$value['name']], $matches[1]));
			}
		}

	}

	public static function GetRubricsArray() {
		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		if( isset(self::$fields['groups']) AND is_array(self::$fields['groups']) ) return self::$fields['groups'];
		else return [];
	}

	public static function GETFieldByRubric( $group_name ) {
		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		$fields = [];
		if (isset(self::$fields['fields']) AND is_array(self::$fields['fields']) AND count(self::$fields['fields'])) {

			foreach (self::$fields['fields'] as $key => $value) {
				if ($value['group'] == $group_name) $fields[$key] = $value;
			}
		}

		return $fields;

	}
	
	public static function GETField( $name ) {
		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		if (isset(self::$fields['fields']) AND is_array(self::$fields['fields']) AND isset(self::$fields['fields'][$name]) AND is_array(self::$fields['fields'][$name]) ) {

			return self::$fields['fields'][$name];
		}

		return [];

	}
	
	public static function GetFieldsNames() {
		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		if (isset(self::$fields['fields']) AND is_array(self::$fields['fields']) ) {
			$names = [];
			foreach(self::$fields['fields'] as $value){
				$names[] = $value['name'];
			}

			return $names;
		}

		return [];

	}

	public static function SaveField( $name, $data ) {
		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		$name = totranslit($name);

		self::$fields['fields'][$name] = $data;
		self::SaveFields(self::$fields);
	}

	public static function AddRubric( $title, $description ) {
		global $lang;

		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		$group_name = totranslit($title, true, false);

		$title  = trim(htmlspecialchars(strip_tags(stripslashes($title)), ENT_QUOTES, 'UTF-8'));
		$description  = trim(htmlspecialchars(strip_tags(stripslashes($description)), ENT_QUOTES, 'UTF-8'));
		
		if(!$title) {
			self::$error = $lang['xf_err_2'];
			return false;
		}

		if( !isset(self::$fields['groups'][$group_name]) ) {
			self::$fields['groups'][$group_name] = ['title' => $title, 'description' => $description];
			self::SaveFields(self::$fields);
			return $group_name;
		} else {
			self::$error = $lang['xf_err_1'];
		}
		return false;
	}

	public static function EditRubric( $group_name, $title, $description ) {
		global $lang;

		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		$group_name = totranslit($group_name, true, false);

		$title  = trim(htmlspecialchars(strip_tags(stripslashes($title)), ENT_QUOTES, 'UTF-8'));
		$description  = trim(htmlspecialchars(strip_tags(stripslashes($description)), ENT_QUOTES, 'UTF-8'));
		
		if(!$title) {
			self::$error = $lang['xf_err_2'];
			return false;
		}

		if( isset(self::$fields['groups'][$group_name]) ) {
			self::$fields['groups'][$group_name] = ['title' => $title, 'description' => $description];
			self::SaveFields(self::$fields);
			return $group_name;
		} else {
			self::$error = $lang['xf_err_3'];
		}

		return false;
	}

	public static function DeleteRubric( $group_name ) {
		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		$group_name = totranslit($group_name, true, false);

		if (isset(self::$fields['groups'][$group_name])) {
			unset(self::$fields['groups'][$group_name]);
			self::DeleteFieldsByRubric($group_name);
			self::SaveFields(self::$fields);
			return $group_name;
		} else return false;
	}
	
	private static function DeleteFieldsByRubric( $group_name ) {
		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		if (isset(self::$fields['fields']) AND is_array(self::$fields['fields']) AND count(self::$fields['fields']) ) {

			foreach (self::$fields['fields'] as $key => $value) {
				if ($value['group'] == $group_name) unset(self::$fields['fields'][$key]);
			}
		}

	}

	public static function SortRubrics( $groups ) {
		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}
		
		$sorted = [];

		foreach ( $groups as $value ) {
			$sorted[$value]  = self::$fields['groups'][$value];
		}
		
		self::$fields['groups'] = $sorted;

		self::SaveFields(self::$fields);

	}

	public static function SortFields( $fields ) {
		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}
		
		$sorted = [];

		foreach ( $fields as $value ) {
			$sorted[$value]  = self::$fields['fields'][$value];
			unset(self::$fields['fields'][$value]);
			self::$fields['fields'][$value] = $sorted[$value];
		}

		self::SaveFields(self::$fields);

	}

	public static function DeleteField( $name ) {
		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		$name = totranslit($name);
		
		if (isset(self::$fields['fields'][$name])) {
			unset(self::$fields['fields'][$name]);
			self::SaveFields(self::$fields);
			return $name;
		} else return false;
	}

	public static function DisableField( $name ) {
		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		$name = totranslit($name);
		
		if (isset(self::$fields['fields'][$name])) {
			self::$fields['fields'][$name]['disabled'] = 1;
			self::SaveFields(self::$fields);
			return $name;
		} else return false;
	}

	public static function EnableField( $name ) {
		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		$name = totranslit($name);
		
		if (isset(self::$fields['fields'][$name])) {
			unset(self::$fields['fields'][$name]['disabled']);
			self::SaveFields(self::$fields);
			return $name;
		} else return false;
	}

	private static function LoadFields() {
		
		if (file_exists( ENGINE_DIR . '/cache/system/xfields.php' )) {
			self::$fields = require ENGINE_DIR . '/cache/system/xfields.php';
			
			if (is_array(self::$fields)) {
				return self::$fields;
			}
			self::$fields = ['fields' => []];
		}

		if( !file_exists(ENGINE_DIR . '/data/xfields.json') ) {
			self::$fields = ['fields' => []];
		} else {
			$fields = @file_get_contents(ENGINE_DIR . '/data/xfields.json');

			if ($fields !== false) {

				self::$fields = json_decode($fields, true);

				if (json_last_error() === JSON_ERROR_NONE AND is_array(self::$fields)) {
					if (!isset(self::$fields['fields'])) self::$fields['fields'] = [];
				} else {
					self::$fields = ['fields' => []];
				}
			} else {
				self::$fields = ['fields' => []];
			}
		}

		@file_put_contents(ENGINE_DIR . '/cache/system/xfields.php','<?php return ' . var_export(self::$fields, true) . ';');
		@chmod(ENGINE_DIR . '/cache/system/xfields.php', 0666);

		return self::$fields;

	}

	private static function SaveFields( $fields ) {
		@file_put_contents(ENGINE_DIR . '/data/xfields.json', json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
		@chmod(ENGINE_DIR .  '/data/xfields.json', 0666);
		@unlink(ENGINE_DIR . '/cache/system/xfields.php');
		
		if (function_exists('opcache_reset')) {
			opcache_reset();
		}		
	}

}
