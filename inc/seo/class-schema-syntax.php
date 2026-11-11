<?php

namespace DDWPTweaks\SEO;

defined('ABSPATH') || exit;

/**
 * Token syntax, modelled on Bricks dynamic data:
 *
 *   {tag}                         {post_title}
 *   {tag:filter:filter}           {acf_recipe_general_prep_time:minutes:duration}
 *   {tag:filter|arg}              {item_label:prefix|' (':suffix|')'}
 *   {tag @fallback:'text'}        {acf_location_general_phone:title:phone|+61 @fallback:'+61 1300 123 674'}
 *
 * Filters run left to right. An argument follows "|" (as in Bricks'
 * array_value|key) and is single-quoted when it has a colon, quote, brace,
 * "@" or edge spaces; \' escapes a quote inside. @fallback is used when the
 * filtered value is empty and may itself contain tags.
 *
 * The earlier syntax, {{source|transform:arg}} with dotted sources
 * (post.title, acf:group.field, item.label), still parses, so templates
 * saved before the switch keep rendering. See docs/seo-schema.md.
 */
class Schema_Syntax
{
    /**
     * Tokens in a string, in order.
     *
     * @return array[] ['start' => int, 'length' => int, 'body' => string, 'legacy' => bool]
     */
    public static function tokens(string $text): array
    {
        $tokens = [];
        $length = strlen($text);
        $offset = 0;

        while (($start = strpos($text, '{', $offset)) !== false) {
            // Legacy {{body}}
            if (substr($text, $start, 2) === '{{') {
                $end = strpos($text, '}}', $start + 2);
                if ($end === false) break;
                $tokens[] = ['start' => $start, 'length' => $end + 2 - $start, 'body' => ltrim(substr($text, $start + 2, $end - $start - 2)), 'legacy' => true];
                $offset = $end + 2;
                continue;
            }

            // {tag...}: a tag starts with a letter or underscore (or ":" when the
            // source is still to be picked); braces inside quotes nest.
            if (!preg_match('/[A-Za-z_:]/A', $text, $match, 0, $start + 1)) {
                $offset = $start + 1;
                continue;
            }

            $depth  = 0;
            $quoted = false;
            $end    = null;
            for ($index = $start; $index < $length; $index++) {
                $char = $text[$index];
                if ($char === '\\' && $quoted) { $index++; continue; }
                if ($char === "'") { $quoted = !$quoted; continue; }
                if ($quoted) continue;
                if ($char === '{') $depth++;
                if ($char === '}' && --$depth === 0) { $end = $index; break; }
            }

            if ($end === null) {
                $offset = $start + 1;
                continue;
            }

            $tokens[] = ['start' => $start, 'length' => $end + 1 - $start, 'body' => substr($text, $start + 1, $end - $start - 1), 'legacy' => false];
            $offset = $end + 1;
        }

        return $tokens;
    }

    /** True when the whole string is one token (or the empty field "{}"). */
    public static function single(string $text): ?array
    {
        $text = trim($text);
        if ($text === '{}' || $text === '{{}}') return ['body' => '', 'legacy' => false];

        $tokens = self::tokens($text);
        return count($tokens) === 1 && $tokens[0]['start'] === 0 && $tokens[0]['length'] === strlen($text) ? $tokens[0] : null;
    }

    /**
     * Expression body (the inside of a token, or a bare @each/@from/@if
     * value) to its parts.
     *
     * @return array{source: string, filters: array[], fallback: ?string}
     */
    public static function parse(string $body, ?bool $legacy = null): array
    {
        // A bare value may still be written with braces.
        if ($legacy === null && ($single = self::single($body))) {
            return self::parse($single['body'], $single['legacy']);
        }

        // Legacy arguments keep trailing spaces ("join:, "), as they always did.
        if ($legacy ?? self::is_legacy($body)) {
            $body    = ltrim($body);
            $parts   = explode('|', $body);
            $source  = trim(array_shift($parts));
            $filters = [];
            foreach ($parts as $part) {
                $colon = strpos($part, ':');
                $name  = trim($colon === false ? $part : substr($part, 0, $colon));
                if ($name !== '') $filters[] = [$name, $colon === false ? '' : substr($part, $colon + 1)];
            }
            return ['source' => $source, 'filters' => $filters, 'fallback' => null];
        }

        [$main, $arguments] = self::split_arguments(trim($body));
        $segments = self::split_unquoted($main, ':');
        $source   = trim(array_shift($segments));
        $filters  = [];

        foreach ($segments as $segment) {
            $pipe = self::split_unquoted($segment, '|', 2);
            $name = trim($pipe[0]);
            if ($name === '') continue;
            $filters[] = [$name, isset($pipe[1]) ? self::unquote($pipe[1]) : ''];
        }

        return ['source' => $source, 'filters' => $filters, 'fallback' => $arguments['fallback'] ?? null];
    }

    /** Earlier syntax: dotted or colon-prefixed sources ("post.title", "acf:x"). */
    public static function is_legacy(string $body): bool
    {
        return (bool) preg_match('/^\s*(post\.|site\.|acf:|meta:|tax:|option:|ref:|item\.|item\s*\|)/', $body);
    }

    /** "main @key:value @key:'quoted value'" → [main, [key => value]] */
    private static function split_arguments(string $body): array
    {
        $arguments = [];
        $quoted    = false;
        $cut       = null;
        $length    = strlen($body);

        for ($index = 0; $index < $length; $index++) {
            $char = $body[$index];
            if ($char === '\\' && $quoted) { $index++; continue; }
            if ($char === "'") { $quoted = !$quoted; continue; }
            if (!$quoted && $char === '@' && $index > 0 && ctype_space($body[$index - 1])) {
                $cut = $index;
                break;
            }
        }

        if ($cut === null) return [$body, []];

        $main = rtrim(substr($body, 0, $cut));
        preg_match_all("/@([\\w-]+):('(?:[^'\\\\]|\\\\.)*'|\\S*)/", substr($body, $cut), $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $arguments[$match[1]] = self::unquote($match[2]);
        }

        return [$main, $arguments];
    }

    /** Splits on $separator outside single quotes. */
    private static function split_unquoted(string $text, string $separator, int $limit = 0): array
    {
        $parts   = [];
        $current = '';
        $quoted  = false;
        $length  = strlen($text);

        for ($index = 0; $index < $length; $index++) {
            $char = $text[$index];
            if ($char === '\\' && $quoted && $index + 1 < $length) {
                $current .= $char . $text[++$index];
                continue;
            }
            if ($char === "'") $quoted = !$quoted;
            if (!$quoted && $char === $separator && (!$limit || count($parts) < $limit - 1)) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return $parts;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
            return str_replace("\\'", "'", substr($value, 1, -1));
        }
        return $value;
    }
}
