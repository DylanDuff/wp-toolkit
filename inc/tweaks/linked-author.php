<?php

namespace DDWPTweaks\Tweaks;

use DDWPTweaks\Bricks_Tags;

const LINKED_AUTHOR_META      = 'linked_author';
const LINKED_AUTHOR_POST_TYPE = 'team-member';

/**
 * Post types the linked author applies to. Set once by the tweak callback.
 */
function linked_author_post_types(?array $set = null): array
{
    static $post_types = [];

    if ($set !== null) {
        $post_types = $set;
    }

    return $post_types;
}

/**
 * The published team member linked to a post, or null.
 */
function linked_author($post = null): ?\WP_Post
{
    static $cache = [];

    $post = get_post($post);

    if (!$post || !in_array($post->post_type, linked_author_post_types(), true)) {
        return null;
    }

    if (!array_key_exists($post->ID, $cache)) {
        $member = get_post((int) get_post_meta($post->ID, LINKED_AUTHOR_META, true));

        $cache[$post->ID] = ($member && $member->post_type === LINKED_AUTHOR_POST_TYPE && $member->post_status === 'publish')
            ? $member
            : null;
    }

    return $cache[$post->ID];
}

/**
 * Team member to substitute for an author lookup, or null to keep core output.
 * Front end only (wp-admin keeps showing real users), the current post only,
 * and only when the lookup is for that post's own author.
 */
function linked_author_override($user_id = null, $post = null): ?\WP_Post
{
    if (is_admin() && !wp_doing_ajax()) {
        return null;
    }

    $post   = get_post($post);
    $member = linked_author($post);

    if (!$member || ($user_id && (int) $user_id !== (int) $post->post_author)) {
        return null;
    }

    return $member;
}

function linked_author_bio(\WP_Post $member): string
{
    $bio = trim(wp_strip_all_tags($member->post_content));

    return $bio !== '' ? $bio : trim(wp_strip_all_tags($member->post_excerpt));
}

function linked_author_photo(\WP_Post $member, string $size = 'medium'): string
{
    return get_the_post_thumbnail_url($member, $size) ?: '';
}

/**
 * One author field for a post: the linked team member's when set, otherwise
 * the WordPress user's. Fields: name, bio, url, photo, role, id.
 */
function linked_author_field(string $field, $post = null): string
{
    $post = get_post($post);

    if (!$post) {
        return '';
    }

    $member = linked_author($post);

    if (!$member) {
        switch ($field) {
            case 'name':
                return (string) get_the_author_meta('display_name', $post->post_author);
            case 'bio':
                return (string) get_the_author_meta('description', $post->post_author);
            case 'url':
                return get_author_posts_url($post->post_author);
            case 'photo':
                return (string) get_avatar_url($post->post_author, ['size' => 300]);
            default:
                return '';
        }
    }

    switch ($field) {
        case 'id':
            return (string) $member->ID;
        case 'name':
            return get_the_title($member);
        case 'bio':
            return linked_author_bio($member);
        case 'url':
            return (string) get_permalink($member);
        case 'photo':
            return linked_author_photo($member);
        case 'role':
            // Job Title taxonomy from the Team Members preset.
            $terms = taxonomy_exists('job-title') ? get_the_terms($member, 'job-title') : false;
            return is_array($terms) ? implode(', ', wp_list_pluck($terms, 'name')) : '';
        default:
            return '';
    }
}

function linked_author_register_field(array $post_types): void
{
    if (!function_exists('acf_add_local_field_group') || !$post_types) {
        return;
    }

    acf_add_local_field_group([
        'key'    => 'group_ddwpt_linked_author',
        'title'  => 'Relationship: Post Author',
        'fields' => [
            [
                'key'           => 'field_ddwpt_linked_author',
                'label'         => 'Author',
                'name'          => LINKED_AUTHOR_META,
                'type'          => 'post_object',
                'instructions'  => 'Show this team member as the post author on the front end. Leave empty to use the WordPress user.',
                'post_type'     => [LINKED_AUTHOR_POST_TYPE],
                'post_status'   => ['publish'],
                'return_format' => 'id',
                'allow_null'    => 1,
                'multiple'      => 0,
                'ui'            => 1,
            ],
        ],
        'location' => array_map(
            static fn($slug) => [['param' => 'post_type', 'operator' => '==', 'value' => $slug]],
            $post_types
        ),
        'position' => 'side',
        'active'   => true,
    ]);
}

return [
    'id'    => 'ddwpt_linked_author',
    'label' => 'Team Member Authors',
    'tab'   => 'acf',
    'group' => 'presets',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Let a post name a team member as its author. On the front end, the author name, bio, link and avatar (including Bricks author tags) resolve to that team member. Needs the Team Members preset.',
        ],
        [
            'id'      => 'post_types',
            'type'    => 'checkboxes',
            'label'   => 'Post types',
            'options' => function () {
                $options = [];
                foreach (get_post_types(['show_ui' => true], 'objects') as $slug => $object) {
                    if ($slug !== LINKED_AUTHOR_POST_TYPE && post_type_supports($slug, 'author')) {
                        $options[$slug] = $object->labels->singular_name;
                    }
                }
                return $options;
            },
            'default' => '["post"]',
        ],
        [
            'id'          => 'register_field',
            'type'        => 'checkbox',
            'label'       => 'Add the Author field',
            'description' => 'Adds an ACF "Author" field (<code>linked_author</code>) to these post types. Turn off if the site already defines its own <code>linked_author</code> field.',
            'default'     => '1',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        $post_types = $settings['post_types'];
        $post_types = is_array($post_types) ? $post_types : (json_decode((string) $post_types, true) ?: []);
        $post_types = array_values(array_filter(array_map('sanitize_key', $post_types)));

        if (!$post_types) {
            return;
        }

        linked_author_post_types($post_types);

        if (!empty($settings['register_field'])) {
            linked_author_register_field($post_types);
        }

        // get_the_author() / the_author()
        add_filter('the_author', static function ($name) {
            $member = linked_author_override();
            return $member ? get_the_title($member) : $name;
        });

        // get_the_author_meta('{field}'): themes, Bricks author tags, SEO plugins.
        foreach (['display_name', 'nickname', 'first_name', 'last_name', 'description', 'user_url'] as $field) {
            add_filter("get_the_author_{$field}", static function ($value, $user_id) use ($field) {
                $member = linked_author_override($user_id);

                if (!$member) {
                    return $value;
                }

                $name  = get_the_title($member);
                $parts = preg_split('/\s+/', $name, 2);

                switch ($field) {
                    case 'first_name':
                        return $parts[0];
                    case 'last_name':
                        return $parts[1] ?? '';
                    case 'description':
                        return linked_author_bio($member);
                    case 'user_url':
                        return get_permalink($member);
                    default:
                        return $name;
                }
            }, 10, 2);
        }

        // get_author_posts_url() / the_author_posts_link() → team member profile.
        add_filter('author_link', static function ($link, $author_id) {
            $member = linked_author_override($author_id);
            return $member ? get_permalink($member) : $link;
        }, 10, 2);

        // get_avatar() / get_avatar_url() → team member photo. Comments and emails are left alone.
        add_filter('pre_get_avatar_data', static function ($args, $id_or_email) {
            if ($id_or_email instanceof \WP_Post) {
                $member = (is_admin() && !wp_doing_ajax()) ? null : linked_author($id_or_email);
            } elseif ($id_or_email instanceof \WP_User) {
                $member = linked_author_override($id_or_email->ID);
            } elseif (is_numeric($id_or_email)) {
                $member = linked_author_override((int) $id_or_email);
            } else {
                return $args;
            }

            $url = $member ? linked_author_photo($member, ((int) ($args['size'] ?? 96)) > 150 ? 'medium' : 'thumbnail') : '';

            if ($url) {
                $args['url']          = $url;
                $args['found_avatar'] = true;
            }

            return $args;
        }, 10, 2);

        if (!defined('BRICKS_VERSION')) {
            return;
        }

        // Bricks {author_name} reads WP_User properties directly, bypassing the filters above.
        add_filter('bricks/dynamic_data/author_value', static function ($value, $field_type) {
            if ($field_type !== 'name') {
                return $value;
            }

            $member = linked_author_override();
            return $member ? get_the_title($member) : $value;
        }, 10, 2);

        Bricks_Tags::register('WP Toolkit', [
            'linked_author' => [
                'label'   => 'Post author (add :name, :bio, :url, :photo or :role)',
                'resolve' => static fn(array $args, $post) => linked_author_field($args[0] ?? 'name', $post),
                'image'   => true,
            ],
        ]);
    },
];
