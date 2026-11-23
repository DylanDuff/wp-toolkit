# WP Toolkit

A modular WordPress plugin for managing admin tweaks and client-facing tools. Each tweak is a self-contained PHP file — drop one in, and it appears in the settings UI automatically.

---

## Structure

```
wp-toolkit/
├── plugin.php                  # Plugin bootstrap
├── inc/
│   ├── class-plugin.php        # Settings UI, menu registration
│   ├── class-tweak-loader.php  # Loads and validates tweak definitions
│   ├── class-knowledge-base.php
│   ├── knowledge/              # Markdown articles for the knowledge base
│   ├── tweaks/                 # Individual tweak files
│   └── zag-filters/            # Zag Filters Bricks module (PHP + built bundle)
├── src/zag-filters/            # Zag Filters frontend source (built with pnpm)
├── scripts/                    # Build scripts
├── tests/                      # Schema and Zag Filters test suites
└── release.sh                  # Release automation script
```

---

## Adding a Tweak

Create a new PHP file in `inc/tweaks/` that returns a definition array, then add its slug to `ALLOWED_TWEAKS` in `class-tweak-loader.php`.

```php
<?php

namespace DDWPTweaks\Tweaks;

return [
    'id'    => 'ddwpt_my_tweak',       // Unique ID, used as option prefix
    'label' => 'My Tweak',             // Shown in settings UI
    'tab'   => 'site',                 // Settings area (optional)

    'settings' => [
        [
            'id'          => 'enabled', // Becomes ddwpt_my_tweak_enabled
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'What this tweak does.',
        ],
    ],

    'callback' => function ($settings) {
        if (empty($settings['enabled'])) {
            return;
        }

        // Your tweak logic here.
        // $settings keys are available both prefixed and unprefixed:
        // $settings['ddwpt_my_tweak_enabled'] === $settings['enabled']
    },
];
```

The loader auto-prefixes all setting IDs with the tweak ID, registers them with WordPress, and calls `callback` on `init` with the resolved values.

### Available field types

| Type | Notes |
|---|---|
| `checkbox` | Stored as `1` or `''` |
| `text` | Plain text input |
| `select` | Requires `options` array (`value => label`) |
| `multiselect` | Requires `options` array or callable; stored as JSON |
| `media` | URL string from the WP media picker |
| `sortable` | Drag-to-reorder list; stored as JSON `{order, hidden}` |
| `wysiwyg` | TinyMCE editor |

### Areas

The settings page groups tweaks into areas (`tab`) and sections (`group`): Site, Admin, Content model, Builder, Integrations, SEO, AI & MCP and Settings, with an Overview hub and search (`/` or Cmd/Ctrl+K). Omit `tab` to place a tweak under **Site**. See [`docs/settings-layout.md`](docs/settings-layout.md).

---

## Knowledge Base

Articles are Markdown files in `inc/knowledge/`. Drop any `.md` file in and it appears in the knowledge base automatically — no registration needed.

The knowledge base can be displayed as a **sidebar page** (its own admin menu entry) or as a **dashboard panel** injected above the widget area, replacing the default WordPress welcome panel. Mode is toggled via the Knowledge Base tweak setting.

---

## Developer Reference

Detailed documentation is in [`docs/`](docs/):

- [`tweak-system.md`](docs/tweak-system.md) — tweak definition format, all field types, areas and sections, settings ID prefixing, callback timing
- [`settings-layout.md`](docs/settings-layout.md): settings page layout (Overview, areas, search, nested pages), tweak layout keys, ability rows, option key migrations
- [`bricks-elements.md`](docs/bricks-elements.md) — custom element architecture, builder re-render rules, vendored vs CDN policy, Embla Slider reference
- [`knowledge-base.md`](docs/knowledge-base.md) — adding articles, display modes, manifest structure
- [`settings-export-import.md`](docs/settings-export-import.md) — export/import behaviour, security model, portability notes
- [`ai-abilities.md`](docs/ai-abilities.md) — registered AI abilities, MCP visibility gotchas, whitelist mechanism
- [`seo-schema.md`](docs/seo-schema.md) — Structured Data (JSON-LD) templates: sources, transforms, directives, editor, SEO plugin conflicts
- [`fluent-forms-mirror.md`](docs/fluent-forms-mirror.md): Fluent Forms Mirror, `ff_form` CPT for Bricks query loops and popup context, sync triggers
- [`bricks-builder-tweaks.md`](docs/bricks-builder-tweaks.md): `Bricks_Tags` helper for dynamic tags, extra dynamic tags (reading time, post count, page type), component property groups in Gutenberg, extra HTML tags
- [`linked-author.md`](docs/linked-author.md): team member as post author, `{linked_author:field}` tag
- [`acf-admin-screens.md`](docs/acf-admin-screens.md): ACF tab error highlighting, tidier term edit screens
- [`zag-filters/`](docs/zag-filters/README.md): Zag Filters, the Zag.js query filter elements for Bricks folded in from the standalone plugin (drop-in replacement, filter impersonation, frontend, build). Per element: [select](docs/zag-filters/select.md), [sorting](docs/zag-filters/sorting.md), [smart search](docs/zag-filters/smart-search.md), [active filters](docs/zag-filters/active-filters.md), [group](docs/zag-filters/group.md)
- [`acf-shopify-products.md`](docs/acf-shopify-products.md): Shopify Product(s) ACF field types, Bricks loop tags, replacing the standalone plugin

---

## Releasing

The release script handles version bumping, zip packaging, git tagging, and GitHub release creation.

```bash
bash release.sh
```

Requires the `gh` CLI authenticated and `zip` installed. The script will prompt for the version bump type and a changelog entry. With `pnpm install` done, it also rebuilds the Zag Filters bundle and refuses to release a stale one.

## Building

Only Zag Filters has a build step; its bundle is committed so a plain checkout works.

```bash
pnpm install
pnpm build   # src/zag-filters/ -> inc/zag-filters/assets/zag-filters.js
pnpm test    # Zag Filters and schema test suites
```
