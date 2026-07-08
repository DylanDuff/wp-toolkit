# AI Abilities

WP Toolkit registers WordPress Abilities API abilities (`inc/tweaks/ai-*.php`) so MCP-connected AI clients can read and manage site content. This covers what's registered, how the guards work, and quirks in the mcp-adapter integration worth knowing before adding more.

## Making an ability visible to MCP

Registering via `wp_register_ability()` is not enough on its own. `mcp-adapter`'s `DefaultServerFactory::discover_abilities_by_type()` only exposes abilities where:

```php
'meta' => [
    'mcp' => ['public' => true],
],
```

Without this, the ability exists in `wp_get_abilities()` and shows up in the AI tab's "Registered Abilities" table, but is silently excluded from the MCP tool list. ACF's abilities get this key "for free" because `ACF\AI\Abilities\AbstractAbilityGroup::register_ability()` injects the default — it's not visible in ACF's own registration calls, which made this non-obvious the first time around. Every ability registered by this plugin must set it explicitly.

## input_schema is required, even for no-arg abilities

`WP_Ability::validate_input()` only allows a `null`/missing input when the ability declares **no** `input_schema` at all. The moment an ability declares one, MCP's `AbilityArgumentNormalizer` stops converting `{}` (which PHP decodes to `[]`) to `null`, and an empty array must validate against the schema directly. So:

- Abilities with no required params use `'type' => ['object', 'null']` with `'properties' => []` (or a full properties list with nothing required) and `'additionalProperties' => false`, matching `acf/field-groups`.
- Abilities with required params (`create-post`, `get-or-create-term`, etc.) just use `'type' => 'object'` — `{}` will correctly fail their `required` check.

## mcp-adapter wraps `type: [object, null]` schemas

`SchemaTransformer::transform_to_object_schema()` does a strict `'object' === $schema['type']` check. A union type like `['object', 'null']` fails that check, so the adapter treats the schema as "flattened" and wraps it: the MCP tool's real `inputSchema` becomes `{ type: object, properties: { input: <original schema> }, required: [input] }`, and calls must be made as `{"input": {...}}` instead of the flat shape. This happens to every ability that uses the nullable-object pattern above (including ACF's own), not just ours. It's not a bug — spec-compliant MCP clients read the tool's schema from `tools/list` before calling — but it's worth knowing before assuming a tool's call shape matches its `input_schema` array literally.

## Registered abilities

| Ability | Tweak | Guard | Capability |
|---|---|---|---|
| `wp-toolkit/get-site-instructions` | `ai-site-instructions` | — | `read` |
| `wp-toolkit/get-post` | `ai-content-abilities` | enabled | `edit_posts` |
| `wp-toolkit/list-posts` | `ai-content-abilities` | enabled | `edit_posts` |
| `wp-toolkit/get-option-field` | `ai-content-abilities` | ACF active | `edit_posts` |
| `wp-toolkit/create-post` | `ai-content-abilities` | allow_write | `publish_posts` |
| `wp-toolkit/update-post` | `ai-content-abilities` | allow_write | `publish_posts` |
| `wp-toolkit/get-or-create-term` | `ai-content-abilities` | allow_write | `publish_posts` |
| `wp-toolkit/upload-media` | `ai-content-abilities` | allow_write | `publish_posts` + `upload_files` |
| `wp-toolkit/update-option-field` | `ai-content-abilities` | allow_write + ACF active | `manage_options` |
| `wp-toolkit/set-seo-meta` | `ai-content-abilities` | allow_write + SEOPress active | `publish_posts` |

There is deliberately no delete ability.

## Post type / taxonomy whitelist

`AI_CONTENT_ABILITIES_POST_TYPES` in `ai-content-abilities.php` is the hard ceiling: `post`, `page`, plus the CPTs WP Toolkit's own ACF tweaks register (`faq`, `service-area`, `team-member`, `testimonial`, `location`, `project`). CPTs from unrelated plugins are never exposed, regardless of admin settings.

The "Expose post types" checkbox setting (default: `post`, `page` only) selects a subset of that ceiling — and is further intersected with `post_type_exists()` at registration time, so disabling a CPT's own tweak (e.g. `acf-faq`) removes it from exposure even if it's still checked in the AI tab.

Allowed taxonomies are derived from `get_object_taxonomies()` on the allowed post types (not independently configurable) — there's no separate taxonomy whitelist setting.

## Content write conventions

`create-post` / `update-post` accept a generic `meta` object (field name → value) and `terms` object (taxonomy → array of existing term slugs):

- **meta**: if ACF is active and `acf_get_field($key)` resolves, writes go through `update_field()`; otherwise falls back to `update_post_meta()` with `sanitize_text_field()` on scalar strings. Arrays pass through untouched (ACF repeater/gallery support).
- **terms**: only assigns *existing* terms — call `get-or-create-term` first. `update-post` takes an `append_terms` flag (default `true`) to add rather than replace, matching the append-don't-overwrite convention in the site instructions doc (`ai-site-instructions.php`).

## Registering a new ability here

Follow the pattern in `ai-content-abilities.php`: hook on `wp_abilities_api_init` at file-load time (gated by `get_option('ddwpt_<tweak>_enabled')` so the hook itself is a no-op when disabled), set `meta.mcp.public = true`, and declare `input_schema`/`output_schema` even for simple cases — omitting `output_schema` is safe (skips validation) but omitting `input_schema` is not, per above.
