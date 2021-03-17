<?php

/**
 * Plugin name: WebP Images
 * Plugin URI: https://rue-de-la-vieille.fr
 * Description: Add a cron job that creates WebP versions of all uploaded images
 * Author: Jérôme Mulsant
 * Author URI: https://rue-de-la-vieille.fr
 * Version: GIT
 */

namespace Rdlv\WordPress\WebpImages;

use DateInterval;
use DateTime;
use WP_CLI;

new WebpImages();

/**
 * @todo Hook on media thumbnail regenerate
 */
class WebpImages
{
    /** @var int */
    private $quality;

    /** @var string[] */
    private $extensions;

    /** @var string */
    private $uploadDir;

    public function __construct()
    {
        add_action('init', [$this, 'schedule']);
        add_action('webp_images_generation', [$this, 'cron']);
        add_filter('wp_delete_file', [$this, 'delete']);

        add_filter('mime_types', function ($mimes) {
            $mimes['webp'] = 'image/webp';
            return $mimes;
        });

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI_Command')) {
            WP_CLI::add_command('webp generate', [$this, 'cron']);
        }
    }

    private function getQuality()
    {
        if (!$this->quality) {
            // WP_Image_Editor default
            $this->quality = 82;
            $this->quality = (int)apply_filters('wp_editor_set_quality', $this->quality);
            $this->quality = (int)apply_filters('webp_images_quality', $this->quality);
        }
        return $this->quality;
    }

    private function getExtensions(): array
    {
        if (!$this->extensions) {
            $this->extensions = apply_filters('webp_images_extensions', ['jpg', 'jpeg', 'png']);
            $this->extensions = array_filter($this->extensions, function ($extension) {
                return $extension !== 'svg';
            });
        }
        return $this->extensions;
    }

    private function getUploadBaseDir(): string
    {
        if (!$this->uploadDir) {
            $this->uploadDir = wp_upload_dir()['basedir'];
        }
        return $this->uploadDir;
    }

    public function schedule()
    {
        if (wp_next_scheduled('webp_images_generation')) {
            // task exists already
            return;
        }

        // default to 03:00:00
        $time = (new DateTime())->setTime(3, 0);

        // allow to change that
        $time = apply_filters('webp_images_task_time', $time);

        // assert first occurrence is in the future
        $now = new DateTime();
        while ($time < $now) {
            $time->add(new DateInterval('P1D'));
        }

        wp_schedule_event($time->format('U'), 'daily', 'webp_images_generation');
    }

    public function cron()
    {
        // set priority to lowest
        proc_nice(20);

        // start with files because there may be multiple attachment for a single file due to i18n
        $paths = glob(
            sprintf(
                '%s{,/*,/*/*}/*.{%s}',
                $this->getUploadBaseDir(),
                implode(',', $this->getExtensions())
            ),
            GLOB_BRACE
        );

        $count = 0;
        foreach ($paths as $path) {
            if (preg_match('/-[0-9]+x[0-9]+\.[^.]+$/', $path)) {
                // do not handle thumbnails directly
                continue;
            }
            $this->generate($path);
            ++$count;
        }

        if ($count) {
            trigger_error(sprintf('%s images converted to WebP', $count));
        }
    }

    private function generate(string $path): bool
    {
        $webp_path = $this->pathToWebp($path);

        if (file_exists($webp_path)) {
            return true;
        }

        $metadata = $this->getMetadata($path);
        if (!$metadata) {
            return false;
        }

        // create full webp version
        $editor = wp_get_image_editor($path);

        if (is_wp_error($editor)) {
            return false;
        }

        if (!$editor::supports_mime_type('image/webp')) {
            return false;
        }

        $editor->set_quality($this->getQuality());
        $output = $editor->save($webp_path);

        if (is_wp_error($output)) {
            return false;
        }

        if (!empty($metadata['original_image'])) {
            // convert original image and use it as source for generated thumbnails
            $path = preg_replace('/[^\/]+$/i', $metadata['original_image'], $path);
            $editor = wp_get_image_editor($path);
            $webp_path = $this->pathToWebp($path);
            $editor->set_quality(92);
            $output = $editor->save($webp_path);

            if (is_wp_error($output)) {
                return false;
            }
        }

        if ($metadata['sizes']) {
            // create webp thumbnails
            $editor = wp_get_image_editor($webp_path);
            $editor->set_quality($this->getQuality());
            $editor->multi_resize($metadata['sizes']);
        }

        return true;
    }

    public function pathToWebp($path): string
    {
        return preg_replace('/\.[^.]+$/', '.webp', $path);
    }

    /**
     * @param $file
     * @return mixed
     */
    public function delete($file)
    {
        if ($file) {
            $webp = $this->pathToWebp($file);
            if (file_exists($webp)) {
                @unlink($webp);
            }
        }
        return $file;
    }

    /**
     * @param $path
     * @return array|null
     */
    public function getMetadata($path): ?array
    {
        global $wpdb;
        $basepath = substr_replace($path, '', 0, strlen(trailingslashit($this->getUploadBaseDir())));
        $metadata = $wpdb->get_var($wpdb->prepare(
            "
            SELECT pm2.meta_value
            FROM $wpdb->postmeta pm1
            RIGHT JOIN $wpdb->postmeta pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_wp_attachment_metadata'
            WHERE pm1.meta_key = '_wp_attached_file'
            AND pm1.meta_value = %s
            ",
            $basepath
        ));

        if (empty($metadata)) {
            return null;
        }
        return unserialize($metadata);
    }
}