<?php

namespace Rdlv\WordPress\WebpImages;

use DateTime;
use ErrorException;
use Exception;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;
use WP_CLI;

class Setup
{
    private ?int $quality = null;
    private ?array $extensions = null;
    private ?string $uploadDir = null;
    private ?string $lastPath = null;

    public function __construct()
    {
        add_action('init', [$this, 'schedule']);
        add_action('webp_images_generation', [$this, 'cron']);
        add_filter('wp_delete_file', [$this, 'delete']);
        add_filter('mod_rewrite_rules', [$this, 'htaccess']);

        add_filter('mime_types', function ($mimes) {
            $mimes['webp'] = 'image/webp';
            return $mimes;
        });

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI_Command')) {
            try {
                WP_CLI::add_command('webp generate', [$this, 'cron']);
            } catch (Exception $e) {
                trigger_error($e->getMessage(), E_USER_WARNING);
            }
        }
    }

    private function getQuality(): int
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

    public function schedule(): void
    {
        if (wp_next_scheduled('webp_images_generation')) {
            // task exists already
            return;
        }

        // default to 03:00:00
        $time = (new DateTime())->setTime(3, 0);

        // allow to change that
        $time = apply_filters('webp_images_task_time', $time);

        wp_schedule_event($time->format('U'), 'daily', 'webp_images_generation');
    }

    public function cron(): void
    {
        // set priority to lowest
        proc_nice(20);

        // start with files because there may be multiple attachment for a single file due to i18n
        $uploads = $this->getUploadBaseDir();

        /*
         * uploads/*
         * uploads/YEAR/MONTH/*
         * uploads/sites/SITE_ID/*
         * uploads/sites/SITE_ID/YEAR/MONTH/*
         */

        $in = [
            $uploads.'/*/*',
        ];

        if (is_multisite() && file_exists($uploads.'/sites')) {
            $in[] = $uploads.'/sites/*';
        }

        $in = apply_filters('webp_images_paths', $in);

        if (!$in) {
            trigger_error('No image directory found.');
            return;
        }

        $finder = (new Finder())
            ->ignoreUnreadableDirs()
            ->in($in)
            ->files()
            ->name(sprintf('*.{%s}', implode(',', $this->getExtensions())))
            ->filter(function (SplFileInfo $file) {
                return !preg_match('/-[0-9]+x[0-9]+\.[^.]+$/', $file->getFilename());
            });

        add_action('wp_die_handler', [$this, 'wp_die_handler']);

        $count = 0;
        foreach ($finder as $file) {
            try {
                set_error_handler(function ($level, $message, $file, $line) {
                    throw new ErrorException($message, 0, $level, $file, $line);
                });
                $this->generate($file->getRealPath()) && ++$count;
            } catch (Throwable $e) {
                error_log($e->getMessage());
            } finally {
                restore_error_handler();
            }
        }

        if ($count) {
            trigger_error(sprintf('%s images converted to WebP', $count));
        }
    }

    /**
     * @throws Exception
     */
    private function generate(string $path): bool
    {
        $this->lastPath = $path;
        $webpPath = $this->pathToWebp($path);

        if (file_exists($webpPath)) {
            return false;
        }

        // create full webp version
        if (is_wp_error($editor = wp_get_image_editor($path))) {
            throw new Exception($editor->get_error_message());
        }

        if (!$editor::supports_mime_type('image/webp')) {
            return false;
        }

        if (is_wp_error($output = @$editor->save($webpPath))) {
            @unlink($webpPath);
            throw new Exception(($output->get_error_message()));
        }

        $metadata = $this->getMetadata($path);
        if (!$metadata) {
            return false;
        }

        if (!empty($metadata['original_image'])) {
            // convert original image and use it as source for generated thumbnails
            $path = preg_replace('/[^\/]+$/i', $metadata['original_image'], $path);

            if (is_wp_error($editor = wp_get_image_editor($path))) {
                throw new Exception($editor->get_error_message());
            }
            $webpPath = $this->pathToWebp($path);
            $editor->set_quality(92);

            if (is_wp_error($output = $editor->save($webpPath))) {
                @unlink($webpPath);
                throw new Exception(($output->get_error_message()));
            }
        }

        if ($metadata['sizes']) {
            // create webp thumbnails
            if (is_wp_error($editor = wp_get_image_editor($webpPath))) {
                throw new Exception($editor->get_error_message());
            }
            $editor->set_quality($this->getQuality());
            $editor->multi_resize($metadata['sizes']);
        }

        return true;
    }

    public function pathToWebp($path): string
    {
        return preg_replace('/\.[^.]+$/', '.webp', $path);
    }

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

    public function getMetadata($path): ?array
    {
        global $wpdb;
        $basepath = substr_replace($path, '', 0, strlen(trailingslashit($this->getUploadBaseDir())));
        $metadata = $wpdb->get_var(
            $wpdb->prepare(
                "
            SELECT pm2.meta_value
            FROM $wpdb->postmeta pm1
            RIGHT JOIN $wpdb->postmeta pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_wp_attachment_metadata'
            WHERE pm1.meta_key = '_wp_attached_file'
            AND pm1.meta_value = %s
            ",
                $basepath
            )
        );

        if (empty($metadata)) {
            return null;
        }
        return unserialize($metadata);
    }

    public function wp_die_handler($handler)
    {
        if (!($error = error_get_last()) || $error['type'] !== E_ERROR) {
            return $handler;
        }
        if ($this->lastPath === null) {
            return $handler;
        }

        $webpPath = $this->pathToWebp($this->lastPath);
        if (!file_exists($webpPath)) {
            return $handler;
        }

        /* This may happen with indexed colors PNG. We have no way to catch the
         * Fatal Error thrown by the image function. Simply dropping the empty WebP file
         * would trigger the error again on each cron, preventing WebP generation for
         * other images. So we work around this by replacing the empty WebP file with
         * the original JPG or PNG version.
         */
        copy($this->lastPath, $webpPath);

        return $handler;
    }

    public function htaccess(string $rules): string
    {
        $webp_rules = <<<EOD
# BEGIN WebP
AddType image/webp .webp
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP_ACCEPT} image/webp
    RewriteCond %{REQUEST_URI} (?i)(.*)\.(jpe?g|png)$
    RewriteCond %{DOCUMENT_ROOT}%1.webp -f
    RewriteRule (?i)\.(jpe?g|png)$ %1.webp [NC,T=image/webp,L]
</IfModule>
<IfModule mod_headers.c>
    <If "%{REQUEST_URI} =~ m#\.(jpe?g|png)$#">
        Header append Vary Accept
    </If>
</IfModule>
# END WebP
EOD;
        return "\n".trim($webp_rules)."\n\n".trim($rules);
    }
}
