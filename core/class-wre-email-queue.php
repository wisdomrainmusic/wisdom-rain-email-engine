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
            $queue    = get_option( self::OPTION, array() );
            $user_id  = intval( $user_id );
            $template = sanitize_key( $template );
            $context  = is_array( $context ) ? $context : array();

            $queue[] = array(
                'user_id' => $user_id,
                'type'    => $template,
                'vars'    => $context,
                'uid'     => $user_id,
                'tpl'     => $template,
                'ctx'     => $context,
                'ts'      => current_time( 'timestamp', false ),
            );

            update_option( self::OPTION, $queue, false );

            return true;
        }

        public static function process_queue() {
            $queue = get_option( self::OPTION, array() );

            if ( empty( $queue ) ) {
                return;
            }

            $job = array_shift( $queue );

            update_option( self::OPTION, $queue, false );

            if ( ! is_array( $job ) ) {
                if ( class_exists( 'WRE_Logger' ) ) {
                    WRE_Logger::log( '[QUEUE] Encountered invalid job payload; expected array.', 'ERROR' );
                }

                return;
            }

            $user_id = isset( $job['user_id'] ) ? intval( $job['user_id'] ) : 0;

            if ( ! $user_id && isset( $job['uid'] ) ) {
                $user_id = intval( $job['uid'] );
            }

            if ( ! $user_id ) {
                if ( class_exists( 'WRE_Logger' ) ) {
                    WRE_Logger::log( '[QUEUE] Missing user_id in job payload: ' . wp_json_encode( $job ), 'ERROR' );
                }

                return;
            }

            $template = '';

            if ( isset( $job['type'] ) ) {
                $template = sanitize_key( $job['type'] );
            } elseif ( isset( $job['tpl'] ) ) {
                $template = sanitize_key( $job['tpl'] );
            }

            if ( '' === $template ) {
                if ( class_exists( 'WRE_Logger' ) ) {
                    WRE_Logger::log( sprintf( '[QUEUE] Missing template for user #%d job.', $user_id ), 'ERROR' );
                }

                return;
            }

            $context = array();

            if ( isset( $job['vars'] ) && is_array( $job['vars'] ) ) {
                $context = $job['vars'];
            } elseif ( isset( $job['ctx'] ) && is_array( $job['ctx'] ) ) {
                $context = $job['ctx'];
            }

            $user = get_userdata( $user_id );

            if ( ! $user || empty( $user->user_email ) ) {
                if ( class_exists( 'WRE_Logger' ) ) {
                    WRE_Logger::log( sprintf( '[QUEUE] Invalid user #%d for template "%s".', $user_id, $template ), 'ERROR' );
                }

                return;
            }

            if ( class_exists( 'WRE_Logger' ) ) {
                WRE_Logger::log(
                    sprintf(
                        '[QUEUE] Preparing to send "%s" to user #%d (%s).',
                        $template,
                        $user_id,
                        $user->user_email
                    ),
                    'INFO'
                );
            }

            if ( class_exists( 'WRE_Email_Sender' ) ) {
                WRE_Email_Sender::send_template_email( $user_id, $template, $context );
            }

            if ( class_exists( 'WRE_Logger' ) ) {
                WRE_Logger::log( '[QUEUE] Email dispatched.', 'INFO' );
            }

            if ( ! empty( $queue ) ) {
                wp_schedule_single_event( time() + 60, 'wre_cron_run_tasks' );
            }
        }
    }
}
