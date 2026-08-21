<?php
/**
 * Shared helpers for the DLE Marketplace plugin.
 */

if (!defined('DATALIFEENGINE')) {
    header('HTTP/1.1 403 Forbidden');
    header('Location: ../../');
    die('Hacking attempt!');
}

if (!defined('DLE_MARKETPLACE_STORAGE')) {
    define('DLE_MARKETPLACE_STORAGE', ROOT_DIR . '/uploads/dle_marketplace/files');
}

if (!defined('DLE_MARKETPLACE_PREVIEWS')) {
    define('DLE_MARKETPLACE_PREVIEWS', ROOT_DIR . '/uploads/dle_marketplace/previews');
}

function dle_marketplace_load_language()
{
    global $config, $lang;

    $selected = isset($config['langs']) ? trim((string)$config['langs']) : 'Russian';
    $selected = preg_replace('/[^a-z0-9_-]/i', '', $selected);
    $files = array();

    if ($selected) {
        $files[] = ROOT_DIR . '/language/' . $selected . '/dle_marketplace.lng';
    }

    $files[] = ROOT_DIR . '/language/Russian/dle_marketplace.lng';

    foreach ($files as $file) {
        if (is_file($file)) {
            include_once DLEPlugins::Check($file);
            return true;
        }
    }

    return false;
}

function dle_marketplace_h($value)
{
    global $config;

    if (is_array($value) || is_object($value)) {
        $value = '';
    }

    $charset = !empty($config['charset']) ? $config['charset'] : 'UTF-8';

    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, $charset);
}

function dle_marketplace_lang($key, $replace = array())
{
    global $lang;

    $text = isset($lang[$key]) ? (string)$lang[$key] : (string)$key;

    if (is_array($replace)) {
        foreach ($replace as $name => $value) {
            $text = str_replace('{' . $name . '}', (string)$value, $text);
        }
    }

    return $text;
}

function dle_marketplace_request_string($key, $default = '', $max_length = 255)
{
    $value = isset($_REQUEST[$key]) && is_scalar($_REQUEST[$key]) ? (string)$_REQUEST[$key] : $default;
    $value = trim(strip_tags($value));

    if (function_exists('dle_substr')) {
        return dle_substr($value, 0, $max_length);
    }

    return substr($value, 0, $max_length);
}

function dle_marketplace_int($value, $default = 0)
{
    if (!is_scalar($value) || $value === '') {
        return (int)$default;
    }

    return (int)$value;
}

function dle_marketplace_price($value)
{
    if (!is_scalar($value)) {
        return '0.00';
    }

    $value = str_replace(',', '.', trim((string)$value));
    $value = preg_replace('/[^0-9.]/', '', $value);

    if ($value === '' || !is_numeric($value)) {
        return '0.00';
    }

    $value = max(0, min(999999999, (float)$value));

    return number_format($value, 2, '.', '');
}

function dle_marketplace_currency()
{
    global $config;

    $currency = isset($config['marketplace_currency']) && is_scalar($config['marketplace_currency']) ? strtoupper((string)$config['marketplace_currency']) : 'RUB';
    $currency = preg_replace('/[^A-Z0-9₽$€£]/u', '', $currency);

    return $currency ?: 'RUB';
}

function dle_marketplace_money($value, $currency = '')
{
    $formatted = number_format((float)$value, 2, '.', ' ');
    $currency = is_scalar($currency) ? (string)$currency : '';

    return $formatted . ($currency !== '' ? ' ' . $currency : '');
}

function dle_marketplace_format_size($bytes)
{
    $bytes = max(0, (int)$bytes);
    $units = array(
        dle_marketplace_lang('mp_unit_bytes'),
        dle_marketplace_lang('mp_unit_kb'),
        dle_marketplace_lang('mp_unit_mb'),
        dle_marketplace_lang('mp_unit_gb'),
        dle_marketplace_lang('mp_unit_tb'),
    );
    $unit = 0;
    $size = (float)$bytes;

    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }

    if ($unit === 0) {
        return $bytes . ' ' . $units[$unit];
    }

    return number_format($size, $size >= 10 ? 0 : 1, ',', ' ') . ' ' . $units[$unit];
}

function dle_marketplace_date($timestamp)
{
    global $config;

    $timestamp = (int)$timestamp;

    if (!$timestamp) {
        return '';
    }

    if (function_exists('langdate')) {
        $format = !empty($config['timestamp_active']) ? $config['timestamp_active'] : 'd.m.Y H:i';
        return langdate($format, $timestamp);
    }

    return date('d.m.Y H:i', $timestamp);
}

function dle_marketplace_random_token($length = 32)
{
    try {
        return substr(bin2hex(random_bytes(max(16, (int)$length))), 0, (int)$length);
    } catch (Throwable $e) {
        return substr(sha1(uniqid((string)mt_rand(), true)), 0, (int)$length);
    }
}

function dle_marketplace_csrf_token()
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }

    if (empty($_SESSION['dle_marketplace_csrf'])) {
        $_SESSION['dle_marketplace_csrf'] = dle_marketplace_random_token(64);
    }

    return (string)$_SESSION['dle_marketplace_csrf'];
}

function dle_marketplace_verify_csrf($token)
{
    if (!is_scalar($token) || $token === '') {
        return false;
    }

    return hash_equals(dle_marketplace_csrf_token(), (string)$token);
}

function dle_marketplace_post_nonce()
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }

    if (empty($_SESSION['dle_marketplace_post_nonce'])) {
        $_SESSION['dle_marketplace_post_nonce'] = dle_marketplace_random_token(48);
    }

    return (string)$_SESSION['dle_marketplace_post_nonce'];
}

function dle_marketplace_consume_post_nonce($nonce)
{
    $valid = is_scalar($nonce) && $nonce !== '' && hash_equals(dle_marketplace_post_nonce(), (string)$nonce);

    if ($valid) {
        unset($_SESSION['dle_marketplace_post_nonce']);
    }

    return $valid;
}

function dle_marketplace_table($name)
{
    return PREFIX . '_marketplace_' . preg_replace('/[^a-z0-9_]/i', '', (string)$name);
}

function dle_marketplace_current_url($params = array())
{
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
    $parts = parse_url($request_uri);
    $path = !empty($parts['path']) ? $parts['path'] : '/';
    $query = array();

    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $remove = array(
        'mp_product', 'mp_category', 'mp_search', 'mp_sort', 'mp_page',
        'mp_cart', 'mp_orders', 'mp_download', 'mp_token', 'mp_notice',
        'cf_ajax', 'cf_csrf', 'cf_nonce', 'product_id'
    );

    foreach ($remove as $key) {
        unset($query[$key]);
    }

    foreach ((array)$params as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return $path . (count($query) ? '?' . http_build_query($query, '', '&') : '');
}

function dle_marketplace_asset_url($path)
{
    global $config;

    $base = !empty($config['http_home_url']) ? rtrim($config['http_home_url'], '/') : '';
    $path = ltrim(str_replace('\\', '/', (string)$path), '/');

    return $base . '/plugins/dle_marketplace/' . $path;
}

function dle_marketplace_preview_url($file)
{
    global $config;

    $file = basename(str_replace('\\', '/', (string)$file));

    if ($file === '') {
        return '';
    }

    $base = !empty($config['http_home_url']) ? rtrim($config['http_home_url'], '/') : '';

    return $base . '/uploads/dle_marketplace/previews/' . rawurlencode($file);
}

function dle_marketplace_ensure_storage()
{
    $directories = array(DLE_MARKETPLACE_STORAGE, DLE_MARKETPLACE_PREVIEWS);
    $deny_php = "<FilesMatch \"\\.([Pp][Hh][Pp]|[Pp][Hh][Tt][Mm][Ll]|[Cc][Gg][Ii])\.?\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n</FilesMatch>\n";
    $deny_all = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";

    foreach ($directories as $directory) {
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        if (is_dir($directory)) {
            @chmod($directory, 0775);
        }
    }

    if (is_dir(DLE_MARKETPLACE_STORAGE) && !is_file(DLE_MARKETPLACE_STORAGE . '/.htaccess')) {
        @file_put_contents(DLE_MARKETPLACE_STORAGE . '/.htaccess', $deny_all, LOCK_EX);
    }

    if (is_dir(DLE_MARKETPLACE_PREVIEWS) && !is_file(DLE_MARKETPLACE_PREVIEWS . '/.htaccess')) {
        @file_put_contents(DLE_MARKETPLACE_PREVIEWS . '/.htaccess', $deny_php, LOCK_EX);
    }

    return is_dir(DLE_MARKETPLACE_STORAGE) && is_dir(DLE_MARKETPLACE_PREVIEWS);
}

function dle_marketplace_safe_storage_name($path)
{
    $path = str_replace('\\', '/', (string)$path);
    $path = basename($path);

    if ($path === '' || $path === '.' || $path === '..' || strpos($path, "\0") !== false) {
        return '';
    }

    return $path;
}

function dle_marketplace_file_path($path, $preview = false)
{
    $path = dle_marketplace_safe_storage_name($path);

    if ($path === '') {
        return '';
    }

    return ($preview ? DLE_MARKETPLACE_PREVIEWS : DLE_MARKETPLACE_STORAGE) . '/' . $path;
}

function dle_marketplace_delete_stored_file($path, $preview = false)
{
    $file = dle_marketplace_file_path($path, $preview);

    if ($file && is_file($file)) {
        @unlink($file);
    }
}

function dle_marketplace_fetch_product($id, $include_inactive = false)
{
    global $db;

    $id = dle_marketplace_int($id);

    if ($id < 1) {
        return array();
    }

    $where = $include_inactive ? '' : " AND status='1'";
    $table = dle_marketplace_table('products');
    $row = $db->super_query("SELECT * FROM {$table} WHERE id='{$id}'{$where} LIMIT 1", false, false);

    return is_array($row) ? $row : array();
}

function dle_marketplace_fetch_products($filters = array())
{
    global $db;

    $table = dle_marketplace_table('products');
    $filters = is_array($filters) ? $filters : array();
    $where = array("status='1'");
    $category = isset($filters['category']) && is_scalar($filters['category']) ? trim((string)$filters['category']) : '';
    $search = isset($filters['search']) && is_scalar($filters['search']) ? trim((string)$filters['search']) : '';
    $sort = isset($filters['sort']) && is_scalar($filters['sort']) ? (string)$filters['sort'] : 'newest';
    $page = max(1, dle_marketplace_int($filters['page'] ?? 1, 1));
    $per_page = min(48, max(1, dle_marketplace_int($filters['per_page'] ?? 12, 12)));

    if ($category !== '') {
        $category = $db->safesql($category);
        $where[] = "category='{$category}'";
    }

    if ($search !== '') {
        $search = $db->safesql($search);
        $where[] = "(title LIKE '%{$search}%' OR short_description LIKE '%{$search}%' OR description LIKE '%{$search}%')";
    }

    $sort_sql = 'featured DESC, created_at DESC, id DESC';

    switch ($sort) {
        case 'popular':
            $sort_sql = 'sales DESC, downloads DESC, created_at DESC';
            break;
        case 'price_asc':
            $sort_sql = 'price ASC, created_at DESC';
            break;
        case 'price_desc':
            $sort_sql = 'price DESC, created_at DESC';
            break;
    }

    $where_sql = implode(' AND ', $where);
    $count_row = $db->super_query("SELECT COUNT(*) AS total FROM {$table} WHERE {$where_sql}", false, false);
    $total = is_array($count_row) ? (int)$count_row['total'] : 0;
    $pages = max(1, (int)ceil($total / $per_page));
    $page = min($page, $pages);
    $offset = ($page - 1) * $per_page;
    $result = array();

    $db->query("SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$sort_sql} LIMIT {$offset},{$per_page}", false);

    while ($row = $db->get_row()) {
        $result[] = $row;
    }

    $db->free();

    return array(
        'items' => $result,
        'total' => $total,
        'pages' => $pages,
        'page' => $page,
        'per_page' => $per_page,
    );
}

function dle_marketplace_fetch_categories()
{
    global $db;

    $table = dle_marketplace_table('products');
    $categories = array();
    $db->query("SELECT category, COUNT(*) AS product_count FROM {$table} WHERE status='1' AND category!='' GROUP BY category ORDER BY category ASC", false);

    while ($row = $db->get_row()) {
        $categories[] = $row;
    }

    $db->free();

    return $categories;
}

function dle_marketplace_fetch_products_by_ids($ids)
{
    global $db;

    $clean_ids = array();

    foreach ((array)$ids as $id) {
        $id = dle_marketplace_int($id);
        if ($id > 0) {
            $clean_ids[$id] = $id;
        }
    }

    if (!count($clean_ids)) {
        return array();
    }

    $list = implode(',', $clean_ids);
    $table = dle_marketplace_table('products');
    $rows = array();
    $db->query("SELECT * FROM {$table} WHERE status='1' AND id IN ({$list}) ORDER BY FIELD(id,{$list})", false);

    while ($row = $db->get_row()) {
        $rows[] = $row;
    }

    $db->free();

    return $rows;
}

function dle_marketplace_owned_product_ids($user_id)
{
    global $db;

    $user_id = dle_marketplace_int($user_id);

    if ($user_id < 1) {
        return array();
    }

    $orders = dle_marketplace_table('orders');
    $ids = array();
    $db->query("SELECT product_id FROM {$orders} WHERE user_id='{$user_id}' AND status='paid'", false);

    while ($row = $db->get_row()) {
        $ids[(int)$row['product_id']] = true;
    }

    $db->free();

    return $ids;
}

function dle_marketplace_fetch_user_orders($user_id)
{
    global $db;

    $user_id = dle_marketplace_int($user_id);

    if ($user_id < 1) {
        return array();
    }

    $orders = dle_marketplace_table('orders');
    $products = dle_marketplace_table('products');
    $rows = array();
    $db->query("SELECT o.*, p.title, p.file_name, p.file_path FROM {$orders} o LEFT JOIN {$products} p ON p.id=o.product_id WHERE o.user_id='{$user_id}' ORDER BY o.created_at DESC, o.id DESC", false);

    while ($row = $db->get_row()) {
        $rows[] = $row;
    }

    $db->free();

    return $rows;
}

function dle_marketplace_wallet_balance($user_id)
{
    global $db;

    $user_id = dle_marketplace_int($user_id);

    if ($user_id < 1) {
        return 0.00;
    }

    $wallets = dle_marketplace_table('wallets');
    $row = $db->super_query("SELECT balance FROM {$wallets} WHERE user_id='{$user_id}' LIMIT 1", false, false);

    return is_array($row) ? (float)$row['balance'] : 0.00;
}

function dle_marketplace_cart()
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }

    $cart = isset($_SESSION['dle_marketplace_cart']) && is_array($_SESSION['dle_marketplace_cart']) ? $_SESSION['dle_marketplace_cart'] : array();
    $clean = array();

    foreach ($cart as $id) {
        $id = dle_marketplace_int($id);
        if ($id > 0) {
            $clean[$id] = $id;
        }
    }

    $_SESSION['dle_marketplace_cart'] = array_values(array_slice($clean, 0, 50, true));

    return $_SESSION['dle_marketplace_cart'];
}

function dle_marketplace_cart_add($product_id)
{
    global $db;

    $product_id = dle_marketplace_int($product_id);
    $product = dle_marketplace_fetch_product($product_id);

    if (!$product) {
        return false;
    }

    $cart = dle_marketplace_cart();

    if (!in_array($product_id, $cart, true) && count($cart) < 50) {
        $cart[] = $product_id;
    }

    $_SESSION['dle_marketplace_cart'] = $cart;

    return true;
}

function dle_marketplace_cart_remove($product_id)
{
    $product_id = dle_marketplace_int($product_id);
    $cart = dle_marketplace_cart();
    $cart = array_values(array_filter($cart, function ($id) use ($product_id) {
        return (int)$id !== $product_id;
    }));
    $_SESSION['dle_marketplace_cart'] = $cart;
}

function dle_marketplace_cart_clear()
{
    dle_marketplace_cart();
    $_SESSION['dle_marketplace_cart'] = array();
}

function dle_marketplace_checkout($user_id, $product_ids)
{
    global $db, $_TIME;

    $user_id = dle_marketplace_int($user_id);
    $clean_ids = array();

    foreach ((array)$product_ids as $id) {
        $id = dle_marketplace_int($id);
        if ($id > 0) {
            $clean_ids[$id] = $id;
        }
    }

    if ($user_id < 1 || !count($clean_ids)) {
        return array('success' => false, 'message' => dle_marketplace_lang('mp_cart_empty'));
    }

    $products_table = dle_marketplace_table('products');
    $orders_table = dle_marketplace_table('orders');
    $wallets_table = dle_marketplace_table('wallets');
    $wallet_log_table = dle_marketplace_table('wallet_log');
    $order_items = array();
    $total = 0.00;
    $ip = isset($_SERVER['REMOTE_ADDR']) && is_scalar($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    $ip = $db->safesql(substr($ip, 0, 46));
    $now = isset($_TIME) ? (int)$_TIME : time();

    $db->query('START TRANSACTION', false);

    foreach ($clean_ids as $product_id) {
        $product = $db->super_query("SELECT * FROM {$products_table} WHERE id='{$product_id}' AND status='1' LIMIT 1", false, false);

        if (!is_array($product) || empty($product['id'])) {
            continue;
        }

        if (strtoupper((string)$product['currency']) !== strtoupper(dle_marketplace_currency())) {
            $db->query('ROLLBACK', false);
            return array('success' => false, 'message' => dle_marketplace_lang('mp_currency_error'));
        }

        $existing = $db->super_query("SELECT id, status, amount FROM {$orders_table} WHERE user_id='{$user_id}' AND product_id='{$product_id}' LIMIT 1 FOR UPDATE", false, false);

        if (is_array($existing) && $existing['status'] === 'paid') {
            continue;
        }

        $amount = (float)$product['price'];
        $total += $amount;
        $order_items[] = array(
            'product' => $product,
            'existing' => is_array($existing) ? $existing : array(),
            'amount' => $amount,
        );
    }

    if (!count($order_items)) {
        $db->query('COMMIT', false);
        dle_marketplace_cart_clear();
        return array('success' => true, 'message' => dle_marketplace_lang('mp_already_owned'), 'balance' => dle_marketplace_wallet_balance($user_id));
    }

    if ($total > 0) {
        $db->query("INSERT IGNORE INTO {$wallets_table} (user_id, balance, updated_at) VALUES ('{$user_id}', '0.00', '{$now}')", false);
        $db->query("UPDATE {$wallets_table} SET balance=balance-" . dle_marketplace_price($total) . ", updated_at='{$now}' WHERE user_id='{$user_id}' AND balance >= " . dle_marketplace_price($total), false);

        if ((int)$db->get_affected_rows() !== 1) {
            $db->query('ROLLBACK', false);
            return array('success' => false, 'message' => dle_marketplace_lang('mp_insufficient_balance'));
        }
    }

    $wallet = $db->super_query("SELECT balance FROM {$wallets_table} WHERE user_id='{$user_id}' LIMIT 1", false, false);
    $balance_after = is_array($wallet) ? (float)$wallet['balance'] : 0.00;

    foreach ($order_items as $item) {
        $product = $item['product'];
        $existing = $item['existing'];
        $product_id = (int)$product['id'];
        $amount = dle_marketplace_price($item['amount']);
        $order_id = 0;

        if (!empty($existing['id'])) {
            $order_id = (int)$existing['id'];
            $updated = $db->query("UPDATE {$orders_table} SET status='paid', amount='{$amount}', currency='" . $db->safesql($product['currency']) . "', paid_at='{$now}', ip='{$ip}' WHERE id='{$order_id}'", false);
        } else {
            $order_token = $db->safesql(dle_marketplace_random_token(64));
            $currency = $db->safesql((string)$product['currency']);
            $updated = $db->query("INSERT INTO {$orders_table} (order_token, user_id, product_id, amount, currency, status, created_at, paid_at, ip) VALUES ('{$order_token}', '{$user_id}', '{$product_id}', '{$amount}', '{$currency}', 'paid', '{$now}', '{$now}', '{$ip}')", false);
            $order_id = (int)$db->insert_id();
        }

        if (!$updated) {
            $db->query('ROLLBACK', false);
            return array('success' => false, 'message' => dle_marketplace_lang('mp_order_error'));
        }

        if ((float)$item['amount'] > 0) {
            $negative_amount = '-' . $amount;
            $note = $db->safesql((string)$product['title']);
            $logged = $db->query("INSERT INTO {$wallet_log_table} (user_id, amount, balance_after, type, order_id, note, created_at) VALUES ('{$user_id}', '{$negative_amount}', '" . dle_marketplace_price($balance_after) . "', 'purchase', '{$order_id}', '{$note}', '{$now}')", false);

            if (!$logged) {
                $db->query('ROLLBACK', false);
                return array('success' => false, 'message' => dle_marketplace_lang('mp_order_error'));
            }

            $db->query("UPDATE {$products_table} SET sales=sales+1, updated_at='{$now}' WHERE id='{$product_id}'", false);
        }
    }

    $db->query('COMMIT', false);
    dle_marketplace_cart_clear();

    return array(
        'success' => true,
        'message' => dle_marketplace_lang('mp_purchase_success'),
        'balance' => $balance_after,
        'items' => count($order_items),
    );
}

function dle_marketplace_download_token($user_id, $product_id)
{
    $key = defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : 'dle-marketplace';

    return hash_hmac('sha256', dle_marketplace_int($user_id) . '|' . dle_marketplace_int($product_id), $key);
}

function dle_marketplace_stream_download($user_id, $product_id, $token)
{
    global $db, $_TIME;

    $user_id = dle_marketplace_int($user_id);
    $product_id = dle_marketplace_int($product_id);

    if ($user_id < 1 || $product_id < 1 || !is_scalar($token) || !hash_equals(dle_marketplace_download_token($user_id, $product_id), (string)$token)) {
        header('HTTP/1.1 403 Forbidden');
        die(dle_marketplace_lang('mp_access_denied'));
    }

    $orders = dle_marketplace_table('orders');
    $products = dle_marketplace_table('products');
    $row = $db->super_query("SELECT p.*, o.status AS order_status FROM {$products} p INNER JOIN {$orders} o ON o.product_id=p.id WHERE p.id='{$product_id}' AND o.user_id='{$user_id}' AND o.status='paid' LIMIT 1", false, false);

    if (!is_array($row) || empty($row['id'])) {
        header('HTTP/1.1 404 Not Found');
        die(dle_marketplace_lang('mp_product_not_found'));
    }

    $file = dle_marketplace_file_path($row['file_path']);
    $base = realpath(DLE_MARKETPLACE_STORAGE);
    $real_file = $file ? realpath($file) : false;
    $base_prefix = $base ? rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';

    if (!$real_file || !$base || strpos($real_file, $base_prefix) !== 0 || !is_file($real_file) || !is_readable($real_file)) {
        header('HTTP/1.1 404 Not Found');
        die(dle_marketplace_lang('mp_file_missing'));
    }

    $download_name = dle_marketplace_safe_storage_name($row['file_name']);
    $download_name = preg_replace('/[\\x00-\\x1F\\x7F]+/', '_', $download_name);
    if ($download_name === '') {
        $download_name = 'download.' . (pathinfo($real_file, PATHINFO_EXTENSION) ?: 'bin');
    }

    $ascii_name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $download_name);
    $ascii_name = $ascii_name ?: 'download.bin';
    $encoded_name = rawurlencode($download_name);
    $extension = strtolower(pathinfo($real_file, PATHINFO_EXTENSION));
    $mime_types = array(
        'zip' => 'application/zip', 'pdf' => 'application/pdf', 'epub' => 'application/epub+zip',
        'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', '7z' => 'application/x-7z-compressed',
        'rar' => 'application/vnd.rar', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    );
    $content_type = isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';

    $db->query("UPDATE {$products} SET downloads=downloads+1 WHERE id='{$product_id}'", false);

    while (ob_get_level()) {
        @ob_end_clean();
    }

    ignore_user_abort(true);
    header('Content-Type: ' . $content_type);
    header('Content-Length: ' . (string)filesize($real_file));
    header('Content-Disposition: attachment; filename="' . $ascii_name . '"; filename*=UTF-8\'\'' . $encoded_name);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    readfile($real_file);
    exit;
}

function dle_marketplace_upload($field, $preview = false)
{
    $empty = array('success' => true, 'empty' => true, 'path' => '', 'name' => '');

    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return $empty;
    }

    $file = $_FILES[$field];
    $error = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;

    if ($error === UPLOAD_ERR_NO_FILE) {
        return $empty;
    }

    if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return array('success' => false, 'message' => dle_marketplace_lang('mp_admin_upload_error'));
    }

    $original = basename(str_replace('\\', '/', (string)$file['name']));
    $original = preg_replace('/[\\x00-\\x1F\\x7F]+/', '_', $original);
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = $preview
        ? array('jpg', 'jpeg', 'png', 'gif', 'webp')
        : array('zip', 'pdf', 'epub', 'mp3', 'mp4', 'webm', '7z', 'rar', 'docx', 'xlsx', 'png', 'jpg', 'jpeg', 'gif');
    $max_size = $preview ? 8 * 1024 * 1024 : 100 * 1024 * 1024;

    if ($extension === '' || !in_array($extension, $allowed, true) || (int)$file['size'] < 1 || (int)$file['size'] > $max_size) {
        return array('success' => false, 'message' => dle_marketplace_lang('mp_admin_upload_error'));
    }

    if (!dle_marketplace_ensure_storage()) {
        return array('success' => false, 'message' => dle_marketplace_lang('mp_admin_storage_error'));
    }

    $stored_name = dle_marketplace_random_token(24) . '.' . $extension;
    $target = ($preview ? DLE_MARKETPLACE_PREVIEWS : DLE_MARKETPLACE_STORAGE) . '/' . $stored_name;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return array('success' => false, 'message' => dle_marketplace_lang('mp_admin_upload_error'));
    }

    @chmod($target, 0644);

    return array('success' => true, 'empty' => false, 'path' => $stored_name, 'name' => $original, 'size' => (int)$file['size']);
}
