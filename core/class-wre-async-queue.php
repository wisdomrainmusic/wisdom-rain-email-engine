<?php
/**
 * Lightweight async dispatcher backed by Action Scheduler or WP-Cron.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Async_Queue' ) ) {
    /**
     * Provides a unified API for dispatching async tasks.
     */
    class WRE_Async_Queue {
        /**
         * Primary action hook used to execute queued tasks.
         */
        const ACTION_HOOK = 'wre_async_queue_task';

        /**
         * Default Action Scheduler group used when available.
         */
        const ACTION_GROUP = 'wre-email-engine';

        /**
         * Register runtime hooks.
         */
        public static function init() {
            add_action( self::ACTION_HOOK, array( __CLASS__, 'run_task' ), 10, 2 );
        }

        /**
         * Dispatch a task to the background queue.
         *
         * @param string $task    Task identifier.
         * @param array  $payload Data payload provided to the task handler.
         * @param string $group   Optional queue group label.
         *
         * @return bool
         */
        public static function dispatch_task( $task, $payload = array(), $group = self::ACTION_GROUP ) {
            $task    = sanitize_key( $task );
            $payload = self::sanitize_payload( $payload );
            $group   = sanitize_key( $group );

            if ( '' === $task ) {
                return false;
            }

            /**
             * Prefer Action Scheduler when available to benefit from its table-backed queue.
             */
            if ( function_exists( 'as_enqueue_async_action' ) ) {
                as_enqueue_async_action(
                    self::ACTION_HOOK,
                    array(
                        'task'    => $task,
                        'payload' => $payload,
                    ),
                    $group ? $group : self::ACTION_GROUP
                );

                return true;
            }

            if ( ! function_exists( 'wp_schedule_single_event' ) ) {
                return false;
            }

            wp_schedule_single_event(
                time(),
                self::ACTION_HOOK,
                array(
                    $task,
                    $payload,
                )
            );

            return true;
        }

        /**
         * Execute the queued task.
         *
         * @param string $task    Task identifier.
         * @param array  $payload Task payload.
         */
        public static function run_task( $task, $payload = array() ) {
            $task    = sanitize_key( $task );
            $payload = is_array( $payload ) ? $payload : array();

            if ( '' === $task ) {
                return;
            }

            /**
             * Allow observers to hook into the async task execution.
             */
            do_action( 'wre_async_task_' . $task, $payload );
        }

        /**
         * Sanitize payload data for storage.
         *
         * @param mixed $payload Raw payload.
         *
         * @return array
         */
        protected static function sanitize_payload( $payload ) {
            if ( empty( $payload ) || ! is_array( $payload ) ) {
                return array();
            }

            $sanitized = array();

            foreach ( $payload as $key => $value ) {
                $key = sanitize_key( $key );

                if ( '' === $key ) {
                    continue;
                }

                if ( is_scalar( $value ) || null === $value ) {
                    $sanitized[ $key ] = $value;
                } elseif ( is_array( $value ) ) {
                    $sanitized[ $key ] = self::sanitize_payload( $value );
                }
            }

            return $sanitized;
        }
    }
}
