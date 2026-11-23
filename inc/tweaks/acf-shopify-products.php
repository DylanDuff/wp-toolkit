<?php

namespace DDWPTweaks\Tweaks;

use DDWPTweaks\Shopify;
use DDWPTweaks\Shopify\Client;

require_once __DIR__ . '/../shopify/class-shopify-client.php';
require_once __DIR__ . '/../shopify/integration.php';

/**
 * Shopify Product / Shopify Products ACF field types, backed by the Storefront
 * API, with Bricks loop tags. A drop-in replacement for the standalone
 * "ACF Shopify Product Field" plugin: same field type names, helper
 * functions, hooks and Bricks tags, and its saved credentials are migrated.
 * Stands down while that plugin is still active. See docs/acf-shopify-products.md.
 */

const ACF_SHOPIFY_ENABLED  = 'ddwpt_acf_shopify_products_enabled';
const ACF_SHOPIFY_MIGRATED = 'ddwpt_acf_shopify_products_migrated';

// The standalone plugin declares the same global functions and field types.
$standalone_active = defined('ASPF_PLUGIN_FILE');

if (!$standalone_active && !get_option(ACF_SHOPIFY_MIGRATED)) {
    // First load after the standalone plugin is switched off: carry its
    // credentials over and switch the tweak on so its fields keep working.
    $legacy = get_option(Client::LEGACY_OPTION);

    if (is_array($legacy) && (!empty($legacy['shop_domain']) || !empty($legacy['storefront_token']))) {
        if (!get_option(Client::OPTION_DOMAIN) && !empty($legacy['shop_domain'])) {
            update_option(Client::OPTION_DOMAIN, Client::normalize_domain($legacy['shop_domain']));
        }
        if (!get_option(Client::OPTION_TOKEN) && !empty($legacy['storefront_token'])) {
            update_option(Client::OPTION_TOKEN, sanitize_text_field($legacy['storefront_token']));
        }
        if (get_option(ACF_SHOPIFY_ENABLED) === false) {
            update_option(ACF_SHOPIFY_ENABLED, '1');
        }
        update_option(ACF_SHOPIFY_MIGRATED, '1', false);
    }
}

if (get_option(ACF_SHOPIFY_ENABLED)) {
    if ($standalone_active) {
        add_action('admin_notices', static function () {
            if (!current_user_can('activate_plugins')) return;
            echo '<div class="notice notice-warning"><p>WP Toolkit’s Shopify Product fields are paused while the standalone <strong>ACF Shopify Product Field</strong> plugin is active. Deactivate that plugin: its fields, values and credentials carry over.</p></div>';
        });
    } else {
        require_once __DIR__ . '/../shopify/functions.php';

        // ACF fires acf/include_field_types on init priority 5, before tweak callbacks.
        add_action('acf/include_field_types', 'DDWPTweaks\\Shopify\\register_field_types');
        add_action('wp_ajax_' . Shopify\SEARCH_ACTION, 'DDWPTweaks\\Shopify\\search_products');
    }
}

// Token input: masked, and left blank to keep the saved token.
add_action('ddwpt_render_field_shopify_token', static function ($field, $value, $field_id) {
    printf(
        '<input type="password" class="regular-text" autocomplete="off" id="%1$s" name="%1$s" value="" placeholder="%2$s" />',
        esc_attr($field_id),
        esc_attr($value ? 'Saved. Leave blank to keep the current token.' : '')
    );
}, 10, 3);

return [
    'id'    => 'ddwpt_acf_shopify_products',
    'label' => 'Shopify Product Fields',
    'tab'   => 'acf',
    'group' => 'general',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Add Shopify Product and Shopify Products ACF field types that search your store through the Storefront API, plus Bricks loop tags. Replaces the standalone ACF Shopify Product Field plugin.' . ($standalone_active ? ' <strong>Paused: deactivate the standalone plugin first.</strong>' : ''),
        ],
        [
            'id'          => 'shop_domain',
            'type'        => 'text',
            'label'       => 'Shop domain',
            'description' => 'The .myshopify.com domain, e.g. <code>your-shop.myshopify.com</code>.',
            'sanitize'    => [Client::class, 'normalize_domain'],
        ],
        [
            'id'          => 'storefront_token',
            'type'        => 'shopify_token',
            'label'       => 'Storefront API access token',
            'description' => 'From a Shopify custom app or the Headless channel. The <code>unauthenticated_read_product_listings</code> scope is enough.',
            'sanitize'    => static function ($value) {
                $value = sanitize_text_field((string) $value);
                return $value !== '' ? $value : (string) get_option(Client::OPTION_TOKEN, '');
            },
        ],
    ],

    'callback' => function ($settings) use ($standalone_active) {
        if (empty($settings['enabled']) || $standalone_active || !defined('BRICKS_VERSION')) {
            return;
        }

        Shopify\register_bricks();
    },
];
