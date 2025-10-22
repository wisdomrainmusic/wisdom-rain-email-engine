<?php
/**
 * Email Queue Processor for WRE
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Email_Queue' ) ) {
    /**
     * Persists queued email jobs and dispatches them in a throttled fashion.
     */
    class WRE_Email_Queue {
        /**
         * Option key storing queued jobs.
         */
        const OPTION_KEY = '_wre_email_queue';

        /**
         * Option key storing rate limiting window data.
         */
        const OPTION_RATE = '_wre_email_queue_rate';

        /**
         * Cron hook used to trigger queue processing.
         */
        const CRON_HOOK = 'wre_cron_run';

        /**
         * Maximum number of emails allowed per hour.
         */
        const MAX_PER_HOUR = 100;

        /**
         * Delay between consecutive emails when the queue has additional jobs.
         */
        const SECONDS_PER_EMAIL = 36;

        /**
         * Number of retry attempts before a job is discarded.
         */
        const MAX_ATTEMPTS = 3;

        /**
         * Register runtime hooks for the queue processor.
         */
        public static function init() {
            add_action( self::CRON_HOOK, array( __CLASS__, 'process_queue' ) );
        }

        /**
         * Add a new job to the queue.
         *
         * @param int    $user_id  WordPress user identifier.
         * @param string $template Email template slug to dispatch.
         * @param array  $context  Optional context passed to the template sender.
         *
         * @return bool True when the job is queued, false otherwise.
         */
        public static function add_to_queue( $user_id, $template, $context = array() ) {
            $user_id  = absint( $user_id );
            $template = sanitize_key( $template );

            if ( $user_id <= 0 || '' === $template ) {
                self::log( 'Unable to queue email job: invalid user or template.', 'failed' );

                return false;
            }

            $job = array(
                'user_id'   => $user_id,
                'template'  => $template,
                'context'   => self::sanitize_context( $context ),
                'queued_at' => time(),
                'attempts'  => 0,
            );

            $queue   = self::get_queue();
            $queue[] = $job;

            self::save_queue( $queue );

            if ( class_exists( 'WRE_Logger' ) ) {
                \WRE_Logger::add(
                    sprintf( 'Queued "%s" email for user #%d.', $template, $user_id ),
                    'queue'
                );
            } else {
                self::log( sprintf( 'Queued %s email for user #%d.', $template, $user_id ) );
            }

            self::schedule_next_run();

            return true;
        }

        /**
         * Process queued jobs while respecting the configured rate limit.
         */
        public static function process_queue() {
            $queue = self::get_queue();

            if ( empty( $queue ) ) {
                self::clear_queue();
                self::log( 'No pending emails in queue.' );

                return;
            }

            $window = self::get_rate_window();

            if ( $window['count'] >= self::MAX_PER_HOUR ) {
                self::log( 'Hourly email limit reached. Deferring queue processing.' );
                self::schedule_next_window( $window['start'] );

                return;
            }

            $job = array_shift( $queue );

            $dispatched = self::dispatch_job( $job );

            if ( $dispatched ) {
                $window['count']++;
                self::save_rate_window( $window );

                self::log( sprintf( 'Email sent for template %s (user #%d).', $job['template'], $job['user_id'] ) );
            } else {
                $attempts = isset( $job['attempts'] ) ? absint( $job['attempts'] ) : 0;
                $attempts++;

                if ( $attempts < self::MAX_ATTEMPTS ) {
                    $job['attempts'] = $attempts;
                    $queue[]         = $job;

                    self::log(
                        sprintf(
                            'Email job failed; requeued for attempt %d (user #%d, %s).',
                            $attempts,
                            isset( $job['user_id'] ) ? absint( $job['user_id'] ) : 0,
                            isset( $job['template'] ) ? $job['template'] : 'unknown'
                        ),
                        'failed'
                    );
                } else {
                    self::log(
                        sprintf(
                            'Email job failed permanently after %d attempts (user #%d, %s).',
                            $attempts,
                            isset( $job['user_id'] ) ? absint( $job['user_id'] ) : 0,
                            isset( $job['template'] ) ? $job['template'] : 'unknown'
                        ),
                        'failed'
                    );
                }
            }

            if ( ! empty( $queue ) ) {
                self::save_queue( $queue );
            } else {
                self::clear_queue();
            }

            if ( empty( $queue ) ) {
                self::clear_schedule();

                return;
            }

            $window = self::get_rate_window();

            if ( $window['count'] >= self::MAX_PER_HOUR ) {
                self::log( 'Hourly email limit reached after processing; scheduling next window.' );
                self::schedule_next_window( $window['start'] );

                return;
            }

            self::schedule_next_run( self::SECONDS_PER_EMAIL );
        }

        /**
         * Attempt to dispatch a single job.
         *
         * @param array $job Job payload from the queue.
         *
         * @return bool
         */
        protected static function dispatch_job( $job ) {
            $user_id  = isset( $job['user_id'] ) ? absint( $job['user_id'] ) : 0;
            $template = isset( $job['template'] ) ? sanitize_key( $job['template'] ) : '';
            $context  = isset( $job['context'] ) && is_array( $job['context'] ) ? $job['context'] : array();

            if ( $user_id <= 0 || '' === $template ) {
                return false;
            }

            $callback = self::resolve_sender_callback( $template );

            if ( $callback ) {
                $args = self::prepare_callback_arguments( $template, $user_id, $context );

                if ( ! empty( $args ) ) {
                    return (bool) call_user_func_array( $callback, $args );
                }
            }

            return self::send_template_email( $user_id, $template, $context );
        }

        /**
         * Resolve a WRE_Email_Sender callback for a template when available.
         *
         * @param string $template Template slug.
         *
         * @return callable|null
         */
        protected static function resolve_sender_callback( $template ) {
            if ( ! class_exists( 'WRE_Email_Sender' ) ) {
                return null;
            }

            $map = array(
                'welcome-verify'  => array( '\\WRE_Email_Sender', 'send_welcome_verify' ),
                'verify-reminder' => array( '\\WRE_Email_Sender', 'send_verify_reminder' ),
                'plan-reminder'   => array( '\\WRE_Email_Sender', 'send_plan_reminder' ),
                'comeback'        => array( '\\WRE_Email_Sender', 'send_comeback_email' ),
            );

            if ( isset( $map[ $template ] ) && is_callable( $map[ $template ] ) ) {
                return $map[ $template ];
            }

            return null;
        }

        /**
         * Prepare arguments for the resolved sender callback.
         *
         * @param string $template Template slug.
         * @param int    $user_id  WordPress user identifier.
         * @param array  $context  Job context data.
         *
         * @return array
         */
        protected static function prepare_callback_arguments( $template, $user_id, $context ) {
            switch ( $template ) {
                case 'plan-reminder':
                    $days = isset( $context['days_remaining'] ) ? absint( $context['days_remaining'] ) : 0;

                    return array( $user_id, $days );
                case 'comeback':
                    $days = isset( $context['days_since_expiry'] ) ? absint( $context['days_since_expiry'] ) : 0;

                    return array( $user_id, $days );
                default:
                    return array( $user_id );
            }
        }

        /**
         * Send an email using the templates system as a fallback.
         *
         * @param int    $user_id  WordPress user identifier.
         * @param string $template Template slug.
         * @param array  $context  Placeholder values passed to the template.
         *
         * @return bool
         */
        protected static function send_template_email( $user_id, $template, $context ) {
            $user = get_userdata( $user_id );

            if ( ! $user || empty( $user->user_email ) ) {
                return false;
            }

            $email = sanitize_email( $user->user_email );

            if ( '' === $email ) {
                return false;
            }

            if ( ! class_exists( 'WRE_Templates' ) ) {
                return false;
            }

            $context['recipient_name'] = isset( $context['recipient_name'] ) && '' !== $context['recipient_name']
                ? $context['recipient_name']
                : ( $user->display_name ? sanitize_text_field( $user->display_name ) : $user->user_login );

            $subject = isset( $context['subject'] ) && '' !== $context['subject']
                ? wp_strip_all_tags( $context['subject'] )
                : __( 'Wisdom Rain Notification', 'wisdom-rain-email-engine' );

            $body = \WRE_Templates::render_template( $template, $context );

            if ( '' === $body ) {
                return false;
            }

            $headers = array( 'Content-Type: text/html; charset=UTF-8' );

            return wp_mail( $email, $subject, $body, $headers );
        }

        /**
         * Retrieve the queue from storage.
         *
         * @return array<int, array<string, mixed>>
         */
        protected static function get_queue() {
            $queue = get_option( self::OPTION_KEY, array() );

            return is_array( $queue ) ? $queue : array();
        }

        /**
         * Persist the queue to storage.
         *
         * @param array $queue Queue payload.
         */
        protected static function save_queue( $queue ) {
            update_option( self::OPTION_KEY, array_values( $queue ), false );
        }

        /**
         * Remove the queue option from storage.
         */
        protected static function clear_queue() {
            delete_option( self::OPTION_KEY );
        }

        /**
         * Retrieve the current rate limiting window, resetting when expired.
         *
         * @return array{start:int,count:int}
         */
        protected static function get_rate_window() {
            $window = get_option( self::OPTION_RATE, array() );
            $now    = time();

            $start = isset( $window['start'] ) ? absint( $window['start'] ) : 0;
            $count = isset( $window['count'] ) ? absint( $window['count'] ) : 0;

            if ( $start <= 0 || ( $now - $start ) >= HOUR_IN_SECONDS ) {
                $start = $now;
                $count = 0;

                $window = array(
                    'start' => $start,
                    'count' => $count,
                );

                update_option( self::OPTION_RATE, $window, false );
            }

            return array(
                'start' => $start,
                'count' => $count,
            );
        }

        /**
         * Persist the rate window to storage.
         *
         * @param array $window Rate window payload.
         */
        protected static function save_rate_window( $window ) {
            $start = isset( $window['start'] ) ? absint( $window['start'] ) : time();
            $count = isset( $window['count'] ) ? absint( $window['count'] ) : 0;

            update_option(
                self::OPTION_RATE,
                array(
                    'start' => $start,
                    'count' => $count,
                ),
                false
            );
        }

        /**
         * Schedule the next queue processing run.
         *
         * @param int $delay Seconds before the next run.
         */
        protected static function schedule_next_run( $delay = 0 ) {
            if ( ! function_exists( 'wp_schedule_single_event' ) ) {
                return;
            }

            $timestamp = time() + max( 0, absint( $delay ) );

            if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
            }

            wp_schedule_single_event( $timestamp, self::CRON_HOOK );
        }

        /**
         * Schedule processing at the start of the next rate limiting window.
         *
         * @param int $window_start Timestamp of the current window start.
         */
        protected static function schedule_next_window( $window_start ) {
            if ( ! function_exists( 'wp_schedule_single_event' ) ) {
                return;
            }

            $start     = absint( $window_start );
            $timestamp = ( $start > 0 ? $start : time() ) + HOUR_IN_SECONDS;

            if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
            }

            wp_schedule_single_event( $timestamp, self::CRON_HOOK );
        }

        /**
         * Clear any scheduled queue processor events.
         */
        protected static function clear_schedule() {
            if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
            }
        }

        /**
         * Sanitize job context values prior to storage.
         *
         * @param mixed $context Context payload provided by the caller.
         *
         * @return array<string, mixed>
         */
        protected static function sanitize_context( $context ) {
            if ( empty( $context ) || ! is_array( $context ) ) {
                return array();
            }

            $sanitized = array();

            foreach ( $context as $key => $value ) {
                $key = sanitize_key( $key );

                if ( '' === $key ) {
                    continue;
                }

                if ( is_array( $value ) ) {
                    $sanitized[ $key ] = self::sanitize_context( $value );
                } elseif ( is_string( $value ) ) {
                    $sanitized[ $key ] = wp_kses_post( $value );
                } elseif ( is_int( $value ) || is_float( $value ) ) {
                    $sanitized[ $key ] = $value + 0;
                } elseif ( is_bool( $value ) ) {
                    $sanitized[ $key ] = (bool) $value;
                }
            }

            return $sanitized;
        }

        /**
         * Log queue activity to the PHP error log.
         *
         * @param string $message Message to log.
         */
        protected static function log( $message, $type = 'queue' ) {
            $message = is_scalar( $message ) ? (string) $message : '';

            if ( '' === $message ) {
                return;
            }

            $type = is_string( $type ) ? $type : 'queue';

            if ( class_exists( 'WRE_Logger' ) ) {
                \WRE_Logger::add( $message, $type );

                return;
            }

            error_log( 'WRE Queue → ' . $message );
        }
    }
}
