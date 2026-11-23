# ACF Admin Screens

Two admin-only tweaks on the ACF tab (General group) that make ACF-heavy edit screens easier to use. Both depend on ACF's markup rather than an API, so re-check them after ACF updates.

## Highlight Tabs With Errors (`acf-tab-errors.php`)

After a failed save, ACF marks invalid fields but only the active tab is visible, so an error on another tab is easy to miss. This adds `.has-field-error` to the tab's `<li>` while any field in that tab, or inside one (group, repeater), carries `.acf-error`. The CSS turns the tab label red with a "!" badge.

- Assets: `assets/js/acf-tab-errors.js`, `assets/css/acf-tab-errors.css`, enqueued on `acf/input/admin_enqueue_scripts` (every screen that renders ACF fields).
- A tab's fields are the siblings after its `.acf-field-tab` row, up to the next tab row.
- Re-syncs on ACF's `invalid_field`, `valid_field`, `append` and `remove` actions, and on DOM changes (debounced to one run per animation frame).

## Tidier Term Edit Screens (`acf-term-screens.php`)

Restyles `term.php`:

- Core term fields (name, slug, parent, description, Permalink Manager row) as one card, with consistent labels, inputs and helper text. Matched with `.form-table:has(.term-name-wrap)`, so ACF tables are never affected.
- Section order through flexbox `order`: core fields, then ACF groups, then anything else, then the actions.
- Each ACF field group's `<h2>` and table read as one panel.
- Update / Delete stay in view: the actions bar is `position: sticky` at the bottom of the viewport.
- **Left tabs on term screens.** ACF ignores tab placement on term screens: fields render as `<tr>` rows, ACF builds a full-width top tab bar, and its left-tab refresh handler throws a `TypeError` when the tab wrap is a `<tr>`. The script switches left-placed tab buttons to `top` before ACF reads them (keeping `data-ddwpt-placement="left"`) and flags the table with `.ddwpt-acf-left-tabs`; the CSS then floats the tab bar into a 200px column beside the fields. Desktop only (783px and up).

The left column is a float rather than CSS grid on purpose: a grid spanning hundreds of implicit rows re-lays out the whole grid on every tab switch, which is visibly laggy on large field groups.

The script is printed inline on `admin_footer` at priority 1, not enqueued: ACF's tab initialisation is printed with the footer scripts, and the buttons must be rewritten after the form exists but before that runs.
