<?php
/**
 * Verify Handler for WRE
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Verify' ) ) {
    /**
     * Handles GET /verify requests to confirm user email addresses.
     */
    class WRE_Verify {
        /**
         * Meta key storing the verification token for a user.
         */
        const META_VERIFY_TOKEN = '_wre_verify_token';

        /**
         * Meta key storing the timestamp when a user verified their email.
         */
        const META_VERIFIED_FLAG = '_wre_verified';

        /**
         * Wire up the request handler.
         */
        public static function init() {
            add_action( 'init', array( __CLASS__, 'handle_verify_link' ) );
        }

        /**
         * Handle /verify endpoint requests with user and token query args.
         */
        public static function handle_verify_link() {
            if ( ! self::is_verify_request() ) {
                return;
            }

            $user_id   = absint( filter_input( INPUT_GET, 'user', FILTER_SANITIZE_NUMBER_INT ) );
            $token_raw = filter_input( INPUT_GET, 'token', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
            $token     = is_string( $token_raw ) ? sanitize_text_field( $token_raw ) : '';

            if ( $user_id <= 0 || '' === $token ) {
                self::render_error();
            }

            $saved = get_user_meta( $user_id, self::META_VERIFY_TOKEN, true );

            $saved_token = '';
            $generated   = 0;

            if ( is_array( $saved ) ) {
                if ( isset( $saved['token'] ) ) {
                    $saved_token = (string) $saved['token'];
                }

                if ( isset( $saved['generated'] ) ) {
                    $generated = absint( $saved['generated'] );
                }
            } elseif ( is_string( $saved ) ) {
                $saved_token = $saved;
            }

            if ( '' === $saved_token || ! hash_equals( $saved_token, $token ) || ! self::is_token_fresh( $generated ) ) {
                self::render_error();
            }

            update_user_meta( $user_id, self::META_VERIFIED_FLAG, current_time( 'timestamp', true ) );
            delete_user_meta( $user_id, self::META_VERIFY_TOKEN );

            self::maybe_login_user( $user_id );

            /**
             * Fires after a user successfully verifies their email address.
             *
             * @param int $user_id Verified user identifier.
             */
            do_action( 'wre_verify_success', $user_id );

            $redirect = apply_filters( 'wre_verify_redirect', home_url( '/' ), $user_id );
            $redirect = is_string( $redirect ) ? $redirect : home_url( '/' );

            wp_safe_redirect( $redirect );
            exit;
        }

        /**
         * Output an HTML error message for invalid tokens.
         */
        protected static function render_error() {
            $message = '<h2>' . esc_html__( 'Verification failed', 'wisdom-rain-email-engine' ) . '</h2>' .
                '<p>' . esc_html__( 'The link is invalid or expired. Please request a new verification email.', 'wisdom-rain-email-engine' ) . '</p>';

            $message = apply_filters( 'wre_verify_error_message', $message );

            wp_die(
                wp_kses_post( $message ),
                esc_html__( 'Verification Error', 'wisdom-rain-email-engine' ),
                array( 'response' => 403 )
            );
        }

        /**
         * Determine if the current request should trigger verification handling.
         *
         * @return bool
         */
        protected static function is_verify_request() {
            if ( is_admin() && ! wp_doing_ajax() ) {
                return false;
            }

            if ( ! filter_has_var( INPUT_GET, 'user' ) || ! filter_has_var( INPUT_GET, 'token' ) ) {
                return false;
            }

            $request_uri  = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
            $request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
            $verify_path  = wp_parse_url( self::get_verify_endpoint_url(), PHP_URL_PATH );

            if ( empty( $request_path ) || empty( $verify_path ) ) {
                return true;
            }

            return untrailingslashit( $request_path ) === untrailingslashit( $verify_path );
        }

        /**
         * Confirm that a stored token is still within its allowed lifetime.
         *
         * @param int $generated Unix timestamp when the token was generated.
         *
         * @return bool
         */
        protected static function is_token_fresh( $generated ) {
            $lifetime = apply_filters( 'wre_verify_token_ttl', DAY_IN_SECONDS * 2 );
            $lifetime = absint( $lifetime );

            if ( $lifetime <= 0 || $generated <= 0 ) {
                return true;
            }

            $now = current_time( 'timestamp', true );

            return ( $now - $generated ) <= $lifetime;
        }

        /**
         * Attempt to authenticate the user after successful verification when allowed.
         *
         * @param int $user_id Verified user identifier.
         */
        protected static function maybe_login_user( $user_id ) {
            $default_should_login = ! is_user_logged_in();
            $should_login         = apply_filters( 'wre_verify_autologin', $default_should_login, $user_id );

            if ( ! $should_login || is_user_logged_in() ) {
                return;
            }

            wp_set_auth_cookie( $user_id );
        }

        /**
         * Retrieve the verification endpoint URL for request checks.
         *
         * @return string
         */
        protected static function get_verify_endpoint_url() {
            $default = home_url( '/verify' );
            $endpoint = apply_filters( 'wre_verify_endpoint', $default );

            if ( ! is_string( $endpoint ) || '' === trim( $endpoint ) ) {
                return $default;
            }

            return $endpoint;
        }
    }
}
