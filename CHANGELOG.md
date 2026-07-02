# Changelog

## [0.6.0]
### Removed
- **Gallery auto-sync** (`onAdminSave` / `onApiPageUpdated` / `reconcileGalleryList`, the `gallery_sync.*` config and its Preferences toggle). This was a workaround for [getgrav/grav-plugin-admin2#74](https://github.com/getgrav/grav-plugin-admin2/issues/74) — admin2 couldn't drag-reorder Page Media at all, so galleries used a custom `list` blueprint field synced from Page Media on every save. Fixed upstream in **admin2 v2.0.7** (native Page Media drag-reorder, refined into a Reorder toggle in v2.0.8), so the workaround, its extra "Gallery" tab on gallery templates, and the config it required are no longer needed.

### Changed
- Minimum requirement raised to **Grav 2.0+** (previously 1.7+, back when the plugin still needed to work under the classic admin's `onAdminSave`).
- `newestFile()` (the upload-detection fallback used when `$_FILES['file']['name']` isn't available) now only considers image files, matching the primary detection path, instead of the newest file of any type in the page folder.

## [0.5.0]
### Fixed
- **Gallery auto-sync under newer Grav-2.0 `api` (≈ ≥1.0.3 / Grav 2.0.3).** The api now saves the page *before* firing its update event, so the `header.<field>` mutation made in `onAdminSave` no longer reached disk — galleries stopped auto-populating their list from Page Media on save. Added an `onApiPageUpdated` hook that reconciles the list and re-saves only when it changed. `onAdminSave` is retained for the older api (1.0.2) / classic admin, where it still works, so the plugin stays compatible across both.

## [0.4.0]
### Added
- **Content-body seeding on new pages (Grav 2.0 / admin2).** On page/module create (`onApiBeforePageCreate`), when the body is empty, inject the template's blueprint Content-field `default` (resolved from the active theme's blueprints, falling back to `default.yaml`). Restores the editor "notes slot" / per-template instructions that admin2 otherwise drops because it ignores the Content-field `default`. Toggle: `content_seed.enabled` (default on).

## [0.3.0]
### Added
- **Gallery auto-sync.** On save of a configured gallery template (`gallery_sync.fields`, default `gallery-draggable → gallery`), reconcile the frontmatter photo list with the page's media: add a row per new image, drop rows for deleted images, and normalize each row to the `{image: <file>}` map form (so admin thumbnails render). Existing drag order is preserved; new entries seed from `media_order` then filename. Toggle: `gallery_sync.enabled` (default on).

## [0.2.0]
### Added
- **Preferences pane for per-template widths.** The admin config now shows one max-width field for every modular template in the active theme (and any parent theme), generated dynamically from the `theme://templates/modular` stream — so widths can be set from Admin instead of editing YAML over SSH.
- Exposed `default_max_width`, `jpeg_quality`, and `sanitize_filenames` in the Preferences pane.

### Changed
- Per-template width resolution: a blank value now falls back to the default width, and `0` means "never resize" for that template.

## [0.1.0]
### Added
- Initial release: on Page-Media upload, sanitize the filename and shrink oversized images to a per-template max width (via the ImageMagick `convert` binary), discarding the original.
