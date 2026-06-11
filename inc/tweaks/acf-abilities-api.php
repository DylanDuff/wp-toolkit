<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_acf_abilities_api',
    'label' => 'Enable Abilities API',
    'tab'   => 'acf',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Enables the ACF AI Abilities API via the <code>acf/settings/enable_acf_ai</code> filter.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_filter('acf/settings/enable_acf_ai', '__return_true');
    },
];
