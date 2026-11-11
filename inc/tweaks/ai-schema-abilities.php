<?php

namespace DDWPTweaks\Tweaks;

use DDWPTweaks\SEO\Schema_Sources;
use DDWPTweaks\SEO\Schema_Syntax;
use DDWPTweaks\SEO\Schema_Transforms;
use DDWPTweaks\SEO\Schema_Types;

/**
 * Abilities for the experimental Structured Data tweak: a guide to the
 * template format and what this site offers, reading and writing templates
 * and their nodes, and a dry-run preview. Agents only ever write template
 * JSON; field discovery is left to the ACF and content abilities.
 * See docs/ai-abilities.md and docs/seo-schema.md.
 */

const AI_SCHEMA_GUIDE = <<<'GUIDE'
# Structured data templates

Each public post type can have one template: a JSON array of schema.org nodes. On a single post of that type the template is rendered against the post and printed as JSON-LD in `@graph`. You only ever write that JSON. Never write PHP or theme code for schema.

## Workflow

1. Call `get-schema-guide` with `post_type` to see the tags that post type offers, the filters, the schema.org types the editor knows about, and the current template.
2. If you need more detail about a field (its type, repeater sub-fields, real values), use the abilities you already have. `get-acf-field-group` / `list-acf-field-groups` give field names and types. `get-post` returns a real post's ACF values under `meta`, keyed by field name, and `get-option-field` reads options page fields. A tag is the field name path, so you never need to guess a field key.
3. Write the template JSON and call `preview-schema` with it and a real `post_id`. This is a dry run and saves nothing. Fix every warning you can.
4. Save it with `set-schema-template` (the whole template), or change one node with `save-schema-node` / `delete-schema-node`. Pass `enabled: true` only when asked to switch output on.

## Template format

- The template is an array of root nodes. Each root node is a JSON-LD object with an `@type` and usually an `@id` such as `"{post_url}#recipe"`.
- Any string value may contain tokens: `"{post_title}"`, `"{post_url}#clinic"`, `"{site_title} {post_title}"`.
- A value that is exactly one token keeps the source's type (number, list, object). Mixed text always renders as a string.
- Empty values and empty objects are dropped from the output, so it is safe to map optional fields.
- Do not add `@context`. Do not add the site Organization or WebSite nodes; the site settings own those. Point at them with `"{ref_organization}"` / `"{ref_website}"`.

## Tokens (Bricks-style dynamic data)

```
{tag}                       {post_title}
{tag:filter:filter}         {acf_recipe_general_prep_time:minutes:duration}
{tag:filter|arg}            {post_content:summary|220}
{tag @fallback:'text'}      {acf_location_general_phone:phone|+61 @fallback:'+61 1300 123 674'}
```

- Filters run left to right. An argument follows `|`. Quote it with single quotes when it contains `:`, `|`, `{`, `}`, `@`, a quote or edge spaces: `{item_label:prefix|' (':suffix|')'}`. Escape a quote as `\'`.
- `@fallback` is used when the filtered value is empty, and may contain tokens.
- `{}` with filters but no tag, such as `{:address|AU}`, is a placeholder from a preset: replace it with a real tag before saving.

## Tags

| Tag | Value |
|---|---|
| `post_title` `post_url` `post_content` `post_excerpt` `post_date` `post_modified` `featured_image` `author_name` `post_id` `post_type` | Core post fields |
| `site_title` `site_url` `site_logo` | Site |
| `ref_organization` `ref_website` | Reference to the site Organization / WebSite node |
| `acf_<field path>` | ACF field on the post. The path is the field **names** joined with `_` through Group fields: field `prep_time` inside group `general` inside group `recipe` is `acf_recipe_general_prep_time`. |
| `acf_option_<field path>` | ACF field on an options page |
| `cf_<meta_key>` | Raw post meta, for non-ACF meta |
| `post_terms_<taxonomy>` | The post's terms (a list) |
| `item` `item_<sub field>` `query_loop_index` | The current row inside `@each` (repeater sub-field by name, term `item_name`/`item_slug`/`item_url`, 1-based index) |

The `sources` in this guide's response list every tag the post type offers, with labels and ACF field types. Fields marked `list: true` (repeaters, relationships, taxonomies, galleries) are what `@each` loops over; their `children` are the `item_*` tags available inside the loop.

## Directives

Keys the renderer reads and never outputs. Their values are bare expressions without braces: `"@each": "acf_recipe_general_method"`.

| Directive | Meaning |
|---|---|
| `@each` | Output this object once per row of a list tag. Long form: `{"rows": "<tag>", "if": "<tag>", "else": {"<item name>": "<tag>"}}`; `if` gates the rows, `else` builds one row from other tags when there are none |
| `@value` | Inside `@each`: output this value per row instead of an object (plain string lists such as `recipeIngredient`) |
| `@from` | Switch `post_*` tags to a related post (relationship / post object field) |
| `@if` / `@unless` | Keep the object only when a tag has a value / is empty |
| `@else` | Used when the object comes out empty, `@from` finds no post or `@each` has no rows |
| `@merge` | Spread a resolved object (for example from the `pairs` filter) into this node |
| `@maps` | Root node only: named lookup tables for the `map` filter, `{"diets": {"Vegan": "https://schema.org/VeganDiet"}}` |

## Examples

```json
[
  {
    "@type": "Recipe",
    "@id": "{post_url}#recipe",
    "name": "{post_title}",
    "image": "{featured_image}",
    "description": "{post_content:summary|220}",
    "author": "{ref_organization}",
    "prepTime": "{acf_recipe_general_prep_time:minutes:duration}",
    "recipeIngredient": { "@each": "acf_recipe_general_ingredients", "@value": "{item_quantity} {item_ingredient}" },
    "recipeInstructions": { "@each": "acf_recipe_general_method", "@type": "HowToStep", "text": "{item_step:clean}" }
  }
]
```

FAQ from a repeater:

```json
[
  {
    "@type": "FAQPage",
    "mainEntity": {
      "@each": "acf_faqs",
      "@type": "Question",
      "name": "{item_question:clean}",
      "acceptedAnswer": { "@type": "Answer", "text": "{item_answer:clean}" }
    }
  }
]
```

## Output

Templates only print when the Structured Data tweak is enabled, Experiments is enabled and the template's own `enabled` flag is on. The response's `status` says which of these are set. Keep the required properties of each type filled; `preview-schema` warns about missing ones.
GUIDE;

/** The post types the editor offers (public, not attachments). */
function ai_schema_post_types(): array
{
    $types = get_post_types(['public' => true], 'objects');
    unset($types['attachment']);
    return $types;
}

function ai_schema_check_post_type($post_type)
{
    $post_type = sanitize_key((string) $post_type);
    if (!isset(ai_schema_post_types()[$post_type])) {
        return new \WP_Error('invalid_post_type', sprintf('"%s" is not a public post type. Call get-schema-guide to list them.', $post_type));
    }
    return $post_type;
}

/** Every tag value in a catalogue entry, including loop children. */
function ai_schema_catalogue_tags(array $groups): array
{
    $tags = [];
    foreach ($groups as $group) {
        foreach ($group['sources'] as $source) {
            $tags[] = $source['value'];
            foreach ($source['children'] ?? [] as $child) $tags[] = $child['value'];
        }
    }
    return array_unique($tags);
}

/** Expressions in a template: [expression, legacy, where] for every token and directive value. */
function ai_schema_expressions($value, string $path, array &$found): void
{
    if (is_string($value)) {
        foreach (Schema_Syntax::tokens($value) as $token) {
            $found[] = [$token['body'], $token['legacy'], $path];
        }
        return;
    }
    if (!is_array($value)) return;

    foreach ($value as $key => $child) {
        $where = $path . (is_int($key) ? "[$key]" : ".$key");
        if (in_array($key, ['@each', '@from', '@if', '@unless'], true)) {
            $parts = is_array($child) ? array_filter([$child['rows'] ?? null, $child['if'] ?? null]) : [$child];
            foreach ($parts as $part) {
                if (is_string($part) && $part !== '') $found[] = [$part, null, $where];
            }
            foreach (is_array($child) ? (array) ($child['else'] ?? []) : [] as $name => $source) {
                if (is_string($source) && $source !== '') $found[] = [$source, null, "$where.else.$name"];
            }
            continue;
        }
        if ($key === '@maps') continue;
        ai_schema_expressions($child, $where, $found);
    }
}

/**
 * Structural errors (the template can't be saved) and warnings (it can, but
 * something looks wrong: unknown filters or tags, preset placeholders).
 */
function ai_schema_validate($template, string $post_type): array
{
    $errors = [];
    $warnings = [];

    if (!is_array($template)) {
        return ['errors' => ['The template must be a JSON array of nodes (or one node object).'], 'warnings' => []];
    }

    $roots = array_is_list($template) ? $template : [$template];
    foreach ($roots as $index => $root) {
        if (!is_array($root) || array_is_list($root)) {
            $errors[] = "Node [$index] must be an object.";
            continue;
        }
        if (empty($root['@type']) && !isset($root['@each'])) {
            $errors[] = "Node [$index] has no @type.";
        }
        if (isset($root['@context'])) {
            $warnings[] = "Node [$index]: drop @context, the output adds it.";
        }
    }

    $catalogue = Schema_Sources::catalogue()[$post_type]['sources'] ?? [];
    $known     = ai_schema_catalogue_tags($catalogue);
    $filters   = array_keys(Schema_Transforms::all());
    $found     = [];
    ai_schema_expressions($roots, '', $found);

    foreach ($found as [$expression, $legacy, $where]) {
        $parsed = Schema_Syntax::parse($expression, $legacy);
        $source = $parsed['source'];

        if ($legacy || ($legacy === null && Schema_Syntax::is_legacy($expression))) {
            $warnings[] = "$where: \"$expression\" uses the old {{source|transform}} syntax; write {tag:filter|arg} instead.";
        }
        if ($source === '') {
            $warnings[] = "$where: a token has no tag (preset placeholder); pick a tag.";
        } elseif (!in_array($source, $known, true) && !preg_match('/^(item(_|$)|cf_|query_loop_index)/', $source)) {
            $warnings[] = "$where: \"$source\" is not a tag this post type offers.";
        }
        foreach ($parsed['filters'] as [$name]) {
            if (!in_array($name, $filters, true)) $warnings[] = "$where: unknown filter \"$name\".";
        }
    }

    return ['errors' => $errors, 'warnings' => array_values(array_unique($warnings))];
}

/** A published post to preview against: the given one, or the latest of the type. */
function ai_schema_preview_post(string $post_type, $post_id): ?\WP_Post
{
    if ($post_id) {
        $post = get_post((int) $post_id);
        return $post && $post->post_type === $post_type ? $post : null;
    }
    $posts = get_posts(['post_type' => $post_type, 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'modified']);
    return $posts[0] ?? null;
}

/** Preview plus validation, attached to every write so agents see the result of a save. */
function ai_schema_report(string $post_type, array $template, $post_id = null): array
{
    $check  = ai_schema_validate($template, $post_type);
    $post   = ai_schema_preview_post($post_type, $post_id);
    $report = ['errors' => $check['errors'], 'warnings' => $check['warnings'], 'preview' => null];

    if ($post && !$check['errors']) {
        $preview = seo_schema_preview($post->ID, $template);
        // The validator already names unknown tags and filters with their path.
        $rendered = array_filter($preview['warnings'], fn($warning) => !str_starts_with($warning, 'Unknown '));
        $report['warnings'] = array_values(array_unique(array_merge($report['warnings'], $rendered)));
        $report['preview']  = ['post_id' => $post->ID, 'title' => get_the_title($post), 'graph' => $preview['graph'], 'test_url' => $preview['test_url']];
    } elseif (!$post) {
        $report['warnings'][] = "No published $post_type post to preview against.";
    }

    return $report;
}

function ai_schema_status(): array
{
    return [
        'tweak_enabled'       => (bool) get_option('ddwpt_seo_schema_enabled'),
        'experiments_enabled' => (bool) get_option('ddwpt_experiments_enabled'),
        'organization_output' => get_option('ddwpt_seo_schema_organization_output', '') ?: 'none',
        'organization_type'   => get_option('ddwpt_seo_schema_organization_type', '') ?: 'Organization',
        'conflict_mode'       => get_option('ddwpt_seo_schema_conflict_mode', '') ?: 'replace',
    ];
}

/** Template input may arrive as JSON text from clients that stringify objects. */
function ai_schema_decode($value)
{
    return is_string($value) ? json_decode($value, true) : $value;
}

// Runs at plugins_loaded when the file is required, before wp_abilities_api_init fires.
if (get_option('ddwpt_ai_schema_abilities_enabled') && get_option('ddwpt_experiments_enabled')) {
    add_action('wp_abilities_api_categories_init', static function () {
        wp_register_ability_category('wp-toolkit-schema', [
            'label'       => 'WP Toolkit: Structured Data',
            'description' => 'Read and write the per-post-type JSON-LD templates of the experimental Structured Data tweak.',
        ]);
    });

    add_action('wp_abilities_api_init', static function () {
        if (!function_exists('wp_register_ability') || !function_exists(__NAMESPACE__ . '\\seo_schema_templates')) {
            return;
        }

        $allow_write = (bool) get_option('ddwpt_ai_schema_abilities_allow_write');
        $admin       = static fn() => current_user_can('manage_options');
        $post_type   = ['type' => 'string', 'description' => 'Post type slug, e.g. "recipe".'];
        $template    = ['type' => ['array', 'object', 'string'], 'description' => 'Template JSON: an array of schema.org nodes (a single node object is accepted).'];
        $node        = ['type' => ['object', 'string'], 'description' => 'One schema.org node object.'];
        $read_meta   = ['annotations' => ['readonly' => true, 'idempotent' => true], 'show_in_rest' => true, 'mcp' => ['public' => true]];
        $write_meta  = ['annotations' => ['destructive' => false, 'idempotent' => true], 'show_in_rest' => true, 'mcp' => ['public' => true]];

        wp_register_ability('wp-toolkit/get-schema-guide', [
            'label'       => 'Get Structured Data Guide',
            'description' => 'Read this before writing schema. Explains the JSON-LD template format, token syntax, directives and how ACF fields map to tags, and returns what this site offers: post types with their template status, the tags a post type offers (sources), filters, known schema.org types with required properties, and preset names. Pass post_type for its tags and current template; pass preset for a starting template.',
            'category'    => 'wp-toolkit-schema',
            'input_schema' => [
                'type'                 => ['object', 'null'],
                'properties'           => [
                    'post_type' => $post_type,
                    'preset'    => ['type' => 'string', 'description' => 'A schema.org type from presets, to return its starting template.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object'],
            'execute_callback' => static function ($input) {
                $input     = (array) $input;
                $templates = seo_schema_templates();
                $result    = [
                    'guide'      => AI_SCHEMA_GUIDE,
                    'status'     => ai_schema_status(),
                    'post_types' => array_values(array_map(fn($type) => [
                        'post_type'    => $type->name,
                        'label'        => $type->labels->name,
                        'has_template' => !empty($templates[$type->name]['template']),
                        'enabled'      => !empty($templates[$type->name]['enabled']),
                    ], ai_schema_post_types())),
                    'filters'    => Schema_Transforms::catalogue(),
                    'types'      => array_map(fn($type) => array_intersect_key($type, array_flip(['label', 'required', 'recommended'])), Schema_Types::all()),
                    'presets'    => array_keys(Schema_Types::presets()),
                ];

                if (!empty($input['post_type'])) {
                    $slug = ai_schema_check_post_type($input['post_type']);
                    if (is_wp_error($slug)) return $slug;
                    $result['post_type'] = [
                        'post_type' => $slug,
                        'sources'   => Schema_Sources::catalogue()[$slug]['sources'] ?? [],
                        'template'  => $templates[$slug] ?? null,
                    ];
                }

                if (!empty($input['preset'])) {
                    $preset = Schema_Types::presets()[$input['preset']] ?? null;
                    if (!$preset) return new \WP_Error('invalid_preset', sprintf('No preset "%s". Presets: %s', $input['preset'], implode(', ', array_keys(Schema_Types::presets()))));
                    $result['preset'] = $preset['template'];
                }

                return $result;
            },
            'permission_callback' => $admin,
            'meta' => $read_meta,
        ]);

        wp_register_ability('wp-toolkit/preview-schema', [
            'label'       => 'Preview Structured Data',
            'description' => 'Dry run: render a template (or the saved one) against a real post and return the JSON-LD graph, validation errors and warnings (unknown tags or filters, empty sources, missing required properties). Saves nothing. Defaults to the most recently modified published post of the type.',
            'category'    => 'wp-toolkit-schema',
            'input_schema' => [
                'type'                 => 'object',
                'properties'           => [
                    'post_type' => $post_type,
                    'template'  => $template,
                    'post_id'   => ['type' => 'integer', 'description' => 'Post to render against.'],
                ],
                'required'             => ['post_type'],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object'],
            'execute_callback' => static function ($input) {
                $slug = ai_schema_check_post_type($input['post_type'] ?? '');
                if (is_wp_error($slug)) return $slug;

                $template = isset($input['template']) ? ai_schema_decode($input['template']) : (seo_schema_templates()[$slug]['template'] ?? null);
                if (!is_array($template)) return new \WP_Error('no_template', 'Pass a template, or save one first: this post type has none.');
                if (!empty($input['post_id']) && !ai_schema_preview_post($slug, $input['post_id'])) {
                    return new \WP_Error('invalid_post', sprintf('Post %d is not a %s.', $input['post_id'], $slug));
                }

                return ai_schema_report($slug, $template, $input['post_id'] ?? null);
            },
            'permission_callback' => $admin,
            'meta' => $read_meta,
        ]);

        if (!$allow_write) return;

        wp_register_ability('wp-toolkit/set-schema-template', [
            'label'       => 'Set Structured Data Template',
            'description' => 'Replace a post type\'s whole template and/or switch its output on or off. Rejects structural errors; returns warnings and a preview against a real post. Call preview-schema first, and only pass enabled: true when asked to publish the schema.',
            'category'    => 'wp-toolkit-schema',
            'input_schema' => [
                'type'                 => 'object',
                'properties'           => [
                    'post_type' => $post_type,
                    'template'  => $template,
                    'enabled'   => ['type' => 'boolean', 'description' => 'Output this template on the front end.'],
                ],
                'required'             => ['post_type'],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object'],
            'execute_callback' => static function ($input) {
                $slug = ai_schema_check_post_type($input['post_type'] ?? '');
                if (is_wp_error($slug)) return $slug;
                if (!isset($input['template']) && !isset($input['enabled'])) {
                    return new \WP_Error('nothing_to_do', 'Pass a template, enabled, or both.');
                }

                $template = isset($input['template']) ? ai_schema_decode($input['template']) : null;
                if ($template !== null) {
                    $template = is_array($template) && !array_is_list($template) ? [$template] : $template;
                    $check    = ai_schema_validate($template, $slug);
                    if ($check['errors']) return new \WP_Error('invalid_template', implode(' ', $check['errors']));
                }

                $entry = seo_schema_save_template($slug, $template, isset($input['enabled']) ? (bool) $input['enabled'] : null);

                return ['success' => true, 'post_type' => $slug, 'enabled' => $entry['enabled'], 'template' => $entry['template']]
                    + ai_schema_report($slug, $entry['template']);
            },
            'permission_callback' => $admin,
            'meta' => $write_meta,
        ]);

        wp_register_ability('wp-toolkit/save-schema-node', [
            'label'       => 'Save Structured Data Node',
            'description' => 'Add one root node to a post type\'s template, or replace the node at index. Use get-schema-guide (post_type) to see the current nodes and their order. Returns the saved template with warnings and a preview.',
            'category'    => 'wp-toolkit-schema',
            'input_schema' => [
                'type'                 => 'object',
                'properties'           => [
                    'post_type' => $post_type,
                    'node'      => $node,
                    'index'     => ['type' => 'integer', 'description' => 'Replace the node at this 0-based index; omit to append.'],
                ],
                'required'             => ['post_type', 'node'],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object'],
            'execute_callback' => static function ($input) {
                $slug = ai_schema_check_post_type($input['post_type'] ?? '');
                if (is_wp_error($slug)) return $slug;

                $node  = ai_schema_decode($input['node'] ?? null);
                $check = ai_schema_validate([$node], $slug);
                if ($check['errors']) return new \WP_Error('invalid_node', implode(' ', $check['errors']));

                $nodes = array_values((array) (seo_schema_templates()[$slug]['template'] ?? []));
                if (isset($input['index'])) {
                    $index = (int) $input['index'];
                    if (!isset($nodes[$index])) return new \WP_Error('invalid_index', sprintf('No node at index %d; the template has %d.', $index, count($nodes)));
                    $nodes[$index] = $node;
                } else {
                    $nodes[] = $node;
                    $index   = count($nodes) - 1;
                }

                $entry = seo_schema_save_template($slug, $nodes);

                return ['success' => true, 'post_type' => $slug, 'index' => $index, 'enabled' => $entry['enabled'], 'template' => $entry['template']]
                    + ai_schema_report($slug, $entry['template']);
            },
            'permission_callback' => $admin,
            'meta' => $write_meta,
        ]);

        wp_register_ability('wp-toolkit/delete-schema-node', [
            'label'       => 'Delete Structured Data Node',
            'description' => 'Remove the root node at index from a post type\'s template. Returns the remaining template.',
            'category'    => 'wp-toolkit-schema',
            'input_schema' => [
                'type'                 => 'object',
                'properties'           => [
                    'post_type' => $post_type,
                    'index'     => ['type' => 'integer', 'description' => '0-based index of the node to remove.'],
                ],
                'required'             => ['post_type', 'index'],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object'],
            'execute_callback' => static function ($input) {
                $slug = ai_schema_check_post_type($input['post_type'] ?? '');
                if (is_wp_error($slug)) return $slug;

                $nodes = array_values((array) (seo_schema_templates()[$slug]['template'] ?? []));
                $index = (int) $input['index'];
                if (!isset($nodes[$index])) return new \WP_Error('invalid_index', sprintf('No node at index %d; the template has %d.', $index, count($nodes)));

                $removed = $nodes[$index]['@type'] ?? 'node';
                array_splice($nodes, $index, 1);
                $entry = seo_schema_save_template($slug, $nodes);

                return ['success' => true, 'post_type' => $slug, 'removed' => $removed, 'enabled' => $entry['enabled'], 'template' => $entry['template']];
            },
            'permission_callback' => $admin,
            'meta' => ['annotations' => ['destructive' => true, 'idempotent' => false], 'show_in_rest' => true, 'mcp' => ['public' => true]],
        ]);
    });
}

return [
    'id'    => 'ddwpt_ai_schema_abilities',
    'label' => 'AI Structured Data Abilities',
    'tab'   => 'experimental',

    'settings' => [
        [
            'id'          => 'enabled',
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Registers <code>get-schema-guide</code> and <code>preview-schema</code> so agents can learn the template format and dry-run templates against real posts. Only while Experiments is enabled.',
        ],
        [
            'id'          => 'allow_write',
            'type'        => 'checkbox',
            'label'       => 'Allow template changes',
            'description' => 'Also registers <code>set-schema-template</code>, <code>save-schema-node</code> and <code>delete-schema-node</code>. Agents write template JSON only; output still follows each template\'s own on/off switch.',
        ],
    ],

    'callback' => static function ($settings) {
        // Ability registration is handled at file-load time above.
    },
];
