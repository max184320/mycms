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
 File: geo.class.php
-----------------------------------------------------
 Use: Get Country class
=====================================================
*/

if (!defined('DATALIFEENGINE')) {
	header("HTTP/1.1 403 Forbidden");
	header('Location: ../../../');
	die("Hacking attempt!");
}

abstract class DLECountry {
	private static $country = null;
	private static $last_check_ip = null;
	
	public static $info = array();

	public static function Get($ip = null) {
		global $_IP, $config;

		if( $ip === null ) {

			if(isset($_IP) && $_IP) $ip = $_IP;
			else $ip = get_ip();
			
		}
		
		if (!is_string($ip) || !$ip) {
			self::$country = 'UNKNOWN';
			return self::$country;
		}
		
		if( self::$country !== null && self::$last_check_ip == $ip ) {
			return self::$country;
		}
		
		self::$last_check_ip = $ip;
		self::$country = 'UNKNOWN';
		self::$info = array();
		
		if( $config['use_cloudflare_country'] && isset($_SERVER['HTTP_CF_IPCOUNTRY']) && $_SERVER['HTTP_CF_IPCOUNTRY'] ) {
			self::$country = strtoupper($_SERVER['HTTP_CF_IPCOUNTRY']);
			return self::$country;
		}

		try {
			$db = new \IP2Location\Database(ENGINE_DIR . '/classes/geoip/geo.base.dat');

			$records = $db->lookup($ip);
			
			if( isset($records['countryCode']) && $records['countryCode'] != '-') {
				self::$country = strtoupper($records['countryCode']);
				self::$info = $records;
			}
		
		} catch (Throwable $e) {
			self::$country = 'UNKNOWN';
		}
		
		unset($db);
		unset($records);

		return self::$country;
	}

	public static function Check( $country_list = '', $ip = null) {
		if( !is_string($country_list) ) return false;
		
		if ($ip === null) $country = self::Get(); else $country = self::Get($ip);
		
		if (!is_string($country) || !$country) return false;

		$country_list = strtoupper(trim($country_list));
		$country_list = array_map( 'trim', explode( ",", $country_list ) );

		if( in_array($country, $country_list) ) {
			return true;
		} else {
			return false;
		}
	}
	
}
