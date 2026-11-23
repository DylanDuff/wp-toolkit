<?php

namespace DDWPTweaks\Shopify;

defined('ABSPATH') || exit;

/**
 * ACF field type `shopify_products`: picks several Shopify products into a
 * sortable list, like ACF's Relationship field, and stores an ordered array
 * of GIDs. The type name and settings match the standalone plugin.
 */
class Field_Products extends \acf_field
{
    public function __construct()
    {
        $this->name        = 'shopify_products';
        $this->label       = 'Shopify Products';
        $this->category    = 'relational';
        $this->description = 'Search and select multiple Shopify products, reorderable like the ACF Relationship field. Stores an ordered array of product GIDs.';
        $this->defaults    = [
            'placeholder'    => '',
            'max'            => 0,
            'collection_gid' => '',
        ];

        parent::__construct();
    }

    public function render_field_settings($field)
    {
        render_placeholder_setting($field);

        acf_render_field_setting($field, [
            'label'        => 'Maximum Products',
            'instructions' => 'Leave blank or 0 for no limit.',
            'type'         => 'number',
            'name'         => 'max',
            'min'          => 0,
        ]);

        acf_render_field_setting($field, [
            'label'        => 'Restrict to Collection',
            'instructions' => 'Only allow selecting products from this collection. Requires Shopify credentials under Tools → WP Toolkit → ACF.',
            'type'         => 'select',
            'name'         => 'collection_gid',
            'choices'      => ['' => '— All products —'] + array_column(aspf_get_cached_collections(), 'title', 'gid'),
            'allow_null'   => false,
        ]);
    }

    public function render_field($field)
    {
        $value       = is_array($field['value']) ? array_values(array_filter($field['value'])) : [];
        $selected    = $value ? aspf_get_cached_products_summary($value) : [];
        $max         = (int) $field['max'];
        $collections = empty($field['collection_gid']) ? aspf_get_cached_collections() : [];
        ?>
        <div class="aspf-shopify-products-field">
            <input type="hidden" name="<?php echo esc_attr($field['name']); ?>" value="" />
            <div class="aspf-relationship">
                <div class="aspf-relationship-left">
                    <div class="aspf-products-search-row">
                        <input
                            type="text"
                            class="aspf-products-search"
                            placeholder="<?php echo esc_attr(placeholder($field)); ?>"
                            data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                            data-nonce="<?php echo esc_attr(wp_create_nonce(SEARCH_ACTION)); ?>"
                            data-collection-gid="<?php echo esc_attr($field['collection_gid']); ?>"
                        />
                        <?php if ($collections) : ?>
                            <select class="aspf-collection-filter">
                                <option value="">All Collections</option>
                                <?php foreach ($collections as $collection) : ?>
                                    <option value="<?php echo esc_attr($collection['gid']); ?>"><?php echo esc_html($collection['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <ul class="aspf-products-results"></ul>
                </div>
                <div class="aspf-relationship-right">
                    <?php if ($max > 0) : ?>
                        <p class="aspf-products-max-notice"><?php echo esc_html(sprintf(_n('Maximum of %d product.', 'Maximum of %d products.', $max), $max)); ?></p>
                    <?php endif; ?>
                    <ul class="aspf-products-values" data-max="<?php echo esc_attr($max); ?>">
                        <?php foreach ($selected as $product) : ?>
                            <li data-gid="<?php echo esc_attr($product['gid']); ?>">
                                <input type="hidden" name="<?php echo esc_attr($field['name']); ?>[]" value="<?php echo esc_attr($product['gid']); ?>" />
                                <?php if (!empty($product['image'])) : ?>
                                    <img src="<?php echo esc_url($product['image']); ?>" alt="" />
                                <?php endif; ?>
                                <span class="aspf-product-title"><?php echo esc_html($product['title']); ?></span>
                                <a href="#" class="aspf-remove-product" aria-label="Remove">&times;</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    public function input_admin_enqueue_scripts()
    {
        enqueue_field_assets('acf-shopify-products', ['acf-input', 'jquery', 'jquery-ui-sortable']);
    }
}
