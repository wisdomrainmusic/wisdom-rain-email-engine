<?php
/**
 * Admin-specific functionality for the Wisdom Rain Email Engine plugin.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! class_exists( 'WRPA_Admin' ) ) {
    /**
     * Handles administrative hooks such as notices.
     */
    class WRPA_Admin {
        const NOTICE_OPTION = 'wrpa_codex_notice';

        /**
         * Register admin hooks.
         */
        public static function init() {
            add_action( 'admin_notices', array( __CLASS__, 'maybe_render_cli_notice' ) );
        }

        /**
         * Render a queued notice from the Codex CLI command.
         */
        public static function maybe_render_cli_notice() {
            $message = get_option( self::NOTICE_OPTION );

            if ( empty( $message ) ) {
                return;
            }

            delete_option( self::NOTICE_OPTION );

            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html( $message )
            );
        }
    }
}
