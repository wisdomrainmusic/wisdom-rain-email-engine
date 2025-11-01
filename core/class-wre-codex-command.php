<?php
/**
 * WP-CLI command registration for the Wisdom Rain Email Engine plugin.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! class_exists( 'WRE_Codex_Command' ) ) {
    /**
     * Registers Codex test commands with WP-CLI.
     */
    class WRE_Codex_Command {
        /**
         * Register the command with WP-CLI.
         */
        public static function register() {
            if ( ! class_exists( '\\WP_CLI' ) ) {
                return;
            }

            \WP_CLI::add_command( 'codex test', array( __CLASS__, 'handle_test_command' ) );
        }

        /**
         * Handle the "codex test" command.
         */
        public static function handle_test_command() {
            update_option( \WRE_Admin_Notices::NOTICE_OPTION, __( 'Hello Email Engine', 'wisdom-rain-email-engine' ) );

            \WP_CLI::success( 'Hello Email Engine notice scheduled for display.' );
        }
    }
}
