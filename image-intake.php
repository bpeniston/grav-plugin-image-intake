<?php
namespace Grav\Plugin;

use Grav\Common\Grav;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

/**
 * Image Intake: on Page-Media upload, sanitize the filename and shrink an oversized
 * image down to a per-template max width (discarding the original). The heavy resize
 * is done by the ImageMagick `convert` binary (its own memory / disk cache), so it
 * stays well under PHP's memory_limit even for very large originals.
 *
 * The per-template widths are configured from the plugin's admin Preferences pane:
 * the form is built dynamically (see capWidthFields) from the modular templates the
 * active theme — and any parent theme it inherits from — actually provides.
 */
class ImageIntakePlugin extends Plugin
{
    public static function getSubscribedEvents()
    {
        return [
            'onAdminAfterAddMedia'  => ['onAdminAfterAddMedia', 0],
            'onAdminSave'           => ['onAdminSave', 0],
            'onApiBeforePageCreate' => ['onApiBeforePageCreate', 0],
        ];
    }

    public function onAdminAfterAddMedia(Event $event)
    {
        if (!$this->config->get('plugins.image-intake.enabled', true)) {
            return;
        }

        $page = isset($event['page']) ? $event['page'] : (isset($event['object']) ? $event['object'] : null);
        if (!$page || !method_exists($page, 'path')) {
            return;
        }
        $folder = $page->path();
        if (!$folder || !is_dir($folder)) {
            return;
        }

        // Identify the file that was just uploaded.
        $file = null;
        if (!empty($_FILES['file']['name'])) {
            $candidate = $folder . '/' . basename($_FILES['file']['name']);
            if (is_file($candidate)) {
                $file = $candidate;
            }
        }
        if ($file === null) {
            $file = $this->newestFile($folder);
        }
        if ($file === null || !is_file($file)) {
            return;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return;
        }

        if ($this->config->get('plugins.image-intake.sanitize_filenames', true)) {
            $file = $this->sanitizeName($file);
        }

        // Resolve the max width for this page's template.
        //   - a positive per-template value  -> use it
        //   - a per-template value of 0       -> never resize this template
        //   - blank / template not listed     -> fall back to the default width
        $template = method_exists($page, 'template') ? basename((string) $page->template()) : '';
        $caps = (array) $this->config->get('plugins.image-intake.caps', []);

        $cap = null;
        if ($template !== '' && array_key_exists($template, $caps)
            && $caps[$template] !== '' && $caps[$template] !== null) {
            $cap = (int) $caps[$template];
        }
        if ($cap === null) {
            $cap = (int) $this->config->get('plugins.image-intake.default_max_width', 2000);
        }

        if ($cap > 0) {
            $this->shrink($file, $cap);
        }
    }

    /**
     * Keep a gallery module's frontmatter list in sync with its Page Media.
     *
     * Fires on every page save (the Grav-2.0 `api` plugin re-fires `onAdminSave`
     * with the page by reference, just before `$page->save()`). For pages whose
     * template is listed in `gallery_sync.fields`, we rebuild the configured
     * header list from the images actually in the page folder:
     *   - new uploads gain a row (appended),
     *   - deleted images lose their row,
     *   - every row is written in the `{image: <file>}` map form so the admin's
     *     thumbnail picker renders (a single-field list otherwise saves as bare
     *     strings, which blanks the thumbnail).
     * The user's drag order is preserved for files that still exist.
     */
    public function onAdminSave(Event $event)
    {
        if (!$this->config->get('plugins.image-intake.enabled', true)) {
            return;
        }
        if (!$this->config->get('plugins.image-intake.gallery_sync.enabled', true)) {
            return;
        }

        // onAdminSave also fires for users / config; act only on Page-like objects.
        $page = isset($event['page']) ? $event['page'] : (isset($event['object']) ? $event['object'] : null);
        if (!$page
            || !method_exists($page, 'header')
            || !method_exists($page, 'path')
            || !method_exists($page, 'template')) {
            return;
        }

        $template = basename((string) $page->template());
        $fields = (array) $this->config->get('plugins.image-intake.gallery_sync.fields', []);
        if (!array_key_exists($template, $fields)
            || $fields[$template] === '' || $fields[$template] === null) {
            return;
        }

        $this->reconcileGalleryList($page, (string) $fields[$template]);
    }

    /**
     * Rebuild $page->header()->{$field} from the image files in the page folder.
     */
    private function reconcileGalleryList($page, $field)
    {
        $folder = $page->path();
        if (!$folder || !is_dir($folder)) {
            return;
        }

        // 1. Image files actually present (stable natural order for any new files).
        $exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $onDisk = [];
        foreach (glob($folder . '/*') ?: [] as $f) {
            if (is_file($f)
                && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts, true)) {
                $onDisk[] = basename($f);
            }
        }
        sort($onDisk, SORT_NATURAL | SORT_FLAG_CASE);
        $onDiskSet = array_flip($onDisk);

        // 2. Current order from the existing list (tolerate {image: x} maps and bare scalars).
        $header = $page->header();
        $current = isset($header->{$field}) ? $header->{$field} : null;
        $existing = [];
        if (is_array($current)) {
            foreach ($current as $row) {
                if (is_array($row)) {
                    $name = isset($row['image']) ? $row['image'] : reset($row);
                } else {
                    $name = $row;
                }
                $name = is_string($name) ? basename(trim($name)) : '';
                if ($name !== '') {
                    $existing[] = $name;
                }
            }
        }

        // 3. Preferred order: existing gallery rows first (preserves the user's
        //    drag order), then `media_order` (so a not-yet-migrated gallery keeps
        //    its current display order the first time it is synced), then any
        //    remaining files by name (new uploads land at the end). Keep only
        //    files still on disk (drops orphans) and de-dupe.
        $order = $existing;
        $mediaOrder = isset($header->media_order) ? $header->media_order : '';
        if (is_string($mediaOrder) && $mediaOrder !== '') {
            foreach (explode(',', $mediaOrder) as $m) {
                $m = basename(trim($m));
                if ($m !== '') {
                    $order[] = $m;
                }
            }
        }
        foreach ($onDisk as $m) {
            $order[] = $m;
        }

        $result = [];
        $seen = [];
        foreach ($order as $name) {
            if (isset($onDiskSet[$name]) && !isset($seen[$name])) {
                $result[] = $name;
                $seen[$name] = true;
            }
        }

        // 4. Normalize to {image: file} maps and write back so $page->save() persists it.
        $list = [];
        foreach ($result as $name) {
            $list[] = ['image' => $name];
        }
        $header->{$field} = $list;
    }

    /**
     * Seed a new page/module's Content body with its blueprint's Content-field
     * `default` when the body comes in empty.
     *
     * Grav 1.7's classic admin editor applied `value ?? field.default`, so new
     * items opened pre-filled (e.g. the team's `[//]: # (CommentsGoHere)` notes
     * slot). Grav 2.0's admin2 SPA dropped that — it ignores the Content-field
     * default and the api persists the empty body as-is. This restores the seed
     * server-side at create time, via the by-reference `content` the api exposes.
     */
    public function onApiBeforePageCreate(Event $event)
    {
        if (!$this->config->get('plugins.image-intake.enabled', true)) {
            return;
        }
        if (!$this->config->get('plugins.image-intake.content_seed.enabled', true)) {
            return;
        }

        $content = isset($event['content']) ? (string) $event['content'] : '';
        if (trim($content) !== '') {
            return; // never clobber content the user actually supplied
        }

        $template = isset($event['template']) ? (string) $event['template'] : '';
        if ($template === '') {
            return;
        }

        $seed = $this->blueprintContentDefault($template);
        if (is_string($seed) && $seed !== '') {
            $event['content'] = $seed;
        }
    }

    /**
     * Resolve the Content-body `default` a template's blueprint would supply,
     * honoring this theme's one-level `@extends: default` fallback. Reads the
     * theme blueprint YAML directly (Symfony Yaml) — no dependency on Grav's
     * internal Blueprint API.
     */
    private function blueprintContentDefault($template)
    {
        $locator = $this->grav['locator'];
        if (!$locator->schemeExists('theme')) {
            return null;
        }
        foreach ([
            $locator->findResource('theme://blueprints/' . $template . '.yaml'),
            $locator->findResource('theme://blueprints/default.yaml'),
        ] as $f) {
            if (!$f || !is_file($f)) {
                continue;
            }
            try {
                $data = \Symfony\Component\Yaml\Yaml::parseFile($f);
            } catch (\Exception $e) {
                continue;
            }
            $default = $this->scanContentDefault($data);
            if ($default !== null) {
                return $default;
            }
        }
        return null;
    }

    /**
     * Recursively find the Content-body field's `default` in a parsed blueprint:
     * a field keyed `content` that carries a `default` and has no sub-`fields`
     * (i.e. the editor field itself, not the "Content" tab container).
     */
    private function scanContentDefault($node)
    {
        if (!is_array($node)) {
            return null;
        }
        foreach ($node as $key => $val) {
            if ($key === 'content' && is_array($val)
                && array_key_exists('default', $val) && !isset($val['fields'])) {
                return $val['default'];
            }
            if (is_array($val)) {
                $r = $this->scanContentDefault($val);
                if ($r !== null) {
                    return $r;
                }
            }
        }
        return null;
    }

    /**
     * Blueprint callback (data-fields@): build one max-width number field for every
     * modular template offered by the active theme and any parent theme it inherits.
     * Returns an associative array of blueprint fields keyed by their (dotted) name,
     * so each saves to `caps.<template>` in the plugin config.
     */
    public static function capWidthFields()
    {
        $grav = Grav::instance();

        // Resolve the modular-template directories of the active theme (and any parent
        // theme it inherits from) via the theme:// stream. Guard defensively: a config
        // page must never fatal just because the stream isn't ready.
        $dirs = [];
        try {
            $locator = $grav['locator'];
            if ($locator->schemeExists('theme')) {
                $dirs = (array) $locator->findResources('theme://templates/modular');
            }
        } catch (\Exception $e) {
            $dirs = [];
        }

        $names = [];
        foreach ($dirs as $dir) {
            foreach (glob(rtrim((string) $dir, '/') . '/*.html.twig') ?: [] as $tpl) {
                $name = basename($tpl, '.html.twig');
                // Skip partials/private templates (conventionally prefixed with "_").
                if ($name !== '' && strpos($name, '_') !== 0) {
                    $names[$name] = true;
                }
            }
        }
        $names = array_keys($names);
        sort($names);

        $default = (int) $grav['config']->get('plugins.image-intake.default_max_width', 2000);

        if (!$names) {
            return [
                'caps_none' => [
                    'type'     => 'display',
                    'markdown' => true,
                    'content'  => '_No modular templates were found in the active theme._',
                ],
            ];
        }

        $fields = [];
        foreach ($names as $name) {
            $fields['caps.' . $name] = [
                'type'        => 'number',
                'label'       => $name,
                'placeholder' => $default . ' (default)',
                'help'        => 'Max upload width in px for the "' . $name . '" template. '
                    . 'Blank = use the default width above; 0 = never resize.',
                'validate'    => [
                    'type' => 'int',
                    'min'  => 0,
                ],
            ];
        }

        return $fields;
    }

    private function newestFile($folder)
    {
        $best = null;
        $bestTime = -1;
        foreach (glob($folder . '/*') as $f) {
            if (is_file($f) && filemtime($f) > $bestTime) {
                $bestTime = filemtime($f);
                $best = $f;
            }
        }
        return $best;
    }

    private function sanitizeName($file)
    {
        $dir = dirname($file);
        $orig = basename($file);
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($orig, PATHINFO_EXTENSION)));
        $base = strtolower(pathinfo($orig, PATHINFO_FILENAME));
        $base = preg_replace('/[^a-z0-9_-]+/', '-', $base);
        $base = preg_replace('/-+/', '-', $base);
        $base = trim($base, '-_');
        if ($base === '') {
            $base = 'image';
        }
        $new = $base . ($ext !== '' ? '.' . $ext : '');
        if ($new === $orig) {
            return $file;
        }
        $target = $dir . '/' . $new;
        if (file_exists($target) && realpath($target) !== realpath($file)) {
            $i = 2;
            do {
                $new = $base . '-' . $i . ($ext !== '' ? '.' . $ext : '');
                $target = $dir . '/' . $new;
                $i++;
            } while (file_exists($target));
        }
        if (@rename($file, $target)) {
            return $target;
        }
        return $file;
    }

    private function shrink($file, $cap)
    {
        $info = @getimagesize($file);
        if (!$info || empty($info[0]) || (int) $info[0] <= $cap) {
            return;
        }
        $quality = (int) $this->config->get('plugins.image-intake.jpeg_quality', 82);
        $tmp = $file . '.intake-tmp';
        $cmd = 'convert ' . escapeshellarg($file)
            . ' -auto-orient -resize ' . (int) $cap . 'x -strip -quality ' . $quality . ' '
            . escapeshellarg($tmp) . ' 2>/dev/null';
        @exec($cmd, $out, $rc);
        if ($rc === 0 && is_file($tmp) && filesize($tmp) > 0) {
            @rename($tmp, $file);
        } elseif (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}
