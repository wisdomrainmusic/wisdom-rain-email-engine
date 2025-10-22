<?php
/**
 * Admin UI for the Wisdom Rain Email Engine plugin.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Admin' ) ) {
    /**
     * Handles the plugin admin dashboard experience.
     */
    class WRE_Admin {
        const MENU_SLUG = 'wre-dashboard';

        /**
         * Register admin hooks for the dashboard UI.
         */
        public static function init() {
            if ( ! is_admin() ) {
                return;
            }

            add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        }

        /**
         * Register the top-level admin menu for the plugin.
         */
        public static function register_menu() {
            add_menu_page(
                __( 'WRE Email Engine', 'wisdom-rain-email-engine' ),
                __( 'WRE Email', 'wisdom-rain-email-engine' ),
                'manage_options',
                self::MENU_SLUG,
                array( __CLASS__, 'render_admin_page' ),
                'dashicons-email-alt2',
                25
            );
        }

        /**
         * Render the admin dashboard container with placeholder sections.
         */
        public static function render_admin_page() {
            $sections = array(
                __( 'Templates', 'wisdom-rain-email-engine' ),
                __( 'Preview', 'wisdom-rain-email-engine' ),
                __( 'Test Send', 'wisdom-rain-email-engine' ),
                __( 'Campaigns', 'wisdom-rain-email-engine' ),
            );
            ?>
            <div class="wrap wre-admin">
                <h1><?php esc_html_e( '📧 Wisdom Rain Email Engine', 'wisdom-rain-email-engine' ); ?></h1>

                <p><?php esc_html_e( 'WRE Admin Module loaded successfully.', 'wisdom-rain-email-engine' ); ?></p>

                <h2><?php esc_html_e( 'Available Sections', 'wisdom-rain-email-engine' ); ?></h2>
                <ul class="wre-admin__sections">
                    <?php foreach ( $sections as $section ) : ?>
                        <li><?php echo esc_html( $section ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
        }
    }
}
