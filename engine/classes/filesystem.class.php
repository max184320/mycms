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
 File: filesystem.class.php
-----------------------------------------------------
 Use: DLE Files System
=====================================================
*/

use DleFilesystem\Filesystem;
use DleFilesystem\FileAttributes;
use DleFilesystem\DirectoryAttributes;
use DleFilesystem\Adapters\LocalAdapter;
use DleFilesystem\Adapters\FtpAdapter;
use DleFilesystem\Adapters\SftpAdapter;
use DleFilesystem\Adapters\S3Adapter;
use DleFilesystem\Adapters\WebDAVAdapter;
use DleFilesystem\Visibility\PortableVisibilityConverter;
use DleFilesystem\MimeTypeDetection\ExtensionMimeTypeDetector;

if (!defined('DATALIFEENGINE')) {
	header("HTTP/1.1 403 Forbidden");
	header('Location: ../../');
	die("Hacking attempt!");
}

include_once(DLEPlugins::Check(ENGINE_DIR . '/classes/filesystem/filesystem.php'));

abstract class DLEFiles {

	private static $root = null;

	private static $local_on_remote_errors = null;
	private static $run_force_local = false;
	private static $base_local_url = '';
	
	public static $driver = null;	
	public static $error  = null;
	public static $remote_error = null;

	private static $storages = null;
	private static $filesystem = array();
	private static $storages_list = null;
	private static $mime_detector = null;

	public static function init( $driver = null, $local_on_remote_errors = false, $root = null ) {
		global $config, $lang;

		self::$error = self::$remote_error = null;
		
		if ( defined('DLE_PHP_MIN') AND !DLE_PHP_MIN ) {
			$lang['stat_phperror'] = str_replace('{minversion}', DLE_PHP_MIN_VERSION, $lang['stat_phperror']);
			$lang['stat_phperror'] = str_replace('{version}', PHP_VERSION, $lang['stat_phperror']);
			
			self::error($lang['stat_phperror']);
			return false;
		}

		if( !is_array( self::$storages ) ) {
			self::$storages = self::loadStorages();
		}

		if( is_null( $root ) ) {
			
			self::$root = ROOT_DIR.'/uploads/';
			self::$base_local_url = $config['http_home_url'] . 'uploads/';
			
		} else {
			
			$root = self::normalize_root_path( $root );
			
			if( $root ) {
				self::$root = ROOT_DIR.'/'. $root .'/';
				self::$base_local_url = $config['http_home_url'] . $root .'/';
			} else {
				self::$root = ROOT_DIR.'/';
				self::$base_local_url = $config['http_home_url'];
			}
			
		}
		
		if( is_null( $driver ) ) {

			self::$driver = self::$storages['default'];
			
		} elseif ( $driver ) {

			$driver = intval($driver);
			if( isset( self::$storages[$driver] ) ) self::$driver = $driver;
			else self::$driver = self::$storages['default'];
			
		}
			
		if( self::$driver > 0 AND $local_on_remote_errors ) {
			self::$local_on_remote_errors = true;
		}

		if( !isset( self::$filesystem[0] ) ) {

			try {

				$visibilityConverter = PortableVisibilityConverter::fromArray([
					'file' => [
						'public' => 0666,
						'private' => 0644
					],
					'dir' => [
						'public' => 0777,
						'private' => 0755
					]
				], "public");

				$adapter = new LocalAdapter(self::$root, $visibilityConverter, LOCK_EX, LocalAdapter::DISALLOW_LINKS);

				self::$filesystem[0] = new Filesystem($adapter, ['public_url' => self::$base_local_url, 'directory_visibility' => "public", 'visibility' => "public"]);
			} catch (Throwable $e) {
				self::error($e->getMessage());
				return false;
			}

		}

		if( self::$driver > 0  AND isset( self::$storages[self::$driver] )  ) {

			if( !isset(self::$filesystem[self::$driver]) ) {
				$adapter_info = self::$storages[self::$driver];

				if (!in_array($adapter_info['accesstype'], array("public", "private"))) {
					$adapter_info['accesstype'] = "public";
				}

				$visibilityConverter = PortableVisibilityConverter::fromArray([
					'file' => [
						'public' => 0666,
						'private' => 0644
					],
					'dir' => [
						'public' => 0777,
						'private' => 0755
					]
				], $adapter_info['accesstype']);


				try {

					$adapter_info['path'] = trim($adapter_info['path']);

					if ($adapter_info['path'] and  $adapter_info['type'] == '1' or $adapter_info['type'] == '2') {

						if (!$adapter_info['path']) $adapter_info['path'] = '/';
						else $adapter_info['path'] = '/' . trim($adapter_info['path'], '\\/') . '/';
					
					} elseif( $adapter_info['path'] ) {
						
						$adapter_info['path'] = trim($adapter_info['path'], '\\/');
					}

					if ($adapter_info['type'] == '1') {

						$adapter = FtpAdapter::create([
							'host' => $adapter_info['connect_url'],
							'port' => intval($adapter_info['connect_port']),
							'root' => $adapter_info['path'],
							'username' => $adapter_info['username'],
							'password' => $adapter_info['password'],
							'timeout' => 120
						], $visibilityConverter);
					} elseif ($adapter_info['type'] == '2') {

						$adapter = SftpAdapter::create([
							'host' => $adapter_info['connect_url'],
							'username' => $adapter_info['username'],
							'password' => $adapter_info['password'],
							'port' => intval($adapter_info['connect_port']),
							'useAgent' => false,
							'timeout' => 120,
							'maxTries' => 0,
							'root' => $adapter_info['path'],
						], $visibilityConverter);
					} elseif ($adapter_info['type'] == '3') {

						$clientoptions = [];

						if (trim($adapter_info['client_key']) and trim($adapter_info['secret_key'])) {

							$clientoptions['accessKeyId'] = trim($adapter_info['client_key']);
							$clientoptions['accessKeySecret'] = trim($adapter_info['secret_key']);
						}

						if (trim($adapter_info['region'])) {
							$clientoptions['region'] = trim($adapter_info['region']);
						}

						$clientoptions['sharedCredentialsFile'] = '';
						$clientoptions['sharedConfigFile'] = '';
						$clientoptions['connectTimeout'] = 15;
						$clientoptions['timeout'] = 300;

						$adapter = S3Adapter::create($clientoptions, $adapter_info['bucket'], $adapter_info['path'], $adapter_info['accesstype']);
					} elseif ($adapter_info['type'] == '4' or $adapter_info['type'] == '5') {

						if ($adapter_info['type'] == '4') {
							$clientoptions = ['endpoint' => 'https://storage.yandexcloud.net'];
						} else {
							$clientoptions = ['endpoint' => $adapter_info['connect_url']];
						}

						if (trim($adapter_info['client_key']) and trim($adapter_info['secret_key'])) {

							$clientoptions['accessKeyId'] = trim($adapter_info['client_key']);
							$clientoptions['accessKeySecret'] = trim($adapter_info['secret_key']);
						}

						if (trim($adapter_info['region'])) {
							$clientoptions['region'] = trim($adapter_info['region']);
						}

						$clientoptions['sharedCredentialsFile'] = '';
						$clientoptions['sharedConfigFile'] = '';
						$clientoptions['connectTimeout'] = 15;
						$clientoptions['timeout'] = 300;


						$adapter = S3Adapter::create($clientoptions, $adapter_info['bucket'], $adapter_info['path'], $adapter_info['accesstype']);
					} elseif ($adapter_info['type'] == '6') {

						$adapter = WebDAVAdapter::create([
							'baseUri' => trim($adapter_info['connect_url']),
							'userName' => trim($adapter_info['username']),
							'password' => trim($adapter_info['password']),
							'connectTimeout' => 15,
							'timeout' => 300,
						]);
					
					} else {

						self::$driver = 0;
						return false;
					}
					
					$adapter_config = ['directory_visibility' => $adapter_info['accesstype'], 'visibility' => $adapter_info['accesstype']];

					if( trim($adapter_info['http_url']) ) {
						$adapter_config['public_url'] = $adapter_info['http_url'];
					}

					self::$filesystem[self::$driver] = new Filesystem($adapter, $adapter_config );

				} catch (Throwable $e) {
					self::error($e->getMessage());
					return false;
				}
			}
			
		} else self::$driver = 0;

		return true;
	
	}
	
	public static function Read( $path, $driver = null ) {
		
		if( is_null( self::$driver ) ) {
			DLEFiles::init();
		}

		if( is_null( $driver ) ) $driver = self::$driver;

		if (!isset(self::$filesystem[$driver]) OR !is_object(self::$filesystem[$driver])) {
			DLEFiles::init($driver);
			
			if (!is_object(self::$filesystem[$driver])) {
				$driver = self::$storages['default'];
			}

		}
		
		$path = self::normalize_path( $path );

		if( is_object(self::$filesystem[$driver]) ) {
			
			try {
				
				return self::$filesystem[$driver]->read($path);
			
			} catch(Throwable $e) {
				self::error( $e->getMessage() );
			}
		
		}
		
		return false;
		
	}
	
	public static function Save( $path, $contents, $driver = null ) {

		if (is_null(self::$driver)) {
			DLEFiles::init();
		}

		if (is_null($driver)) $driver = self::$driver;

		if (!isset(self::$filesystem[$driver]) OR !is_object(self::$filesystem[$driver])) {

			DLEFiles::init($driver);

			if (!is_object(self::$filesystem[$driver])) {
				$driver = self::$storages['default'];
			}

		}
		
		$path = self::normalize_path( $path );
		
		if( is_object(self::$filesystem[$driver]) ) {
			
			try {

				self::$filesystem[$driver]->write($path, $contents);
				return true;
			
			} catch(Throwable $e) {
				self::error( $e->getMessage() );
			}
		
		}
		
		if( self::$run_force_local ) {
			
			try {

				self::$filesystem[0]->write($path, $contents);
				return true;
			
			} catch(Throwable $e) {
				self::error( $e->getMessage() );
			}

			self::$run_force_local = false;
		
		}
		
		return false;
		
	}

	public static function FileExists( $path, $driver = null ) {

		if (is_null(self::$driver)) {
			DLEFiles::init();
		}

		if (is_null($driver)) $driver = self::$driver;

		if (!isset(self::$filesystem[$driver]) OR !is_object(self::$filesystem[$driver])) {
			DLEFiles::init($driver);

			if (!is_object(self::$filesystem[$driver])) {
				$driver = self::$storages['default'];
			}

		}
		
		$path = self::normalize_path( $path );

		if( is_object(self::$filesystem[$driver]) ) {
			
			try {
				
				return self::$filesystem[$driver]->fileExists($path);
			
			} catch(Throwable $e) {
				self::error( $e->getMessage() );
			}
		
		}
		
		return false;
		
	}

	public static function Size( $path, $driver = null ) {

		if (is_null(self::$driver)) {
			DLEFiles::init();
		}

		if (is_null($driver)) $driver = self::$driver;

		if (!isset(self::$filesystem[$driver]) OR !is_object(self::$filesystem[$driver])) {
			DLEFiles::init($driver);

			if (!is_object(self::$filesystem[$driver])) {
				$driver = self::$storages['default'];
			}

		}
		
		$path = self::normalize_path( $path );

		if( is_object(self::$filesystem[$driver]) ) {
			
			try {
				
				return self::$filesystem[$driver]->fileSize($path);
			
			} catch(Throwable $e) {
				self::error( $e->getMessage() );
			}
		
		}
		
		return 0;
		
	}

	public static function Checksum($path, $driver = null)
	{

		if (is_null(self::$driver)) {
			DLEFiles::init();
		}

		if (is_null($driver)) $driver = self::$driver;

		if ( !is_object(self::$filesystem[$driver]) ) {
			DLEFiles::init($driver);
		}

		$path = self::normalize_path($path);

		if ( is_object(self::$filesystem[$driver]) ) {

			try {

				return self::$filesystem[$driver]->checksum($path);

			} catch (Throwable $e) {
				self::error($e->getMessage());
			}
		}

		return '';
	}

	public static function Delete( $path, $driver = null ) {

		if (is_null(self::$driver)) {
			DLEFiles::init();
		}

		if (is_null($driver)) $driver = self::$driver;

		if (!isset(self::$filesystem[$driver]) OR !is_object(self::$filesystem[$driver])) {
			DLEFiles::init($driver);

			if (!is_object(self::$filesystem[$driver])) {
				$driver = self::$storages['default'];
			}

		}
		
		$path = self::normalize_path( $path );

		if( is_object(self::$filesystem[$driver]) ) {
			
			try {
				
				self::$filesystem[$driver]->delete($path);
				return true;
			
			} catch(Throwable $e) {
				self::error( $e->getMessage() );
			}
		
		}
		
		return false;
		
	}
	
	public static function ReadStream( $path, $driver = null ) {

		if (is_null(self::$driver)) {
			DLEFiles::init();
		}

		if (is_null($driver)) $driver = self::$driver;

		if (!isset(self::$filesystem[$driver]) OR !is_object(self::$filesystem[$driver])) {
			DLEFiles::init($driver);

			if (!is_object(self::$filesystem[$driver])) {
				$driver = self::$storages['default'];
			}

		}
		
		$path = self::normalize_path( $path );
		
		if( is_object(self::$filesystem[$driver]) ) {
			
			try {
				
				return self::$filesystem[$driver]->readStream($path);
			
			} catch(Throwable $e) {
				self::error( $e->getMessage() );
			}
		
		}
		
		return false;
		
	}
	
	public static function WriteStream( $path, $stream, $driver = null ) {

		if (is_null(self::$driver)) {
			DLEFiles::init();
		}

		if (is_null($driver)) $driver = self::$driver;

		if (!isset(self::$filesystem[$driver]) OR !is_object(self::$filesystem[$driver])) {
			DLEFiles::init($driver);

			if (!is_object(self::$filesystem[$driver])) {
				$driver = self::$storages['default'];
			}

		}
		
		$path = self::normalize_path( $path );
		
		if( is_object(self::$filesystem[$driver]) ) {
			
			try {

				self::$filesystem[$driver]->writeStream($path, $stream);
				return true;
			
			} catch(Throwable $e) {
				self::error( $e->getMessage() );
			}
		
		}
		
		if( self::$run_force_local ) {
			
			try {

				self::$filesystem[0]->writeStream($path, $stream);
				return true;
			
			} catch(Throwable $e) {
				self::error( $e->getMessage() );
			}

			self::$run_force_local = false;
		
		}
		
		return false;
		
	}
	
	public static function Rename( $source, $destination, $driver = null ) {

		if (is_null(self::$driver)) {
			DLEFiles::init();
		}

		if (is_null($driver)) $driver = self::$driver;

		if (!isset(self::$filesystem[$driver]) OR !is_object(self::$filesystem[$driver])) {
			DLEFiles::init($driver);

			if (!is_object(self::$filesystem[$driver])) {
				$driver = self::$storages['default'];
			}

		}
		
		$source = self::normalize_path( $source );
		$destination = self::normalize_path( $destination );
		
		if( is_object(self::$filesystem[$driver]) ) {
			
			try {

				self::$filesystem[$driver]->move($source, $destination);
				return true;
			
			} catch(Throwable $e) {
				self::error( $e->getMessage() );
			}
		
		}
		
		return false;
		
	}
	
	public static function MimeType( $path ) {
		
		$path = self::normalize_path( $path );
		
		try {
			if( !self::$mime_detector instanceof ExtensionMimeTypeDetector ) {
				self::$mime_detector = new ExtensionMimeTypeDetector();
			}

			return self::$mime_detector->detectMimeTypeFromPath($path);
		
		} catch(Throwable $e) {
			self::error( $e->getMessage() );
		}
		
		return false;
		
	}
	
	public static function ListDirectory( $path, $allowed_ext = null, $driver = null, $recursive = false ) {

		if (is_null(self::$driver)) {
			DLEFiles::init();
		}

		if (is_null($driver)) $driver = self::$driver;

		if (!isset(self::$filesystem[$driver]) OR !is_object(self::$filesystem[$driver])) {
			DLEFiles::init($driver);

			if (!is_object(self::$filesystem[$driver])) {
				$driver = self::$storages['default'];
			}
		}
		
		$path = self::normalize_path( $path );
		$array = array('dirs' => array(), 'files' => array());

		if( is_object(self::$filesystem[$driver]) ) {
			
			try {

				foreach (self::$filesystem[$driver]->listContents($path, $recursive) as $item) {
					
					if( $path == $item->path() ) continue;
					
					$path_info = $item->path();
					$name = basename($path_info);
					
					if ($item instanceof FileAttributes) {
						
						if( is_array( $allowed_ext ) ) {
							$extension_pos = strrpos($name, '.');
							$ext = $extension_pos !== false ? strtolower((string)substr($name, $extension_pos + 1)) : '';
							if(!$ext OR !in_array( $ext, $allowed_ext )) continue;
						}
						
						$array['files'][] = array('path' => $path_info, 'name' => $name, 'size' => $item->fileSize() );
					
					} elseif ($item instanceof DirectoryAttributes) {

						$array['dirs'][] = array('path' => $path_info, 'name' => $name );

					}
				}
				
			} catch(Throwable $e) {
				self::error( $e->getMessage() );
			}
		
		}
		
		if( count($array['dirs']) > 1 ) {
			usort($array['dirs'], static function($left, $right) {
				return $left['path'] <=> $right['path'];
			});
		}
		
		if( count($array['files']) > 1 ) {
			usort($array['files'], static function($left, $right) {
				return $left['path'] <=> $right['path'];
			});
		}
	
		return $array;
		
	}

	public static function DeleteDirectory( $path, $driver = null ) {

		if (is_null(self::$driver)) {
			DLEFiles::init();
		}

		if (is_null($driver)) $driver = self::$driver;

		if (!isset(self::$filesystem[$driver]) OR !is_object(self::$filesystem[$driver])) {
			DLEFiles::init($driver);

			if (!is_object(self::$filesystem[$driver])) {
				$driver = self::$storages['default'];
			}
		}
		
		$path = self::normalize_path( $path );

		if( is_object(self::$filesystem[$driver]) ) {
			
			try {
				
				self::$filesystem[$driver]->deleteDirectory($path);
				return true;
			
			} catch(Throwable $e) {
				self::error( $e->getMessage() );
			}
		
		}
		
		return false;
		
	}
	
	public static function CreateDirectory( $path, $driver = null ) {

		if (is_null(self::$driver)) {
			DLEFiles::init();
		}

		if (is_null($driver)) $driver = self::$driver;

		if (!isset(self::$filesystem[$driver]) OR !is_object(self::$filesystem[$driver])) {
			DLEFiles::init($driver);

			if (!is_object(self::$filesystem[$driver])) {
				$driver = self::$storages['default'];
			}
			
		}
		
		$path = self::normalize_path( $path );

		if( is_object(self::$filesystem[$driver]) ) {
			
			try {
				
				self::$filesystem[$driver]->createDirectory($path);
				return true;
			
			} catch(Throwable $e) {
				self::error( $e->getMessage() );
			}
		
		}
		
		return false;
		
	}

	public static function GetBaseURL( $driver = null ) {

		if (is_null(self::$driver)) {
			DLEFiles::init();
		}

		if (is_null($driver)) $driver = self::$driver;

		if (!isset(self::$filesystem[$driver]) OR !is_object(self::$filesystem[$driver])) {
			DLEFiles::init($driver);

			if (!is_object(self::$filesystem[$driver])) {
				$driver = self::$storages['default'];
			}
		}

		if (is_object(self::$filesystem[$driver])) {

			try {

				return self::$filesystem[$driver]->publicUrl('');

			} catch (Throwable $e) {
				
				if( $driver ) {

					return isset(self::$storages[$driver]['http_url']) ? self::$storages[$driver]['http_url'] : '';

				} else {

					return self::$base_local_url;

				}		

			}
		}

		return '';

	}

	private static function normalize_path( $path ) {
	
		$path = str_replace(array('/', '\\'), '/', trim((string)$path));
		
		if( !$path ) return '';
		
		if( is_null( self::$root ) ) {
			$root = ROOT_DIR.'/';
		} else {
			$root = self::$root;
		}
		
		if(stripos ($path, $root) === 0) {
			$path = str_ireplace($root, '', $path);
		}
		
		return ltrim($path, '/');
	
	}

	private static function normalize_root_path( $path ) {
	
		$path = trim(str_replace(chr(0), '', (string)$path));
		$path = str_replace(array('/', '\\'), '/', $path);

		if( !$path ) return '';
		
		if (preg_match('#\p{C}+#u', $path)) {
			return '';
		}
	
		$path_parts = pathinfo( $path );

		$filename = $path_parts['basename'];
		
		$parts = array_filter(explode('/', $path_parts['dirname']), 'strlen');
		
		$absolutes = array();
		
		foreach ($parts as $part) {
			$part = trim($part);
			
			if ('.' == $part OR '..' == $part OR !$part) continue;
			
			$absolutes[] = $part;
		}
	
		$path = implode('/', $absolutes);
	
		if ( $path ) {
			$path = $path.'/';
		}
		
		if( $filename ) {
			$path .= $filename;
		}
		
		return trim($path, '/');
	
	}
	
	private static function error( $message ) {
		
		$message = str_ireplace( ROOT_DIR, '', $message );
		
		if( self::$driver > 0 AND self::$local_on_remote_errors) {
			
			self::$driver = 0;
			self::$remote_error = $message;
			self::$run_force_local = true;

		} else {
			
			self::$error = $message;
			
		}
		
	}

	public static function getStorages() {

		if (is_null(self::$storages_list)) {
			self::$storages = self::loadStorages();
		}

		return self::$storages_list;

	}

	public static function getDefaultStorage() {

		if (!is_array(self::$storages)) {
			self::$storages = self::loadStorages();
		}

		return self::$storages['default'];
	}

	private static function loadStorages() {
		global $db;

		if ( file_exists( ENGINE_DIR . '/cache/system/storages.php' ) ) {
			include_once ( ENGINE_DIR . '/cache/system/storages.php' );
			
			if( isset($storages) ) {
				$storages = json_decode($storages, true);
				if( !is_array($storages)) unset($storages);
			}

		}

		if ( !isset($storages) ) {
			$storages = array( 'default' => 0) ;

			$db->query("SELECT * FROM " . PREFIX . "_storage WHERE `enabled`='1' ORDER BY posi ASC, id DESC");

			while ($row = $db->get_row()) {

				$storages[$row['id']] = array();
				
				if( $row['default_storage'] ) {
					$storages['default'] = $row['id'];					
				}

				foreach ($row as $key => $value) {
					$storages[$row['id']][$key] = stripslashes($value);
				}
			}

			$db->free();

			$save_data = json_encode($storages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$save_data = str_replace("'", "\'", $save_data);
			
			$save_data = "<?php \n\n//Storages Configurations\n\n\$storages = '" . $save_data . "';\n\n?>";

			file_put_contents(ENGINE_DIR . '/cache/system/storages.php', $save_data, LOCK_EX);
			@chmod(ENGINE_DIR . '/cache/system/storages.php', 0666);

		}

		self::$storages_list = array();

		foreach ($storages as $value) {
			if ( isset( $value['id'] ) ) {
				self::$storages_list[$value['id']] = $value['name'];
			}
		}

		return $storages;

	}

	public static function FindDriver( $url ) {
		
		static $storages_list = null;

		if (!is_array(self::$storages)) {
			self::$storages = self::loadStorages();
		}

		if( $storages_list === null ) {
			$storages_list = self::$storages;
			
			uasort($storages_list, function ($a, $b) {

				$lenA = (is_array($a) && isset($a['http_url'])) ? strlen($a['http_url']) : 0;
				$lenB = (is_array($b) && isset($b['http_url'])) ? strlen($b['http_url']) : 0;

				return $lenB <=> $lenA;
			});
		}

		foreach ($storages_list as $value) {
			if ( isset( $value['id'] ) ) {
				if (isset($value['http_url']) AND stripos($url, $value['http_url']) === 0) {
					return $value['id'];
				}	
			}

		}

		return 0;
	}

}
