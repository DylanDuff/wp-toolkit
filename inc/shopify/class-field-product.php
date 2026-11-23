<?php

namespace DDWPTweaks\Shopify;

defined('ABSPATH') || exit;

/**
 * ACF field type `shopify_product`: searches Shopify products and stores the
 * selected product's GID. The type name matches the standalone plugin, so
 * existing field groups and stored values keep working.
 */
class Field_Product extends \acf_field
{
    public function __construct()
    {
        $this->name        = 'shopify_product';
        $this->label       = 'Shopify Product';
        $this->category    = 'relational';
        $this->description = 'Search and select a Shopify product. Stores the product GID; use aspf_get_product_field() to resolve it to live product data.';
        $this->defaults    = ['placeholder' => ''];

        parent::__construct();
    }

    public function render_field_settings($field)
    {
        render_placeholder_setting($field);
    }

    public function render_field($field)
    {
        $value = (string) $field['value'];
        $label = $value !== '' ? aspf_get_cached_product_label($value) : '';
        ?>
        <select
            id="<?php echo esc_attr($field['id']); ?>"
            class="aspf-shopify-product-select"
            style="width:100%"
            name="<?php echo esc_attr($field['name']); ?>"
            data-nonce="<?php echo esc_attr(wp_create_nonce(SEARCH_ACTION)); ?>"
            data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
            data-placeholder="<?php echo esc_attr(placeholder($field)); ?>"
        >
            <?php if ($value !== '') : ?>
                <option value="<?php echo esc_attr($value); ?>" selected="selected"><?php echo esc_html($label !== '' ? $label : $value); ?></option>
            <?php endif; ?>
        </select>
        <?php
    }

    public function input_admin_enqueue_scripts()
    {
        enqueue_field_assets('acf-shopify-product', ['acf-input', 'jquery', 'select2']);
    }
}
