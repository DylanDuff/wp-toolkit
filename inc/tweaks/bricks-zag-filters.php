<?php

namespace DDWPTweaks\Tweaks;

/*
 * Zag Filters, folded in from the standalone "Zag Filters for Bricks" plugin.
 *
 * Loaded here at file-load time (plugins_loaded), not in the callback: the
 * module registers its elements on init priority 20 and hooks Bricks' filter
 * index writers, and the callback only runs on init. Every plugin file has been
 * required by now, so ZAG_FILE tells us whether the standalone plugin is still
 * active. If it is, it owns the Zag namespace and the zag-* elements and this
 * copy stays idle; loading both would redeclare every class.
 *
 * See docs/zag-filters/README.md.
 */

$ddwpt_zag_standalone = defined('ZAG_FILE');

if (get_option('ddwpt_zag_filters_enabled') && !$ddwpt_zag_standalone) {
    require_once dirname(__DIR__) . '/zag-filters/bootstrap.php';
}

/*
 * Hand over automatically when the standalone plugin is deactivated, so pages
 * built with it never render missing elements. Nothing else needs migrating:
 * the plugin stored no options, and its elements and their settings live in
 * Bricks page data under the same names this module registers.
 */
if ($ddwpt_zag_standalone) {
    add_action('deactivated_plugin', static function ($plugin) {
        if ($plugin !== plugin_basename(ZAG_FILE)) {
            return;
        }

        if (!get_option('ddwpt_zag_filters_enabled')) {
            update_option('ddwpt_zag_filters_enabled', '1');
            set_transient('ddwpt_zag_filters_handover', 1, HOUR_IN_SECONDS);
        }
    });
}

add_action('admin_notices', static function () {
    if (!current_user_can('manage_options') || !get_transient('ddwpt_zag_filters_handover')) {
        return;
    }

    delete_transient('ddwpt_zag_filters_handover');

    echo '<div class="notice notice-success is-dismissible"><p><strong>WP Toolkit:</strong> '
        . 'Zag Filters for Bricks was deactivated, so the toolkit\'s built-in Zag Filters are now on. '
        . 'Existing Zag elements keep working unchanged.</p></div>';
});

$ddwpt_zag_description = 'Register the Zag Filters elements in Bricks: Zag.js filter select, date picker, sort, smart search, active filters, select and group. A drop-in replacement for the standalone "Zag Filters for Bricks" plugin, using the same element names and settings.';

if ($ddwpt_zag_standalone) {
    $ddwpt_zag_description .= ' <strong>The standalone plugin is active, so it is handling these elements and this copy is idle.</strong> Deactivating it switches this on automatically.';
}

return [
    'id'    => 'ddwpt_zag_filters',
    'label' => 'Zag Filters',
    'tab'   => 'builder',
    'group' => 'elements',
    'description' => 'Zag.js query filter elements: select, date picker, sort and search.',
    'tags'  => ['Frontend', 'Drop-in'],
    'requires' => ['Bricks'],

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => $ddwpt_zag_description,
        ],
    ],

    // Loading happens above, at file-load time.
    'callback' => static function ($settings) {
    },
];
