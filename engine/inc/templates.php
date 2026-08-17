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
 File: templates.php
-----------------------------------------------------
 Use: Templates
=====================================================
*/

if( !defined( 'DATALIFEENGINE' ) OR !defined( 'LOGGED_IN' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

if( $member_id['user_group'] != 1 ) {
	msg( "error", $lang['opt_denied'], $lang['opt_denied'] );
}

if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
	
	header( "Location: ?mod=templates&user_hash=" . $dle_login_hash );
	die();

}

$_REQUEST['do_template'] = isset($_REQUEST['do_template']) ? trim( totranslit($_REQUEST['do_template'], false, false) ) : '';

$do_template = $_REQUEST['do_template'];
$subaction = $_REQUEST['subaction'];

$templates_list = get_folder_list( 'templates' );
$language_list = get_folder_list( 'language' );

if( $_REQUEST['subaction'] == "language" ) {
	
	$allow_save = false;
	
	include(ENGINE_DIR . '/data/config.php');

	$_REQUEST['do_template'] = trim( totranslit($_REQUEST['do_template'], false, false) );
	$_REQUEST['do_language'] = trim( totranslit($_REQUEST['do_language'], false, false) );

	if( $_REQUEST['do_template'] != "" and $_REQUEST['do_language'] != "" ) {
		$config["lang_" . $_REQUEST['do_template']] = $_REQUEST['do_language'];
		$allow_save = true;
	
	} elseif( isset($config["lang_" . $_REQUEST['do_template']]) AND $config["lang_" . $_REQUEST['do_template']] AND $_REQUEST['do_language'] == "" ) {
		unset( $config["lang_" . $_REQUEST['do_template']] );
		$allow_save = true;
	}
	
	if( $allow_save ) {

		$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '66', '{$_REQUEST['do_template']}')" );
		
		if( $auto_detect_config ) $config['http_home_url'] = "";

		@file_put_contents(ENGINE_DIR . '/data/config.php', "<?php \n\n//System Configurations\n\n\$config = " . var_export($config, true) . ';', LOCK_EX);
	
	}

}

if( $subaction == "new" ) {

	$b_form = "<form method=\"post\"><table width=100%><tr><td height=\"150\"><center>$lang[opt_newtemp_1]&nbsp;&nbsp;&nbsp;<select name=\"base_template\" class=\"uniform\">";

	foreach ( $templates_list as $key => $value ) {
		$b_form .= "<option value=\"{$key}\">{$value['name']}</option>";
	}

	$b_form .= '</select>&nbsp;&nbsp;' . $lang['opt_msgnew'] . '&nbsp;&nbsp;<input class="form-control" style="width:190px;" type="text" dir="auto" name="template_name"><br><br><input type="submit" value="' . $lang['b_start'] . '" class="btn bg-teal btn-sm btn-raised">
        <input type=hidden name=mod value=templates>
        <input type=hidden name=action value=templates>
        <input type=hidden name=subaction value=donew>
        <input type=hidden name=user_hash value="' . $dle_login_hash . '">
        </td></tr></table></form>';

		msg( "info", $lang['create_template'], $b_form );
	exit();
}

if( $subaction == "donew" ) {
	
	function open_dir($dir, $newdir) {
		if( file_exists( $dir ) && file_exists( $newdir ) ) {
			$open_dir = opendir( $dir );
			while ( false !== ($file = readdir( $open_dir )) ) {
				if( $file != "." && $file != ".." ) {
					if( @filetype( $dir . "/" . $file . "/" ) == "dir" ) {
						if( ! file_exists( $newdir . "/" . $file . "/" ) ) {
							mkdir( $newdir . "/" . $file . "/" );
							@chmod( $newdir . "/" . $file, 0777 );
							open_dir( $dir . "/" . $file . "/", $newdir . "/" . $file . "/" );
						}
					} else {
						copy( $dir . "/" . $file, $newdir . "/" . $file );
						@chmod( $newdir . "/" . $file, 0666 );
					}
				}
			}
		}
	}

	$base_template = trim( totranslit($_REQUEST['base_template'], false, false) );
	$template_name = trim( totranslit($_REQUEST['template_name'], false, false) );
	
	if( preg_match( "/[\||\'|\<|\>|\[|\]|\"|\!|\?|\$|\@|\/|\\\|\&\~\*\+]/", $template_name ) ) {
		msg( "error", $lang['opt_error'], $lang['opt_error_1'], "?mod=templates&subaction=new&user_hash={$dle_login_hash}" );
	}
	
	$result = @mkdir( ROOT_DIR . "/templates/" . $template_name, 0777 );
	@chmod( ROOT_DIR . "/templates/" . $template_name, 0777 );
	
	if( ! $result ) msg( "error", $lang['opt_error'], $lang['opt_cr_err'], "?mod=templates&subaction=new&user_hash={$dle_login_hash}" );
	else open_dir( ROOT_DIR . "/templates/" . $base_template, ROOT_DIR . "/templates/" . $template_name );

	$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '67', '{$template_name}')" );
	
	msg( "success", $lang['opt_info'], $lang['opt_info_1'], "?mod=templates&user_hash={$dle_login_hash}" );
}

if( $subaction == "delete" ) {

	if( strtolower( $do_template ) == strtolower($config['skin']) OR strtolower( $do_template ) == "smartphone" OR strtolower( $do_template ) == '' ) {
		msg( "Error", $lang['opt_error'], $lang['opt_error_4'], "?mod=templates&user_hash={$dle_login_hash}" );
	}
	
	$msg = "<form method=\"post\">$lang[opt_info_2] <b>$do_template</b>?<br><br>
        <input class=\"btn bg-teal btn-sm btn-raised position-left\" type=submit value=\" $lang[opt_yes] \"><input class=\"btn bg-danger btn-sm btn-raised\" onClick=\"document.location='?mod=templates';\" type=button value=\"$lang[opt_no]\">
        <input type=hidden name=mod value=templates>
        <input type=hidden name=subaction value=dodelete>
        <input type=hidden name=do_template value=\"$do_template\">
        <input type=hidden name=user_hash value=\"$dle_login_hash\">
        </form>";
	
	msg( "info", $lang['opt_info_3'], $msg );
}

if( $subaction == "dodelete" ) {
	if( strtolower( $do_template ) == strtolower($config['skin']) OR strtolower( $do_template ) == "smartphone" ) {
		msg( "Error", $lang['opt_error'], $lang['opt_error_4'], "?mod=templates&user_hash={$dle_login_hash}" );
	}
	if(!$do_template OR preg_match( "/[\||\'|\<|\>|\[|\]|\"|\!|\?|\$|\@|\/|\\\|\&\~\*\+]/", $do_template ) ) {
		msg( "error", $lang['opt_error'], $lang['opt_error_1'], "?mod=templates&user_hash={$dle_login_hash}" );
	}

	$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '68', '{$do_template}')" );
	
	listdir( ROOT_DIR . "/templates/" . $do_template );
	
	msg( "success", $lang['opt_info_3'], $lang['opt_info_4'], "?mod=templates&user_hash={$dle_login_hash}" );
}

$show_delete_link = '';

$do_template = trim( totranslit($do_template, false, false) );

if( !$do_template ) {
	$do_template = $config['skin'];
} elseif( $do_template != $config['skin'] AND $do_template != "smartphone" ) {
	$show_delete_link = "<a class=\"btn bg-danger btn-sm btn-raised\" href=\"?mod=templates&subaction=delete&user_hash={$dle_login_hash}&do_template=$do_template\">$lang[opt_dellink]</a>";
}

if (!@is_dir ( ROOT_DIR . '/templates/' . $do_template )) {
	die ( "Template not found!" );
}

if(!is_writable(ROOT_DIR . '/templates/' . $do_template . "/")) {

	$lang['stat_template'] = str_replace ("{template}", '/templates/'.$do_template.'/', $lang['stat_template']);

	$fail = "<div class=\"alert alert-warning alert-styled-left alert-arrow-left alert-component\">{$lang['stat_template']}</div>";

} else $fail = "";

DLEFiles::init(0, false, 'templates');
$files = DLEFiles::ListDirectory($do_template, [], 0, true);

$folders = [];
$folders[] = "<option value='{$do_template}'>{$do_template}</option>";
foreach ($files['dirs'] as $folder) {
	$folders[] = "<option value='{$folder['path']}'>{$folder['path']}</option>";
}
$folders = implode('', $folders);

$js_array[] = "public/js/sortable.js";
$js_array[] = "public/editor/ace/code_editor.js";
$js_array[] = "public/editor/ace/langs/{$lang['language_code']}.js";

echoheader( "<i class=\"fa fa-desktop position-left\"></i><span class=\"text-semibold\">{$lang['header_tm_1']}</span>", $lang['header_tm_2'] );

echo <<<HTML
<div class="panel panel-default">
  <div class="panel-heading">
    {$lang['opt_edit_head']}
  </div>
  <div class="panel-body">
		<form method="post" action="?mod=templates" class="form-horizontal" autocomplete="off">	
		 <div class="form-group">
		  <label class="control-label col-sm-2">{$lang['opt_theads']}</label>
		  <div class="col-sm-10">
			<b>{$do_template}</b>
		  </div>
		</div>
		
		 <div class="form-group mb-20">
		  <label class="control-label col-sm-2">{$lang['opt_sys_al']}</label>
		  <div class="col-sm-10">
			<select class="uniform" name="do_language">
		<option value="">{$lang['sys_global']}</option>
HTML;

foreach ( $language_list as $key => $value ) {

	if (isset($value['icon']) and $value['icon']) {
		$flags = " data-content=\"<span class='select-icon'><img src='public/flags/{$value['icon']}'></span><span class='select-descr'>{$value['name']}</span>\" ";
	} else $flags = "";
	
	if( $key == $config["lang_" . $do_template] ) {
		echo "<option selected value=\"{$key}\"{$flags}>{$value['name']}</option>";
	} else {
		echo "<option value=\"{$key}\"{$flags}>{$value['name']}</option>";
	}
	
}

echo <<<HTML
		</select><input type="submit" value="{$lang['b_select']}" class="btn bg-slate-600 btn-sm btn-raised position-right"><input type="hidden" name=user_hash value="$dle_login_hash"><input type="hidden" name="subaction" value="language"><input type="hidden" name="do_template" value="{$do_template}">
		  </div>
		</div>
		</form>
		<form method="post" action="?mod=templates" class="form-horizontal" autocomplete="off">	
		 <div class="form-group mb-20">
		  <label class="control-label col-sm-2">{$lang['opt_newtepled']}</label>
		  <div class="col-sm-10"><form method="post" action="?mod=templates" class="form-horizontal" autocomplete="off"><select class="uniform" name="do_template">
HTML;

foreach ( $templates_list as $key => $value ) {
	if( $key == $do_template ) {
		echo "<option selected value=\"{$key}\">{$value['name']}</option>";
	} else {
		echo "<option value=\"{$key}\">{$value['name']}</option>";
	}
}

echo <<<HTML
</select><input type="submit" value="{$lang['b_start']}" class="btn bg-slate-600 btn-sm btn-raised position-right">&nbsp;&nbsp;<a onclick="javascript:Help('templates')" class="status-info" href="#">{$lang['opt_temphelp']}</a><input type=hidden name=user_hash value="$dle_login_hash"><input type="hidden" name="action" value="templates">
		  </div>
		</div>
		</form>
			 <div class="form-group">
			  <label class="control-label col-sm-2"></label>
			  <div class="col-sm-10">
				<a class="btn bg-teal btn-sm btn-raised position-left" href="?mod=templates&subaction=new&action=templates&user_hash={$dle_login_hash}">{$lang['opt_enewtepl']}</a>
				{$show_delete_link}
			  </div>
			</div>

   </div>
</div>

<div class="panel panel-default">
  <div class="panel-heading">
    {$lang['opt_edteil']} <b>{$do_template}</b>
    <div class="heading-elements">
	    <ul class="icons-list">
			<li><a href="#" class="panel-fullscreen"><i class="fa fa-expand"></i></a></li>
		</ul>
    </div>
  </div>
  <div class="panel-body row-seamless">
	 <div class="col-md-12 mb-10">{$lang['templates_help']} <a class="main" href="https://dle-news.ru/extras/online/" target="_blank">https://dle-news.ru/extras/online/</a></div>
	
	  <div class="template-ed-container">
		  <div id="filetree_block" class="template-ed-tree">
			<div id="filetree" class="filetree"></div>
		  </div>
		  <div class="template-ed-editor">
				<div id="fileedit" style="flex:1;display:flex;flex-direction:column;"></div>
		  </div>
	  </div>
	
   </div>
<div class="panel-footer">
	<button class="btn bg-teal btn-sm btn-raised" type="button" onclick="createfile('folder')"><i class="fa fa-plus-circle position-left"></i>{$lang['btn_folder']}</button>
	<button class="btn bg-teal btn-sm btn-raised" type="button" onclick="createfile('file')"><i class="fa fa-plus-circle position-left"></i>{$lang['template_create']}</button>
</div>
</div>
<script>
var editor = null;
var EditorTabs = {
	tabs: [],
	activeFile: null,
	sortable: null,
	templateName: '{$do_template}',
	storageKey: 'dle-editor-tabs-{$do_template}',

	getAceMode: function(type) {
		return type === 'css' ? 'ace/mode/css' : (type === 'js' ? 'ace/mode/javascript' : 'ace/mode/html');
	},

	findTab: function(file) {
		for (var i = 0; i < this.tabs.length; i++) {
			if (this.tabs[i].file === file) return this.tabs[i];
		}
		return null;
	},

	addTab: function(file, content, type, writable) {
		var tab = this.findTab(file);
		if (tab) {
			this.activateTab(file);
			return;
		}

		var mode = this.getAceMode(type);
		var session = ace.createEditSession(content, mode);
		if (type === 'tpl' || type === 'json') session.setOption('useWorker', false);

		var use_wrap = false;
		var prefix = file.split('/').pop().replace(/\.[^/.]+$/, '');
		try {
			var savedWrap = localStorage.getItem('ace-usewordwrap' + prefix);
			if (savedWrap !== null) use_wrap = JSON.parse(savedWrap) === true;
		} catch (e) { }

		if (use_wrap) {
			session.setOption('wrap', true);
			session.setOption('wrapMethod', 'text');
		}

		tab = {
			file: file,
			type: type,
			writable: writable,
			session: session,
			originalContent: content,
			modified: false
		};

		var self = this;
		session.on('change', function() {
			var isModified = session.getValue() !== tab.originalContent;
			if (tab.modified !== isModified) {
				tab.modified = isModified;
				self.renderTabs();
			}
		});

		this.tabs.push(tab);
		this.activateTab(file);
		this.saveTabs();
	},

	activateTab: function(file) {
		var tab = this.findTab(file);
		if (!tab) return;

		this.activeFile = file;
		this.initEditor();
		editor.setSession(tab.session);
		editor.setReadOnly(!tab.writable);
		this.renderTabs();
		this.renderToolbar();
		this.saveTabs();
		editor.focus();
	},

	initEditor: function() {
		if (editor) return;

		var editDiv = document.getElementById('fileedit');
		editDiv.innerHTML = '<div id="editortabs"></div>' +
			'<div style="border:solid 0.0625em #ddd;width:100%;height:31.11em;"><textarea style="display:none;" id="ace_init_area"></textarea></div>' +
			'<div class="editor-toolbar">' +
			'<div class="checkbox"><label><input class="icheck" type="checkbox" id="ace-usewordwrap" value="1">{$lang['ed_word_wrap']}</label></div>' +
			'<button type="button" class="btn bg-teal btn-sm btn-raised position-left" id="btn-save"><i class="fa fa-floppy-o position-left"></i>{$lang['user_save']}</button>' +
			'<button type="button" class="btn bg-teal btn-sm btn-raised position-left" id="btn-save-all"><i class="fa fa-floppy-o position-left"></i>{$lang['user_save_all']}</button>' +
			'<button type="button" class="btn bg-slate-600 btn-sm btn-raised position-left" id="btn-move"><i class="fa fa-exchange position-left"></i>{$lang['template_move_3']}</button>' +
			'<button type="button" class="btn bg-danger btn-sm btn-raised pull-right position-right" id="btn-delete"><i class="fa fa-trash-o position-left"></i>{$lang['edit_seldel']}</button>' +
			'</div>';

		editor = code_editor('#ace_init_area', {height: '100%', beautify: false});

		editor.commands.addCommand({
			name: 'save',
			bindKey: {win: 'Ctrl-S', mac: 'Cmd-S'},
			exec: function() { savefile(EditorTabs.activeFile); }
		});

		var self = this;

		$('#btn-save').on('click', function() { savefile(self.activeFile); });
		$('#btn-save-all').on('click', function() { self.saveAllModified(); });
		$('#btn-move').on('click', function() { MoveFile(self.activeFile); });
		$('#btn-delete').on('click', function() { DeleteFile(self.activeFile); });

		$('#ace-usewordwrap').on('change', function() {
			var tab = self.findTab(self.activeFile);
			if (!tab) return;
			var value = this.checked;
			tab.session.setOption('wrap', value);
			if (value) tab.session.setOption('wrapMethod', 'text');
			var prefix = self.activeFile.split('/').pop().replace(/\.[^/.]+$/, '');
			try { localStorage.setItem('ace-usewordwrap' + prefix, value); } catch (e) { }
		});
	},

	closeTab: function(file, force) {
		var tab = this.findTab(file);
		if (!tab) return;

		if (!force && tab.modified) {
			var self = this;
			DLEconfirm('{$lang['c_close_tab']}', '{$lang['p_confirm']}', function() {
				self._removeTab(file);
			});
			return;
		}

		this._removeTab(file);
	},

	_removeTab: function(file) {
		var idx = -1;
		for (var i = 0; i < this.tabs.length; i++) {
			if (this.tabs[i].file === file) { idx = i; break; }
		}
		if (idx === -1) return;

		this.tabs[idx].session.destroy();
		this.tabs.splice(idx, 1);

		if (this.activeFile === file) {
			if (this.tabs.length > 0) {
				var newIdx = idx > 0 ? idx - 1 : 0;
				this.activateTab(this.tabs[newIdx].file);
			} else {
				this.activeFile = null;
				if (editor) {
					editor.destroy();
					editor = null;
				}
				$('#fileedit').empty();
				this.renderTabs();
			}
		} else {
			this.renderTabs();
		}

		this.saveTabs();
	},

	updateTabFile: function(oldFile, newFile) {
		var tab = this.findTab(oldFile);
		if (!tab) return;
		tab.file = newFile;
		if (this.activeFile === oldFile) this.activeFile = newFile;
		this.renderTabs();
		this.renderToolbar();
		this.saveTabs();
	},

	markSaved: function(file) {
		var tab = this.findTab(file);
		if (!tab) return;
		tab.originalContent = tab.session.getValue();
		tab.modified = false;
		this.renderTabs();
	},

	saveAllModified: function() {
		for (var i = 0; i < this.tabs.length; i++) {
			if (this.tabs[i].modified) {
				savefile(this.tabs[i].file);
			}
		}
	},

	renderTabs: function() {
		var container = document.getElementById('editortabs');
		if (!container) return;
		container.innerHTML = '';

		if (!this.tabs.length) {
			container.className = '';
			return;
		}

		container.className = 'editor-tabs';
		var self = this;

		for (var i = 0; i < this.tabs.length; i++) {
			(function(tab) {
				var el = document.createElement('div');
				el.className = 'editor-tab' + (tab.file === self.activeFile ? ' active' : '') + (tab.modified ? ' modified' : '');

				var name = tab.file;
				var parts = name.split('/');
				var displayName = parts.length > 1 ? parts.slice(1).join('/') : parts[0];

				var nameSpan = document.createElement('span');
				nameSpan.className = 'tab-name';
				nameSpan.textContent = displayName;
				nameSpan.title = tab.file;
				el.appendChild(nameSpan);

				var modSpan = document.createElement('span');
				modSpan.className = 'tab-modified';
				modSpan.textContent = '\u25CF';
				el.appendChild(modSpan);

				var closeSpan = document.createElement('span');
				closeSpan.className = 'tab-close';
				closeSpan.textContent = '\u00D7';
				closeSpan.addEventListener('click', function(e) {
					e.stopPropagation();
					self.closeTab(tab.file);
				});
				el.appendChild(closeSpan);

				el.addEventListener('click', function() {
					self.activateTab(tab.file);
				});

				container.appendChild(el);
			})(this.tabs[i]);
		}

		if (this.tabs.length > 1 && typeof Sortable !== 'undefined') {
			if (this.sortable) {
				this.sortable.destroy();
			}

			this.sortable = Sortable.create(container, {
				animation: 150,
				ghostClass: 'drop-bg',
				onChoose(evt) {
        			evt.item.classList.add('is-sorting');
    			},
				onEnd(evt) {
					evt.item.classList.remove('is-sorting');
				},							
				onSort: function () {
					var newTabs = [];
					container.querySelectorAll('.editor-tab').forEach(function (el) {
						var file = el.querySelector('.tab-name').getAttribute('title');
						var tab = self.findTab(file);
						if (tab) newTabs.push(tab);
					});
					self.tabs = newTabs;
					self.saveTabs();
				}
			});
		}
	},

	renderToolbar: function() {
		var tab = this.findTab(this.activeFile);
		if (!tab) return;

		var wrapToggle = document.getElementById('ace-usewordwrap');
		if (wrapToggle) {
			wrapToggle.checked = tab.session.getUseWrapMode();
		}
	},

	saveTabs: function() {
		try {
			var files = [];
			for (var i = 0; i < this.tabs.length; i++) files.push(this.tabs[i].file);
			localStorage.setItem(this.storageKey, JSON.stringify({files: files, active: this.activeFile}));
		} catch (e) { }
	},

	restoreTabs: function() {
		var data = null;
		try {
			data = JSON.parse(localStorage.getItem(this.storageKey));
		} catch (e) { return; }

		if (!data || !data.files || !data.files.length) return;

		var self = this;
		var files = data.files.slice();
		var activeFile = data.active;
		var idx = 0;

		function loadNext() {
			if (idx >= files.length) {
				if (activeFile && self.findTab(activeFile)) {
					self.activateTab(activeFile);
				}
				return;
			}

			var file = files[idx];
			idx++;

			if (self.findTab(file)) {
				loadNext();
				return;
			}

			$.post('index.php?controller=ajax&mod=templates', { action: 'load', file: file, user_hash: '{$dle_login_hash}' }, function(resp) {
				if (resp && !resp.error) {
					var session = ace.createEditSession(resp.content, self.getAceMode(resp.type));
					if (resp.type === 'tpl' || resp.type === 'json') session.setOption('useWorker', false);

					var use_wrap = false;
					var prefix = file.split('/').pop().replace(/\.[^/.]+$/, '');
					try {
						var sw = localStorage.getItem('ace-usewordwrap' + prefix);
						if (sw !== null) use_wrap = JSON.parse(sw) === true;
					} catch (e) { }
					if (use_wrap) {
						session.setOption('wrap', true);
						session.setOption('wrapMethod', 'text');
					}

					var tab = {
						file: resp.file,
						type: resp.type,
						writable: resp.writable,
						session: session,
						originalContent: resp.content,
						modified: false
					};

					session.on('change', (function(t) {
						return function() {
							var isModified = t.session.getValue() !== t.originalContent;
							if (t.modified !== isModified) {
								t.modified = isModified;
								self.renderTabs();
							}
						};
					})(tab));

					self.tabs.push(tab);
				} else {}

				loadNext();
			}, 'json').fail(function() { loadNext(); });
		}

		loadNext();
	}
};

jQuery(function($){
	FileTree();
	EditorTabs.restoreTabs();
});

function FileTree(){
	$('#filetree').remove();
	$('#filetree_block').append('<div id="filetree" class="filetree"></div>');

	$('#filetree').fileTree({ root: '{$do_template}/', script: 'index.php?controller=ajax&mod=templates&user_hash={$dle_login_hash}', folderEvent: 'click', expandSpeed: 750, collapseSpeed: 750, multiFolder: false, preventLinkAction: true }, function(file) {

		Loadfile(file);

	});
}

function Loadfile( file ){
	var tab = EditorTabs.findTab(file);
	if (tab) {
		EditorTabs.activateTab(file);
		return false;
	}

	ShowLoading('');

	$.post('index.php?controller=ajax&mod=templates', { action: 'load', file: file, user_hash: '{$dle_login_hash}' }, function(resp) {

		HideLoading('');

		if (resp.error) {
			DLEPush.error(resp.error);
			return;
		}

		EditorTabs.addTab(resp.file, resp.content, resp.type, resp.writable);

	}, 'json').fail(function() {
		HideLoading('');
		DLEPush.error('Load error');
	});

	return false;
};

function savefile( file ){
	var tab = EditorTabs.findTab(file);
	if (!tab) return;

	var content = tab.session.getValue();

	$.post('index.php?controller=ajax&mod=templates', { action: 'save', file: file, content: content, user_hash: '{$dle_login_hash}' }, function(data){

		if ( data == 'ok' ) {
			DLEPush.info('{$lang['template_saved']}');
			EditorTabs.markSaved(file);
		} else {
			DLEPush.error(data);
		}

	});

};

function DeleteFile( file ){

	DLEconfirmDelete( '{$lang['confirm_action']}', '{$lang['p_confirm']}', function () {
		$.post('index.php?controller=ajax&mod=templates', { action: 'delete', file: file, user_hash: '{$dle_login_hash}' }, function(data){

			if ( data == 'ok' ) {
				EditorTabs.closeTab(file, true);
				FileTree();
			} else {
				DLEPush.error(data);
			}

		});
	});
};

function createfile( type ){

	if( type == 'folder') {
		var promt = '{$lang['folder_enter']}';
	} else {
		var promt = '{$lang['template_enter']}';
	}

	DLEprompt(promt, '', "{$lang['p_prompt']}", function (file) {

		ShowLoading('');

		$.post('index.php?controller=ajax&mod=templates', { action: 'create', file: file, type: type, template: '{$do_template}', user_hash: '{$dle_login_hash}' }, function(data){

			HideLoading('');

			if ( data == 'ok' ) {
				FileTree();
				if( type != 'folder' ) {
					Loadfile('{$do_template}/'+file);
				}
			} else {
				DLEPush.error(data);
			}

		});

	}, false, '{$lang['news_save']}');

};

function MoveFile( file ){
	var b = {};

	b[dle_act_lang[3]] = function() {
		$(this).dialog('close');
	};

	b['{$lang['template_move_3']}'] = function() {
		var movefolder = $('#movefolder').val();
		$.post('index.php?controller=ajax&mod=templates', { action: 'move', file: file, movefolder: movefolder, user_hash: '{$dle_login_hash}' }, function(data){
			if ( data == 'ok' ) {
				var newFile = movefolder + '/' + file.split('/').pop();
				EditorTabs.updateTabFile(file, newFile);
				FileTree();
			} else {
				DLEPush.error(data);
			}

		});
		$(this).dialog('close');
	};

	$('#dlemovepopup').remove();

	$('body').append("<div id='dlemovepopup' title='{$lang['template_move_1']}' style='display:none'>{$lang['template_move_2']} <select name='movefolder' id='movefolder' class='uniform'>{$folders}</select></div>");

	$('#dlemovepopup .uniform').selectpicker();

	var ww = 700 * getBaseSize();

	if(ww > ( $(window).width() * 0.95 ) )  { ww = $(window).width() * 0.95;  }

	$('#dlemovepopup').dialog({
		autoOpen: true,
		width: ww,
		resizable: false,
		buttons: b
	});

};

</script>
{$fail}
HTML;

echofooter();