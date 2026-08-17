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
 File: urls.class.php
-----------------------------------------------------
 Use: DLE URL System
=====================================================
*/

if( !defined( 'DATALIFEENGINE' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

include_once(DLEPlugins::Check(ENGINE_DIR . '/classes/fastroute/fastroute.php'));

abstract class DLEUrl { 

	public static $rules = null;
	public static $error = null;

	public static $rule = null;
	public static $rulevars = null;
	private static $domain = null;

	private static $default_rules = [
		0 => [ 
			0 => [
				'showfull.page.newscomments' => ['/{year}/{month}/{day}/page,{news_page},{cstart},{news_name}.html', '/index.php?subaction=showfull&year={year}&month={month}&day={day}&news_page={news_page}&cstart={cstart}&news_name={news_name}'],
				'showfull.page.news' => ['/{year}/{month}/{day}/page,{news_page},{news_name}.html', '/index.php?subaction=showfull&year={year}&month={month}&day={day}&news_page={news_page}&news_name={news_name}'],
				'showfull.print' => ['/{year}/{month}/{day}/print:page,{news_page},{news_name}.html', '/index.php?mod=print&subaction=showfull&year={year}&month={month}&day={day}&news_page={news_page}&news_name={news_name}'],
				'showfull' => ['/{year}/{month}/{day}/{news_name}.html', '/index.php?subaction=showfull&year={year}&month={month}&day={day}&news_name={news_name}']
			],
			1 => [
				'showfull.page.newscomments' => ['/page,{news_page},{cstart},{newsid}-{news_name}.html', '/index.php?newsid={newsid}&news_page={news_page}&cstart={cstart}'],
				'showfull.page.news' => ['/page,{news_page},{newsid}-{news_name}.html', '/index.php?newsid={newsid}&news_page={news_page}'],
				'showfull.print' => ['/print:page,{news_page},{newsid}-{news_name}.html', '/index.php?mod=print&news_page={news_page}&newsid={newsid}'],
				'showfull' => ['/{newsid}-{news_name}.html', '/index.php?newsid={newsid}']
			],
			2 => [
				'showfull.page.newscomments' => ['/{category}/page,{news_page},{cstart},{newsid}-{news_name}.html', '/index.php?newsid={newsid}&news_page={news_page}&cstart={cstart}'],
				'showfull.page.news' => ['/{category}/page,{news_page},{newsid}-{news_name}.html', '/index.php?newsid={newsid}&news_page={news_page}'],
				'showfull.print' => ['/{category}/print:page,{news_page},{newsid}-{news_name}.html', '/index.php?mod=print&news_page={news_page}&newsid={newsid}'],
				'showfull' => ['/{category}/{newsid}-{news_name}.html', '/index.php?newsid={newsid}'],
				'showfull.page.newscomments.1' => ['/page,{news_page},{cstart},{newsid}-{news_name}.html', '/index.php?newsid={newsid}&news_page={news_page}&cstart={cstart}'],
				'showfull.page.news.1' => ['/page,{news_page},{newsid}-{news_name}.html', '/index.php?newsid={newsid}&news_page={news_page}'],
				'showfull.print.1' => ['/print:page,{news_page},{newsid}-{news_name}.html', '/index.php?mod=print&news_page={news_page}&newsid={newsid}'],
				'showfull.1' => ['/{newsid}-{news_name}.html', '/index.php?newsid={newsid}']
			]
		],
		1 => [
			'main.page' => ['/page/{cstart}/', '/index.php?cstart={cstart}'],
			'date.day' => ['/{year}/{month}/{day}/', '/index.php?year={year}&month={month}&day={day}'],
			'date.day.page' => ['/{year}/{month}/{day}/page/{cstart}/', '/index.php?year={year}&month={month}&day={day}&cstart={cstart}'],
			'date.month' => ['/{year}/{month}/', '/index.php?year={year}&month={month}'],
			'date.month.page' => ['/{year}/{month}/page/{cstart}/', '/index.php?year={year}&month={month}&cstart={cstart}'],
			'date.year' => ['/{year}/', '/index.php?year={year}'],
			'date.year.page' => ['/{year}/page/{cstart}/', '/index.php?year={year}&cstart={cstart}'],

			'tags.page' => ['/tags/{tag}/page/{cstart}/', '/index.php?do=tags&tag={tag}&cstart={cstart}'],
			'tags' => ['/tags/{tag}/', '/index.php?do=tags&tag={tag}'],
			'tags.all' => ['/tags/', '/index.php?do=tags'],

			'xfsearch.page' => ['/xfsearch/{xf}/page/{cstart}/', '/index.php?do=xfsearch&xf={xf}&cstart={cstart}'],
			'xfsearch' => ['/xfsearch/{xf}/', '/index.php?do=xfsearch&xf={xf}'],

			'user.rss' => ['/user/{user}/rss.xml', '/index.php?mod=rss&subaction=allnews&user={user}'],
			'user.rssdzen' => ['/user/{user}/rssdzen.xml', '/index.php?mod=rss&subaction=allnews&rssmode=dzen&user={user}'],
			'user' => ['/user/{user}/', '/index.php?subaction=userinfo&user={user}'],
			'user.page' => ['/user/{user}/page/{cstart}/', '/index.php?subaction=userinfo&user={user}&cstart={cstart}'],
			'user.news' => ['/user/{user}/news/', '/index.php?subaction=allnews&user={user}'],
			'user.news.page' => ['/user/{user}/news/page/{cstart}/', '/index.php?subaction=allnews&user={user}&cstart={cstart}'],

			'lastnews' => ['/lastnews/', '/index.php?do=lastnews'],
			'lastnews.page' => ['/lastnews/page/{cstart}/', '/index.php?do=lastnews&cstart={cstart}'],

			'catalog.rss' => ['/catalog/{catalog}/rss.xml', '/index.php?mod=rss&catalog={catalog}'],
			'catalog.rssdzen' => ['/catalog/{catalog}/rssdzen.xml', '/index.php?mod=rss&rssmode=dzen&catalog={catalog}'],
			'catalog' => ['/catalog/{catalog}/', '/index.php?catalog={catalog}'],
			'catalog.page' => ['/catalog/{catalog}/page/{cstart}/', '/index.php?catalog={catalog}&cstart={cstart}'],

			'newposts' => ['/newposts/', '/index.php?subaction=newposts'],
			'newposts.page' => ['/newposts/page/{cstart}/', '/index.php?subaction=newposts&cstart={cstart}'],

			'favorites' => ['/favorites/', '/index.php?do=favorites'],
			'favorites.page' => ['/favorites/page/{cstart}/', '/index.php?do=favorites&cstart={cstart}'],

			'rules' => ['/rules.html', '/index.php?do=rules'],
			'statistics' => ['/statistics.html', '/index.php?do=stats'],
			'addnews' => ['/addnews.html', '/index.php?do=addnews'],

			'rss' => ['/rss.xml', '/index.php?mod=rss'],
			'rssdzen' => ['/rssdzen.xml', '/index.php?mod=rss&rssmode=dzen'],
			'rss.category' => ['/{category}/rss.xml', '/index.php?mod=rss&do=cat&category={category}'],
			'rssdzen.category' => ['/{category}/rssdzen.xml', '/index.php?mod=rss&do=cat&rssmode=dzen&category={category}'],

			'category.page' => ['/{category}/page/{cstart}/', '/index.php?do=cat&category={category}&cstart={cstart}'],
			'category' => ['/{category}/', '/index.php?do=cat&category={category}'],

			'static.page' => ['/page,{news_page},{page}.html', '/index.php?do=static&page={page}&news_page={news_page}'],
			'static.print' => ['/print:{page}.html', '/index.php?mod=print&do=static&page={page}'],
			'static' => ['/{page}.html', '/index.php?do=static&page={page}']			
		]
	];

	private static $compile_rules = [
		'find'    => ['{year}', '{month}', '{day}', '{category}', '{news_page}', '{cstart}', '{newsid}', '{xf}', '{news_name}'],
		'replace' => ['{year:\d{4}}', '{month:\d{2}}', '{day:\d{2}}', '{category:[^.]+}', '{news_page:\d+}', '{cstart:\d+}', '{newsid:\d+}', '{xf:.+}', '{news_name:.*}'],
	];

	public static function Init() {
		if (!is_array(self::$rules)) {
			self::LoadRules(self::$rules);
		}
	}

	public static function Route() {
		global $config;

		if (!is_array(self::$rules)) {
			self::LoadRules(self::$rules );
		}

		try {

			$dispatcher = FastRoute\Dispatcher::cachedDispatcher(function ($r) {

				foreach(self::$rules as $key => $value) {
					$r->addRoute('ALL', $value[2], $key);
				}

			}, ['cacheFile' => ENGINE_DIR . '/cache/system/route.rules.php'] );

			$uri = $_SERVER['REQUEST_URI'];
			$script = getSafeScriptName();

			$uri = substr($uri, 0, strpos($uri . '?', '?'));

			if (($pos = strrpos($script, '/')) !== false && $pos > 0) {
				$base = substr($script, 0, $pos);
				if (strncmp($uri, $base, strlen($base)) === 0) {
					$uri = substr($uri, strlen($base));
				}
			}

			if ($uri AND $uri != '/' AND $uri != '/'.basename($script) ) {

				$routeInfo = $dispatcher->dispatch('ALL', $uri);

				switch ($routeInfo[0]) {
					case FastRoute\Dispatcher::NOT_FOUND:
						// ... 404 Not Found
						$_GET = $_REQUEST = array();
						
						$_GET['newsid'] = $_REQUEST['newsid'] = -1;
						$_SERVER['QUERY_STRING'] = 'newsid=-1';

						break;

					case FastRoute\Dispatcher::FOUND:
						self::$rule = $routeInfo[1];
						self::$rulevars = $routeInfo[2];
			
						$real_uri = $uri;
						
						if( self::$rules[self::$rule][0][-1] == '/' AND $uri[-1] != '/') {
							$real_uri .= '/';
						}

						$real_uri = preg_replace('#/+#', '/', $real_uri);

						if ($real_uri != $uri) {

							if ($config['http_home_url'][-1] == '/') {
								$config['http_home_url'] = substr($config['http_home_url'], 0, -1);
							}

							$path = parse_url($config['http_home_url'] . $real_uri, PHP_URL_PATH);

							if ($path) {
								header("HTTP/1.0 301 Moved Permanently");
								header("Location: {$path}");
								die("Redirect");
							}
						}
						
						$real_uri = parse_url(self::$rules[self::$rule][1]);
						$real_uri = $real_uri['query'];

						foreach( self::$rulevars as $key => $value ) {

							if (substr($value, -1, 1) == '/') {
								$value = self::$rulevars[$key] = substr(self::$rulevars[$key], 0, -1);
							}

							$real_uri = str_replace('{' . $key . '}', $value, $real_uri);
							
						}

						$url_params = array();
						parse_str($real_uri, $url_params);

						foreach ($url_params as $key => $value) {
							if( $value == '{'.$key.'}' ) continue;
							
							if (!isset($_POST[$key])) {
								$_GET[$key] = $_REQUEST[$key] = $value;
							} else {
								$_GET[$key] = $value;
							}
						}
						
						$_SERVER['QUERY_STRING'] = $real_uri;
						break;
				}
				
			}

		} catch (Throwable $e) {
			self::$error = $e->getMessage();
		}

	}

	public static function CheckRoutes( $rules = [] ) {

		if (!is_array(self::$rules)) {
			self::LoadRules(self::$rules );
		}
		
		self::$error = null;
		$tmp = self::$rules;

		if( is_array($rules) AND count( $rules ) ) {
			self::$rules = $rules;
		}
		
		try {
			FastRoute\Dispatcher::simpleDispatcher(function ($r) {
				foreach(self::$rules as $key => $value) {
					$r->addRoute('ALL', $value[2], $key);
				}
			});

		} catch (Throwable $e) {
			self::$error = str_replace('for method "ALL"', '', $e->getMessage() );
		}

		self::$rules = $tmp;

		return self::$error;

	}
	
	public static function ClearDomain( $url, $relative = false ){
		$parts = parse_url($url);

		$url = $parts['path'] ?? '';
		
		if (isset($parts['query'])) {
			$url .= '?' . $parts['query'];
		}

		if(!$url) $url = '/';

		if( $relative ) {
			$url = ltrim($url, '/');
		}

		return $url;
	}

	public static function ClearRoot( $url ){
		if (self::$domain AND strpos($url, self::$domain) === 0) {
			$url = substr($url, strlen(self::$domain));
			$url = ltrim($url, '/');
		}

		return $url;
	}

	public static function BuildUrl($key, $params){
		global $config;
		
		if(!is_array($params) ) {
			return '';
		}

		if (!is_array(self::$rules)) {
			self::LoadRules(self::$rules);
		}

		if( !self::$domain ) {

			if (strpos($config['http_home_url'], "//") === 0) self::$domain = isSSL() ? "https:" . $config['http_home_url'] : "http:" . $config['http_home_url'];
			elseif (strpos($config['http_home_url'], "/") === 0) self::$domain = isSSL() ? "https://" . $_SERVER['HTTP_HOST'] . $config['http_home_url'] : "http://" . $_SERVER['HTTP_HOST'] . $config['http_home_url'];
			else self::$domain = $config['http_home_url'];

			if (isset(self::$domain[-1]) AND self::$domain[-1] == '/') {
				self::$domain = substr(self::$domain, 0, -1);
			}

		}

		if( $config['allow_alt_url'] ) {
			$url = isset(self::$rules[$key][0]) ? self::$rules[$key][0] : '';
		} else $url = isset(self::$rules[$key][1]) ? self::$rules[$key][1] : '';

		foreach($params as $key => $value) {
			$url = str_replace('{' . $key . '}', $value, $url);
		}
		
		if (false !== strpos($url, '//')) {
			$url = preg_replace('#/+#', '/', $url);
		}

		return self::$domain.$url;
		
	}

	public static function AddRule($key, $route, $real_url) {
		
		if(!(string)$route OR !(string)$real_url) {
			return false;
		}

		if (!is_array(self::$rules)) {
			self::LoadRules(self::$rules);
		}

		self::$rules['custom.' . $key] = [(string)$route, (string)$real_url];
		
		self::$rules['custom.' . $key][2] = str_replace(self::$compile_rules['find'], self::$compile_rules['replace'], (string)$route);

		if (self::$rules['custom.' . $key][2][-1] == '/') {
			self::$rules['custom.' . $key][2] = substr(self::$rules['custom.' . $key][2], 0, -1) . '[/]';
		}

		self::SaveRules(self::$rules);
		
		return 'custom.' . $key;
	}

	public static function DeleteRule($key) {
		
		if (!is_array(self::$rules)) {
			self::LoadRules(self::$rules);
		}

		unset(self::$rules[$key]);

		self::SaveRules(self::$rules);

	}

	public static function RestoreRule($key) {
		global $config;

		if (!is_array(self::$rules)) {
			self::LoadRules(self::$rules);
		}
		
		if (false !== strpos($key, 'showfull')) {
			$default_rules = self::$default_rules[0][$config['seo_type']];
		} else $default_rules = self::$default_rules[1];

		if( isset($default_rules[$key]) ) {

			self::$rules[$key] = $default_rules[$key];
			self::$rules[$key][2] = str_replace(self::$compile_rules['find'], self::$compile_rules['replace'], self::$rules[$key][0]);

			if (substr(self::$rules[$key][2], -1, 1) == '/') {
				self::$rules[$key][2] = substr(self::$rules[$key][2], 0, -1) . '[/]';
			}

			self::SaveRules(self::$rules);

			return $default_rules[$key];
		}

		return false;
	}

	public static function EditRule($key, $route, $real_url) {
		
		if (!is_array(self::$rules)) {
			self::LoadRules(self::$rules);
		}

		if( isset(self::$rules[$key]) ) {

			self::$rules[$key] = [(string)$route, (string)$real_url];

			self::$rules[$key][2] = str_replace(self::$compile_rules['find'], self::$compile_rules['replace'], (string)$route);

			if (substr(self::$rules[$key][2], -1, 1) == '/') {
				self::$rules[$key][2] = substr(self::$rules[$key][2], 0, -1) . '[/]';
			}

			self::SaveRules(self::$rules);

			return true;
		}

		return false;
	}

	public static function SaveDefaultsRules() {
		global $config;

		self::$rules = self::$default_rules[0][$config['seo_type']] + self::$default_rules[1];

		foreach(self::$rules as $key => $value) {
			self::$rules[$key][2] = str_replace(self::$compile_rules['find'], self::$compile_rules['replace'], $value[0]);
			
			if ( substr(self::$rules[$key][2], -1, 1) == '/' ) {
				self::$rules[$key][2] = substr(self::$rules[$key][2], 0, -1) . '[/]';
			}
		}

		self::SaveRules( self::$rules );
	}

	public static function ChangeRules( $type ) {
		
		if (!is_array(self::$rules)) {
			self::LoadRules(self::$rules);
		}
		
		unset(self::$rules['showfull.page.newscomments']);
		unset(self::$rules['showfull.page.news']);
		unset(self::$rules['showfull.print']);
		unset(self::$rules['showfull']);
		unset(self::$rules['showfull.page.newscomments.1']);
		unset(self::$rules['showfull.page.news.1']);
		unset(self::$rules['showfull.print.1']);
		unset(self::$rules['showfull.1']);

		self::$rules = self::$default_rules[0][$type] + self::$rules;

		foreach(self::$rules as $key => $value) {
			self::$rules[$key][2] = str_replace(self::$compile_rules['find'], self::$compile_rules['replace'], $value[0]);
			
			if ( substr(self::$rules[$key][2], -1, 1) == '/' ) {
				self::$rules[$key][2] = substr(self::$rules[$key][2], 0, -1) . '[/]';
			}
		}

		self::SaveRules( self::$rules );
	}

	private static function LoadRules() {

		self::$rules = @file_get_contents(ENGINE_DIR . '/data/rules.json');
		
		if ( self::$rules !== false ) {
			
			self::$rules = json_decode(self::$rules , true);

			if ( is_array( self::$rules ) ) {

				return self::$rules;
			
			} else {
				self::SaveDefaultsRules();

			}

		} else {
			self::SaveDefaultsRules();
		}

	}

	public static function SaveRules( $rules ) {
		@file_put_contents(ENGINE_DIR . '/data/rules.json', json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
		@chmod(ENGINE_DIR .  '/data/rules.json', 0666);
		@unlink( ENGINE_DIR . '/cache/system/route.rules.php' );
		
		if (function_exists('opcache_reset')) {
			opcache_reset();
		}
	}

}
