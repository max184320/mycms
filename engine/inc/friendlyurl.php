<?php
/*
=====================================================
DataLife Engine - by SoftNews Media Group
-----------------------------------------------------
https://dle-news.ru/
-----------------------------------------------------
Copyright (c) 2004-2026 SoftNews Media Group
=====================================================
File: friendlyurl.php
-----------------------------------------------------
Use: Friendly SEO URLs
=====================================================
*/

if (!defined('DATALIFEENGINE') or !defined('LOGGED_IN')) {
    header("HTTP/1.1 403 Forbidden");
    header('Location: ../../');
    die("Hacking attempt!");
}

if ($member_id['user_group'] != 1) {
    msg("error", $lang['index_denied'], $lang['index_denied']);
}

$js_array[] = "public/js/sortable.js";

echoheader("<i class=\"fa fa-tags position-left\"></i><span class=\"text-semibold\">{$lang['opt_friendly']}</span>", $lang['opt_friendly']);
DLEUrl::Init();
$entries = '';

foreach (DLEUrl::$rules as $key => $value) {

    if( strpos($key, 'custom.') !== 0 ) {
        $restore_link = "<li><a data-id=\"{$key}\" class=\"restorelink\" href=\"?mod=friendlyurl\"><i class=\"fa fa-undo position-left\"></i>{$lang['friendly_fl_7']}</a></li>";
    } else $restore_link = "";

        $menu_link = <<<HTML
        <div class="btn-group">
          <a href="#" class="dropdown-toggle nocolor" data-toggle="dropdown" aria-expanded="true"><i class="fa fa-bars"></i><span class="caret"></span></a>
          <ul class="dropdown-menu text-left dropdown-menu-right">
            <li><a data-id="{$key}" class="editlink" href="?mod=friendlyurl"><i class="fa fa-pencil-square-o position-left"></i>{$lang['word_ledit']}</a></li>
            {$restore_link}
			<li class="divider"></li>
            <li><a data-id="{$key}" class="dellink" href="?mod=friendlyurl"><i class="fa fa-trash-o position-left text-danger"></i>{$lang['word_ldel']}</a></li>
          </ul>
        </div>
HTML;
        $value[0] = htmlspecialchars($value[0], ENT_QUOTES, $config['charset']);
        $value[1] = htmlspecialchars($value[1], ENT_QUOTES, $config['charset']);

        $entries .= "<tr class=\"dd-item dd-item-table drag-bg\" data-id=\"{$key}\">
        <td class=\"dd-handles\"></td>
        <td><div data-name=\"{$key}\">{$value[0]}</div></td>
        <td><div data-realurl=\"{$key}\">{$value[1]}</div></td>
        <td>{$menu_link}</td>
        </tr>";
}

echo <<<HTML
<div class="alert alert-warning alert-styled-left alert-arrow-left alert-component text-left">{$lang['friendly_fl_8']}</div>
<div class="panel panel-default">
    <div class="panel-heading">
    {$lang['friendly_fl_1']}
    </div>
    <div class="dd table-responsive">
        <table class="table table-xs table-hover" style="table-layout:fixed;">
        <thead>
        <tr>
            <th style="width: 3.33em">&nbsp;</th>
            <th class="text-left">{$lang['friendly_fl_2']}</th>
            <th class="text-left">{$lang['friendly_fl_3']}</th>
            <th style="width: 3.88em">&nbsp;</th>
        </tr>
        </thead>
        <tbody class="dd-list dd-table text-size-small" id="nestable">
            {$entries}
        </tbody>
        </table>
    </div>
    <div class="panel-footer">
        <button type="button" onclick="AddRules(); return false;" class="btn bg-teal btn-sm btn-raised position-left"><i class="fa fa-plus position-left"></i>{$lang['friendly_fl_4']}</button>
        <button type="button" onclick="checkRules(); return false;" class="btn bg-slate-600 btn-sm btn-raised"><i class="fa fa-desktop position-left"></i>{$lang['friendly_fl_5']}</button>
        <button type="button" onclick="restoreDefaultsRules(); return false;" class="btn bg-danger btn-sm btn-raised"><i class="fa fa-undo position-left"></i>{$lang['friendly_fl_6']}</button>
    </div>
</div>
<script>  
<!--

function restoreDefaultsRules(){

    DLEconfirm( '{$lang['friendly_fl_10']}', '{$lang['p_confirm']}', function () {

        ShowLoading('');
        $.get('index.php?controller=ajax&mod=adminfunction',  'action=friendlyurlreset&user_hash={$dle_login_hash}', function(data){
            HideLoading('');

            if (data.error) {
                DLEPush.error(data.error);
            } else {
                location.reload(true);
            }

        }, 'json');

    } );
}

function checkRules(){

    ShowLoading('');
    $.get('index.php?controller=ajax&mod=adminfunction',  'action=friendlyurlcheck&user_hash={$dle_login_hash}', function(data){
        HideLoading('');

        if (data.error) {
            DLEPush.error('{$lang['friendly_fl_9']} ' + data.error);
        } else {
            DLEPush.info('{$lang['friendly_fl_11']}');
        }

    }, 'json');
}
function AddRow(seo_key, seo_url, real_url) {

    const lastRow = $('.dd-table tr:last');
    
    if (lastRow.length) {
        const clonedRow = lastRow.clone();
        
        clonedRow.attr('data-id', seo_key);
        clonedRow.find('[data-id]').attr('data-id', seo_key);
        clonedRow.find('[data-name]').attr('data-name', seo_key);
        clonedRow.find('[data-realurl]').attr('data-realurl', seo_key);
        clonedRow.find('[data-name]').text(seo_url);
        clonedRow.find('[data-realurl]').text(real_url);
        clonedRow.find('.restorelink').remove();

        lastRow.after(clonedRow);
    }
}

function AddRules(){

    var b = {};

    b[dle_act_lang[3]] = function() { 
                    $(this).dialog("close");						
                };

    b['{$lang['news_save']}'] = function() { 
        if ( $("#seo_key").val().length < 1) {
                $("#seo_key").addClass('ui-state-error');
        } else if ($("#seo_url").val().length < 1 || $("#seo_url").val() == '/') {
                $("#seo_key").removeClass('ui-state-error');
                $("#seo_url").addClass('ui-state-error');
        } else if ($("#real_url").val().length < 1 || $("#real_url").val() == '/' ) {
                $("#seo_key").removeClass('ui-state-error');
                $("#seo_url").removeClass('ui-state-error');
                $("#real_url").addClass('ui-state-error');                            
        } else {

            var seo_key = $("#seo_key").val();
            var seo_url = $("#seo_url").val();
            var real_url = $("#real_url").val();

            ShowLoading('');
            $.post('index.php?controller=ajax&mod=adminfunction&user_hash={$dle_login_hash}', { action: 'friendlyurladd', key: seo_key, seo_url: seo_url, real_url: real_url }, function(data){
                
                HideLoading('');

                if (data.ok) {
                    DLEPush.info('{$lang['friendly_fl_24']}');
                    AddRow(data.seo_key, data.seo_url, data.real_url);
                } else if(data.warning) {
                    DLEPush.warning('{$lang['friendly_fl_25']}');
                    AddRow(data.seo_key, data.seo_url, data.real_url);
                } else if(data.error) {
                    DLEPush.error(data.error);
                }

            }, 'json');	
        
            $(this).dialog("close");
            $("#dlepopup").remove();

        }				
    };

    $("#dlepopup").remove();

    $("body").append("<div id='dlepopup' title='{$lang['friendly_fl_22']}' style='display:none'>{$lang['friendly_fl_23']}<br><input type='text' dir='auto' name='seo_key' id='seo_key' class='form-control' autocomplete='off' style='width:100%;' value=''><br><br>{$lang['friendly_fl_17']}<br><input type='text' dir='auto' name='seo_url' id='seo_url' class='form-control' autocomplete='off' style='width:100%;' value=''><br><br>{$lang['friendly_fl_18']}<br><input type='text' dir='auto' name='real_url' id='real_url' class='form-control' autocomplete='off' style='width:100%;' value=''></div>");
    

    var ww = 700 * getBaseSize();

    if(ww > ( $(window).width() * 0.95 ) )  { ww = $(window).width() * 0.95;  }

    $('#dlepopup').dialog({
        autoOpen: true,
        width: ww,
        resizable: false,
        buttons: b
    });

    return false;

}

$(function(){

    if( document.getElementById('nestable') ) {
		var nestable = new Sortable(document.getElementById('nestable'), {
			animation: 150,
			ghostClass: 'drop-bg',
			handle: '.dd-handles',
			onChoose(evt) {
        		evt.item.classList.add('is-sorting');
    		},
    		onEnd(evt) {
        		evt.item.classList.remove('is-sorting');
    		},            
			onSort: function (evt) {

                var url = "action=friendlyurlsort&user_hash={$dle_login_hash}&list="+window.JSON.stringify(nestable.toArray());
                
                ShowLoading('');
                $.post('index.php?controller=ajax&mod=adminfunction', url, function(data){
                    HideLoading('');

                    if (data.warning) {
                        DLEPush.warning(data.warning);
                    } else if(data.error) {
                        DLEPush.error('{$lang['friendly_fl_9']} ' + data.error);
                    }

                }, 'json');

			}

		});
	}

    $(document).on('click', '.dellink', function(){
        
        var rule = $(this).data('id');
        var url = $('[data-name="' + rule + '"]').text();
        
        DLEconfirmDelete( '{$lang['friendly_fl_12']} &laquo;'+url+'&raquo; {$lang['friendly_fl_13']}', '{$lang['p_confirm']}', function () {
            
            ShowLoading('');
            $.post('index.php?controller=ajax&mod=adminfunction', 'action=friendlyurldel&user_hash={$dle_login_hash}&key='+rule, function(data){
                HideLoading('');

                if (data.error) {
                    DLEPush.error(data.error);
                } else {
                    $('[data-id="' + rule + '"]').remove();
                }

            }, 'json');

        } );

        return false;
    });

    $(document).on('click', '.restorelink', function(){
        
        var rule = $(this).data('id');
        var url = $('[data-name="' + rule + '"]').text();
        
        DLEconfirm( '{$lang['friendly_fl_14']} &laquo;'+url+'&raquo; {$lang['friendly_fl_15']}', '{$lang['p_confirm']}', function () {
            
            ShowLoading('');
            $.post('index.php?controller=ajax&mod=adminfunction', 'action=friendlyurlrestore&user_hash={$dle_login_hash}&key='+rule, function(data){
                HideLoading('');

                if (data.error) {
                    DLEPush.error(data.error);
                } else {
                    $('[data-name="' + rule + '"]').text(data.seo_url);
                    $('[data-realurl="' + rule + '"]').text(data.real_url);
                }

            }, 'json');

        } );

        return false;
    });

    $(document).on('click', '.editlink', function(){

        var rule = $(this).data('id');
        var seo_url = $('[data-name="' + rule + '"]').text();
        var real_url = $('[data-realurl="' + rule + '"]').text();

        var b = {};
    
        b[dle_act_lang[3]] = function() { 
                        $(this).dialog("close");						
                    };
    
        b['{$lang['news_save']}'] = function() { 
                        if ( $("#seo_url").val().length < 1 || $("#seo_url").val() == '/') {
                                $("#seo_url").addClass('ui-state-error');
                        } else if ( $("#real_url").val().length < 1 || $("#real_url").val() == '/') {
                                $("#seo_url").removeClass('ui-state-error');
                                $("#real_url").addClass('ui-state-error');
                        } else {

                            var seo_url = $("#seo_url").val();
                            var real_url = $("#real_url").val();

                            ShowLoading('');
                            $.post('index.php?controller=ajax&mod=adminfunction&user_hash={$dle_login_hash}', { action: 'friendlyurledit', key: rule, seo_url: seo_url, real_url: real_url }, function(data){
                                
                                HideLoading('');

                                if (data.ok) {

                                    DLEPush.info('{$lang['friendly_fl_19']}');
                                    
                                    $('[data-name="' + rule + '"]').text(data.seo_url);
                                    $('[data-realurl="' + rule + '"]').text(data.real_url);

                                } else if(data.warning) {
                                    DLEPush.warning('{$lang['friendly_fl_20']}');
                                    $('[data-name="' + rule + '"]').text(data.seo_url);
                                    $('[data-realurl="' + rule + '"]').text(data.real_url);
                                } else if(data.error) {
                                    DLEPush.error(data.error);
                                }

                            }, 'json');	
                        
                            $(this).dialog("close");
                            $("#dlepopup").remove();

                        }				
                    };

        $("#dlepopup").remove();

        $("body").append("<div id='dlepopup' title='{$lang['friendly_fl_16']}' style='display:none'>{$lang['friendly_fl_17']}<br><input type='text' dir='auto' name='seo_url' id='seo_url' class='form-control' autocomplete='off' style='width:100%;' value=\""+seo_url+"\"/><br><br>{$lang['friendly_fl_18']}<br><input type='text' dir='auto' name='real_url' id='real_url' class='form-control' autocomplete='off' style='width:100%;' value='"+real_url+"'></div>");
        
        var ww = 700 * getBaseSize();

        if(ww > ( $(window).width() * 0.95 ) )  { ww = $(window).width() * 0.95;  }

        $('#dlepopup').dialog({
            autoOpen: true,
            width: ww,
            resizable: false,
            buttons: b
        });

        return false;
    });

});
//-->
</script>
HTML;

echofooter();