# Changelog

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
