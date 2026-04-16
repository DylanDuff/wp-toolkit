<?php

namespace DDWPTweaks\Tweaks;

function strip_version_query($src, $handle)
{
    if ($src && str_contains($src, 'ver=')) {
        $src = remove_query_arg('ver', $src);
    }
    return $src;
}

return [
    'id'    => 'ddwpt_hide_wp_version',
    'label' => 'Hide WP Version',
    'tab'   => 'security',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Remove the WordPress version number from the frontend, feeds, and script/style URLs.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        // Remove <meta name="generator"> tag
        remove_action('wp_head', 'wp_generator');

        // Strip version from RSS feeds
        add_filter('the_generator', '__return_empty_string');

        // Strip ?ver= from enqueued scripts and styles
        add_filter('script_loader_src', __NAMESPACE__ . '\\strip_version_query', 10, 2);
        add_filter('style_loader_src', __NAMESPACE__ . '\\strip_version_query', 10, 2);
    },
];
