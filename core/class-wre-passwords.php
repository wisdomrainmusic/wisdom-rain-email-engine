<?php
/**
 * Password reset email formatter for Wisdom Rain Email Engine.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Passwords' ) ) {
    /**
     * Replaces the core password reset email with a branded HTML template.
     */
    class WRE_Passwords {
        /**
         * Register hooks for password reset notifications.
         */
        public static function init() {
            add_filter( 'retrieve_password_notification_email', array( __CLASS__, 'filter_notification_email' ), 10, 3 );
        }

        /**
         * Swap the default password reset email for the Wisdom Rain template.
         *
         * @param array   $wp_email   Email arguments prepared by WordPress.
         * @param string  $user_login User login identifier.
         * @param WP_User $user_data  WordPress user object.
         *
         * @return array
         */
        public static function filter_notification_email( $wp_email, $user_login, $user_data ) {
            if ( ! is_array( $wp_email ) ) {
                return $wp_email;
            }

            if ( ! isset( $wp_email['message'] ) || '' === trim( $wp_email['message'] ) ) {
                return $wp_email;
            }

            $reset_url = self::extract_reset_url( $wp_email['message'] );

            if ( '' === $reset_url ) {
                return $wp_email;
            }

            if ( ! class_exists( '\\WRE_Templates' ) ) {
                return $wp_email;
            }

            $display_name = '';

            if ( $user_data instanceof WP_User ) {
                $display_name = $user_data->display_name ? $user_data->display_name : $user_data->user_login;
            }

            $placeholders = array(
                'recipient_name' => sanitize_text_field( $display_name ),
                'reset_url'      => esc_url( $reset_url ),
                'reset_window'   => apply_filters( 'wre_password_reset_window', __( '24 hours', 'wisdom-rain-email-engine' ), $user_data ),
                'user_login'     => sanitize_text_field( $user_login ),
            );

            $message = \WRE_Templates::render_template( 'password-reset', $placeholders );

            if ( '' === $message ) {
                return $wp_email;
            }

            $wp_email['message'] = $message;
            $wp_email['headers'] = self::merge_headers( $wp_email );

            if ( class_exists( '\\WRE_Logger' ) && $user_data instanceof WP_User ) {
                \WRE_Logger::add(
                    sprintf( 'Formatted password reset email for user #%d.', absint( $user_data->ID ) ),
                    'instant'
                );
            }

            return $wp_email;
        }

        /**
         * Attempt to extract the reset URL from the default plaintext message.
         *
         * @param string $message Default WordPress email content.
         *
         * @return string
         */
        protected static function extract_reset_url( $message ) {
            if ( function_exists( 'wp_extract_urls' ) ) {
                $urls = wp_extract_urls( $message );

                if ( ! empty( $urls ) ) {
                    return (string) $urls[0];
                }
            }

            if ( preg_match( '/https?:\\/\\/[^\s]+/i', $message, $matches ) ) {
                return (string) $matches[0];
            }

            return '';
        }

        /**
         * Merge the required HTML headers with the defaults supplied by WordPress.
         *
         * @param array $wp_email Email arguments array.
         *
         * @return array
         */
        protected static function merge_headers( $wp_email ) {
            $headers = array();

            if ( isset( $wp_email['headers'] ) && ! empty( $wp_email['headers'] ) ) {
                $headers = is_array( $wp_email['headers'] ) ? $wp_email['headers'] : array( $wp_email['headers'] );
            }

            $headers[] = 'Content-Type: text/html; charset=UTF-8';

            $headers = array_unique( array_filter( array_map( 'trim', $headers ) ) );

            return $headers;
        }
    }
}
