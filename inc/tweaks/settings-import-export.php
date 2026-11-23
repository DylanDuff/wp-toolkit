<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'     => 'ddwpt_settings_import_export',
    'label'  => 'Import / Export',
    'tab'    => 'settings',
    'description' => 'Back up or restore all WP Toolkit settings as JSON.',
    'tags'   => ['JSON'],

    'render' => function (\DDWPTweaks\Plugin $plugin) {
        $plugin->render_info_card(
            'Import / Export Settings',
            'Download every WP Toolkit setting as a JSON file, or restore settings from a previously exported file.',
            [
                [
                    'full' => true,
                    'html' => '<div class="ddwpt-import-export-actions">'
                        . '<button type="button" class="ddwpt-btn ddwpt-import-btn">Import</button>'
                        . '<button type="button" class="ddwpt-btn ddwpt-export-btn">Export</button>'
                        . '<input type="file" id="ddwpt-import-file" accept=".json" style="display:none;" />'
                        . '</div>',
                ],
            ]
        );
    },
];
