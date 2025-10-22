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
            add_action( 'wre_send_verify_reminder', array( __CLASS__, 'send_verify_reminder' ), 10, 1 );
            add_action( 'wre_send_plan_reminder', array( __CLASS__, 'send_plan_reminder' ), 10, 2 );
            add_action( 'wre_send_comeback', array( __CLASS__, 'send_comeback_email' ), 10, 2 );
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

            $display_name = $user->display_name ? $user->display_name : $user->user_login;

            $placeholders = array(
                'recipient_name'   => sanitize_text_field( $display_name ),
                'verification_url' => esc_url( $verify_url ),
            );

            $body = self::render_template( 'welcome-verify', $placeholders );

            if ( empty( $body ) ) {
                return false;
            }

            return self::dispatch_email( 'welcome-verify', $user_id, $email, $subject, $body );
        }

        /**
         * Send a reminder email to users whose plan is approaching expiration.
         *
         * @param int $user_id        WordPress user identifier.
         * @param int $days_remaining Number of days remaining until expiration.
         *
         * @return bool True if email was dispatched, false otherwise.
         */
        public static function send_plan_reminder( $user_id, $days_remaining ) {
            $user_id        = absint( $user_id );
            $days_remaining = absint( $days_remaining );

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

            $display_name = $user->display_name ? $user->display_name : $user->user_login;
            $days_remaining = max( 1, $days_remaining );

            $subject = sprintf(
                _n(
                    'Your Wisdom Rain plan ends in %d day',
                    'Your Wisdom Rain plan ends in %d days',
                    $days_remaining,
                    'wisdom-rain-email-engine'
                ),
                $days_remaining
            );

            if ( 1 === $days_remaining ) {
                $subject = __( 'Your Wisdom Rain plan ends tomorrow', 'wisdom-rain-email-engine' );
            }

            $expiry_timestamp = absint( apply_filters( 'wre_plan_expiration_timestamp', 0, $user_id, $days_remaining ) );
            $plan_name        = apply_filters( 'wre_plan_name', '', $user_id, $days_remaining );

            $placeholders = array(
                'recipient_name' => sanitize_text_field( $display_name ),
                'days_remaining' => $days_remaining,
                'plan_name'      => is_string( $plan_name ) ? $plan_name : '',
                'expiry_date'    => $expiry_timestamp > 0 ? date_i18n( get_option( 'date_format' ), $expiry_timestamp ) : '',
            );

            $placeholders = apply_filters( 'wre_plan_reminder_placeholders', $placeholders, $user_id, $days_remaining );

            $body = self::render_template( 'plan-reminder', $placeholders );

            if ( '' === $body ) {
                return false;
            }

            return self::dispatch_email( 'plan-reminder', $user_id, $email, $subject, $body );
        }

        /**
         * Send a comeback campaign email to users whose plan expired 30 days ago.
         *
         * @param int $user_id           WordPress user identifier.
         * @param int $days_since_expiry Number of days since the plan expired.
         *
         * @return bool True if email was dispatched, false otherwise.
         */
        public static function send_comeback_email( $user_id, $days_since_expiry = 0 ) {
            $user_id           = absint( $user_id );
            $days_since_expiry = absint( $days_since_expiry );

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

            $display_name = $user->display_name ? $user->display_name : $user->user_login;

            $subject = __( 'We miss you at Wisdom Rain', 'wisdom-rain-email-engine' );

            $placeholders = array(
                'recipient_name'    => sanitize_text_field( $display_name ),
                'days_since_expiry' => $days_since_expiry > 0 ? $days_since_expiry : '',
            );

            $placeholders = apply_filters( 'wre_comeback_placeholders', $placeholders, $user_id, $days_since_expiry );

            $body = self::render_template( 'comeback', $placeholders );

            if ( '' === $body ) {
                return false;
            }

            return self::dispatch_email( 'comeback', $user_id, $email, $subject, $body );
        }

        /**
         * Send a reminder prompting the user to verify their account.
         *
         * @param int $user_id WordPress user identifier.
         *
         * @return bool True if the email is dispatched, false otherwise.
         */
        public static function send_verify_reminder( $user_id ) {
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

            $token_data = get_user_meta( $user_id, self::META_VERIFY_TOKEN, true );

            $token = '';

            if ( is_array( $token_data ) && isset( $token_data['token'] ) ) {
                $token = (string) $token_data['token'];
            } elseif ( is_string( $token_data ) ) {
                $token = $token_data;
            }

            if ( '' === $token ) {
                $token = self::generate_token( $user_id );
            }

            $verify_url = self::build_verification_link( $user_id, $token );

            $subject = __( 'Reminder: verify your Wisdom Rain email', 'wisdom-rain-email-engine' );

            $display_name = $user->display_name ? $user->display_name : $user->user_login;

            $placeholders = array(
                'recipient_name'   => sanitize_text_field( $display_name ),
                'verification_url' => esc_url( $verify_url ),
            );

            $body = self::render_template( 'verify-reminder', $placeholders );

            if ( '' === $body ) {
                $body = self::render_template( 'welcome-verify', $placeholders );
            }

            if ( '' === $body ) {
                return false;
            }

            return self::dispatch_email( 'verify-reminder', $user_id, $email, $subject, $body );
        }

        /**
         * Dispatch an email and record the outcome for logging purposes.
         *
         * @param string $template Template identifier.
         * @param int    $user_id  WordPress user identifier.
         * @param string $email    Recipient email address.
         * @param string $subject  Email subject line.
         * @param string $body     Email body content.
         *
         * @return bool
         */
        protected static function dispatch_email( $template, $user_id, $email, $subject, $body ) {
            if ( '' === $body ) {
                return false;
            }

            $result = wp_mail( $email, $subject, $body, self::get_default_headers() );

            if ( class_exists( 'WRE_Logger' ) ) {
                $template = sanitize_key( $template );
                $user_id  = absint( $user_id );

                if ( $result ) {
                    $message = sprintf( 'Email "%s" sent for user #%d.', $template, $user_id );
                    $type    = 'sent';
                } else {
                    $message = sprintf( 'Email "%s" failed for user #%d.', $template, $user_id );
                    $type    = 'failed';
                }

                \WRE_Logger::add( $message, $type );
            }

            return (bool) $result;
        }

        /**
         * Retrieve default headers used for outbound HTML emails.
         *
         * @return array
         */
        protected static function get_default_headers() {
            return array(
                'Content-Type: text/html; charset=UTF-8',
                'From: Wisdom Rain <no-reply@wisdomrainbookmusic.com>',
            );
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
            if ( ! class_exists( '\WRE_Templates' ) ) {
                return '';
            }

            return \WRE_Templates::render_template( $template, $placeholders );
        }
    }
}
