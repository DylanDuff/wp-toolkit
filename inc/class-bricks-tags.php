<?php

namespace DDWPTweaks;

defined('ABSPATH') || exit;

/**
 * Registers simple string-valued Bricks dynamic data tags.
 *
 * Bricks resolves a tag through three filters (single tag, tag inside content,
 * and frontend render data) and lists it through a fourth. Every tweak that adds
 * tags wires the same four, so they share this one implementation.
 *
 *   Bricks_Tags::register('My group', [
 *       'my_tag' => [
 *           'label'   => 'My tag',
 *           'resolve' => fn(array $args, $post) => 'value',
 *           'image'   => false, // true: return [value] in image context
 *       ],
 *   ]);
 *
 * `{my_tag:a:b}` calls resolve(['a', 'b'], $post). Resolvers return a string.
 * See docs/bricks-builder-tweaks.md.
 */
final class Bricks_Tags
{
    public static function register(string $group, array $tags): void
    {
        if (!$tags) {
            return;
        }

        add_filter('bricks/dynamic_tags_list', static function ($list) use ($group, $tags) {
            foreach ($tags as $name => $tag) {
                $list[] = [
                    'name'  => '{' . $name . '}',
                    'label' => $tag['label'],
                    'group' => $group,
                ];
            }

            return $list;
        });

        add_filter('bricks/dynamic_data/render_tag', static function ($tag, $post, $context = 'text') use ($tags) {
            if (!is_string($tag)) {
                return $tag;
            }

            $parts = explode(':', trim($tag, '{}'), 2);
            $name  = $parts[0];

            if (!isset($tags[$name])) {
                return $tag;
            }

            $value = self::resolve($tags[$name], $parts[1] ?? '', $post);

            if ($context === 'image' && !empty($tags[$name]['image'])) {
                return $value !== '' ? [$value] : [];
            }

            return $value;
        }, 20, 3);

        $pattern = '/\{(' . implode('|', array_map(static fn($name) => preg_quote($name, '/'), array_keys($tags))) . ')(?::([^{}\s]*))?\}/';

        $replace = static function ($content, $post = null) use ($tags, $pattern) {
            if (!is_string($content) || strpos($content, '{') === false) {
                return $content;
            }

            return preg_replace_callback($pattern, static function ($match) use ($tags, $post) {
                return esc_html(self::resolve($tags[$match[1]], $match[2] ?? '', $post));
            }, $content);
        };

        add_filter('bricks/dynamic_data/render_content', $replace, 20, 2);
        add_filter('bricks/frontend/render_data', $replace, 20, 2);
    }

    private static function resolve(array $tag, string $args, $post): string
    {
        $args = $args === '' ? [] : explode(':', $args);

        return (string) call_user_func($tag['resolve'], $args, $post);
    }
}
