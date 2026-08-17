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
 File: keywords.php
-----------------------------------------------------
 Use: Generation of keywords
=====================================================
*/

if(!defined('DATALIFEENGINE')) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

if( !$is_logged OR !$user_group[$member_id['user_group']]['allow_admin'] ) { die ("error"); }

if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		
	die ("error");
	
}

$key = isset($_POST['key']) ? intval($_POST['key'] ) : 0;
$ai = isset($_POST['ai']) ? intval($_POST['ai'] ) : 0;

if( $ai && ( !$config['enable_ai'] OR !in_array($member_id['user_group'], explode(',', trim($config['ai_groups'])) ) ) ) {
	die ("AI not allowed");
}

$parse = new ParseFilter();
$short_story = isset($_POST['short_story']) ? $parse->BB_Parse($parse->process($_POST['short_story']), false) : '';
$full_story = isset($_POST['full_story']) ? $parse->BB_Parse($parse->process($_POST['full_story']), false) : '';
$parsed_fields = DLEXFields::Parse();
$all_xf_content = $parsed_fields['all_xf_content'];
unset($parsed_fields);

if (dle_strlen($full_story) > 12) $story = $full_story . ' ' . $all_xf_content;
else $story = $short_story . ' ' . $all_xf_content;
$story = stripslashes($story);

$metatags = create_metatags($story, true);

$metatags['description'] = str_replace("&amp;","&", $metatags['description'] );
$metatags['description'] = str_replace("&quot;", '"', $metatags['description'] );
$metatags['description'] = str_replace("&#039;", "'", $metatags['description']);
$metatags['description'] = stripslashes($metatags['description']);
$metatags['keywords'] = str_replace("&quot;", '"', $metatags['keywords']);
$metatags['keywords'] = str_replace("&#039;", "'", $metatags['keywords']);
$metatags['keywords'] = stripslashes($metatags['keywords']);

if ($key == 1) $content = $metatags['description']; else $content = $metatags['keywords'];

$success = true;
$error = '';

if( $ai ) {
	
	$story = clear_content($story, 0, false);

	if($story) {
		$ai_conf = array('apiUrl' => $config['ai_endpoint'], 'apiKey' => $config['ai_key'], 'model' => $config['ai_mode'], 'temperature' => '0.3');

		if ($key == 1) {
			$systemPrompt = <<<'EOT'
Based on the provided text, generate an SEO description for the <meta name="description"> tag.

REQUIREMENTS:
- Maximum length: 250 characters.
- Capture the core essence of the text.
- Make it informative and engaging.
- DO NOT use hashtags.
- DO NOT use quotation marks of any kind (neither inside the text nor wrapping the output).

STRICT OUTPUT FORMAT:
Return ONLY the final ready-to-use string. Do not include introductory words, explanations, markdown formatting, or quotes.
EOT;			
			$result = ExecuteAI($story, $systemPrompt, $ai_conf);
		} else {

			$systemPrompt = <<<'EOT'
Based on the provided text, generate a set of SEO keywords for the <meta name="keywords"> tag.

REQUIREMENTS:
- The keywords must reflect the main theme of the text, be highly relevant, and unique.
- Maximum amount: 20 keywords.
- Minimum length of each keyword: 3 characters.
- DO NOT use hashtags.
- DO NOT use quotation marks of any kind.

STRICT OUTPUT FORMAT:
Return ONLY a single string where all keywords are separated by commas (e.g., keyword1, keyword2, keyword3). Do not include introductory words, explanations, bullet points, or markdown formatting.
EOT;
			
			$result = ExecuteAI($story, $systemPrompt, $ai_conf);

			if($result['ok'] AND $result['text']) {
				$result['text'] = implode(',', array_map(fn($v) => rtrim(trim($v), '.'), explode(',', $result['text'])));
			}
			
		}

		if ($result['ok']) {
			$result['text'] = strip_tags( $result['text'] );
			$content = $result['text'];
		} else {
			$success = false;
			$error = $lang['ai_error'].'<br>'.$result['error'];
		}
	}

}

echo json_encode(array("success" => $success, "content" => $content, "error" => $error), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
