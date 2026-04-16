<?php

namespace DDWPTweaks\Tweaks;

function inset_subposts()
{
    if (is_admin() && is_user_logged_in()) {
        echo '<style>
            tr.level-1>td.title {
                padding-left: 2rem;
            }

            tr.level-1>td .row-title {
                opacity: .85;
            }
        </style>';
    }
}

return [
    'id'    => 'ddwpt_inset_subposts',
    'label' => 'Inset Sub-Posts',
    'tab'   => 'admin-tables',

    'settings' => [
        [
            'id'    => 'enabled',
            'type'  => 'checkbox',
            'label' => 'Enable tweak'
        ]
    ],

    'callback' => function ($settings) {
        if (!empty($settings['enabled'])) {
            add_action('admin_head', __NAMESPACE__ . '\\inset_subposts');
        }
    }
];
