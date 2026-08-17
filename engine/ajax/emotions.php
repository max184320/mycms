<?php
/*
=====================================================
 DataLife Engine - by SoftNews Media Group 
-----------------------------------------------------
 http://dle-news.ru/
-----------------------------------------------------
 Copyright (c) 2004-2026 SoftNews Media Group
=====================================================
 This code is protected by copyright
=====================================================
 File: emotions.php
-----------------------------------------------------
 Use: WYSIWYG for emotions
=====================================================
*/
if (!defined('DATALIFEENGINE')) {
	header("HTTP/1.1 403 Forbidden");
	header('Location: ../../');
	die("Hacking attempt!");
}

$get_lang = isset($_GET['lang']) ? totranslit($_GET['lang']) : 'en';

if( $config['emoji'] ) {

	$emoji_language_code = strtolower((string) ($get_lang ?? 'en'));

	$emoji_locale = $emoji_language_code;
	if (!$emoji_locale || !file_exists(ROOT_DIR . '/public/emoji/i18n/' . $emoji_locale . '.json')) {
		$emoji_locale = 'en';
	}

	$emoji_search_locale = $emoji_language_code;
	if (!$emoji_search_locale || !file_exists(ROOT_DIR . '/public/emoji/search/' . $emoji_search_locale . '.json')) {
		$emoji_search_locale = 'en';
	}

	$emoji_script = <<<HTML
var emojiPickerScriptUrl = "{$_ROOT_DLE_URL}public/emoji/emoji.js";
var emojiPickerDataUrl = "{$_ROOT_DLE_URL}public/emoji/data/data.json";
var emojiPickerLocaleUrl = "{$_ROOT_DLE_URL}public/emoji/i18n/{$emoji_locale}.json";
var emojiPickerSearchUrl = "{$_ROOT_DLE_URL}public/emoji/search/{$emoji_search_locale}.json";
var emojiPickerLocale = "{$emoji_locale}";
var emojiPickerScriptPromise = null;

function loadEmojiPickerScript() {
	if (window.EmojiMart && window.EmojiMart.Picker) {
		return Promise.resolve();
	}

	if (emojiPickerScriptPromise) {
		return emojiPickerScriptPromise;
	}

	emojiPickerScriptPromise = new Promise(function(resolve, reject) {
		var script = document.createElement('script');

		script.src = emojiPickerScriptUrl;
		script.async = true;
		script.onload = function() {
			resolve();
		};
		script.onerror = function() {
			emojiPickerScriptPromise = null;
			reject(new Error('Unable to load emoji picker script'));
		};

		document.head.appendChild(script);
	});

	return emojiPickerScriptPromise;
}

function loadEmojiPickerJson(url, fallbackValue) {
	return fetch(url, {
		credentials: 'same-origin'
	}).then(function(response) {
		if (!response.ok) {
			throw new Error('Unable to load emoji json');
		}

		return response.json();
	}).catch(function() {
		return fallbackValue;
	});
}

function normalizeEmojiSearchValue(value) {
	return String(value || '').trim();
}

function uniqueEmojiSearchValues(values) {
	var result = [];
	var used = {};

	for (var i = 0; i < values.length; i++) {
		var value = normalizeEmojiSearchValue(values[i]);

		if (!value || used[value]) {
			continue;
		}

		used[value] = true;
		result.push(value);
	}

	return result;
}

function mergeEmojiSearchIndex(emojiData, emojiSearchIndex) {
	if (!emojiData || !emojiData.emojis || !emojiSearchIndex) {
		return emojiData;
	}

	var emojiUnifiedMap = {};
	var emojiKeys = Object.keys(emojiData.emojis);

	for (var i = 0; i < emojiKeys.length; i++) {
		var emoji = emojiData.emojis[emojiKeys[i]];
		var skin = emoji && emoji.skins && emoji.skins.length ? emoji.skins[0] : null;
		var unified = skin && skin.unified ? String(skin.unified).toLowerCase() : '';

		if (unified) {
			emojiUnifiedMap[unified] = emoji;
		}
	}

	for (var unified in emojiSearchIndex) {
		if (!Object.prototype.hasOwnProperty.call(emojiSearchIndex, unified)) {
			continue;
		}

		var searchItem = emojiSearchIndex[unified];
		var emoji = emojiUnifiedMap[String(unified || '').toLowerCase()];
		var searchKeywords = searchItem && Array.isArray(searchItem.keywords) ? searchItem.keywords : [];
		var mergedKeywords = [];
		var translatedName = normalizeEmojiSearchValue(searchItem && searchItem.name);

		if (!emoji) {
			continue;
		}

		if (translatedName) {
			emoji.name = translatedName;
		}

		if (Array.isArray(emoji.keywords)) {
			mergedKeywords = mergedKeywords.concat(emoji.keywords);
		}

		mergedKeywords = mergedKeywords.concat(searchKeywords);

		if (translatedName) {
			mergedKeywords.push(translatedName);
		}

		emoji.keywords = uniqueEmojiSearchValues(mergedKeywords);
	}

	return emojiData;
}

function insert_editor_emoji(emoji) {
	var skin = emoji && emoji.skins && emoji.skins.length ? emoji.skins[0] : null;
	var nativeEmoji = emoji && emoji.native ? emoji.native : (skin && skin.native ? skin.native : '');

	if (!nativeEmoji) {
		return;
	}

	parent.tinyMCE.execCommand('mceInsertContent', false, '<span class="native-emoji noncontenteditable">' + nativeEmoji + '</span>');
	parent.tinyMCE.activeEditor.windowManager.close();
}

function initEmojiPicker() {
	var parentDoc = window.parent.document;
	var root = parentDoc.documentElement;
	var fontSize = window.parent.getComputedStyle(root).getPropertyValue('--font-size-base').trim();
	var pickerContainer = document.getElementById('dle-emoji-picker');
	var isDarkTheme = parentDoc.body.classList.contains('dle_theme_dark');

	if (fontSize) {
		fontSize = window.parent.getComputedStyle(parentDoc.body).fontSize;
	} else {
		fontSize = '0.9rem';
	}

	document.body.style.fontSize = fontSize;

	if (isDarkTheme) {
		document.body.classList.add('dle_theme_dark');
	}

	var parenthtmlElement = parent.document.getElementsByTagName('html')[0];

	if (parenthtmlElement.className) {
		var htmlElement = document.querySelector('html');
		var htmlclasses = htmlElement.classList;

		htmlclasses.add(parenthtmlElement.className);
	}

	if (!pickerContainer) {
		return;
	}

	Promise.all([
		loadEmojiPickerScript(),
		loadEmojiPickerJson(emojiPickerDataUrl, null),
		loadEmojiPickerJson(emojiPickerLocaleUrl, {}),
		loadEmojiPickerJson(emojiPickerSearchUrl, {})
	]).then(function(results) {
		var emojiData = results[1];
		var emojiI18n = results[2];
		var emojiSearchIndex = results[3];

		if (!emojiData || !window.EmojiMart || !window.EmojiMart.Picker) {
			return;
		}

		emojiData = mergeEmojiSearchIndex(emojiData, emojiSearchIndex);
		var emojiBaseSize = parseFloat(window.getComputedStyle(document.body).fontSize) || 16;
		var emojiButtonSize = Math.round(2.9 * emojiBaseSize);
		var emojiPerLine = Math.max(1, Math.floor(((pickerContainer.getBoundingClientRect().width || pickerContainer.clientWidth) + 1) / emojiButtonSize));

		pickerContainer.innerHTML = '';
		pickerContainer.appendChild(new EmojiMart.Picker({
			data: emojiData,
			i18n: emojiI18n,
			locale: emojiPickerLocale,
			set: 'native',
			emojiVersion: 17,
			emojiButtonSize: emojiButtonSize,
			emojiSize: Math.round(1.9 * emojiBaseSize),
			theme: isDarkTheme ? 'dark' : 'light',
			dynamicWidth: true,
			perLine: emojiPerLine,
			maxFrequentRows: 2,
			searchPosition: 'sticky',
			previewPosition: 'none',
			skinTonePosition: 'search',
			noCountryFlags: navigator.platform.indexOf('Win') > -1,
			onEmojiSelect: insert_editor_emoji
		}));
	}).catch(function() {
	});
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initEmojiPicker, { once: true });
} else {
	initEmojiPicker();
}
HTML;

	$output = <<<HTML
<div class="emoji_box"><div id="dle-emoji-picker"></div></div>
HTML;
	
} else {
	
	$emoji_script = "";
	$i = 0;
	$output = "<table style=\"width:100%;border: 0px;padding: 0px;\"><tr>";

	$smilies = explode(",", $config['smilies']);
	$count_smilies = count($smilies);
	
	foreach($smilies as $smile)
	{
		$i++;
		$smile = trim($smile);
		$sm_image ="";
		if( file_exists( ROOT_DIR . "/public/emoticons/" . $smile . ".png" ) ) {
			if( file_exists( ROOT_DIR . "/public/emoticons/" . $smile . "@2x.png" ) ) {
				$sm_image = "<img alt=\"{$smile}\" class=\"emoji\" src=\"{$_ROOT_DLE_URL}public/emoticons/{$smile}.png\" srcset=\"{$_ROOT_DLE_URL}public/emoticons/{$smile}@2x.png 2x\" />";
			} else {
				$sm_image = "<img alt=\"{$smile}\" class=\"emoji\" src=\"{$_ROOT_DLE_URL}public/emoticons/{$smile}.png\" />";	
			}
		} elseif ( file_exists( ROOT_DIR . "/public/emoticons/" . $smile . ".gif" ) ) {
			if( file_exists( ROOT_DIR . "/public/emoticons/" . $smile . "@2x.gif" ) ) {
				$sm_image = "<img alt=\"{$smile}\" class=\"emoji\" src=\"{$_ROOT_DLE_URL}public/emoticons/{$smile}.gif\" srcset=\"{$_ROOT_DLE_URL}public/emoticons/{$smile}@2x.gif 2x\" />";
			} else {
				$sm_image = "<img alt=\"{$smile}\" class=\"emoji\" src=\"{$_ROOT_DLE_URL}public/emoticons/{$smile}.gif\" />";	
			}
		}
		
		$output .= "<td style=\"padding:5px;text-align: center;\"><a href=\"#\" onclick=\"dle_smiley(this); return false;\">{$sm_image}</a></td>";
		if ($i%7 == 0 AND $i < $count_smilies) $output .= "</tr><tr>";
	
	}

	$output .= "</tr></table>";
}

$body_class = $config['emoji'] ? ' class="emoji_mart_mode"' : '';

echo <<<HTML
<html>
    <head>
        <meta charset="UTF-8">
<style>
	.emoji_box {
		width:100%;
		height:100vh;
		overflow:hidden;
	}
	em-emoji-picker {
		--font-size: 1em!important;
		--border-radius: 0;
	}
	#dle-emoji-picker,
	em-emoji-picker {
		width:100%;
		height:100vh;
	}
	.emoji_category {
		padding:0.487em;
		clear:both;
	}
	.emoji_list {
		margin-top:0.348em;
		margin-bottom:0.348em;
		width:100%;
		font-family:'Apple Color Emoji', 'Segoe UI Emoji', 'NotoColorEmoji', 'Segoe UI Symbol', 'Android Emoji', 'EmojiSymbols';
		font-size:1.667em;
	}
	.emoji_symbol {
		float:left;
		margin-bottom: 0.694em;
		width:12.5%;
		text-align:center;
	}
	
	.emoji_symbol a,  .emoji_symbol a:hover {
		cursor: pointer;
		text-decoration:none;
	}
	
	img.emoji {
	    width: 1.8em;
	    height: 1.8em;
	}

	html, body {
		height:100%;
		margin:0;
	}

	body {
		font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", "Noto Sans", "Liberation Sans", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
    	line-height: 1.4285715;
		word-spacing: 0.1em;
		overflow-x:hidden;
		overflow-y:auto;
   }

	body.emoji_mart_mode {
		overflow:hidden;
	}
   
   body.dle_theme_dark {
   		color: #b3b3b3;
   }

	html.htmlfontsize-50 {
		font-size: .5rem
	}

	html.htmlfontsize-75 {
		font-size: .75rem
	}
	html.htmlfontsize-80 {
		font-size: .8rem
	}
	html.htmlfontsize-85 {
		font-size: .85rem
	}
	html.htmlfontsize-90 {
		font-size: .9rem
	}
	html.htmlfontsize-95 {
		font-size: .95rem
	}
	html.htmlfontsize-100 {
		font-size: .9rem
	}	
	html.htmlfontsize-105 {
		font-size: 1.05rem
	}
	html.htmlfontsize-110 {
		font-size: 1.1rem
	}
	html.htmlfontsize-115 {
		font-size: 1.15rem
	}
	html.htmlfontsize-120 {
		font-size: 1.2rem
	}
	html.htmlfontsize-125 {
		font-size: 1.25rem
	}
	html.htmlfontsize-130 {
		font-size: 1.3rem
	}
	html.htmlfontsize-135 {
		font-size: 1.35rem
	}
	html.htmlfontsize-140 {
		font-size: 1.4rem
	}
	html.htmlfontsize-145 {
		font-size: 1.45rem
	}
	html.htmlfontsize-150 {
		font-size: 1.5rem
	}

	html.htmlfontsize-175 {
		font-size: 1.75rem
	}

	html.htmlfontsize-200 {
		font-size: 2rem
	}
</style>
    </head>
    <body{$body_class}>
{$output}
<script>
<!--
    function dle_smiley(finalImage) {

		finalImage = '<span class="emoji noncontenteditable">' + finalImage.innerHTML + "</span>";

		parent.tinyMCE.execCommand('mceInsertContent',false,finalImage);
		parent.tinyMCE.activeEditor.windowManager.close();


	}
{$emoji_script}
-->
</script>
    </body>
</html>
HTML;
?>
