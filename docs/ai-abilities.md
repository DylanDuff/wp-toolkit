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
| `wp-toolkit/get-acf-architecture-guide` | `ai-field-architecture` | enabled | `read` |
| `wp-toolkit/list-post-types` | `ai-content-abilities` | enabled | `edit_posts` |
| `wp-toolkit/list-taxonomies` | `ai-content-abilities` | enabled | `edit_posts` |
| `wp-toolkit/get-post` | `ai-content-abilities` | enabled | `edit_posts` |
| `wp-toolkit/list-posts` | `ai-content-abilities` | enabled | `edit_posts` |
| `wp-toolkit/list-terms` | `ai-content-abilities` | enabled | `edit_posts` |
| `wp-toolkit/get-option-field` | `ai-content-abilities` | enabled | `edit_posts` |
| `wp-toolkit/create-post` | `ai-content-abilities` | allow_write | `publish_posts` |
| `wp-toolkit/update-post` | `ai-content-abilities` | allow_write | `publish_posts` |
| `wp-toolkit/get-or-create-term` | `ai-content-abilities` | allow_write | `publish_posts` |
| `wp-toolkit/upload-media` | `ai-content-abilities` | allow_write | `publish_posts` + `upload_files` |
| `wp-toolkit/update-option-field` | `ai-content-abilities` | allow_write | `publish_posts` (post) / `manage_options` (options page) |
| `wp-toolkit/set-seo-meta` | `ai-content-abilities` | allow_write + SEOPress active | `publish_posts` |
| `wp-toolkit/list-redirects` | `ai-redirection-abilities` | enabled + Redirection active | `redirection_cap_redirect_manage`* |
| `wp-toolkit/get-redirect` | `ai-redirection-abilities` | enabled + Redirection active | `redirection_cap_redirect_manage`* |
| `wp-toolkit/list-redirect-groups` | `ai-redirection-abilities` | enabled + Redirection active | `redirection_cap_redirect_manage`* |
| `wp-toolkit/create-redirect` | `ai-redirection-abilities` | allow_write + Redirection active | `redirection_cap_redirect_add`* |
| `wp-toolkit/update-redirect` | `ai-redirection-abilities` | allow_write + Redirection active | `redirection_cap_redirect_add`* |
| `wp-toolkit/create-redirect-group` | `ai-redirection-abilities` | allow_write + Redirection active | `redirection_cap_group_add`* |

\* Resolved via `Redirection_Capabilities::has_access()`, which defaults to `manage_options` unless the site filters `redirection_capability_check`. Falls back to a plain `manage_options` check if the Redirection plugin's capability class isn't loaded.

There is deliberately no delete ability for content or redirects — disable a redirect via `update-redirect`'s `enabled` flag instead.

## `get-site-instructions` vs. `get-acf-architecture-guide`

Two separate abilities, both editable per-site text blobs (`ai-site-instructions.php` / `ai-field-architecture.php`), split by how often they're relevant rather than merged into one document:

- **`get-site-instructions`** is meant to be read once per session/task — stack, content model, what not to touch, global conventions. It intentionally no longer hardcodes a specific site's collections (see `## Discovering Collections` in the default text) — that's runtime state, discoverable via `list-post-types`/`list-taxonomies`/`get-post`, not something to bake into a static doc that ships identically to every install.
- **`get-acf-architecture-guide`** is narrower and only relevant when actually building a new ACF field group's Tab → Group → Fields structure for a CPT's visually laid-out sections — a different, more detailed convention than the flat per-entry field naming covered in `get-site-instructions`' "Adding New Collections" section. Keeping it a separate ability means the common case (an agent reading, creating, or updating content) doesn't pull in a page's worth of field-architecture rules it doesn't need for that task.

Both follow the exact same pattern: a `<<<'INSTRUCTIONS'` heredoc default, an `enabled` checkbox, and a `resettable` textarea setting (see `render_field()`'s `textarea` case in `class-plugin.php`) so a site can customize the text and reset back to the plugin's shipped default without losing the option entirely.

## Site context vs. the exposure whitelist

`list-post-types` and `list-taxonomies` always report on **every** post type / taxonomy registered on the site (`show_ui => true`, i.e. anything an admin would see in wp-admin) — not just the ones exposed to AI agents. Each item carries `read_access`/`write_access` (post types) or `exposed`/`write_access` (taxonomies) flags reflecting the current whitelist, so an agent always has full site context even for content it can't touch, and can explain *why* a call to `get-post` or `create-post` failed for a given type rather than just seeing an opaque error.

This is deliberate: visibility (what exists) and access (what can be read/written) are separate concerns. Don't gate `list-post-types`/`list-taxonomies` behind the whitelist — they must stay independent of it or they lose their purpose.

## Post type / taxonomy whitelist

There is no hardcoded ceiling — any post type registered on the site with `show_ui => true` can be selected in the "Expose post types" checkbox setting (`ai_content_site_post_types()`), the same pool `list-post-types` reports on. Nothing is exposed until explicitly checked; the checkbox list is itself intersected with the current site's post types at registration time (`ai_content_allowed_post_types()`), so a deactivated plugin's CPT silently drops out of exposure even if it's still checked in the option value.

Allowed taxonomies are derived from `get_object_taxonomies()` on the allowed (exposed) post types — not independently configurable via a setting. `list-taxonomies`, however, reports on all `show_ui => true` taxonomies regardless of exposure, per above.

If no post types are selected, `get-post`/`list-posts`/`create-post`/etc. still register (so `list-post-types` and `get-option-field` keep working), with an empty `post_type`/`taxonomy` schema `enum`. WP core's `rest_validate_value_from_schema()` skips the enum check entirely when `enum` is empty (`!empty($args['enum'])` guards it) — it does **not** reject every value — so the real gate is the `in_array($post_type, $allowed_post_types, true)` check every execute_callback does at runtime, which returns a clear "not exposed" `WP_Error`. The schema `enum` is a discoverability hint for the calling agent when non-empty; it is not the enforcement boundary.

## Content write conventions

`create-post` / `update-post` accept a generic `meta` object (field name → value) and `terms` object (taxonomy → array of existing term slugs):

- **meta**: if ACF is active and `acf_get_field($key)` resolves, writes go through `update_field()`; otherwise falls back to `update_post_meta()` with `sanitize_text_field()` on scalar strings. Arrays pass through untouched (ACF repeater/gallery support).
- **terms**: only assigns *existing* terms — call `get-or-create-term` first. `update-post` takes an `append_terms` flag (default `true`) to add rather than replace, matching the append-don't-overwrite convention in the site instructions doc (`ai-site-instructions.php`).

## `get-option-field` / `update-option-field` are dual-mode

Both abilities target a post's meta when the input includes `id`, or the ACF site options page when `id` is omitted — they're not ACF-only. Reads/writes go through `ai_content_get_meta_value()` / `ai_content_save_meta()`, the same ACF-field-if-registered-else-`get_post_meta()`/`update_post_meta()` fallback used by `create-post`/`update-post`'s `meta` object, so a site without ACF at all can still read and write arbitrary post meta through these two abilities.

The options-page branch (`id` omitted) is still ACF-only — there's no generic "site option" concept safe to expose the way post meta is, since the WP options table holds things like API keys, cron state, and serialized internal data that shouldn't be blindly readable/writable by an agent. That branch returns a `ddwpt_acf_required` error if ACF isn't active, telling the caller to pass `id` instead. Both abilities register unconditionally (not gated by `function_exists('get_field')`) precisely because the post-meta branch works without ACF.

Permission differs by branch: writing to a post uses the standard `publish_posts` write capability plus the `allowed_post_types` exposure check (same as `update-post`); writing to the options page requires `manage_options`, since it isn't scoped to the post-type whitelist at all. `update-option-field`'s `permission_callback` receives `$input` (abilities API passes it whenever `input_schema` is non-empty) specifically to branch on `id` for this.

## Redirection abilities (`ai-redirection-abilities.php`)

Guarded by `defined('REDIRECTION_VERSION')` rather than `class_exists()` — Redirection's `Red_Item`/`Red_Group` classes are required unconditionally at the top of its main plugin file, so they're always loaded whenever the plugin is active, regardless of admin context.

- **Module gating:** Redirection groups belong to a "module" — WordPress (id `1`, matched in PHP on every request), Apache, or Nginx (both written out to server config files instead). `list-redirect-groups` and the default group picked by `create-redirect` only ever consider WordPress-module groups (`ai_redirection_wp_groups()`), since a redirect filed under an Apache/Nginx group would silently never fire through this plugin.
- **`Red_Item::update()` replaces the full sanitized field set, not a diff.** `Red_Item_Sanitize::get()` reads `action_type`/`match_type`/etc. directly off the details array with no per-field fallback to the object's current value — omit `action_type` and the sanitizer's `Red_Action::create('', ...)` call returns `null`, which surfaces as an opaque "Invalid redirect action" `WP_Error`. `update-redirect` works around this by loading the current `to_json()` state first and merging user-supplied fields over it before calling `->update()`, so a partial update (e.g. just flipping `title`) doesn't require the caller to resupply everything.
- **Status isn't part of the sanitized field set at all.** `->update()` never touches `status` — enabling/disabling goes through the dedicated `->enable()`/`->disable()` methods, called separately after `->update()` succeeds when the `enabled` input is present.
- **Capability check:** uses `Redirection_Capabilities::has_access()` (falls back to `manage_options` if the class isn't loaded) rather than a WordPress capability string directly, so a site that has filtered `redirection_capability_check` to grant editors redirect access is respected instead of silently requiring `manage_options`.
- **Scope is deliberately narrower than the full Redirection API:** `action_type` is restricted to `url` (redirect to another URL) and `error` (return an HTTP error status) — the two common cases. Match types other than plain `url` matching (cookie, header, IP, role, etc.) and bulk/global actions aren't exposed; use the Redirection admin UI for those.

## Registering a new ability here

Follow the pattern in `ai-content-abilities.php`: hook on `wp_abilities_api_init` at file-load time (gated by `get_option('ddwpt_<tweak>_enabled')` so the hook itself is a no-op when disabled), set `meta.mcp.public = true`, and declare `input_schema`/`output_schema` even for simple cases — omitting `output_schema` is safe (skips validation) but omitting `input_schema` is not, per above.
