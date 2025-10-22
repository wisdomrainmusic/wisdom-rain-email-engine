<?php
/**
 * WooCommerce order and trial event integration for WRE.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Orders' ) ) {
    /**
     * Bridges WooCommerce order events and WRPA trial expirations to WRE emails.
     */
    class WRE_Orders {
        const OPTION_LAST_ORDER_ID   = '_wre_last_order_id';
        const OPTION_TRIAL_QUEUE     = '_wre_trial_queue';
        const MAX_TRIAL_ATTEMPTS     = 3;

        /**
         * Wire WordPress hooks for order and trial notifications.
         */
        public static function init() {
            add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'handle_order_completed' ), 20, 1 );
            add_action( 'wrpa_trial_expired', array( __CLASS__, 'handle_trial_expired' ), 10, 2 );

            add_filter( 'woocommerce_email_enabled_customer_completed_order', array( __CLASS__, 'disable_default_wc_email' ), 20, 2 );
            add_filter( 'woocommerce_email_enabled_customer_processing_order', array( __CLASS__, 'disable_default_wc_email' ), 20, 2 );
        }

        /**
         * Disable selected WooCommerce transactional emails to avoid duplicates.
         *
         * @param bool       $enabled Whether the email is enabled.
         * @param mixed      $order   WooCommerce order instance.
         *
         * @return bool
         */
        public static function disable_default_wc_email( $enabled, $order = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
            return false;
        }

        /**
         * Triggered when an order transitions to the completed status.
         *
         * @param int $order_id WooCommerce order identifier.
         */
        public static function handle_order_completed( $order_id ) {
            $order_id = absint( $order_id );

            if ( $order_id <= 0 ) {
                return;
            }

            self::record_last_order_id( $order_id );

            $sent = self::send_order_confirmation_email( $order_id, 'instant' );

            if ( class_exists( 'WRE_Logger' ) ) {
                $message = $sent
                    ? sprintf( 'Order #%d marked completed; confirmation dispatched.', $order_id )
                    : sprintf( 'Order #%d marked completed; confirmation dispatch failed.', $order_id );

                \WRE_Logger::add( $message, 'order' );
            }
        }

        /**
         * Dispatch an order confirmation email for the supplied order.
         *
         * @param int|WC_Order $order        WooCommerce order identifier or instance.
         * @param string       $delivery_mode Delivery mode for logging context.
         *
         * @return bool
         */
        public static function send_order_confirmation_email( $order, $delivery_mode = 'instant' ) {
            if ( ! class_exists( 'WRE_Email_Sender' ) ) {
                return false;
            }

            return \WRE_Email_Sender::send_order_confirmation_email( $order, $delivery_mode );
        }

        /**
         * Handle WRPA trial expiration events.
         *
         * @param int   $user_id WordPress user identifier.
         * @param array $context Additional context provided by WRPA.
         */
        public static function handle_trial_expired( $user_id, $context = array() ) {
            $user_id = absint( $user_id );

            if ( $user_id <= 0 ) {
                return;
            }

            self::queue_trial_job( $user_id, $context );
            self::process_pending_trial_jobs( true );
        }

        /**
         * Determine whether there are trial jobs queued for dispatch.
         *
         * @return bool
         */
        public static function has_pending_trial_jobs() {
            $queue = self::get_trial_queue();

            return ! empty( $queue );
        }

        /**
         * Process queued trial-expiration jobs.
         *
         * @param bool $force_instant Whether to dispatch emails instantly.
         *
         * @return int Number of jobs dispatched successfully.
         */
        public static function process_pending_trial_jobs( $force_instant = false ) {
            $queue = self::get_trial_queue();

            if ( empty( $queue ) ) {
                return 0;
            }

            $remaining = array();
            $processed = 0;

            foreach ( $queue as $job ) {
                $user_id  = isset( $job['user_id'] ) ? absint( $job['user_id'] ) : 0;
                $context  = isset( $job['context'] ) && is_array( $job['context'] ) ? $job['context'] : array();
                $attempts = isset( $job['attempts'] ) ? absint( $job['attempts'] ) : 0;

                if ( $user_id <= 0 ) {
                    continue;
                }

                $delivery_mode = $force_instant ? 'instant' : 'standard';
                $sent          = class_exists( 'WRE_Email_Sender' )
                    ? \WRE_Email_Sender::send_trial_expired_email( $user_id, $context, $delivery_mode )
                    : false;

                if ( $sent ) {
                    $processed++;
                    continue;
                }

                $attempts++;

                if ( $attempts < self::MAX_TRIAL_ATTEMPTS ) {
                    $job['attempts'] = $attempts;
                    $remaining[]      = $job;
                } elseif ( class_exists( 'WRE_Logger' ) ) {
                    \WRE_Logger::add(
                        sprintf( 'Trial expiration email dropped for user #%1$d after %2$d attempts.', $user_id, $attempts ),
                        'trial'
                    );
                }
            }

            self::save_trial_queue( $remaining );

            return $processed;
        }

        /**
         * Retrieve the most recently completed order identifier.
         *
         * @return int
         */
        public static function get_last_order_id() {
            return absint( get_option( self::OPTION_LAST_ORDER_ID, 0 ) );
        }

        /**
         * Execute manual test routines covering order and trial notifications.
         *
         * @return array{order:bool|null,trials:int}
         */
        public static function run_manual_tests() {
            $results = array(
                'order'  => null,
                'trials' => 0,
            );

            $last_order = self::get_last_order_id();

            if ( $last_order > 0 ) {
                $results['order'] = self::send_order_confirmation_email( $last_order, 'instant' );
            }

            $results['trials'] = self::process_pending_trial_jobs( true );

            return $results;
        }

        /**
         * Persist the last order identifier processed by the engine.
         *
         * @param int $order_id Order identifier.
         */
        protected static function record_last_order_id( $order_id ) {
            update_option( self::OPTION_LAST_ORDER_ID, absint( $order_id ), false );
        }

        /**
         * Store a trial expiration job for later dispatch.
         *
         * @param int   $user_id WordPress user identifier.
         * @param array $context Additional job context.
         */
        protected static function queue_trial_job( $user_id, $context = array() ) {
            $queue = array_filter(
                self::get_trial_queue(),
                static function ( $job ) use ( $user_id ) {
                    if ( ! is_array( $job ) || ! isset( $job['user_id'] ) ) {
                        return false;
                    }

                    return absint( $job['user_id'] ) !== $user_id;
                }
            );

            $queue[] = array(
                'user_id'   => absint( $user_id ),
                'context'   => is_array( $context ) ? $context : array(),
                'queued_at' => current_time( 'timestamp', true ),
                'attempts'  => 0,
            );

            self::save_trial_queue( $queue );

            if ( class_exists( 'WRE_Logger' ) ) {
                \WRE_Logger::add(
                    sprintf( 'Trial expiration detected for user #%d; email queued for delivery.', $user_id ),
                    'trial'
                );
            }
        }

        /**
         * Retrieve the persisted trial queue.
         *
         * @return array<int, array<string, mixed>>
         */
        protected static function get_trial_queue() {
            $queue = get_option( self::OPTION_TRIAL_QUEUE, array() );

            return is_array( $queue ) ? $queue : array();
        }

        /**
         * Persist the trial queue.
         *
         * @param array<int, array<string, mixed>> $queue Trial queue payload.
         */
        protected static function save_trial_queue( $queue ) {
            update_option( self::OPTION_TRIAL_QUEUE, array_values( $queue ), false );
        }
    }
}
