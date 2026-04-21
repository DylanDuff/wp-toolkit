<?php

namespace DDWPTweaks\Tweaks;

// ── Shared helpers ────────────────────────────────────────────────────────────

function wpsr_get_reviews_array() {
    global $wpdb;

    $table = $wpdb->prefix . 'wpsr_reviews';

    $rows = $wpdb->get_results(
        "SELECT reviewer_name, reviewer_url, reviewer_img, reviewer_text, review_time
         FROM {$table}
         WHERE rating = 5
           AND reviewer_text <> ''
         ORDER BY review_time DESC
         LIMIT 12"
    );

    if ( empty( $rows ) ) {
        return [];
    }

    $output = [];

    foreach ( $rows as $row ) {
        $output[] = [
            'reviewer_name' => $row->reviewer_name,
            'reviewer_url'  => $row->reviewer_url,
            'reviewer_img'  => $row->reviewer_img,
            'reviewer_text' => $row->reviewer_text,
            'review_time'   => date( 'F jS, Y', strtotime( $row->review_time ) ),
        ];
    }

    return $output;
}

function wpsr_get_loop_object_property( $name ) {
    $loop_object = \Bricks\Query::get_loop_object();

    if ( ! $loop_object )                            return false;
    if ( ! is_array( $loop_object ) )                return false;
    if ( ! array_key_exists( $name, $loop_object ) ) return false;

    return $loop_object[ $name ];
}

function wpsr_field_map() {
    return [
        'wpsr_reviewer_name' => [ 'key' => 'reviewer_name', 'label' => 'Reviewer Name',  'context' => 'text'  ],
        'wpsr_reviewer_url'  => [ 'key' => 'reviewer_url',  'label' => 'Reviewer URL',   'context' => 'text'  ],
        'wpsr_reviewer_img'  => [ 'key' => 'reviewer_img',  'label' => 'Reviewer Image', 'context' => 'image' ],
        'wpsr_reviewer_text' => [ 'key' => 'reviewer_text', 'label' => 'Review Text',    'context' => 'text'  ],
        'wpsr_review_time'   => [ 'key' => 'review_time',   'label' => 'Review Date',    'context' => 'text'  ],
    ];
}

function wpsr_render_tags_in_content( $content, $post, $context = 'text' ) {
    if ( strpos( $content, '{wpsr_reviews}' ) !== false ) {
        $value = wpsr_get_reviews_array();
        return is_array( $value ) ? $value : str_replace( '{wpsr_reviews}', '', $content );
    }

    foreach ( wpsr_field_map() as $tag_name => $field ) {
        $tag = '{' . $tag_name . '}';

        if ( strpos( $content, $tag ) === false ) {
            continue;
        }

        $value = wpsr_get_loop_object_property( $field['key'] );

        if ( $value === false ) {
            continue;
        }

        $content = str_replace( $tag, $value, $content );
    }

    return $content;
}

// ── Tweak definition ──────────────────────────────────────────────────────────

return [
    'id'    => 'ddwpt_wpsr_bricks',
    'label' => 'WP Social Ninja — Bricks',
    'tab'   => 'bricks',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Expose WP Social Ninja reviews as Bricks dynamic tags. Array loop source: {wpsr_reviews}. Per-field tags: {wpsr_reviewer_name}, {wpsr_reviewer_url}, {wpsr_reviewer_img}, {wpsr_reviewer_text}, {wpsr_review_time}.',
        ],
    ],

    'callback' => function ( $settings ) {
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // ── Dynamic tags list ─────────────────────────────────────────────
        add_filter( 'bricks/dynamic_tags_list', function ( $tags ) {
            $tags[] = [
                'name'  => '{wpsr_reviews}',
                'label' => 'WP Social Ninja Reviews (Array)',
                'group' => 'WP Social Ninja',
            ];

            foreach ( wpsr_field_map() as $tag_name => $field ) {
                $tags[] = [
                    'name'  => '{' . $tag_name . '}',
                    'label' => $field['label'],
                    'group' => 'WP Social Ninja',
                ];
            }

            return $tags;
        } );

        // ── Render individual tag ─────────────────────────────────────────
        add_filter( 'bricks/dynamic_data/render_tag', function ( $tag, $post, $context = 'text' ) {
            if ( ! is_string( $tag ) ) {
                return $tag;
            }

            $clean_tag = str_replace( [ '{', '}' ], '', $tag );

            if ( $clean_tag === 'wpsr_reviews' ) {
                return wpsr_get_reviews_array();
            }

            $field_map = wpsr_field_map();

            if ( ! array_key_exists( $clean_tag, $field_map ) ) {
                return $tag;
            }

            $field = $field_map[ $clean_tag ];
            $value = wpsr_get_loop_object_property( $field['key'] );

            if ( $value === false ) {
                return $tag;
            }

            if ( $field['context'] === 'image' && $context === 'image' ) {
                return ! empty( $value ) ? [ $value ] : [];
            }

            return $value;
        }, 20, 3 );

        // ── Render tags inside content strings ────────────────────────────
        add_filter( 'bricks/dynamic_data/render_content', __NAMESPACE__ . '\\wpsr_render_tags_in_content', 20, 3 );
        add_filter( 'bricks/frontend/render_data',        __NAMESPACE__ . '\\wpsr_render_tags_in_content', 20, 2 );

        // ── Custom query type (legacy) ─────────────────────────────────────
        add_filter( 'bricks/setup/control_options', function ( $control_options ) {
            $control_options['queryTypes']['reviews'] = esc_html__( 'WP Social Ninja Reviews', 'bpf-reviews' );
            return $control_options;
        } );

        add_filter( 'bricks/query/run', function ( $results, $query_obj ) {
            if ( $query_obj->object_type !== 'reviews' ) {
                return $results;
            }

            return wpsr_get_reviews_array();
        }, 10, 2 );

        add_filter( 'bricks/query/loop_object', function ( $loop_object, $loop_key, $query_obj ) {
            if ( $query_obj->object_type !== 'reviews' ) {
                return $loop_object;
            }

            return $loop_object;
        }, 10, 3 );
    },
];
