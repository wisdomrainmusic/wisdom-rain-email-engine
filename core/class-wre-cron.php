<?php
/**
 * Cron engine v2 for Wisdom Rain Email Engine.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Cron' ) ) {
    class WRE_Cron {
        const CRON_HOOK   = 'wre_cron_run_tasks';
        const MAX_PER_RUN = 100;

        public static function init() {
            add_action( self::CRON_HOOK, array( __CLASS__, 'run_tasks' ) );
            add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
        }

        public static function install_schedule() {
            self::ensure_schedule();
        }

        public static function ensure_schedule() {
            $next = wp_next_scheduled( self::CRON_HOOK );

            if ( $next ) {
                return;
            }

            $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );

            $today = new DateTime( 'today', $tz );
            $now   = new DateTime( 'now', $tz );

            foreach ( array( 1, 13 ) as $hour ) {
                $candidate = ( clone $today )->setTime( $hour, 0, 0 );

                if ( $candidate > $now ) {
                    wp_schedule_event( $candidate->getTimestamp(), 'twicedaily', self::CRON_HOOK );

                    return;
                }
            }

            $tomorrow = new DateTime( 'tomorrow', $tz );
            $tomorrow->setTime( 1, 0, 0 );

            wp_schedule_event( $tomorrow->getTimestamp(), 'twicedaily', self::CRON_HOOK );
        }

        public static function run_tasks() {
            $queued = 0;

            if ( class_exists( 'WRE_Logger' ) ) {
                WRE_Logger::add( '[CRON] Run started', 'cron' );
            }

            $queued += self::queue_subscription_expired();

            if ( class_exists( 'WRE_Email_Queue' ) ) {
                WRE_Email_Queue::process_queue();
            }

            if ( class_exists( 'WRE_Logger' ) ) {
                WRE_Logger::add( sprintf( '[CRON] Cron tasks executed; %d jobs queued.', $queued ), 'cron' );
            }
        }

        protected static function queue_subscription_expired() {
            if ( ! class_exists( 'WRE_Email_Queue' ) ) {
                return 0;
            }

            $now = current_time( 'timestamp', false );

            $args = array(
                'fields'     => 'ids',
                'number'     => self::MAX_PER_RUN,
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key'     => 'wrpa_last_product_id',
                        'value'   => array( 2893, 2894, 2895 ),
                        'compare' => 'IN',
                    ),
                    array(
                        'key'     => 'wrpa_subscription_end',
                        'compare' => 'EXISTS',
                    ),
                ),
            );

            $user_ids = get_users( $args );

            if ( empty( $user_ids ) ) {
                if ( class_exists( 'WRE_Logger' ) ) {
                    WRE_Logger::add( '[CRON][SCAN] No candidates for subscription-expired.', 'cron' );
                }

                return 0;
            }

            $count = 0;

            foreach ( $user_ids as $user_id ) {
                if ( $count >= self::MAX_PER_RUN ) {
                    break;
                }

                $expires = intval( get_user_meta( $user_id, 'wrpa_subscription_end', true ) );

                if ( $expires > 0 && $expires <= $now ) {
                    if ( class_exists( 'WRE_Logger' ) ) {
                        WRE_Logger::add( sprintf( '[CRON][SCAN] Subscription expired for user #%d.', $user_id ), 'cron' );
                    }

                    if ( WRE_Email_Queue::queue_email(
                        $user_id,
                        'subscription-expired',
                        array(
                            'expired_at' => $expires,
                            'source'     => 'wre_cron',
                        )
                    ) ) {
                        $count++;

                        if ( class_exists( 'WRE_Logger' ) ) {
                            WRE_Logger::add( sprintf( '[CRON][QUEUE] Queued subscription-expired for user #%d.', $user_id ), 'cron' );
                        }
                    }
                }
            }

            return $count;
        }
    }
}
