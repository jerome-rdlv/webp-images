<?php

/**
 * Plugin name: WebP Images
 * Plugin URI: https://rue-de-la-vieille.fr
 * Description: Add a cron job that creates WebP versions of all uploaded images
 * Author: Jérôme Mulsant
 * Author URI: https://rue-de-la-vieille.fr
 * Version: GIT
 */

use Rdlv\WordPress\WebpImages\Setup;

defined('ABSPATH') || exit;

if (!class_exists(Setup::class)) {
    $autoload = __DIR__.'/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    } else {
        error_log('You need to install dependencies with `composer install`.');
        return;
    }
}

new Setup();
