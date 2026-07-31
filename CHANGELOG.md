# Changelog

## [0.6.1]
### Added
- **Registered `capWidthFields()` as an allowed dynamic-data provider.** Grav >= 2.0.13 gates every `data-*@` provider behind an allowlist, closing an arbitrary-static-method bypass ([GHSA-7pgq-cr25-xvc8](https://github.com/getgrav/grav/security/advisories/GHSA-7pgq-cr25-xvc8), [GHSA-cxv3-5jj3-cpgr](https://github.com/getgrav/grav/security/advisories/GHSA-cxv3-5jj3-cpgr)); an unregistered provider is refused **silently**, with no exception and nothing in `grav.log`. Registration now happens via `Blueprint::addAllowedDynamicCallable()` in a new `onPluginsInitialized` handler, guarded by `method_exists` so the plugin still loads on 2.0.x below 2.0.13.

### Known issue (not fixed by this release)
- **The "Template widths" fieldset still renders empty under admin2.** The allowlist registration above is a *prerequisite*, not a cure. The api plugin loads plugin/config blueprints with `(new Blueprint($path))->load()` and never calls `->init()` — and `init()` is what resolves `data-*@` directives. Verified on Grav 2.0.13 / admin2 2.0.16: with registration in place `capWidthFields()` *is* invoked and returns its fields, yet the blueprint still reports `caps => fieldset, children=0`. (The api's *page*-blueprint path uses the full `load()->init()` pipeline and is unaffected; this is the same class of bug fixed there in grav-plugin-admin2#3.) Reported upstream.
- **Cap enforcement is unaffected and always has been.** `onAdminAfterAddMedia` reads `plugins.image-intake.caps` straight from config and never touches a blueprint, so configured widths — and filename sanitizing — keep applying on every admin upload. Until the upstream fix lands, widths are edited in `user/config/plugins/image-intake.yaml`.

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
