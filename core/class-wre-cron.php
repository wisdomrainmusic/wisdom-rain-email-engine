<?php
/**
 * Cron and reminder scheduler for the Wisdom Rain Email Engine.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Cron' ) ) {
    /**
     * Registers recurring cron jobs to handle automated reminder emails.
     */
    class WRE_Cron {
        /**
         * Cron hook executed twice daily to queue reminder jobs.
         */
        const CRON_HOOK = 'wre_cron_run_tasks';

        /**
         * Meta key storing the timestamp of the last verification reminder.
         */
        const META_VERIFY_REMINDER = '_wre_last_verify_reminder';

        /**
         * Prefix used for meta keys that record sent plan reminders by day.
         */
        const META_PLAN_REMINDER_PREFIX = '_wre_plan_reminder_';

        /**
         * Meta key storing the timestamp when the comeback campaign email was sent.
         */
        const META_COMEBACK_SENT = '_wre_comeback_sent';

        /**
         * Number of reminders that may be queued during a single cron run.
         */
        const MAX_QUEUE_PER_RUN = 100;

        /**
         * WRPA meta key storing the most recent product identifier.
         */
        const META_WRPA_LAST_PRODUCT = 'wrpa_last_product_id';

        /**
         * WRPA meta key storing the subscription expiration timestamp.
         */
        const META_WRPA_SUBSCRIPTION_END = 'wrpa_subscription_end';

        /**
         * Plan identifiers that should trigger subscription expiration handling.
         */
        const EXPIRABLE_PLAN_IDS = array( 2895, 2894, 2893 );

        /**
         * Meta key storing the custom subscription expiry timestamp.
         */
        const META_SUBSCRIPTION_EXPIRY = '_wre_subscription_expiry';

        /**
         * Meta key storing the human-readable subscription status.
         */
        const META_SUBSCRIPTION_STATUS = '_wre_subscription_status';

        /**
         * Subscription status label persisted when an account has expired.
         */
        const SUBSCRIPTION_STATUS_EXPIRED = 'expired';

        /**
         * Number of days after registration to send a verification reminder.
         */
        const VERIFY_REMINDER_DELAY_DAYS = 3;

        /**
         * Number of days after plan expiration to send a comeback email.
         */
        const COMEBACK_DELAY_DAYS = 30;

        /**
         * Number of days prior to expiration that reminders should be sent.
         */
        protected static $plan_reminder_days = array( 3, 1 );

        /**
         * Tracks the number of jobs queued during the current cron execution.
         *
         * @var int
         */
        protected static $queued_during_run = 0;

        /**
         * Wire hooks for scheduling and executing cron-based routines.
         */
        public static function init() {
            add_action( self::CRON_HOOK, array( __CLASS__, 'run_tasks' ) );
            add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
            add_action( 'wre_run_scheduled_tasks', array( __CLASS__, 'run_scheduled_tasks' ) );
        }

        /**
         * Ensure that the twice-daily cron event is scheduled.
         */
        public static function ensure_schedule() {
            $next = wp_next_scheduled( self::CRON_HOOK );

            if ( $next && self::is_schedule_aligned( $next ) ) {
                return;
            }

            if ( $next ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
            }

            self::install_schedule();
        }

        /**
         * Register the twice-daily schedule aligned to 01:00 and 13:00.
         */
        public static function install_schedule() {
            $next = self::get_next_runtime();

            if ( ! $next ) {
                return;
            }

            wp_schedule_event( $next, 'twicedaily', self::CRON_HOOK );
        }

        /**
         * Execute reminder routines and queue outbound emails.
         */
        public static function run_tasks() {
            self::$queued_during_run = 0;

            if ( self::can_queue_more() ) {
                self::queue_verify_reminders();
            }

            if ( self::can_queue_more() ) {
                self::queue_plan_reminders();
            }

            if ( self::can_queue_more() ) {
                self::queue_comeback_emails();
            }

            if ( self::can_queue_more() ) {
                self::dispatch_trial_expiration_queue();
            }

            if ( self::can_queue_more() ) {
                self::dispatch_subscription_expiration_queue();
            }

            if ( class_exists( 'WRE_Email_Queue' ) ) {
                // Process queued emails after reminders are added.
                \WRE_Email_Queue::process_queue();
            }

            do_action( 'wre_run_scheduled_tasks' );

            if ( class_exists( 'WRE_Logger' ) ) {
                $summary = sprintf(
                    /* translators: %d: number of email jobs queued during the cron run */
                    __( 'Cron tasks executed; %d jobs queued.', 'wisdom-rain-email-engine' ),
                    absint( self::$queued_during_run )
                );

                \WRE_Logger::add( '[CRON] ' . $summary, 'cron' );
            }
        }

        /**
         * Execute subscription expiration checks shared by manual and scheduled runs.
         */
        public static function run_scheduled_tasks() {
            self::scan_for_expired_users();

            $plans = self::get_expirable_plan_ids();

            if ( empty( $plans ) ) {
                return;
            }

            if ( class_exists( 'WRE_Logger' ) ) {
                \WRE_Logger::increment( 'cron' );
            }

            $query_args = apply_filters(
                'wre_cron_expiration_query_args',
                array(
                    'fields'     => 'ids',
                    'number'     => -1,
                    'meta_query' => array(
                        array(
                            'key'     => self::META_WRPA_LAST_PRODUCT,
                            'value'   => $plans,
                            'compare' => 'IN',
                        ),
                        array(
                            'key'     => self::META_WRPA_SUBSCRIPTION_END,
                            'compare' => 'EXISTS',
                        ),
                    ),
                )
            );

            $users = get_users( $query_args );

            if ( empty( $users ) ) {
                return;
            }

            $now = current_time( 'timestamp', true );

            foreach ( $users as $user ) {
                $user_id = self::normalize_user_id( $user );

                if ( $user_id <= 0 ) {
                    continue;
                }

                $product_id = absint( get_user_meta( $user_id, self::META_WRPA_LAST_PRODUCT, true ) );

                if ( ! in_array( $product_id, $plans, true ) ) {
                    continue;
                }

                $expires = self::normalize_timestamp( get_user_meta( $user_id, self::META_WRPA_SUBSCRIPTION_END, true ) );

                if ( $expires <= 0 || $expires > $now ) {
                    continue;
                }

                $context = array(
                    'product_id'       => $product_id,
                    'subscription_end' => $expires,
                    'triggered_at'     => $now,
                    'triggered_by'     => 'wre_cron',
                );

                /**
                 * Fires when the WRE cron determines a WRPA subscription has expired.
                 *
                 * @param int   $user_id WordPress user identifier.
                 * @param array $context Supplemental data describing the expiration.
                 */
                do_action( 'wrpa_subscription_expired', $user_id, $context );

                if ( class_exists( 'WRE_Logger' ) ) {
                    $message = sprintf(
                        '[CRON] Subscription expired for user #%1$d (plan #%2$d).',
                        $user_id,
                        $product_id
                    );

                    \WRE_Logger::add( $message, 'cron' );
                }
            }
        }

        /**
         * Detect locally-tracked subscriptions whose expiry has passed and notify users.
         */
        public static function scan_for_expired_users() {
            if ( ! class_exists( 'WRE_Email_Queue' ) ) {
                return;
            }

            if ( ! self::can_queue_more() ) {
                return;
            }

            $query_args = apply_filters(
                'wre_cron_local_expiration_query_args',
                array(
                    'fields'     => 'ids',
                    'number'     => -1,
                    'meta_query' => array(
                        array(
                            'key'     => self::META_SUBSCRIPTION_EXPIRY,
                            'compare' => 'EXISTS',
                        ),
                    ),
                )
            );

            $users = get_users( $query_args );

            if ( empty( $users ) ) {
                return;
            }

            $now = current_time( 'timestamp', true );

            foreach ( $users as $user ) {
                if ( ! self::can_queue_more() ) {
                    break;
                }

                $user_id = self::normalize_user_id( $user );

                if ( $user_id <= 0 ) {
                    continue;
                }

                $expires = self::normalize_timestamp( get_user_meta( $user_id, self::META_SUBSCRIPTION_EXPIRY, true ) );

                if ( $expires <= 0 || $expires > $now ) {
                    continue;
                }

                $status = get_user_meta( $user_id, self::META_SUBSCRIPTION_STATUS, true );

                if ( self::SUBSCRIPTION_STATUS_EXPIRED === $status ) {
                    continue;
                }

                update_user_meta( $user_id, self::META_SUBSCRIPTION_STATUS, self::SUBSCRIPTION_STATUS_EXPIRED );

                $queued = self::queue_email(
                    'subscription-expired',
                    $user_id,
                    array(
                        'expired_at' => $expires,
                        'source'     => 'wre_cron',
                    )
                );

                if ( class_exists( 'WRE_Logger' ) ) {
                    if ( $queued ) {
                        $message = sprintf(
                            '[CRON] Queued subscription-expired email for user #%1$d.',
                            $user_id
                        );
                    } else {
                        $message = sprintf(
                            '[CRON] Unable to queue subscription-expired email for user #%1$d; limit reached.',
                            $user_id
                        );
                    }

                    \WRE_Logger::add( $message, 'cron' );
                }
            }
        }

        /**
         * Queue verification reminders for users who have not completed verification.
         */
        protected static function queue_verify_reminders() {
            if ( ! class_exists( 'WRE_Verify' ) || ! class_exists( 'WRE_Email_Queue' ) || ! class_exists( 'WRE_Email_Sender' ) ) {
                return;
            }

            $remaining = self::MAX_QUEUE_PER_RUN - self::$queued_during_run;

            if ( $remaining <= 0 ) {
                return;
            }

            $users = get_users(
                array(
                    'fields'     => array( 'ID', 'user_registered' ),
                    'number'     => $remaining,
                    'orderby'    => 'user_registered',
                    'order'      => 'ASC',
                    'meta_query' => array(
                        array(
                            'key'     => WRE_Verify::META_VERIFY_TOKEN,
                            'compare' => 'EXISTS',
                        ),
                        array(
                            'key'     => WRE_Verify::META_VERIFIED_FLAG,
                            'compare' => 'NOT EXISTS',
                        ),
                    ),
                )
            );

            if ( empty( $users ) ) {
                return;
            }

            $now = current_time( 'timestamp', true );

            foreach ( $users as $user ) {
                if ( ! self::can_queue_more() ) {
                    return;
                }

                $user_id = self::normalize_user_id( $user );

                if ( $user_id <= 0 ) {
                    continue;
                }

                $registered = false;

                if ( is_object( $user ) && isset( $user->user_registered ) ) {
                    $registered = strtotime( $user->user_registered . ' UTC' );
                } elseif ( is_array( $user ) && isset( $user['user_registered'] ) ) {
                    $registered = strtotime( $user['user_registered'] . ' UTC' );
                }

                if ( empty( $registered ) ) {
                    continue;
                }

                $age = $now - $registered;

                if ( $age < ( self::VERIFY_REMINDER_DELAY_DAYS * DAY_IN_SECONDS ) ) {
                    continue;
                }

                $last_reminder = absint( get_user_meta( $user_id, self::META_VERIFY_REMINDER, true ) );

                if ( $last_reminder > 0 && ( $now - $last_reminder ) < DAY_IN_SECONDS ) {
                    continue;
                }

                if ( self::queue_email( 'verify-reminder', $user_id ) ) {
                    update_user_meta( $user_id, self::META_VERIFY_REMINDER, $now );
                }
            }
        }

        /**
         * Queue reminders for users whose paid plan is nearing expiration.
         */
        protected static function queue_plan_reminders() {
            if ( ! class_exists( 'WRE_Email_Queue' ) || ! class_exists( 'WRPA_Access' ) || ! class_exists( 'WRE_Email_Sender' ) ) {
                return;
            }

            foreach ( self::$plan_reminder_days as $days ) {
                $method = 'get_users_expiring_in_days';

                if ( ! method_exists( 'WRPA_Access', $method ) ) {
                    break;
                }

                $users = call_user_func( array( 'WRPA_Access', $method ), absint( $days ) );

                if ( empty( $users ) || is_wp_error( $users ) ) {
                    continue;
                }

                self::queue_plan_reminder_for_users( (array) $users, absint( $days ) );

                if ( self::$queued_during_run >= self::MAX_QUEUE_PER_RUN ) {
                    return;
                }
            }
        }

        /**
         * Queue comeback emails for users whose plan expired 30 days ago.
         */
        protected static function queue_comeback_emails() {
            if ( ! class_exists( 'WRE_Email_Queue' ) || ! class_exists( 'WRPA_Access' ) || ! class_exists( 'WRE_Email_Sender' ) ) {
                return;
            }

            if ( ! method_exists( 'WRPA_Access', 'get_users_expired_for_days' ) ) {
                return;
            }

            $users = WRPA_Access::get_users_expired_for_days( self::COMEBACK_DELAY_DAYS );

            if ( empty( $users ) || is_wp_error( $users ) ) {
                return;
            }

            $now = current_time( 'timestamp', true );

            foreach ( (array) $users as $user ) {
                if ( ! self::can_queue_more() ) {
                    return;
                }

                $user_id = self::normalize_user_id( $user );

                if ( $user_id <= 0 ) {
                    continue;
                }

                $sent = absint( get_user_meta( $user_id, self::META_COMEBACK_SENT, true ) );

                if ( $sent > 0 ) {
                    continue;
                }

                if ( self::queue_email( 'comeback', $user_id, array( 'days_since_expiry' => self::COMEBACK_DELAY_DAYS ) ) ) {
                    update_user_meta( $user_id, self::META_COMEBACK_SENT, $now );
                }
            }
        }

        /**
         * Deliver queued trial expiration notices that were deferred to cron.
         */
        protected static function dispatch_trial_expiration_queue() {
            if ( ! class_exists( 'WRE_Trials' ) || ! method_exists( 'WRE_Trials', 'process_queued_expirations' ) ) {
                return;
            }

            $remaining = self::MAX_QUEUE_PER_RUN - self::$queued_during_run;

            if ( $remaining <= 0 ) {
                return;
            }

            $processed = \WRE_Trials::process_queued_expirations( 'trial', $remaining );

            if ( $processed > 0 ) {
                self::$queued_during_run += absint( $processed );
            }
        }

        /**
         * Deliver queued subscription expiration notices via cron.
         */
        protected static function dispatch_subscription_expiration_queue() {
            if ( ! class_exists( 'WRE_Trials' ) || ! method_exists( 'WRE_Trials', 'process_queued_expirations' ) ) {
                return;
            }

            $remaining = self::MAX_QUEUE_PER_RUN - self::$queued_during_run;

            if ( $remaining <= 0 ) {
                return;
            }

            $processed = \WRE_Trials::process_queued_expirations( 'subscription', $remaining );

            if ( $processed > 0 ) {
                self::$queued_during_run += absint( $processed );
            }
        }

        /**
         * Determine the timestamp for the next cron execution slot.
         *
         * @return int Timestamp for the next run or 0 on failure.
         */
        protected static function get_next_runtime() {
            $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );

            try {
                $now = new DateTime( 'now', $timezone );
                $today = new DateTime( 'today', $timezone );
            } catch ( Exception $exception ) {
                return time() + HOUR_IN_SECONDS;
            }

            $hours = array( 1, 13 );

            foreach ( $hours as $hour ) {
                $candidate = clone $today;
                $candidate->setTime( $hour, 0, 0 );

                if ( $candidate > $now ) {
                    return $candidate->getTimestamp();
                }
            }

            $tomorrow = new DateTime( 'tomorrow', $timezone );
            $tomorrow->setTime( $hours[0], 0, 0 );

            return $tomorrow->getTimestamp();
        }

        /**
         * Queue a templated email if the per-run limit has not been exceeded.
         *
         * @param string $template Template slug handled by the queue processor.
         * @param int    $user_id  WordPress user identifier.
         * @param array  $context  Optional context passed to the queue job.
         *
         * @return bool True when the job is enqueued, false otherwise.
         */
        protected static function queue_email( $template, $user_id, $context = array() ) {
            if ( ! class_exists( 'WRE_Email_Queue' ) ) {
                return false;
            }

            if ( ! self::can_queue_more() ) {
                return false;
            }

            $queued = \WRE_Email_Queue::queue_email( $user_id, $template, $context );

            if ( ! $queued ) {
                return false;
            }

            self::$queued_during_run++;

            return true;
        }

        /**
         * Queue reminders for a collection of users expiring in a given number of days.
         *
         * @param array<int, mixed> $users Collection of user representations from WRPA.
         * @param int               $days  Number of days remaining before expiration.
         */
        protected static function queue_plan_reminder_for_users( $users, $days ) {
            $meta_key = self::META_PLAN_REMINDER_PREFIX . absint( $days );
            $now      = current_time( 'timestamp', true );

            foreach ( $users as $user ) {
                if ( ! self::can_queue_more() ) {
                    return;
                }

                $user_id = self::normalize_user_id( $user );

                if ( $user_id <= 0 ) {
                    continue;
                }

                $last_sent = absint( get_user_meta( $user_id, $meta_key, true ) );

                if ( $last_sent > 0 && ( $now - $last_sent ) < DAY_IN_SECONDS ) {
                    continue;
                }

                if ( ! self::queue_email( 'plan-reminder', $user_id, array( 'days_remaining' => absint( $days ) ) ) ) {
                    return;
                }

                update_user_meta( $user_id, $meta_key, $now );
            }
        }

        /**
         * Extract a user ID from possible WRPA return values.
         *
         * @param mixed $user User representation from WRPA_Access.
         *
         * @return int
         */
        protected static function normalize_user_id( $user ) {
            if ( is_numeric( $user ) ) {
                return absint( $user );
            }

            if ( is_object( $user ) && isset( $user->ID ) ) {
                return absint( $user->ID );
            }

            if ( is_array( $user ) && isset( $user['ID'] ) ) {
                return absint( $user['ID'] );
            }

            return 0;
        }

        /**
         * Return the list of WRPA plan IDs that should be considered for expirations.
         *
         * @return array<int>
         */
        protected static function get_expirable_plan_ids() {
            $plans = apply_filters( 'wre_cron_expirable_plan_ids', self::EXPIRABLE_PLAN_IDS );

            if ( empty( $plans ) ) {
                return array();
            }

            $plans = array_filter(
                array_map( 'absint', (array) $plans ),
                function ( $plan ) {
                    return $plan > 0;
                }
            );

            return array_values( array_unique( $plans ) );
        }

        /**
         * Normalise a timestamp stored in user meta.
         *
         * @param mixed $value Raw meta value retrieved from storage.
         *
         * @return int
         */
        protected static function normalize_timestamp( $value ) {
            if ( is_numeric( $value ) ) {
                $value = (int) $value;

                return $value > 0 ? $value : 0;
            }

            if ( is_string( $value ) && '' !== trim( $value ) ) {
                $timestamp = strtotime( $value );

                if ( false !== $timestamp ) {
                    return (int) $timestamp;
                }
            }

            return 0;
        }

        /**
         * Determine if additional jobs may be queued during this run.
         *
         * @return bool
         */
        protected static function can_queue_more() {
            return self::$queued_during_run < self::MAX_QUEUE_PER_RUN;
        }

        /**
         * Confirm the existing schedule aligns with the intended run windows.
         *
         * @param int $timestamp Timestamp retrieved from wp_next_scheduled.
         *
         * @return bool
         */
        protected static function is_schedule_aligned( $timestamp ) {
            $timestamp = absint( $timestamp );

            if ( $timestamp <= 0 ) {
                return false;
            }

            $allowed_hours = array( 1, 13 );
            $timezone      = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );

            try {
                $date = new DateTime( '@' . $timestamp );
                $date->setTimezone( $timezone );
            } catch ( Exception $exception ) {
                return false;
            }

            $hour   = (int) $date->format( 'G' );
            $minute = (int) $date->format( 'i' );

            if ( ! in_array( $hour, $allowed_hours, true ) ) {
                return false;
            }

            return $minute < 15;
        }
    }
}
