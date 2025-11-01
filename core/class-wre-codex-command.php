<?php
/**
 * WP-CLI command registration for the Wisdom Rain Email Engine plugin.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! class_exists( 'WRE_Codex_Command' ) ) {
    /**
     * Registers Codex test commands with WP-CLI.
     */
    class WRE_Codex_Command {
        /**
         * Register the command with WP-CLI.
         */
        public static function register() {
            if ( ! class_exists( '\\WP_CLI' ) ) {
                return;
            }

            \WP_CLI::add_command( 'codex test', array( __CLASS__, 'handle_test_command' ) );
        }

        /**
         * Handle the "codex test" command.
         */
        public static function handle_test_command() {
            if ( class_exists( '\\WRE_Logger' ) ) {
                \WRE_Logger::clear();
            }

            if ( ! class_exists( 'WRE_Cron' ) ) {
                \WP_CLI::error( 'WRE_Cron class is not available under WP-CLI bootstrap.' );

                return;
            }

            \WRE_Cron::run_tasks();

            $log_output = array();

            if ( class_exists( '\\WRE_Logger' ) ) {
                $log_output = array_slice( (array) \WRE_Logger::get(), 0, 5 );
            }

            if ( empty( $log_output ) ) {
                \WP_CLI::warning( 'WRE_Logger did not capture entries during WRE_Cron::run_tasks().' );
            } else {
                \WP_CLI::log( sprintf( 'Captured %d log entries. Displaying latest:', count( $log_output ) ) );

                foreach ( $log_output as $entry ) {
                    if ( ! is_array( $entry ) ) {
                        continue;
                    }

                    $time    = isset( $entry['time'] ) ? $entry['time'] : '';
                    $type    = isset( $entry['type'] ) ? $entry['type'] : '';
                    $message = isset( $entry['msg'] ) ? $entry['msg'] : '';

                    \WP_CLI::log( sprintf( '[%s][%s] %s', $time, $type, $message ) );
                }
            }

            \WP_CLI::success( 'WRE_Cron::run_tasks() executed under WP-CLI. Bootstrap verified.' );
        }
    }
}
