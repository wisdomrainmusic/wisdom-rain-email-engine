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

/**
 * Define constants
 */
define( 'WRE_VERSION', '1.0.0' );
define( 'WRE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WRE_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Autoload core classes
 */
require_once WRE_PATH . 'core/class-wrpa-core.php';

/**
 * Initialize the plugin
 */
add_action( 'plugins_loaded', [ 'WRPA_Core', 'init' ] );
