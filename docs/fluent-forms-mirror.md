# Fluent Forms Mirror

Tweak: `inc/tweaks/fluentforms-mirror.php` (`ddwpt_fluentforms_mirror`, Bricks tab, Integrations group).

Mirrors every Fluent Forms form into one post of the `ff_form` custom post type so forms can be used where Bricks expects posts: Query Loops (loop over forms, render each with the Fluent Forms element and `{post_meta:fluentform_id}`) and popup context (pass a mirror post to a popup so it renders the matching form).

Only runs when the tweak is enabled **and** Fluent Forms is active (`defined('FLUENTFORM')`).

## Data model

| Item | Value |
|---|---|
| Post type | `ff_form` |
| Meta key | `fluentform_id` (integer, single, in REST) |
| Title | Form title |
| Status | `publish` when the form is `published`, otherwise `draft` |
| `menu_order` | Form ID, so loops can sort by it |

One mirror per form. Mirrors with no matching form, no `fluentform_id`, or a duplicate ID are deleted on sync.

## Why `public => true`

Bricks only lists public post types in the Query Loop post type picker. Everything else is switched off: `publicly_queryable`, `query_var`, `rewrite`, `has_archive` are false, it is excluded from search, nav menus and the admin bar, and a `template_redirect` guard 404s any singular or archive request that still gets through (for example `?p=` or a preview link).

The admin list sits under the Fluent Forms menu (`show_in_menu => 'fluent_forms'`) with a Form ID column. `create_posts` is `do_not_allow`: mirrors are only created by the sync.

## Sync

`fluentforms_mirror_sync()` reconciles the CPT against `{prefix}fluentform_forms`. It never runs inline; `fluentforms_mirror_queue_sync()` hooks it to `shutdown` once per request, so several triggers in one request cost one sync.

Triggers:

- Fluent Forms lifecycle hooks, current and legacy names: `fluentform/inserted_new_form`, `fluentform/form_duplicated`, `fluentform/after_form_delete` (and the `fluentform_*` underscore variants).
- `admin_init` on any `fluent_forms*` admin page or the `ff_form` list screen. Renames and status changes have no reliable hook, so they are picked up the next time someone opens a Fluent Forms screen.
- First run: if the `ddwpt_fluentforms_mirror_synced` option is missing, the first admin request after enabling fills the CPT.

## Disabling

Disabling the tweak stops registering the CPT, so existing mirror posts become inert rows in `wp_posts`. They are not deleted. Re-enabling picks up where it left off; the next Fluent Forms screen load reconciles any drift. Delete `ddwpt_fluentforms_mirror_synced` to force the first-run fill again.
