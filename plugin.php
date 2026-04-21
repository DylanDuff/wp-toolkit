<?php

/**
 * Plugin Name: WP Toolkit
 * Description: Modular WP admin tweaks loaded from individual drop-in files.
 * Version: 1.1.0
 * Author:      Dylan Duff
 * Author URI:  https://dylanduff.com
 * GitHub URI:  https://github.com/DylanDuff/wp-toolkit
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/inc/class-plugin.php';
require_once __DIR__ . '/inc/class-knowledge-base.php';
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
    new DDWPTweaks\Plugin();
});
