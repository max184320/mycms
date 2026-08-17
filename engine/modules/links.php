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
File: links.php
=====================================================
*/

if( !defined('DATALIFEENGINE') ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

mb_internal_encoding("UTF-8");

class Trie
{
	private $root;

	public function __construct() {
		$this->root = new TrieNode();
	}

	public function add($word, $data, $caseInsensitive = false) {
		$node = $this->root;
		$lowerWord = $caseInsensitive ? mb_strtolower($word) : $word;
		$originalWord = $word;

		foreach (preg_split('//u', $lowerWord, -1, PREG_SPLIT_NO_EMPTY) as $char) {
			if (!isset($node->children[$char])) {
				$node->children[$char] = new TrieNode();
			}
			$node = $node->children[$char];
		}

		$node->data = [
			'original' => $originalWord,
			'link' => $data['link'],
			'target_blank' => $data['target_blank'],
			'title' => $data['title'],
			'origpattern' => $data['origpattern'],
			'max_count' => $data['max_count'] ?? -1
		];
	}

	public function search($chars, $caseInsensitive = false) {
		$result = [];
		$len = count($chars);

		for ($i = 0; $i < $len; $i++) {
			$node = $this->root;
			$originalWord = '';

			for ($j = $i; $j < $len; $j++) {
				$char = $chars[$j];
				$lowerChar = $caseInsensitive ? mb_strtolower($char) : $char;

				if (!isset($node->children[$lowerChar])) break;

				$node = $node->children[$lowerChar];
				$originalWord .= $char;

				if ($node->data !== null) {
					$prevChar = $i > 0 ? $chars[$i - 1] : '';
					$nextChar = $j + 1 < $len ? $chars[$j + 1] : '';

					if (!$this->isWordChar($prevChar) && !$this->isWordChar($nextChar)) {
						$result[] = [
							'position' => $i,
							'word' => $originalWord,
							'word_len' => $j - $i + 1,
							'data' => $node->data
						];
					}
				}
			}
		}

		return $result;
	}

	private function isWordChar($char) {
		return preg_match('/[\p{L}\p{N}_]/u', $char);
	}	
}

class TrieNode {
	public $children = array();
	public $data = null;
}

function replace_links($html, $links) {

	if(!is_array($links) OR !count($links)) return $html;

	static $trieCache = array();
	$cacheKey = md5(json_encode(array_map(
		fn($i) => $i['word'] . $i['replace'] . $i['rcount'] . ($i['case'] ? '1' : '0'),
		$links
	)));

	if (!isset($trieCache[$cacheKey])) {
		$groups = array();
		foreach ($links as $item) {
			$key = $item['rcount'] . '___' . ($item['case'] ? '1' : '0');
			$groups[$key][] = $item;
		}

		$matchers = array();

		foreach ($groups as $key => $group) {
			$trie = new Trie();
			foreach ($group as $item) {
				$trie->add($item['word'], [
					'link' => $item['replace'],
					'target_blank' => $item['targetblank'],
					'title' => $item['title'],
					'origpattern' => $item['origpattern'],
					'max_count' => $item['rcount']
				], $item['case']);
			}
			$keyParts = explode('___', $key);
			$case = $keyParts[1] === '1';
			$matchers[] = array(
				'trie' => $trie,
				'case' => $case
			);
		}

		$trieCache[$cacheKey] = $matchers;
	}

	$matchers = $trieCache[$cacheKey];

	if (empty($matchers)) return $html;

	libxml_use_internal_errors(true);

	$dom = new DOMDocument('1.0', 'UTF-8');
	$utf8Meta = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';

	if (stripos($html, "<html") !== false) {
		$full_page = true;
		$dom->loadHTML($utf8Meta . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
	} else {
		$full_page = false;
		$dom->loadHTML('<!DOCTYPE html><html><head>' . $utf8Meta . '</head><body>' . $html . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
	}

	libxml_clear_errors();

	$xpath = new DOMXPath($dom);
	$nodes = $xpath->query('//text()[
		(parent::td or parent::div or parent::p or parent::li or parent::article or parent::span)
		and not(ancestor::a or ancestor::script or ancestor::style or ancestor::meta or ancestor::noscript or ancestor::textarea or ancestor::title or ancestor::code or ancestor::pre or ancestor::button or ancestor::svg)
	]');

	$currentUri = $_SERVER['REQUEST_URI'] ?? '';
	$compareCache = [];
	$has_replacement = false;
	$replacementCounts = array();

	foreach ($nodes as $node) {

		$text = $node->nodeValue;
		if ($text === '' || trim($text) === '') continue;
		$charsText = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
		$parent = $node->parentNode;
		$offset = 0;
		$fragments = [];

		$allMatches = [];

		foreach ($matchers as $matcher) {
			foreach ($matcher['trie']->search($charsText, $matcher['case']) as $m) {
				$allMatches[] = $m;
			}
		}
		
		if (empty($allMatches)) continue;

		usort(
			$allMatches,
			fn($a, $b) =>
			$a['position'] <=> $b['position'] ?: $b['word_len'] <=> $a['word_len']
		);

		foreach ($allMatches as $match) {

			$start = $match['position'];
			$end = $start + $match['word_len'];

			if ($start < $offset) continue;

			$linkUrl = $match['data']['link'];
			if (!isset($compareCache[$linkUrl])) {
				$compareCache[$linkUrl] = compare_paths($linkUrl, $currentUri);
			}

			if ($compareCache[$linkUrl]) continue;

			$word = $match['data']['origpattern'];
			$maxCount = $match['data']['max_count'];

			if (!isset($replacementCounts[$word])) $replacementCounts[$word] = 0;
			if ($maxCount > -1 && $replacementCounts[$word] >= $maxCount) continue;

			if ($start > $offset) {
				$fragments[] = implode('', array_slice($charsText, $offset, $start - $offset));
			}

			$a = $dom->createElement('a');

			$a->setAttribute('href', $match['data']['link']);

			if ($match['data']['target_blank']) {
				$a->setAttribute('target', '_blank');
				$a->setAttribute('rel', 'noopener noreferrer');
			}

			if ($match['data']['title']) {
				$a->setAttribute('title', $match['data']['title']);
			}

			$a->textContent = $match['word'];
			$fragments[] = $a;

			$offset = $end;
			$replacementCounts[$word]++;
			$has_replacement = true;
		}

		if ($offset < count($charsText)) {
			$fragments[] = implode('', array_slice($charsText, $offset));
		}

		if (!empty($fragments) && (count($fragments) > 1 || $fragments[0] instanceof DOMNode)) {
			$fragment = $dom->createDocumentFragment();
			foreach ($fragments as $part) {
				if ($part instanceof DOMNode) {
					$fragment->appendChild($part);
				} else {
					$fragment->appendChild($dom->createTextNode($part));
				}
			}
			$parent->replaceChild($fragment, $node);
		}
	}

	if(!$has_replacement) return $html;	

	if ($full_page) {
		$newHTML = $dom->saveHTML();
		$newHTML = str_replace($utf8Meta, '', $newHTML);
	} else {
		$newHTML = '';
		$body = $dom->getElementsByTagName('body')->item(0);
		if ($body) {
			foreach ($body->childNodes as $child) {
				$newHTML .= $dom->saveHTML($child);
			}
		} else {
			$newHTML = $dom->saveHTML();
		}
	}

	$newHTML = str_ireplace('%7Btheme%7D', '{THEME}', $newHTML);

	return $newHTML;
}

function compare_paths($a, $b) {

	if (empty($a) || empty($b)) {
		return false;
	}
	
	if(!is_same_domain($a)) return false;
	
	$normalizeUri = static function ($url) {
		if (str_starts_with($url, '//')) {
			$url = 'http:' . $url;
		}
		
		if (!preg_match('~^https?://~i', $url) && !str_starts_with($url, '//')) {
			$url = 'http://example.com' . (str_starts_with($url, '/') ? '' : '/') . $url;
		}

		$parsed = parse_url($url);
		if ($parsed === false) return null;
		$path = $parsed['path'] ?? '';

		$path = preg_replace('#/+#', '/', $path);

		if (!str_starts_with($path, '/')) {
			$path = '/' . $path;
		}

		$query = $parsed['query'] ?? '';

		return $query !== '' ? $path . '?' . $query : $path;
	};

	$na = $normalizeUri((string)$a);
	$nb = $normalizeUri((string)$b);

	if ($na === null || $nb === null) return false;

	return $na === $nb;
}

function is_same_domain($url) {
	global $config;

	$u = parse_url($url);
	if ($u === false || empty($u['host'])) return true;

	$norm = fn(string $h): string => preg_replace('/^www\./i', '', strtolower($h));

	$server = $norm(preg_replace('/:\d+$/', '', $_SERVER['SERVER_NAME'] ?? ''));

	$home = parse_url((string)($config['http_home_url'] ?? ''), PHP_URL_HOST) ?: $server;

	$h = $norm($u['host']);
	$home = $norm($home);

	return $h === $home || ($server !== '' && $h === $server);
}

function generate_words_variations($string) {
	$limit = 200;
	if (substr_count($string, '(') > 10) return [];

	$words = preg_split('~\s+~u', trim($string));
	$sentence = [];

	foreach ($words as $raw) {
		$vars = [$raw];

		while (count($vars) < $limit) {
			$expanded = [];
			$changed = false;

			foreach ($vars as $w) {
				if (!preg_match('~\(([^()]+)\)~u', $w, $m, PREG_OFFSET_CAPTURE)) {
					$expanded[] = $w;
					continue;
				}

				$changed = true;
				$full = $m[0][0];
				$pos  = $m[0][1];
				$opts = explode('|', $m[1][0]);

				$pre = substr($w, 0, $pos);
				$suf = substr($w, $pos + strlen($full));

				foreach ($opts as $o) {
					$expanded[] = $pre . $o . $suf;
					if (count($expanded) >= $limit) break 2;
				}
			}

			$vars = $expanded;
			if (!$changed) break;
		}

		$sentence[] = $vars;
	}

	$r = [[]];
	foreach ($sentence as $v) {
		$n = [];
		foreach ($r as $p) {
			foreach ($v as $i) {
				$n[] = [...$p, $i];
				if (count($n) >= $limit) break 2;
			}
		}
		$r = $n;
	}

	return array_map(fn($p) => implode(' ', $p), $r);
}

$replace_links = get_vars( "links" );

if( !is_array( $replace_links ) ) {

	$replace_links = array();
	
	$db->query( "SELECT * FROM " . PREFIX . "_links WHERE enabled=1 ORDER BY id DESC" );
	
	while ( $row = $db->get_row() ) {
		
		$processed = array();

		$row['word'] = trim(str_replace('&quot;', '"', stripslashes($row['word'])));
		if ($row['rcount'] < 1) $rcount = -1;
		else $rcount = intval($row['rcount']);
		if (!$row['only_one']) $caseInsensitive = true;
		else $caseInsensitive = false;

		$row['link'] = trim($row['link']);
		$row['link'] = preg_replace('/\s+/u', '', $row['link']);

		if (preg_match('~^(javascript|data|vbscript):~i', $row['link'])) {
			continue;
		}

		if (!preg_match('~^(https?:)?//~i', $row['link']) && !str_starts_with($row['link'], '/')) {
			continue;
		}

		if (preg_match('/^(.*?)\((.*?)\)/', $row['word'], $matches)) {

			$variants = generate_words_variations($row['word']);

			foreach ($variants as $v) {
				$processed[] = array(
					'word' => $v,
					'origpattern' => $row['word'],
					'replace' => $row['link'],
					'rcount' => $rcount,
					'targetblank' => $row['targetblank'],
					'title' => $row['title'],
					'case' => $caseInsensitive
				);
			}

		} else {
			$processed[] = [
				'word' => $row['word'],
				'origpattern' => $row['word'],
				'replace' => $row['link'],
				'rcount' => $rcount,
				'targetblank' => $row['targetblank'],
				'title' => $row['title'],
				'case' => $caseInsensitive
			];
		}

		foreach ($processed as $item) {
			if ($row['replacearea'] == 2) {
				$replace_links['news'][] = $item;
				$replace_links['comments'][] = $item;
			} elseif ($row['replacearea'] == 3) {
				$replace_links['news'][] = $item;
			} elseif ($row['replacearea'] == 4) {
				$replace_links['comments'][] = $item;
			} elseif ($row['replacearea'] == 5) {
				$replace_links['static'][] = $item;
			} elseif ($row['replacearea'] == 6) {
				$replace_links['news'][] = $item;
				$replace_links['comments'][] = $item;
				$replace_links['static'][] = $item;
			} else {
				$replace_links['all'][] = $item;
			}
		}
	
	}

	unset($processed);

	set_vars( "links", $replace_links );
	$db->free();

}