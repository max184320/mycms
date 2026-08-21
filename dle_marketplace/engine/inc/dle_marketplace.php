<?php
/**
 * DLE Marketplace administration module.
 */

if (!defined('DATALIFEENGINE') || !defined('LOGGED_IN')) {
    header('HTTP/1.1 403 Forbidden');
    header('Location: ../../');
    die('Hacking attempt!');
}

if (method_exists('DLEPlugins', 'CheckIFActive') && !DLEPlugins::CheckIFActive('dle_marketplace')) {
    die('Marketplace plugin is disabled.');
}

include_once ROOT_DIR . '/plugins/dle_marketplace/functions.php';
dle_marketplace_load_language();

global $config, $db, $member_id, $user_group, $dle_login_hash, $_TIME;

if (intval($member_id['user_group']) !== 1) {
    msg('error', dle_marketplace_h(dle_marketplace_lang('mp_admin_title')), dle_marketplace_h(dle_marketplace_lang('mp_admin_access')));
}

function dle_marketplace_admin_back_url($section = 'products', $extra = array())
{
    $params = array_merge(array('mod' => 'dle_marketplace', 'section' => $section), $extra);
    return '?' . http_build_query($params, '', '&');
}

function dle_marketplace_admin_redirect($section = 'products', $notice = '')
{
    if ($notice !== '') {
        $_SESSION['dle_marketplace_admin_notice'] = $notice;
    }

    if (function_exists('clear_all_caches')) {
        clear_all_caches();
    }

    header('Location: ' . dle_marketplace_admin_back_url($section));
    die();
}

function dle_marketplace_admin_check_request()
{
    global $dle_login_hash;

    if (!isset($_REQUEST['user_hash']) || !is_scalar($_REQUEST['user_hash']) || !hash_equals((string)$dle_login_hash, (string)$_REQUEST['user_hash'])) {
        msg('error', dle_marketplace_h(dle_marketplace_lang('mp_admin_title')), dle_marketplace_h(dle_marketplace_lang('mp_csrf_error')), 'javascript:history.go(-1)');
    }

    if (function_exists('check_referer') && !check_referer($_SERVER['SCRIPT_NAME'] . '?mod=dle_marketplace')) {
        msg('error', dle_marketplace_h(dle_marketplace_lang('mp_admin_title')), dle_marketplace_h(dle_marketplace_lang('no_referer')), 'javascript:history.go(-1)');
    }
}

function dle_marketplace_admin_fail($message)
{
    msg('error', dle_marketplace_h(dle_marketplace_lang('mp_admin_title')), dle_marketplace_h($message), 'javascript:history.go(-1)');
}

function dle_marketplace_admin_status_label($status)
{
    $map = array(
        'paid' => 'mp_admin_paid',
        'pending' => 'mp_admin_pending',
        'cancelled' => 'mp_admin_cancelled',
    );

    return dle_marketplace_lang(isset($map[$status]) ? $map[$status] : 'mp_admin_pending');
}

function dle_marketplace_admin_notice()
{
    if (empty($_SESSION['dle_marketplace_admin_notice'])) {
        return '';
    }

    $notice = (string)$_SESSION['dle_marketplace_admin_notice'];
    unset($_SESSION['dle_marketplace_admin_notice']);

    $map = array(
        'saved' => 'mp_admin_product_saved',
        'deleted' => 'mp_admin_product_deleted',
        'order' => 'mp_admin_order_updated',
        'balance' => 'mp_admin_balance_updated',
    );

    if (!isset($map[$notice])) {
        return '';
    }

    return '<div class="alert alert-success alert-styled-left alert-arrow-left alert-component">' . dle_marketplace_h(dle_marketplace_lang($map[$notice])) . '</div>';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    $action = is_scalar($_POST['action']) ? (string)$_POST['action'] : '';
    dle_marketplace_admin_check_request();

    if ($action === 'save_product') {
        $id = dle_marketplace_int($_POST['id'] ?? 0);
        $title = dle_marketplace_request_string('title', '', 180);
        $category = dle_marketplace_request_string('category', '', 100);
        $short_description = dle_marketplace_request_string('short_description', '', 500);
        $description = isset($_POST['description']) && is_scalar($_POST['description']) ? trim(strip_tags((string)$_POST['description'])) : '';
        $description = function_exists('dle_substr') ? dle_substr($description, 0, 20000) : substr($description, 0, 20000);
        $price = dle_marketplace_price($_POST['price'] ?? '0');
        $currency = dle_marketplace_currency();
        $status = !empty($_POST['status']) ? 1 : 0;
        $featured = !empty($_POST['featured']) ? 1 : 0;
        $now = isset($_TIME) ? (int)$_TIME : time();
        $products_table = dle_marketplace_table('products');

        if ($title === '' || $description === '') {
            dle_marketplace_admin_fail(dle_marketplace_lang('mp_admin_required'));
        }

        $existing = $id > 0 ? dle_marketplace_fetch_product($id, true) : array();
        if ($id > 0 && !$existing) {
            dle_marketplace_admin_fail(dle_marketplace_lang('mp_admin_invalid_product'));
        }

        $file_upload = dle_marketplace_upload('product_file');
        if (!$file_upload['success']) {
            dle_marketplace_admin_fail($file_upload['message']);
        }

        $preview_upload = dle_marketplace_upload('preview_image', true);
        if (!$preview_upload['success']) {
            if (!$file_upload['empty']) {
                dle_marketplace_delete_stored_file($file_upload['path']);
            }
            dle_marketplace_admin_fail($preview_upload['message']);
        }

        $file_path = $existing['file_path'] ?? '';
        $file_name = $existing['file_name'] ?? '';
        $file_size = isset($existing['file_size']) ? (int)$existing['file_size'] : 0;
        $preview_image = $existing['preview_image'] ?? '';

        if (!$file_upload['empty']) {
            $file_path = $file_upload['path'];
            $file_name = $file_upload['name'];
            $file_size = (int)$file_upload['size'];
        }

        if (!$preview_upload['empty']) {
            $preview_image = $preview_upload['path'];
        }

        if ($file_path === '' || $file_name === '') {
            if (!$preview_upload['empty']) {
                dle_marketplace_delete_stored_file($preview_upload['path'], true);
            }
            dle_marketplace_admin_fail(dle_marketplace_lang('mp_admin_required'));
        }

        $title_sql = $db->safesql($title);
        $category_sql = $db->safesql($category);
        $short_sql = $db->safesql($short_description);
        $description_sql = $db->safesql($description);
        $currency_sql = $db->safesql($currency);
        $file_path_sql = $db->safesql($file_path);
        $file_name_sql = $db->safesql($file_name);
        $preview_sql = $db->safesql($preview_image);
        $author_id = dle_marketplace_int($member_id['user_id']);

        if ($id > 0) {
            $created_at = dle_marketplace_int($existing['created_at'] ?? $now, $now);
            $db->query("UPDATE {$products_table} SET title='{$title_sql}', category='{$category_sql}', short_description='{$short_sql}', description='{$description_sql}', price='{$price}', currency='{$currency_sql}', file_path='{$file_path_sql}', file_name='{$file_name_sql}', file_size='{$file_size}', preview_image='{$preview_sql}', status='{$status}', featured='{$featured}', updated_at='{$now}' WHERE id='{$id}'");

            if (!$file_upload['empty'] && !empty($existing['file_path']) && $existing['file_path'] !== $file_path) {
                dle_marketplace_delete_stored_file($existing['file_path']);
            }
            if (!$preview_upload['empty'] && !empty($existing['preview_image']) && $existing['preview_image'] !== $preview_image) {
                dle_marketplace_delete_stored_file($existing['preview_image'], true);
            }
        } else {
            $db->query("INSERT INTO {$products_table} (title, category, short_description, description, price, currency, file_path, file_name, file_size, preview_image, status, featured, downloads, sales, author_id, created_at, updated_at) VALUES ('{$title_sql}', '{$category_sql}', '{$short_sql}', '{$description_sql}', '{$price}', '{$currency_sql}', '{$file_path_sql}', '{$file_name_sql}', '{$file_size}', '{$preview_sql}', '{$status}', '{$featured}', '0', '0', '{$author_id}', '{$now}', '{$now}')");
        }

        dle_marketplace_admin_redirect('products', 'saved');
    }

    if ($action === 'update_order') {
        $order_id = dle_marketplace_int($_POST['order_id'] ?? 0);
        $status = isset($_POST['status']) && is_scalar($_POST['status']) ? (string)$_POST['status'] : '';
        if (!in_array($status, array('paid', 'pending', 'cancelled'), true)) {
            dle_marketplace_admin_fail(dle_marketplace_lang('mp_admin_invalid_status'));
        }

        $orders_table = dle_marketplace_table('orders');
        $products_table = dle_marketplace_table('products');
        $order = $db->super_query("SELECT * FROM {$orders_table} WHERE id='{$order_id}' LIMIT 1", false, false);
        if (!is_array($order) || empty($order['id'])) {
            dle_marketplace_admin_fail(dle_marketplace_lang('mp_admin_invalid_product'));
        }

        $old_status = (string)$order['status'];
        $now = isset($_TIME) ? (int)$_TIME : time();
        $status_sql = $db->safesql($status);
        $paid_at = $status === 'paid' ? $now : 0;
        $db->query("UPDATE {$orders_table} SET status='{$status_sql}', paid_at='{$paid_at}' WHERE id='{$order_id}'");

        if ($old_status !== 'paid' && $status === 'paid') {
            $db->query("UPDATE {$products_table} SET sales=sales+1, updated_at='{$now}' WHERE id='" . (int)$order['product_id'] . "'");
        } elseif ($old_status === 'paid' && $status !== 'paid') {
            $db->query("UPDATE {$products_table} SET sales=IF(sales>0, sales-1, 0), updated_at='{$now}' WHERE id='" . (int)$order['product_id'] . "'");
        }

        dle_marketplace_admin_redirect('orders', 'order');
    }

    if ($action === 'adjust_balance') {
        $user_id = dle_marketplace_int($_POST['user_id'] ?? 0);
        $delta = (float)str_replace(',', '.', (string)($_POST['delta'] ?? '0'));
        $delta = max(-999999999, min(999999999, $delta));
        $delta_sql = dle_marketplace_price(abs($delta));
        $note = dle_marketplace_request_string('note', dle_marketplace_lang('mp_admin_reason'), 255);
        $users_table = USERPREFIX . '_users';
        $wallets_table = dle_marketplace_table('wallets');
        $wallet_log_table = dle_marketplace_table('wallet_log');
        $user = $db->super_query("SELECT user_id, name FROM {$users_table} WHERE user_id='{$user_id}' LIMIT 1", false, false);

        if (!is_array($user) || empty($user['user_id'])) {
            dle_marketplace_admin_fail(dle_marketplace_lang('mp_admin_invalid_user'));
        }
        if ($delta == 0) {
            dle_marketplace_admin_fail(dle_marketplace_lang('mp_admin_required'));
        }

        $now = isset($_TIME) ? (int)$_TIME : time();
        $admin_id = dle_marketplace_int($member_id['user_id']);
        $db->query("INSERT IGNORE INTO {$wallets_table} (user_id, balance, updated_at) VALUES ('{$user_id}', '0.00', '{$now}')");
        $before = dle_marketplace_wallet_balance($user_id);
        $signed = $delta >= 0 ? $delta_sql : '-' . $delta_sql;
        $db->query("UPDATE {$wallets_table} SET balance=GREATEST(0, balance+({$signed})), updated_at='{$now}' WHERE user_id='{$user_id}'");
        $after = dle_marketplace_wallet_balance($user_id);
        $actual = $after - $before;
        $type = $delta >= 0 ? 'admin_credit' : 'admin_debit';
        $note_sql = $db->safesql($note);
        $actual_sql = dle_marketplace_price(abs($actual));
        if ($actual < 0) {
            $actual_sql = '-' . $actual_sql;
        }
        $db->query("INSERT INTO {$wallet_log_table} (user_id, amount, balance_after, type, order_id, admin_id, note, created_at) VALUES ('{$user_id}', '{$actual_sql}', '" . dle_marketplace_price($after) . "', '{$type}', '0', '{$admin_id}', '{$note_sql}', '{$now}')");

        dle_marketplace_admin_redirect('wallets', 'balance');
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'delete_product') {
    dle_marketplace_admin_check_request();
    $id = dle_marketplace_int($_GET['id'] ?? 0);
    $product = dle_marketplace_fetch_product($id, true);
    if (!$product) {
        dle_marketplace_admin_fail(dle_marketplace_lang('mp_admin_invalid_product'));
    }

    $orders_table = dle_marketplace_table('orders');
    $paid = $db->super_query("SELECT COUNT(*) AS total FROM {$orders_table} WHERE product_id='{$id}' AND status='paid'", false, false);
    $now = isset($_TIME) ? (int)$_TIME : time();

    if (!empty($paid['total'])) {
        $products_table = dle_marketplace_table('products');
        $db->query("UPDATE {$products_table} SET status='0', updated_at='{$now}' WHERE id='{$id}'");
    } else {
        $db->query("DELETE FROM " . dle_marketplace_table('products') . " WHERE id='{$id}'");
        dle_marketplace_delete_stored_file($product['file_path']);
        dle_marketplace_delete_stored_file($product['preview_image'], true);
    }

    dle_marketplace_admin_redirect('products', 'deleted');
}

$section = isset($_GET['section']) && is_scalar($_GET['section']) ? (string)$_GET['section'] : 'products';
if (!in_array($section, array('products', 'edit', 'orders', 'wallets'), true)) {
    $section = 'products';
}

$header_title = '<i class="fa fa-shopping-bag position-left"></i><span class="text-semibold">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_title')) . '</span>';
$breadcrumb = array('' => dle_marketplace_h(dle_marketplace_lang('mp_admin_title')));
if ($section === 'edit') {
    $breadcrumb = array(dle_marketplace_admin_back_url('products') => dle_marketplace_h(dle_marketplace_lang('mp_admin_products')), '' => dle_marketplace_h(dle_marketplace_lang('mp_admin_edit')));
} elseif ($section === 'orders') {
    $breadcrumb = array('' => dle_marketplace_h(dle_marketplace_lang('mp_admin_orders')));
} elseif ($section === 'wallets') {
    $breadcrumb = array('' => dle_marketplace_h(dle_marketplace_lang('mp_admin_wallets')));
}

echoheader($header_title, $breadcrumb);
echo dle_marketplace_admin_notice();

echo '<div class="row"><div class="col-md-12"><div class="panel panel-default"><div class="panel-heading"><span class="text-semibold">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_title')) . '</span><div class="heading-elements not-collapsible"><ul class="icons-list"><li><a class="btn bg-teal btn-xs btn-raised" href="' . dle_marketplace_h(dle_marketplace_admin_back_url('products')) . '"><i class="fa fa-cubes position-left"></i>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_products')) . '</a></li><li><a class="btn bg-slate-600 btn-xs btn-raised" href="' . dle_marketplace_h(dle_marketplace_admin_back_url('orders')) . '"><i class="fa fa-list position-left"></i>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_orders')) . '</a></li><li><a class="btn bg-slate-600 btn-xs btn-raised" href="' . dle_marketplace_h(dle_marketplace_admin_back_url('wallets')) . '"><i class="fa fa-credit-card position-left"></i>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_wallets')) . '</a></li></ul></div></div><div class="panel-body">';

if ($section === 'edit') {
    $id = dle_marketplace_int($_GET['id'] ?? 0);
    $product = $id > 0 ? dle_marketplace_fetch_product($id, true) : array();
    if ($id > 0 && !$product) {
        echo '<div class="alert alert-danger alert-styled-left alert-component">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_invalid_product')) . '</div>';
    } else {
        $product = array_merge(array('id' => 0, 'title' => '', 'category' => '', 'short_description' => '', 'description' => '', 'price' => '0.00', 'currency' => dle_marketplace_currency(), 'file_path' => '', 'file_name' => '', 'file_size' => 0, 'preview_image' => '', 'status' => 1, 'featured' => 0), $product);
        echo '<form method="post" action="' . dle_marketplace_h(dle_marketplace_admin_back_url('edit')) . '" enctype="multipart/form-data" class="form-horizontal"><input type="hidden" name="action" value="save_product"><input type="hidden" name="id" value="' . (int)$product['id'] . '"><input type="hidden" name="user_hash" value="' . dle_marketplace_h($dle_login_hash) . '">';
        echo '<div class="form-group"><label class="control-label col-sm-3">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_title_field')) . ' <span class="text-danger">*</span></label><div class="col-sm-9"><input class="form-control" type="text" name="title" maxlength="180" value="' . dle_marketplace_h($product['title']) . '" required></div></div>';
        echo '<div class="form-group"><label class="control-label col-sm-3">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_category_field')) . '</label><div class="col-sm-9"><input class="form-control" type="text" name="category" maxlength="100" value="' . dle_marketplace_h($product['category']) . '"></div></div>';
        echo '<div class="form-group"><label class="control-label col-sm-3">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_short_field')) . '</label><div class="col-sm-9"><textarea class="form-control" name="short_description" rows="2" maxlength="500">' . dle_marketplace_h($product['short_description']) . '</textarea></div></div>';
        echo '<div class="form-group"><label class="control-label col-sm-3">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_description_field')) . ' <span class="text-danger">*</span></label><div class="col-sm-9"><textarea class="form-control" name="description" rows="8" required>' . dle_marketplace_h($product['description']) . '</textarea></div></div>';
        echo '<div class="form-group"><label class="control-label col-sm-3">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_price_field')) . '</label><div class="col-sm-9"><div class="row"><div class="col-sm-4"><input class="form-control" type="text" name="price" value="' . dle_marketplace_h($product['price']) . '"></div><div class="col-sm-3"><input class="form-control" type="text" name="currency" maxlength="8" value="' . dle_marketplace_h(dle_marketplace_currency()) . '" readonly title="' . dle_marketplace_h(dle_marketplace_lang('mp_admin_currency_field')) . '"></div></div></div></div>';
        echo '<div class="form-group"><label class="control-label col-sm-3">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_file_field')) . ' <span class="text-danger">*</span></label><div class="col-sm-9"><input type="file" name="product_file" accept=".zip,.pdf,.epub,.mp3,.mp4,.webm,.7z,.rar,.docx,.xlsx,.png,.jpg,.jpeg,.gif" class="form-control"' . ($product['id'] ? '' : ' required') . '><span class="help-block">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_upload_hint')) . '</span>' . ($product['file_name'] ? '<p class="text-muted">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_file_current')) . ': ' . dle_marketplace_h($product['file_name']) . ' (' . dle_marketplace_h(dle_marketplace_format_size($product['file_size'])) . ')</p>' : '') . '</div></div>';
        echo '<div class="form-group"><label class="control-label col-sm-3">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_preview_field')) . '</label><div class="col-sm-9"><input type="file" name="preview_image" accept=".jpg,.jpeg,.png,.gif,.webp" class="form-control"><span class="help-block">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_preview_hint')) . '</span>' . ($product['preview_image'] ? '<p class="text-muted"><img src="' . dle_marketplace_h(dle_marketplace_preview_url($product['preview_image'])) . '" alt="" style="max-width:100px;max-height:70px;"></p>' : '') . '</div></div>';
        echo '<div class="form-group"><div class="col-sm-offset-3 col-sm-9"><label class="checkbox-inline"><input type="checkbox" name="status" value="1"' . ($product['status'] ? ' checked' : '') . '> ' . dle_marketplace_h(dle_marketplace_lang('mp_admin_active')) . '</label> <label class="checkbox-inline"><input type="checkbox" name="featured" value="1"' . ($product['featured'] ? ' checked' : '') . '> ' . dle_marketplace_h(dle_marketplace_lang('mp_admin_featured')) . '</label></div></div>';
        echo '<div class="form-group"><div class="col-sm-offset-3 col-sm-9"><button type="submit" class="btn bg-teal btn-raised position-left"><i class="fa fa-floppy-o position-left"></i>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_save')) . '</button> <a class="btn bg-slate-600 btn-raised" href="' . dle_marketplace_h(dle_marketplace_admin_back_url('products')) . '">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_cancel')) . '</a></div></div></form>';
    }
} elseif ($section === 'orders') {
    $orders_table = dle_marketplace_table('orders');
    $products_table = dle_marketplace_table('products');
    $users_table = USERPREFIX . '_users';
    echo '<h3 class="text-semibold">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_orders')) . '</h3><div class="table-responsive"><table class="table table-striped table-xs table-hover"><thead><tr><th>#</th><th>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_user')) . '</th><th>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_products')) . '</th><th>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_amount')) . '</th><th>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_date')) . '</th><th>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_status')) . '</th><th></th></tr></thead><tbody>';
    $db->query("SELECT o.*, p.title, u.name AS user_name FROM {$orders_table} o LEFT JOIN {$products_table} p ON p.id=o.product_id LEFT JOIN {$users_table} u ON u.user_id=o.user_id ORDER BY o.created_at DESC, o.id DESC LIMIT 100", false);
    $order_count = 0;
    while ($order = $db->get_row()) {
        $order_count++;
        echo '<tr><td>' . (int)$order['id'] . '</td><td>' . dle_marketplace_h($order['user_name'] ?: ('#' . (int)$order['user_id'])) . '<br><small class="text-muted">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_id_label')) . ': ' . (int)$order['user_id'] . '</small></td><td>' . dle_marketplace_h($order['title'] ?: dle_marketplace_lang('mp_product_not_found')) . '</td><td>' . dle_marketplace_h(dle_marketplace_money($order['amount'], $order['currency'])) . '</td><td>' . dle_marketplace_h(dle_marketplace_date($order['created_at'])) . '</td><td><form method="post" action="' . dle_marketplace_h(dle_marketplace_admin_back_url('orders')) . '" class="form-inline"><input type="hidden" name="action" value="update_order"><input type="hidden" name="order_id" value="' . (int)$order['id'] . '"><input type="hidden" name="user_hash" value="' . dle_marketplace_h($dle_login_hash) . '"><select class="form-control input-sm" name="status"><option value="pending"' . ($order['status'] === 'pending' ? ' selected' : '') . '>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_pending')) . '</option><option value="paid"' . ($order['status'] === 'paid' ? ' selected' : '') . '>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_paid')) . '</option><option value="cancelled"' . ($order['status'] === 'cancelled' ? ' selected' : '') . '>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_cancelled')) . '</option></select></td><td><button class="btn btn-xs bg-teal btn-raised" type="submit">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_update')) . '</button></form></td></tr>';
    }
    $db->free();
    if (!$order_count) {
        echo '<tr><td colspan="7" class="text-center text-muted">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_no_orders')) . '</td></tr>';
    }
    echo '</tbody></table></div>';
} elseif ($section === 'wallets') {
    $wallets_table = dle_marketplace_table('wallets');
    $users_table = USERPREFIX . '_users';
    echo '<div class="panel panel-default"><div class="panel-heading"><span class="text-semibold">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_adjust')) . '</span></div><div class="panel-body"><form method="post" action="' . dle_marketplace_h(dle_marketplace_admin_back_url('wallets')) . '" class="form-horizontal"><input type="hidden" name="action" value="adjust_balance"><input type="hidden" name="user_hash" value="' . dle_marketplace_h($dle_login_hash) . '"><div class="form-group"><label class="control-label col-sm-3">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_user_id')) . '</label><div class="col-sm-9"><input class="form-control" type="number" name="user_id" min="1" required></div></div><div class="form-group"><label class="control-label col-sm-3">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_delta')) . '</label><div class="col-sm-9"><input class="form-control" type="text" name="delta" placeholder="' . dle_marketplace_h(dle_marketplace_lang('mp_admin_delta_placeholder')) . '" required></div></div><div class="form-group"><label class="control-label col-sm-3">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_note')) . '</label><div class="col-sm-9"><input class="form-control" type="text" name="note" value="' . dle_marketplace_h(dle_marketplace_lang('mp_admin_reason')) . '" maxlength="255"></div></div><div class="form-group"><div class="col-sm-offset-3 col-sm-9"><button type="submit" class="btn bg-teal btn-raised"><i class="fa fa-plus position-left"></i>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_adjust')) . '</button></div></div></form></div></div>';
    echo '<h3 class="text-semibold">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_wallets')) . '</h3><div class="table-responsive"><table class="table table-striped table-xs table-hover"><thead><tr><th>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_user')) . '</th><th>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_balance')) . '</th><th>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_date')) . '</th></tr></thead><tbody>';
    $db->query("SELECT w.*, u.name AS user_name FROM {$wallets_table} w LEFT JOIN {$users_table} u ON u.user_id=w.user_id ORDER BY w.updated_at DESC LIMIT 100", false);
    $wallet_count = 0;
    while ($wallet = $db->get_row()) {
        $wallet_count++;
        echo '<tr><td>' . dle_marketplace_h($wallet['user_name'] ?: ('#' . (int)$wallet['user_id'])) . ' <small class="text-muted">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_id_label')) . ': ' . (int)$wallet['user_id'] . '</small></td><td><strong>' . dle_marketplace_h(dle_marketplace_money($wallet['balance'], dle_marketplace_currency())) . '</strong></td><td>' . dle_marketplace_h(dle_marketplace_date($wallet['updated_at'])) . '</td></tr>';
    }
    $db->free();
    if (!$wallet_count) {
        echo '<tr><td colspan="3" class="text-center text-muted">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_no_wallets')) . '</td></tr>';
    }
    echo '</tbody></table></div>';
} else {
    $products_table = dle_marketplace_table('products');
    $stats = $db->super_query("SELECT COUNT(*) AS product_count, COALESCE(SUM(sales),0) AS sales_count, COALESCE(SUM(sales*price),0) AS revenue FROM {$products_table}", false, false);
    echo '<div class="row"><div class="col-md-4"><div class="panel panel-body bg-teal-600"><div class="text-size-small text-uppercase">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_products_count')) . '</div><h2 class="no-margin">' . (int)($stats['product_count'] ?? 0) . '</h2></div></div><div class="col-md-4"><div class="panel panel-body bg-slate-600"><div class="text-size-small text-uppercase">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_sales_count')) . '</div><h2 class="no-margin">' . (int)($stats['sales_count'] ?? 0) . '</h2></div></div><div class="col-md-4"><div class="panel panel-body bg-primary-600"><div class="text-size-small text-uppercase">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_revenue')) . '</div><h2 class="no-margin">' . dle_marketplace_h(dle_marketplace_money($stats['revenue'] ?? 0, dle_marketplace_currency())) . '</h2></div></div></div>';
    echo '<div class="clearfix"><a class="btn bg-teal btn-raised position-left" href="' . dle_marketplace_h(dle_marketplace_admin_back_url('edit')) . '"><i class="fa fa-plus-circle position-left"></i>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_add')) . '</a><p class="text-muted position-left" style="margin:8px 0 0 12px;">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_page_hint')) . '</p></div><div class="table-responsive" style="margin-top:20px;"><table class="table table-striped table-xs table-hover"><thead><tr><th>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_title_field')) . '</th><th>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_price_field')) . '</th><th>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_status')) . '</th><th>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_sales')) . '</th><th>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_downloads')) . '</th><th></th></tr></thead><tbody>';
    $db->query("SELECT * FROM {$products_table} ORDER BY created_at DESC, id DESC", false);
    $product_count = 0;
    while ($product = $db->get_row()) {
        $product_count++;
        $status = $product['status'] ? dle_marketplace_lang('mp_admin_active') : dle_marketplace_lang('mp_admin_draft');
        echo '<tr><td><strong>' . dle_marketplace_h($product['title']) . '</strong><br><small class="text-muted">' . dle_marketplace_h($product['category']) . '</small></td><td>' . dle_marketplace_h(dle_marketplace_money($product['price'], $product['currency'])) . '</td><td><span class="label ' . ($product['status'] ? 'label-success' : 'label-default') . '">' . dle_marketplace_h($status) . '</span>' . ($product['featured'] ? ' <span class="label label-info">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_featured')) . '</span>' : '') . '</td><td>' . (int)$product['sales'] . '</td><td>' . (int)$product['downloads'] . '</td><td class="text-right"><a class="btn btn-xs bg-slate-600 btn-raised" href="' . dle_marketplace_h(dle_marketplace_admin_back_url('edit', array('id' => (int)$product['id']))) . '"><i class="fa fa-pencil position-left"></i>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_edit')) . '</a> <a class="btn btn-xs bg-danger btn-raised" href="' . dle_marketplace_h(dle_marketplace_admin_back_url('products', array('action' => 'delete_product', 'id' => (int)$product['id'], 'user_hash' => $dle_login_hash))) . '" onclick="DLEconfirmDelete(\'' . addslashes(dle_marketplace_lang('mp_admin_confirm_delete')) . '\', \'\', function(){ window.location.href=this.href; }.bind(this)); return false;"><i class="fa fa-trash-o position-left"></i>' . dle_marketplace_h(dle_marketplace_lang('mp_admin_delete')) . '</a></td></tr>';
    }
    $db->free();
    if (!$product_count) {
        echo '<tr><td colspan="6" class="text-center text-muted">' . dle_marketplace_h(dle_marketplace_lang('mp_admin_no_products')) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

echo '</div></div></div>';
echofooter();
