# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Modular WordPress admin plugin. Core concept: self-contained **tweaks** (PHP files in `inc/tweaks/`) that each define a settings form and a callback. The tweak loader wires everything together — no tweak touches the loader or UI code.

No frontend assets. All JS/CSS is admin-only.

**Slug:** `wp-toolkit` | **Namespace:** `DDWPTweaks` | **Admin URL:** `Tools → WP Toolkit`

---

## Release

```bash
bash release.sh 1.2.3 "Changelog entry describing the change"
```

Bumps `Version:` in `plugin.php`, packages a ZIP, commits/pushes, creates a GitHub release with the ZIP as an asset. A changelog entry argument is required. `Version:` header in `plugin.php` is the single source of truth — read at runtime with `get_file_data()`, not a constant.

Only these paths are packaged: `plugin.php`, `assets/`, `inc/`, `plugin-update-checker/`.

---

## Architecture

### Entry Point
`plugin.php` — bootstraps the plugin, wires the update checker, requires core classes, and calls `Plugin::get_instance()`.

### Core Classes (`inc/`)
| File | Class | Role |
|---|---|---|
| `class-plugin.php` | `Plugin` | Admin menu, settings page render, asset enqueueing, AJAX export/import |
| `class-tweak-loader.php` | `Tweak_Loader` | Loads + validates tweaks, auto-prefixes option IDs, fires callbacks on `init` |
| `class-knowledge-base.php` | `Knowledge_Base` | Markdown-based KB; sidebar menu or dashboard panel mode |

### Tweaks (`inc/tweaks/`)

Each file returns a PHP array. Two tweak types exist:

**Standard tweak** — has settings fields and a callback that registers hooks:
```php
<?php
return [
    'id'       => 'ddwpt_my_tweak',
    'label'    => 'My Tweak',
    'tab'      => 'general',
    'settings' => [
        ['id' => 'enabled', 'type' => 'checkbox', 'label' => 'Enable', 'description' => '...'],
    ],
    'callback' => function ( $settings ) {
        if ( ! $settings['enabled'] ) return;
        // hook registrations here
    },
];
```

**Render-only tweak** — no settings, no callback; renders informational UI by calling `$plugin->render_info_card()`. Used for info panels that don't save any options:
```php
<?php
return [
    'id'     => 'ddwpt_my_info',
    'tab'    => 'ai',
    'render' => function ( \DDWPTweaks\Plugin $plugin ) {
        $plugin->render_info_card(
            'Title',
            'Optional description with <a href="#">links</a> allowed.',
            [
                ['label' => 'Field',      'html'  => '<code>some value</code>'],
                ['label' => 'Wide field', 'full'  => true, 'html' => '<table>...</table>'],
            ],
            ['label' => 'Active', 'class' => 'is-active'] // optional badge
        );
    },
];
```

`render_info_card()` signature: `(string $title, string $desc = '', array $fields = [], array $badge = [])`. Fields accept `label`+`html` for a two-column row, or `full => true`+`html` for a full-width block. Badge classes: `is-active`, `is-inactive`, `is-missing`.

Render tweaks appear after the save button in their tab. Settings tweaks get a save button above them; render tweaks get none.

**ID prefixing rule:** The loader auto-prefixes unprefixed setting IDs with the tweak's own `id`. So `ddwpt_my_tweak` + field `id: 'enabled'` → stored as `ddwpt_my_tweak_enabled`. The callback receives both the short key and the full key.

**ALLOWED_TWEAKS whitelist:** New tweak files must be added to the `ALLOWED_TWEAKS` constant in `class-tweak-loader.php` or they are silently ignored.

### Field Types
| Type | Storage | Notes |
|---|---|---|
| `checkbox` | `'1'` or `''` | Field with `id: 'enabled'` renders as card header toggle |
| `text` | string | — |
| `select` | string | Requires `options` array |
| `multiselect` | JSON array | — |
| `checkboxes` | JSON array | — |
| `media` | URL string | WP media picker |
| `sortable` | JSON `{order, hidden}` | jQuery UI Sortable; drag-to-reorder |
| `wysiwyg` | HTML string | TinyMCE |

### Tabs
Tabs are ad-hoc — any tweak can declare any `tab` string and it will appear automatically. The `ai` tab is the exception: it is always present regardless of whether any tweak declares it.

Preferred order: `general → acf → dashboard → admin-bar → admin-tables → sidebar → animations → bricks → ai → settings`.

---

## Admin UI

- Settings page: `tools.php?page=ddwptweaks`
- Vertical tab layout; each tweak = collapsible card
- `_enabled` checkbox field on a tweak drives the card header toggle
- JS lives in `assets/js/settings.js` (jQuery + vanilla); CSS in `assets/css/settings.css`
- Icons: Lucide SVGs from `assets/icons/` inlined via `Plugin::inline_icon()`

### Export / Import
AJAX-based. Nonces: `ddwptweaks_export_nonce` / `ddwptweaks_import_nonce`. See `docs/settings-export-import.md`.

---

## ACF Integrations

Several tweaks register ACF options pages or field groups — guard with `class_exists('ACF')`.

**Filter timing:** ACF settings filters (e.g. `acf/settings/enable_acf_ai`) must fire before `acf/init`, which means they must be registered at file-load time (outside the `return []` array), not inside the callback. See `acf-abilities-api.php` for the pattern:

```php
// Runs at plugins_loaded when the file is required — before acf/init.
if ( get_option('ddwpt_acf_abilities_api_enabled') ) {
    add_filter('acf/settings/enable_acf_ai', '__return_true');
}

return [ 'id' => 'ddwpt_acf_abilities_api', ... ];
```

---

## AI Tab (`inc/tweaks/ai-mcp-info.php`)

Render-only tweak that shows MCP Adapter connection details, authentication steps, and registered WordPress Abilities API abilities. Only shows connection details when the `mcp-adapter` plugin is active.

**WordPress Abilities API** (`wp_get_abilities()`, requires WP 6.9+): returns an array of `WP_Ability` objects. Access data via getter methods — **not** properties:
- `$ability->get_name()`
- `$ability->get_label()`
- `$ability->get_description()`
- `$ability->get_category()`

### Registering abilities (`inc/tweaks/ai-site-instructions.php` pattern)

Abilities must be registered on `wp_abilities_api_init` (not `init`). The correct pattern is to hook at file-load time and check the saved option — the same approach used for ACF filters:

```php
// Runs at plugins_loaded when the file is required — before wp_abilities_api_init fires.
if (get_option('ddwpt_my_tweak_enabled')) {
    add_action('wp_abilities_api_init', static function () {
        wp_register_ability('wp-toolkit/my-ability', [
            'label'               => 'My Ability',
            'description'         => 'What this ability does.',
            'category'            => 'site',     // core categories: 'site', 'user'
            'input_schema'        => [            // required even for no-arg abilities — see docs/ai-abilities.md
                'type'                 => ['object', 'null'],
                'properties'           => [],
                'additionalProperties' => false,
            ],
            'output_schema'       => ['type' => 'string', 'description' => '...'],
            'execute_callback'    => static function () { return '...'; },
            'permission_callback' => static function () { return current_user_can('read'); },
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true], // required or mcp-adapter silently excludes it from tool discovery
            ],
        ]);
    });
}
```

Ability names must match `/^[a-z0-9-]+\/[a-z0-9-]+$/`. Categories must be registered on `wp_abilities_api_categories_init` before use; core provides `site` and `user`. The tweak `callback` can be a no-op when registration is handled at file-load time.

**`meta.mcp.public` and `input_schema` are both easy to forget and fail silently** (the ability just never shows up as an MCP tool, or errors out on empty-arg calls). See `docs/ai-abilities.md` for why, plus the full pattern used by the content abilities in `ai-content-abilities.php`.

---

## Bricks Builder Integrations

Tweaks under the `bricks` tab. Custom Bricks elements live in `inc/elements/` as classes; companion JS in `elements/js/`, vendored third-party runtimes in `elements/js/vendor/`. All Bricks code must guard with `defined('BRICKS_VERSION')`.

Two builder behaviours are undocumented upstream and fail silently — see `docs/bricks-elements.md`:

- **`public $scripts = ['myInitFn']`** is the only way element JS re-runs after a builder re-render. A `<script>` emitted from `render()` works on the frontend and is inert in the canvas.
- **A control with a `css` key does not trigger a re-render** (stylesheet is patched in place), so `$scripts` won't fire for it. Controls that change a JS runtime option must therefore *not* declare `css`. `'rerender' => true` forces one either way.

Third-party runtimes are vendored or CDN-loaded per element, deliberately: vendor when nothing users author depends on the version (Embla), stay on a CDN when the runtime must track upstream export formats (Rive, Unicorn Studio, Mapbox). Always pin an exact version.

---

## Dependencies

| Dependency | Required | Notes |
|---|---|---|
| WordPress | Yes | 6.9+ recommended for AI tab abilities list |
| ACF PRO | No | Multiple tweaks; guard with `class_exists('ACF')` |
| Bricks Builder | No | 6+ tweaks; guard with `defined('BRICKS_VERSION')` |
| Redirection | No | AI redirect-management abilities; guard with `defined('REDIRECTION_VERSION')` |

`plugin-update-checker/` is vendored (YahnisElsts v5.6+). Do not remove.

---

## Knowledge Base (`inc/knowledge/`)

Markdown articles auto-discovered from the directory. `manifest.php` provides index metadata. Two display modes (toggled via the `knowledge-base` tweak): **Sidebar** (default) or **Dashboard** (replaces WP welcome panel). See `docs/knowledge-base.md`.

---

## Docs (`docs/`)

| File | Covers |
|---|---|
| `tweak-system.md` | Full tweak definition reference, all field types |
| `bricks-elements.md` | Custom element architecture, builder re-render rules, vendored vs CDN policy |
| `knowledge-base.md` | KB article authoring, display modes |
| `settings-export-import.md` | Export/import security and portability |
| `ai-abilities.md` | Registered AI abilities, MCP visibility gotchas, whitelist mechanism |

Keep docs current when features change materially.
