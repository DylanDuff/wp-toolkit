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
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        new \DDWPTweaks\Knowledge_Base($settings['mode'] ?? 'sidebar');
    },
];
