<?php

namespace DDWPTweaks;

defined('ABSPATH') || exit;

/**
 * Keeps saved settings intact when an option key changes between releases.
 *
 * Runs on plugins_loaded before any tweak file is required, because several
 * tweaks read their options at file load (ACF filters, ability registration).
 * See docs/settings-migrations.md.
 */
class Migrations
{
    private const VERSION_OPTION = 'ddwpt_settings_version';
    private const VERSION        = 2;

    /**
     * Old option key => new option key. Applied once to the database and on
     * every settings import, so older export files still restore correctly.
     */
    public const RENAMED_OPTIONS = [
        // 1.6.0: "Enable ACF Abilities" folded into AI ACF Field Group Abilities.
        'ddwpt_acf_abilities_api_enabled' => 'ddwpt_ai_acf_field_abilities_acf_native',
    ];

    public static function run(): void
    {
        $current = (int) get_option(self::VERSION_OPTION, 1);
        if ($current >= self::VERSION) {
            return;
        }

        if ($current < 2) {
            self::copy_renamed_options();
        }

        update_option(self::VERSION_OPTION, self::VERSION);
    }

    /**
     * Rewrites legacy keys in an imported settings array. A new key that the
     * file already holds wins over its legacy counterpart.
     */
    public static function translate_keys(array $data): array
    {
        foreach (self::RENAMED_OPTIONS as $old => $new) {
            if (array_key_exists($old, $data) && !array_key_exists($new, $data)) {
                $data[$new] = $data[$old];
            }
            unset($data[$old]);
        }
        return $data;
    }

    // The old key is left in place so rolling back to an earlier release
    // still finds its setting. A value already saved under the new key wins.
    private static function copy_renamed_options(): void
    {
        foreach (self::RENAMED_OPTIONS as $old => $new) {
            $value = get_option($old, null);
            if ($value === null || get_option($new, null) !== null) {
                continue;
            }
            update_option($new, $value);
        }
    }
}
