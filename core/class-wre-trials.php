<?php
/**
 * WRPA trial expiration bridge for the WRE engine.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Trials' ) ) {
    /**
     * Bridges trial expiration events from WRPA into WRE notifications.
     */
    class WRE_Trials {
        const OPTION_LAST_EXPIRATION         = '_wre_last_trial_expiration';
        const OPTION_QUEUE_TRIAL_EXPIRED     = '_wre_queue_trial_expired';
        const OPTION_QUEUE_SUBSCRIPTION      = '_wre_queue_subscription_expired';
        const MAX_QUEUE_ATTEMPTS             = 3;

        /**
         * Wire hooks that listen for trial lifecycle changes.
         */
        public static function init() {
            add_action( 'wrpa_trial_expired', array( __CLASS__, 'send_expiration_notice' ), 10, 2 );
            add_action( 'wrpa_subscription_expired', array( __CLASS__, 'send_subscription_expired_email' ), 10, 2 );
        }

        /**
         * Dispatch the expiration notice for a WRPA trial user.
         *
         * @param int   $user_id WordPress user identifier.
         * @param array $context Supplemental data from WRPA.
         *
         * @return bool Whether the notice was dispatched successfully.
         */
        public static function send_expiration_notice( $user_id, $context = array() ) {
            $user_id = absint( $user_id );

            if ( $user_id <= 0 ) {
                return false;
            }

            $context       = is_array( $context ) ? $context : array();
            $delivery_mode = self::normalize_delivery_mode( $context );

            self::record_last_expiration( $user_id, $context );

            if ( self::should_queue_delivery( $delivery_mode ) ) {
                return self::enqueue_expiration_job( 'trial', $user_id, $context, $delivery_mode );
            }

            return self::dispatch_trial_expired_email( $user_id, $context, $delivery_mode );
        }

        /**
         * Dispatch subscription expiration notifications for paid plans.
         *
         * @param int   $user_id WordPress user identifier.
         * @param array $context Supplemental data from WRPA.
         *
         * @return bool Whether the notice was dispatched or queued.
         */
        public static function send_subscription_expired_email( $user_id, $context = array() ) {
            $user_id = absint( $user_id );

            if ( $user_id <= 0 ) {
                return false;
            }

            $context       = is_array( $context ) ? $context : array();
            $delivery_mode = self::normalize_delivery_mode( $context );

            if ( self::should_queue_delivery( $delivery_mode ) ) {
                return self::enqueue_expiration_job( 'subscription', $user_id, $context, $delivery_mode );
            }

            return self::dispatch_subscription_expired_email( $user_id, $context, $delivery_mode );
        }

        /**
         * Retrieve details about the most recent trial expiration event.
         *
         * @return array{user_id:int,context:array<string,mixed>,recorded_at:int}|
         *         array<string, mixed>
         */
        public static function get_last_expiration() {
            $data = get_option( self::OPTION_LAST_EXPIRATION, array() );

            if ( ! is_array( $data ) ) {
                return array();
            }

            $user_id     = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0;
            $context     = isset( $data['context'] ) && is_array( $data['context'] ) ? $data['context'] : array();
            $recorded_at = isset( $data['recorded_at'] ) ? absint( $data['recorded_at'] ) : 0;

            if ( $user_id <= 0 ) {
                return array();
            }

            return array(
                'user_id'     => $user_id,
                'context'     => $context,
                'recorded_at' => $recorded_at,
            );
        }

        /**
         * Replay the most recent trial expiration email, if one is available.
         *
         * @return bool|null True if replay succeeded, false if it failed, null when nothing is recorded.
         */
        public static function replay_last_expiration_notice() {
            $last = self::get_last_expiration();

            if ( empty( $last ) || empty( $last['user_id'] ) ) {
                return null;
            }

            if ( ! class_exists( 'WRE_Email_Sender' ) ) {
                return false;
            }

            $sent = \WRE_Email_Sender::send_trial_expired_email(
                $last['user_id'],
                isset( $last['context'] ) && is_array( $last['context'] ) ? $last['context'] : array(),
                'instant'
            );

            if ( class_exists( 'WRE_Logger' ) ) {
                $message = $sent
                    ? sprintf( 'Expiration notice replayed for trial user #%d.', $last['user_id'] )
                    : sprintf( 'Expiration notice replay failed for trial user #%d.', $last['user_id'] );

                \WRE_Logger::add( $message, 'trial' );
            }

            return (bool) $sent;
        }

        /**
         * Persist the last trial expiration context for debugging and manual tests.
         *
         * @param int   $user_id WordPress user identifier.
         * @param array $context Context array provided by WRPA.
         */
        protected static function record_last_expiration( $user_id, $context = array() ) {
            $payload = array(
                'user_id'     => absint( $user_id ),
                'context'     => self::sanitize_context( $context ),
                'recorded_at' => current_time( 'timestamp', true ),
            );

            update_option( self::OPTION_LAST_EXPIRATION, $payload, false );
        }

        /**
         * Process queued expiration emails for cron-based delivery.
         *
         * @param string $type  Queue identifier (trial|subscription).
         * @param int    $limit Maximum number of notifications to process.
         *
         * @return int Number of notifications dispatched successfully.
         */
        public static function process_queued_expirations( $type, $limit = 10 ) {
            $queue_key = self::get_queue_option_key( $type );

            if ( '' === $queue_key ) {
                return 0;
            }

            $limit = absint( $limit );

            if ( $limit <= 0 ) {
                $limit = 10;
            }

            $queue = get_option( $queue_key, array() );

            if ( empty( $queue ) || ! is_array( $queue ) ) {
                return 0;
            }

            $jobs          = array_slice( $queue, 0, $limit );
            $remainder     = array_slice( $queue, $limit );
            $requeue       = array();
            $processed     = 0;
            $normalized_type = self::normalize_queue_type( $type );

            foreach ( $jobs as $job ) {
                $user_id = isset( $job['user_id'] ) ? absint( $job['user_id'] ) : 0;

                if ( $user_id <= 0 ) {
                    continue;
                }

                $context       = isset( $job['context'] ) && is_array( $job['context'] ) ? $job['context'] : array();
                $attempts      = isset( $job['attempts'] ) ? absint( $job['attempts'] ) : 0;

                $sent = 'subscription' === $normalized_type
                    ? self::dispatch_subscription_expired_email( $user_id, $context, 'cron' )
                    : self::dispatch_trial_expired_email( $user_id, $context, 'cron' );

                if ( $sent ) {
                    $processed++;
                    continue;
                }

                if ( $attempts < self::MAX_QUEUE_ATTEMPTS ) {
                    $job['attempts'] = $attempts + 1;
                    $requeue[]       = self::sanitize_queue_job( $job );
                }
            }

            $updated_queue = array_merge( $remainder, $requeue );

            update_option( $queue_key, $updated_queue, false );

            return $processed;
        }

        /**
         * Sanitize a context array for persistence.
         *
         * @param array<string, mixed> $context Context to sanitize.
         *
         * @return array<string, mixed>
         */
        protected static function sanitize_context( $context ) {
            if ( ! is_array( $context ) ) {
                return array();
            }

            $sanitized = array();

            foreach ( $context as $key => $value ) {
                $key = sanitize_key( $key );

                if ( is_array( $value ) ) {
                    $sanitized[ $key ] = self::sanitize_context( $value );
                    continue;
                }

                if ( is_scalar( $value ) ) {
                    $sanitized[ $key ] = sanitize_text_field( (string) $value );
                }
            }

            return $sanitized;
        }

        /**
         * Normalise the requested delivery mode for expiration notices.
         *
         * @param array<string, mixed> $context Context payload from WRPA.
         * @param string               $default Default delivery mode when not provided.
         *
         * @return string Either "instant" or "cron".
         */
        protected static function normalize_delivery_mode( $context, $default = 'instant' ) {
            $default = in_array( $default, array( 'instant', 'cron' ), true ) ? $default : 'instant';

            if ( ! is_array( $context ) ) {
                return $default;
            }

            $keys = array( 'delivery_mode', 'delivery', 'schedule', 'send_mode' );

            foreach ( $keys as $key ) {
                if ( empty( $context[ $key ] ) ) {
                    continue;
                }

                $value = strtolower( trim( (string) $context[ $key ] ) );

                if ( in_array( $value, array( 'cron', 'queue', 'queued', 'delayed', 'scheduled', 'schedule' ), true ) ) {
                    return 'cron';
                }

                if ( in_array( $value, array( 'instant', 'immediate', 'now', 'direct' ), true ) ) {
                    return 'instant';
                }
            }

            return $default;
        }

        /**
         * Determine whether the delivery mode requires queuing for cron delivery.
         *
         * @param string $delivery_mode Normalised delivery mode value.
         *
         * @return bool
         */
        protected static function should_queue_delivery( $delivery_mode ) {
            return 'cron' === $delivery_mode;
        }

        /**
         * Store a queued expiration job for cron processing.
         *
         * @param string $type          Queue identifier (trial|subscription).
         * @param int    $user_id       WordPress user identifier.
         * @param array  $context       Event context array.
         * @param string $delivery_mode Delivery mode recorded for logging.
         *
         * @return bool
         */
        protected static function enqueue_expiration_job( $type, $user_id, $context, $delivery_mode ) {
            $queue_key = self::get_queue_option_key( $type );

            if ( '' === $queue_key ) {
                return false;
            }

            $queue = get_option( $queue_key, array() );

            if ( ! is_array( $queue ) ) {
                $queue = array();
            }

            $queue[] = array(
                'user_id'       => absint( $user_id ),
                'context'       => self::sanitize_context( $context ),
                'delivery_mode' => self::normalize_delivery_mode( array( 'delivery_mode' => $delivery_mode ), 'cron' ),
                'queued_at'     => current_time( 'timestamp', true ),
                'attempts'      => 0,
            );

            $result = update_option( $queue_key, $queue, false );

            if ( $result && class_exists( 'WRE_Logger' ) ) {
                $normalized_type = self::normalize_queue_type( $type );
                $message         = ( 'subscription' === $normalized_type )
                    ? sprintf( 'Subscription expiration notice queued for user #%d.', $user_id )
                    : sprintf( 'Trial expiration notice queued for user #%d.', $user_id );

                \WRE_Logger::add( $message, 'queue' );
            }

            return (bool) $result;
        }

        /**
         * Ensure queued job fields are safely sanitised before requeueing.
         *
         * @param array<string, mixed> $job Job payload.
         *
         * @return array<string, mixed>
         */
        protected static function sanitize_queue_job( $job ) {
            if ( ! is_array( $job ) ) {
                return array();
            }

            $job['user_id']       = isset( $job['user_id'] ) ? absint( $job['user_id'] ) : 0;
            $job['context']       = isset( $job['context'] ) && is_array( $job['context'] ) ? self::sanitize_context( $job['context'] ) : array();
            $job['delivery_mode'] = 'cron';
            $job['queued_at']     = isset( $job['queued_at'] ) ? absint( $job['queued_at'] ) : current_time( 'timestamp', true );
            $job['attempts']      = isset( $job['attempts'] ) ? absint( $job['attempts'] ) : 0;

            return $job;
        }

        /**
         * Translate shorthand queue identifiers to canonical types.
         *
         * @param string $type Queue identifier or alias.
         *
         * @return string
         */
        protected static function normalize_queue_type( $type ) {
            $type = strtolower( (string) $type );

            if ( in_array( $type, array( 'subscription', 'subscription_expired' ), true ) ) {
                return 'subscription';
            }

            return 'trial';
        }

        /**
         * Resolve the option key for a given expiration queue type.
         *
         * @param string $type Queue identifier or alias.
         *
         * @return string
         */
        protected static function get_queue_option_key( $type ) {
            $type = self::normalize_queue_type( $type );

            if ( 'subscription' === $type ) {
                return self::OPTION_QUEUE_SUBSCRIPTION;
            }

            return self::OPTION_QUEUE_TRIAL_EXPIRED;
        }

        /**
         * Send and log a trial expiration email.
         *
         * @param int    $user_id       WordPress user identifier.
         * @param array  $context       Supplemental context for the email.
         * @param string $delivery_mode Delivery mode string.
         *
         * @return bool
         */
        protected static function dispatch_trial_expired_email( $user_id, $context, $delivery_mode ) {
            if ( ! class_exists( 'WRE_Email_Sender' ) ) {
                return false;
            }

            $sent = \WRE_Email_Sender::send_trial_expired_email( $user_id, $context, $delivery_mode );

            if ( class_exists( 'WRE_Logger' ) ) {
                $message = $sent
                    ? sprintf( 'Expiration notice sent for trial user #%d.', $user_id )
                    : sprintf( 'Expiration notice failed for trial user #%d.', $user_id );

                \WRE_Logger::add( $message, 'trial' );
            }

            return (bool) $sent;
        }

        /**
         * Send and log a subscription expiration email.
         *
         * @param int    $user_id       WordPress user identifier.
         * @param array  $context       Supplemental context for the email.
         * @param string $delivery_mode Delivery mode string.
         *
         * @return bool
         */
        protected static function dispatch_subscription_expired_email( $user_id, $context, $delivery_mode ) {
            if ( ! class_exists( 'WRE_Email_Sender' ) ) {
                return false;
            }

            $sent = \WRE_Email_Sender::send_subscription_expired_email( $user_id, $context, $delivery_mode );

            if ( class_exists( 'WRE_Logger' ) ) {
                $message = $sent
                    ? sprintf( 'Subscription expiration notice sent for user #%d.', $user_id )
                    : sprintf( 'Subscription expiration notice failed for user #%d.', $user_id );

                \WRE_Logger::add( $message, 'subscription' );
            }

            return (bool) $sent;
        }
    }
}
