<?php
/**
 * Email Queue Processor for WRE (synchronous dispatcher)
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Email_Queue' ) ) {
    /**
     * Persists queued email jobs and dispatches them synchronously with throttling.
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
         * Option key storing queue configuration (rate limit, etc.).
         */
        const OPTION_SETTINGS = 'wre_queue_settings';

        /**
         * Cron hook used to trigger queue processing.
         */
        const CRON_HOOK = 'wre_cron_run';

        /**
         * Maximum number of emails allowed per hour.
         */
        const MAX_PER_HOUR = 100;

        /**
         * Minimum configurable emails per hour.
         */
        const MIN_PER_HOUR = 50;

        /**
         * Maximum configurable emails per hour.
         */
        const MAX_CONFIG_PER_HOUR = 200;

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
         * Queue an email job. Alias for add_to_queue() to improve readability.
         *
         * @param int    $user_id  WordPress user identifier.
         * @param string $template Email template slug to dispatch.
         * @param array  $context  Optional context passed to the template sender.
         *
         * @return bool
         */
        public static function queue_email( $user_id, $template, $context = array() ) {
            return self::add_to_queue( $user_id, $template, $context );
        }

        /**
         * Add a new job to the queue and immediately attempt to process jobs.
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

            // Skip if email already queued for this template & user within last 12 hours.
            if ( self::was_recently_queued( $user_id, $template, 12 * HOUR_IN_SECONDS ) ) {
                if ( class_exists( 'WRE_Logger' ) ) {
                    $message = sprintf( '[QUEUE] Skipped duplicate email for user #%d (%s)', $user_id, $template );

                    if ( method_exists( 'WRE_Logger', 'add' ) ) {
                        \WRE_Logger::add( $message, 'QUEUE', array() );
                    } elseif ( method_exists( 'WRE_Logger', 'log_entry' ) ) {
                        \WRE_Logger::log_entry( $message, 'QUEUE' );
                    }
                }

                return false;
            }

            $job = array(
                'job_id'   => self::generate_job_id(),
                'user_id'  => $user_id,
                'template' => $template,
                'context'  => self::sanitize_context( $context ),
                'queued_at'=> time(),
                'attempts' => 0,
            );

            $queue   = self::get_queue();
            $queue[] = $job;

            self::save_queue( $queue );

            if ( class_exists( 'WRE_Logger' ) ) {
                \WRE_Logger::increment( 'queue' );
            }

            if ( class_exists( 'WRE_Logger' ) ) {
                $message = sprintf(
                    '[QUEUE] Queued %s email for user #%d (template: %s)',
                    strtoupper( $template ),
                    $user_id,
                    $template
                );

                if ( method_exists( 'WRE_Logger', 'add' ) ) {
                    \WRE_Logger::add( $message, 'QUEUE', array() );
                } elseif ( method_exists( 'WRE_Logger', 'log_entry' ) ) {
                    \WRE_Logger::log_entry( $message, 'QUEUE' );
                }
            }

            self::log( sprintf( 'Queued "%s" email for user #%d.', $template, $user_id ), 'queue' );

            self::process_queue();

            return true;
        }

        /**
         * Determine whether the user/template combination was queued recently.
         *
         * @param int    $user_id User identifier.
         * @param string $template Template slug.
         * @param int    $window Number of seconds to look back.
         *
         * @return bool
         */
        public static function was_recently_queued( $user_id, $template, $window = 43200 ) {
            global $wpdb;

            if ( ! isset( $wpdb ) ) {
                return false;
            }

            $table = $wpdb->prefix . 'wre_jobs';
            $since = time() - absint( $window );

            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE user_id=%d AND template=%s AND queued_at > %d",
                    $user_id,
                    $template,
                    $since
                )
            );

            return $exists > 0;
        }

        /**
         * Process queued jobs while respecting the configured rate limit.
         */
        public static function process_queue() {
            $queue = self::get_queue();

            if ( empty( $queue ) ) {
                self::clear_queue();
                self::clear_schedule();
                self::log( 'No pending emails in queue.' );

                return;
            }

            $window = self::get_rate_window();
            $limit  = self::get_rate_limit();

            if ( $window['count'] >= $limit ) {
                self::log( 'Hourly email limit reached. Deferring queue processing.' );
                self::schedule_next_window( $window['start'] );

                return;
            }

            $remaining      = array();
            $dispatched     = 0;
            $limit_reached  = false;

            foreach ( $queue as $job ) {
                $job = self::normalize_job( $job );

                if ( empty( $job ) ) {
                    continue;
                }

                if ( $window['count'] >= $limit ) {
                    $remaining[]    = $job;
                    $limit_reached  = true;
                    continue;
                }

                self::log_job_event( 'start', $job );

                $result = self::dispatch_job( $job );

                if ( $result ) {
                    $window['count']++;
                    $dispatched++;

                    self::log_job_event( 'success', $job );
                    self::log( sprintf( 'Email sent for template %s (user #%d).', $job['template'], $job['user_id'] ) );

                    continue;
                }

                $attempts = isset( $job['attempts'] ) ? absint( $job['attempts'] ) + 1 : 1;
                $job['attempts']  = $attempts;
                $job['queued_at'] = time();

                if ( $attempts < self::MAX_ATTEMPTS ) {
                    $remaining[] = $job;

                    self::log_job_event( 'retry', $job );
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
                    self::log_job_event( 'fail', $job );
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

            if ( ! empty( $remaining ) ) {
                self::save_queue( $remaining );
            } else {
                self::clear_queue();
            }

            self::save_rate_window( $window );

            if ( $dispatched > 0 ) {
                self::log( sprintf( 'Dispatched %d queue job(s) synchronously.', $dispatched ), 'queue' );
            }

            if ( $limit_reached && ! empty( $remaining ) ) {
                self::log( 'Hourly email limit reached during processing. Deferring remaining jobs.' );
                self::schedule_next_window( $window['start'] );

                return;
            }

            if ( ! empty( $remaining ) ) {
                self::schedule_next_run( self::SECONDS_PER_EMAIL );

                return;
            }

            self::clear_schedule();
        }

        /**
         * Normalise queued job payloads.
         *
         * @param array $job Raw job payload.
         *
         * @return array<string, mixed>
         */
        protected static function normalize_job( $job ) {
            if ( empty( $job ) || ! is_array( $job ) ) {
                return array();
            }

            $normalized = array(
                'job_id'    => isset( $job['job_id'] ) && is_scalar( $job['job_id'] ) ? (string) $job['job_id'] : self::generate_job_id(),
                'user_id'   => isset( $job['user_id'] ) ? absint( $job['user_id'] ) : 0,
                'template'  => isset( $job['template'] ) ? sanitize_key( $job['template'] ) : '',
                'context'   => isset( $job['context'] ) && is_array( $job['context'] ) ? $job['context'] : array(),
                'queued_at' => isset( $job['queued_at'] ) ? absint( $job['queued_at'] ) : time(),
                'attempts'  => isset( $job['attempts'] ) ? absint( $job['attempts'] ) : 0,
            );

            if ( '' === $normalized['template'] || $normalized['user_id'] <= 0 ) {
                return array();
            }

            $normalized['context'] = self::sanitize_context( $normalized['context'] );

            return $normalized;
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

            if ( class_exists( 'WRE_Consent' ) ) {
                $unsubscribe = \WRE_Consent::get_unsubscribe_url( $user_id );

                if ( $unsubscribe ) {
                    $context['unsubscribe_url'] = esc_url_raw( $unsubscribe );
                }
            }

            $body = \WRE_Templates::render_template( $template, $context );

            if ( '' === $body ) {
                return false;
            }

            $headers = array( 'Content-Type: text/html; charset=UTF-8' );

            $context = array(
                'template'      => $template,
                'user_id'       => $user_id,
                'delivery_mode' => 'cron',
                'log_type'      => 'queue',
            );

            if ( class_exists( 'WRE_Email_Sender' ) && method_exists( 'WRE_Email_Sender', 'send_raw_email' ) ) {
                return \WRE_Email_Sender::send_raw_email( $email, $subject, $body, $headers, $context );
            }

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
            $limit = self::get_rate_limit();

            if ( $count > $limit ) {
                $count = $limit;
            }

            if ( $start <= 0 || $start + HOUR_IN_SECONDS <= $now ) {
                $start = $now;
                $count = 0;
            }

            return array(
                'start' => $start,
                'count' => $count,
            );
        }

        /**
         * Persist the current rate limiting window data.
         *
         * @param array $window Window payload.
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
         * Generate a unique job identifier for tracking.
         *
         * @return string
         */
        public static function generate_job_id() {
            return uniqid( 'wre_job_', true );
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
         * Retrieve the number of jobs currently queued.
         *
         * @return int
         */
        public static function get_queue_length() {
            $queue = get_option( self::OPTION_KEY, array() );

            if ( empty( $queue ) || ! is_array( $queue ) ) {
                return 0;
            }

            return count( $queue );
        }

        /**
         * Persist structured log entries for job lifecycle events.
         *
         * @param string $status  Lifecycle status keyword.
         * @param array  $job     Normalised job payload.
         * @param string $message Optional custom message.
         */
        protected static function log_job_event( $status, $job, $message = '' ) {
            $status = sanitize_key( $status );

            $context = array(
                'job_id'    => isset( $job['job_id'] ) ? $job['job_id'] : '',
                'status'    => $status,
                'user_id'   => isset( $job['user_id'] ) ? absint( $job['user_id'] ) : 0,
                'template'  => isset( $job['template'] ) ? sanitize_key( $job['template'] ) : '',
                'attempt'   => isset( $job['attempts'] ) ? absint( $job['attempts'] ) : 0,
                'queued_at' => isset( $job['queued_at'] ) ? absint( $job['queued_at'] ) : 0,
            );

            if ( '' === $message ) {
                switch ( $status ) {
                    case 'start':
                        $message = sprintf( 'Dispatching queue job %s for template %s (user #%d).', $context['job_id'], $context['template'], $context['user_id'] );
                        break;
                    case 'success':
                        $message = sprintf( 'Queue job %s completed for template %s (user #%d).', $context['job_id'], $context['template'], $context['user_id'] );
                        break;
                    case 'retry':
                        $message = sprintf( 'Queue job %s scheduled for retry (attempt %d).', $context['job_id'], $context['attempt'] );
                        break;
                    case 'fail':
                        $message = sprintf( 'Queue job %s failed after %d attempts.', $context['job_id'], $context['attempt'] );
                        break;
                    default:
                        $message = sprintf( 'Queue job %s updated (%s).', $context['job_id'], $status );
                        break;
                }
            }

            self::log( $message, 'queue', $context );
        }

        /**
         * Retrieve the configured hourly rate limit.
         *
         * @return int
         */
        public static function get_rate_limit() {
            $settings = get_option( self::OPTION_SETTINGS, array() );

            if ( ! is_array( $settings ) ) {
                $settings = array();
            }

            $limit = isset( $settings['rate_limit'] ) ? absint( $settings['rate_limit'] ) : self::MAX_PER_HOUR;

            if ( $limit <= 0 ) {
                $limit = self::MAX_PER_HOUR;
            }

            return max( self::MIN_PER_HOUR, min( $limit, self::MAX_CONFIG_PER_HOUR ) );
        }

        /**
         * Persist a new rate limit value.
         *
         * @param int $limit Emails per hour.
         */
        public static function update_rate_limit( $limit ) {
            $limit = absint( $limit );

            if ( $limit <= 0 ) {
                $limit = self::MAX_PER_HOUR;
            }

            $limit = max( self::MIN_PER_HOUR, min( $limit, self::MAX_CONFIG_PER_HOUR ) );

            $settings = get_option( self::OPTION_SETTINGS, array() );

            if ( ! is_array( $settings ) ) {
                $settings = array();
            }

            $settings['rate_limit'] = $limit;

            update_option( self::OPTION_SETTINGS, $settings, false );
        }

        /**
         * Log queue activity to the PHP error log.
         *
         * @param string $message Message to log.
         * @param string $type    Log channel identifier.
         * @param array  $context Optional context.
         */
        protected static function log( $message, $type = 'queue', $context = array() ) {
            $message = is_scalar( $message ) ? (string) $message : '';

            if ( '' === $message ) {
                return;
            }

            $type = is_string( $type ) ? $type : 'queue';

            if ( class_exists( 'WRE_Logger' ) ) {
                $context = self::sanitize_context( $context );

                if ( method_exists( 'WRE_Logger', 'add' ) ) {
                    \WRE_Logger::add( $message, $type, $context );
                } elseif ( method_exists( 'WRE_Logger', 'log_entry' ) ) {
                    \WRE_Logger::log_entry( $message, $type );
                }

                return;
            }

            error_log( 'WRE Queue → ' . $message );
        }
    }
}
