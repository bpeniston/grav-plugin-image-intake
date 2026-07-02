# Image Intake Plugin

The **Image Intake** plugin for [Grav CMS](https://getgrav.org) cleans up images as they're uploaded through Admin2, and restores a couple of admin2 behaviors the site's theme relies on. On every Page-Media upload it:

1. **Sanitizes the filename** — lowercases it and replaces spaces and unsafe characters with hyphens (so `My Photo (1).JPG` becomes `my-photo-1.jpg`).
2. **Shrinks oversized images** to a maximum width you choose **per modular template**, discarding the bulky original.

It also seeds new modular pages' Content body from the template's blueprint default (see [Content seeding](#content-seeding-on-new-pages) below) — an admin2 gap that's unrelated to image handling but small enough to live in the same plugin.

The resize is performed by the ImageMagick `convert` binary in its own process, so it stays well under PHP's `memory_limit` even for very large (multi-megapixel) originals — no need to raise PHP memory just to accept big photos from contributors.

## Why

Editors shouldn't have to think about pixel dimensions or filenames. Drag in a full-size photo with a messy name and the plugin makes it web-ready automatically — and a hero banner can keep a higher cap than a small sponsor-logo grid, because the cap is set **per template**.

## What's new in 0.6.0

Earlier versions also carried a **Gallery auto-sync** feature that rebuilt a gallery module's photo list from Page Media on every save, working around a bug where admin2 couldn't drag-reorder Page Media at all ([getgrav/grav-plugin-admin2#74](https://github.com/getgrav/grav-plugin-admin2/issues/74)). That bug is fixed as of **admin2 v2.0.7** — Page Media (and the site-wide Media manager) now support a native drag-to-reorder, saved with the page. The workaround, its config toggle, and the extra "Gallery" tab it required on gallery templates are gone. If a gallery module's order ever looks off after upgrading admin2, reorder it once from Page Media's new **Reorder** toggle and save — order is picked up from `media_order` / on-page order the normal way from then on.

## Requirements

- Grav **2.0+**, with `admin2` **≥ 2.0.7** (for native Page Media drag-reorder — earlier admin2 builds still work for upload processing, they just can't reorder galleries in the admin UI)
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
| **Seed Content notes on new pages** | See [Content seeding](#content-seeding-on-new-pages) below. |
| **Default maximum width (px)** | Used for any template without its own width. `0` = never resize by default. |
| **Re-encode quality (1–100)** | Quality used when an image is resized. |
| **Per-template maximum widths** | One field per modular template in your theme. |

### Per-template widths

The plugin automatically discovers every modular template provided by your **active theme and any parent theme it inherits from**, and shows a width field for each. For example, a Quark-based site might show `gallery`, `gallery-draggable`, `hero`, `feature-images`, `text`, and so on.

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
content_seed:
  enabled: true
```

## Content seeding on new pages

Grav 1.7's classic admin editor pre-filled a new page's Content body from the template blueprint's Content-field `default` (e.g. a `[//]: # (CommentsGoHere)` notes slot, or a template's own instructions). admin2's SPA editor ignores that default and the api persists the empty body as-is.

On page/module create (`onApiBeforePageCreate`), if the body comes in empty, this plugin injects the template's blueprint Content `default` — read directly from the active theme's blueprint YAML (`theme://blueprints/<template>.yaml`, falling back to `default.yaml`). It never overwrites content the user actually typed. Toggle: **Seed Content notes on new pages** (default on).

## How it works

The plugin listens for Grav's `onAdminAfterAddMedia` event on upload. It locates the just-uploaded file, sanitizes the name, looks up the cap for the page's template (`basename` of `$page->template()`), and — only if the image is wider than the cap — runs:

```
convert <file> -auto-orient -resize <cap>x -strip -quality <q> <file>
```

The Preferences form is built dynamically: the `caps` fieldset uses a `data-fields@` blueprint callback (`ImageIntakePlugin::capWidthFields`) that scans the `theme://templates/modular` resource stream, so the list always matches whatever templates your theme actually offers.

## License

[MIT](LICENSE)
