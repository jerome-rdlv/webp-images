<?php

/**
 * Plugin name: WebP Images
 * Plugin URI: https://rue-de-la-vieille.fr
 * Description: Add a cron job that creates WebP versions of all uploaded images
 * Author: Jérôme Mulsant
 * Author URI: https://rue-de-la-vieille.fr
 * Version: GIT
 *
 * This plugin use precompiled cwebp from https://developers.google.com/speed/webp/docs/precompiled
 */

add_action('webp_images_generation', function () {
    $bin_dir = defined('BIN_DIR') ? BIN_DIR : WP_CONTENT_DIR . '/bin';

    $cmd_path = $bin_dir . '/cwebp';
    if (!file_exists($cmd_path)) {
        // install cwebp command
        wp_mkdir_p($bin_dir);
        
        $cmd_basename = 'libwebp-1.1.0-linux-x86-64';
        $cmd_url = sprintf(
            'https://storage.googleapis.com/downloads.webmproject.org/releases/webp/%s.tar.gz',
            $cmd_basename
        );
        passthru(
            sprintf(
                'wget -c "%s" -O - | tar -zxOvf - "%s/bin/cwebp" > "%s"',
                $cmd_url,
                $cmd_basename,
                $cmd_path
            ),
            $return
        );
        if (!file_exists($cmd_path) || $return !== 0) {
            trigger_error(
                sprintf(
                    'cwebp installation returned %s code.',
                    $return,
                )
            );
            return;
        }
        
        chmod($cmd_path, 0744);
    }

    // test command execution
    passthru(sprintf('"%s" -h', $cmd_path), $return);
    if ($return !== 0) {
        trigger_error(
            sprintf(
                'cwebp execution returned %s code during webp-images cron job with cmd %s.',
                $return,
                $cmd_path
            )
        );
        return;
    }

    // set priority to lowest
    proc_nice(20);

    $upload_dir = wp_upload_dir()['basedir'];
    $paths = glob($upload_dir . '{,/*,/*/*}/*.{jpg,jpeg,png}', GLOB_BRACE);

    $count = 0;
    foreach ($paths as $img_path) {
        $webp_path = preg_replace('/\.(jpg|jpeg|png)$/', '.webp', $img_path);

        if (file_exists($webp_path)) {
            // WebP version exists
            continue;
        }

        // WebP version does not exist, create it
        passthru(
            sprintf(
                '"%s" -quiet "%s" -o "%s"',
                $cmd_path,
                $img_path,
                $webp_path
            ),
            $return
        );

        ++$count;
    }

    if ($count) {
        trigger_error(sprintf('%s images converted to WebP', $count), E_USER_NOTICE);
    }
});

add_action('init', function () {
    if (wp_next_scheduled('webp_images_generation')) {
        // task exists already
        return;
    }

    // default to 03:00:00
    $time = (new DateTime())->setTime(3, 0, 0);

    // allow to change that
    $time = apply_filters('webp_images_task_time', $time);

    // assert first occurrence is in the future
    $now = new DateTime();
    while ($time < $now) {
        $time->add(new DateInterval('P1D'));
    }

    wp_schedule_event($time->format('U'), 'daily', 'webp_images_generation');
});
