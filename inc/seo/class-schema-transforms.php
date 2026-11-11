<?php

namespace DDWPTweaks\SEO;

defined('ABSPATH') || exit;

/**
 * Filters for schema template tokens: {tag:filter|arg:filter} (see Schema_Syntax).
 *
 * Each transform is callable($value, string $arg, Schema_Renderer $renderer)
 * and returns the new value (null drops it). Themes add their own through
 * the `ddwpt_schema_transforms` filter. See docs/seo-schema.md.
 */
class Schema_Transforms
{
    public const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /** Postcode/region patterns for the address transform, by country code. */
    private const ADDRESS_PATTERNS = [
        'AU' => '/^(.*),\s*([^,]+?)\s+(NSW|ACT|VIC|QLD|SA|WA|TAS|NT)\s+(\d{4})$/u',
        'US' => '/^(.*),\s*([^,]+?),?\s+([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/u',
        'CA' => '/^(.*),\s*([^,]+?),?\s+([A-Z]{2})\s+([A-Z]\d[A-Z]\s?\d[A-Z]\d)$/u',
        'NZ' => '/^(.*),\s*([^,]+?)()\s+(\d{4})$/u',
        'GB' => '/^(.*),\s*([^,]+?)()\s+([A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2})$/iu',
    ];

    private static $registry = null;

    public static function all(): array
    {
        if (self::$registry === null) {
            self::$registry = apply_filters('ddwpt_schema_transforms', self::builtins());
        }

        return self::$registry;
    }

    /** Names, argument hints and descriptions, for the editor UI. */
    public static function catalogue(): array
    {
        $catalogue = [
            'clean'   => ['arg' => '',              'label' => 'Strip HTML and collapse whitespace'],
            'summary' => ['arg' => 'characters',    'label' => 'Opening sentences up to N characters (default 220)'],
            'minutes' => ['arg' => '',              'label' => '"1 hour 10 minutes" to 70'],
            'duration' => ['arg' => '',             'label' => 'Minutes to ISO 8601 duration (PT1H10M)'],
            'sum'     => ['arg' => 'source',        'label' => 'Add another source\'s minutes (null if either is missing)'],
            'hours'   => ['arg' => '',              'label' => 'Opening hours text to OpeningHoursSpecification list'],
            'time'    => ['arg' => '',              'label' => '"7:30am" to "07:30"'],
            'address' => ['arg' => 'country',       'label' => 'One-line address to PostalAddress (AU, US, CA, NZ, GB)'],
            'geo'     => ['arg' => 'longitude source', 'label' => 'Latitude (or ACF map) to GeoCoordinates'],
            'phone'   => ['arg' => 'country prefix', 'label' => 'Phone number to international format, e.g. +61'],
            'map'     => ['arg' => 'table',         'label' => 'Look up values in a lookup table, dropping misses'],
            'pairs'   => ['arg' => 'key,value',     'label' => 'Repeater rows to {key: value}, e.g. label,statistic'],
            'nutrition' => ['arg' => 'label,value', 'label' => 'Repeater rows (Calories, Fat, Protein...) to NutritionInformation'],
            'term'    => ['arg' => 'taxonomy',      'label' => 'Term IDs or slugs to terms'],
            'name'    => ['arg' => '',              'label' => 'Name of terms, posts or link fields'],
            'slug'    => ['arg' => '',              'label' => 'Slug of terms or posts'],
            'url'     => ['arg' => '',              'label' => 'URL of terms, posts, links or images'],
            'title'   => ['arg' => '',              'label' => 'Title of an ACF link field'],
            'post'    => ['arg' => 'schema type',   'label' => 'Related posts to {@type, name, url} nodes'],
            'image'   => ['arg' => '',              'label' => 'Attachment ID, ACF image or URL to an image URL'],
            'join'    => ['arg' => 'separator',     'label' => 'Join a list into text (default ", ")'],
            'first'   => ['arg' => '',              'label' => 'First item of a list'],
            'unique'  => ['arg' => '',              'label' => 'Remove duplicate list items'],
            'int'     => ['arg' => '',              'label' => 'Whole number'],
            'float'   => ['arg' => '',              'label' => 'Decimal number'],
            'date'    => ['arg' => '',              'label' => 'Date to ISO 8601'],
            'fallback' => ['arg' => 'text or tags',  'label' => 'Used when the value is empty; written as @fallback'],
            'prefix'  => ['arg' => 'text',          'label' => 'Text before the value, only when it is not empty'],
            'suffix'  => ['arg' => 'text',          'label' => 'Text after the value, only when it is not empty'],
            'plural'  => ['arg' => 'word',          'label' => '4 to "4 serves", 1 to "1 serve"'],
        ];

        foreach (array_keys(self::all()) as $name) {
            if ($name === 'default') continue; // earlier name for fallback, kept for old templates
            $catalogue[$name] = $catalogue[$name] ?? ['arg' => '', 'label' => 'Custom transform'];
        }

        return $catalogue;
    }

    public static function apply(string $name, $value, string $arg, Schema_Renderer $renderer)
    {
        $transforms = self::all();

        if (!isset($transforms[$name])) {
            $renderer->warn("Unknown transform \"{$name}\"");
            return $value;
        }

        return call_user_func($transforms[$name], $value, $arg, $renderer);
    }

    private static function builtins(): array
    {
        return [
            'clean'    => fn($value) => self::map_scalars($value, [self::class, 'clean']),
            'summary'  => fn($value, $arg) => self::summary($value, $arg !== '' ? (int) $arg : 220),
            'minutes'  => fn($value) => self::minutes($value),
            'duration' => fn($value) => self::duration($value),
            'sum'      => function ($value, $arg, $renderer) {
                $other = self::minutes($renderer->resolve_argument($arg));
                $value = is_numeric($value) ? (int) $value : self::minutes($value);
                return $value && $other ? $value + $other : null;
            },
            'hours'    => fn($value) => self::hours($value),
            'time'     => fn($value) => self::time($value),
            'address'  => fn($value, $arg) => self::address($value, $arg !== '' ? strtoupper($arg) : 'AU'),
            'geo'      => fn($value, $arg, $renderer) => self::geo($value, $arg !== '' ? $renderer->resolve_argument($arg) : null),
            'phone'    => fn($value, $arg) => self::phone($value, $arg),
            'map'      => fn($value, $arg, $renderer) => self::lookup($value, $renderer->map_table($arg)),
            'pairs'    => fn($value, $arg) => self::pairs($value, $arg),
            'nutrition' => fn($value, $arg) => self::nutrition(self::pairs($value, $arg !== '' ? $arg : 'label,value')),
            'term'     => fn($value, $arg) => self::terms($value, $arg),
            'name'     => fn($value) => self::pluck($value, 'name'),
            'slug'     => fn($value) => self::pluck($value, 'slug'),
            'url'      => fn($value) => self::pluck($value, 'url'),
            'title'    => fn($value) => self::pluck($value, 'title'),
            'post'     => fn($value, $arg) => self::post_nodes($value, $arg !== '' ? $arg : 'Thing'),
            'image'    => fn($value) => self::image($value),
            'join'     => fn($value, $arg) => is_array($value)
                ? implode($arg !== '' ? $arg : ', ', array_filter(array_map([self::class, 'to_text'], $value), 'strlen'))
                : $value,
            'first'    => fn($value) => is_array($value) && array_is_list($value) ? ($value[0] ?? null) : $value,
            'unique'   => fn($value) => is_array($value) ? array_values(array_unique($value, SORT_REGULAR)) : $value,
            'int'      => fn($value) => self::map_scalars($value, fn($item) => is_numeric($item) ? (int) $item : null),
            'float'    => fn($value) => self::map_scalars($value, fn($item) => is_numeric($item) ? (float) $item : null),
            'date'     => fn($value) => self::map_scalars($value, [self::class, 'date']),
            'fallback' => fn($value, $arg, $renderer) => Schema_Renderer::is_empty($value) ? $renderer->resolve_argument($arg) : $value,
            'default'  => fn($value, $arg, $renderer) => Schema_Renderer::is_empty($value) ? $renderer->resolve_argument($arg) : $value,
            'prefix'   => fn($value, $arg) => Schema_Renderer::is_empty($value) ? null : $arg . self::to_text($value),
            'suffix'   => fn($value, $arg) => Schema_Renderer::is_empty($value) ? null : self::to_text($value) . $arg,
            'plural'   => function ($value, $arg) {
                $text = trim(self::to_text($value));
                if ($text === '') return null;
                return $text . ' ' . ($text === '1' ? $arg : $arg . 's');
            },
        ];
    }

    /* ---------- Shared helpers ---------- */

    public static function to_text($value): string
    {
        if (is_scalar($value)) return (string) $value;
        if (is_array($value) && isset($value['title'])) return (string) $value['title'];
        if (is_object($value) && isset($value->name)) return (string) $value->name;
        return '';
    }

    private static function map_scalars($value, callable $callback)
    {
        if (is_array($value) && array_is_list($value)) {
            return array_values(array_filter(array_map($callback, $value), fn($item) => !Schema_Renderer::is_empty($item)));
        }

        return is_scalar($value) ? $callback($value) : $value;
    }

    public static function clean($text): string
    {
        return trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $text)));
    }

    private static function summary($text, int $limit): string
    {
        $text    = self::clean(is_scalar($text) ? $text : '');
        $summary = '';

        foreach (preg_split('/(?<=[.!?])\s+/u', $text) as $sentence) {
            if ($summary !== '' && strlen($summary) + strlen($sentence) > $limit) {
                break;
            }
            $summary = trim($summary . ' ' . $sentence);
        }

        return $summary;
    }

    /** "15 minutes" / "1 hour 10 minutes" / "1h 10m" → minutes; "Overnight" → null */
    private static function minutes($text): ?int
    {
        if (is_numeric($text)) {
            return (int) $text > 0 ? (int) $text : null;
        }

        $text    = strtolower(self::to_text($text));
        $hours   = preg_match('/(\d+)\s*h/', $text, $hour_match) ? (int) $hour_match[1] : 0;
        $minutes = preg_match('/(\d+)\s*m(?!o)/', $text, $minute_match) ? (int) $minute_match[1] : 0;
        $total   = $hours * 60 + $minutes;

        return $total > 0 ? $total : null;
    }

    private static function duration($minutes): ?string
    {
        $minutes = is_numeric($minutes) ? (int) $minutes : self::minutes($minutes);

        if (!$minutes) {
            return null;
        }

        $hours = intdiv($minutes, 60);
        $rest  = $minutes % 60;

        return 'PT' . ($hours ? $hours . 'H' : '') . ($rest ? $rest . 'M' : '');
    }

    /** "7:30am" → "07:30"; "17:30" stays "17:30" */
    public static function time($time): ?string
    {
        $time = (string) $time;

        if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*([ap])\.?m\.?/i', $time, $match)) {
            $hour = (int) $match[1] % 12 + (strtolower($match[3]) === 'p' ? 12 : 0);
            return sprintf('%02d:%s', $hour, $match[2] ?: '00');
        }

        if (preg_match('/^\s*(\d{1,2}):(\d{2})\s*$/', $time, $match)) {
            return sprintf('%02d:%s', (int) $match[1], $match[2]);
        }

        return null;
    }

    /**
     * Parses hours text such as "Monday: 7:30am–6:00pm" lines, or
     * "Monday to Thursday, 8:30am–5:30pm" / "Monday 1:30pm–6:00pm, Wednesday 8:00am–6:00pm".
     * Fortnightly and closed days are skipped: schema can't express "every second week".
     */
    private static function hours($text): array
    {
        if (is_array($text)) {
            $text = implode("\n", array_map([self::class, 'to_text'], $text));
        }

        $days     = implode('|', self::DAYS);
        $segments = preg_split('/\R|,\s*(?=(?:' . $days . '))/iu', (string) $text);
        $time     = '(\d{1,2}(?::\d{2})?\s*[ap]\.?m\.?|\d{1,2}:\d{2})';
        $pattern  = '/(' . $days . ')(?:\s*(?:to|-|–|—)\s*(' . $days . '))?[,:]?\s*' . $time . '\s*(?:[–—-]|to)\s*' . $time . '/iu';
        $specs    = [];

        foreach ($segments as $segment) {
            if (stripos($segment, 'fortnight') !== false || !preg_match($pattern, $segment, $match)) {
                continue;
            }

            $start = array_search(ucfirst(strtolower($match[1])), self::DAYS, true);
            $end   = $match[2] ? array_search(ucfirst(strtolower($match[2])), self::DAYS, true) : $start;

            if ($end < $start) {
                continue;
            }

            $specs[] = [
                '@type'     => 'OpeningHoursSpecification',
                'dayOfWeek' => array_slice(self::DAYS, $start, $end - $start + 1),
                'opens'     => self::time($match[3]),
                'closes'    => self::time($match[4]),
            ];
        }

        return $specs;
    }

    /** "510/536 High St, Penrith NSW 2750" → PostalAddress */
    private static function address($address, string $country): ?array
    {
        if (is_array($address) && isset($address['address'])) {
            $address = $address['address']; // ACF Google Map field
        }

        $address = trim(preg_replace('/\s*\R\s*/u', ', ', self::to_text($address)));

        if ($address === '') {
            return null;
        }

        $pattern = self::ADDRESS_PATTERNS[$country] ?? null;

        if (!$pattern || !preg_match($pattern, $address, $match)) {
            return ['@type' => 'PostalAddress', 'streetAddress' => $address, 'addressCountry' => $country];
        }

        return [
            '@type'           => 'PostalAddress',
            'streetAddress'   => $match[1],
            'addressLocality' => $match[2],
            'addressRegion'   => $match[3] !== '' ? $match[3] : null,
            'postalCode'      => strtoupper($match[4]),
            'addressCountry'  => $country,
        ];
    }

    private static function geo($latitude, $longitude): ?array
    {
        if (is_array($latitude) && isset($latitude['lat'], $latitude['lng'])) {
            [$latitude, $longitude] = [$latitude['lat'], $latitude['lng']]; // ACF Google Map field
        }

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        return ['@type' => 'GeoCoordinates', 'latitude' => (float) $latitude, 'longitude' => (float) $longitude];
    }

    /** ACF link fields use their title; "1300 123 674" + "+61" → "+61 1300 123 674"; "02 9999 0000" → "+61 2 9999 0000" */
    private static function phone($phone, string $prefix): ?string
    {
        $phone = trim(is_array($phone) ? (string) ($phone['title'] ?? '') : self::to_text($phone));

        if ($phone === '') {
            return null;
        }

        if ($prefix === '' || str_starts_with($phone, '+')) {
            return $phone;
        }

        return $prefix . ' ' . preg_replace('/^0(?=\d)/', '', $phone);
    }

    private static function lookup($value, array $table)
    {
        $find = function ($key) use ($table) {
            $key = trim(self::to_text($key));
            foreach ($table as $from => $to) {
                if (strcasecmp((string) $from, $key) === 0) return $to;
            }
            return null;
        };

        if (!is_array($value)) {
            return $find($value);
        }

        if (array_is_list($value)) {
            return array_values(array_filter(array_map($find, $value), fn($item) => $item !== null));
        }

        // Associative, e.g. from pairs: map the keys, keep the values.
        $mapped = [];
        foreach ($value as $key => $item) {
            $to = $find($key);
            if ($to !== null) $mapped[$to] = $item;
        }
        return $mapped;
    }

    private static function pairs($rows, string $arg): array
    {
        [$key_field, $value_field] = array_map('trim', explode(',', $arg . ','));
        $key_field   = $key_field !== '' ? $key_field : 'label';
        $value_field = $value_field !== '' ? $value_field : 'value';
        $pairs       = [];

        foreach ((array) $rows as $row) {
            $key   = strtolower(trim(self::to_text($row[$key_field] ?? '')));
            $value = trim(self::to_text($row[$value_field] ?? ''));
            if ($key !== '' && $value !== '') $pairs[$key] = $value;
        }

        return $pairs;
    }

    /** {"calories": "250", "fibre": "4g"} → NutritionInformation; bare calorie numbers get " calories". */
    private static function nutrition(array $pairs): ?array
    {
        $properties = [
            'calories'      => 'calories',
            'energy'        => 'calories',
            'fat'           => 'fatContent',
            'total fat'     => 'fatContent',
            'saturates'     => 'saturatedFatContent',
            'saturated fat' => 'saturatedFatContent',
            'trans fat'     => 'transFatContent',
            'carbs'         => 'carbohydrateContent',
            'carbohydrates' => 'carbohydrateContent',
            'carbohydrate'  => 'carbohydrateContent',
            'sugars'        => 'sugarContent',
            'sugar'         => 'sugarContent',
            'fibre'         => 'fiberContent',
            'fiber'         => 'fiberContent',
            'protein'       => 'proteinContent',
            'sodium'        => 'sodiumContent',
            'salt'          => 'sodiumContent',
            'cholesterol'   => 'cholesterolContent',
            'serving size'  => 'servingSize',
        ];
        $nutrition = ['@type' => 'NutritionInformation'];

        foreach ($pairs as $label => $value) {
            $key = $properties[$label] ?? null;
            if (!$key) continue;
            $nutrition[$key] = $key === 'calories' && is_numeric($value) ? $value . ' calories' : $value;
        }

        return count($nutrition) > 1 ? $nutrition : null;
    }

    private static function terms($values, string $taxonomy): array
    {
        $terms = [];

        foreach ((array) $values as $value) {
            if ($value instanceof \WP_Term) {
                $terms[] = $value;
                continue;
            }
            $term = is_numeric($value)
                ? get_term((int) $value, $taxonomy ?: '')
                : get_term_by('slug', (string) $value, $taxonomy);
            if ($term && !is_wp_error($term)) $terms[] = $term;
        }

        return $terms;
    }

    /** Reads name/slug/url/title off terms, posts, ACF link and image arrays, or lists of them. */
    public static function pluck($value, string $property)
    {
        if (is_array($value) && array_is_list($value)) {
            return array_values(array_filter(array_map(fn($item) => self::pluck($item, $property), $value), fn($item) => !Schema_Renderer::is_empty($item)));
        }

        if ($value instanceof \WP_Term) {
            return match ($property) {
                'url'   => ($link = get_term_link($value)) && !is_wp_error($link) ? $link : null,
                'title' => $value->name,
                default => $value->$property ?? null,
            };
        }

        if ($value instanceof \WP_Post || (is_numeric($value) && $property !== 'title')) {
            $post = get_post($value);
            if (!$post) return null;
            return match ($property) {
                'url'   => get_permalink($post),
                'slug'  => $post->post_name,
                default => get_the_title($post),
            };
        }

        if (is_array($value)) {
            $aliases = ['name' => ['name', 'title', 'label'], 'title' => ['title', 'name'], 'url' => ['url', 'link'], 'slug' => ['slug', 'value']];
            foreach ($aliases[$property] ?? [$property] as $key) {
                if (isset($value[$key]) && $value[$key] !== '') return $value[$key];
            }
            return null;
        }

        return $value;
    }

    private static function post_nodes($value, string $type)
    {
        $node = function ($item) use ($type) {
            $post = get_post($item);
            if (!$post) return null;
            return ['@type' => $type, 'name' => get_the_title($post), 'url' => get_permalink($post)];
        };

        if (is_array($value) && array_is_list($value)) {
            return array_values(array_filter(array_map($node, $value)));
        }

        return Schema_Renderer::is_empty($value) ? null : $node($value);
    }

    private static function image($value)
    {
        if (is_array($value) && array_is_list($value)) {
            return array_values(array_filter(array_map([self::class, 'image'], $value)));
        }
        if (is_numeric($value)) {
            return wp_get_attachment_image_url((int) $value, 'full') ?: null;
        }
        if (is_array($value)) {
            return $value['url'] ?? (isset($value['ID']) ? self::image($value['ID']) : null);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function date($value): ?string
    {
        if ($value === '' || $value === null) return null;
        if (preg_match('/^\d{8}$/', (string) $value)) { // ACF date picker storage format
            $value = substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
        }
        $timestamp = strtotime((string) $value);
        return $timestamp ? wp_date('c', $timestamp) : null;
    }
}
