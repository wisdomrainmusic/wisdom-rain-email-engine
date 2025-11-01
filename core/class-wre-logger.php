<?php
/**
 * Logging system for Wisdom Rain Email Engine
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Logger' ) ) {
    /**
     * Stores up to MAX_LOGS email engine events inside wp_options.
     */
    class WRE_Logger {
        const OPTION_KEY   = '_wre_log_entries';
        const OPTION_TOTAL = 'wre_log_totals';
        const MAX_LOGS     = 500;

        /**
         * Default aggregate counters persisted in the totals option.
         *
         * @var array<string, int>
         */
        protected static $default_totals = array(
            'sent'    => 0,
            'instant' => 0,
            'failed'  => 0,
            'queue'   => 0,
            'cron'    => 0,
            'total'   => 0,
        );

        /**
         * Add log entry
         *
         * @param string $message Message to persist.
         * @param string $type    Log type for filtering.
         */
        public static function add( $message, $type = 'info', $context = array() ) {
            $logs = get_option( self::OPTION_KEY, array() );

            if ( ! is_array( $logs ) ) {
                $logs = array();
            }

            $logs[] = array(
                'time' => current_time( 'mysql' ),
                'type' => strtoupper( sanitize_key( $type ) ),
                'msg'  => self::sanitize_message( $message ),
                'context' => self::sanitize_context( $context ),
            );

            if ( count( $logs ) > self::MAX_LOGS ) {
                $logs = array_slice( $logs, -self::MAX_LOGS );
            }

            update_option( self::OPTION_KEY, $logs, false );
        }

        /**
         * Get all logs
         *
         * @return array<int, array<string, string>>
         */
        public static function get() {
            $logs = get_option( self::OPTION_KEY, array() );

            if ( ! is_array( $logs ) ) {
                return array();
            }

            return array_reverse( $logs );
        }

        /**
         * Get aggregate statistics for the stored log entries.
         *
         * @param array<int, array<string, mixed>>|null $logs Optional pre-fetched log entries.
         * @return array<string, int>
         */
        public static function get_stats( $logs = null ) {
            $totals = self::get_totals();

            $stats = array(
                'sent'    => isset( $totals['sent'] ) ? absint( $totals['sent'] ) : 0,
                'instant' => isset( $totals['instant'] ) ? absint( $totals['instant'] ) : 0,
                'failed'  => isset( $totals['failed'] ) ? absint( $totals['failed'] ) : 0,
                'queue'   => isset( $totals['queue'] ) ? absint( $totals['queue'] ) : 0,
                'cron'    => isset( $totals['cron'] ) ? absint( $totals['cron'] ) : 0,
                'total'   => isset( $totals['total'] ) ? absint( $totals['total'] ) : 0,
                'order'   => 0,
                'trial'   => 0,
                'verify'  => 0,
            );

            if ( null === $logs ) {
                $logs = get_option( self::OPTION_KEY, array() );
            }

            if ( ! is_array( $logs ) ) {
                return $stats;
            }

            foreach ( $logs as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }

                $type = isset( $entry['type'] ) ? strtoupper( sanitize_key( $entry['type'] ) ) : '';

                switch ( $type ) {
                    case 'ORDER':
                        $stats['order']++;
                        break;
                    case 'TRIAL':
                        $stats['trial']++;
                        break;
                    case 'VERIFY':
                        $stats['verify']++;
                        break;
                    default:
                        break;
                }
            }

            return $stats;
        }

        /**
         * Clear logs
         */
        public static function clear() {
            delete_option( self::OPTION_KEY );
            delete_option( self::OPTION_TOTAL );
        }

        /**
         * Retrieve persisted aggregate counters.
         *
         * @return array<string, int>
         */
        public static function get_totals() {
            $totals = get_option( self::OPTION_TOTAL, array() );

            if ( ! is_array( $totals ) ) {
                $totals = array();
            }

            return array_merge( self::$default_totals, array_intersect_key( array_map( 'absint', $totals ), self::$default_totals ) );
        }

        /**
         * Increment a persisted counter used for the admin dashboard statistics.
         *
         * @param string $key Counter identifier.
         */
        public static function increment( $key ) {
            $key = sanitize_key( $key );

            if ( '' === $key ) {
                return;
            }

            $totals = self::get_totals();

            if ( ! array_key_exists( $key, $totals ) ) {
                return;
            }

            $totals[ $key ] = absint( $totals[ $key ] ) + 1;

            if ( 'total' !== $key && 'instant' !== $key ) {
                $totals['total'] = absint( $totals['total'] ) + 1;
            }

            update_option( self::OPTION_TOTAL, $totals, false );
        }

        /**
         * Sanitize the stored log message.
         *
         * @param mixed $message Message to sanitize.
         *
         * @return string
         */
        protected static function sanitize_message( $message ) {
            if ( is_scalar( $message ) ) {
                return wp_strip_all_tags( (string) $message );
            }

            return '';
        }

        /**
         * Sanitize structured context payloads prior to storage.
         *
         * @param mixed $context Context data to sanitise.
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
                    continue;
                }

                if ( is_bool( $value ) ) {
                    $sanitized[ $key ] = (bool) $value;
                } elseif ( is_numeric( $value ) ) {
                    $sanitized[ $key ] = 0 + $value;
                } elseif ( is_scalar( $value ) ) {
                    $sanitized[ $key ] = wp_kses_post( (string) $value );
                }
            }

            return $sanitized;
        }
    }
}
