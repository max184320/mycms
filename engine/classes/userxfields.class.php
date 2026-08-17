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

abstract class DLEUserXFields { 

	public static $fields = null;
	public static $error = null;
	
	public static function Init() {
		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}
	}
	
	public static function Parse($row = null) {
		global $member_id, $user_group, $parse, $db;

		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		if ($row === null OR !is_array($row)) {
			$xfieldsdata = [];
		} else {
			$xfieldsdata = self::xfieldsdataload(stripslashes($row['xfields']));
		}
		
		$postedxfields = isset($_POST['xfield']) ? $_POST['xfield'] : array();
		$newpostedxfields = array();

		foreach (self::$fields['fields'] AS $value) {
			
			if (!$value['registration'] AND $row === null) {
				continue;
			}

			if (intval($value['allow_change']) OR $user_group[$member_id['user_group']]['admin_editusers'] OR ($value['registration'] AND $row === null) ) {

				if ($value['type'] == "select") {

					$value['default'] = str_replace("\r", '', $value['default']);
					$options = explode("\n", $value['default']);

					$options = explode("|", $options[$postedxfields[$value['name']]]);
					$postedxfields[$value['name']] = $options[0];
				}
				
				if ($value['type'] == "datetime" AND isset($postedxfields[$value['name']]) AND $postedxfields[$value['name']]) {

					$postedxfields[$value['name']] = @strtotime($postedxfields[$value['name']]);

					if ($postedxfields[$value['name']] !== -1 AND $postedxfields[$value['name']]) {

						if ($value['date_format'] == 1) $postedxfields[$value['name']] = date("Y-m-d", $postedxfields[$value['name']]);
						elseif ($value['date_format'] == 2) $postedxfields[$value['name']] = date("H:i", $postedxfields[$value['name']]);
						else $postedxfields[$value['name']] = date("Y-m-d H:i", $postedxfields[$value['name']]);
					} else $postedxfields[$value['name']] = '';
				}

				if (isset($postedxfields[$value['name']]) AND dle_strlen($postedxfields[$value['name']]) > 10000) {
					$postedxfields[$value['name']] = '';
				}

				if ($value['type'] == "yesorno") {
					$postedxfields[$value['name']] = isset($postedxfields[$value['name']]) ? intval($postedxfields[$value['name']]) : 0;
				}
				
				if ($value['type'] == "datetime" AND isset($postedxfields[$value['name']]) AND $postedxfields[$value['name']] ) {

					$newpostedxfields[$value['name']] = str_replace(":", "&#58;", (string)$postedxfields[$value['name']]);

				} elseif ($value['type'] == "yesorno") {

					$newpostedxfields[$value['name']] = $postedxfields[$value['name']];

				} elseif ( ($value['safe_mode'] OR $value['type'] == "select" ) AND $postedxfields[$value['name']]) {

					$newpostedxfields[$value['name']] = str_replace("&#44;", "&amp;#44;", (string)$postedxfields[$value['name']]);
					$newpostedxfields[$value['name']] = str_replace("&#124;", "&amp;#124;", $newpostedxfields[$value['name']]);
					$newpostedxfields[$value['name']] = str_replace("&#x2C;", "&amp;#x2C;", $newpostedxfields[$value['name']]);

					$newpostedxfields[$value['name']] = html_entity_decode($newpostedxfields[$value['name']], ENT_QUOTES, 'UTF-8');
					$newpostedxfields[$value['name']] = trim(htmlspecialchars(strip_tags(stripslashes($newpostedxfields[$value['name']])), ENT_QUOTES, 'UTF-8'));

				} else {
					$parse->remove_html = false;
					$parse->wysiwyg = true;

					$newpostedxfields[$value['name']] = $parse->BB_Parse($parse->process(trim((string)$postedxfields[$value['name']])), false);
					
					if ($value['type'] == 'text') {
						$newpostedxfields[$value['name']] = str_replace('&amp;', '&', $newpostedxfields[$value['name']]);
					}

				}
				
				$newpostedxfields[$value['name']] = str_replace(array("{", "["), array("&#123;", "&#91;"), $newpostedxfields[$value['name']]);
				$newpostedxfields[$value['name']] = preg_replace(array('/data:/i', '/about:/i', '/vbscript:/i', '/javascript:/i'), array("d&#1072;ta&#58;", "&#1072;bout&#58;", "vbscript&#58;", "j&#1072;vascript&#58;"), $newpostedxfields[$value['name']]);

			} elseif( isset($xfieldsdata[$value['name']]) AND $xfieldsdata[$value['name']] ) {
			
				$newpostedxfields[$value['name']] = $xfieldsdata[$value['name']]; 
			
			} else {
				$newpostedxfields[$value['name']] = '';
			}

		}

		$postedxfields = $newpostedxfields;
		unset($newpostedxfields);
		$filecontents = [];
			
		if (count($postedxfields)) {

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
				$filecontents[] = "{$xfielddataname}|{$xfielddatavalue}";
			}

			if (count($filecontents)) $filecontents = $db->safesql(implode("||", $filecontents));
			else $filecontents = '';

		} else $filecontents = '';

		return (string)$filecontents;

	}

	public static function FieldsList($row = null, $xfieldmode = '') {
		
		global $member_id, $user_group, $parse;

		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		if ($row === null) {
			$xfieldsdata = [];
		} else {
			$xfieldsdata = self::xfieldsdataload($row['xfields']);
		}
		
		$xfieldinput = array();
		$fields_list = array();

		foreach (self::$fields['fields'] as $name => $value) {
			$fieldname = $name;
			$value['description'] = htmlspecialchars($value['description'], ENT_QUOTES, 'UTF-8');

			if ($row !== null AND !$value['allow_change'] AND !$user_group[$member_id['user_group']]['admin_editusers']) continue;
			
			if ( count($xfieldsdata) ) {

				$fieldvalue = isset($xfieldsdata[$value['name']]) ? (string)$xfieldsdata[$value['name']] : '';

				if ($value['safe_mode'] OR $value['type'] == "select") {

					$fieldvalue = str_replace("&#44;", "&amp;#44;", $fieldvalue);
					$fieldvalue = str_replace("&#124;", "&amp;#124;", $fieldvalue);
					$fieldvalue = html_entity_decode(stripslashes($fieldvalue), ENT_QUOTES, 'UTF-8');
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
					$parse->safe_mode = false;
					$fieldvalue = $parse->decodeBBCodes($fieldvalue, false);
					$fieldvalue = str_replace( array("&amp;#123;", "&amp;#91;"), array("&#123;", "&#91;"), $fieldvalue);

				}
				
				$fieldvalue = str_replace(array("{", "["), array("&#123;", "&#91;"), $fieldvalue);

			} else {
				$fieldvalue = '';
			}

			if (intval($value['registration']) OR $row !== null ) {

				if ($value['type'] == "textarea") {

					if ($xfieldmode == "site") {
						
						$output = <<<HTML
<div class="xfieldsrow">
	<div class="xfieldscolleft">{$value['description']}:</div>
	<div class="xfieldscolright"><textarea dir="auto" name="xfield[{$fieldname}]" id="xf_{$fieldname}" class="quick-edit-textarea">{$fieldvalue}</textarea></div>
</div>
HTML;

						$xfieldinput[$fieldname] = "<textarea dir=\"auto\" name=\"xfield[{$fieldname}]\" id=\"xf_{$fieldname}\">{$fieldvalue}</textarea>";

					} elseif ( $xfieldmode == "admin" ) {
						
						$output = <<<HTML
<tr>
<td style="padding:4px;">{$value['description']}:</td>
<td class="xprofile" colspan="2"><textarea dir="auto" name="xfield[{$fieldname}]" id="xf_{$fieldname}">{$fieldvalue}</textarea></td></tr>
HTML;
					} else {

						$output = <<<HTML
				<div class="form-group">
				  <label class="control-label col-md-3 col-sm-3">{$value['description']}:</label>
				  <div class="col-md-9 col-sm-9">
					<textarea dir="auto" name="xfield[{$fieldname}]" id="xf_{$fieldname}" class="classic" style="width:100%;" rows="7">{$fieldvalue}</textarea>
				  </div>
				 </div>
HTML;
					}

				} elseif($value['type'] == "text") {
					
					if ($xfieldmode == "site") {

						$output = <<<HTML
<div class="xfieldsrow">
	<div class="xfieldscolleft">{$value['description']}:</div>
	<div class="xfieldscolright"><input type="text" dir="auto" name="xfield[{$fieldname}]" id="xf_{$fieldname}" value="{$fieldvalue}" class="quick-edit-text"></div>
</div>
HTML;

						$xfieldinput[$fieldname] = "<input type=\"text\" dir=\"auto\" name=\"xfield[{$fieldname}]\" id=\"xf_{$fieldname}\" value=\"{$fieldvalue}\">";

					} elseif ($xfieldmode == "admin") {

						$output = <<<HTML
<tr>
<td style="padding:4px;">{$value['description']}:</td>
<td class="xprofile" colspan="2"><input type="text" dir="auto" name="xfield[{$fieldname}]" id="xf_{$fieldname}" value="{$fieldvalue}"></td></tr>
HTML;
					} else {

						$output = <<<HTML
				<div class="form-group">
				  <label class="control-label col-md-3 col-sm-3">{$value['description']}:</label>
				  <div class="col-md-9 col-sm-9">
					<input class="form-control" type="text" dir="auto" name="xfield[{$fieldname}]" id="xf_{$fieldname}" value="{$fieldvalue}">
				  </div>
				 </div>
HTML;
					}

				} elseif ($value['type'] == "select") {

					if ($xfieldmode == "site") {
						$select = "<select name=\"xfield[{$fieldname}]\" id=\"xf_{$fieldname}\" class=\"uniform quick-edit-select\">";
					} else {
						$select = "<select name=\"xfield[{$fieldname}]\" id=\"xf_{$fieldname}\" class=\"uniform\">";
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
<div class="xfieldsrow">
	<div class="xfieldscolleft">{$value['description']}:</div>
	<div class="xfieldscolright">{$select}</div>
</div>
HTML;
						$xfieldinput[$fieldname] = $select;

					} elseif ($xfieldmode == "admin") {

						$output = <<<HTML

<tr>
<td style="padding:4px;">{$value['description']}:</td>
<td class="xprofile" colspan="2">{$select}</td>
</tr>
HTML;
					} else {

						$output = <<<HTML
				<div class="form-group">
				  <label class="control-label col-md-3 col-sm-3">{$value['description']}:</label>
				  <div class="col-md-9 col-sm-9">
					{$select}
				  </div>
				 </div>
HTML;

					}
				} elseif ($value['type'] == "yesorno") {
					
					if (!isset($fieldvalue) OR $fieldvalue === '') $fieldvalue = $value['condition'];

					$fieldvalue = intval($fieldvalue);
					$selected = $fieldvalue ? " checked" : "";

					if ($xfieldmode == "site") {

						$output = <<<HTML
<div class="xfieldsrow">
	<div class="xfieldscolleft">{$value['description']}:</div>
	<div class="xfieldscolright"><label class="form-check-label"><input class="form-check-input" type="checkbox" name="xfield[{$fieldname}]" value="1"{$selected}></label></div>
</div>
HTML;
						$xfieldinput[$fieldname] = "<label class=\"form-check-label\"><input class=\"form-check-input\" type=\"checkbox\" name=\"xfield[{$fieldname}]\" value=\"1\"{$selected}></label>";

					} elseif ($xfieldmode == "admin") {
						
						$output = <<<HTML

<tr>
<td style="padding:4px;">{$value['description']}:</td>
<td class="xprofile" colspan="2"><label class="form-check-label"><input class="form-check-input" type="checkbox" name="xfield[{$fieldname}]" value="1"{$selected}></label></td>
</tr>
HTML;

					} else {

						$output = <<<HTML
				<div class="form-group">
				  <label class="control-label col-md-3 col-sm-3">{$value['description']}:</label>
				  <div class="col-md-9 col-sm-9">
					<input class="switch" type="checkbox" name="xfield[{$fieldname}]" value="1"{$selected}>
				  </div>
				 </div>
HTML;

					}

				} elseif ($value['type'] == "datetime") {

					if ($value['date_format'] == 1) {
						$params = "data-rel=\"calendardate\" ";
					} elseif ($value['date_format'] == 2) {
						$params = "data-rel=\"calendartime\" ";
					} else {
						$params = "data-rel=\"calendardatetime\" ";
					}

					if ($xfieldmode == "site") {

						$output = <<<HTML
<div class="xfieldsrow">
	<div class="xfieldscolleft">{$value['description']}:</div>
	<div class="xfieldscolright"><input type="text" name="xfield[{$fieldname}]" id="xf_{$fieldname}" autocomplete="off" value="{$fieldvalue}" class="quick-edit-datetime" {$params}></div>
</div>
HTML;
						$xfieldinput[$fieldname] = "<input type=\"text\" dir=\"auto\" name=\"xfield[{$fieldname}]\" id=\"xf_{$fieldname}\" autocomplete=\"off\" value=\"{$fieldvalue}\" {$params}>";

					} elseif ($xfieldmode == "admin") {

						$output = <<<HTML

<tr>
<td style="padding:4px;">{$value['description']}:</td>
<td class="xprofile" colspan="2"><input type="text" name="xfield[{$fieldname}]" id="xf_{$fieldname}" autocomplete="off" value="{$fieldvalue}" {$params}></td>
</tr>
HTML;

					} else {

						$output = <<<HTML
				<div class="form-group">
				  <label class="control-label col-md-3 col-sm-3">{$value['description']}:</label>
				  <div class="col-md-9 col-sm-9">
					<input type="text" dir="auto" class="form-control" style="width:200px;" name="xfield[{$fieldname}]" id="xf_{$fieldname}" autocomplete="off" value="{$fieldvalue}" {$params}>
				  </div>
				 </div>
HTML;						
					}
				}

			}

			if (isset($output)) {
				$fields_list['fields'][$fieldname] = $output;
				unset($output);
			}
		}
		
		$fields_list['custom'] = $xfieldinput;
		if (!isset($fields_list['fields'])) $fields_list['fields'] = array();

		return $fields_list;

	}

	public static function Compile( &$row, &$tpl, $is_own = true) {
		global $lang, $config, $customlangdate;

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

				if (!isset($matches[1]) OR !isset($matches[2]) OR !isset($matches[3]) OR !$matches[1] OR !$matches[2] OR !$matches[3]) {
					return $matches[0];
				}

				$matches[2] = trim($matches[2]);
				$fields_arr = explode(',', $matches[2]);
				$found = 0;

				foreach ($fields_arr as $field) {
					$field  = trim($field);

					if ($matches[1] == 'set') {
						if (isset($xfieldsdata[$field]) AND strlen(trim((string)$xfieldsdata[$field])) > 0) $found++;
					} elseif ($matches[1] == 'notset') {
						if (!isset($xfieldsdata[$field]) OR strlen(trim((string)$xfieldsdata[$field])) < 1) $found++;
					}
				}

				if ($found == count($fields_arr)) return $matches[3];
				else return '';
			}, $tpl->copy_template
		);

		foreach (self::$fields['fields'] as $value) {
			$preg_safe_name = preg_quote($value['name'], "'");
			
			if (!isset($xfieldsdata[$value['name']])) $xfieldsdata[$value['name']] = '';
			
			if (!$value['private'] OR $is_own ) {
	
				if ($value['type'] == "yesorno") {

					if (isset($xfieldsdata[$value['name']]) AND intval($xfieldsdata[$value['name']])) {
						$xfgiven = true;
						$xfieldsdata[$value['name']] = $lang['xfield_xyes'];
					} else {
						$xfgiven = false;
						$xfieldsdata[$value['name']] = $lang['xfield_xno'];
					}
				} else {

					if (isset($xfieldsdata[$value['name']]) AND $xfieldsdata[$value['name']]) $xfgiven = true;
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
				
				if ($value['type'] == "datetime" AND !empty($xfieldsdata[$value['name']])) {

					$xfieldsdata[$value['name']] = strtotime(str_replace("&#58;", ":", $xfieldsdata[$value['name']]));

					if (!trim($value['date_view_format'])) $value['date_view_format'] = $config['timestamp_active'];

					if (strpos($tpl->copy_template, "[xfvalue_{$value['name']} format=") !== false) {

						$tpl->copy_template = preg_replace_callback( "#\\[xfvalue_{$preg_safe_name} format=['\"](.*?)['\"]\\]#i",
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

				$tpl->set("[xfvalue_{$value['name']}]", $xfieldsdata[$value['name']]);

			} else {

				$tpl->copy_template = preg_replace("'\\[xfgiven_{$preg_safe_name}\\](.*?)\\[/xfgiven_{$preg_safe_name}\\]'is", "", $tpl->copy_template);
				$tpl->copy_template = str_ireplace("[xfnotgiven_{$value['name']}]", "", $tpl->copy_template);
				$tpl->copy_template = str_ireplace("[/xfnotgiven_{$value['name']}]", "", $tpl->copy_template);
				$tpl->set("[xfvalue_{$value['name']}]", '');

			}


		}

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

	public static function GETFields() {
		if (!is_array(self::$fields)) {
			self::LoadFields(self::$fields);
		}

		return self::$fields['fields'];

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

	private static function LoadFields() {
		
		if (file_exists( ENGINE_DIR . '/cache/system/userxfields.php' )) {
			self::$fields = require ENGINE_DIR . '/cache/system/userxfields.php';
			
			if (is_array(self::$fields)) {
				return self::$fields;
			}
			self::$fields = ['fields' => []];
		}

		if( !file_exists(ENGINE_DIR . '/data/userxfields.json') ) {
			self::$fields = ['fields' => []];
		} else {
			$fields = @file_get_contents(ENGINE_DIR . '/data/userxfields.json');

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

		@file_put_contents(ENGINE_DIR . '/cache/system/userxfields.php','<?php return ' . var_export(self::$fields, true) . ';');
		@chmod(ENGINE_DIR . '/cache/system/userxfields.php', 0666);

		return self::$fields;

	}

	private static function SaveFields( $fields ) {
		@file_put_contents(ENGINE_DIR . '/data/userxfields.json', json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
		@chmod(ENGINE_DIR .  '/data/userxfields.json', 0666);
		@unlink(ENGINE_DIR . '/cache/system/userxfields.php');
		
		if (function_exists('opcache_reset')) {
			opcache_reset();
		}		
	}

}
