<?php
/**
 * Admin notice integration for the Wisdom Rain Email Engine plugin.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! class_exists( 'WRE_Admin_Notices' ) ) {
    /**
     * Handles administrative hooks such as notices raised by CLI commands.
     */
    class WRE_Admin_Notices {
        const NOTICE_OPTION = 'wre_codex_notice';

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
