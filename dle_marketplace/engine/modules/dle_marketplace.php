<?php
/**
 * Public storefront module.
 *
 * Put this tag on a dedicated DLE static page:
 * {include file="engine/modules/dle_marketplace.php"}
 */

if (!defined('DATALIFEENGINE')) {
    header('HTTP/1.1 403 Forbidden');
    header('Location: ../../');
    die('Hacking attempt!');
}

if (method_exists('DLEPlugins', 'CheckIFActive') && !DLEPlugins::CheckIFActive('dle_marketplace')) {
    return;
}

include_once ROOT_DIR . '/plugins/dle_marketplace/functions.php';
dle_marketplace_load_language();

global $config, $is_logged, $member_id;

if (isset($_GET['mp_download'])) {
    $download_id = dle_marketplace_int($_GET['mp_download']);
    $download_token = isset($_GET['mp_token']) && is_scalar($_GET['mp_token']) ? (string)$_GET['mp_token'] : '';
    $download_user_id = !empty($is_logged) && !empty($member_id['user_id']) ? (int)$member_id['user_id'] : 0;
    dle_marketplace_stream_download($download_user_id, $download_id, $download_token);
}

function dle_marketplace_public_json($payload)
{
    while (ob_get_level()) {
        @ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($_POST['cf_ajax'])) {
    $ajax_action = is_scalar($_POST['cf_ajax']) ? trim((string)$_POST['cf_ajax']) : '';
    $csrf = isset($_POST['cf_csrf']) && is_scalar($_POST['cf_csrf']) ? (string)$_POST['cf_csrf'] : '';

    if (!dle_marketplace_verify_csrf($csrf)) {
        dle_marketplace_public_json(array('success' => false, 'message' => dle_marketplace_lang('mp_csrf_error')));
    }

    $current_user_id = !empty($is_logged) && !empty($member_id['user_id']) ? (int)$member_id['user_id'] : 0;

    switch ($ajax_action) {
        case 'cart_add':
            $product_id = dle_marketplace_int($_POST['product_id'] ?? 0);
            if (!dle_marketplace_cart_add($product_id)) {
                dle_marketplace_public_json(array('success' => false, 'message' => dle_marketplace_lang('mp_product_not_found')));
            }
            dle_marketplace_public_json(array(
                'success' => true,
                'message' => dle_marketplace_lang('mp_in_cart'),
                'cart_count' => count(dle_marketplace_cart()),
            ));
            break;

        case 'cart_remove':
            dle_marketplace_cart_remove($_POST['product_id'] ?? 0);
            dle_marketplace_public_json(array('success' => true, 'redirect' => dle_marketplace_current_url(array('mp_cart' => 1))));
            break;

        case 'cart_clear':
            dle_marketplace_cart_clear();
            dle_marketplace_public_json(array('success' => true, 'redirect' => dle_marketplace_current_url(array('mp_cart' => 1))));
            break;

        case 'checkout':
            if ($current_user_id < 1) {
                dle_marketplace_public_json(array('success' => false, 'message' => dle_marketplace_lang('mp_login_required')));
            }

            $nonce = isset($_POST['cf_nonce']) && is_scalar($_POST['cf_nonce']) ? (string)$_POST['cf_nonce'] : '';
            $valid_nonce = $nonce !== '' && hash_equals(dle_marketplace_post_nonce(), $nonce);

            if (!$valid_nonce) {
                dle_marketplace_public_json(array('success' => false, 'message' => dle_marketplace_lang('mp_request_error')));
            }

            $result = dle_marketplace_checkout($current_user_id, dle_marketplace_cart());

            if (!empty($result['success'])) {
                unset($_SESSION['dle_marketplace_post_nonce']);
                $result['redirect'] = dle_marketplace_current_url(array('mp_orders' => 1, 'mp_notice' => 'success'));
            }

            dle_marketplace_public_json($result);
            break;

        default:
            dle_marketplace_public_json(array('success' => false, 'message' => dle_marketplace_lang('mp_request_error')));
    }
}

function dle_marketplace_public_price($product)
{
    if ((float)$product['price'] <= 0) {
        return '<span class="dle-marketplace-price is-free">' . dle_marketplace_h(dle_marketplace_lang('mp_free')) . '</span>';
    }

    return '<span class="dle-marketplace-price">' . dle_marketplace_h(dle_marketplace_money($product['price'], $product['currency'])) . '</span>';
}

function dle_marketplace_public_action($product, $owned, $in_cart, $form_action, $csrf)
{
    global $is_logged;

    $id = (int)$product['id'];

    if ($owned) {
        $url = dle_marketplace_current_url(array(
            'mp_download' => $id,
            'mp_token' => dle_marketplace_download_token((int)$GLOBALS['member_id']['user_id'], $id),
        ));

        return '<a class="dle-marketplace-button is-secondary" href="' . dle_marketplace_h($url) . '">' . dle_marketplace_h(dle_marketplace_lang('mp_download')) . '</a>';
    }

    return '<form method="post" action="' . dle_marketplace_h($form_action) . '" class="dle-marketplace-ajax-form">'
        . '<input type="hidden" name="cf_ajax" value="cart_add">'
        . '<input type="hidden" name="cf_csrf" value="' . dle_marketplace_h($csrf) . '">'
        . '<input type="hidden" name="product_id" value="' . $id . '">'
        . '<button type="submit" class="dle-marketplace-button">'
        . ($in_cart ? dle_marketplace_h(dle_marketplace_lang('mp_in_cart')) : dle_marketplace_h(dle_marketplace_lang('mp_add_to_cart')))
        . '</button></form>';
}

function dle_marketplace_public_card($product, $owned_ids, $cart_ids, $csrf, $form_action)
{
    $id = (int)$product['id'];
    $owned = isset($owned_ids[$id]);
    $in_cart = in_array($id, $cart_ids, true);
    $product_url = dle_marketplace_current_url(array('mp_product' => $id));
    $short_description = trim((string)$product['short_description']);
    $preview = dle_marketplace_preview_url($product['preview_image']);
    $image = $preview !== ''
        ? '<img src="' . dle_marketplace_h($preview) . '" alt="" loading="lazy">'
        : '<span class="dle-marketplace-card-placeholder"><i class="fa fa-cube"></i></span>';

    $html = '<article class="dle-marketplace-card">'
        . '<a class="dle-marketplace-card-media" href="' . dle_marketplace_h($product_url) . '">' . $image
        . ($product['featured'] ? '<span class="dle-marketplace-badge">' . dle_marketplace_h(dle_marketplace_lang('mp_featured')) . '</span>' : '')
        . '</a>'
        . '<div class="dle-marketplace-card-body">'
        . '<div class="dle-marketplace-card-meta">' . dle_marketplace_h($product['category']) . '</div>'
        . '<h2><a href="' . dle_marketplace_h($product_url) . '">' . dle_marketplace_h($product['title']) . '</a></h2>'
        . '<p>' . ($short_description !== '' ? nl2br(dle_marketplace_h($short_description)) : '') . '</p>'
        . '<div class="dle-marketplace-card-footer"><div>' . dle_marketplace_public_price($product) . '<small>'
        . dle_marketplace_h((int)$product['sales'] . ' ' . dle_marketplace_lang('mp_sales')) . '</small></div>'
        . '<div class="dle-marketplace-card-actions">'
        . '<a class="dle-marketplace-link" href="' . dle_marketplace_h($product_url) . '">' . dle_marketplace_h(dle_marketplace_lang('mp_details')) . '</a>'
        . dle_marketplace_public_action($product, $owned, $in_cart, $form_action, $csrf)
        . '</div></div></div></article>';

    return $html;
}

function dle_marketplace_public_pagination($data, $search, $category, $sort)
{
    if ((int)$data['pages'] < 2) {
        return '';
    }

    $html = '<nav class="dle-marketplace-pagination" aria-label="' . dle_marketplace_h(dle_marketplace_lang('mp_page')) . '">';
    $current = (int)$data['page'];
    $pages = (int)$data['pages'];

    if ($current > 1) {
        $url = dle_marketplace_current_url(array('mp_page' => $current - 1, 'mp_search' => $search, 'mp_category' => $category, 'mp_sort' => $sort));
        $html .= '<a href="' . dle_marketplace_h($url) . '">&larr; ' . dle_marketplace_h(dle_marketplace_lang('mp_prev')) . '</a>';
    }

    $html .= '<span>' . dle_marketplace_h(dle_marketplace_lang('mp_page')) . ' ' . $current . ' ' . dle_marketplace_h(dle_marketplace_lang('mp_of')) . ' ' . $pages . '</span>';

    if ($current < $pages) {
        $url = dle_marketplace_current_url(array('mp_page' => $current + 1, 'mp_search' => $search, 'mp_category' => $category, 'mp_sort' => $sort));
        $html .= '<a href="' . dle_marketplace_h($url) . '">' . dle_marketplace_h(dle_marketplace_lang('mp_next')) . ' &rarr;</a>';
    }

    return $html . '</nav>';
}

function dle_marketplace_public_header($csrf, $cart_count, $store_url, $cart_url, $orders_url, $wallet_balance, $is_logged)
{
    global $config;

    $currency = dle_marketplace_currency();
    $wallet = $is_logged ? '<span class="dle-marketplace-balance"><span>' . dle_marketplace_h(dle_marketplace_lang('mp_balance')) . '</span> ' . dle_marketplace_h(dle_marketplace_money($wallet_balance, $currency)) . '</span>' : '';
    $account = $is_logged
        ? '<a class="dle-marketplace-header-link" href="' . dle_marketplace_h($orders_url) . '">' . dle_marketplace_h(dle_marketplace_lang('mp_purchases')) . '</a>'
        : '<a class="dle-marketplace-header-link" href="' . dle_marketplace_h($config['http_home_url'] . 'index.php?do=login') . '">' . dle_marketplace_h(dle_marketplace_lang('mp_login')) . '</a>';

    return '<link rel="stylesheet" href="' . dle_marketplace_h(dle_marketplace_asset_url('assets/marketplace.css')) . '">'
        . '<div class="dle-marketplace" data-marketplace="1" data-csrf="' . dle_marketplace_h($csrf) . '" data-request-error="' . dle_marketplace_h(dle_marketplace_lang('mp_request_error')) . '">'
        . '<header class="dle-marketplace-hero">'
        . '<div class="dle-marketplace-hero-copy"><span class="dle-marketplace-eyebrow">' . dle_marketplace_h(dle_marketplace_lang('mp_badge')) . '</span>'
        . '<h1>' . dle_marketplace_h(dle_marketplace_lang('mp_title')) . '</h1>'
        . '<p>' . dle_marketplace_h(dle_marketplace_lang('mp_hero_text')) . '</p></div>'
        . '<div class="dle-marketplace-hero-actions">' . $wallet . $account
        . '<a class="dle-marketplace-cart-link" href="' . dle_marketplace_h($cart_url) . '"><i class="fa fa-shopping-bag"></i> '
        . dle_marketplace_h(dle_marketplace_lang('mp_cart')) . ' <b data-cart-count="1">' . (int)$cart_count . '</b></a></div>'
        . '</header>';
}

function dle_marketplace_public_footer()
{
    return '</div><script src="' . dle_marketplace_h(dle_marketplace_asset_url('assets/marketplace.js')) . '"></script>';
}

$csrf = dle_marketplace_csrf_token();
$cart_ids = dle_marketplace_cart();
$current_user_id = !empty($is_logged) && !empty($member_id['user_id']) ? (int)$member_id['user_id'] : 0;
$owned_ids = dle_marketplace_owned_product_ids($current_user_id);
$wallet_balance = dle_marketplace_wallet_balance($current_user_id);
$store_url = dle_marketplace_current_url();
$cart_url = dle_marketplace_current_url(array('mp_cart' => 1));
$orders_url = dle_marketplace_current_url(array('mp_orders' => 1));
$form_action = $store_url;
$cart_count = count($cart_ids);

if (isset($_GET['mp_orders'])) {
    echo dle_marketplace_public_header($csrf, $cart_count, $store_url, $cart_url, $orders_url, $wallet_balance, $current_user_id > 0);
    echo '<section class="dle-marketplace-section">';
    if (isset($_GET['mp_notice']) && is_scalar($_GET['mp_notice']) && $_GET['mp_notice'] === 'success') {
        echo '<div class="dle-marketplace-notice is-success"><i class="fa fa-check-circle"></i> ' . dle_marketplace_h(dle_marketplace_lang('mp_purchase_success')) . '</div>';
    }

    if ($current_user_id < 1) {
        echo '<div class="dle-marketplace-empty"><span class="dle-marketplace-empty-icon"><i class="fa fa-lock"></i></span><h2>' . dle_marketplace_h(dle_marketplace_lang('mp_login_required')) . '</h2>'
            . '<a class="dle-marketplace-button" href="' . dle_marketplace_h($config['http_home_url'] . 'index.php?do=login') . '">' . dle_marketplace_h(dle_marketplace_lang('mp_login')) . '</a></div>';
    } else {
        $orders = dle_marketplace_fetch_user_orders($current_user_id);
        echo '<div class="dle-marketplace-section-heading"><div><span class="dle-marketplace-eyebrow">' . dle_marketplace_h(dle_marketplace_lang('mp_subtitle')) . '</span><h2>' . dle_marketplace_h(dle_marketplace_lang('mp_purchases')) . '</h2></div>'
            . '<a class="dle-marketplace-button is-secondary" href="' . dle_marketplace_h($store_url) . '">' . dle_marketplace_h(dle_marketplace_lang('mp_back')) . '</a></div>';

        if (!count($orders)) {
            echo '<div class="dle-marketplace-empty"><span class="dle-marketplace-empty-icon"><i class="fa fa-download"></i></span><h2>' . dle_marketplace_h(dle_marketplace_lang('mp_no_purchases')) . '</h2>'
                . '<a class="dle-marketplace-button" href="' . dle_marketplace_h($store_url) . '">' . dle_marketplace_h(dle_marketplace_lang('mp_back')) . '</a></div>';
        } else {
            echo '<div class="dle-marketplace-purchases">';
            foreach ($orders as $order) {
                $paid = $order['status'] === 'paid';
                $download_url = $paid && $order['file_path'] !== ''
                    ? dle_marketplace_current_url(array('mp_download' => (int)$order['product_id'], 'mp_token' => dle_marketplace_download_token($current_user_id, (int)$order['product_id'])))
                    : '';
                $status_key = $order['status'] === 'paid' ? 'mp_status_paid' : ($order['status'] === 'pending' ? 'mp_status_pending' : 'mp_status_cancelled');
                echo '<article class="dle-marketplace-purchase-row"><div class="dle-marketplace-purchase-icon"><i class="fa fa-file-o"></i></div><div class="dle-marketplace-purchase-main"><h3>'
                    . dle_marketplace_h($order['title'] ?: dle_marketplace_lang('mp_product_not_found')) . '</h3><p>'
                    . dle_marketplace_h(dle_marketplace_lang('mp_purchase_date')) . ': ' . dle_marketplace_h(dle_marketplace_date($order['paid_at'] ?: $order['created_at'])) . '</p></div>'
                    . '<div class="dle-marketplace-purchase-price">' . dle_marketplace_h(dle_marketplace_money($order['amount'], $order['currency'])) . '<span class="dle-marketplace-status status-' . dle_marketplace_h($order['status']) . '">' . dle_marketplace_h(dle_marketplace_lang($status_key)) . '</span></div>'
                    . ($download_url !== '' ? '<a class="dle-marketplace-button" href="' . dle_marketplace_h($download_url) . '">' . dle_marketplace_h(dle_marketplace_lang('mp_download')) . '</a>' : '')
                    . '</article>';
            }
            echo '</div>';
        }
    }

    echo '</section>' . dle_marketplace_public_footer();
    return;
}

if (isset($_GET['mp_cart'])) {
    $cart_products = dle_marketplace_fetch_products_by_ids($cart_ids);
    $cart_product_ids = array();
    $cart_total = 0.00;

    foreach ($cart_products as $product) {
        $cart_product_ids[] = (int)$product['id'];
        if (!isset($owned_ids[(int)$product['id']])) {
            $cart_total += (float)$product['price'];
        }
    }

    echo dle_marketplace_public_header($csrf, $cart_count, $store_url, $cart_url, $orders_url, $wallet_balance, $current_user_id > 0);
    echo '<section class="dle-marketplace-section"><div class="dle-marketplace-section-heading"><div><span class="dle-marketplace-eyebrow">' . dle_marketplace_h(dle_marketplace_lang('mp_subtitle')) . '</span><h2>' . dle_marketplace_h(dle_marketplace_lang('mp_cart')) . '</h2></div>'
        . '<a class="dle-marketplace-button is-secondary" href="' . dle_marketplace_h($store_url) . '">' . dle_marketplace_h(dle_marketplace_lang('mp_back')) . '</a></div>';

    if (!count($cart_products)) {
        echo '<div class="dle-marketplace-empty"><span class="dle-marketplace-empty-icon"><i class="fa fa-shopping-bag"></i></span><h2>' . dle_marketplace_h(dle_marketplace_lang('mp_cart_empty')) . '</h2><p>' . dle_marketplace_h(dle_marketplace_lang('mp_cart_empty_hint')) . '</p>'
            . '<a class="dle-marketplace-button" href="' . dle_marketplace_h($store_url) . '">' . dle_marketplace_h(dle_marketplace_lang('mp_back')) . '</a></div>';
    } else {
        echo '<div class="dle-marketplace-cart-list">';
        foreach ($cart_products as $product) {
            $id = (int)$product['id'];
            echo '<div class="dle-marketplace-cart-row"><div><span class="dle-marketplace-card-meta">' . dle_marketplace_h($product['category']) . '</span><h3>' . dle_marketplace_h($product['title']) . '</h3></div><div class="dle-marketplace-cart-row-right">'
                . dle_marketplace_public_price($product)
                . (isset($owned_ids[$id]) ? '<span class="dle-marketplace-status status-paid">' . dle_marketplace_h(dle_marketplace_lang('mp_owned')) . '</span>' : '')
                . '<form method="post" action="' . dle_marketplace_h($form_action) . '" class="dle-marketplace-ajax-form"><input type="hidden" name="cf_ajax" value="cart_remove"><input type="hidden" name="cf_csrf" value="' . dle_marketplace_h($csrf) . '"><input type="hidden" name="product_id" value="' . $id . '"><button type="submit" class="dle-marketplace-link-button">' . dle_marketplace_h(dle_marketplace_lang('mp_remove')) . '</button></form></div></div>';
        }
        echo '</div><div class="dle-marketplace-cart-summary"><div><span>' . dle_marketplace_h(dle_marketplace_lang('mp_total')) . '</span><strong>' . dle_marketplace_h(dle_marketplace_money($cart_total, dle_marketplace_currency())) . '</strong></div>';

        if ($current_user_id < 1) {
            echo '<div class="dle-marketplace-notice is-info"><i class="fa fa-info-circle"></i> ' . dle_marketplace_h(dle_marketplace_lang('mp_login_required')) . ' <a href="' . dle_marketplace_h($config['http_home_url'] . 'index.php?do=login') . '">' . dle_marketplace_h(dle_marketplace_lang('mp_login')) . '</a></div>';
        } else {
            echo '<p class="dle-marketplace-balance-note">' . dle_marketplace_h(dle_marketplace_lang('mp_balance')) . ': <b>' . dle_marketplace_h(dle_marketplace_money($wallet_balance, dle_marketplace_currency())) . '</b><br>' . dle_marketplace_h(dle_marketplace_lang('mp_balance_hint')) . '</p>';
            if (count($cart_product_ids) && $cart_total >= 0) {
                echo '<form method="post" action="' . dle_marketplace_h($form_action) . '" class="dle-marketplace-ajax-form dle-marketplace-checkout-form"><input type="hidden" name="cf_ajax" value="checkout"><input type="hidden" name="cf_csrf" value="' . dle_marketplace_h($csrf) . '"><input type="hidden" name="cf_nonce" value="' . dle_marketplace_h(dle_marketplace_post_nonce()) . '"><button type="submit" class="dle-marketplace-button">' . dle_marketplace_h(dle_marketplace_lang('mp_checkout')) . '</button></form>';
            }
        }
        echo '<form method="post" action="' . dle_marketplace_h($form_action) . '" class="dle-marketplace-ajax-form"><input type="hidden" name="cf_ajax" value="cart_clear"><input type="hidden" name="cf_csrf" value="' . dle_marketplace_h($csrf) . '"><button type="submit" class="dle-marketplace-link-button">' . dle_marketplace_h(dle_marketplace_lang('mp_clear_cart')) . '</button></form></div>';
    }

    echo '</section>' . dle_marketplace_public_footer();
    return;
}

$product_id = isset($_GET['mp_product']) ? dle_marketplace_int($_GET['mp_product']) : 0;

if ($product_id > 0) {
    $product = dle_marketplace_fetch_product($product_id);

    echo dle_marketplace_public_header($csrf, $cart_count, $store_url, $cart_url, $orders_url, $wallet_balance, $current_user_id > 0);
    echo '<section class="dle-marketplace-section">';

    if (!$product) {
        echo '<div class="dle-marketplace-empty"><span class="dle-marketplace-empty-icon"><i class="fa fa-search"></i></span><h2>' . dle_marketplace_h(dle_marketplace_lang('mp_product_not_found')) . '</h2><a class="dle-marketplace-button" href="' . dle_marketplace_h($store_url) . '">' . dle_marketplace_h(dle_marketplace_lang('mp_back')) . '</a></div>';
    } else {
        $preview = dle_marketplace_preview_url($product['preview_image']);
        $image = $preview !== '' ? '<img src="' . dle_marketplace_h($preview) . '" alt="">' : '<span class="dle-marketplace-detail-placeholder"><i class="fa fa-cube"></i></span>';
        $owned = isset($owned_ids[(int)$product['id']]);
        $in_cart = in_array((int)$product['id'], $cart_ids, true);
        echo '<div class="dle-marketplace-detail"><div class="dle-marketplace-detail-media">' . $image . '</div><div class="dle-marketplace-detail-content"><a class="dle-marketplace-back" href="' . dle_marketplace_h($store_url) . '">&larr; ' . dle_marketplace_h(dle_marketplace_lang('mp_back')) . '</a><span class="dle-marketplace-eyebrow">' . dle_marketplace_h($product['category']) . '</span><h2>' . dle_marketplace_h($product['title']) . '</h2><div class="dle-marketplace-detail-price">' . dle_marketplace_public_price($product) . '</div><div class="dle-marketplace-description">' . nl2br(dle_marketplace_h($product['description'])) . '</div><dl class="dle-marketplace-facts"><div><dt>' . dle_marketplace_h(dle_marketplace_lang('mp_file')) . '</dt><dd>' . dle_marketplace_h($product['file_name']) . '</dd></div><div><dt>' . dle_marketplace_h(dle_marketplace_lang('mp_size')) . '</dt><dd>' . dle_marketplace_h(dle_marketplace_format_size($product['file_size'])) . '</dd></div><div><dt>' . dle_marketplace_h(dle_marketplace_lang('mp_downloads')) . '</dt><dd>' . dle_marketplace_h((int)$product['downloads']) . '</dd></div></dl><div class="dle-marketplace-detail-action">' . dle_marketplace_public_action($product, $owned, $in_cart, $form_action, $csrf) . '</div></div></div>';
    }

    echo '</section>' . dle_marketplace_public_footer();
    return;
}

$search = isset($_GET['mp_search']) && is_scalar($_GET['mp_search']) ? trim(strip_tags((string)$_GET['mp_search'])) : '';
$category = isset($_GET['mp_category']) && is_scalar($_GET['mp_category']) ? trim(strip_tags((string)$_GET['mp_category'])) : '';
$sort = isset($_GET['mp_sort']) && is_scalar($_GET['mp_sort']) ? (string)$_GET['mp_sort'] : 'newest';
$page = isset($_GET['mp_page']) ? dle_marketplace_int($_GET['mp_page'], 1) : 1;
$catalog = dle_marketplace_fetch_products(array('search' => $search, 'category' => $category, 'sort' => $sort, 'page' => $page, 'per_page' => 12));
$categories = dle_marketplace_fetch_categories();

$notice_key = isset($_GET['mp_notice']) && is_scalar($_GET['mp_notice']) ? (string)$_GET['mp_notice'] : '';
$notice = in_array($notice_key, array('success', 'info'), true) ? '<div class="dle-marketplace-notice is-' . dle_marketplace_h($notice_key) . '"><i class="fa fa-info-circle"></i> ' . dle_marketplace_h(dle_marketplace_lang('mp_purchase_success')) . '</div>' : '';

$filter_url = dle_marketplace_current_url();
echo dle_marketplace_public_header($csrf, $cart_count, $store_url, $cart_url, $orders_url, $wallet_balance, $current_user_id > 0);
echo '<section class="dle-marketplace-section">' . $notice . '<form class="dle-marketplace-toolbar" method="get" action="' . dle_marketplace_h($filter_url) . '"><label class="dle-marketplace-search"><i class="fa fa-search"></i><input type="search" name="mp_search" value="' . dle_marketplace_h($search) . '" placeholder="' . dle_marketplace_h(dle_marketplace_lang('mp_search_placeholder')) . '"></label><select name="mp_category" aria-label="' . dle_marketplace_h(dle_marketplace_lang('mp_category')) . '"><option value="">' . dle_marketplace_h(dle_marketplace_lang('mp_all_categories')) . '</option>';
foreach ($categories as $item) {
    $value = (string)$item['category'];
    echo '<option value="' . dle_marketplace_h($value) . '"' . ($category === $value ? ' selected' : '') . '>' . dle_marketplace_h($value) . ' (' . (int)$item['product_count'] . ')</option>';
}
echo '</select><select name="mp_sort" aria-label="' . dle_marketplace_h(dle_marketplace_lang('mp_sort_newest')) . '"><option value="newest"' . ($sort === 'newest' ? ' selected' : '') . '>' . dle_marketplace_h(dle_marketplace_lang('mp_sort_newest')) . '</option><option value="popular"' . ($sort === 'popular' ? ' selected' : '') . '>' . dle_marketplace_h(dle_marketplace_lang('mp_sort_popular')) . '</option><option value="price_asc"' . ($sort === 'price_asc' ? ' selected' : '') . '>' . dle_marketplace_h(dle_marketplace_lang('mp_sort_price_asc')) . '</option><option value="price_desc"' . ($sort === 'price_desc' ? ' selected' : '') . '>' . dle_marketplace_h(dle_marketplace_lang('mp_sort_price_desc')) . '</option></select><button class="dle-marketplace-button" type="submit">' . dle_marketplace_h(dle_marketplace_lang('mp_search')) . '</button></form>';

if (!count($catalog['items'])) {
    echo '<div class="dle-marketplace-empty"><span class="dle-marketplace-empty-icon"><i class="fa fa-cubes"></i></span><h2>' . dle_marketplace_h(dle_marketplace_lang('mp_no_products')) . '</h2><p>' . dle_marketplace_h(dle_marketplace_lang('mp_no_products_hint')) . '</p></div>';
} else {
    echo '<div class="dle-marketplace-grid">';
    foreach ($catalog['items'] as $product) {
        echo dle_marketplace_public_card($product, $owned_ids, $cart_ids, $csrf, $form_action);
    }
    echo '</div>' . dle_marketplace_public_pagination($catalog, $search, $category, $sort);
}

echo '</section>' . dle_marketplace_public_footer();
