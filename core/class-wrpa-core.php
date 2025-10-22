<?php
/**
 * Core loader for the Wisdom Rain Email Engine plugin.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! class_exists( 'WRPA_Core' ) ) {
    /**
     * Bootstrap class for initializing plugin functionality.
     */
    class WRPA_Core {
        /**
         * Initialize the plugin by wiring dependencies and hooks.
         */
        public static function init() {
            self::include_files();
            self::register_hooks();
            self::register_cli_commands();
        }

        /**
         * Load the PHP files required for the plugin.
         */
        protected static function include_files() {
            require_once dirname( __DIR__ ) . '/admin/class-wrpa-admin.php';
            require_once __DIR__ . '/class-wrpa-codex-command.php';
        }

        /**
         * Register WordPress hooks for the plugin lifecycle.
         */
        protected static function register_hooks() {
            if ( is_admin() ) {
                \WRPA_Admin::init();
            }
        }

        /**
         * Register CLI integrations when available.
         */
        protected static function register_cli_commands() {
            if ( defined( 'WP_CLI' ) && WP_CLI ) {
                \WRPA_Codex_Command::register();
            }
        }
    }
}
