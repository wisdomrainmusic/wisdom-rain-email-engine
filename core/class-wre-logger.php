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
         * @param string               $message Message to persist.
         * @param string               $type    Log type for filtering.
         * @param array<string, mixed> $context Optional structured context values.
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
         * Build aggregate counts from stored log entry context payloads.
         *
         * @param array<int, array<string, mixed>>|null $logs Optional pre-fetched log entries.
         * @return array<string, array<string, int>>
         */
        public static function summarize_context( $logs = null ) {
            if ( null === $logs ) {
                $logs = get_option( self::OPTION_KEY, array() );
            }

            if ( ! is_array( $logs ) ) {
                return array();
            }

            $summary = array(
                'templates'      => array(),
                'delivery_modes' => array(),
                'statuses'       => array(),
                'log_types'      => array(),
            );

            foreach ( $logs as $entry ) {
                if ( ! is_array( $entry ) || empty( $entry['context'] ) || ! is_array( $entry['context'] ) ) {
                    if ( isset( $entry['type'] ) ) {
                        $type = sanitize_key( $entry['type'] );

                        if ( '' !== $type ) {
                            if ( ! isset( $summary['log_types'][ $type ] ) ) {
                                $summary['log_types'][ $type ] = 0;
                            }

                            $summary['log_types'][ $type ]++;
                        }
                    }

                    continue;
                }

                $context = $entry['context'];

                if ( isset( $context['template'] ) ) {
                    $template = sanitize_key( $context['template'] );

                    if ( '' !== $template ) {
                        if ( ! isset( $summary['templates'][ $template ] ) ) {
                            $summary['templates'][ $template ] = 0;
                        }

                        $summary['templates'][ $template ]++;
                    }
                }

                if ( isset( $context['delivery_mode'] ) ) {
                    $mode = sanitize_key( $context['delivery_mode'] );

                    if ( '' !== $mode ) {
                        if ( ! isset( $summary['delivery_modes'][ $mode ] ) ) {
                            $summary['delivery_modes'][ $mode ] = 0;
                        }

                        $summary['delivery_modes'][ $mode ]++;
                    }
                }

                if ( isset( $context['status'] ) ) {
                    $status = sanitize_key( $context['status'] );

                    if ( '' !== $status ) {
                        if ( ! isset( $summary['statuses'][ $status ] ) ) {
                            $summary['statuses'][ $status ] = 0;
                        }

                        $summary['statuses'][ $status ]++;
                    }
                }

                $log_type = '';

                if ( isset( $context['log_type'] ) ) {
                    $log_type = sanitize_key( $context['log_type'] );
                }

                if ( '' === $log_type && isset( $entry['type'] ) ) {
                    $log_type = sanitize_key( $entry['type'] );
                }

                if ( '' !== $log_type ) {
                    if ( ! isset( $summary['log_types'][ $log_type ] ) ) {
                        $summary['log_types'][ $log_type ] = 0;
                    }

                    $summary['log_types'][ $log_type ]++;
                }
            }

            foreach ( $summary as $key => $groups ) {
                if ( empty( $groups ) || ! is_array( $groups ) ) {
                    unset( $summary[ $key ] );
                    continue;
                }

                arsort( $summary[ $key ] );
            }

            return $summary;
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
