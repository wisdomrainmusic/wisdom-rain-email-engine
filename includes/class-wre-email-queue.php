<?php
/**
 * Simple email queue and rate limiter for Wisdom Rain Email Engine.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Email_Queue' ) ) {
    /**
     * Handles queuing and throttled dispatch of email actions.
     */
    class WRE_Email_Queue {
        /**
         * Action name invoked by WP-Cron when queued jobs should be processed.
         */
        const CRON_HOOK = 'wre_process_email_queue';

        /**
         * Option key storing queued jobs waiting to run.
         */
        const OPTION_QUEUE = 'wre_email_queue_jobs';

        /**
         * Option key storing rate limit window data.
         */
        const OPTION_RATE = 'wre_email_queue_window';

        /**
         * Maximum number of emails that may be sent within a rolling hour window.
         */
        const MAX_PER_HOUR = 100;

        /**
         * Bootstrap the queue by wiring cron hooks.
         */
        public static function init() {
            add_action( self::CRON_HOOK, array( __CLASS__, 'process_queue' ) );
        }

        /**
         * Legacy helper to enqueue predefined email jobs by action slug.
         *
         * @param int    $user_id WordPress user identifier the email relates to.
         * @param string $action  Legacy action slug describing the email type.
         */
        public static function add_to_queue( $user_id, $action ) {
            $user_id = absint( $user_id );
            $action  = sanitize_key( $action );

            if ( $user_id <= 0 || '' === $action ) {
                return;
            }

            $map = self::get_legacy_action_map();

            if ( ! isset( $map[ $action ] ) ) {
                return;
            }

            $hook = $map[ $action ]['hook'];
            $args = array_merge( array( $user_id ), $map[ $action ]['args'] );

            self::enqueue( $hook, $args );
        }

        /**
         * Add an email action to the queue and ensure processing is scheduled.
         *
         * @param string $hook Action hook name to invoke when processing the job.
         * @param array  $args Arguments passed to the action hook when executed.
         */
        public static function enqueue( $hook, $args = array() ) {
            if ( empty( $hook ) || ! is_string( $hook ) ) {
                return;
            }

            $job = array(
                'hook' => sanitize_key( $hook ),
                'args' => array_values( (array) $args ),
            );

            $queue   = get_option( self::OPTION_QUEUE, array() );
            $queue[] = $job;

            update_option( self::OPTION_QUEUE, $queue, false );

            self::maybe_schedule_processor();
        }

        /**
         * Process queued jobs while respecting the configured rate limit.
         */
        public static function process_queue() {
            $queue = get_option( self::OPTION_QUEUE, array() );

            if ( empty( $queue ) || ! is_array( $queue ) ) {
                delete_option( self::OPTION_QUEUE );
                return;
            }

            $window = self::get_rate_window();

            $allowed = self::MAX_PER_HOUR - $window['count'];

            if ( $allowed <= 0 ) {
                self::schedule_next_window( $window['start'] );
                return;
            }

            $processed = 0;
            $remaining = array();

            foreach ( $queue as $job ) {
                if ( $processed >= $allowed ) {
                    $remaining[] = $job;
                    continue;
                }

                $hook = isset( $job['hook'] ) ? (string) $job['hook'] : '';

                if ( '' === $hook ) {
                    continue;
                }

                $args = array();

                if ( isset( $job['args'] ) && is_array( $job['args'] ) ) {
                    $args = $job['args'];
                }

                /**
                 * Execute the queued action with arguments.
                 */
                do_action_ref_array( $hook, $args );

                $processed++;
            }

            if ( ! empty( $remaining ) ) {
                update_option( self::OPTION_QUEUE, array_values( $remaining ), false );
            } else {
                delete_option( self::OPTION_QUEUE );
            }

            if ( $processed > 0 ) {
                $window['count'] += $processed;
                update_option( self::OPTION_RATE, $window, false );
            }

            if ( ! empty( $remaining ) ) {
                self::maybe_schedule_processor();
            }
        }

        /**
         * Ensure that the queue processor is scheduled if jobs are pending.
         */
        protected static function maybe_schedule_processor() {
            if ( wp_next_scheduled( self::CRON_HOOK ) ) {
                return;
            }

            if ( empty( get_option( self::OPTION_QUEUE, array() ) ) ) {
                return;
            }

            wp_schedule_single_event( time(), self::CRON_HOOK );
        }

        /**
         * Retrieve the current rate limiting window, resetting when expired.
         *
         * @return array
         */
        protected static function get_rate_window() {
            $window = get_option( self::OPTION_RATE, array() );

            $now = time();

            $start = isset( $window['start'] ) ? absint( $window['start'] ) : 0;
            $count = isset( $window['count'] ) ? absint( $window['count'] ) : 0;

            if ( $start <= 0 || ( $now - $start ) >= HOUR_IN_SECONDS ) {
                $start = $now;
                $count = 0;
            }

            return array(
                'start' => $start,
                'count' => $count,
            );
        }

        /**
         * Schedule the queue processor for the start of the next rate limiting window.
         *
         * @param int $window_start Timestamp representing the beginning of the current window.
         */
        protected static function schedule_next_window( $window_start ) {
            $window_start = absint( $window_start );

            if ( $window_start <= 0 ) {
                $window_start = time();
            }

            $next = $window_start + HOUR_IN_SECONDS;

            if ( $next <= time() ) {
                $next = time() + MINUTE_IN_SECONDS;
            }

            wp_schedule_single_event( $next, self::CRON_HOOK );
        }

        /**
         * Map legacy queue identifiers to queue hooks and default arguments.
         *
         * @return array<string, array{hook:string,args:array<int,mixed>}>
         */
        protected static function get_legacy_action_map() {
            return array(
                'email-verify-reminder'        => array(
                    'hook' => 'wre_send_verify_reminder',
                    'args' => array(),
                ),
                'email-plan-reminder'          => array(
                    'hook' => 'wre_send_plan_reminder',
                    'args' => array( 3 ),
                ),
                'email-plan-expiring-tomorrow' => array(
                    'hook' => 'wre_send_plan_reminder',
                    'args' => array( 1 ),
                ),
                'email-come-back'              => array(
                    'hook' => 'wre_send_comeback',
                    'args' => array( 30 ),
                ),
            );
        }
    }
}
