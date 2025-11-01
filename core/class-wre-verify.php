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
        const META_VERIFIED_FLAG = 'wre_email_verified';

        /**
         * Wire up the request handler.
         */
        public static function init() {
            add_action( 'init', array( __CLASS__, 'handle_verify_link' ) );
            add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_unverified_user' ), 0 );
            add_filter( 'template_include', array( __CLASS__, 'maybe_render_verify_required_template' ) );
            add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_verify_required_assets' ) );
            add_action( 'wp_ajax_wre_resend_verification', array( __CLASS__, 'handle_resend_verification' ) );
        }

        /**
         * Send the welcome + verification email to a newly registered user.
         *
         * @param int $user_id Newly created WordPress user identifier.
         *
         * @return bool Whether the verification email was dispatched.
         */
        public static function send_verification_email( $user_id, $mode = 'auto' ) {
            $user_id = absint( $user_id );

            if ( $user_id <= 0 ) {
                return false;
            }

            $mode = in_array( $mode, array( 'instant', 'queue', 'auto' ), true ) ? $mode : 'auto';
            $dispatched = false;

            if ( 'instant' === $mode && class_exists( 'WRE_Email_Sender' ) ) {
                $dispatched = \WRE_Email_Sender::send_welcome_verify( $user_id, 'instant' );
            }

            if ( ! $dispatched && 'instant' !== $mode && class_exists( 'WRE_Email_Queue' ) ) {
                $dispatched = \WRE_Email_Queue::add_to_queue( $user_id, 'welcome-verify' );
            }

            if ( ! $dispatched && class_exists( 'WRE_Email_Sender' ) ) {
                $fallback_mode = ( 'instant' === $mode ) ? 'instant' : 'standard';
                $dispatched    = \WRE_Email_Sender::send_welcome_verify( $user_id, $fallback_mode );
            }

            if ( ! $dispatched && class_exists( 'WRE_Logger' ) ) {
                \WRE_Logger::add(
                    sprintf( 'Failed to dispatch verification email for user #%d.', $user_id ),
                    'verify'
                );
            }

            return (bool) $dispatched;
        }

        /**
         * Convenience wrapper for instant verification dispatches triggered on signup.
         *
         * @param int $user_id WordPress user identifier.
         *
         * @return bool
         */
        public static function send_verification_email_instant( $user_id ) {
            return self::send_verification_email( $user_id, 'instant' );
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

            self::mark_user_verified( $user_id );

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
         * Determine whether the current request is targeting the verify required endpoint.
         *
         * @return bool
         */
        protected static function is_verify_required_request() {
            $request_uri  = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
            $request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
            $verify_path  = wp_parse_url( self::get_verify_required_url(), PHP_URL_PATH );

            if ( empty( $request_path ) || empty( $verify_path ) ) {
                return false;
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
         * Evaluate whether the current user has verified their email address.
         *
         * @param int $user_id User identifier.
         *
         * @return bool
         */
        protected static function is_user_verified( $user_id ) {
            $user_id = absint( $user_id );

            if ( $user_id <= 0 ) {
                return false;
            }

            $flag = get_user_meta( $user_id, self::META_VERIFIED_FLAG, true );

            if ( is_numeric( $flag ) ) {
                return absint( $flag ) > 0;
            }

            if ( is_bool( $flag ) ) {
                return (bool) $flag;
            }

            if ( is_string( $flag ) && '' !== trim( $flag ) ) {
                return true;
            }

            $legacy_flag = get_user_meta( $user_id, '_wre_verified', true );

            if ( is_numeric( $legacy_flag ) && absint( $legacy_flag ) > 0 ) {
                update_user_meta( $user_id, self::META_VERIFIED_FLAG, absint( $legacy_flag ) );
                delete_user_meta( $user_id, '_wre_verified' );

                return true;
            }

            if ( is_string( $legacy_flag ) && '' !== trim( $legacy_flag ) ) {
                update_user_meta( $user_id, self::META_VERIFIED_FLAG, current_time( 'timestamp', true ) );
                delete_user_meta( $user_id, '_wre_verified' );

                return true;
            }

            return false;
        }

        /**
         * Mark the given user as verified and clean up legacy metadata.
         *
         * @param int $user_id User identifier.
         */
        protected static function mark_user_verified( $user_id ) {
            update_user_meta( $user_id, self::META_VERIFIED_FLAG, current_time( 'timestamp', true ) );
            delete_user_meta( $user_id, self::META_VERIFY_TOKEN );
            delete_user_meta( $user_id, '_wre_verified' );
        }

        /**
         * Determine whether unverified users are allowed to view the current request.
         *
         * @return bool
         */
        protected static function is_unverified_request_allowed() {
            $request_uri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );

            if ( empty( $request_uri ) ) {
                return false;
            }

            $allowed_substrings = array(
                'wp-login.php',
                'wp-cron.php',
                'wp-json',
                'logout',
            );

            foreach ( $allowed_substrings as $allowed ) {
                if ( false !== strpos( $request_uri, $allowed ) ) {
                    return true;
                }
            }

            /**
             * Filter whether the request should bypass the verification redirect.
             *
             * @param bool   $allowed     Whether the request is allowed.
             * @param string $request_uri Current request URI.
             */
            return (bool) apply_filters( 'wre_verify_allow_unverified_request', false, $request_uri );
        }

        /**
         * Redirect unverified logged-in users to the verification required screen.
         */
        public static function maybe_redirect_unverified_user() {
            if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
                return;
            }

            if ( ! is_user_logged_in() ) {
                return;
            }

            $user_id = get_current_user_id();

            // Administrators retain access even if their email is unverified to prevent lockouts.
            if ( current_user_can( 'manage_options' ) ) {
                return;
            }

            if ( self::is_user_verified( $user_id ) ) {
                if ( self::is_verify_required_request() ) {
                    wp_safe_redirect( apply_filters( 'wre_verify_redirect', home_url( '/' ), $user_id ) );
                    exit;
                }

                return;
            }

            if ( self::is_verify_request() || self::is_verify_required_request() ) {
                return;
            }

            if ( self::is_unverified_request_allowed() ) {
                return;
            }

            wp_safe_redirect( self::get_verify_required_url() );
            exit;
        }

        /**
         * Replace the template for the verification required screen when needed.
         *
         * @param string $template Current template path.
         *
         * @return string
         */
        public static function maybe_render_verify_required_template( $template ) {
            if ( ! self::is_verify_required_request() ) {
                return $template;
            }

            $plugin_template = trailingslashit( WRE_PATH ) . 'templates/verify-required.php';

            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }

            return $template;
        }

        /**
         * Enqueue front-end assets for the verification required page.
         */
        public static function enqueue_verify_required_assets() {
            if ( ! self::is_verify_required_request() ) {
                return;
            }

            wp_enqueue_style( 'wre-verify-required', trailingslashit( WRE_URL ) . 'assets/css/verify-required.css', array(), WRE_VERSION );

            wp_enqueue_script( 'wre-verify-required', trailingslashit( WRE_URL ) . 'assets/js/verify-required.js', array(), WRE_VERSION, true );

            wp_localize_script(
                'wre-verify-required',
                'wreVerifyRequired',
                array(
                    'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( 'wre_resend_verification' ),
                    'messages' => array(
                        'sending'  => esc_html__( 'Sending verification email…', 'wisdom-rain-email-engine' ),
                        'success'  => esc_html__( 'A new verification email is on its way!', 'wisdom-rain-email-engine' ),
                        'failure'  => esc_html__( 'We were unable to resend the verification email. Please try again later.', 'wisdom-rain-email-engine' ),
                        'verified' => esc_html__( 'Your email is already verified. You can continue to the homepage.', 'wisdom-rain-email-engine' ),
                        'error'    => esc_html__( 'Something went wrong. Please refresh and try again.', 'wisdom-rain-email-engine' ),
                    ),
                )
            );
        }

        /**
         * Handle AJAX requests to resend the verification email.
         */
        public static function handle_resend_verification() {
            check_ajax_referer( 'wre_resend_verification', 'nonce' );

            if ( ! is_user_logged_in() ) {
                wp_send_json_error(
                    array( 'message' => esc_html__( 'You need to be logged in to request another verification email.', 'wisdom-rain-email-engine' ) ),
                    403
                );
            }

            $user_id = get_current_user_id();

            if ( self::is_user_verified( $user_id ) ) {
                wp_send_json_success(
                    array( 'message' => esc_html__( 'Your email address is already verified.', 'wisdom-rain-email-engine' ) )
                );
            }

            $sent = self::send_verification_email( $user_id );

            if ( ! $sent ) {
                wp_send_json_error(
                    array( 'message' => esc_html__( 'Unable to send a new verification email.', 'wisdom-rain-email-engine' ) ),
                    500
                );
            }

            wp_send_json_success(
                array( 'message' => esc_html__( 'Verification email dispatched.', 'wisdom-rain-email-engine' ) )
            );
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

        /**
         * Retrieve the verify required URL for redirects and template detection.
         *
         * @return string
         */
        protected static function get_verify_required_url() {
            $default = home_url( '/verify-required/' );
            $url     = apply_filters( 'wre_verify_required_url', $default );

            if ( ! is_string( $url ) || '' === trim( $url ) ) {
                return $default;
            }

            return $url;
        }
    }
}
