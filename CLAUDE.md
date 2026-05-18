# WP Toolkit — Project Reference

## Overview

Modular WordPress admin plugin. Core concept: self-contained **tweaks** (PHP files in `inc/tweaks/`) that each define a settings form and a callback. The tweak loader wires everything together — no tweak touches the loader or UI code.

No frontend assets. All JS/CSS is admin-only.

**Slug:** `wp-toolkit` | **Namespace:** `DDWPTweaks` | **Admin URL:** `Tools → WP Toolkit`

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
Each file returns a PHP array. The loader `require`s it and expects an array back — no class, no function, just a `return [...]`.

**Minimal tweak structure:**
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

**ID prefixing rule:** The loader auto-prefixes unprefixed setting IDs with the tweak's own `id`. So tweak `ddwpt_my_tweak` + field `id: 'enabled'` → stored in WP options as `ddwpt_my_tweak_enabled`. The callback receives both the short key (`$settings['enabled']`) and the full key.

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
Tabs are ad-hoc — any tweak can declare any `tab` string. Preferred order when adding new tabs: `general → acf → dashboard → admin-bar → admin-tables → sidebar → animations → bricks`.

---

## Admin UI

- Settings page: `tools.php?page=ddwptweaks`
- Vertical tab layout; each tweak = collapsible card
- `_enabled` checkbox field on a tweak drives the card header toggle
- JS lives in `assets/js/settings.js` (jQuery); CSS in `assets/css/settings.css`
- Icons: Lucide SVGs embedded as data URIs in PHP (see `Plugin` class)

### Export / Import
AJAX-based. Nonces: `ddwptweaks_export_nonce` / `ddwptweaks_import_nonce`. Sanitization runs through a shared JSON sanitizer. See `docs/settings-export-import.md`.

---

## Knowledge Base (`inc/knowledge/`)

Markdown articles auto-discovered from the directory. `manifest.php` provides index metadata. Two display modes (toggled via the `knowledge-base` tweak):
- **Sidebar** (default) — separate admin menu entry
- **Dashboard** — replaces the WP welcome panel

Articles are client-facing (plain language). See `docs/knowledge-base.md`.

---

## Bricks Builder Integrations

Six tweaks under the `bricks` tab. Custom Bricks elements live in `inc/elements/` as classes. The `elements/js/` subdirectory holds companion JS loaded by those elements.

Optional dependency — all Bricks code must guard with `defined('BRICKS_VERSION')` or equivalent.

---

## ACF Integrations

Several tweaks register ACF options pages or field groups. These are optional — guard with `class_exists('ACF')`.

---

## Dependencies

| Dependency | Required | Notes |
|---|---|---|
| WordPress | Yes | No minimum version pinned |
| ACF | No | Multiple tweaks; guard with `class_exists('ACF')` |
| Bricks Builder | No | 6+ tweaks; guard with `defined('BRICKS_VERSION')` |

`plugin-update-checker/` is vendored (YahnisElsts v5.6+). Do not remove.

---

## Release Process

`release.sh` handles everything: version bump in `plugin.php`, ZIP packaging (only `plugin.php`, `assets/`, `inc/`, `plugin-update-checker/`), git commit/tag/push, GitHub release with ZIP asset.

`Version:` header in `plugin.php` is the single source of truth — read at runtime with `get_file_data()`, not a constant.

---

## Docs (`docs/`)

| File | Covers |
|---|---|
| `tweak-system.md` | Full tweak definition reference, all field types |
| `knowledge-base.md` | KB article authoring, display modes |
| `settings-export-import.md` | Export/import security and portability |

Keep docs current when features change. New subsystems warrant a new doc file.
