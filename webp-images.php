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
    $cmd_path = __DIR__ . '/cwebp';
    if (!file_exists($cmd_path)) {
        return;
    }

    // test command execution
    passthru(sprintf('%s -h', $cmd_path), $return);
    if ($return !== 0) {
        trigger_error(
            sprintf(
                'cwebp execution returned %s code during webp-images cron job.',
                $return
            )
        );
        return;
    }

    trigger_error('Starting WebP conversion routine', E_USER_NOTICE);

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
                '%s -quiet %s -o %s',
                $cmd_path,
                $img_path,
                $webp_path
            ),
            $return
        );

        ++$count;
    }

    trigger_error(sprintf('%s images converted to WebP', $count), E_USER_NOTICE);
});

add_action('init', function () {
    $exists = wp_next_scheduled('webp_images_generation');
    if (file_exists(__DIR__ . '/cwebp')) {
        // binary found, schedule task if it does not exists yet
        if (!$exists) {
            // default to 03:00:00
            $time = (new DateTime())->setTime(3, 0, 0);

            // allow to change that
            $time = apply_filters('webp_images_time', $time);

            // assert first occurrence is in the future
            $now = new DateTime();
            while ($time < $now) {
                $time->add(new DateInterval('P1D'));
            }
            wp_schedule_event($time->format('U'), 'daily', 'webp_images_generation');
        }
    } else {
        // binary not found, unschedule existing task
        if ($exists) {
            $timestamp = wp_next_scheduled('webp_images_generation');
            wp_unschedule_event($timestamp, 'webp_images_generation');
        }
    }
});
