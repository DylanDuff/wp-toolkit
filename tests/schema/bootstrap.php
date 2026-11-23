<?php

/**
 * Minimal WordPress stubs for running the schema renderer outside WordPress.
 * Posts live in $GLOBALS['schema_test_posts'], keyed by ID, as arrays of
 * post fields plus 'acf' (field values) and 'status'.
 */

define('ABSPATH', __DIR__ . '/');

class WP_Post
{
    public $ID;
    public $post_type = 'post';
    public $post_name = '';
    public $post_title = '';
    public $post_content = '';
    public $post_excerpt = '';
    public $post_status = 'publish';
    public $post_author = 1;
}

class WP_Term
{
    public $term_id;
    public $name;
    public $slug;
    public $taxonomy;
}

class WP_Error
{
}

$GLOBALS['schema_test_posts']    = [];
$GLOBALS['schema_test_timezone'] = 'Australia/Sydney';

function schema_test_post($id)
{
    $id = is_object($id) ? $id->ID : (int) $id;
    return $GLOBALS['schema_test_posts'][$id] ?? null;
}

function get_post($id)
{
    $data = schema_test_post($id);
    if (!$data) return null;

    $post = new WP_Post();
    foreach ($data as $key => $value) {
        if (property_exists($post, $key)) $post->$key = $value;
    }
    $post->ID = $data['ID'];
    return $post;
}

function get_post_status($post)
{
    return schema_test_post($post)['post_status'] ?? 'publish';
}

function get_the_title($post)
{
    return schema_test_post($post)['post_title'] ?? '';
}

function get_permalink($post)
{
    return 'https://example.com/?p=' . (is_object($post) ? $post->ID : (int) $post);
}

function has_excerpt($post)
{
    return (schema_test_post($post)['post_excerpt'] ?? '') !== '';
}

function get_field($name, $id)
{
    return schema_test_post($id)['acf'][$name] ?? null;
}

function get_fields($id)
{
    return $id === 'option' ? [] : (schema_test_post($id)['acf'] ?? false);
}

function get_the_date($format, $post) { return ''; }
function get_the_modified_date($format, $post) { return ''; }
function get_the_post_thumbnail_url($post) { return false; }
function get_the_author_meta() { return ''; }
function get_post_meta($id, $key, $single) { return schema_test_post($id)['meta'][$key] ?? ''; }
function get_the_terms($id, $taxonomy) { return false; }
function get_term($id) { return null; }
function get_term_by() { return false; }
function get_term_link($term) { return ''; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_option($key, $default = null) { return $default; }
function home_url($path = '') { return 'https://example.com' . $path; }
function get_bloginfo() { return 'Example'; }
function get_theme_mod() { return null; }
function wp_get_attachment_image_url($id) { return 'https://example.com/image-' . $id . '.jpg'; }
function apply_filters($hook, $value) { return $value; }
function wp_timezone() { return new DateTimeZone($GLOBALS['schema_test_timezone']); }

function wp_strip_all_tags($text)
{
    return strip_tags(preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text));
}

foreach (['syntax', 'transforms', 'sources', 'renderer', 'types'] as $file) {
    require dirname(__DIR__, 2) . "/inc/seo/class-schema-$file.php";
}
