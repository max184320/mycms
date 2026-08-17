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
 File: zipextract.class.php
-----------------------------------------------------
 Use: ZIP Extract class
=====================================================
*/

if( !defined( 'DATALIFEENGINE' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

class dle_zip_extract {
	
	private $root = null;
	
	public $zip;

	private $ftp = null;
	private $ssh = null;
	private $sftp = null;
	private $sftpDir = null;

	private $parse_template = null;
	private $created_dirs = array();
	
	public $folder_permission = 0755;
	public $file_permission = 0644;
	
	public $errors_list = array();
	public $skip_index = array();
	public $is_plugin = false;

	public $zip_root = false;
	public $zip_numfiles = 0;
	public $zip_list_with_root = array();
	
	function __construct( $zip_link = false, $template_lang = null ) {
		global $lang;
		
		$this->root = ROOT_DIR.'/';
		
		if($zip_link) {
			$this->zip = new ZipArchive();
			if($this->zip->open( $zip_link, ZIPARCHIVE::CHECKCONS ) !== true) {
				throw new RuntimeException($lang['upgr_f_error_16']);
			}
			
			$this->zip_numfiles = $this->zip->numFiles;	
		}

		if( $template_lang !== null && is_array($template_lang) ) {
			if( count($template_lang) ) {
				$this->parse_template = [
					'find' => array_keys($template_lang),
					'replace' => array_values($template_lang)
				];
			} else $this->parse_template = [];
		}
		
	}
	
	public function FtpConnect( $data ) {
		global $lang;
		
		if ( $data['type'] == 'ssh2' ) {
			
			if( !function_exists('ssh2_connect') ) {
				throw new RuntimeException($lang['upgr_f_error_10']);
			}
			
			$this->ssh = ssh2_connect( $data['server'], $data['port'] );	

			if ( $this->ssh === false ) {
				throw new RuntimeException($lang['upgr_f_error_11'].' ' . $data['server'] . ', '.$lang['upgr_ftp_4'].' ' . $data['port']);
			}

			if ( !@ssh2_auth_password( $this->ssh, $data['username'], $data['password'] ) ) {
				throw new RuntimeException($lang['upgr_f_error_12']);
			}

			$this->sftp = @ssh2_sftp( $this->ssh );

			if ( $this->sftp === false ) {
				throw new Exception($lang['upgr_f_error_13']);
			}
			
			if ( $data['path'] and !@ssh2_sftp_stat( $this->sftp, $data['path'] ) ) {
				throw new RuntimeException( $lang['upgr_f_error_14'].' '.$data['path'] );
			}
			
			$this->sftpDir = ssh2_sftp_realpath( $this->sftp, $data['path'] ) . '/';
			
			$stream = @fopen("ssh2.sftp://".intval($this->sftp).$this->sftpDir."index.php", 'r');
			
			if(!$stream OR  @stripos(stream_get_contents($stream), 'DATALIFEENGINE') === false ) {
				throw new RuntimeException($lang['upgr_f_error_15']);
			}

		} else {

			if ( $data['type'] == 'sslftp' ) {
				$this->ftp = @ftp_ssl_connect( $data['server'], $data['port'], 5 );
			} else {
				$this->ftp = @ftp_connect( $data['server'], $data['port'], 5 );
			}

			if ( $this->ftp === false ) {
				throw new RuntimeException($lang['upgr_f_error_11'].' ' . $data['server'] . ', '.$lang['upgr_ftp_4'].' ' . $data['port']);
			}

			if ( !@ftp_login( $this->ftp, $data['username'], $data['password'] ) ) {
				
				$this->DisconnectFTP();
				
				throw new RuntimeException($lang['upgr_f_error_12']);
			}

			@ftp_pasv( $this->ftp, true );
			
			if ( $data['path'] AND !@ftp_chdir( $this->ftp, $data['path'] ) ) {
				
				$this->DisconnectFTP();
				
				throw new RuntimeException( $lang['upgr_f_error_14'].' '.$data['path'] );
			}
			
			$temp = fopen('php://temp', 'r+');
			
			if (@ftp_fget($this->ftp, $temp, 'index.php', FTP_BINARY, 0)) {
				rewind($temp);
				
				if(stripos(stream_get_contents($temp), 'DATALIFEENGINE') === false ) throw new RuntimeException($lang['upgr_f_error_15']);
			
			} else throw new RuntimeException($lang['upgr_f_error_15']);
			
			
		}
	}
	
    public function DisconnectFTP() {
		
        if ($this->ftp) {
			
			if( $this->ftp !== false ) {
				@ftp_close($this->ftp);
			}
			
			$this->ftp = null;
		}
    }

	public function SetFilesRoot($dir) {

		if (substr($dir, -1) != '/')  $dir = $dir . '/';

		$this->root = $dir;
		$this->created_dirs = array();
	}

	public function SetRootZipArchive($dir) {

		if (!$dir) return;

		$dir = str_replace('\\', '/', $dir);
		$dir = trim($dir, '/');

		if ($dir === '') return;

		$dir = $dir . '/';

		$file_list = array();

		for ($i = 0; $i < $this->zip->numFiles; $i++) {

			$file = $this->zip->statIndex($i);

			if (!$file) continue;

			$file_name = str_replace('\\', '/', $file['name']);

			if (substr($file_name, -1) == '/') continue;

			if (strpos($file_name, $dir) === 0) {
				$file_list[] = $file['index'];
			}
		}

		if (count($file_list)) {
			$this->zip_root = $dir;
			$this->zip_numfiles = count($file_list);
			$this->zip_list_with_root = $file_list;
		}
	}

	public function ExtractZipArchive($offset = 0, $limit = 0) {
		$done = 0;

		$offset = intval($offset);
		$limit = intval($limit);

		if ($offset < 0) $offset = 0;

		if ($offset >= $this->zip_numfiles) {
			return 0;
		}

		if ($limit <= 0) {
			$limit = $this->zip_numfiles - $offset;
		} else {
			$limit = min($limit, $this->zip_numfiles - $offset);
		}

		for ($i = 0; $i < $limit; $i++) {
			$index = $offset + $i;

			$extract_index = false;

			if ($this->zip_root) {

				if (isset($this->zip_list_with_root[$index])) {
					$extract_index = $this->zip_list_with_root[$index];
				}
			} else $extract_index = $index;

			if ($extract_index !== false) {

				$file = $this->zip->statIndex($extract_index);

				if ($file) {
					$this->ExtractFile($extract_index, $file);
					$done++;
				}
			}
		}

		return $done;
	}
	
	public function FixHtaccess() {
		
		$data = $this->ReadFile( ".htaccess" );
		
		if($data) {

			if ( stripos($data, 'RewriteRule . index.php [L]') === false ) {
				$data = <<<HTML
DirectoryIndex index.php

<IfModule mod_rewrite.c>
	RewriteEngine On

	RewriteCond %{REQUEST_FILENAME} !-f
	RewriteRule \.(jpg|jpeg|gif|ico|png|svg|webp|avif|js|css|mp3|ogg|mp4|mkv|avi|zip|rar|woff|woff2)(\?|$) - [L,NC,R=404]

	RewriteRule ^sitemap.xml$ uploads/sitemap.xml [L]
	RewriteRule ^google_news.xml$ uploads/google_news.xml [L]
	RewriteRule ^static_pages.xml$ uploads/static_pages.xml [L]
	RewriteRule ^category_pages.xml$ uploads/category_pages.xml [L]
	RewriteRule ^tags_pages.xml$ uploads/tags_pages.xml [L]
	RewriteRule ^news_pages(\d*?).xml$ uploads/news_pages$1.xml [L]

	RewriteCond %{REQUEST_FILENAME} !-f
	RewriteCond %{REQUEST_FILENAME} !-d
	RewriteRule . index.php [L]
</IfModule>
HTML;
				$this->WriteFile(".htaccess", $data);
			}
		}
		
		if( isset($_SESSION['cronfile']) ) {
			
			$data = $this->ReadFile($_SESSION['cronfile']);
			
			if($data) {
				
				$data = str_replace('$allow_cron = 0;', '$allow_cron = 1;', $data);
				$this->WriteFile( $_SESSION['cronfile'], $data );
				unset($_SESSION['cronfile']);
				
			}
			
		}
		
	}
	private function NormalizeZipPath($path) {

		$path = str_replace('\\', '/', $path);

		if (strpos($path, "\0") !== false) {
			return false;
		}

		if (strpos($path, '://') !== false) {
			return false;
		}

		if (preg_match('#^[a-zA-Z]:#', $path)) {
			return false;
		}

		$path = ltrim($path, '/');
		$path = preg_replace('#/+#', '/', $path);

		$parts = array();

		foreach (explode('/', $path) as $part) {

			if ($part === '' or $part === '.') {
				continue;
			}

			if ($part === '..') {
				return false;
			}

			$parts[] = $part;
		}

		if (!count($parts)) {
			return false;
		}

		return implode('/', $parts);
	}

	private function EnsureDirectory($dir) {
		global $lang;

		if ($dir === '.' or $dir === '') {
			return true;
		}

		$dir = str_replace('\\', '/', $dir);
		$dir = trim($dir, '/');

		if ($dir === '') {
			return true;
		}

		if (isset($this->created_dirs[$dir])) {
			return true;
		}

		$parts = explode('/', $dir);
		$current_dir = '';

		foreach ($parts as $part) {

			if ($part === '' or $part === '.') {
				continue;
			}

			$current_dir = $current_dir ? $current_dir . '/' . $part : $part;

			if (isset($this->created_dirs[$current_dir])) {
				continue;
			}

			if (!is_dir($this->root . $current_dir)) {

				if ($this->sftp) {

					if (!@ssh2_sftp_mkdir($this->sftp, $this->sftpDir . $current_dir)) {
						$this->errors_list[] = array('file' => $current_dir, 'error' => $lang['upgr_f_error_17']);
						return false;
					}
				} elseif ($this->ftp) {

					if (!@ftp_mkdir($this->ftp, $current_dir)) {
						$this->errors_list[] = array('file' => $current_dir, 'error' => $lang['upgr_f_error_17']);
						return false;
					}
				} else {

					if (!@mkdir($this->root . $current_dir, $this->folder_permission)) {
						$this->errors_list[] = array('file' => $current_dir, 'error' => $lang['upgr_f_error_17']);
						return false;
					} else {
						@chmod($this->root . $current_dir, $this->folder_permission);
					}
				}
			}

			$this->created_dirs[$current_dir] = true;
		}

		return true;
	}

	private function CreateTempStreamFromContents($contents, $file) {
		global $lang;

		$expected_size = strlen($contents);

		$stream = @fopen('php://temp', 'r+');

		if ($stream) {

			$written = @fwrite($stream, $contents);

			if ($written !== false && $written == $expected_size && @rewind($stream)) {
				return array(
					'stream' => $stream,
					'tmp_file' => false
				);
			}

			@fclose($stream);
		}

		$tmpFile = @tempnam(ENGINE_DIR . "/cache/system/", 'DLE');

		if (!$tmpFile) {
			$this->errors_list[] = array('file' => $file, 'error' => $lang['upgr_f_error_18']);
			return false;
		}

		$written = @file_put_contents($tmpFile, $contents, LOCK_EX);

		if ($written === false || $written != $expected_size) {
			@unlink($tmpFile);
			$this->errors_list[] = array('file' => $file, 'error' => $lang['upgr_f_error_18']);
			return false;
		}

		$stream = @fopen($tmpFile, 'rb');

		if (!$stream) {
			@unlink($tmpFile);
			$this->errors_list[] = array('file' => $file, 'error' => $lang['upgr_f_error_18']);
			return false;
		}

		return array(
			'stream' => $stream,
			'tmp_file' => $tmpFile
		);
	}

	private function ExtractFile($index, $file = false) {
		global $config, $lang;

		if (!$file) {
			$file = $this->zip->statIndex($index);
		}

		if (!$file) return;

		$zip_file_name = $file['name'];

		$file['name'] = str_replace('\\', '/', $file['name']);

		if (substr($file['name'], -1) == '/') return;
		
		if( count($this->skip_index) AND in_array($index, $this->skip_index) ) return;

		if ($this->is_plugin) {

			$file['name'] = str_ireplace('{THEME}', $config['skin'], $file['name']);
		}

		if ($this->zip_root) {

			if (strpos($file['name'], $this->zip_root) !== 0) {
				return;
			}

			$file['name'] = substr($file['name'], strlen($this->zip_root));
		}

		$file['name'] = $this->NormalizeZipPath($file['name']);

		if ($file['name'] === false) {
			$this->errors_list[] = array('file' => $zip_file_name, 'error' => $lang['upgr_f_error_18']);
			return;
		}

		if ($file['name'] == ".htaccess" && $this->parse_template === null) return;

		if ($file['name'] == "admin.php" and $file['name'] != $config['admin_path'] and $config['admin_path']) {
			$file['name'] = $config['admin_path'];
		}

		if ($file['name'] == "cron.php" and isset($_SESSION['cronfile']) and $file['name'] != $_SESSION['cronfile']) {
			$file['name'] = $_SESSION['cronfile'];
		}

		$file['name'] = $this->NormalizeZipPath($file['name']);

		if ($file['name'] === false) {
			$this->errors_list[] = array('file' => $zip_file_name, 'error' => $lang['upgr_f_error_18']);
			return;
		}

		$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

		$need_parse = false;

		if ($this->parse_template !== null && is_array($this->parse_template)) {
			if ($extension == 'tpl' || $extension == 'json') {
				$need_parse = true;
			}
		}

		if (!$this->EnsureDirectory(dirname($file['name']))) {
			return;
		}

		if ($need_parse) {

			$contents = $this->zip->getFromIndex($index);

			if ($contents === false) {
				$this->errors_list[] = array('file' => $file['name'], 'error' => $lang['upgr_f_error_18']);
				return;
			}

			if (count($this->parse_template)) {
				$contents = str_replace($this->parse_template['find'], $this->parse_template['replace'], $contents);
			} else {
				$contents = str_replace(
					array('{{website_url}}', '{{direction}}', '{{rtl_prefix}}', '{{', '}}'),
					array('dle-news.com', 'ltr', '', '', ''),
					$contents
				);
			}

			$temp_stream = $this->CreateTempStreamFromContents($contents, $file['name']);

			if (!$temp_stream) {
				return;
			}

			$this->WriteFileFromStream($file['name'], $temp_stream['stream'], strlen($contents));

			@fclose($temp_stream['stream']);

			if ($temp_stream['tmp_file']) {
				@unlink($temp_stream['tmp_file']);
			}

		} else {

			if ($this->ftp) {

				for ($attempt = 0; $attempt < 2; $attempt++) {

					$stream = $this->GetZipStream($index, $zip_file_name);

					if (!$stream) {
						$this->errors_list[] = array('file' => $file['name'], 'error' => $lang['upgr_f_error_18']);
						return;
					}

					$result = @ftp_fput($this->ftp, $file['name'], $stream, FTP_BINARY);

					fclose($stream);

					if ($result) {
						return;
					}

					if ($attempt == 0) {
						@ftp_chmod($this->ftp, $this->file_permission, $file['name']);
					}
				}

				$this->errors_list[] = array('file' => $file['name'], 'error' => $lang['upgr_f_error_18']);
				return;
			}

			$stream = $this->GetZipStream($index, $zip_file_name);

			if (!$stream) {
				$this->errors_list[] = array('file' => $file['name'], 'error' => $lang['upgr_f_error_18']);
				return;
			}

			$this->WriteFileFromStream($file['name'], $stream, isset($file['size']) ? $file['size'] : null);

			fclose($stream);
		}
		
	}

	private function ReadFile( $file ) {
		
		$data = '';
		
		if ( $this->sftp ) {
			
			$temp = @fopen("ssh2.sftp://".intval($this->sftp).$this->sftpDir.$file, 'r');
			
			if($temp) {
				$data = stream_get_contents($temp);
				@fclose($temp);
			}
			
		} elseif( $this->ftp ) {
			
			$temp = fopen('php://temp', 'r+');
			
			if (@ftp_fget($this->ftp, $temp, $file, FTP_BINARY, 0)) {
				rewind($temp);
				$data = stream_get_contents($temp);
				@fclose($temp);
			}
			
		} else {
			
			$data = file_get_contents($this->root.$file);
			
		}
		
		return $data;

	}
	
	private function WriteFile( $file, $contents ) {
		global $lang;

		if ( $this->sftp OR $this->ftp ) {
			
			$tmpFile = tempnam( ENGINE_DIR . "/cache/system/", 'DLE' );
			file_put_contents( $tmpFile, $contents );
			
			if ( $this->sftp ) {
				
				if( !@ssh2_scp_send( $this->ssh, $tmpFile, $this->sftpDir . $file ) ) {
					$this->errors_list[] = array( 'file' => $file, 'error' => $lang['upgr_f_error_18'] );
				}
				
			} else {
				
				if( !@ftp_put( $this->ftp, $file, $tmpFile, FTP_BINARY ) ) {
					
					@ftp_chmod($this->ftp, $this->file_permission, $file);
					
					if( !@ftp_put( $this->ftp, $file, $tmpFile, FTP_BINARY ) ) {
						$this->errors_list[] = array( 'file' => $file, 'error' => $lang['upgr_f_error_18'] );
					}
				}
				
			}
			
			@unlink( $tmpFile );
			
		} else {
			
			if( @file_exists( $this->root.$file ) AND !@is_writable( $this->root.$file ) ) {
				@chmod( $this->root.$file, $this->file_permission );
			}
			
			$fh = @fopen( $this->root.$file, 'w+b' );
			
			if ( $fh !== false ) {

				if ( @fwrite( $fh, $contents ) !== false ) {
					
					@chmod( $this->root.$file, $this->file_permission );
					
				} else $this->errors_list[] = array( 'file' => $file, 'error' => $lang['upgr_f_error_18'] );
				
				@fclose($fh);
				
			} else $this->errors_list[] = array( 'file' => $file, 'error' => $lang['upgr_f_error_18'] );
			
		}
		
	}

	private function GetZipStream($index, $file_name) {

		if (method_exists($this->zip, 'getStreamIndex')) {
			return $this->zip->getStreamIndex($index);
		}

		return $this->zip->getStream($file_name);
	}

	private function WriteFileFromStream($file, $stream, $expected_size = null) {
		global $lang;

		if (!$stream) {
			$this->errors_list[] = array('file' => $file, 'error' => $lang['upgr_f_error_18']);
			return false;
		}

		if ($this->sftp) {

			$out = @fopen("ssh2.sftp://" . intval($this->sftp) . $this->sftpDir . $file, 'w+b');

			if (!$out) {
				$this->errors_list[] = array('file' => $file, 'error' => $lang['upgr_f_error_18']);
				return false;
			}

			$written = @stream_copy_to_stream($stream, $out);

			@fclose($out);

			if ($written === false || ($expected_size !== null && $written != $expected_size)) {
				$this->errors_list[] = array('file' => $file, 'error' => $lang['upgr_f_error_18']);
				return false;
			}

			return true;
		} elseif ($this->ftp) {

			if (!@ftp_fput($this->ftp, $file, $stream, FTP_BINARY)) {

				@ftp_chmod($this->ftp, $this->file_permission, $file);

				if (!@rewind($stream)) {
					$this->errors_list[] = array('file' => $file, 'error' => $lang['upgr_f_error_18']);
					return false;
				}

				if (!@ftp_fput($this->ftp, $file, $stream, FTP_BINARY)) {
					$this->errors_list[] = array('file' => $file, 'error' => $lang['upgr_f_error_18']);
					return false;
				}
			}

			return true;
		} else {

			if (@file_exists($this->root . $file) and !@is_writable($this->root . $file)) {
				@chmod($this->root . $file, $this->file_permission);
			}

			$out = @fopen($this->root . $file, 'w+b');

			if (!$out) {
				$this->errors_list[] = array('file' => $file, 'error' => $lang['upgr_f_error_18']);
				return false;
			}

			$written = @stream_copy_to_stream($stream, $out);

			@fclose($out);

			if ($written === false || ($expected_size !== null && $written != $expected_size)) {
				$this->errors_list[] = array('file' => $file, 'error' => $lang['upgr_f_error_18']);
				return false;
			}

			@chmod($this->root . $file, $this->file_permission);

			return true;
		}
	}

}
