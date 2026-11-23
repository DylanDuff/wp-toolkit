<?php

/**
 * Public helpers for the Shopify Product(s) ACF fields. Names match the
 * standalone "ACF Shopify Product Field" plugin so theme and Bricks code that
 * calls them keeps working after the switch. Only loaded while that plugin is
 * inactive. See docs/acf-shopify-products.md.
 */

use DDWPTweaks\Shopify\Client;

defined('ABSPATH') || exit;

/**
 * Live product data for a stored GID, e.g. "gid://shopify/Product/1234567890".
 *
 * @return array|WP_Error
 */
function aspf_get_product($gid)
{
    $client = Client::from_settings();
    return is_wp_error($client) ? $client : $client->get_product((string) $gid);
}

/**
 * Product summaries (gid, title, handle, image, vendor, price, currency) for
 * a list of GIDs, in order, in one API call.
 *
 * @return array|WP_Error
 */
function aspf_get_products($gids)
{
    $client = Client::from_settings();
    return is_wp_error($client) ? $client : $client->get_products((array) $gids);
}

/**
 * Read a Shopify Product field (a GID) and resolve it in one call.
 *
 * @return array|WP_Error|null Null when the field is empty.
 */
function aspf_get_product_field($selector, $post_id = null)
{
    if (!function_exists('get_field')) {
        return new WP_Error('aspf_acf_missing', 'Advanced Custom Fields is not active.');
    }

    $gid = get_field($selector, $post_id);
    return empty($gid) ? null : aspf_get_product($gid);
}

/**
 * Read a Shopify Products field (GIDs) and resolve it to summaries, in the
 * field's stored order.
 *
 * @return array|WP_Error
 */
function aspf_get_products_field($selector, $post_id = null)
{
    if (!function_exists('get_field')) {
        return new WP_Error('aspf_acf_missing', 'Advanced Custom Fields is not active.');
    }

    $gids = get_field($selector, $post_id);
    return empty($gids) || !is_array($gids) ? [] : aspf_get_products($gids);
}

/**
 * Summaries for admin rendering, memoized per request. Unresolvable GIDs
 * (e.g. a product deleted in Shopify) fall back to the GID as the title.
 */
function aspf_get_cached_products_summary($gids)
{
    static $cache = [];

    $missing = array_values(array_diff((array) $gids, array_keys($cache)));
    if ($missing) {
        $fetched = aspf_get_products($missing);
        foreach (is_wp_error($fetched) ? [] : $fetched as $product) {
            $cache[$product['gid']] = $product;
        }
    }

    return array_map(static fn($gid) => $cache[$gid] ?? [
        'gid'      => $gid,
        'title'    => $gid,
        'handle'   => '',
        'image'    => '',
        'vendor'   => '',
        'price'    => '',
        'currency' => '',
    ], array_values((array) $gids));
}

/** Title for one selected GID in the admin field, '' when it can't be resolved. */
function aspf_get_cached_product_label($gid)
{
    $product = aspf_get_cached_products_summary([$gid])[0];
    return $product['title'] === $gid ? '' : $product['title'];
}

/** Collections for the field settings and filter, memoized; [] on failure. */
function aspf_get_cached_collections()
{
    static $collections = null;

    if ($collections === null) {
        $client      = Client::from_settings();
        $result      = is_wp_error($client) ? [] : $client->get_collections();
        $collections = is_wp_error($result) ? [] : $result;
    }

    return $collections;
}
