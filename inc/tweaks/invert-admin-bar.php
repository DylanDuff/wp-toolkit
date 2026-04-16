<?php

namespace DDWPTweaks\Tweaks;

function invert_admin()
{
    if (! is_admin() && is_user_logged_in()) {

        echo '<style>
            #wpadminbar {
                position: fixed;
                bottom: 0;
                top: auto;
                max-width: fit-content;
                left: 0;
                right: 0;
                margin-inline: auto;
                border-radius: .5rem .5rem 0 0;
                padding-right: 2rem;
            }
            #wpadminbar .menupop .ab-sub-wrapper,
            #wpadminbar .shortlink-input {
                transform: translateY(calc(-100% - 3rem));
            }
            html {
                margin-top: 0 !important;
            }
        </style>';
    }
}

return [
    'id'    => 'ddwpt_invert_admin_bar',
    'label' => 'Invert Admin Bar',
    'tab'   => 'admin-bar',

    'settings' => [
        [
            'id'    => 'enabled',
            'type'  => 'checkbox',
            'label' => 'Enable tweak'
        ]
    ],

    'callback' => function ($settings) {
        if (!empty($settings['enabled'])) {
            add_action('wp_head', __NAMESPACE__ . '\\invert_admin');
        }
    }
];
