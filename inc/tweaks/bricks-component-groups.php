<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_bricks_component_groups',
    'label' => 'Component Property Groups in Gutenberg',
    'tab'   => 'bricks',
    'group' => 'gutenberg',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Bricks components used as blocks show every property in one flat "Properties" panel. This shows each property group as its own sidebar panel instead. Needs Bricks 2.1 or later.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled']) || !defined('BRICKS_VERSION')) {
            return;
        }

        add_action('enqueue_block_assets', static function () {
            if (!is_admin() || !wp_script_is('bricks-gutenberg-components', 'enqueued')) {
                return;
            }

            // Inline after Bricks' own script, so the editor.BlockEdit filter and the
            // bricksCreatePropertyControl wrapper are in place before any block renders.
            $script = file_get_contents(dirname(__DIR__, 2) . '/assets/js/bricks-component-groups.js');

            if ($script) {
                wp_add_inline_script('bricks-gutenberg-components', $script, 'after');
            }
        }, 20);
    },
];
