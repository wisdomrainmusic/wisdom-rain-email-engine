<?php
/**
 * Plugin Name: Wisdom Rain Email Engine
 * Plugin URI:  https://wisdomrainbookmusic.com
 * Description: Email & Verification Engine for Wisdom Rain Platform.
 * Version:     1.0.0
 * Author:      Wisdom Rain Team
 * Text Domain: wisdom-rain-email-engine
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once __DIR__ . '/core/class-wre-core.php';
    require_once __DIR__ . '/core/class-wre-cron.php';
    require_once __DIR__ . '/core/class-wre-email-queue.php';
    require_once __DIR__ . '/core/class-wre-logger.php';

    if ( function_exists( 'wp_timezone_string' ) ) {
        date_default_timezone_set( wp_timezone_string() );
    }

    if ( class_exists( 'WRE_Core' ) ) {
        WRE_Core::boot();
    }
}

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'core/class-wre-core.php';

add_action(
    'plugins_loaded',
    static function () {
        if ( function_exists( 'wp_timezone_string' ) ) {
            date_default_timezone_set( wp_timezone_string() );
        }

        if ( class_exists( 'WRE_Core' ) ) {
            WRE_Core::boot( __FILE__ );
        }
    }
);
