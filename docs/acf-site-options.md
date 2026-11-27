# ACF Site Options

`inc/tweaks/acf-site-options.php` registers the **Branding & Extras** ACF options page (`admin.php?page=site-options`) and its field group (`group_66d540426b8e3`): brand, contact, social, map, announcement bar, trust indicators and legal. Requires ACF. Values are read with `get_field('so_…', 'option')` and stored as `options_so_…` rows in `wp_options`. The full field name list is on the tweak's **Site Options Fields** nested page.

## Announcement bar

| Field | Type | Notes |
|---|---|---|
| `so_announcement_enabled` | `true_false` | Master switch. Gated by timer mode (below). |
| `so_announcement_content` | `wysiwyg` | Shown while enabled. |
| `so_announcement_timer` | `true_false` | Opt-in timer mode. Shown while enabled. |
| `so_announcement_start` | `date_time_picker` | Shown while timer mode is on. Blank: no lower bound. |
| `so_announcement_end` | `date_time_picker` | Shown while timer mode is on. Blank: no upper bound. |

### Timer mode

With timer mode on, the bar shows only while `start <= now < end`, with either bound optional. Both pickers store and return `Y-m-d H:i:s` with no offset, which is read in the site's WordPress timezone (`wp_timezone()`), and `now` is `current_datetime()`. The pickers' instructions name the timezone so editors are not guessing.

Saving an end that is not after the start fails ACF validation (`acf/validate_value/name=so_announcement_end`).

### How the bar is gated

The tweak filters `acf/load_value/name=so_announcement_enabled`: outside the window the field loads as `0`. Every ACF read sees this, so existing Bricks conditions on the field, `{acf_so_announcement_enabled}` and `get_field('so_announcement_enabled', 'option')` all hide the bar with no template changes. The filter is skipped on admin screens (except AJAX), so the options page still shows and saves the editor's real toggle.

Raw `get_option('options_so_announcement_enabled')` is not gated. In PHP, use the helpers in the `DDWPTweaks\Tweaks` namespace:

| Function | Returns |
|---|---|
| `announcement_bar_active()` | Enabled and inside the window |
| `announcement_bar_in_window(?DateTimeImmutable $now = null)` | Schedule check only (always `true` with timer mode off) |
| `announcement_bar_bound($raw)` | A stored picker value as a `DateTimeImmutable` in the site timezone, or `null` |

The helpers read the raw `options_` rows, so they do not recurse through the ACF filter.

### Caching

The check runs per request. A full page cache serves whatever was cached, so the bar appears or disappears once the cached page expires or is purged. Set the cache lifetime short around a scheduled start or end, or purge at that time.
