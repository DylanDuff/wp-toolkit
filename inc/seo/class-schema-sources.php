<?php

namespace DDWPTweaks\SEO;

defined('ABSPATH') || exit;

/**
 * Where token values come from, and the list of sources each post type
 * offers to the editor. Tag names follow Bricks dynamic data:
 *
 *   post_title | post_url | post_excerpt | post_content | post_date | post_modified
 *   featured_image | author_name | post_id | post_type
 *   acf_group_sub_field     ACF value; field names joined by "_" into groups and rows
 *   acf_option_field        ACF options page value (falls back to get_option)
 *   cf_key                  raw post meta (Bricks' custom field prefix)
 *   post_terms_taxonomy     assigned terms
 *   site_title | site_url | site_logo
 *   item | item_field | query_loop_index   current row inside @each
 *   ref_organization | ref_website         {"@id": ...} reference to a site node
 *
 * The earlier dotted names (post.title, acf:group.field, tax:a,b, item.@index,
 * ref:organization...) still resolve. Underscored paths are matched against
 * the actual field and row keys, longest first, so field names may contain
 * underscores themselves.
 */
class Schema_Sources
{
    private const PREFIXES = ['post.', 'acf:', 'meta:', 'tax:', 'option:', 'site.', 'item', 'ref:'];

    private const TAG_PREFIXES = ['post_', 'acf_', 'cf_', 'site_', 'ref_', 'item', 'query_loop_index', 'featured_image', 'author_name'];

    /** Bricks tag → earlier source name, for tags that map one to one. */
    private const TAGS = [
        'post_title'       => 'post.title',
        'post_url'         => 'post.url',
        'post_excerpt'     => 'post.excerpt',
        'post_content'     => 'post.content',
        'post_date'        => 'post.date',
        'post_modified'    => 'post.modified',
        'featured_image'   => 'post.thumbnail',
        'author_name'      => 'post.author',
        'post_id'          => 'post.id',
        'post_type'        => 'post.type',
        'site_title'       => 'site.name',
        'site_url'         => 'site.url',
        'site_logo'        => 'site.logo',
        'query_loop_index' => 'item.@index',
    ];

    private static $site = [];

    /**
     * Site-wide values set by the tweak: name, logo, the site `nodes`
     * (organization, website) and whether `ref:` tokens inline them.
     */
    public static function configure(array $site): void
    {
        self::$site = array_merge(self::$site, $site);
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
        foreach (array_merge(self::PREFIXES, self::TAG_PREFIXES) as $prefix) {
            if (str_starts_with($text, $prefix)) return true;
        }
        return false;
    }

    public static function resolve(string $source, Schema_Renderer $renderer)
    {
        $post_id = $renderer->post_id();
        $source  = self::TAGS[$source] ?? $source;

        // Bricks-style tags with an underscored path
        if (preg_match('/^(acf_option_|acf_|cf_|post_terms_|ref_|item_)(.+)$/', $source, $tag)) {
            [, $prefix, $path] = $tag;
            switch ($prefix) {
                case 'acf_option_':
                    $fields = function_exists('get_fields') ? (get_fields('option') ?: []) : [];
                    $value  = self::descend_tag($fields, $path);
                    return $value ?? get_option($path, null);
                case 'acf_':
                    return self::descend_tag(function_exists('get_fields') ? (get_fields($post_id) ?: []) : [], $path);
                case 'cf_':
                    return get_post_meta($post_id, $path, true);
                case 'post_terms_':
                    $terms = get_the_terms($post_id, $path);
                    return is_array($terms) ? $terms : [];
                case 'ref_':
                    $source = 'ref:' . $path;
                    break;
                case 'item_':
                    $item = $renderer->item();
                    return $item ? self::descend_tag($item['row'], $path) : null;
            }
        }

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
                // When the page carries no site nodes of its own, a reference
                // becomes the node itself so it still means something.
                $node = self::$site['nodes'][$key] ?? null;
                return !empty(self::$site['inline_refs']) && $node ? $node : ['@id' => self::node_id($key)];

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

    /**
     * Walks an underscored path ("location_general_phone") through arrays,
     * matching the longest key at each level so keys may hold underscores.
     */
    private static function descend_tag($value, string $path)
    {
        if ($path === '') return $value;

        if (is_object($value)) {
            return in_array($path, ['name', 'slug', 'url', 'title'], true) ? Schema_Transforms::pluck($value, $path) : ($value->$path ?? null);
        }

        if (!is_array($value)) return null;

        $keys = array_map('strval', array_keys($value));
        usort($keys, fn($a, $b) => strlen($b) - strlen($a));

        foreach ($keys as $key) {
            if ($path === $key) return $value[$key];
            if (str_starts_with($path, $key . '_')) {
                $found = self::descend_tag($value[$key], substr($path, strlen($key) + 1));
                if ($found !== null) return $found;
            }
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
                    ['value' => 'site_title',       'label' => 'Site name'],
                    ['value' => 'site_url',         'label' => 'Home URL'],
                    ['value' => 'site_logo',        'label' => 'Logo'],
                    ['value' => 'ref_organization', 'label' => 'Organization (reference)'],
                    ['value' => 'ref_website',      'label' => 'WebSite (reference)'],
                ],
            ],
        ];

        $options = self::acf_sources(self::options_field_groups(), 'acf_option_');
        if ($options) {
            $shared[] = ['label' => 'Site Options', 'sources' => $options];
        }

        $catalogue = [];

        foreach ($post_types as $slug => $object) {
            $groups = [[
                'label'   => 'Post',
                'sources' => [
                    ['value' => 'post_title',     'label' => 'Title'],
                    ['value' => 'post_url',       'label' => 'Permalink'],
                    ['value' => 'post_content',   'label' => 'Content'],
                    ['value' => 'post_excerpt',   'label' => 'Excerpt'],
                    ['value' => 'featured_image', 'label' => 'Featured image'],
                    ['value' => 'post_date',      'label' => 'Published date'],
                    ['value' => 'post_modified',  'label' => 'Modified date'],
                    ['value' => 'author_name',    'label' => 'Author name'],
                    ['value' => 'post_id',        'label' => 'ID'],
                ],
            ]];

            $field_groups = function_exists('acf_get_field_groups') ? acf_get_field_groups(['post_type' => $slug]) : [];
            $acf          = self::acf_sources($field_groups, 'acf_');
            if ($acf) {
                $groups[] = ['label' => 'ACF', 'sources' => $acf];
            }

            $taxonomies = [];
            foreach (get_object_taxonomies($slug, 'objects') as $taxonomy) {
                if (!$taxonomy->public || $taxonomy->name === 'post_format') continue;
                $taxonomies[] = [
                    'value'    => 'post_terms_' . $taxonomy->name,
                    'label'    => $taxonomy->labels->name,
                    'list'     => true,
                    'children' => [['value' => 'item_name', 'label' => 'Name'], ['value' => 'item_slug', 'label' => 'Slug'], ['value' => 'item_url', 'label' => 'URL']],
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
                self::walk_fields($field['sub_fields'] ?? [], $prefix, $value . '_', $label, $sources);
                continue;
            }

            $entry = ['value' => $prefix . $value, 'label' => $label, 'type' => $field['type']];

            if (in_array($field['type'], ['repeater', 'flexible_content'], true)) {
                $children = [];
                self::walk_fields($field['sub_fields'] ?? [], 'item_', '', '', $children);
                $entry['list']     = true;
                $entry['children'] = array_merge([['value' => 'query_loop_index', 'label' => 'Row number']], $children);
            }

            if (in_array($field['type'], ['relationship', 'post_object', 'taxonomy', 'gallery', 'checkbox', 'select'], true)) {
                $entry['list'] = !empty($field['multiple']) || in_array($field['type'], ['relationship', 'gallery', 'checkbox'], true);
            }

            $sources[] = $entry;
        }
    }
}
