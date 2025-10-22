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
            add_action( 'wre_send_subscription_expired', array( __CLASS__, 'send_subscription_expired_email' ), 10, 2 );
        }

        /**
         * Send the welcome verification email to a user by ID.
         *
         * @param int $user_id WordPress user identifier.
         *
         * @return bool True if the email is dispatched, false otherwise.
         */
        public static function send_welcome_verify( $user_id, $delivery_mode = 'standard' ) {
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

            $placeholders = self::add_unsubscribe_context( $placeholders, $user_id );

            $body = self::render_template( 'welcome-verify', $placeholders );

            if ( empty( $body ) ) {
                return false;
            }

            return self::dispatch_email( 'welcome-verify', $user_id, $email, $subject, $body, $delivery_mode, 'verify' );
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
            $placeholders = self::add_unsubscribe_context( $placeholders, $user_id );

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
            $placeholders = self::add_unsubscribe_context( $placeholders, $user_id );

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

            $placeholders = self::add_unsubscribe_context( $placeholders, $user_id );

            $body = self::render_template( 'verify-reminder', $placeholders );

            if ( '' === $body ) {
                $body = self::render_template( 'welcome-verify', $placeholders );
            }

            if ( '' === $body ) {
                return false;
            }

            return self::dispatch_email( 'verify-reminder', $user_id, $email, $subject, $body, 'standard', 'verify' );
        }

        /**
         * Send an order confirmation email using the payment receipt template.
         *
         * @param int|WC_Order $order         WooCommerce order identifier or instance.
         * @param string       $delivery_mode Delivery mode for logging context.
         *
         * @return bool
         */
        public static function send_order_confirmation_email( $order, $delivery_mode = 'instant' ) {
            if ( ! function_exists( 'wc_get_order' ) ) {
                return false;
            }

            $order = ( is_object( $order ) && is_a( $order, 'WC_Order' ) ) ? $order : wc_get_order( $order );

            if ( ! $order ) {
                return false;
            }

            $email = sanitize_email( $order->get_billing_email() );

            if ( '' === $email ) {
                return false;
            }

            $order_number = method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : $order->get_id();
            $order_date   = method_exists( $order, 'get_date_completed' ) ? $order->get_date_completed() : null;

            if ( ! $order_date && method_exists( $order, 'get_date_created' ) ) {
                $order_date = $order->get_date_created();
            }

            if ( $order_date && class_exists( 'WC_DateTime' ) && $order_date instanceof WC_DateTime ) {
                $order_date = wc_format_datetime( $order_date );
            } elseif ( $order_date instanceof \DateTimeInterface ) {
                $order_date = $order_date->format( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
            } else {
                $order_date = date_i18n( get_option( 'date_format' ), current_time( 'timestamp' ) );
            }

            $items = method_exists( $order, 'get_items' ) ? $order->get_items() : array();
            $item  = ! empty( $items ) ? reset( $items ) : false;

            $product_name = ( $item && method_exists( $item, 'get_name' ) ) ? $item->get_name() : '';

            if ( '' === $product_name ) {
                $product_name = __( 'your Wisdom Rain order', 'wisdom-rain-email-engine' );
            }

            $total = method_exists( $order, 'get_total' ) ? $order->get_total() : 0;

            if ( function_exists( 'wc_price' ) ) {
                $order_total = wc_price( $total, array( 'currency' => $order->get_currency() ) );
            } else {
                $order_total = wp_kses_post( number_format_i18n( $total, 2 ) );
            }

            $support_url = apply_filters( 'wre_support_url', home_url( '/account/' ), $order );

            $placeholders = array(
                'product_name' => sanitize_text_field( $product_name ),
                'order_number' => sanitize_text_field( (string) $order_number ),
                'order_date'   => sanitize_text_field( $order_date ),
                'order_total'  => $order_total,
                'support_url'  => esc_url( $support_url ),
            );

            $placeholders = apply_filters( 'wre_order_confirmation_placeholders', $placeholders, $order );

            $user_id = method_exists( $order, 'get_user_id' ) ? absint( $order->get_user_id() ) : 0;
            $placeholders = self::add_unsubscribe_context( $placeholders, $user_id );

            $subject = sprintf(
                /* translators: %s: order number */
                __( 'Your Wisdom Rain order %s is complete', 'wisdom-rain-email-engine' ),
                '#' . sanitize_text_field( (string) $order_number )
            );

            $body = self::render_template( 'payment-receipt', $placeholders );

            if ( '' === $body ) {
                return false;
            }

            return self::dispatch_email( 'payment-receipt', $user_id, $email, $subject, $body, $delivery_mode, 'order' );
        }

        /**
         * Send the trial expired email to a user.
         *
         * @param int   $user_id       WordPress user identifier.
         * @param array $context       Optional trial context.
         * @param string $delivery_mode Delivery mode for logging context.
         *
         * @return bool
         */
        public static function send_trial_expired_email( $user_id, $context = array(), $delivery_mode = 'instant' ) {
            $user_id = absint( $user_id );

            if ( $user_id <= 0 ) {
                return false;
            }

            $user = get_userdata( $user_id );

            if ( ! $user || empty( $user->user_email ) ) {
                return false;
            }

            $email = sanitize_email( $user->user_email );

            if ( '' === $email ) {
                return false;
            }

            $display_name = $user->display_name ? $user->display_name : $user->user_login;

            $plan_name = '';
            $renew_url = home_url( '/plans/' );
            $expired   = '';

            if ( is_array( $context ) ) {
                if ( isset( $context['plan_name'] ) ) {
                    $plan_name = sanitize_text_field( $context['plan_name'] );
                }

                if ( isset( $context['renew_url'] ) ) {
                    $renew_url = esc_url_raw( $context['renew_url'] );
                }

                $expired_timestamp = 0;

                if ( isset( $context['expired_at'] ) ) {
                    $expired_timestamp = absint( $context['expired_at'] );
                } elseif ( isset( $context['expired_date'] ) ) {
                    $expired_timestamp = absint( $context['expired_date'] );
                }

                if ( $expired_timestamp > 0 ) {
                    $expired = ' ' . sprintf(
                        /* translators: %s: localized date */
                        __( 'on %s', 'wisdom-rain-email-engine' ),
                        date_i18n( get_option( 'date_format' ), $expired_timestamp )
                    );
                }
            }

            if ( '' === $plan_name ) {
                $plan_name = __( 'Wisdom Rain', 'wisdom-rain-email-engine' );
            }

            $placeholders = array(
                'recipient_name' => sanitize_text_field( $display_name ),
                'plan_name'      => $plan_name,
                'renew_url'      => esc_url( $renew_url ),
                'expired_date'   => $expired,
            );

            $placeholders = apply_filters( 'wre_trial_expired_placeholders', $placeholders, $user_id, $context );
            $placeholders = self::add_unsubscribe_context( $placeholders, $user_id );

            $subject = __( 'Your Wisdom Rain trial has ended', 'wisdom-rain-email-engine' );
            $body    = self::render_template( 'trial-expired', $placeholders );

            if ( '' === $body ) {
                return false;
            }

            return self::dispatch_email( 'trial-expired', $user_id, $email, $subject, $body, $delivery_mode, 'trial' );
        }

        /**
         * Send the subscription expired email to a user.
         *
         * @param int    $user_id       WordPress user identifier.
         * @param array  $context       Optional subscription context.
         * @param string $delivery_mode Delivery mode for logging context.
         *
         * @return bool
         */
        public static function send_subscription_expired_email( $user_id, $context = array(), $delivery_mode = 'instant' ) {
            $user_id = absint( $user_id );

            if ( $user_id <= 0 ) {
                return false;
            }

            $user = get_userdata( $user_id );

            if ( ! $user || empty( $user->user_email ) ) {
                return false;
            }

            $email = sanitize_email( $user->user_email );

            if ( '' === $email ) {
                return false;
            }

            $display_name      = $user->display_name ? $user->display_name : $user->user_login;
            $plan_name         = '';
            $plan_interval     = '';
            $plan_interval_tag = '';
            $renew_url         = home_url( '/plans/' );
            $expired_message   = '';

            if ( is_array( $context ) ) {
                if ( isset( $context['plan_name'] ) ) {
                    $plan_name = sanitize_text_field( $context['plan_name'] );
                }

                if ( isset( $context['plan_interval'] ) ) {
                    $plan_interval = sanitize_text_field( $context['plan_interval'] );
                }

                if ( isset( $context['renew_url'] ) ) {
                    $renew_url = esc_url_raw( $context['renew_url'] );
                }

                $expired_timestamp = 0;

                if ( isset( $context['expired_at'] ) ) {
                    $expired_timestamp = absint( $context['expired_at'] );
                } elseif ( isset( $context['expired_date'] ) ) {
                    $expired_timestamp = absint( $context['expired_date'] );
                }

                if ( $expired_timestamp > 0 ) {
                    $expired_message = ' ' . sprintf(
                        /* translators: %s: localized date */
                        __( 'on %s', 'wisdom-rain-email-engine' ),
                        date_i18n( get_option( 'date_format' ), $expired_timestamp )
                    );
                }
            }

            if ( '' === $plan_name ) {
                $plan_name = __( 'Wisdom Rain subscription', 'wisdom-rain-email-engine' );
            }

            if ( '' !== $plan_interval ) {
                $plan_interval_tag = ' ' . $plan_interval;
            }

            $placeholders = array(
                'recipient_name' => sanitize_text_field( $display_name ),
                'plan_name'      => $plan_name,
                'plan_interval'  => $plan_interval_tag,
                'renew_url'      => esc_url( $renew_url ),
                'expired_date'   => $expired_message,
            );

            $placeholders = apply_filters( 'wre_subscription_expired_placeholders', $placeholders, $user_id, $context );
            $placeholders = self::add_unsubscribe_context( $placeholders, $user_id );

            $subject = __( 'Your Wisdom Rain subscription has expired', 'wisdom-rain-email-engine' );
            $body    = self::render_template( 'subscription-expired', $placeholders );

            if ( '' === $body ) {
                return false;
            }

            return self::dispatch_email( 'subscription-expired', $user_id, $email, $subject, $body, $delivery_mode, 'subscription' );
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
        protected static function dispatch_email( $template, $user_id, $email, $subject, $body, $delivery_mode = 'standard', $log_type = '' ) {
            if ( '' === $body ) {
                return false;
            }

            $context = array(
                'template'      => $template,
                'user_id'       => $user_id,
                'delivery_mode' => $delivery_mode,
                'log_type'      => $log_type,
            );

            $result = self::send_raw_email( $email, $subject, $body, self::get_default_headers(), $context );

            if ( class_exists( 'WRE_Logger' ) ) {
                $template = sanitize_key( $template );
                $user_id  = absint( $user_id );
                $delivery_mode = in_array( $delivery_mode, array( 'standard', 'instant', 'cron' ), true ) ? $delivery_mode : 'standard';
                $log_type = sanitize_key( $log_type );

                $mode_label = 'standard';

                if ( 'instant' === $delivery_mode ) {
                    $mode_label = 'instant';
                } elseif ( 'cron' === $delivery_mode ) {
                    $mode_label = 'cron';
                }

                if ( $result ) {
                    $message = sprintf(
                        'Email "%s" sent for user #%d (%s dispatch).',
                        $template,
                        $user_id,
                        $mode_label
                    );
                    if ( $log_type ) {
                        $type = $log_type;
                    } else {
                        $type = ( 'instant' === $delivery_mode ) ? 'instant' : ( 'cron' === $delivery_mode ? 'cron' : 'sent' );
                    }
                    \WRE_Logger::increment( 'sent' );

                    if ( 'instant' === $delivery_mode ) {
                        \WRE_Logger::increment( 'instant' );
                    }
                } else {
                    $message = sprintf( 'Email "%s" failed for user #%d.', $template, $user_id );
                    $type    = $log_type ? $log_type : 'failed';
                    \WRE_Logger::increment( 'failed' );
                }

                \WRE_Logger::add( $message, $type );
            }

            return (bool) $result;
        }

        /**
         * Dispatch a raw email payload through the configured mailer.
         *
         * @param string $email   Recipient address.
         * @param string $subject Email subject.
         * @param string $body    Email body content.
         * @param array  $headers List of headers to include with the message.
         * @param array  $context Context data describing the outbound email.
         *
         * @return bool
         */
        public static function send_raw_email( $email, $subject, $body, $headers = array(), $context = array() ) {
            $email = sanitize_email( $email );

            if ( '' === $email || '' === $subject || '' === $body ) {
                return false;
            }

            $headers = self::normalize_headers( $headers );
            $context = self::normalize_context( $context );

            $use_wp_mail = apply_filters( 'wre_mailer_use_wp_mail', true, $context );

            if ( $use_wp_mail ) {
                return self::send_via_wp_mail( $email, $subject, $body, $headers );
            }

            $result = self::send_via_phpmailer( $email, $subject, $body, $headers, $context );

            if ( null === $result || false === $result ) {
                return self::send_via_wp_mail( $email, $subject, $body, $headers );
            }

            return (bool) $result;
        }

        /**
         * Append unsubscribe placeholder data when consent tools are available.
         *
         * @param array $context  Placeholder values to send to the template engine.
         * @param int   $user_id  WordPress user identifier.
         *
         * @return array
         */
        protected static function add_unsubscribe_context( $context, $user_id ) {
            if ( ! is_array( $context ) ) {
                $context = array();
            }

            if ( class_exists( 'WRE_Consent' ) ) {
                $url = \WRE_Consent::get_unsubscribe_url( $user_id );

                if ( $url ) {
                    $context['unsubscribe_url'] = esc_url_raw( $url );
                }
            }

            return $context;
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
         * Normalize headers to a sequential array of strings.
         *
         * @param array|string $headers Header list or string.
         *
         * @return array
         */
        protected static function normalize_headers( $headers ) {
            if ( empty( $headers ) ) {
                return array();
            }

            if ( ! is_array( $headers ) ) {
                $headers = array( (string) $headers );
            }

            $normalized = array();

            foreach ( $headers as $header ) {
                $header = trim( (string) $header );

                if ( '' !== $header ) {
                    $normalized[] = $header;
                }
            }

            return $normalized;
        }

        /**
         * Ensure outbound email context includes expected defaults.
         *
         * @param array $context Context describing the outbound email.
         *
         * @return array
         */
        protected static function normalize_context( $context ) {
            if ( ! is_array( $context ) ) {
                $context = array();
            }

            $defaults = array(
                'template'      => '',
                'user_id'       => 0,
                'delivery_mode' => 'standard',
                'log_type'      => '',
            );

            $context = wp_parse_args( $context, $defaults );

            $context['template']      = sanitize_key( $context['template'] );
            $context['user_id']       = absint( $context['user_id'] );
            $context['delivery_mode'] = in_array( $context['delivery_mode'], array( 'standard', 'instant', 'cron' ), true ) ? $context['delivery_mode'] : 'standard';
            $context['log_type']      = sanitize_key( $context['log_type'] );

            return $context;
        }

        /**
         * Send an email using WordPress' wp_mail() function.
         *
         * @param string $email   Recipient address.
         * @param string $subject Email subject.
         * @param string $body    Email body.
         * @param array  $headers Header list.
         *
         * @return bool
         */
        protected static function send_via_wp_mail( $email, $subject, $body, $headers ) {
            return (bool) wp_mail( $email, $subject, $body, $headers );
        }

        /**
         * Attempt to send an email using a standalone PHPMailer instance.
         *
         * @param string $email   Recipient address.
         * @param string $subject Email subject.
         * @param string $body    Email body.
         * @param array  $headers Header list.
         * @param array  $context Context describing the outbound email.
         *
         * @return bool|null Returns null when PHPMailer is unavailable.
         */
        protected static function send_via_phpmailer( $email, $subject, $body, $headers, $context ) {
            if ( ! class_exists( '\\PHPMailer\\PHPMailer\\PHPMailer' ) ) {
                return null;
            }

            try {
                $mailer = new \PHPMailer\PHPMailer\PHPMailer( true );
            } catch ( \Exception $e ) {
                return false;
            }

            $mailer->CharSet = 'UTF-8';
            $mailer->clearAllRecipients();
            $mailer->clearAttachments();
            $mailer->isHTML( true );
            $mailer->Subject = $subject;
            $mailer->Body    = $body;
            $mailer->AltBody = wp_strip_all_tags( $body );

            try {
                $mailer->addAddress( $email );
            } catch ( \PHPMailer\PHPMailer\Exception $e ) {
                return false;
            }

            list( $from_email, $from_name ) = self::resolve_from_header( $headers );

            try {
                $mailer->setFrom( $from_email, $from_name ?: $from_email, false );
            } catch ( \PHPMailer\PHPMailer\Exception $e ) {
                try {
                    $mailer->setFrom( $from_email, '', false );
                } catch ( \PHPMailer\PHPMailer\Exception $e ) {
                    return false;
                }
            }

            self::apply_header_recipients( $mailer, $headers );
            self::apply_additional_headers( $mailer, $headers );

            try {
                return $mailer->send();
            } catch ( \PHPMailer\PHPMailer\Exception $e ) {
                return false;
            }
        }

        /**
         * Determine the From address from the provided headers.
         *
         * @param array $headers Header list.
         *
         * @return array{0:string,1:string}
         */
        protected static function resolve_from_header( $headers ) {
            $header = self::find_header( $headers, 'from' );

            if ( '' !== $header ) {
                list( $email, $name ) = self::parse_address( $header );

                if ( '' !== $email ) {
                    return array( $email, $name );
                }
            }

            $default_email = get_option( 'admin_email', '' );

            if ( '' === $default_email ) {
                $host          = wp_parse_url( home_url(), PHP_URL_HOST );
                $default_email = $host ? 'no-reply@' . $host : 'no-reply@example.com';
            }

            return array( sanitize_email( $default_email ), get_bloginfo( 'name', 'display' ) );
        }

        /**
         * Apply Reply-To, CC, and BCC headers to the PHPMailer instance.
         *
         * @param \PHPMailer\PHPMailer\PHPMailer $mailer  Mailer instance.
         * @param array                           $headers Header list.
         */
        protected static function apply_header_recipients( $mailer, $headers ) {
            foreach ( array( 'reply-to' => 'addReplyTo', 'cc' => 'addCC', 'bcc' => 'addBCC' ) as $header => $method ) {
                $value = self::find_header( $headers, $header );

                if ( '' === $value ) {
                    continue;
                }

                foreach ( self::split_addresses( $value ) as $address ) {
                    list( $email, $name ) = self::parse_address( $address );

                    if ( '' === $email ) {
                        continue;
                    }

                    try {
                        $mailer->{$method}( $email, $name );
                    } catch ( \PHPMailer\PHPMailer\Exception $e ) {
                        continue;
                    }
                }
            }
        }

        /**
         * Apply additional headers such as Return-Path and Content-Type.
         *
         * @param \PHPMailer\PHPMailer\PHPMailer $mailer  Mailer instance.
         * @param array                           $headers Header list.
         */
        protected static function apply_additional_headers( $mailer, $headers ) {
            $return_path = sanitize_email( self::find_header( $headers, 'return-path' ) );

            if ( '' !== $return_path ) {
                $mailer->Sender = $return_path;
            }

            $content_type = self::find_header( $headers, 'content-type' );

            if ( '' !== $content_type ) {
                if ( false !== stripos( $content_type, 'text/plain' ) ) {
                    $mailer->isHTML( false );
                } elseif ( false !== stripos( $content_type, 'text/html' ) ) {
                    $mailer->isHTML( true );
                }
            }
        }

        /**
         * Retrieve a header value from the list, matching case-insensitively.
         *
         * @param array  $headers Header list.
         * @param string $needle  Header name to locate.
         *
         * @return string
         */
        protected static function find_header( $headers, $needle ) {
            $needle = strtolower( $needle );

            foreach ( $headers as $header ) {
                $parts = explode( ':', $header, 2 );

                if ( 2 !== count( $parts ) ) {
                    continue;
                }

                if ( strtolower( trim( $parts[0] ) ) === $needle ) {
                    return trim( $parts[1] );
                }
            }

            return '';
        }

        /**
         * Split a comma-delimited list of addresses.
         *
         * @param string $addresses Comma-delimited addresses.
         *
         * @return array
         */
        protected static function split_addresses( $addresses ) {
            if ( '' === $addresses ) {
                return array();
            }

            $parts = array_map( 'trim', explode( ',', $addresses ) );

            return array_filter( $parts );
        }

        /**
         * Parse a single address into email and name components.
         *
         * @param string $address Address string.
         *
         * @return array{0:string,1:string}
         */
        protected static function parse_address( $address ) {
            $address = trim( $address );

            if ( preg_match( '/(.*)<(.+)>/', $address, $matches ) ) {
                $email = sanitize_email( trim( $matches[2] ) );
                $name  = trim( $matches[1] );
                $name  = trim( $name, "\"' " );
            } else {
                $email = sanitize_email( $address );
                $name  = '';
            }

            if ( '' === $email ) {
                return array( '', '' );
            }

            return array( $email, $name );
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
