# Image Intake Plugin

The **Image Intake** plugin for [Grav CMS](https://getgrav.org) cleans up images as they are uploaded through the Admin panel. On every Page-Media upload it:

1. **Sanitizes the filename** — lowercases it and replaces spaces and unsafe characters with hyphens (so `My Photo (1).JPG` becomes `my-photo-1.jpg`).
2. **Shrinks oversized images** to a maximum width you choose **per modular template**, discarding the bulky original.

The resize is performed by the ImageMagick `convert` binary in its own process, so it stays well under PHP's `memory_limit` even for very large (multi-megapixel) originals — no need to raise PHP memory just to accept big photos from contributors.

## Why

Editors shouldn't have to think about pixel dimensions or filenames. Drag in a full-size photo with a messy name and the plugin makes it web-ready automatically — and a hero banner can keep a higher cap than a small sponsor-logo grid, because the cap is set **per template**.

## Requirements

- Grav 1.7+
- The ImageMagick `convert` binary available on the server (`command -v convert`)
- PHP `exec()` not disabled

If `convert` isn't available, uploads still work and filenames are still sanitized — images simply aren't resized.

## Installation

This plugin is installed manually (not yet on the Grav Package Manager).

```bash
cd /your/site/user/plugins
git clone https://github.com/bpeniston/grav-plugin-image-intake.git image-intake
```

The folder **must** be named `image-intake`. Then clear the cache:

```bash
bin/grav clearcache
```

## Configuration

Open **Admin → Plugins → Image Intake**. The Preferences pane offers:

| Setting | Meaning |
|---|---|
| **Plugin status** | Enable / disable all processing. |
| **Sanitize filenames** | Lowercase + hyphenate uploaded filenames. |
| **Default maximum width (px)** | Used for any template without its own width. `0` = never resize by default. |
| **Re-encode quality (1–100)** | Quality used when an image is resized. |
| **Per-template maximum widths** | One field per modular template in your theme. |

### Per-template widths

The plugin automatically discovers every modular template provided by your **active theme and any parent theme it inherits from**, and shows a width field for each. For example, a Quark-based site might show `gallery`, `hero`, `feature-images`, `text`, and so on.

For each template:

- **A number** — uploads on pages using that template are capped at that width.
- **Blank** — use the *Default maximum width*.
- **`0`** — never resize uploads on that template (sanitize only).

Images narrower than the cap are left alone (no upscaling).

Settings are stored in `user/config/plugins/image-intake.yaml` and can also be edited there directly:

```yaml
enabled: true
sanitize_filenames: true
jpeg_quality: 82
default_max_width: 2000
caps:
  hero: 2560
  gallery: 1200
  feature-images: 800
```

## How it works

The plugin listens for Grav's `onAdminAfterAddMedia` event. It locates the just-uploaded file, sanitizes the name, looks up the cap for the page's template (`basename` of `$page->template()`), and — only if the image is wider than the cap — runs:

```
convert <file> -auto-orient -resize <cap>x -strip -quality <q> <file>
```

The Preferences form is built dynamically: the `caps` fieldset uses a `data-fields@` blueprint callback (`ImageIntakePlugin::capWidthFields`) that scans the `theme://templates/modular` resource stream, so the list always matches whatever templates your theme actually offers.

## License

[MIT](LICENSE)
