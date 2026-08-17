<?php
/*
=====================================================
 DataLife Engine - by SoftNews Media Group
 ----------------------------------------------------
 https://dle-news.ru/
-----------------------------------------------------
 Copyright (c) 2004-2026 SoftNews Media Group
=====================================================
 File: dumper.php
-----------------------------------------------------
 Use: DB backup
=====================================================
*/

if( !defined( 'DATALIFEENGINE' ) OR !defined( 'LOGGED_IN' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header ( 'Location: ../../' );
	die( "Hacking attempt!" );
}

@set_error_handler("BackupErrorHandler", E_ALL ^ E_WARNING ^ E_DEPRECATED ^ E_NOTICE);

header("Content-type: text/html; charset=utf-8");
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Surrogate-Control: no-store');
header('Content-Encoding: none');

if( $member_id['user_group'] != 1 ){ msg("error", $lang['addnews_denied'], $lang['db_denied']); }

if( !defined('AUTOMODE') ) {
	if( !isset($_REQUEST['user_hash']) OR !$_REQUEST['user_hash'] OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		echo $lang['sess_error']; die();
	}
}

define('HOSTING_MEMORY_LIMIT',    _detect_memory_limit());
define('HOSTING_MAX_EXEC_TIME',   _detect_max_exec_time());

@ini_set('max_execution_time', 0);
if (function_exists('apache_setenv')) @apache_setenv('no-gzip', 1);
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
@ini_set('output_buffering', 0);
@set_time_limit(0);

while (ob_get_level() > 0) {
	ob_end_flush();
}

ob_implicit_flush(true);
ob_ignore();

$db->query( "INSERT INTO " . USERPREFIX . "_admin_logs (name, date, ip, action, extras) values ('".$db->safesql($member_id['name'])."', '{$_TIME}', '{$_IP}', '24', '')" );

define('PATH',      ROOT_DIR.'/backup/');
define('URL',       'backup/');
define('LIMIT',     1);
define('DBNAMES',   DBNAME);
define('DBNUSER',   DBUSER);
define('DBPREFIX',  PREFIX);

if(!defined('VERSIONID')) {
	define( 'VERSIONID', $config['version_id'] );
}

define('CHARSET',         'auto');
define('RESTORE_CHARSET', 'utf8');
define('SC',              0);
define('ONLY_CREATE',     'MRG_MyISAM,MERGE,HEAP,MEMORY');

define('WRITE_BUFFER_SIZE', _calc_write_buffer());

$timer    = array_sum(explode(' ', microtime()));
$auth     = 0;
$error    = '';
$db_location = explode(":", DBHOST);

if (isset($db_location[1])) {
	$dblink = @mysqli_connect($db_location[0], DBNUSER, DBPASS, DBNAME, (int)$db_location[1]);
} else {
	$dblink = @mysqli_connect($db_location[0], DBNUSER, DBPASS, DBNAME);
}

if ($dblink) {
	$auth = 1;
	@mysqli_query($dblink, "SET SESSION net_write_timeout = 3600");
	@mysqli_query($dblink, "SET SESSION net_read_timeout = 3600");
	@mysqli_query($dblink, "SET SESSION max_allowed_packet = 1073741824");	
} else {
	$error = '#' . mysqli_connect_error();
}

define('C_DEFAULT', 1);
define('C_RESULT',  2);
define('C_ERROR',   3);
define('C_WARNING', 4);

$action = $_REQUEST['action'] ?? '';
$Backup = new dumper();

match ($action) {
	'backup'  => $Backup->backup(),
	'restore' => $Backup->restore(),
	default   => $Backup->main(),
};

mysqli_close($dblink);

if(!defined('AUTOMODE')) {
	echo "<SCRIPT>document.getElementById('timer').innerHTML = '" . round(array_sum(explode(' ', microtime())) - $timer, 4) . " sec.'</SCRIPT>";
}

function _detect_memory_limit() {
	$val = ini_get('memory_limit');
	if ($val == -1) return 128 * 1024 * 1024;
	$unit = strtolower(substr($val, -1));
	$num  = (int)$val;
	switch ($unit) {
		case 'g': return $num * 1024 * 1024 * 1024;
		case 'm': return $num * 1024 * 1024;
		case 'k': return $num * 1024;
	}
	return max((int)$val, 32 * 1024 * 1024);
}

function _detect_max_exec_time() {
	$t = (int)ini_get('max_execution_time');
	return ($t > 0) ? $t : 300;
}

function _calc_write_buffer() {
	$mem = HOSTING_MEMORY_LIMIT;
	if ($mem >= 128 * 1024 * 1024) return 2 * 1024 * 1024;
	if ($mem >=  64 * 1024 * 1024) return 512 * 1024;
	if ($mem >=  32 * 1024 * 1024) return 128 * 1024;
	return 32 * 1024;
}

class dumper {

	public $SET            = array();
	public $tabs           = 0;
	public $records        = 0;
	public $size           = 0;
	public $comp           = 0;

	public $only_create    = array();
	public $forced_charset = false;
	public $restore_charset = '';
	public $restore_collate = '';
	public $mysql_version  = '';
	public $filename       = '';
	public $file_cache     = '';

	private $write_buffer      = '';
	private $write_buffer_size = 0;
	private $write_buffer_len  = 0;

	function __construct() {
		global $dblink;

		$this->SET['last_action']     = 0;
		$this->SET['last_db_backup']  = '';
		$this->SET['tables']          = '';
		$this->SET['comp_method']     = 1;
		$this->SET['comp_level']      = 7;
		$this->SET['last_db_restore'] = '';
		$this->tabs    = 0;
		$this->records = 0;
		$this->size    = 0;
		$this->comp    = 0;

		preg_match("/^(\d+)\.(\d+)\.(\d+)/", mysqli_get_server_info($dblink), $m);
		$this->mysql_version = sprintf("%d%02d%02d", $m[1], $m[2], $m[3]);

		$this->only_create     = explode(',', ONLY_CREATE);
		$this->forced_charset  = false;
		$this->restore_charset = $this->restore_collate = '';

		if (preg_match("/^(forced->)?(([a-z0-9]+)(\_\w+)?)$/", RESTORE_CHARSET, $matches)) {
			$this->forced_charset  = $matches[1] == 'forced->';
			$this->restore_charset = $matches[3];
			$this->restore_collate = !empty($matches[4]) ? ' COLLATE ' . $matches[2] : '';
		}

		$this->write_buffer_size = WRITE_BUFFER_SIZE;
	}

	function backup() {
		global $lang, $config, $dblink;

		if (!isset($_POST['comp_method'])) $_POST['comp_method'] = $_GET['comp_method'] ?? 1;
		
		if(!defined('AUTOMODE')) {
			$buttons = "<span id='save' STYLE='display: none;'>{$lang['dumper_1']}</span>";
			echo tpl_page(tpl_process($lang['dumper_2']), $buttons);
		}

		$this->SET['last_action']    = 0;
		$this->SET['last_db_backup'] = DBNAMES;
		$this->SET['tables_exclude'] = 0;
		$this->SET['tables']         = DBPREFIX . '*';
		$this->SET['comp_method']    = (int)($_POST['comp_method'] ?? 1);
		$this->SET['comp_level']     = 5;

		$this->SET['tables'] = explode(",", $this->SET['tables']);
		$tbls = array();
		foreach ($this->SET['tables'] as $table) {
			$table   = preg_replace("/[^\w*?^]/", "", $table);
			$pattern = array("/\?/", "/\*/");
			$replace = array(".", ".*?");
			$tbls[]  = preg_replace($pattern, $replace, $table);
		}

		$db = $this->SET['last_db_backup'];
		if (!$db) {
			if (!defined('AUTOMODE')) echo tpl_l($lang['dumper_3'], C_ERROR);
			exit;
		}

		if (!defined('AUTOMODE')) echo tpl_l("{$lang['dumper_20']} `{$db}`.");

		$result    = mysqli_query($dblink, "SHOW TABLE STATUS");
		$tables    = array();
		$tabinfo   = array();
		$tab_charset = array();
		$tab_type  = array();
		$total_rows = 0;
		$info      = '';

		while ($item = mysqli_fetch_assoc($result)) {
			$status = 0;
			$all    = 0;
			foreach ($tbls as $table_pat) {
				$exclude   = ($table_pat[0] == '^');
				$clean_pat = $exclude ? substr($table_pat, 1) : $table_pat;
				if (!$exclude) {
					if (preg_match("/^{$clean_pat}$/i", $item['Name'])) $status = 1;
					$all = 1;
				} elseif (preg_match("/{$clean_pat}$/i", $item['Name'])) {
					$status = -1;
				}
			}

			if ($status >= $all) {
				$tables[] = $item['Name'];
				$item['Rows'] = (int)$item['Rows'];
				$tabinfo[$item['Name']] = $item['Rows'];
				$total_rows            += $item['Rows'];
				$this->size            += $item['Data_length'];

				$info .= "|" . $item['Rows'];
				if (!empty($item['Collation']) && preg_match("/^([a-z0-9]+)_/i", $item['Collation'], $m)) {
					$tab_charset[$item['Name']] = $m[1];
				}
				$tab_type[$item['Name']] = $item['Engine'] ?? $item['Type'] ?? '';
			}
		}
		mysqli_free_result($result);

		$tabs   = count($tables);
		$info   = $total_rows . $info;
		$rand   = UniqIDReal(32);
		$name   = (defined('AUTOMODE'))
			? date("Y-m-d_H-i") . '_' . $db . '_' . $rand
			: $db . '_' . date("Y-m-d_H-i") . '_' . substr($rand, 0, 5);

		$fp = $this->fn_open($name, "w");
		if (!defined('AUTOMODE')) echo tpl_l($lang['dumper_5']);
		$this->fn_write($fp, "#DLE|" . VERSIONID . "\n\n");
		$this->fn_write($fp, "#SKD101|{$db}|{$tabs}|" . date("Y.m.d H:i:s") . "|{$info}\n\n");

		$t = 0;
		if (!defined('AUTOMODE')) echo tpl_l(str_repeat("-", 60));
		mysqli_query($dblink, "SET SQL_QUOTE_SHOW_CREATE = 1");

		$last_charset = (CHARSET != 'auto') ? CHARSET : '';
		if ($last_charset) mysqli_query($dblink, "SET NAMES '" . $last_charset . "'");

		mysqli_query($dblink, "SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
		mysqli_query($dblink, "START TRANSACTION WITH CONSISTENT SNAPSHOT");

		foreach ($tables as $table) {

			if ($this->mysql_version > 40101 && isset($tab_charset[$table]) && $tab_charset[$table] != $last_charset) {
				if (CHARSET == 'auto') {
					mysqli_query($dblink, "SET NAMES '" . $tab_charset[$table] . "'");
					$last_charset = $tab_charset[$table];
				}
			}

			if (!defined('AUTOMODE')) {
				echo tpl_l("{$lang['dumper_11']} `{$table}` [" . fn_int($tabinfo[$table]) . "].");
				echo tpl_s($t / max(1, $total_rows));
			}

			$result = mysqli_query($dblink, "SHOW CREATE TABLE `{$table}`");
			$tab    = mysqli_fetch_array($result);
			mysqli_free_result($result);
			$tab[1] = str_replace("utf8mb4_0900_ai_ci", "utf8mb4_general_ci", $tab[1]);
			$this->fn_write($fp, "DROP TABLE IF EXISTS `{$table}`;\n{$tab[1]};\n\n");

			if (in_array($tab_type[$table], $this->only_create)) continue;

			$NumericColumn = array();
			$res_cols      = mysqli_query($dblink, "SHOW COLUMNS FROM `{$table}`");
			$field_idx     = 0;
			while ($col = mysqli_fetch_row($res_cols)) {
				$NumericColumn[$field_idx++] = preg_match("/^(\w*int|year|float|double|decimal)/i", $col[1]) ? 1 : 0;
			}
			mysqli_free_result($res_cols);
			$fields_cnt = $field_idx;

			$update_every = max(1, (int)($tabinfo[$table] / 20));
			$processed    = 0;

			$max_insert_size = 800 * 1024;
			$current_insert_size = 0;
			$is_first_row = true;

			$result = mysqli_query($dblink, "SELECT * FROM `{$table}`", MYSQLI_USE_RESULT);

			if ($result) {
				while ($row = mysqli_fetch_row($result)) {
					$t++;
					$processed++;

					for ($k = 0; $k < $fields_cnt; $k++) {
						if ($row[$k] === null) {
							$row[$k] = "NULL";
						} elseif (!$NumericColumn[$k]) {
							$row[$k] = "'" . mysqli_real_escape_string($dblink, $row[$k]) . "'";
						}
					}

					$row_str = "(" . implode(",", $row) . ")";
					$row_len = strlen($row_str);

					if ($is_first_row) {
						$this->fn_write($fp, "/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n");
						$this->fn_write($fp, "INSERT INTO `{$table}` VALUES\n{$row_str}");
						$current_insert_size = $row_len;
						$is_first_row = false;
					} else {
						if ($current_insert_size + $row_len > $max_insert_size) {
							$this->fn_write($fp, ";\nINSERT INTO `{$table}` VALUES\n{$row_str}");
							$current_insert_size = $row_len;
						} else {
							$this->fn_write($fp, ",\n{$row_str}");
							$current_insert_size += $row_len + 2;
						}
					}

					if ($processed % $update_every == 0) {
						echo tpl_s($t / max(1, $total_rows));
					}
				}
				mysqli_free_result($result);
			}

			$this->fn_flush($fp);
			if (!$is_first_row) {
				$this->fn_write($fp, ";\n");
				$this->fn_write($fp, "/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;\n\n");
			}

			if (!defined('AUTOMODE')) echo tpl_s($t / max(1, $total_rows));
		}

		mysqli_query($dblink, "COMMIT");

		$this->tabs    = $tabs;
		$this->records = $total_rows;
		$this->comp    = $this->SET['comp_method'] * 10 + $this->SET['comp_level'];
		
		$this->fn_close($fp);

		if (!defined('AUTOMODE')) {
			echo tpl_s(1);
			echo tpl_l(str_repeat("-", 60));
			echo tpl_l("{$lang['dumper_12']} `{$db}` {$lang['dumper_13']}", C_RESULT);
			echo tpl_l("{$lang['dumper_14']} " . round($this->size / 1048576, 2) . " MB", C_RESULT);
			$filesize = round(filesize(PATH . $this->filename) / 1048576, 2) . " MB";
			echo tpl_l("{$lang['dumper_15']} {$filesize}", C_RESULT);
			echo tpl_l("{$lang['dumper_16']} {$tabs}", C_RESULT);
			echo tpl_l("{$lang['dumper_17']} " . fn_int($total_rows), C_RESULT);			
		}

		$disk = DLEFiles::getDefaultStorage();
		$config['backup_remote'] = intval($config['backup_remote']);
		if ($config['backup_remote'] > -1) $disk = $config['backup_remote'];

		if ($disk) {
			echo tpl_l($lang['dumper_33'], C_RESULT);
			DLEFiles::init($disk);
			if (!DLEFiles::$error) {
				$stream = @fopen(PATH . $this->filename, 'rb');
				if (is_resource($stream)) {
					DLEFiles::WriteStream(URL . $this->filename, $stream);
					fclose($stream);
				}
				if (DLEFiles::$error) echo tpl_l(DLEFiles::$error, C_ERROR);
				else unlink(PATH . $this->filename);
			} else echo tpl_l(DLEFiles::$error, C_ERROR);
			echo tpl_l($lang['dumper_34'], C_RESULT);
		}

		if (!defined('AUTOMODE')) {
			echo "<script>if (document.getElementById('save')) {document.getElementById('save').style.display = ''; }</script>";
		}
	}

	function restore() {
		global $config, $lang, $dblink;

		$buttons = "<span id='save' style='display: none;'>{$lang['dumper_24']}</span>";
		echo tpl_page(tpl_process($lang['dumper_18']), $buttons);

		$this->SET['last_action']     = 1;
		$this->SET['last_db_restore'] = DBNAMES;
		$file = (string)($_POST['file'] ?? $_GET['file'] ?? '');
		$file = basename($file);

		if (!$file || !preg_match('/^[a-zA-Z0-9_.-]+\.sql(\.(gz|bz2))?$/i', $file)) {
			echo tpl_l($lang['dumper_21'], C_ERROR);
			exit;
		}

		$db = $this->SET['last_db_restore'];
		if (!$db) {
			echo tpl_l($lang['dumper_19'], C_ERROR);
			exit;
		}

		$disk = DLEFiles::getDefaultStorage();
		$config['backup_remote'] = intval($config['backup_remote']);
		if ($config['backup_remote'] > -1) $disk = $config['backup_remote'];

		if ($disk) {
			DLEFiles::init($disk);
			if (!DLEFiles::FileExists(URL . $file)) {
				echo tpl_l($lang['dumper_21'], C_ERROR);
				exit;
			}
			$handle = DLEFiles::ReadStream(URL . $file);
			if (DLEFiles::$error) {
				echo tpl_l(DLEFiles::$error, C_ERROR);
				exit;
			}
			$stream = @fopen(PATH . $file, 'wb');
			if (!is_resource($stream)) {
				echo tpl_l($lang['dumper_35'], C_ERROR);
				exit;
			}
			if (is_resource($handle)) {
				while (!feof($handle)) {
					fwrite($stream, fread($handle, 65536));
				}
				fclose($handle);
			}
			if (is_resource($stream)) fclose($stream);
		}

		echo tpl_l("{$lang['dumper_20']} `{$db}`.");

		if (preg_match("/^(.+?)\.sql(\.(bz2|gz))?$/", $file, $matches)) {
			if (isset($matches[3]) && $matches[3] == 'bz2') $this->SET['comp_method'] = 2;
			elseif (isset($matches[2]) && $matches[3] == 'gz') $this->SET['comp_method'] = 1;
			else $this->SET['comp_method'] = 0;

			$this->SET['comp_level'] = 0;
			if (!file_exists(PATH . "/{$file}")) {
				echo tpl_l($lang['dumper_21'], C_ERROR);
				exit;
			}
			echo tpl_l("{$lang['dumper_22']} `{$file}`.");
			$file = $matches[1];
		} else {
			echo tpl_l($lang['dumper_21'], C_ERROR);
			exit;
		}

		echo tpl_l(str_repeat("-", 60));

		$res = mysqli_query($dblink, "SELECT @@max_allowed_packet");
		if ($res && $row = mysqli_fetch_row($res)) {
			$max_packet = (int)$row[0];
			mysqli_free_result($res);
		} else {
			$max_packet = 1048576;
		}

		$safe_mem_limit = max(16384, intval((HOSTING_MEMORY_LIMIT - 8 * 1024 * 1024) * 0.05));
		$max_query_len  = min((int)($max_packet * 0.8), 1048576, $safe_mem_limit);
		if ($max_query_len < 16384) $max_query_len = 16384;

		mysqli_query($dblink, "SET FOREIGN_KEY_CHECKS=0");
		mysqli_query($dblink, "SET UNIQUE_CHECKS=0");
		mysqli_query($dblink, "SET AUTOCOMMIT=0");
		mysqli_query($dblink, "START TRANSACTION");
		@mysqli_query($dblink, "SET SQL_LOG_BIN=0");

		$fp = $this->fn_open($file, "r");
		$this->file_cache = $sql = $table = $insert = '';
		$is_skd = $is_dle = $query_len = $execute = $q = $t = $i = $aff_rows = 0;
		$limit   = 300;
		$index   = 4;
		$tabs    = 0;
		$info    = array();
		$convert = false;
		$use_mb = function_exists('mb_convert_encoding');
		$use_iconv = !$use_mb && function_exists('iconv');
		
		$redo_log_capacity = $this->_detect_redo_log_capacity($dblink);
		$commit_size_limit = max(5 * 1024 * 1024, min(64 * 1024 * 1024, (int)($redo_log_capacity * 0.10)));
		$commit_every      = max(300, min(5000, (int)($commit_size_limit / 4096)));
		$commit_counter    = 0;
		$uncommitted_size  = 0;

		if ($this->mysql_version > 40101 && (CHARSET != 'auto' || $this->forced_charset)) {
			mysqli_query($dblink, "SET NAMES '" . $this->restore_charset . "'")
				or (tpl_l($lang['dumper_6'] . htmlspecialchars( mysqli_error($dblink), ENT_QUOTES, 'UTF-8'), C_ERROR) ?: exit());
			echo tpl_l("{$lang['dumper_7']} `" . $this->restore_charset . "`.", C_WARNING);
			$last_charset = $this->restore_charset;
		} else {
			$last_charset = '';
		}

		$last_showed = '';

		while (($str = $this->fn_read_str($fp)) !== false) {

			if (empty($str) || $str[0] == '#' || ($str[0] == '-' && isset($str[1]) && $str[1] == '-')) {
				if (!$is_dle && !empty($str)) {
					if (strpos($str, "#DLE") === 0) {
						$dle_info = explode("|", $str);
						if ($dle_info[1] == VERSIONID) $is_dle = 1;
						else {
							echo tpl_l($lang['dumper_32'], C_ERROR);
							exit;
						}
					}
				}
				if (!$is_skd && strpos($str, "#SKD101|") === 0) {
					$info = explode("|", $str);
					echo tpl_s(0);
					$is_skd = 1;
				}
				continue;
			}

			$query_len += strlen($str);

			if (!$insert && stripos($str, 'INSERT INTO') === 0
				&& preg_match("/^(INSERT INTO `?([^` ]+)`? .*?VALUES)(.*)$/i", $str, $m)) {
				if ($table != $m[2]) {
					$table = $m[2];
					echo tpl_l("Table `{$table}`.");
					$last_showed = $table;
					$i = 0;
					if ($is_skd && !empty($info[4])) {
						echo tpl_s($t / max(1, (int)$info[4]));
					}
				}
				$insert = $m[1] . ' ';
				$sql   .= $m[3];
				$info[$index] ??= 0;
				$limit = max(50, round($info[$index] / 100));
			} else {
				$sql .= $str;
				if ($insert) {
					$i++;
					$t++;
					if ($is_skd && !empty($info[4])) {
						if ($i % $limit == 0 || $i == ($info[$index] ?? 0)) {
							echo tpl_s($t / max(1, (int)$info[4]));
						}
					}
				}
			}

			if (!$insert && stripos($str, 'CREATE TABLE') === 0
				&& preg_match("/^CREATE TABLE (IF NOT EXISTS )?`?([^` ]+)`?/i", $str, $m)
				&& $table != $m[2]) {
				$table  = $m[2];
				$insert = '';
				$tabs++;
				$i     = 0;
				$index = 4 + $tabs;
				if ($is_skd && !empty($info[4])) echo tpl_s($t / max(1, (int)$info[4]));
			}

			if ($sql) {
				$trimmed_str = rtrim($str);

				if (str_ends_with($trimmed_str, ';')) {
					$sql = rtrim($insert . $sql, ";");
					if (empty($insert)) {
						if ($this->mysql_version < 40101) {
							$sql = preg_replace("/ENGINE\s?=/", "TYPE=", $sql);
						} elseif (stripos($sql, 'CREATE TABLE') !== false) {
							if (preg_match("/(CHARACTER SET|CHARSET)[=\s]+(\w+)/i", $sql, $charset)) {
								if (!$this->forced_charset && $charset[2] != $last_charset) {
									if (CHARSET == 'auto') {
										if ($charset[2] == "cp1251") {
											$convert = true;
											$charset[2] = "utf8";
											$this->restore_charset = "utf8";
										}
										mysqli_query($dblink, "SET NAMES '" . $charset[2] . "'")
											or (tpl_l($lang['dumper_6'] . htmlspecialchars(mysqli_error($dblink), ENT_QUOTES, 'UTF-8'), C_ERROR) ?: exit());
										echo tpl_l("{$lang['dumper_7']} `" . $charset[2] . "`.", C_WARNING);
										$last_charset = $charset[2];
									} else {
										echo tpl_l($lang['dumper_8'], C_ERROR);
										echo tpl_l($lang['dumper_9'] . ' `' . $table . '` -> ' . $charset[2] . ' (' . $lang['dumper_10'] . ' ' . $this->restore_charset . ')', C_ERROR);
									}
								}
								if ($this->forced_charset || $convert) {
									$sql = preg_replace("/(\/\*!\d+\s)?((COLLATE)[=\s]+)\w+(\s+\*\/)?/i", '', $sql);
									$sql = preg_replace("/((CHARACTER SET|CHARSET)[=\s]+)\w+/i", "\\1" . $this->restore_charset . $this->restore_collate, $sql);
								}
							} elseif (CHARSET == 'auto') {
								$sql .= ' DEFAULT CHARSET=' . $this->restore_charset . $this->restore_collate;
								if ($this->restore_charset != $last_charset) {
									mysqli_query($dblink, "SET NAMES '" . $this->restore_charset . "'")
										or (tpl_l($lang['dumper_6'] . htmlspecialchars(mysqli_error($dblink), ENT_QUOTES, 'UTF-8'), C_ERROR) ?: exit());
									$last_charset = $this->restore_charset;
								}
							}
						}
						if ($last_showed != $table) {
							echo tpl_l("{$lang['dumper_9']} `{$table}`.");
							$last_showed = $table;
						}
					}
					if ($is_skd && !empty($info[4])) {
						echo tpl_s($t / max(1, (int)$info[4]));
					}
					$insert  = '';
					$execute = 1;
				} elseif ($query_len >= $max_query_len && str_ends_with($trimmed_str, '),')) {
					$sql = rtrim($insert . $sql);
					$sql = substr($sql, 0, -1);
					$execute = 1;
				}

				if ($execute) {
					$q++;
					
					if ($convert) {
						if ($use_mb) $sql = mb_convert_encoding($sql, 'UTF-8', 'WINDOWS-1251');
						elseif ($use_iconv) $sql = iconv('WINDOWS-1251', 'UTF-8', $sql);
					}

					mysqli_query($dblink, $sql)
						or (tpl_l($lang['dumper_23'] . htmlspecialchars(mysqli_error($dblink), ENT_QUOTES, 'UTF-8'), C_ERROR) ?: exit());

					if ($insert || strncasecmp($sql, 'insert', 6) === 0) {
						$aff_rows += mysqli_affected_rows($dblink);
					}

					$uncommitted_size += strlen($sql);
					$sql       = '';
					$query_len = 0;
					$execute   = 0;

					$commit_counter++;
					if ($commit_counter >= $commit_every || $uncommitted_size >= $commit_size_limit) {
						mysqli_query($dblink, "COMMIT");
						mysqli_query($dblink, "START TRANSACTION");
						$commit_counter = 0;
						$uncommitted_size = 0;
					}
				}
			}
		}

		if ($is_skd) echo tpl_s(1);
		mysqli_query($dblink, "COMMIT");
		mysqli_query($dblink, "SET FOREIGN_KEY_CHECKS=1");
		mysqli_query($dblink, "SET UNIQUE_CHECKS=1");
		mysqli_query($dblink, "SET AUTOCOMMIT=1");

		echo tpl_s(1);
		echo tpl_l(str_repeat("-", 60));
		echo tpl_l($lang['dumper_24'], C_RESULT);
		if (isset($info[3])) echo tpl_l("{$lang['dumper_25']} {$info[3]}", C_RESULT);
		echo tpl_l("{$lang['dumper_26']} {$q}", C_RESULT);
		echo tpl_l("{$lang['dumper_27']} {$tabs}", C_RESULT);
		echo tpl_l("{$lang['dumper_28']} {$aff_rows}", C_RESULT);

		$this->tabs    = $tabs;
		$this->records = $aff_rows;
		$this->size    = file_exists(PATH . $this->filename) ? filesize(PATH . $this->filename) : 0;
		$this->comp    = $this->SET['comp_method'] * 10 + $this->SET['comp_level'];

		$this->fn_close($fp);
		if ($disk && file_exists(PATH . $this->filename)) unlink(PATH . $this->filename);

		clear_all_caches();
		if (!defined('AUTOMODE')) {
			echo "<script>if (document.getElementById('save')) {document.getElementById('save').style.display = ''; }</script>";
		}
	}

	function main(){
		die("Hacking attempt!");
	}

	function file_select(){
		$files = array('' => ' ');
		if (is_dir(PATH) && $handle = opendir(PATH)) {
			while (false !== ($file = readdir($handle))) {
				if (preg_match("/^.+?\.sql(\.(gz|bz2))?$/", $file)) {
					$files[$file] = $file;
				}
			}
			closedir($handle);
		}
		ksort($files);
		return $files;
	}

	function fn_open($name, $mode) {
		global $lang;

		if ($this->SET['comp_method'] == 2) {
			$this->filename = "{$name}.sql.bz2";
			$fp = bzopen(PATH . $this->filename, "{$mode}");
		} elseif ($this->SET['comp_method'] == 1) {
			$this->filename = "{$name}.sql.gz";
			$fp = gzopen(PATH . $this->filename, "{$mode}b{$this->SET['comp_level']}");
		} else {
			$this->filename = "{$name}.sql";
			$fp = fopen(PATH . $this->filename, "{$mode}b");
		}

		if (is_resource($fp)) {
			if ($this->SET['comp_method'] == 0) {
				stream_set_write_buffer($fp, 1048576);
			}
			return $fp;
		} else {
			echo tpl_l($lang['dumper_35'], C_ERROR);
			exit;
		}
	}

	function fn_write($fp, $str) {
		$this->write_buffer .= $str;
		$this->write_buffer_len += strlen($str);
		if ($this->write_buffer_len >= $this->write_buffer_size) {
			$this->fn_flush($fp);
		}
	}

	function fn_flush($fp) {
		if ($this->write_buffer === '') return;
		if ($this->SET['comp_method'] == 2) {
			bzwrite($fp, $this->write_buffer);
		} elseif ($this->SET['comp_method'] == 1) {
			gzwrite($fp, $this->write_buffer);
		} else {
			fwrite($fp, $this->write_buffer);
		}
		$this->write_buffer = '';
		$this->write_buffer_len = 0;
	}

	function fn_read($fp) {
		if ($this->SET['comp_method'] == 2) {
			return bzread($fp, 65536);
		} elseif ($this->SET['comp_method'] == 1) {
			return gzread($fp, 65536);
		} else {
			return fread($fp, 65536);
		}
	}

	function fn_read_str($fp) {
		if ($this->SET['comp_method'] == 0) {
			$str = fgets($fp);
			return $str !== false ? trim($str) : false;
		} elseif ($this->SET['comp_method'] == 1) {
			$str = gzgets($fp);
			return $str !== false ? trim($str) : false;
		}

		while (($pos = strpos($this->file_cache, "\n")) === false) {
			$str = bzread($fp, 65536);
			if (!$str) {
				if ($this->file_cache !== '') {
					$res = trim($this->file_cache);
					$this->file_cache = '';
					return $res !== '' ? $res : false;
				}
				return false;
			}
			$this->file_cache .= $str;
		}

		$string = substr($this->file_cache, 0, $pos);
		$this->file_cache = (string) substr($this->file_cache, $pos + 1);

		return trim($string);
	}

	function fn_close($fp) {
		$this->fn_flush($fp);
		if ($this->SET['comp_method'] == 2) {
			bzclose($fp);
		} elseif ($this->SET['comp_method'] == 1) {
			gzclose($fp);
		} else {
			fclose($fp);
		}
	}

	function fn_select($items, $selected) {
		$select = '';
		foreach ($items as $key => $value) {
			$select .= $key == $selected
				? "<OPTION VALUE='{$key}' SELECTED>{$value}"
				: "<OPTION VALUE='{$key}'>{$value}";
		}
		return $select;
	}

	function _detect_redo_log_capacity($dblink) {
		
		$res = @mysqli_query($dblink, "SELECT @@innodb_redo_log_capacity AS cap");
		if ($res && $row = mysqli_fetch_assoc($res)) {
			mysqli_free_result($res);
			$cap = (int)$row['cap'];
			if ($cap > 0) return $cap;
		}

		$log_size = 0;
		$log_files = 2;

		$res = @mysqli_query($dblink, "SELECT @@innodb_log_file_size AS sz");
		if ($res && $row = mysqli_fetch_assoc($res)) {
			$log_size = (int)$row['sz'];
			mysqli_free_result($res);
		}

		$res = @mysqli_query($dblink, "SELECT @@innodb_log_files_in_group AS cnt");
		if ($res && $row = mysqli_fetch_assoc($res)) {
			$log_files = max(1, (int)$row['cnt']);
			mysqli_free_result($res);
		}

		if ($log_size > 0) return $log_size * $log_files;

		return 48 * 1024 * 1024;
	}

	function fn_save() {
		return;
	}
}

function fn_int($num) {
	return number_format((float)$num, 0, ',', ' ');
}

function tpl_page($content = '', $buttons = '') {
	global $config, $lang;

	if (defined('AUTOMODE')) return;

	return <<<HTML
<!doctype html>
<html lang="{$lang['language_code']}" dir="{$lang['direction']}">
<head>
<meta charset="utf-8">
<style type="text/css">
<!--
body{
	overflow: auto;
	font-family: system-ui;
    font-size: 1em;
	line-height: 1.5;
}
form {
	margin:0px;
	padding: 0px;
}
table{
	border:0px;
	border-collapse:collapse;
}
table td{
	padding:0px;
	font-family: system-ui;
    font-size: 1em;
}
.row {
	padding-left:.22em;
	padding-right:.22em;
}
-->
</style>
</head>
<body style="padding: 1.2em;">
    <table width="100%">
        <tr>
			<td valign="top">
				{$content}
				<table width="100%" border="0" cellspacing="0" cellpadding="2">
					<tr>
						<td style="color: #CECECE" id="timer"></td>
						<td align="right">{$buttons}</td>
					</tr>
				</table>
			</td>
        </tr>
    </table>
</body>
<script>
    var parentDoc = window.parent.document;
    var root = parentDoc.documentElement;
    var fontSize = window.parent.getComputedStyle(root).getPropertyValue('--font-size-sm').trim();

    if (fontSize) {
        const tempElement = parentDoc.createElement('div');
        Object.assign(tempElement.style, {
            position: 'absolute',
            left: '-9999px',
            visibility: 'hidden',
            fontSize: 'var(--font-size-sm)',
            fontFamily: 'system-ui'
        });
        parentDoc.body.appendChild(tempElement);
        const computedStyles = getComputedStyle(tempElement);
        fontSize = computedStyles.fontSize;
        parentDoc.body.removeChild(tempElement);
    } else {
        fontSize = '0.85rem';
    }

    document.body.style.fontSize = fontSize;
</script>
</html>
HTML;
}

function tpl_process($title) {
	global $lang;

	if (defined('AUTOMODE')) return;

	ob_ignore();

	return <<<HTML
<table width="100%" border="0" cellspacing="0" cellpadding="2">
    <tr>
        <td colspan="2" style="margin-bottom: .15em;">
            <div id="logarea" style="width: 100%; height: 13.24em; margin-bottom:.37em; border: 1px solid #7F9DB9; overflow: auto;"></div>
        </td>
    </tr>
    <tr>
        <td style="width: 11em;">{$lang['dumper_30']}</td>
        <td>
            <table width="100%" style="border: 1px solid #7F9DB9;" cellspacing="0" cellpadding="0">
                <tr>
                    <td bgcolor="#FFFFFF">
                        <table width="1" border="0" cellpadding="0" cellspacing="0" bgcolor="#00AA00" id="so_tab" style="background-image:linear-gradient(to bottom, #9bcff5 0%, #6db9f0 100%);background-repeat:repeat-x;transition: width 0.3s ease-in-out;">
                            <tr>
                                <td style="height:.88em"></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<script>
    var WidthLocked = false;
    function s(so) {
        document.getElementById('so_tab').style.width = so ? so + '%' : '1';
    }
    function l(str, color) {
        switch (color) {
            case 2: color = 'navy'; break;
            case 3: color = 'red'; break;
            case 4: color = 'maroon'; break;
            default: color = '';
        }
        var el = document.getElementById('logarea');
        if (el) {
            if (!WidthLocked) {
                el.style.width = el.clientWidth;
                WidthLocked = true;
            }
            if (color) {
                str = '<span style="color:' + color + ';">' + str + '</span>';
            }
            el.innerHTML += '<div class="row">'+str+'</div>';
            el.scrollTop = el.scrollHeight;
        }
    }
</script>
HTML;
}

function tpl_l($str, $color = C_DEFAULT){
	if (defined('AUTOMODE')) return "";
	ob_ignore();

	$safe_str = json_encode(preg_replace("/\s{2}/", " &nbsp;", htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8')));
	$color_int = (int)$color;

	echo "<script>l({$safe_str}, {$color_int});</script>\n";

	if (ob_get_level() > 0) ob_flush();
	flush();
	return "";
}

function tpl_s($so) {

	if (defined('AUTOMODE')) return;

	ob_ignore();

	$so = number_format(min(100, max(0, (float)$so * 100)), 2, '.', '');

	echo "<script>s({$so});</script>\n";

	if (ob_get_level() > 0) ob_flush();
	flush();

	return "";
}

function tpl_backup_index() {

	if (defined('AUTOMODE')) return;

	return <<<HTML
<center>
<h1>access denied</h1>
</center>

HTML;
}

function tpl_error($error) {

	if (defined('AUTOMODE')) return;

	return <<<HTML
<table width="100%" border="0" cellspacing="0" cellpadding="2">
    <tr>
        <td align="center">{$error}</td>
    </tr>
</table>
HTML;
}

function BackupErrorHandler($errno, $errmsg, $filename, $linenum) {
	global $lang;
	if ($errno == 2048) return true;
	if (str_contains($errmsg, "date():")) return true;
	$dt     = date("Y.m.d H:i:s");
	echo tpl_l("{$dt}<br><b>{$lang['dumper_31']}</b>", C_ERROR);
	echo tpl_l("{$errmsg} ({$errno}) at the file {$filename} ({$linenum})", C_ERROR);
	die();
}

function ob_ignore() {
	static $forward_buffer = null;

	if ($forward_buffer === null) {
		$forward_buffer = str_repeat(' ', 1024);
	}

	while (ob_get_level() > 0) {
		@ob_end_flush();
	}
	echo $forward_buffer;
	flush();
}
