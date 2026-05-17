# Tweak System

Tweaks are self-contained PHP files in `inc/tweaks/`. Each file returns a definition array; the loader validates, auto-prefixes settings IDs, and wires the callback to `init`.

## Adding a tweak

1. Create `inc/tweaks/my-tweak.php` returning the definition array.
2. Add `'my-tweak'` to `ALLOWED_TWEAKS` in `class-tweak-loader.php`.

Files not in `ALLOWED_TWEAKS` are silently ignored at load time.

## Definition array

```php
return [
    'id'       => 'ddwpt_my_tweak',   // unique, used as option key prefix
    'label'    => 'My Tweak',         // shown in settings UI card header
    'tab'      => 'general',          // optional; omit to place under General

    'settings' => [
        [
            'id'          => 'enabled',   // becomes ddwpt_my_tweak_enabled
            'type'        => 'checkbox',
            'label'       => 'Enable tweak',
            'description' => 'Shown in the card header.',
        ],
        // additional fields...
    ],

    'callback' => function ($settings) {
        // Called on init with resolved option values.
        // Both prefixed and short keys are available:
        //   $settings['ddwpt_my_tweak_enabled'] === $settings['enabled']
    },
];
```

The `_enabled` checkbox is special: the loader identifies it by type `checkbox` + ID suffix `_enabled` and promotes it to the card header toggle. All other fields appear in the card body.

## Field types

| Type | Storage | Notes |
|---|---|---|
| `checkbox` | `1` or `''` | If ID ends in `_enabled`, rendered as header toggle |
| `text` | plain string | |
| `select` | string | Requires `options` array (`value => label`) |
| `multiselect` | JSON array | Requires `options` array or callable |
| `checkboxes` | JSON array | Requires `options` array; rendered as a grid |
| `media` | URL string | Uses WP media picker |
| `sortable` | JSON `{order, hidden}` | Requires `items` array or callable |
| `wysiwyg` | HTML string | TinyMCE; supports `rows`, `media_buttons`, `teeny` keys |

All fields accept `default` (used when the option has never been saved).

Callable `options`/`items` are resolved at render time, so they can query the database.

## Tabs

Tabs are created automatically from the `tab` values used across tweaks. Preferred display order (defined in `class-plugin.php::get_all_tabs()`):

`general` → `acf` → `dashboard` → `admin-bar` → `admin-tables` → `sidebar` → `animations` → `bricks`

Tabs outside this list appear after in definition order.

## Settings ID prefixing

The loader auto-prefixes setting IDs with the tweak `id` if they don't already start with it:

- `'id' => 'enabled'` → stored as `ddwpt_my_tweak_enabled`
- `'id' => 'ddwpt_my_tweak_enabled'` → unchanged

This means callbacks can use short keys (`$settings['enabled']`) and WordPress option functions need the full key (`get_option('ddwpt_my_tweak_enabled')`).

## Callback timing

All callbacks are hooked to `init` at priority 10. Tweaks that register CPTs, ACF field groups, or other early hooks must do so directly inside the callback — they run during `init`, which is the correct registration point for most WordPress APIs.
