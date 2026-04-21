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
│   └── tweaks/                 # Individual tweak files
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
    'tab'   => 'general',              // Settings tab (optional)

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

### Tabs

Tweaks are grouped into tabs by their `tab` key. Tabs are auto-created from whatever values are used. The preferred display order is:

`general` → `dashboard` → `admin-bar` → `admin-tables` → `sidebar` → `bricks`

Omit `tab` to place a tweak under **General**.

---

## Knowledge Base

Articles are Markdown files in `inc/knowledge/`. Drop any `.md` file in and it appears in the knowledge base automatically — no registration needed.

The knowledge base can be displayed as a **sidebar page** (its own admin menu entry) or as a **dashboard panel** injected above the widget area, replacing the default WordPress welcome panel. Mode is toggled via the Knowledge Base tweak setting.

---

## Releasing

The release script handles version bumping, zip packaging, git tagging, and GitHub release creation.

```bash
bash release.sh
```

Requires the `gh` CLI authenticated and `zip` installed. The script will prompt for the version bump type and a changelog entry.
