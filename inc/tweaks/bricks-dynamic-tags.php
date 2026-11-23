<?php

namespace DDWPTweaks\Tweaks;

use DDWPTweaks\Bricks_Tags;

/**
 * Minutes to read a post's content, at least 1. Bricks-built posts keep their
 * content in post meta, so this counts post_content only (blocks or classic).
 */
function dynamic_tags_reading_minutes($post, int $words_per_minute): int
{
    $post = get_post($post);

    if (!$post) {
        return 0;
    }

    $text  = wp_strip_all_tags(strip_shortcodes($post->post_content));
    $words = preg_match_all("/[\\p{L}\\p{N}'’-]+/u", $text);

    return max(1, (int) ceil($words / max(1, $words_per_minute)));
}

/**
 * Published post count.
 *   [type]                      posts of a post type
 *   [taxonomy, term]            posts in a term (term count, all post types)
 *   [type, taxonomy, term]      posts of a type in a term; term "any" or "none"
 *                               matches posts with at least one / no term
 */
function dynamic_tags_post_count(array $args): int
{
    static $cache = [];

    $parts = array_values(array_filter(array_map('sanitize_key', $args)));
    $key   = implode(':', $parts);

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    switch (count($parts)) {
        case 0:
        case 1:
            $type  = $parts[0] ?? 'post';
            $count = post_type_exists($type) ? (int) wp_count_posts($type)->publish : 0;
            break;

        case 2:
            $term  = get_term_by('slug', $parts[1], $parts[0]);
            $count = $term ? (int) $term->count : 0;
            break;

        default:
            [$type, $taxonomy, $term] = $parts;

            if (!post_type_exists($type) || !taxonomy_exists($taxonomy)) {
                $count = 0;
                break;
            }

            $operators = ['none' => 'NOT EXISTS', 'any' => 'EXISTS'];
            $tax_query = isset($operators[$term])
                ? ['taxonomy' => $taxonomy, 'operator' => $operators[$term]]
                : ['taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $term];

            $query = new \WP_Query([
                'post_type'              => $type,
                'post_status'            => 'publish',
                'posts_per_page'         => 1,
                'fields'                 => 'ids',
                'tax_query'              => [$tax_query],
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]);

            $count = (int) $query->found_posts;
    }

    return $cache[$key] = $count;
}

/**
 * What the current page is, from the main query rather than the render context:
 * the post type on a single, "term" on a taxonomy archive, otherwise "other".
 * Never empty, so == and != element conditions compare safely.
 */
function dynamic_tags_page_type(): string
{
    $queried = get_queried_object();

    if ($queried instanceof \WP_Post) {
        return $queried->post_type;
    }

    if ($queried instanceof \WP_Term) {
        return 'term';
    }

    return 'other';
}

return [
    'id'    => 'ddwpt_bricks_dynamic_tags',
    'label' => 'Extra Dynamic Tags',
    'tab'   => 'bricks',
    'group' => 'builder',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Add reading time, post count and page type dynamic data tags to Bricks, under the "WP Toolkit" group.',
        ],
        [
            'id'          => 'reading_time',
            'type'        => 'checkbox',
            'label'       => 'Reading time',
            'description' => '<code>{post_reading_time}</code> gives "6 min", <code>{post_reading_time:number}</code> gives "6". Works in query loops.',
            'default'     => '1',
        ],
        [
            'id'          => 'words_per_minute',
            'type'        => 'text',
            'label'       => 'Words per minute',
            'description' => 'Reading speed used by the reading time tag.',
            'default'     => '220',
            'show_if'     => ['reading_time' => '1'],
        ],
        [
            'id'          => 'reading_unit',
            'type'        => 'text',
            'label'       => 'Reading time unit',
            'description' => 'Text after the number, e.g. "min" or "minute read".',
            'default'     => 'min',
            'show_if'     => ['reading_time' => '1'],
        ],
        [
            'id'          => 'post_count',
            'type'        => 'checkbox',
            'label'       => 'Post count',
            'description' => 'Published posts: <code>{post_count:book}</code> by post type, <code>{post_count:category:news}</code> in a term, <code>{post_count:book:genre:crime}</code> of a type in a term. Use <code>any</code> or <code>none</code> as the term for posts with at least one or no term.',
            'default'     => '1',
        ],
        [
            'id'          => 'page_type',
            'type'        => 'checkbox',
            'label'       => 'Page type',
            'description' => '<code>{page_type}</code> gives the post type on a single, "term" on a taxonomy archive, otherwise "other". Unlike <code>{post_type}</code> it reads the main query, so a template shared by singles and term archives can tell them apart in element conditions.',
            'default'     => '1',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled']) || !defined('BRICKS_VERSION')) {
            return;
        }

        $tags = [];

        if (!empty($settings['reading_time'])) {
            $words_per_minute = absint($settings['words_per_minute']) ?: 220;
            $unit             = trim((string) $settings['reading_unit']);

            $tags['post_reading_time'] = [
                'label'   => 'Reading time',
                'resolve' => static function (array $args, $post) use ($words_per_minute, $unit) {
                    $minutes = dynamic_tags_reading_minutes($post, $words_per_minute);

                    if (!$minutes) {
                        return '';
                    }

                    return ($args[0] ?? '') === 'number' || $unit === '' ? (string) $minutes : "{$minutes} {$unit}";
                },
            ];
        }

        if (!empty($settings['post_count'])) {
            $tags['post_count'] = [
                'label'   => 'Published post count (add :post-type)',
                'resolve' => static fn(array $args) => (string) dynamic_tags_post_count($args),
            ];
        }

        if (!empty($settings['page_type'])) {
            $tags['page_type'] = [
                'label'   => 'Page type (post type, "term" or "other")',
                'resolve' => static fn() => dynamic_tags_page_type(),
            ];
        }

        Bricks_Tags::register('WP Toolkit', $tags);
    },
];
