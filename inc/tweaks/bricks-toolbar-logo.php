<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_bricks_toolbar_logo',
    'label' => 'Bricks Toolbar Logo',
    'tab'   => 'bricks',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Replace the Bricks builder toolbar logo with a custom image.',
        ],
        [
            'id'          => 'data_string',
            'type'        => 'url',
            'label'       => 'Image source',
            'description' => 'A base64 data URI (e.g. <code>data:image/svg+xml;base64,…</code>) or any valid image URL.',
        ],
        [
            'id'          => 'logo_bg',
            'type'        => 'color',
            'label'       => 'Logo background color',
            'default'     => '#ffd64f',
            'description' => 'Background color applied to the toolbar logo area.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        if (!defined('BRICKS_VERSION')) {
            return;
        }

        add_action('wp_head', function () use ($settings) {
            if (!function_exists('bricks_is_builder') || !bricks_is_builder()) {
                return;
            }

            $bg = sanitize_hex_color($settings['logo_bg'] ?? '') ?: '#ffd64f';

            echo '<style id="ddwpt-bricks-toolbar-css">'
                . '#bricks-toolbar .logo{aspect-ratio:1;min-width:unset;background:' . $bg . ';}'
                . "</style>\n";

            if (!empty($settings['data_string'])) {
                $src = wp_json_encode($settings['data_string']);
                echo "<script>document.addEventListener('DOMContentLoaded',function(){var img=document.querySelector('#bricks-toolbar .logo img');if(img)img.src={$src};});</script>\n";
            }
        });
    },
];
