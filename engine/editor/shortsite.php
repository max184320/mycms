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
 File: shortsite.php
-----------------------------------------------------
 Use: WYSIWYG for news at website 
=====================================================
*/

if( !defined( 'DATALIFEENGINE' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

if (!isset ($row['short_story'])) $row['short_story'] = "";

$p_name = urlencode($member_id['name']);
$id = isset($id) ? intval($id) : 0;
$dark_theme = isset($dark_theme) ? $dark_theme : '';

if (defined('TEMPLATE_DIR')) {
	$template_dir = TEMPLATE_DIR;
} else $template_dir = ROOT_DIR . "/templates/" . $config['skin'];

if (is_file($template_dir . "/info.json")) {

	$data = json_decode(trim(file_get_contents($template_dir . "/info.json")), true);

	if (isset($data['type']) and $data['type'] == "dark") {
		$dark_theme = " dle_theme_dark";
	}
}

$js_array[] = "public/editor/tiny_mce/tinymce.min.js";

if($config['bbimages_in_wysiwyg']) {
	$implugin = 'dleimage';
} else $implugin = 'image';

$groups_list = [];

foreach ($user_group as $gid => $gdata) {
	if ($gid == 1) continue;
	$groups_list[$gid] = $gdata['group_name'];
}

$dlehide_groups_json = json_encode($groups_list, JSON_UNESCAPED_UNICODE);

$image_upload = array();

if ( $user_group[$member_id['user_group']]['allow_image_upload'] ) {

		$image_upload[0] = "dleupload ";

		$image_upload[1] = <<<HTML
var dle_image_upload_handler = (blobInfo, progress) => new Promise((resolve, reject) => {
  var xhr, formData;

  xhr = new XMLHttpRequest();
  xhr.withCredentials = false;
  xhr.open('POST', dle_root + 'index.php?controller=ajax&mod=upload');
  
  xhr.upload.onprogress = (e) => {
    progress(e.loaded / e.total * 100);
  };

  xhr.onload = function() {
    var json;

    if (xhr.status === 403) {
      reject('HTTP Error: ' + xhr.status, { remove: true });
      return;
    }

    if (xhr.status < 200 || xhr.status >= 300) {
      reject('HTTP Error: ' + xhr.status);
      return;
    }

    json = JSON.parse(xhr.responseText);

    if (!json || typeof json.link != 'string') {

		if(typeof json.error == 'string') {
			reject(json.error);
		} else {
			reject('Invalid JSON: ' + xhr.responseText);	
		}
		
		var editor = tinymce.activeEditor;
		var node = editor.selection.getEnd();
		editor.selection.select(node);
		editor.insertContent('');
		
      return;
    }

	if( json.flink ) {
		
		resolve(json.link);

		setTimeout(() => {
			var editor = tinymce.activeEditor;
			if (!editor) return;

			var imageElement = editor.getBody().querySelector('img[src^="'+json.link+'"]');

			if (imageElement) {
				
				imageElement.removeAttribute('width');
				imageElement.removeAttribute('height');

				editor.dom.setStyles(imageElement, {
                	'display': 'block',
                	'margin-left': 'auto',
                	'margin-right': 'auto'
           		});

				var linkElement = editor.dom.create('a', {
					href: json.flink,
					class: 'highslide'
				});

				editor.dom.insertAfter(linkElement, imageElement);
				linkElement.appendChild(imageElement);
				var brElement = editor.dom.create('br');
            	editor.dom.insertAfter(brElement, linkElement);
				editor.selection.setCursorLocation(brElement, 0);
			}

		}, 300);

		$('#mediaupload').remove();

	} else {
		resolve(json.link);
		$('#mediaupload').remove();
	}
	
  };

  xhr.onerror = function () {
    reject('Image upload failed due to a XHR Transport error. Code: ' + xhr.status);
  };

  formData = new FormData();
  formData.append('qqfile', blobInfo.blob(), blobInfo.filename());
  formData.append("subaction", "upload");
  formData.append("news_id", "{$id}");
  formData.append("area", "short_story");
  formData.append("author", "{$p_name}");
  formData.append("mode", "quickload");
  formData.append("editor_mode", "tinymce");
  formData.append("user_hash", "{$dle_login_hash}");    
  
  xhr.send(formData);
});
HTML;

		$image_upload[2] = <<<HTML
paste_data_images: true,
automatic_uploads: true,
images_upload_handler: dle_image_upload_handler,
images_reuse_filename: true,
image_uploadtab: false,
images_file_types: 'gif,jpg,png,jpeg,bmp,webp,avif',
file_picker_types: 'image',

file_picker_callback: function (cb, value, meta) {
    const input = document.createElement('input');
    input.setAttribute('type', 'file');
    input.setAttribute('accept', 'image/*');

    input.addEventListener('change', (e) => {
      const file = e.target.files[0];

		var filename = file.name;
		filename = filename.split('.').slice(0, -1).join('.');
	
      const reader = new FileReader();
      reader.addEventListener('load', () => {

        const id = filename;
        const blobCache =  tinymce.activeEditor.editorUpload.blobCache;
        const base64 = reader.result.split(',')[1];
        const blobInfo = blobCache.create(id, file, base64);
        blobCache.add(blobInfo);

        /* call the callback and populate the Title field with the file name */
        cb(blobInfo.blobUri());

      });
      reader.readAsDataURL(file);
    });

    input.click();
},
HTML;
		
	} else {
		
		$image_upload[0] = "";
		$image_upload[1] = "";
		$image_upload[2] = "";
		
	}	
	
	if( $user_group[$member_id['user_group']]['allow_file_upload'] ) {
		$image_upload[0] = "dleupload ";
	}

$chat_gpt = array(0 => '', 1 => '', 2 => '', 3 => '', 4 => '');

if ( $config['enable_ai'] AND in_array($member_id['user_group'], explode(',', trim($config['ai_groups'])) ) ) {

	$chat_gpt[0] = 'ai ';
	$chat_gpt[1] = 'aidialog ';
	$chat_gpt[2] = 'aishortcuts ';
	$chat_gpt[3] = "ai_request,
	ai_shortcuts: [
		{ title: 'Summarize content', prompt: '{$lang['ai_command_1']}', selection: true },
		{ title: 'Improve writing', prompt: '{$lang['ai_command_2']}', selection: true },
		{ title: 'Simplify language', prompt: '{$lang['ai_command_3']}', selection: true },
		{ title: 'Expand upon', prompt: '{$lang['ai_command_4']}', selection: true },
		{ title: 'Trim content', prompt: '{$lang['ai_command_5']}', selection: true },
		{
			title: 'Change tone', subprompts: [
			{ title: 'Professional', prompt: '{$lang['ai_command_6']}', selection: true },
			{ title: 'Casual', prompt: '{$lang['ai_command_7']}', selection: true },
			{ title: 'Direct', prompt: '{$lang['ai_command_8']}', selection: true },
			{ title: 'Confident', prompt: '{$lang['ai_command_9']}', selection: true },
			{ title: 'Friendly', prompt: '{$lang['ai_command_10']}', selection: true },
			]
		},
		{
			title: 'Change style', subprompts: [
			{ title: 'Business', prompt: '{$lang['ai_command_11']}', selection: true },
			{ title: 'Legal', prompt: '{$lang['ai_command_12']}', selection: true },
			{ title: 'Journalism', prompt: '{$lang['ai_command_13']}', selection: true },
			{ title: 'Medical', prompt: '{$lang['ai_command_14']}', selection: true },
			{ title: 'Poetic', prompt: '{$lang['ai_command_15']}', selection: true },
			]
		},
		{
			title: 'Translate', subprompts: [
			{ title: 'Translate to English', prompt: 'Translate this content to English language.', selection: true },
			{ title: 'Translate to Russian', prompt: 'Translate this content to Russian language.', selection: true },
			{ title: 'Translate to German', prompt: 'Translate this content to German language.', selection: true },
			{ title: 'Translate to Spanish', prompt: 'Translate this content to Spanish language.', selection: true },
			{ title: 'Translate to Portuguese', prompt: 'Translate this content to Portuguese language.', selection: true },
			{ title: 'Translate to French', prompt: 'Translate this content to French language.', selection: true },
			{ title: 'Translate to Norwegian', prompt: 'Translate this content to Norwegian language.', selection: true },
			{ title: 'Translate to Ukrainian', prompt: 'Translate this content to Ukrainian language.', selection: true },
			{ title: 'Translate to Japanese', prompt: 'Translate this content to Japanese language.', selection: true },
			{ title: 'Translate to Korean', prompt: 'Translate this content to Korean language.', selection: true },
			{ title: 'Translate to Simplified Chinese', prompt: 'Translate this content to Simplified Chinese language.', selection: true },
			{ title: 'Translate to Hebrew', prompt: 'Translate this content to Hebrew language.', selection: true },
			{ title: 'Translate to Hindi', prompt: 'Translate this content to Hindi language.', selection: true },
			{ title: 'Translate to Arabic', prompt: 'Translate this content to Arabic language.', selection: true },
			]
		},
	],
";

	if ($config['ai_tokens'] == '') $config['ai_tokens'] = 'null';
	if ($config['ai_completion_tokens'] == '') $config['ai_completion_tokens'] = 'null';
	if ($config['ai_temperature'] == '') $config['ai_temperature'] = 'null';
	
	$config['ai_proxy'] = intval($config['ai_proxy']) ? '1' : '0';

	if ($config['ai_proxy']) {
		$config['ai_key'] = 'hidden';
	}


	$chat_gpt[4] = <<<HTML
const gptFetchApi = import(dle_root + "public/editor/tiny_mce/plugins/ai/fetch/index.js").then(module => module.fetchEventSource);

const gpt_api_endurl = '{$config['ai_endpoint']}';
const gpt_api_use_proxy = '{$config['ai_proxy']}';
const gpt_api_proxy_url = dle_root + 'index.php?controller=ajax&mod=aiproxy';
const gpt_api_key = '{$config['ai_key']}';
const gpt_api_mode = '{$config['ai_mode']}';
const gpt_api_tokens = {$config['ai_tokens']};
const gpt_api_completion_tokens = {$config['ai_completion_tokens']};
const gpt_api_temperature = {$config['ai_temperature']};
const finalMaxTokens = gpt_api_tokens || gpt_api_completion_tokens || 4096;

const ai_request = (request, respondWith) => {
  respondWith.stream((signal, streamMessage) => {
    
    const isGoogle = gpt_api_endurl.includes('google');
    const isYandex = gpt_api_endurl.includes('yandex');
    const isOpenaiResponses = gpt_api_endurl.includes('v1/responses');
    const isAnthropic = gpt_api_endurl.includes('anthropic');

    let finalUrl = gpt_api_endurl;
    if (isGoogle) {
      const separator = finalUrl.includes('?') ? '&' : '?';
      finalUrl += `\${separator}key=\${gpt_api_key}`;
      if (!finalUrl.includes('alt=sse')) {
        finalUrl += '&alt=sse';
      }
    }
	if(gpt_api_use_proxy == '1') {
		finalUrl = gpt_api_proxy_url;
	}

    const conversation = request.thread.flatMap((event) => {
      if (event.response) {
        if (isGoogle) {
          return [
            { role: 'user', parts: [{ text: `Question:\\n\${event.request.query}\\n\\nContext:\\n\${event.request.context}` }] },
            { role: 'model', parts: [{ text: event.response.data }] }
          ];
        } else if (isYandex) {
          return [
            { role: 'user', text: `Question:\\n\${event.request.query}\\n\\nContext:\\n\${event.request.context}` },
            { role: 'assistant', text: event.response.data }
          ];
        } else {
          return [
            { role: 'user', content: `Question:\\n\${event.request.query}\\n\\nContext:\\n\${event.request.context}` },
            { role: 'assistant', content: event.response.data }
          ];
        }
      }
      return [];
    });

    const originalSystemInstructions = [
      'You are an HTML content editor assistant. Your output is injected directly into an HTML WYSIWYG editor. You MUST respond ONLY with valid HTML markup — never plain text, never Markdown.',
      'OUTPUT FORMAT RULES: Use ONLY HTML tags for ALL formatting. Use <b> for bold. Use <i> for italic. Use <u> for underline. Use <s> for strikethrough. Use <ul><li> and <ol><li> for lists. Use <a href="..."> for links. Use <h2>, <h3>, <h4> for headings. Use <p> for paragraphs. Use <br> for line breaks. Use <table>, <tr>, <td>, <th> for tables.',
      'CODE BLOCKS: If your response contains any source code or code examples, wrap them in <pre class="language-LANG"><code>...</code></pre> where LANG matches the code language: language-markup for HTML/XML, language-javascript for JavaScript, language-css for CSS, language-php for PHP, language-sql for SQL, language-python for Python, language-java for Java, language-csharp for C#, language-cpp for C++, language-c for C, language-ruby for Ruby. Inside <code>, convert all special HTML characters to entities: < to &lt; > to &gt; & to &amp; " to &quot;. NEVER use triple backticks (```), single backticks (`), or any other Markdown code syntax.',
      'MARKDOWN IS STRICTLY FORBIDDEN: Do NOT use any Markdown syntax including: # for headings, * or _ for bold/italic, - or * for lists, ``` or ` for code, [text](url) for links, > for blockquotes, | for tables. Any Markdown in the output makes it invalid. Use the equivalent HTML tags listed above instead.',
      'EDITING AND TRANSFORMATION: When asked to translate, rewrite, improve, expand, simplify, summarize, or change the tone/style of the provided text, you MUST preserve the original HTML structure exactly. Keep all HTML tags, attributes, classes, inline styles, links (<a href>), images (<img>), tables, lists, and any other markup intact — change ONLY the text content inside the tags. For example, if the input is <p><b>Hello</b> <a href="/link">world</a></p> and the task is to translate to Russian, output <p><b>Привет</b> <a href="/link">мир</a></p>. Do NOT flatten the structure, do NOT remove tags, do NOT change href values or class names, do NOT merge or split HTML elements. The HTML tag structure of your output must mirror the input structure as closely as possible.',
      'CONTENT RULES: Write clean, semantic HTML. Do not wrap the entire response in <html>, <head>, or <body> tags — output only the inner content fragment. When generating new content (not editing existing text), use proper HTML tags for structure.',
    ];

    const queryText = request.context.length === 0 || conversation.length > 0
      ? request.query
      : `Question:\\n\${request.query}\\n\\nContext:\\n\${request.context}`;

    let requestBody = {};

    if (isGoogle) {
      const googleSystemText = originalSystemInstructions.join('\\n\\n');
      requestBody = {
        contents: [...conversation, { role: 'user', parts: [{ text: queryText }] }],
        systemInstruction: { parts: [{ text: googleSystemText }] }
      };
	  const generationConfig = {
	    maxOutputTokens: finalMaxTokens
	  };
	  if (gpt_api_temperature !== null) generationConfig.temperature = gpt_api_temperature;
	  requestBody.generationConfig = generationConfig;
    } 
    else if (isYandex) {
      const yandexInstructions = originalSystemInstructions.join('\\n\\n');
      requestBody = {
        modelUri: gpt_api_mode,
        messages: [{ role: 'system', text: yandexInstructions }, ...conversation, { role: 'user', text: queryText }],
        completionOptions: { stream: true, maxTokens: finalMaxTokens }
      };
      if (gpt_api_temperature !== null) requestBody.completionOptions.temperature = gpt_api_temperature;
    }
    else if (isOpenaiResponses) {
      const openaiInstructions = originalSystemInstructions.join('\\n\\n');
      requestBody = {
        model: gpt_api_mode,
        instructions: openaiInstructions,
        input: [...conversation, { role: 'user', content: queryText }],
        stream: true
      };
      if (gpt_api_temperature !== null) requestBody.temperature = gpt_api_temperature;
      if (gpt_api_tokens !== null) requestBody.max_output_tokens = gpt_api_tokens;
    }
    else if (isAnthropic) {
      requestBody = {
        model: gpt_api_mode,
        system: originalSystemInstructions.join('\\n\\n'),
        messages: [...conversation, { role: 'user', content: queryText }],
        max_tokens: finalMaxTokens,
        stream: true
      };
      if (gpt_api_temperature !== null) requestBody.temperature = gpt_api_temperature;
    }
    else {    
      const openaiInstructions = originalSystemInstructions.join('\\n\\n');
      requestBody = {
        model: gpt_api_mode,
        messages: [{ role: 'system', content: openaiInstructions }, ...conversation, { role: 'user', content: queryText }],
        stream: true
      };
      if (gpt_api_temperature !== null) requestBody.temperature = gpt_api_temperature;
      if (gpt_api_tokens !== null) requestBody.max_tokens = gpt_api_tokens;
      if (gpt_api_completion_tokens !== null) requestBody.max_completion_tokens = gpt_api_completion_tokens;
    }

    let lastResponseLength = 0;

    const headers = { 'Content-Type': 'application/json' };

    if (isAnthropic) {
      headers['x-api-key'] = gpt_api_key;
      headers['anthropic-version'] = '2023-06-01';
      headers['anthropic-dangerous-direct-browser-access'] = 'true';
    } else if (isYandex) {
      headers['Authorization'] = `Api-Key \${gpt_api_key}`;
    } else if (isGoogle) {
      headers['x-goog-api-key'] = gpt_api_key;
    } else {
      headers['Authorization'] = `Bearer \${gpt_api_key}`;
    }

    const fetchOptions = {
      signal,
      method: 'POST',
      headers: headers,
      body: JSON.stringify(requestBody)
    };

    const onopen = async (response) => {
      if (response && response.ok) return;
      const data = await response.json().catch(() => ({}));
      throw new Error(data.error?.message || data.error?.type || data.detail || 'API Error');
    };

    const onmessage = (ev) => {
      const data = ev.data;
      if (data === '[DONE]') return;
      try {
        const parsedData = JSON.parse(data);
        let message = '';

        if (isGoogle) {
          message = parsedData?.candidates?.[0]?.content?.parts?.[0]?.text || '';
        } else if (isAnthropic) {
          message = parsedData?.delta?.text || '';
        } else if (isYandex) {
          const fullText = parsedData?.result?.alternatives?.[0]?.message?.text || '';
          message = fullText.substring(lastResponseLength);
          lastResponseLength = fullText.length;
        } else if (isOpenaiResponses) {
          message = parsedData.type === 'response.output_text.delta' ? parsedData.delta : '';
        } else {
          message = parsedData?.choices?.[0]?.delta?.content || '';
        }

        if (message) streamMessage(message);
      } catch (e) {}
    };

    const onerror = (error) => { throw error; };

    return gptFetchApi.then(fetchEventSource =>
      fetchEventSource(finalUrl, {
        ...fetchOptions,
        openWhenHidden: true,
        onopen,
        onmessage,
        onerror
      })
    ).catch(onerror);
  });
};
HTML;

}

	if( @file_exists( ROOT_DIR . '/templates/'. $config['skin'].'/editor.css' ) ) $editor_template_css = ", dle_root + 'templates/{$config['skin']}/editor.css'"; else $editor_template_css = '';
	$dle_cryptkey   = substr(hash('sha256', SECURE_AUTH_KEY), 0, 6);

	$onload_scripts[] = <<<HTML
	
	{$image_upload[1]}
	{$chat_gpt[4]}

	tinyMCE.baseURL = dle_root + 'public/editor/tiny_mce';
	tinyMCE.suffix = '.min';

	var dle_theme = '{$dark_theme}';
	dle_theme = dle_theme.trim();

	if(dle_theme != '') {
		$('body').addClass( dle_theme );
	}

	var statusbar = true;
	var additionalplugins = '';
	var maxheight = $(window).height() * .8;

	if($('body').hasClass('editor-style-light') || $('body').hasClass('editor-autoheight')) {
       statusbar = false;
    } else  additionalplugins += ' wordcount';
	
	if($('body').hasClass('editor-autoheight')) {
       additionalplugins += ' autoresize';
    }
	
	if (typeof getBaseSize === "function") {
		var height = 400 * getBaseSize();
		var body_extra_size;
		if( getBaseSize() > 1 && (body_extra_size = getFontSizeBase()) )  {
			var body_extra = 'body { --font-size-base: '+ body_extra_size +'; }';
		}		
	} else {
		var height = 400;
		var body_extra = '';
	}

	tinymce.init({
		selector: 'textarea.wysiwygeditor',
		language : "{$lang['language_code']}",
		directionality: '{$lang['direction']}',
		element_format : 'html',
		body_class: dle_theme,
		content_style: body_extra,
		skin: dle_theme == 'dle_theme_dark' ? 'oxide-dark' : 'oxide',

		dle_root : dle_root,
		dle_upload_area : "short_story",
		dle_upload_user : "{$p_name}",
		dle_upload_news : "{$id}",
		dle_cryptkey : "{$dle_cryptkey}",
		dle_user_groups: {$dlehide_groups_json},

		width : "100%",
		height : height,
		min_height: 50,
		max_height: maxheight,
		autoresize_bottom_margin: 1,
		statusbar: statusbar,
		deprecation_warnings: false,
		promotion: false,
		cache_suffix: '?v={$config['cache_id']}',
		license_key: 'gpl',
		sandbox_iframes: false,
		plugins: "{$chat_gpt[0]}accordion fullscreen advlist autolink lists dlelink image charmap anchor searchreplace visualblocks visualchars nonbreaking table codemirror dlebutton codesample quickbars autosave pagebreak toc" + additionalplugins,

		setup: function(editor) {
			editor.on('PreInit', function() {
				var shortEndedElements = editor.schema.getVoidElements();
				shortEndedElements['path'] = {};
				shortEndedElements['source'] = {};
				shortEndedElements['use'] = {};
				
				editor.schema.addCustomElements('dlehide');
           		editor.schema.addValidElements('dlehide[class|data-allowed-groups|contenteditable]');
			});
		},
		paste_postprocess: (editor, args) => {
			args.node.innerHTML = DLEclearPasteText(args.node.innerHTML, editor);
		},
		
		indentation : '20px',
		relative_urls : false,
		convert_urls : false,
		remove_script_host : false,
		verify_html: false,
		nonbreaking_force_tab: true,
		branding: false,
		link_default_target: '_blank',
		browser_spellcheck: true,
		pagebreak_separator: '{PAGEBREAK}',
		pagebreak_split_block: true,
		editable_class: 'contenteditable',
		noneditable_class: 'noncontenteditable',
		contextmenu: 'image table lists',
		
		extended_valid_elements: 'dlehide[class|contenteditable|data-allowed-groups]',
  		custom_elements: 'dlehide',

		image_advtab: true,
		image_caption: true,
		image_dimensions: true,
		a11y_advanced_options: true,
		{$image_upload[2]}
		{$chat_gpt[3]}
		
		draggable_modal: true,

		menubar: false,

		toolbar: [
			'{$chat_gpt[1]}bold italic underline strikethrough align bullist numlist link unlink table {$image_upload[0]} {$implugin} dlemp dlaudio dletube dleemo dlequote dlehide dlespoiler codesample pastetext code dlemore',
			'mathml fontformatting forecolor backcolor | outdent indent subscript superscript anchor accordion pagebreak dlepage hr charmap searchreplace toc dletypo visualblocks | restoredraft undo redo removeformat fullscreen'
		],
  
		mobile: {
			plugins: '{$chat_gpt[0]}autoresize dlelink image dlebutton codemirror',
			toolbar: '{$chat_gpt[1]}bold italic underline alignleft aligncenter alignright link {$image_upload[0]} {$implugin} dlemp dlaudio dletube dlequote dlespoiler dlehide code',
			min_height: 50,
			max_height: 400,
			autoresize_bottom_margin: 1,
			statusbar: false,
			quickbars_selection_toolbar: false
		},

		toolbar_groups: {
		  
			fontformatting: {
			  icon: 'change-case',
			  tooltip: 'Formatting',
			  items: 'blocks styles fontfamily fontsize lineheight'
			},
			  
			align: {
			  icon: 'align-center',
			  tooltip: 'Formatting',
			  items: 'alignleft aligncenter alignright alignjustify'
			}
		},

		block_formats: 'Tag (p)=p;Tag (div)=div;Header 1=h1;Header 2=h2;Header 3=h3;Header 4=h4;Header 5=h5;Header 6=h6;',
		style_formats: [
			{ title: 'Information Block', block: 'div', wrapper: true, styles: { 'color': '#333', 'border': 'solid 1px #00897B', 'padding': '0.78em', 'background-color': '#E0F2F1', 'box-shadow': 'rgba(126,142,177,0.2) 0 .35em .7em' } },
			{ title: 'Warning Block', block: 'div', wrapper: true, styles: { 'border': '1px solid #FF9800', 'padding': '0.78em', 'background-color': '#FFF3E0', 'color': '#333', 'box-shadow': 'rgba(126,142,177,0.2) 0 .35em .7em' } },
			{ title: 'Error Block', block: 'div', wrapper: true, styles: { 'border': '1px solid #FF5722', 'padding': '0.78em', 'background-color': '#FBE9E7', 'color': '#333', 'box-shadow': 'rgba(126,142,177,0.2) 0 .35em .7em' } },
			{ title: 'Borders', block: 'div', wrapper: true, styles: { 'border': '1px solid #ccc', 'padding': '0.78em' } },
			{ title: 'Borders top and bottom', block: 'div', wrapper: true, styles: { 'border-top': 'solid 1px #ccc', 'border-bottom': 'solid 1px #ccc', 'padding': '.78em 0' } },
			{ title: 'Use a shadow', block: 'div', wrapper: true, styles: { 'box-shadow': 'rgba(126,142,177,0.2) 0 .35em .7em' } },
			{ title: 'Increased letter spacing', inline: 'span', styles: { 'letter-spacing': '.1em' } },
			{ title: 'Сapital letters', inline: 'span', styles: { 'text-transform': 'uppercase' } },
			{ title: 'Gray background', block: 'div', wrapper: true, styles: { 'color': '#fff', 'background-color': '#607D8B', 'padding': '0.78em' } },
			{ title: 'Brown background', block: 'div', wrapper: true, styles: { 'color': '#fff', 'background-color': '#795548', 'padding': '0.78em' } },
			{ title: 'Blue background', block: 'div', wrapper: true, styles: { 'color': '#104d92', 'background-color': '#E3F2FD', 'padding': '0.78em' } },
			{ title: 'Green background', block: 'div', wrapper: true, styles: { 'color': '#fff', 'background-color': '#009688', 'padding': '0.78em' } },
		],

		image_class_list: [
			{ title: 'None', value: '' },
			{ title: 'Image Border', value: 'image-bordered' },
			{ title: 'Image Shadow', value: 'image-shadows' },
			{ title: 'Image Padding', value: 'image-padded' },
			{ title: 'Borders Padding', value: 'image-bordered image-padded' },
			{ title: 'Shadow Padding', value: 'image-shadows image-padded' },
		],
		
		codesample_languages: [
			{ text: 'HTML/XML', value: 'markup' },
			{ text: 'JavaScript', value: 'javascript' },
			{ text: 'CSS', value: 'css' },
			{ text: 'PHP', value: 'php' },
			{ text: 'SQL', value: 'sql' },
			{ text: 'Ruby', value: 'ruby' },
			{ text: 'Python', value: 'python' },
			{ text: 'Java', value: 'java' },
			{ text: 'C', value: 'c' },
			{ text: 'C#', value: 'csharp' },
			{ text: 'C++', value: 'cpp' }
		],
		
		text_patterns: [],
		extended_mathml_attributes: ['linebreak'],
		font_size_formats: '0.8em 0.9em 1em 1.1em 1.2em 1.3em 1.4em 1.5em 2em 2.5em 3em',
		font_size_input_default_unit: "em",
		quickbars_insert_toolbar: false,
		quickbars_selection_toolbar: 'bold italic underline | quicklink | dlequote dlespoiler dlehide | forecolor backcolor | styles | blocks fontsize',
		quickbars_image_toolbar: 'alignleft aligncenter alignright | image link',

		autosave_ask_before_unload: false,
		autosave_interval: '10s',
		autosave_prefix: 'dle-editor-{path}{query}-{id}-',
		autosave_restore_when_empty: false,
		autosave_retention: '10m',
  
		formats: {
		  bold: {inline: 'b'},  
		  italic: {inline: 'i'},
		  underline: {inline: 'u', exact : true},  
		  strikethrough: {inline: 's', exact : true}
		},
		
		toc_depth : 4,

		content_css : [dle_root + 'public/editor/css/content.css'{$editor_template_css}]

	});
HTML;

$shortarea = <<<HTML
     <div class="wseditor{$dark_theme}"><textarea id="short_story" name="short_story" class="wysiwygeditor" style="width:98%;height:400px;">{$row['short_story']}</textarea></div>
HTML;