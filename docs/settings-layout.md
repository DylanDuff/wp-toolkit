# Settings Layout

Since 1.6.0 the settings page (`Tools → WP Toolkit`) groups tweaks by intent into **areas**, each split into **sections**. Rendering lives in `Plugin::render_settings_page()` (`inc/class-plugin.php`), the area and section definitions in `Settings_Layout` (`inc/class-settings-layout.php`) and the client-side navigation in `assets/js/settings.js`.

Option keys never depend on where a tweak sits, so moving a tweak between areas or sections needs no migration. When a key itself must change, see [Migrations](#migrations).

## Page structure

| Part | What it is |
|---|---|
| Overview | Hero card (version, enabled count, white label colours), one tile per area with an "X of Y on" meter, and a "Running now" card listing enabled tweaks as chips that jump to their card. |
| Sidebar | Search button, Overview, one link per area, then Settings below a divider. No counts. Save button at the bottom. |
| Area page | Breadcrumb, title, one-line lede, a segmented switcher when the area has more than one section, then the selected section's cards. |
| Nested page | A pane in an area for UI too big for a card. Listed after a divider in the switcher (with `›`), or linked from a card. The breadcrumb gains the page name. |
| Search palette | Opened with the sidebar button, `/` or Cmd/Ctrl+K. Searches tweak labels, descriptions, area, section, badges and plugin names, plus nested pages. |

Everything is still one `options.php` form. Switching areas is client-side, and Save submits every field on the page, including hidden ones.

### Routing

The hash holds the view: `#area`, `#area/section`, `#area/page`, or `#ddwpt-card-<tweak id>` to jump to a card (switching area and section, revealing it if hidden behind a missing plugin, and flashing it). The view survives a save through `_wp_http_referer`. Hashes from earlier releases (`#general`, `#acf`, `#wp-admin`, `#bricks`) map to the nearest area.

## Areas and sections

| Area (`tab`) | Sections (`group`) |
|---|---|
| `site` | `content`, `security`, `media`, `notices` |
| `admin` | `dashboard`, `admin-bar`, `menu`, `list-tables`, `footer`, `look-and-feel` |
| `content` (Content model) | `post-type-presets`, `site-options`, `editing-screens`, `tools` |
| `builder` | `editor`, `data`, `elements`, `gutenberg` |
| `integrations` | `fluent-forms`, `wp-social-ninja`, `google-tag-manager`, `shopify` |
| `seo` | `schema`, `urls` |
| `ai` (AI & MCP) | none: one page led by the AI setup card |
| `settings` | none: White Label, Experiments, Import / Export |

`settings` is a utility area: it sits apart at the bottom of the sidebar, has no count and no Overview tile. An unknown `tab` or `group` still renders, labelled from its id, so a new area or section only needs adding to `Settings_Layout::AREAS` to get its label, icon, lede and position.

## Tweak keys

All optional. See `docs/tweak-system.md` for the full definition array.

| Key | Effect |
|---|---|
| `tab` | Area id. Defaults to `site`. |
| `group` | Section id within the area. |
| `description` | One short line under the card title. Falls back to the `_enabled` checkbox's description. Keep it under about 75 characters and move details into badges. |
| `tags` | Badges such as `Frontend`, `Post type`, `Admins only`, `CDN`. |
| `requires` | Plugin names (`ACF`, `Bricks`, `Redirection`, `Fluent Forms`, `WP Social Ninja`, `GTM4WP`, `MCP Adapter`, `ACSS`). Shown as outline badges, or amber "Needs X" when `Settings_Layout::requirement_met()` reports the plugin missing. A tweak with a missing plugin is hidden behind a "Show anyway" note on its area; a name the method cannot detect (it returns `null`) never hides anything. |
| `labs` | Experimental tweak. Shown inline with a Labs badge only while Experiments is on (replaces the old Experimental tab). |
| `abilities` | Ability rows for the card. See below. |
| `pages` | Nested pages owned by a render tweak: `[['id' => ..., 'label' => ..., 'render' => callable]]`. The callable receives the `Plugin` instance. |
| `position` | `'first'` puts a render-only panel above the settings cards in its section (the AI setup card). |

A setting can also take a `page` key, `['id', 'label', 'link', 'summary']`, which renders that field on its own nested page and leaves a "link →" row on the card. `acf-site-options.php` uses it for the field names table.

### Hidden cards still save

A Labs card with Experiments off, or a card whose plugin is missing, is hidden with the `hidden` attribute rather than left out of the page. WordPress clears any registered option that is missing from the POST, so leaving a card out would wipe its values on save. (Before 1.6.0 this happened to Experimental tweaks while Experiments was off.)

### Ability rows

Ability tweaks list the abilities they register as chips instead of describing them in prose:

```php
'abilities' => [
    ['kind' => 'read', 'names' => ['wp-toolkit/list-redirects', ...]],
    ['kind' => 'write', 'setting' => 'allow_write', 'names' => ['wp-toolkit/create-redirect', ...]],
    ['title' => 'ACF built-in', 'setting' => 'acf_native', 'independent' => true, 'names' => callable],
],
```

- Read rows ride on the main switch. A row with `setting` gets a small opt-in switch bound to that existing checkbox setting (short id, prefixed like any setting), labelled with the setting's `label`; the setting is not rendered again as a field row.
- Write rows without a `delete` ability get a "No delete" badge.
- `independent` rows stay at full strength when the card's main switch is off, for opt-ins that work on their own.
- `names` may be a callable, for abilities only known at runtime.
- These lists are for display. Registration still happens at file load in the tweak; keep both in step when adding an ability.

`Plugin::get_ability_summary()` totals the read and write rows of every tweak for the AI setup card (live count, read, write, tweaks on).

## Migrations

`Migrations` (`inc/class-migrations.php`) runs first on `plugins_loaded`, before `Tweak_Loader::load_all()`, because several tweaks read options when their file is required (ACF filters, ability registration). It stores a schema number in `ddwpt_settings_version` and runs each step once.

To rename an option key:

1. Add `'old_key' => 'new_key'` to `Migrations::RENAMED_OPTIONS`.
2. Bump `Migrations::VERSION` and call the copy step for the new version in `run()`.

The copy only fills the new key when it has no value yet, and leaves the old key in place so rolling back to an earlier release keeps its setting. Settings import passes files through `Migrations::translate_keys()`, so exports from older releases restore into the new keys.

| Version | Release | Change |
|---|---|---|
| 2 | 1.6.0 | `ddwpt_acf_abilities_api_enabled` → `ddwpt_ai_acf_field_abilities_acf_native` (Enable ACF Abilities folded into AI ACF Field Group Abilities) |
