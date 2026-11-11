<?php

namespace DDWPTweaks\SEO;

defined('ABSPATH') || exit;

/**
 * Turns a schema template into JSON-LD nodes for one post.
 *
 * A template is the JSON-LD you want with {{source|transform:arg}} tokens.
 * A string that is exactly one token keeps the resolved type (list, object,
 * number); a token inside longer text is interpolated. Keys starting with
 * @each, @if, @unless, @maps, @merge, @from, @value and @else are directives
 * the renderer consumes. Empty values are pruned. See docs/seo-schema.md.
 */
class Schema_Renderer
{
    private const TOKEN = '/\{\{\s*(.+?)\}\}/s';

    private const DIRECTIVES = ['@each', '@if', '@unless', '@maps', '@merge', '@from', '@value', '@else'];

    /** @var int[] Post context stack; @from pushes a related post. */
    private $posts = [];

    /** @var array[] Loop stack of ['row' => mixed, 'index' => int]. */
    private $items = [];

    private $maps = [];

    private $warnings = [];

    public function __construct(int $post_id)
    {
        $this->posts = [$post_id];
    }

    /**
     * @param array $template One node or a list of nodes.
     * @return array[] Graph nodes, pruned.
     */
    public function render(array $template): array
    {
        $roots = array_is_list($template) ? $template : [$template];
        $nodes = [];

        foreach ($roots as $root) {
            if (!is_array($root)) continue;

            $this->maps = array_merge($this->maps, (array) ($root['@maps'] ?? []));
            $result     = $this->value($root);

            foreach (is_array($result) && array_is_list($result) ? $result : [$result] as $node) {
                if (is_array($node) && !self::is_empty($node)) $nodes[] = $node;
            }
        }

        return $nodes;
    }

    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function post_id(): int
    {
        return end($this->posts);
    }

    public function item(): ?array
    {
        return $this->items ? end($this->items) : null;
    }

    public function map_table(string $name): array
    {
        if (!isset($this->maps[$name])) {
            $this->warn("Lookup table \"{$name}\" is not defined in @maps");
        }

        return (array) ($this->maps[$name] ?? []);
    }

    /* ---------- Values ---------- */

    private function value($value)
    {
        if (is_string($value)) return $this->string($value);
        if (!is_array($value)) return $value;

        if (array_is_list($value)) {
            $list = [];
            foreach ($value as $entry) {
                $resolved = $this->value($entry);
                // A looped object inside a list contributes all its rows.
                if (is_array($entry) && isset($entry['@each']) && is_array($resolved) && array_is_list($resolved)) {
                    array_push($list, ...$resolved);
                } elseif (!self::is_empty($resolved)) {
                    $list[] = $resolved;
                }
            }
            return $list;
        }

        return $this->node($value);
    }

    private function node(array $node)
    {
        if (isset($node['@if']) && !$this->truthy($node['@if'])) return null;
        if (isset($node['@unless']) && $this->truthy($node['@unless'])) return null;

        if (isset($node['@each'])) {
            return $this->loop($node);
        }

        if (isset($node['@from'])) {
            return $this->with_related($node);
        }

        if (array_key_exists('@value', $node)) {
            return $this->value($node['@value']);
        }

        $result = [];

        if (isset($node['@merge'])) {
            $merge = $this->value($node['@merge']);
            if (is_array($merge) && !array_is_list($merge)) $result = $merge;
        }

        foreach ($node as $key => $value) {
            if (in_array($key, self::DIRECTIVES, true)) continue;
            $resolved = $this->value($value);
            if (!self::is_empty($resolved)) $result[$key] = $resolved;
        }

        // Typed objects with nothing but their @type (and @id) carry no information.
        $content = array_diff_key($result, ['@type' => 1, '@id' => 1]);
        if (!$content) {
            return isset($node['@else']) ? $this->value($node['@else']) : null;
        }

        return $result;
    }

    /** "@each": "source" or {"rows": "source", "if": "source", "else": {key: "source"}} */
    private function loop(array $node): array
    {
        $each = $node['@each'];
        unset($node['@each']);

        $rows = null;
        $spec = is_array($each) ? $each : ['rows' => $each];

        if (!isset($spec['if']) || $this->truthy($spec['if'])) {
            $rows = $this->resolve_expression((string) ($spec['rows'] ?? ''));
        }

        if (self::is_empty($rows) && isset($spec['else']) && is_array($spec['else'])) {
            // A single synthetic row built from other sources, e.g. a clinic's
            // one address when it has no list of addresses.
            $row = [];
            foreach ($spec['else'] as $key => $source) {
                $row[$key] = is_string($source) ? $this->resolve_expression($source) : $source;
            }
            $rows = [$row];
            $single = true;
        }

        if (self::is_empty($rows)) {
            return isset($node['@else']) ? (array) $this->value($node['@else']) : [];
        }

        $rows   = is_array($rows) && array_is_list($rows) ? $rows : [$rows];
        $output = [];

        foreach ($rows as $index => $row) {
            $this->items[] = ['row' => $row, 'index' => empty($single) ? $index + 1 : null];
            $resolved      = $this->node($node);
            array_pop($this->items);

            if (!self::is_empty($resolved)) $output[] = $resolved;
        }

        return $output;
    }

    /** "@from": "acf:linked_author" switches post.* to the related post(s). */
    private function with_related(array $node)
    {
        $related = $this->resolve_expression((string) $node['@from']);
        unset($node['@from']);

        $ids = [];
        foreach (is_array($related) && array_is_list($related) ? $related : [$related] as $entry) {
            $post = self::is_empty($entry) ? null : get_post(is_array($entry) ? ($entry['ID'] ?? 0) : $entry);
            if ($post) $ids[] = $post->ID;
        }

        if (!$ids) {
            return isset($node['@else']) ? $this->value($node['@else']) : null;
        }

        $output = [];
        foreach ($ids as $id) {
            $this->posts[] = $id;
            $output[]      = $this->node($node);
            array_pop($this->posts);
        }

        $output = array_values(array_filter($output, fn($item) => !self::is_empty($item)));
        return count($output) === 1 ? $output[0] : $output;
    }

    private function string(string $text)
    {
        if (preg_match('/^\{\{\s*(.+?)\}\}$/s', $text, $match) && substr_count($text, '{{') === 1) {
            return $this->resolve_expression($match[1]);
        }

        if (strpos($text, '{{') === false) {
            return $text;
        }

        $tokens = 0;
        $empty  = 0;
        $result = preg_replace_callback(self::TOKEN, function ($match) use (&$tokens, &$empty) {
            $tokens++;
            $value = $this->resolve_expression($match[1]);
            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map([Schema_Transforms::class, 'to_text'], $value), 'strlen'));
            }
            $value = Schema_Transforms::to_text($value);
            if ($value === '') $empty++;
            return $value;
        }, $text);

        // Text made only of tokens that all came back empty is empty too.
        if ($tokens === $empty && trim(preg_replace(self::TOKEN, '', $text)) === '') {
            return null;
        }

        return trim(preg_replace('/\s+/u', ' ', $result));
    }

    private function truthy($condition): bool
    {
        $value = is_string($condition) && strpos($condition, '{{') === false
            ? $this->resolve_expression($condition)
            : $this->value($condition);

        return !self::is_empty($value) && $value !== false && $value !== '0' && $value !== 0;
    }

    /* ---------- Expressions ---------- */

    /** "source|transform:arg|transform" → value */
    public function resolve_expression(string $expression)
    {
        $parts  = explode('|', $expression);
        $source = trim(array_shift($parts));
        $value  = $source === '' ? null : Schema_Sources::resolve($source, $this);

        if (self::is_empty($value) && $source !== '') {
            $this->warn("Empty: {$source}");
        }

        foreach ($parts as $part) {
            $colon = strpos($part, ':');
            $name  = trim($colon === false ? $part : substr($part, 0, $colon));
            $arg   = $colon === false ? '' : substr($part, $colon + 1);
            if ($name === '') continue;
            $value = Schema_Transforms::apply($name, $value, $arg, $this);
        }

        return $value;
    }

    /**
     * A transform argument that names a source (e.g. "acf:cook_time",
     * "ref:organization") is resolved; anything else is literal text.
     */
    public function resolve_argument(string $arg)
    {
        $arg = trim($arg);
        return Schema_Sources::is_source($arg) ? Schema_Sources::resolve($arg, $this) : $arg;
    }

    public static function is_empty($value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /* ---------- Output ---------- */

    public static function script(array $graph): string
    {
        if (!$graph) return '';

        $json = wp_json_encode(
            ['@context' => 'https://schema.org', '@graph' => array_values($graph)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        );

        return '<script type="application/ld+json" class="ddwpt-schema">' . $json . '</script>' . "\n";
    }
}
