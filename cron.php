<?php
/*
=====================================================
 DataLife Engine - by SoftNews Media Group
-----------------------------------------------------
 https://dle-news.ru/
-----------------------------------------------------
 Copyright (c) 2004-2026 SoftNews Media Group
=====================================================
 File: cron.php
-----------------------------------------------------
 Use: Cron operations
=====================================================
*/

/* ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
To support the launch operations for the cron you need set a value 1 for the variable $allow_cron

Rename this file cron.php for security reasons to any other with the php extension
 ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ */

$allow_cron = 0;

/* ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
Specify the number of backup files database for save on the server
 ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ */

$max_count_files = 5;

/* ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
Don't edit the code which follows below without understanding
the security and upgrade implications.
 ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ */

if (!$allow_cron) {
    header('HTTP/1.1 403 Forbidden');
    die('Cron not allowed');
}

define('DATALIFEENGINE', true);
define('AUTOMODE', true);
define('LOGGED_IN', true);

define('ROOT_DIR', __DIR__);
define('ENGINE_DIR', ROOT_DIR . '/engine');

require_once ENGINE_DIR . '/classes/plugins.class.php';
require_once DLEPlugins::Check(ENGINE_DIR . '/inc/include/functions.inc.php');
include_once DLEPlugins::Check(ROOT_DIR . '/language/' . $config['langs'] . '/website.lng');

if (!empty($config['date_adjust'])) {
    date_default_timezone_set($config['date_adjust']);
}

$cronmode = detectCronMode();
resetCronRequestContext();

switch ($cronmode) {
    case 'sitemap':
        runCronSitemap();
        die('done');

    case 'optimize':
        runCronOptimize();
        die('done');

    case 'antivirus':
        runCronAntivirus();
        die('done');

    default:
        runCronBackup($max_count_files);
        die('done');
}

function detectCronMode() {
    $mode = null;

    if (isset($_REQUEST['cronmode']) && $_REQUEST['cronmode']) {
        $mode = (string) $_REQUEST['cronmode'];
    } elseif (isset($_SERVER['argc'], $_SERVER['argv']) && (int)$_SERVER['argc'] > 1 && isset($_SERVER['argv'][1])) {
        $mode = (string) $_SERVER['argv'][1];
    }

    if ($mode === null) {
        return null;
    }

    $mode        = strtolower($mode);
    $allowedMode = array('sitemap', 'optimize', 'antivirus', 'backup');

    return in_array($mode, $allowedMode, true) ? $mode : null;
}

function resetCronRequestContext() {
    global $dle_login_hash;

    $_REQUEST = $_POST = $_GET = array();

    $_REQUEST['user_hash'] = 1;
    $dle_login_hash        = 1;
}

function runCronSitemap() {
    global $db, $config, $lang, $member_id, $user_group, $cat_info;

    $_POST['action'] = 'create';

    $member_id = array(
        'user_group' => 1,
    );

    $user_group = array();
    $user_group[$member_id['user_group']] = array();
    $user_group[$member_id['user_group']]['admin_googlemap'] = 1;

    $cat_info = get_vars('category');

    if (! is_array($cat_info)) {
        $cat_info = array();

        $db->query('SELECT * FROM ' . PREFIX . '_category ORDER BY posi ASC');

        while ($row = $db->get_row()) {
            if (empty($row['active'])) {
                continue;
            }

            $catId            = (int) $row['id'];
            $cat_info[$catId] = array();

            foreach ($row as $key => $value) {
                $cat_info[$catId][$key] = stripslashes($value);
            }
        }

        set_vars('category', $cat_info);
        $db->free();
    }

    include_once DLEPlugins::Check(ROOT_DIR . '/engine/inc/googlemap.php');
}

function runCronOptimize() {
    global $db;

    $tables = '';

    $db->query('SHOW TABLES');

    while ($row = $db->get_array()) {
        if (! isset($row[0])) {
            continue;
        }

        if (substr($row[0], 0, strlen(PREFIX)) === PREFIX) {
            $tables .= ', `' . $db->safesql($row[0]) . '`';
        }
    }

    $db->free();

    if ($tables === '') {
        return;
    }

    $tables = substr($tables, 1);
    $query  = 'OPTIMIZE TABLE ' . $tables;

    $db->query($query);
}

function runCronAntivirus() {
    global $config, $lang, $db;

    include_once DLEPlugins::Check(ENGINE_DIR . '/classes/antivirus.class.php');

    $antivirus = new antivirus();
    $antivirus->scan_files(ROOT_DIR, false, true);

    if (! count($antivirus->bad_files)) {
        return;
    }

    $found_files = '';

    foreach ($antivirus->bad_files as $data) {
        $type = ! empty($data['type']) ? $lang['anti_modified'] : $lang['anti_not'];

        $found_files .= "\n{$data['file_path']} {$type}\n";
    }

    $mail    = new dle_mail($config);
    $message = $lang['anti_message_1']
        . "\n{$found_files}\n"
        . $lang['anti_message_2']
        . "\n\n"
        . $lang['lost_mfg'] . ' ' . $config['http_home_url'];

    $mail->send($config['admin_mail'], $lang['anti_subj'], $message);
}

function runCronBackup($maxCountFiles) {
    global $config, $member_id, $_TIME, $_IP, $db, $dblink;
    
    $_REQUEST['action']   = 'backup';
    $_POST['comp_method'] = 1;
    $_TIME                = time();
    $_IP                  = '127.0.0.1';

    $maxCountFiles = (int) $maxCountFiles;
    if ($maxCountFiles < 1) {
        $maxCountFiles = 1;
    }

    $files = array();
    $disk  = DLEFiles::getDefaultStorage();

    $config['backup_remote'] = isset($config['backup_remote']) ? (int) $config['backup_remote'] : -1;

    if ($config['backup_remote'] > -1) {
        $disk = $config['backup_remote'];
    }

    if ($disk) {
        DLEFiles::init($disk, false);

        $tmp_files = DLEFiles::ListDirectory('backup/', array('sql', 'gz', 'bz2'));

        if (! DLEFiles::$error && isset($tmp_files['files']) && is_array($tmp_files['files'])) {
            foreach ($tmp_files['files'] as $key) {
                if (empty($key['name']) || empty($key['path'])) {
                    continue;
                }

                $prefix = explode('_', $key['name']);
                $prefix = end($prefix);
                $prefix = explode('.', $prefix);
                $prefix = reset($prefix);

                if (strlen($prefix) === 32) {
                    $files[] = $key['path'];
                }
            }
        }
    } else {
        if (is_dir(ROOT_DIR . '/backup/') && ($handle = opendir(ROOT_DIR . '/backup/'))) {
            while (false !== ($file = readdir($handle))) {
                if (! preg_match("/^.+?\.sql(\.(gz|bz2))?$/", $file)) {
                    continue;
                }

                $prefix = explode('_', $file);
                $prefix = end($prefix);
                $prefix = explode('.', $prefix);
                $prefix = reset($prefix);

                if (strlen($prefix) === 32) {
                    $files[] = $file;
                }
            }

            closedir($handle);
        }
    }

    sort($files);

    while (count($files) >= $maxCountFiles) {
        $oldestFile = array_shift($files);

        if ($oldestFile === null) {
            break;
        }

        if ($disk) {
            DLEFiles::Delete($oldestFile);
        } else {
            @unlink(ROOT_DIR . '/backup/' . $oldestFile);
        }
    }

    $member_id = array(
        'user_group' => 1,
        'name'       => 'cron_auto_backup',
    );

    include_once DLEPlugins::Check(ROOT_DIR . '/engine/inc/dumper.php');
}
