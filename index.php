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
*/

define('DATALIFEENGINE', true);
define('ROOT_DIR', __DIR__);
define('ENGINE_DIR', ROOT_DIR . '/engine');

require_once(ENGINE_DIR . '/classes/plugins.class.php');
require_once(DLEPlugins::Check(ROOT_DIR . '/engine/init.php'));
