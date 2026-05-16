<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_acf_faq',
    'label' => 'FAQs',
    'tab'   => 'acf',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Register the FAQ custom post type and the Relationship: FAQs ACF field group.',
        ],
        [
            'id'      => 'post_types',
            'type'    => 'checkboxes',
            'label'   => 'Show field on',
            'options' => function () {
                $post_types = get_post_types(['show_ui' => true], 'objects');
                $options = [];
                foreach ($post_types as $slug => $obj) {
                    $options[$slug] = $obj->labels->singular_name;
                }
                return $options;
            },
            'default' => '["page","post"]',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        register_post_type('faq', [
            'labels' => [
                'name'                     => 'FAQs',
                'singular_name'            => 'FAQ',
                'menu_name'                => 'FAQs',
                'all_items'                => 'All FAQs',
                'edit_item'                => 'Edit FAQ',
                'view_item'                => 'View FAQ',
                'view_items'               => 'View FAQs',
                'add_new_item'             => 'Add New FAQ',
                'add_new'                  => 'Add New FAQ',
                'new_item'                 => 'New FAQ',
                'parent_item_colon'        => 'Parent FAQ:',
                'search_items'             => 'Search FAQs',
                'not_found'                => 'No faqs found',
                'not_found_in_trash'       => 'No faqs found in Trash',
                'archives'                 => 'FAQ Archives',
                'attributes'               => 'FAQ Attributes',
                'insert_into_item'         => 'Insert into faq',
                'uploaded_to_this_item'    => 'Uploaded to this faq',
                'filter_items_list'        => 'Filter faqs list',
                'filter_by_date'           => 'Filter faqs by date',
                'items_list_navigation'    => 'FAQs list navigation',
                'items_list'               => 'FAQs list',
                'item_published'           => 'FAQ published.',
                'item_published_privately' => 'FAQ published privately.',
                'item_reverted_to_draft'   => 'FAQ reverted to draft.',
                'item_scheduled'           => 'FAQ scheduled.',
                'item_updated'             => 'FAQ updated.',
                'item_link'                => 'FAQ Link',
                'item_link_description'    => 'A link to a faq.',
            ],
            'public'             => true,
            'publicly_queryable' => false,
            'show_in_rest'       => true,
            'menu_icon'          => 'dashicons-align-center',
            'supports'           => ['title', 'editor', 'custom-fields'],
            'taxonomies'         => ['post_tag'],
            'delete_with_user'   => false,
        ]);

        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        $selected = json_decode($settings['post_types'] ?? '[]', true);
        if (empty($selected) || !is_array($selected)) {
            return;
        }

        $location = array_map(
            fn($slug) => [['param' => 'post_type', 'operator' => '==', 'value' => $slug]],
            $selected
        );

        acf_add_local_field_group([
            'key'    => 'group_695c328871ee2',
            'title'  => 'Relationship: FAQs',
            'fields' => [
                [
                    'key'               => 'field_695c3288d1891',
                    'label'             => 'Linked FAQs',
                    'name'              => 'linked_faqs',
                    'type'              => 'relationship',
                    'post_type'         => ['faq'],
                    'filters'           => ['search', 'taxonomy'],
                    'return_format'     => 'object',
                    'bidirectional'     => 0,
                    'allow_in_bindings' => 0,
                ],
            ],
            'location'      => $location,
            'position'      => 'normal',
            'active'        => true,
            'display_title' => 'FAQs',
        ]);
    },
];
