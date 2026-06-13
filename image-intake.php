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
            'onAdminAfterAddMedia' => ['onAdminAfterAddMedia', 0],
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
     * Blueprint callback (data-fields@): build one max-width number field for every
     * modular template offered by the active theme and any parent theme it inherits.
     * Returns an associative array of blueprint fields keyed by their (dotted) name,
     * so each saves to `caps.<template>` in the plugin config.
     */
    public static function capWidthFields()
    {
        $grav = Grav::instance();

        $names = [];
        foreach ((array) $grav['locator']->findResources('theme://templates/modular') as $dir) {
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
