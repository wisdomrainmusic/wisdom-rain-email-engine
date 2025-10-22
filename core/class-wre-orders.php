<?php
/**
 * WooCommerce order event integration for WRE.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Orders' ) ) {
    /**
     * Bridges WooCommerce order events to WRE emails.
     */
    class WRE_Orders {
        const OPTION_LAST_ORDER_ID = '_wre_last_order_id';

        /**
         * Wire WordPress hooks for order notifications.
         */
        public static function init() {
            // Instant trigger after successful payment (fires on checkout thank-you page).
            add_action( 'woocommerce_thankyou', array( __CLASS__, 'send_order_confirmation_email' ), 10, 1 );

            add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'send_order_confirmation_email' ), 20, 1 );

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
         * Dispatch an order confirmation email for the supplied order.
         *
         * @param int|WC_Order $order        WooCommerce order identifier or instance.
         * @param string       $delivery_mode Delivery mode for logging context.
         *
         * @return bool
         */
        public static function send_order_confirmation_email( $order, $delivery_mode = 'instant' ) {
            if ( ! function_exists( 'wc_get_order' ) ) {
                return false;
            }

            $order_object = ( is_object( $order ) && is_a( $order, 'WC_Order' ) ) ? $order : wc_get_order( $order );

            if ( ! $order_object ) {
                return false;
            }

            $order_id = method_exists( $order_object, 'get_id' ) ? absint( $order_object->get_id() ) : 0;

            if ( $order_id > 0 ) {
                self::record_last_order_id( $order_id );
            }

            if ( ! class_exists( 'WRE_Email_Sender' ) ) {
                return false;
            }

            $sent = \WRE_Email_Sender::send_order_confirmation_email( $order_object, $delivery_mode );

            if ( class_exists( 'WRE_Logger' ) && $order_id > 0 ) {
                $message = $sent
                    ? sprintf( 'Confirmation email sent for order #%d.', $order_id )
                    : sprintf( 'Confirmation email failed for order #%d.', $order_id );

                \WRE_Logger::add( $message, 'order' );
            }

            return (bool) $sent;
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
         * Persist the last order identifier processed by the engine.
         *
         * @param int $order_id Order identifier.
         */
        protected static function record_last_order_id( $order_id ) {
            update_option( self::OPTION_LAST_ORDER_ID, absint( $order_id ), false );
        }

        /**
         * Replay the most recent order confirmation email, if available.
         *
         * @return bool|null True if resent, false on failure, null when no order recorded.
         */
        public static function replay_last_confirmation() {
            $order_id = self::get_last_order_id();

            if ( $order_id <= 0 ) {
                return null;
            }

            return self::send_order_confirmation_email( $order_id, 'instant' );
        }
    }
}
