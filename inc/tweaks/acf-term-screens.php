<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_acf_term_screens',
    'label' => 'Tidier Term Edit Screens',
    'tab'   => 'acf',
    'group' => 'general',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Restyle the taxonomy term edit screen: core fields as a card, ACF field groups as panels, Update / Delete kept in view while scrolling, and ACF tabs set to "Left" shown as a left column (ACF ignores left tabs on term screens).',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        add_action('admin_enqueue_scripts', static function ($hook) {
            if ($hook !== 'term.php') {
                return;
            }

            wp_enqueue_style('ddwpt-acf-term-screens', DDWPT_URL . 'assets/css/acf-term-screens.css', [], DDWPT_VERSION);
        });

        // admin_footer at priority 1, not an enqueued footer script: ACF prints the
        // inline script that initialises tabs with the footer scripts, and the tab
        // buttons have to be rewritten before it runs but after the form exists.
        add_action('admin_footer', static function () {
            $screen = get_current_screen();

            if (!$screen || $screen->base !== 'term') {
                return;
            }

            $script = file_get_contents(dirname(__DIR__, 2) . '/assets/js/acf-term-screens.js');

            if ($script) {
                wp_print_inline_script_tag($script);
            }
        }, 1);
    },
];
