<?php

namespace DDWPTweaks\SEO;

defined('ABSPATH') || exit;

/**
 * Where token values come from, and the list of sources each post type
 * offers to the editor.
 *
 *   post.title | post.url | post.excerpt | post.content | post.date | post.modified
 *   post.thumbnail | post.author | post.id | post.type
 *   acf:group.sub_field   ACF value, dot path into groups
 *   meta:key              raw post meta
 *   tax:a,b               assigned terms of one or more taxonomies
 *   option:field          ACF options page value (falls back to get_option)
 *   site.name | site.url | site.logo
 *   item | item.field | item.@index   current row inside @each
 *   ref:organization | ref:website    {"@id": ...} reference to a site node
 */
class Schema_Sources
{
    private const PREFIXES = ['post.', 'acf:', 'meta:', 'tax:', 'option:', 'site.', 'item', 'ref:'];

    private static $site = [];

    /** Site-wide values set by the tweak from its Organization settings. */
    public static function configure(array $site): void
    {
        self::$site = $site;
    }

    public static function site(string $key)
    {
        $defaults = [
            'name' => get_bloginfo('name'),
            'url'  => home_url('/'),
            'logo' => ($logo_id = get_theme_mod('custom_logo')) ? wp_get_attachment_image_url($logo_id, 'full') : null,
        ];

        return !Schema_Renderer::is_empty(self::$site[$key] ?? null) ? self::$site[$key] : ($defaults[$key] ?? null);
    }

    public static function node_id(string $name): string
    {
        return home_url('/') . '#' . $name;
    }

    public static function is_source(string $text): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($text, $prefix)) return true;
        }
        return false;
    }

    public static function resolve(string $source, Schema_Renderer $renderer)
    {
        $post_id = $renderer->post_id();

        if ($source === 'item' || str_starts_with($source, 'item.')) {
            $item = $renderer->item();
            if (!$item) return null;
            $path = substr($source, 5);
            if ($path === '@index') return $item['index'];
            return $path === '' || $path === false ? $item['row'] : self::descend($item['row'], explode('.', $path));
        }

        [$kind, $key] = str_contains($source, ':')
            ? explode(':', $source, 2)
            : array_pad(explode('.', $source, 2), 2, '');

        switch ($kind) {
            case 'post':
                return self::post_value($post_id, $key);

            case 'site':
                return self::site($key);

            case 'ref':
                return ['@id' => self::node_id($key)];

            case 'acf':
                if (!function_exists('get_field')) return null;
                $path = explode('.', $key);
                return self::descend(get_field(array_shift($path), $post_id), $path);

            case 'option':
                $path  = explode('.', $key);
                $first = array_shift($path);
                $value = function_exists('get_field') ? get_field($first, 'option') : null;
                return self::descend($value ?? get_option($first, null), $path);

            case 'meta':
                return get_post_meta($post_id, $key, true);

            case 'tax':
                $terms = [];
                foreach (array_map('trim', explode(',', $key)) as $taxonomy) {
                    $assigned = get_the_terms($post_id, $taxonomy);
                    if (is_array($assigned)) array_push($terms, ...$assigned);
                }
                return $terms;
        }

        $renderer->warn("Unknown source \"{$source}\"");
        return null;
    }

    private static function post_value(int $post_id, string $key)
    {
        $post = get_post($post_id);
        if (!$post) return null;

        switch ($key) {
            case 'id':        return $post->ID;
            case 'type':      return $post->post_type;
            case 'title':     return get_the_title($post);
            case 'url':       return get_permalink($post);
            case 'excerpt':   return has_excerpt($post) ? Schema_Transforms::clean($post->post_excerpt) : null;
            case 'content':   return $post->post_content;
            case 'date':      return get_the_date('c', $post);
            case 'modified':  return get_the_modified_date('c', $post);
            case 'thumbnail': return get_the_post_thumbnail_url($post, 'full') ?: null;
            case 'author':    return get_the_author_meta('display_name', $post->post_author) ?: null;
        }

        return null;
    }

    /** Walks a dot path through arrays and objects. */
    private static function descend($value, array $path)
    {
        foreach ($path as $segment) {
            if ($segment === '') continue;

            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } elseif (is_object($value) && in_array($segment, ['name', 'slug', 'url', 'title'], true)) {
                $value = Schema_Transforms::pluck($value, $segment);
            } elseif (is_object($value) && isset($value->$segment)) {
                $value = $value->$segment;
            } else {
                return null;
            }
        }

        return $value;
    }

    /* ---------- Editor catalogue ---------- */

    /**
     * Sources for each public post type, grouped for the editor's picker.
     * ACF repeaters are flagged as lists and carry their row fields so the
     * editor can offer item.* inside a loop.
     */
    public static function catalogue(): array
    {
        $post_types = get_post_types(['public' => true], 'objects');
        unset($post_types['attachment']);

        $shared = [
            [
                'label'   => 'Site',
                'sources' => [
                    ['value' => 'site.name',        'label' => 'Site name'],
                    ['value' => 'site.url',         'label' => 'Home URL'],
                    ['value' => 'site.logo',        'label' => 'Logo'],
                    ['value' => 'ref:organization', 'label' => 'Organization (reference)'],
                    ['value' => 'ref:website',      'label' => 'WebSite (reference)'],
                ],
            ],
        ];

        $options = self::acf_sources(self::options_field_groups(), 'option:');
        if ($options) {
            $shared[] = ['label' => 'Site Options', 'sources' => $options];
        }

        $catalogue = [];

        foreach ($post_types as $slug => $object) {
            $groups = [[
                'label'   => 'Post',
                'sources' => [
                    ['value' => 'post.title',     'label' => 'Title'],
                    ['value' => 'post.url',       'label' => 'Permalink'],
                    ['value' => 'post.content',   'label' => 'Content'],
                    ['value' => 'post.excerpt',   'label' => 'Excerpt'],
                    ['value' => 'post.thumbnail', 'label' => 'Featured image'],
                    ['value' => 'post.date',      'label' => 'Published date'],
                    ['value' => 'post.modified',  'label' => 'Modified date'],
                    ['value' => 'post.author',    'label' => 'Author name'],
                    ['value' => 'post.id',        'label' => 'ID'],
                ],
            ]];

            $field_groups = function_exists('acf_get_field_groups') ? acf_get_field_groups(['post_type' => $slug]) : [];
            $acf          = self::acf_sources($field_groups, 'acf:');
            if ($acf) {
                $groups[] = ['label' => 'ACF', 'sources' => $acf];
            }

            $taxonomies = [];
            foreach (get_object_taxonomies($slug, 'objects') as $taxonomy) {
                if (!$taxonomy->public || $taxonomy->name === 'post_format') continue;
                $taxonomies[] = [
                    'value'    => 'tax:' . $taxonomy->name,
                    'label'    => $taxonomy->labels->name,
                    'list'     => true,
                    'children' => [['value' => 'item.name', 'label' => 'Name'], ['value' => 'item.slug', 'label' => 'Slug'], ['value' => 'item.url', 'label' => 'URL']],
                ];
            }
            if ($taxonomies) {
                $groups[] = ['label' => 'Taxonomies', 'sources' => $taxonomies];
            }

            $catalogue[$slug] = [
                'label'   => $object->labels->name,
                'sources' => array_merge($groups, $shared),
            ];
        }

        return $catalogue;
    }

    private static function options_field_groups(): array
    {
        if (!function_exists('acf_get_field_groups')) return [];

        return array_values(array_filter(acf_get_field_groups(), function ($group) {
            foreach ((array) ($group['location'] ?? []) as $rules) {
                foreach ((array) $rules as $rule) {
                    if (($rule['param'] ?? '') === 'options_page') return true;
                }
            }
            return false;
        }));
    }

    private static function acf_sources(array $field_groups, string $prefix): array
    {
        $sources = [];

        foreach ($field_groups as $group) {
            $fields = function_exists('acf_get_fields') ? (acf_get_fields($group) ?: []) : [];
            self::walk_fields($fields, $prefix, '', $group['title'] ?? '', $sources);
        }

        return $sources;
    }

    private static function walk_fields(array $fields, string $prefix, string $path, string $context, array &$sources): void
    {
        foreach ($fields as $field) {
            if (empty($field['name']) || in_array($field['type'], ['tab', 'message', 'accordion'], true)) continue;

            $value = $path . $field['name'];
            $label = trim($context . ' › ' . ($field['label'] ?: $field['name']), ' ›');

            if ($field['type'] === 'group') {
                self::walk_fields($field['sub_fields'] ?? [], $prefix, $value . '.', $label, $sources);
                continue;
            }

            $entry = ['value' => $prefix . $value, 'label' => $label, 'type' => $field['type']];

            if (in_array($field['type'], ['repeater', 'flexible_content'], true)) {
                $children = [];
                self::walk_fields($field['sub_fields'] ?? [], 'item.', '', '', $children);
                $entry['list']     = true;
                $entry['children'] = array_merge([['value' => 'item.@index', 'label' => 'Row number']], $children);
            }

            if (in_array($field['type'], ['relationship', 'post_object', 'taxonomy', 'gallery', 'checkbox', 'select'], true)) {
                $entry['list'] = !empty($field['multiple']) || in_array($field['type'], ['relationship', 'gallery', 'checkbox'], true);
            }

            $sources[] = $entry;
        }
    }
}
