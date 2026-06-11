<?php

/**
 * Plugin Name: WP Toolkit
 * Description: Modular WP admin tweaks loaded from individual drop-in files.
 * Version: 1.3.8
 * Author:      Dylan Duff
 * Author URI:  https://dylanduff.com
 * GitHub URI:  https://github.com/DylanDuff/wp-toolkit
 */

defined('ABSPATH') || exit;

define('DDWPT_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version'] ?? '1.0.0');
define('DDWPT_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/inc/class-tweak-loader.php';
require_once __DIR__ . '/inc/class-knowledge-base.php';
require_once __DIR__ . '/inc/class-plugin.php';
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/DylanDuff/wp-toolkit/',
    __FILE__,
    'wp-toolkit'
);

$myUpdateChecker->setBranch('main');
$myUpdateChecker->getVcsApi()->enableReleaseAssets();

add_action('plugins_loaded', function () {
    // Tweak runner always boots — CPTs, ACF, GTM etc. must register on every request.
    $tweaks = (new DDWPTweaks\Tweak_Loader())->load_all();

    // Admin UI only needed in the dashboard and AJAX context.
    if (is_admin()) {
        new DDWPTweaks\Plugin($tweaks);
    }
});
