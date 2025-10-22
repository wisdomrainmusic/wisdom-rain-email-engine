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
        const OPTION_KEY = '_wre_log_entries';
        const MAX_LOGS   = 500;

        /**
         * Add log entry
         *
         * @param string $message Message to persist.
         * @param string $type    Log type for filtering.
         */
        public static function add( $message, $type = 'info' ) {
            $logs = get_option( self::OPTION_KEY, array() );

            if ( ! is_array( $logs ) ) {
                $logs = array();
            }

            $logs[] = array(
                'time' => current_time( 'mysql' ),
                'type' => strtoupper( sanitize_key( $type ) ),
                'msg'  => self::sanitize_message( $message ),
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
            $totals = array(
                'sent'   => 0,
                'failed' => 0,
                'queue'  => 0,
                'cron'   => 0,
                'total'  => 0,
            );

            if ( null === $logs ) {
                $logs = get_option( self::OPTION_KEY, array() );
            }

            if ( ! is_array( $logs ) ) {
                return $totals;
            }

            foreach ( $logs as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }

                $type = isset( $entry['type'] ) ? strtoupper( sanitize_key( $entry['type'] ) ) : '';

                switch ( $type ) {
                    case 'SENT':
                        $totals['sent']++;
                        break;
                    case 'FAILED':
                        $totals['failed']++;
                        break;
                    case 'QUEUE':
                        $totals['queue']++;
                        break;
                    case 'CRON':
                        $totals['cron']++;
                        break;
                    default:
                        break;
                }

                $totals['total']++;
            }

            return $totals;
        }

        /**
         * Clear logs
         */
        public static function clear() {
            delete_option( self::OPTION_KEY );
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
    }
}
