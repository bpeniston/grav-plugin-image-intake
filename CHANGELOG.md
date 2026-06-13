# Changelog

## [0.2.0]
### Added
- **Preferences pane for per-template widths.** The admin config now shows one max-width field for every modular template in the active theme (and any parent theme), generated dynamically from the `theme://templates/modular` stream — so widths can be set from Admin instead of editing YAML over SSH.
- Exposed `default_max_width`, `jpeg_quality`, and `sanitize_filenames` in the Preferences pane.

### Changed
- Per-template width resolution: a blank value now falls back to the default width, and `0` means "never resize" for that template.

## [0.1.0]
### Added
- Initial release: on Page-Media upload, sanitize the filename and shrink oversized images to a per-template max width (via the ImageMagick `convert` binary), discarding the original.
