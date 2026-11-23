<?php

namespace DDWPTweaks\Tweaks;

// Tags that can run code, load other documents or change the page head. Bricks
// keeps them off its allowed list on purpose, so this tweak never adds them.
const BRICKS_HTML_TAGS_BLOCKED = [
    'applet', 'base', 'body', 'embed', 'frame', 'frameset', 'head', 'html',
    'iframe', 'link', 'meta', 'noscript', 'object', 'script', 'style', 'template', 'title',
];

/**
 * Comma or space separated tag names → valid, non-blocked, lowercase names.
 */
function bricks_html_tags_parse(string $value): array
{
    $tags = preg_split('/[\s,]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY);
    $tags = array_filter($tags, static fn($tag) => preg_match('/^[a-z][a-z0-9-]*$/', $tag));

    return array_values(array_unique(array_diff($tags, BRICKS_HTML_TAGS_BLOCKED)));
}

return [
    'id'    => 'ddwpt_bricks_html_tags',
    'label' => 'Extra HTML Tags',
    'tab'   => 'bricks',
    'group' => 'builder',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Allow more tags in the "Custom" HTML tag option of Bricks elements.',
        ],
        [
            'id'          => 'tags',
            'type'        => 'text',
            'label'       => 'Tags',
            'description' => 'Comma separated, e.g. <code>canvas, dialog</code>. Tags that run code or load other documents (<code>script</code>, <code>style</code>, <code>iframe</code>, <code>object</code> and similar) are ignored.',
            'default'     => 'canvas',
            'sanitize'    => static fn($value) => implode(', ', bricks_html_tags_parse((string) $value)),
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled']) || !defined('BRICKS_VERSION')) {
            return;
        }

        $tags = bricks_html_tags_parse((string) $settings['tags']);

        if (!$tags) {
            return;
        }

        add_filter('bricks/allowed_html_tags', static function ($allowed) use ($tags) {
            return array_values(array_unique(array_merge((array) $allowed, $tags)));
        });
    },
];
