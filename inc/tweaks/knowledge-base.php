<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_knowledge_base',
    'label' => 'Knowledge Base',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable Knowledge Base',
            'description' => 'Render articles from inc/knowledge/ in the admin.',
        ],
        [
            'id'      => 'mode',
            'type'    => 'select',
            'label'   => 'Display mode',
            'default' => 'sidebar',
            'options' => [
                'sidebar'   => 'Sidebar page',
                'dashboard' => 'Dashboard widget',
            ],
        ],
        [
            'id'          => 'custom_dir',
            'type'        => 'text',
            'label'       => 'Custom uploads directory',
            'description' => 'Subdirectory within wp-content/uploads/ to load articles from (e.g. <code>knowledge</code>). Leave blank to use only bundled articles.',
        ],
        [
            'id'      => 'custom_dir_mode',
            'type'    => 'select',
            'label'   => 'Custom directory mode',
            'default' => 'additive',
            'options' => [
                'additive' => 'Additive — merge with bundled articles (custom wins on duplicate slug)',
                'replace'  => 'Replace — use only the custom directory',
            ],
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        $custom_path = '';
        if (!empty($settings['custom_dir'])) {
            $upload     = wp_upload_dir();
            $subdir     = trim($settings['custom_dir'], '/\\');
            $resolved   = trailingslashit($upload['basedir']) . $subdir . '/';
            if (is_dir($resolved)) {
                $custom_path = $resolved;
            }
        }

        new \DDWPTweaks\Knowledge_Base(
            $settings['mode'] ?? 'sidebar',
            $custom_path,
            $settings['custom_dir_mode'] ?? 'additive'
        );
    },
];
