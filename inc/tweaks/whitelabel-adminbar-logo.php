<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_whitelabel_adminbar_logo',
    'label' => 'Whitelabel Admin Bar Logo',
    'tab'   => 'admin-bar',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Replace the WordPress logo in the admin bar with your own branding.',
        ],
        [
            'id'      => 'logo_image',
            'type'    => 'media',
            'label'   => 'Logo image',
            'default' => '',
        ],
        [
            'id'          => 'logo_data',
            'type'        => 'text',
            'label'       => 'Or data URI',
            'default'     => '',
            'description' => 'Paste a data:image/... string as an alternative to selecting an image above.',
        ],
        [
            'id'      => 'link_url',
            'type'    => 'text',
            'label'   => 'Dropdown link URL',
            'default' => '',
        ],
        [
            'id'      => 'link_text',
            'type'    => 'text',
            'label'   => 'Dropdown link text',
            'default' => '',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        $logo_url  = !empty($settings['logo_image']) ? $settings['logo_image'] : ($settings['logo_data'] ?? '');
        $link_url  = $settings['link_url'] ?? '';
        $link_text = $settings['link_text'] ?? '';

        if (empty($logo_url)) {
            return;
        }

        // Inject CSS to replace the default logo
        add_action('admin_head', function () use ($logo_url) {
            echo '<style>
                #wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon::before {
                    content: "";
                    background: url(' . esc_url($logo_url) . ') no-repeat center center;
                    background-size: contain;
                    width: 20px;
                    height: 20px;
                    display: inline-block;
                }
            </style>';
        });

        add_action('wp_head', function () use ($logo_url) {
            echo '<style>
                #wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon::before {
                    content: "";
                    background: url(' . esc_url($logo_url) . ') no-repeat center center;
                    background-size: contain;
                    width: 20px;
                    height: 20px;
                    display: inline-block;
                }
            </style>';
        });

        // Replace the dropdown menu items with a single custom link
        add_action('admin_bar_menu', function ($wp_admin_bar) use ($link_url, $link_text) {
            // Remove default submenu items
            $wp_admin_bar->remove_node('about');
            $wp_admin_bar->remove_node('wporg');
            $wp_admin_bar->remove_node('documentation');
            $wp_admin_bar->remove_node('support-forums');
            $wp_admin_bar->remove_node('feedback');
            $wp_admin_bar->remove_node('contribute');
            $wp_admin_bar->remove_node('learn');
            $wp_admin_bar->remove_node('wp-logo-external');

            if (!empty($link_url) && !empty($link_text)) {
                $wp_admin_bar->add_node([
                    'parent' => 'wp-logo',
                    'id'     => 'ddwpt-custom-link',
                    'title'  => esc_html($link_text),
                    'href'   => esc_url($link_url),
                ]);
            }
        }, 999);
    },
];
