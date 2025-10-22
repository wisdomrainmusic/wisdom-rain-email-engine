<?php
/**
 * Transactional email sender for the Wisdom Rain Email Engine plugin.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Email_Sender' ) ) {
    /**
     * Provides helper methods for dispatching HTML transactional emails.
     */
    class WRE_Email_Sender {
        /**
         * Option key used to persist verification tokens for users.
         */
        const META_VERIFY_TOKEN = '_wre_verify_token';

        /**
         * Register action hooks required for the email sender.
         */
        public static function init() {
            add_action( 'wre_send_welcome_verify', array( __CLASS__, 'send_welcome_verify' ), 10, 1 );
        }

        /**
         * Send the welcome verification email to a user by ID.
         *
         * @param int $user_id WordPress user identifier.
         *
         * @return bool True if the email is dispatched, false otherwise.
         */
        public static function send_welcome_verify( $user_id ) {
            $user_id = absint( $user_id );

            if ( $user_id <= 0 ) {
                return false;
            }

            $user = get_userdata( $user_id );

            if ( ! $user || empty( $user->user_email ) ) {
                return false;
            }

            $email = sanitize_email( $user->user_email );

            if ( empty( $email ) ) {
                return false;
            }

            $token      = self::generate_token( $user_id );
            $verify_url = self::build_verification_link( $user_id, $token );

            $subject = __( 'Verify your Wisdom Rain email', 'wisdom-rain-email-engine' );

            $placeholders = array(
                'EMAIL_TITLE'       => sprintf(
                    /* translators: %s: User display name. */
                    __( 'Welcome, %s!', 'wisdom-rain-email-engine' ),
                    $user->display_name ? $user->display_name : $user->user_login
                ),
                'EMAIL_BODY'        => __( 'Please confirm your email address to activate your Wisdom Rain experience.', 'wisdom-rain-email-engine' ),
                'EMAIL_BUTTON_TEXT' => __( 'Verify Email', 'wisdom-rain-email-engine' ),
                'EMAIL_BUTTON_LINK' => $verify_url,
            );

            $body = self::render_template( 'email-welcome-verify', $placeholders );

            if ( empty( $body ) ) {
                return false;
            }

            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
                'From: Wisdom Rain <no-reply@wisdomrainbookmusic.com>',
            );

            return wp_mail( $email, $subject, $body, $headers );
        }

        /**
         * Generate and persist a unique verification token for a user.
         *
         * @param int $user_id WordPress user identifier.
         *
         * @return string Token hash stored for verification.
         */
        protected static function generate_token( $user_id ) {
            $raw_token = implode( '|', array( $user_id, time(), wp_generate_password( 20, false ) ) );
            $token     = wp_hash( $raw_token );

            update_user_meta(
                $user_id,
                self::META_VERIFY_TOKEN,
                array(
                    'token'     => $token,
                    'generated' => current_time( 'timestamp', true ),
                )
            );

            return $token;
        }

        /**
         * Build the verification URL for the provided token and user.
         *
         * @param int    $user_id WordPress user identifier.
         * @param string $token   Verification token hash.
         *
         * @return string
         */
        protected static function build_verification_link( $user_id, $token ) {
            $verify_endpoint = self::get_verify_endpoint_url();

            $args = array(
                'user'  => $user_id,
                'token' => rawurlencode( $token ),
            );

            $args = apply_filters( 'wre_verify_query_args', $args, $user_id );

            return add_query_arg( $args, $verify_endpoint );
        }

        /**
         * Retrieve the verification endpoint URL, allowing overrides via filter.
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
         * Render the HTML template for transactional emails using placeholders.
         *
         * @param array $placeholders Template replacement values.
         *
         * @return string
         */
        protected static function render_template( $template, $placeholders ) {
            if ( ! defined( 'WRE_PATH' ) ) {
                return '';
            }

            $defaults = array(
                'EMAIL_TITLE'       => '',
                'EMAIL_BODY'        => '',
                'EMAIL_BUTTON_TEXT' => '',
                'EMAIL_BUTTON_LINK' => '',
            );

            $context = wp_parse_args( $placeholders, $defaults );

            $template_slug = sanitize_key( basename( $template ) );
            $template_path = trailingslashit( WRE_PATH ) . 'templates/emails/' . $template_slug . '.php';

            if ( ! file_exists( $template_path ) ) {
                return sprintf(
                    '<h1>%1$s</h1><p>%2$s</p>',
                    esc_html( $context['EMAIL_TITLE'] ),
                    esc_html( $context['EMAIL_BODY'] )
                );
            }

            ob_start();

            // Expose placeholders as template variables while preserving array access.
            $data = $context;
            foreach ( $context as $key => $value ) {
                if ( ! isset( ${$key} ) ) {
                    ${$key} = $value;
                }
            }

            include $template_path;

            $contents = ob_get_clean();

            return is_string( $contents ) ? trim( $contents ) : '';
        }
    }
}
