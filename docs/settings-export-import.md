# Settings Export / Import

The settings page includes export and import buttons that serialize all registered tweak option values to/from a JSON file.

## Behaviour

**Export** — sends an AJAX request to `ddwpt_export_settings`, which collects every option currently registered by the tweak loader and returns them as a JSON download. The filename is `ddwpt-settings.json`.

**Import** — the user selects a `.json` file; its contents are sent to `ddwpt_import_settings`. Only keys that match a registered setting ID are written. Unknown keys are silently dropped. Each value is sanitized using the same type-based sanitize map used during normal settings saves.

## Security

Both endpoints:
- Require a valid nonce (`ddwpt_export` / `ddwpt_import`), created per-page-load in `enqueue_assets` and passed via `ddwptSettings` JS object.
- Require `manage_options` capability.

The import endpoint validates the incoming JSON is an array and filters against the known-good settings map before calling `update_option`. There is no unserialize step.

## Portability

Exported values reflect the stored option state at the time of export (including defaults for options never explicitly saved). Settings can be moved between WP installs by exporting from one and importing to another, as long as both have the same tweaks active.

Settings specific to a particular environment (e.g. API keys, environment indicator labels) will be carried over as-is — review the exported JSON before importing to a different environment.

## JS hooks

The export/import logic lives in `assets/js/settings.js`. It listens to `.ddwpt-export-btn` and `.ddwpt-import-btn` clicks and uses the hidden `#ddwpt-import-file` input to trigger the file picker.
