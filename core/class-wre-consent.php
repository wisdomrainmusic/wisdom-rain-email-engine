<?php
/**
 * Consent and unsubscribe management for Wisdom Rain Email Engine.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Consent' ) ) {
    /**
     * Handles marketing consent tracking and unsubscribe links.
     */
    class WRE_Consent {
        const META_OPT_OUT = '_wre_marketing_opt_out';
        const META_TOKEN   = '_wre_unsubscribe_token';

        /**
         * Register runtime hooks for consent handling.
         */
        public static function init() {
            add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_unsubscribe' ) );
        }

        /**
         * Determine if the user has opted out of marketing messages.
         *
         * @param int $user_id WordPress user identifier.
         *
         * @return bool
         */
        public static function has_marketing_consent( $user_id ) {
            $user_id = absint( $user_id );

            if ( $user_id <= 0 ) {
                return false;
            }

            $opt_out = get_user_meta( $user_id, self::META_OPT_OUT, true );

            return empty( $opt_out );
        }

        /**
         * Retrieve the unsubscribe URL for the given user, generating a token if necessary.
         *
         * @param int $user_id WordPress user identifier.
         *
         * @return string
         */
        public static function get_unsubscribe_url( $user_id ) {
            $user_id = absint( $user_id );

            if ( $user_id <= 0 ) {
                return '';
            }

            $token = self::ensure_token( $user_id );

            if ( '' === $token ) {
                return '';
            }

            $endpoint = apply_filters( 'wre_unsubscribe_endpoint', home_url( '/unsubscribe' ) );

            if ( ! is_string( $endpoint ) || '' === trim( $endpoint ) ) {
                $endpoint = home_url( '/unsubscribe' );
            }

            $args = array(
                'u' => $user_id,
                't' => rawurlencode( $token ),
            );

            $args = apply_filters( 'wre_unsubscribe_query_args', $args, $user_id );

            return add_query_arg( $args, $endpoint );
        }

        /**
         * Handle requests to the unsubscribe endpoint.
         */
        public static function maybe_handle_unsubscribe() {
            if ( ! self::is_unsubscribe_request() ) {
                return;
            }

            $user_id = absint( filter_input( INPUT_GET, 'u', FILTER_SANITIZE_NUMBER_INT ) );
            $token   = filter_input( INPUT_GET, 't', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
            $token   = is_string( $token ) ? sanitize_text_field( $token ) : '';

            if ( $user_id <= 0 || '' === $token ) {
                self::render_response( false );
            }

            $stored = get_user_meta( $user_id, self::META_TOKEN, true );
            $saved_token = '';

            if ( is_array( $stored ) && isset( $stored['token'] ) ) {
                $saved_token = (string) $stored['token'];
            } elseif ( is_string( $stored ) ) {
                $saved_token = $stored;
            }

            if ( '' === $saved_token || ! hash_equals( $saved_token, $token ) ) {
                self::render_response( false );
            }

            update_user_meta( $user_id, self::META_OPT_OUT, current_time( 'timestamp', true ) );

            if ( class_exists( '\\WRE_Logger' ) ) {
                \WRE_Logger::add( sprintf( 'User #%d unsubscribed from marketing emails.', $user_id ) );
            }

            /**
             * Fires when a user successfully unsubscribes from marketing emails.
             *
             * @param int $user_id WordPress user identifier.
             */
            do_action( 'wre_unsubscribe_success', $user_id );

            self::rotate_token( $user_id );
            self::render_response( true, $user_id );
        }

        /**
         * Provide a basic HTML response or redirect after unsubscribe handling.
         *
         * @param bool $success Whether the unsubscribe action succeeded.
         * @param int  $user_id User identifier related to the request.
         */
        protected static function render_response( $success, $user_id = 0 ) {
            $redirect = apply_filters( 'wre_unsubscribe_redirect', '', $success, $user_id );

            if ( is_string( $redirect ) && '' !== trim( $redirect ) ) {
                wp_safe_redirect( $redirect );
                exit;
            }

            $title   = $success ? __( 'Unsubscribed', 'wisdom-rain-email-engine' ) : __( 'Unable to unsubscribe', 'wisdom-rain-email-engine' );
            $message = $success
                ? __( 'Your preferences have been updated. You will no longer receive marketing messages.', 'wisdom-rain-email-engine' )
                : __( 'We could not verify your request. Please contact support if the issue persists.', 'wisdom-rain-email-engine' );

            wp_die( wp_kses_post( '<p>' . esc_html( $message ) . '</p>' ), esc_html( $title ) );
        }

        /**
         * Confirm whether the current request is targeting the unsubscribe endpoint.
         *
         * @return bool
         */
        protected static function is_unsubscribe_request() {
            if ( ! filter_has_var( INPUT_GET, 'u' ) || ! filter_has_var( INPUT_GET, 't' ) ) {
                return false;
            }

            $request_uri  = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
            $request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
            $endpoint     = apply_filters( 'wre_unsubscribe_endpoint', home_url( '/unsubscribe' ) );
            $endpoint_path = wp_parse_url( $endpoint, PHP_URL_PATH );

            if ( empty( $request_path ) || empty( $endpoint_path ) ) {
                return false;
            }

            return untrailingslashit( $request_path ) === untrailingslashit( $endpoint_path );
        }

        /**
         * Ensure a unique unsubscribe token exists for the user.
         *
         * @param int $user_id WordPress user identifier.
         *
         * @return string
         */
        protected static function ensure_token( $user_id ) {
            $stored = get_user_meta( $user_id, self::META_TOKEN, true );

            if ( is_array( $stored ) && ! empty( $stored['token'] ) ) {
                return (string) $stored['token'];
            }

            if ( is_string( $stored ) && '' !== $stored ) {
                return $stored;
            }

            return self::rotate_token( $user_id );
        }

        /**
         * Generate and store a new unsubscribe token for the user.
         *
         * @param int $user_id WordPress user identifier.
         *
         * @return string
         */
        protected static function rotate_token( $user_id ) {
            $user_id = absint( $user_id );

            if ( $user_id <= 0 ) {
                return '';
            }

            $raw   = implode( '|', array( $user_id, wp_generate_password( 20, false ), microtime( true ) ) );
            $token = wp_hash( $raw );

            update_user_meta(
                $user_id,
                self::META_TOKEN,
                array(
                    'token'     => $token,
                    'generated' => current_time( 'timestamp', true ),
                )
            );

            return $token;
        }
    }
}
