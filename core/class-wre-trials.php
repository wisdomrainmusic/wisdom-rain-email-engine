<?php
/**
 * WRPA trial expiration bridge for the WRE engine.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Trials' ) ) {
    /**
     * Bridges trial expiration events from WRPA into WRE notifications.
     */
    class WRE_Trials {
        const OPTION_LAST_EXPIRATION = '_wre_last_trial_expiration';

        /**
         * Wire hooks that listen for trial lifecycle changes.
         */
        public static function init() {
            add_action( 'wrpa_trial_expired', array( __CLASS__, 'send_expiration_notice' ), 10, 2 );
        }

        /**
         * Dispatch the expiration notice for a WRPA trial user.
         *
         * @param int   $user_id WordPress user identifier.
         * @param array $context Supplemental data from WRPA.
         *
         * @return bool Whether the notice was dispatched successfully.
         */
        public static function send_expiration_notice( $user_id, $context = array() ) {
            $user_id = absint( $user_id );

            if ( $user_id <= 0 ) {
                return false;
            }

            $context = is_array( $context ) ? $context : array();

            self::record_last_expiration( $user_id, $context );

            if ( ! class_exists( 'WRE_Email_Sender' ) ) {
                return false;
            }

            $sent = \WRE_Email_Sender::send_trial_expired_email( $user_id, $context, 'instant' );

            if ( class_exists( 'WRE_Logger' ) ) {
                $message = $sent
                    ? sprintf( 'Expiration notice sent for trial user #%d.', $user_id )
                    : sprintf( 'Expiration notice failed for trial user #%d.', $user_id );

                \WRE_Logger::add( $message, 'trial' );
            }

            return (bool) $sent;
        }

        /**
         * Retrieve details about the most recent trial expiration event.
         *
         * @return array{user_id:int,context:array<string,mixed>,recorded_at:int}|
         *         array<string, mixed>
         */
        public static function get_last_expiration() {
            $data = get_option( self::OPTION_LAST_EXPIRATION, array() );

            if ( ! is_array( $data ) ) {
                return array();
            }

            $user_id     = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0;
            $context     = isset( $data['context'] ) && is_array( $data['context'] ) ? $data['context'] : array();
            $recorded_at = isset( $data['recorded_at'] ) ? absint( $data['recorded_at'] ) : 0;

            if ( $user_id <= 0 ) {
                return array();
            }

            return array(
                'user_id'     => $user_id,
                'context'     => $context,
                'recorded_at' => $recorded_at,
            );
        }

        /**
         * Replay the most recent trial expiration email, if one is available.
         *
         * @return bool|null True if replay succeeded, false if it failed, null when nothing is recorded.
         */
        public static function replay_last_expiration_notice() {
            $last = self::get_last_expiration();

            if ( empty( $last ) || empty( $last['user_id'] ) ) {
                return null;
            }

            if ( ! class_exists( 'WRE_Email_Sender' ) ) {
                return false;
            }

            $sent = \WRE_Email_Sender::send_trial_expired_email(
                $last['user_id'],
                isset( $last['context'] ) && is_array( $last['context'] ) ? $last['context'] : array(),
                'instant'
            );

            if ( class_exists( 'WRE_Logger' ) ) {
                $message = $sent
                    ? sprintf( 'Expiration notice replayed for trial user #%d.', $last['user_id'] )
                    : sprintf( 'Expiration notice replay failed for trial user #%d.', $last['user_id'] );

                \WRE_Logger::add( $message, 'trial' );
            }

            return (bool) $sent;
        }

        /**
         * Persist the last trial expiration context for debugging and manual tests.
         *
         * @param int   $user_id WordPress user identifier.
         * @param array $context Context array provided by WRPA.
         */
        protected static function record_last_expiration( $user_id, $context = array() ) {
            $payload = array(
                'user_id'     => absint( $user_id ),
                'context'     => self::sanitize_context( $context ),
                'recorded_at' => current_time( 'timestamp', true ),
            );

            update_option( self::OPTION_LAST_EXPIRATION, $payload, false );
        }

        /**
         * Sanitize a context array for persistence.
         *
         * @param array<string, mixed> $context Context to sanitize.
         *
         * @return array<string, mixed>
         */
        protected static function sanitize_context( $context ) {
            if ( ! is_array( $context ) ) {
                return array();
            }

            $sanitized = array();

            foreach ( $context as $key => $value ) {
                $key = sanitize_key( $key );

                if ( is_array( $value ) ) {
                    $sanitized[ $key ] = self::sanitize_context( $value );
                    continue;
                }

                if ( is_scalar( $value ) ) {
                    $sanitized[ $key ] = sanitize_text_field( (string) $value );
                }
            }

            return $sanitized;
        }
    }
}
