<?php
/**
 * Plugin Name: Wisdom Rain Email Engine
 * Plugin URI:  https://wisdomrainbookmusic.com
 * Description: Email & Verification Engine for Wisdom Rain Platform.
 * Version:     1.0.0
 * Author:      Wisdom Rain Team
 * Text Domain: wisdom-rain-email-engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI && function_exists( 'wp_timezone_string' ) ) {
    $timezone_string = wp_timezone_string();

    if ( $timezone_string ) {
        date_default_timezone_set( $timezone_string );
    }
}

require_once plugin_dir_path( __FILE__ ) . 'core/class-wre-core.php';

WRE_Core::boot( __FILE__ );
