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
 File: download.class.php
-----------------------------------------------------
 Use: Download files
=====================================================
*/

if( !defined( 'DATALIFEENGINE' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

class download {

	public $properties = array();

	public $range = 0;
	public $range_end = 0;
	public $is_range_request = false;
	
	function __construct($path, $name, $driver) {

		DLEFiles::init();
			
		if ( !DLEFiles::FileExists( $path, $driver ) ) {
			header( "HTTP/1.1 403 Forbidden" );
			die ( "The file was not found on the server" );
		}
		
		$size = DLEFiles::Size( $path, $driver );
		$type = DLEFiles::MimeType( $path, $driver );

		if ( DLEFiles::$error ){
			header( "HTTP/1.1 403 Forbidden" );
			echo DLEFiles::$error;
			die ();
		}
		
		$this->properties = array ('path' => $path, 'name' => $name, 'disk' => $driver, 'type' => $type, 'size' => $size);

		$this->range = 0;
		$this->range_end = $this->properties['size'] - 1;
		$this->is_range_request = false;

		if ( isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $matches) ) {
			$size = (int) $this->properties['size'];

			if ($size > 0) {

				if ($matches[1] === '' && $matches[2] !== '') {

					$suffix_length = (int) $matches[2];

					if ($suffix_length > 0) {
						$this->range = max(0, $size - $suffix_length);
						$this->range_end = $size - 1;
						$this->is_range_request = true;
					}

				} elseif ($matches[1] !== '') {

					$this->range = (int) $matches[1];

					if ($matches[2] !== '') {
						$this->range_end = min((int) $matches[2], $size - 1);
					} else {
						$this->range_end = $size - 1;
					}

					if ($this->range < $size && $this->range <= $this->range_end) {
						$this->is_range_request = true;
					} else {
						$this->range = 0;
						$this->range_end = $size - 1;
						$this->is_range_request = false;
					}
				}
			}
		}

	}
	
	function download_file( $viewonline = false) {

		if ($this->is_range_request) {
			header($_SERVER['SERVER_PROTOCOL'] . " 206 Partial Content");
		} else {
			header($_SERVER['SERVER_PROTOCOL'] . " 200 OK");
		}

		header( "Pragma: public" );
		header( "Expires: 0" );
		header( "Cache-Control: must-revalidate, post-check=0, pre-check=0"); 
		header( "Cache-Control: private", false);

		if( $this->properties['type'] ) {
			header( "Content-Type: " . $this->properties['type'] );
		} else {
			header( "Content-Type: application/octet-stream" );
		}
		
		$disposition = $viewonline ? 'inline' : 'attachment';
		
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $this->properties['name'] . '"' );
		header( "Content-Transfer-Encoding: binary" );
		
		if (!$viewonline) {
			header('Accept-Ranges: bytes');
		}

		if ($this->is_range_request) {

			header("Content-Range: bytes {$this->range}-{$this->range_end}/" . $this->properties['size']);
			header("Content-Length: " . ($this->range_end - $this->range + 1));
			
		} else {
			header("Content-Length: " . $this->properties['size']);
		}

		header("Connection: close");
 		
		@ini_set( 'max_execution_time', 0 );
		@set_time_limit(0);
		
		$this->_download();
	}
	
	function _download() {

		@ob_end_clean();
		
		$handle = DLEFiles::ReadStream( $this->properties['path'], $this->properties['disk']);
	
		if ( DLEFiles::$error ){
			header( "HTTP/1.1 403 Forbidden" );
			echo DLEFiles::$error;
			die ();
		}

		if (is_resource($handle)) {
			fseek($handle, $this->range);

			$bytes_left = $this->range_end - $this->range + 1;

			while (!feof($handle) && $bytes_left > 0) {

				$read_length = ($bytes_left > 8192) ? 8192 : $bytes_left;
				$buffer = fread($handle, $read_length);

				if ($buffer === false || $buffer === '') {
					break;
				}

				print($buffer);

				$bytes_left -= strlen($buffer);

				ob_flush();
				flush();
			}

			fclose($handle);
		}
		
	}

}
