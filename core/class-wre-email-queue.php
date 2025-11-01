<?php
/**
 * Email queue v2 for Wisdom Rain Email Engine.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Email_Queue' ) ) {
    class WRE_Email_Queue {
        const OPTION = '_wre_email_queue_v2';

        public static function init() {
            // v2 queue does not require bootstrap hooks beyond availability checks.
        }

        public static function queue_email( $user_id, $template, $context = array() ) {
            $queue   = get_option( self::OPTION, array() );
            $queue[] = array(
                'uid' => intval( $user_id ),
                'tpl' => $template,
                'ctx' => $context,
                'ts'  => current_time( 'timestamp', false ),
            );

            update_option( self::OPTION, $queue, false );

            return true;
        }

        public static function process_queue() {
            $queue = get_option( self::OPTION, array() );

            if ( empty( $queue ) ) {
                return;
            }

            $next = array_shift( $queue );

            update_option( self::OPTION, $queue, false );

            if ( class_exists( 'WRE_Logger' ) ) {
                WRE_Logger::add( sprintf( "[QUEUE] Sending '%s' to user #%d", $next['tpl'], $next['uid'] ), 'queue' );
            }

            if ( class_exists( 'WRE_Email_Sender' ) ) {
                WRE_Email_Sender::send_template_email( $next['uid'], $next['tpl'], $next['ctx'] );
            }

            if ( class_exists( 'WRE_Logger' ) ) {
                WRE_Logger::add( '[QUEUE] Email dispatched', 'queue' );
            }

            if ( ! empty( $queue ) ) {
                wp_schedule_single_event( time() + 60, 'wre_cron_run_tasks' );
            }
        }
    }
}
