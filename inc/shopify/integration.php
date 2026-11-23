<?php

namespace DDWPTweaks\Shopify;

use DDWPTweaks\Bricks_Tags;

defined('ABSPATH') || exit;

/**
 * Wiring for the Shopify Product(s) ACF fields: field type registration, the
 * admin search endpoint and the Bricks loop tags. Action, nonce, tag and CSS
 * names match the standalone plugin. See docs/acf-shopify-products.md.
 */

const SEARCH_ACTION   = 'aspf_search_products';
const BRICKS_SOURCE   = 'shopify_products';
const BRICKS_GROUP    = 'Shopify Products (ACF)';

/* ---------- Shared field helpers ---------- */

function placeholder(array $field): string
{
    return !empty($field['placeholder']) ? $field['placeholder'] : 'Search Shopify products…';
}

function render_placeholder_setting(array $field): void
{
    acf_render_field_setting($field, [
        'label'        => 'Placeholder Text',
        'instructions' => 'Appears in the empty search input.',
        'type'         => 'text',
        'name'         => 'placeholder',
    ]);
}

/** Enqueue a field's script plus the stylesheet both fields share. */
function enqueue_field_assets(string $script, array $dependencies): void
{
    wp_enqueue_style('ddwpt-acf-shopify', DDWPT_URL . 'assets/css/acf-shopify.css', [], DDWPT_VERSION);
    wp_enqueue_script('ddwpt-' . $script, DDWPT_URL . 'assets/js/' . $script . '.js', $dependencies, DDWPT_VERSION, true);
}

/* ---------- Field types + search endpoint ---------- */

function register_field_types(): void
{
    require_once __DIR__ . '/class-field-product.php';
    require_once __DIR__ . '/class-field-products.php';

    acf_register_field_type(Field_Product::class);
    acf_register_field_type(Field_Products::class);
}

function search_products(): void
{
    check_ajax_referer(SEARCH_ACTION, 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
    }

    $term       = sanitize_text_field(wp_unslash($_GET['term'] ?? ''));
    $exclude    = array_map('sanitize_text_field', (array) wp_unslash($_GET['exclude'] ?? []));
    $collection = sanitize_text_field(wp_unslash($_GET['collection'] ?? ''));

    $client = Client::from_settings();
    if (is_wp_error($client)) {
        wp_send_json_error(['message' => $client->get_error_message()], 400);
    }

    $results = $collection !== ''
        ? $client->search_products_in_collection($collection, $term)
        : $client->search_products($term);

    if (is_wp_error($results)) {
        wp_send_json_error(['message' => $results->get_error_message()], 502);
    }

    $results = array_values(array_filter($results, static fn($product) => !in_array($product['gid'], $exclude, true)));

    wp_send_json_success(['results' => $results]);
}

/* ---------- Bricks ---------- */

/** Loop item tag => product summary key. */
const BRICKS_LOOP_TAGS = [
    'shopify_product_title'    => ['key' => 'title',    'label' => 'Shopify Product Title'],
    'shopify_product_image'    => ['key' => 'image',    'label' => 'Shopify Product Image'],
    'shopify_product_handle'   => ['key' => 'handle',   'label' => 'Shopify Product Handle'],
    'shopify_product_gid'      => ['key' => 'gid',      'label' => 'Shopify Product GID'],
    'shopify_product_vendor'   => ['key' => 'vendor',   'label' => 'Shopify Product Vendor'],
    'shopify_product_price'    => ['key' => 'price',    'label' => 'Shopify Product Price'],
    'shopify_product_currency' => ['key' => 'currency', 'label' => 'Shopify Product Currency'],
];

/**
 * `{shopify_products:FIELD}` as a loop source, plus per-item tags reading the
 * current loop object. Item tags go through Bricks_Tags; the loop source is
 * wired by hand because it must return raw JSON (Bricks_Tags escapes content).
 */
function register_bricks(): void
{
    $item_tags = [];
    foreach (BRICKS_LOOP_TAGS as $name => $tag) {
        $item_tags[$name] = [
            'label'   => $tag['label'],
            'resolve' => static fn() => (string) bricks_loop_value($tag['key']),
            'image'   => $tag['key'] === 'image',
        ];
    }
    Bricks_Tags::register(BRICKS_GROUP, $item_tags);

    add_filter('bricks/dynamic_tags_list', __NAMESPACE__ . '\\bricks_source_tags');
    add_filter('bricks/dynamic_data/render_tag', __NAMESPACE__ . '\\bricks_render_source_tag', 20, 1);

    // PHP_INT_MAX: other plugins on these filters (WPSR, BricksExtras) assume
    // string content and have fataled when they ran after a loop source.
    add_filter('bricks/dynamic_data/render_content', __NAMESPACE__ . '\\bricks_render_source_content', PHP_INT_MAX, 1);
    add_filter('bricks/frontend/render_data', __NAMESPACE__ . '\\bricks_render_source_content', PHP_INT_MAX, 1);
}

/** One loop-source tag per Shopify Products field in the field groups. */
function bricks_source_tags($list)
{
    if (!function_exists('acf_get_field_groups')) {
        return $list;
    }

    foreach (acf_get_field_groups() as $group) {
        foreach (acf_get_fields($group['key']) ?: [] as $field) {
            if ($field['type'] === 'shopify_products') {
                $list[] = [
                    'name'  => '{' . BRICKS_SOURCE . ':' . $field['name'] . '}',
                    'label' => $field['label'] . ' (loop source)',
                    'group' => BRICKS_GROUP,
                ];
            }
        }
    }

    return $list;
}

/**
 * JSON product summaries for a loop source. Bricks' Array query type
 * json_decode()s the tag's value, so this is a JSON string, not an array.
 * Reads the current post first, then the options page under the same name.
 */
function bricks_source_json(string $field_name): string
{
    $post_id  = get_the_ID();
    $products = $post_id ? aspf_get_products_field($field_name, $post_id) : [];

    if (is_wp_error($products) || empty($products)) {
        $products = aspf_get_products_field($field_name, 'option');
    }

    return wp_json_encode(is_array($products) ? $products : []);
}

function bricks_render_source_tag($tag)
{
    if (is_string($tag) && strpos($tag, BRICKS_SOURCE . ':') !== false
        && preg_match('/^\{?' . BRICKS_SOURCE . ':([^}]+)\}?$/', $tag, $match)) {
        return bricks_source_json($match[1]);
    }

    return $tag;
}

function bricks_render_source_content($content)
{
    if (is_string($content) && strpos($content, '{' . BRICKS_SOURCE . ':') !== false
        && preg_match('/^\s*\{' . BRICKS_SOURCE . ':([^}]+)\}\s*$/', $content, $match)) {
        return bricks_source_json($match[1]);
    }

    return $content;
}

/** A key of the current Bricks loop object, or '' outside a product loop. */
function bricks_loop_value(string $key)
{
    if (!class_exists('\Bricks\Query')) {
        return '';
    }

    $object = \Bricks\Query::get_loop_object();

    return is_array($object) && array_key_exists($key, $object) ? $object[$key] : '';
}
