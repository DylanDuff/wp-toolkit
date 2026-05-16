<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_acf_service_areas',
    'label' => 'Service Areas',
    'tab'   => 'acf',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Register the Service Areas custom post type and the Region taxonomy.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        register_taxonomy('region', ['service-area'], [
            'labels' => [
                'name'                  => 'Regions',
                'singular_name'         => 'Region',
                'menu_name'             => 'Regions',
                'all_items'             => 'All Regions',
                'edit_item'             => 'Edit Region',
                'view_item'             => 'View Region',
                'update_item'           => 'Update Region',
                'add_new_item'          => 'Add New Region',
                'new_item_name'         => 'New Region Name',
                'parent_item'           => 'Parent Region',
                'parent_item_colon'     => 'Parent Region:',
                'search_items'          => 'Search Regions',
                'not_found'             => 'No regions found',
                'no_terms'              => 'No regions',
                'filter_by_item'        => 'Filter by region',
                'items_list_navigation' => 'Regions list navigation',
                'items_list'            => 'Regions list',
                'back_to_items'         => '← Go to regions',
                'item_link'             => 'Region Link',
                'item_link_description' => 'A link to a region',
            ],
            'public'           => true,
            'hierarchical'     => true,
            'show_in_menu'     => true,
            'show_in_rest'     => true,
            'show_admin_column' => true,
        ]);

        register_post_type('service-area', [
            'labels' => [
                'name'                  => 'Service Areas',
                'singular_name'         => 'Service Area',
                'menu_name'             => 'Areas',
                'all_items'             => 'All Service Areas',
                'edit_item'             => 'Edit Service Area',
                'view_item'             => 'View Service Area',
                'view_items'            => 'View Service Areas',
                'add_new_item'          => 'Add New Service Area',
                'add_new'               => 'Add New Service Area',
                'new_item'              => 'New Service Area',
                'parent_item_colon'     => 'Parent Service Area:',
                'search_items'          => 'Search Service Areas',
                'not_found'             => 'No service areas found',
                'not_found_in_trash'    => 'No service areas found in Trash',
                'archives'              => 'Service Area Archives',
                'attributes'            => 'Service Area Attributes',
                'insert_into_item'      => 'Insert into service area',
                'uploaded_to_this_item' => 'Uploaded to this service area',
                'filter_items_list'     => 'Filter service areas list',
                'filter_by_date'        => 'Filter service areas by date',
                'items_list_navigation' => 'Service Areas list navigation',
                'items_list'            => 'Service Areas list',
                'item_published'        => 'Service Area published.',
                'item_published_privately' => 'Service Area published privately.',
                'item_reverted_to_draft'   => 'Service Area reverted to draft.',
                'item_scheduled'        => 'Service Area scheduled.',
                'item_updated'          => 'Service Area updated.',
                'item_link'             => 'Service Area Link',
                'item_link_description' => 'A link to a service area.',
            ],
            'public'           => true,
            'show_in_rest'     => true,
            'menu_icon'        => 'dashicons-location-alt',
            'supports'         => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'has_archive'      => 'areas',
            'rewrite'          => ['feeds' => false],
            'delete_with_user' => false,
        ]);
    },
];
