<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WRE_Cron' ) ) {

	class WRE_Cron {

		const META_SENT_TRIAL_EXPIRED        = '_wre_sent_trial_expired';
		const META_SENT_SUBSCRIPTION_EXPIRED = '_wre_sent_subscription_expired';
		const META_SENT_PLAN_REMINDER        = '_wre_sent_plan_reminder';
		const META_SENT_COMEBACK             = '_wre_sent_comeback_30d';

		public static function init() {
			add_action( 'wre_cron_run_tasks', array( __CLASS__, 'run_tasks' ) );

			if ( ! wp_next_scheduled( 'wre_cron_run_tasks' ) ) {
				wp_schedule_event( strtotime( '01:00:00' ), 'twicedaily', 'wre_cron_run_tasks' );
			}
		}

		public static function run_tasks() {
			if ( class_exists( 'WRE_Logger' ) ) {
				WRE_Logger::add( '[CRON] Plan C task started.', 'cron' );
			}

			$expired_users = self::scan_expired_users();
			self::trigger_expiration_events( $expired_users );
			self::scan_for_plan_reminders();
			self::scan_for_comeback_users();

			if ( class_exists( 'WRE_Logger' ) ) {
				WRE_Logger::add( '[CRON] Plan C task finished.', 'cron' );
			}
		}
		protected static function scan_expired_users() {
			global $wpdb;

			$now = current_time( 'timestamp', true );

			$trial_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, CAST(meta_value AS UNSIGNED) AS expiry FROM {$wpdb->usermeta}
					 WHERE meta_key = %s
					 AND CAST(meta_value AS UNSIGNED) > 0
					 AND CAST(meta_value AS UNSIGNED) < %d",
					'_wrpa_trial_expiry',
					$now
				),
				ARRAY_A
			);

			$sub_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, CAST(meta_value AS UNSIGNED) AS expiry FROM {$wpdb->usermeta}
					 WHERE meta_key = %s
					 AND CAST(meta_value AS UNSIGNED) > 0
					 AND CAST(meta_value AS UNSIGNED) < %d",
					'_wrpa_subscription_expiry',
					$now
				),
				ARRAY_A
			);

			$trial_users = array();
			foreach ( (array) $trial_rows as $row ) {
				$user_id = isset( $row['user_id'] ) ? absint( $row['user_id'] ) : 0;
				$expiry  = isset( $row['expiry'] ) ? absint( $row['expiry'] ) : 0;

				if ( $user_id <= 0 || $expiry <= 0 ) {
					continue;
				}

				$trial_users[ $user_id ] = $expiry;
			}

			$subscription_users = array();
			foreach ( (array) $sub_rows as $row ) {
				$user_id = isset( $row['user_id'] ) ? absint( $row['user_id'] ) : 0;
				$expiry  = isset( $row['expiry'] ) ? absint( $row['expiry'] ) : 0;

				if ( $user_id <= 0 || $expiry <= 0 ) {
					continue;
				}

				$subscription_users[ $user_id ] = $expiry;
			}

			$expired_ids = array_unique( array_merge( array_keys( $trial_users ), array_keys( $subscription_users ) ) );

			if ( class_exists( 'WRE_Logger' ) ) {
				\WRE_Logger::add(
					sprintf( '[SCAN] Found %d expired users.', count( $expired_ids ) ),
					'CRON'
				);
			}

			return array(
				'trial'        => $trial_users,
				'subscription' => $subscription_users,
			);
		}

		protected static function trigger_expiration_events( $expired_lists ) {
			$expired_lists = is_array( $expired_lists ) ? $expired_lists : array();
			$trial_users   = isset( $expired_lists['trial'] ) && is_array( $expired_lists['trial'] ) ? $expired_lists['trial'] : array();
			$sub_users     = isset( $expired_lists['subscription'] ) && is_array( $expired_lists['subscription'] ) ? $expired_lists['subscription'] : array();

			$trial_triggered = 0;
			foreach ( $trial_users as $user_id => $expiry ) {
				$user_id = absint( $user_id );
				$expiry  = absint( $expiry );

				if ( $user_id <= 0 || $expiry <= 0 ) {
					continue;
				}

				if ( get_user_meta( $user_id, self::META_SENT_TRIAL_EXPIRED, true ) ) {
					continue;
				}

				do_action(
					'wrpa_trial_expired',
					$user_id,
					array(
						'expired_at'    => $expiry,
						'delivery_mode' => 'instant',
						'source'        => 'wre_cron_scan',
					)
				);

				update_user_meta( $user_id, self::META_SENT_TRIAL_EXPIRED, 1 );
				$trial_triggered++;

				if ( class_exists( 'WRE_Logger' ) ) {
					\WRE_Logger::add( "[CRON][SCAN] Trial expired for user #{$user_id}.", 'trial' );
				}
			}

			$subscription_triggered = 0;
			foreach ( $sub_users as $user_id => $expiry ) {
				$user_id = absint( $user_id );
				$expiry  = absint( $expiry );

				if ( $user_id <= 0 || $expiry <= 0 ) {
					continue;
				}

				if ( get_user_meta( $user_id, self::META_SENT_SUBSCRIPTION_EXPIRED, true ) ) {
					continue;
				}

				do_action(
					'wrpa_subscription_expired',
					$user_id,
					array(
						'expired_at'    => $expiry,
						'delivery_mode' => 'instant',
						'source'        => 'wre_cron_scan',
					)
				);

				update_user_meta( $user_id, self::META_SENT_SUBSCRIPTION_EXPIRED, 1 );
				$subscription_triggered++;

				if ( class_exists( 'WRE_Logger' ) ) {
					\WRE_Logger::add( "[CRON][SCAN] Subscription expired for user #{$user_id}.", 'subscription' );
				}
			}

			if ( class_exists( 'WRE_Logger' ) ) {
				\WRE_Logger::add(
					sprintf(
						'[SCAN] Triggered %d trial expirations and %d subscription expirations.',
						$trial_triggered,
						$subscription_triggered
					),
					'CRON'
				);
			}
		}


		protected static function get_expiry_meta( $user_id ) {
			$keys = array(
				'_wrpa_subscription_expiry',
				'wrpa_subscription_end',
				'_wre_subscription_expiry',
				'wrpa_subscription_expiry',
			);

			foreach ( $keys as $key ) {
				$val = get_user_meta( $user_id, $key, true );

				if ( ! empty( $val ) && is_numeric( $val ) ) {
					return absint( $val );
				}
			}

			return 0;
		}

		protected static function get_status( $user_id ) {
			$keys = array( '_wrpa_subscription_status', '_wre_subscription_status' );

			foreach ( $keys as $key ) {
				$val = get_user_meta( $user_id, $key, true );

				if ( ! empty( $val ) ) {
					return sanitize_text_field( strtolower( $val ) );
				}
			}

			return '';
		}

		public static function scan_for_trial_expired_users() {
			$now   = current_time( 'timestamp', true );
			$users = get_users(
				array(
					'meta_key'   => '_wre_subscription_status',
					'meta_value' => 'trial',
				)
			);

			foreach ( $users as $user ) {
				$uid    = $user->ID;
				$expiry = self::get_expiry_meta( $uid );

				if ( ! $expiry || $expiry > $now ) {
					continue;
				}

				if ( get_user_meta( $uid, self::META_SENT_TRIAL_EXPIRED, true ) ) {
					continue;
				}

				if ( class_exists( 'WRE_Trials' ) ) {
					WRE_Trials::dispatch_trial_expired_email( $uid, array( 'expired_at' => $expiry ), 'instant' );
					update_user_meta( $uid, self::META_SENT_TRIAL_EXPIRED, 1 );

					if ( class_exists( 'WRE_Logger' ) ) {
						WRE_Logger::add( "[CRON][SCAN] Trial expired for user #$uid.", 'trial' );
					}
				}
			}
		}

		public static function scan_for_subscription_expired_users() {
			$now   = current_time( 'timestamp', true );
			$users = get_users(
				array(
					'meta_key'   => '_wre_subscription_status',
					'meta_value' => 'active',
				)
			);

			foreach ( $users as $user ) {
				$uid    = $user->ID;
				$expiry = self::get_expiry_meta( $uid );

				if ( ! $expiry || $expiry > $now ) {
					continue;
				}

				if ( get_user_meta( $uid, self::META_SENT_SUBSCRIPTION_EXPIRED, true ) ) {
					continue;
				}

				if ( class_exists( 'WRE_Trials' ) ) {
					WRE_Trials::dispatch_subscription_expired_email( $uid, array( 'expired_at' => $expiry ), 'instant' );
					update_user_meta( $uid, self::META_SENT_SUBSCRIPTION_EXPIRED, 1 );

					if ( class_exists( 'WRE_Logger' ) ) {
						WRE_Logger::add( "[CRON][SCAN] Subscription expired for user #$uid.", 'subscription' );
					}
				}
			}
		}

		public static function scan_for_plan_reminders() {
			$now       = current_time( 'timestamp', true );
			$threshold = 3 * DAY_IN_SECONDS;
			$users     = get_users();

			foreach ( $users as $user ) {
				$uid    = $user->ID;
				$expiry = self::get_expiry_meta( $uid );

				if ( ! $expiry ) {
					continue;
				}

				$delta = $expiry - $now;

				if ( $delta > 0 && $delta <= $threshold ) {
					if ( get_user_meta( $uid, self::META_SENT_PLAN_REMINDER, true ) ) {
						continue;
					}

					if ( class_exists( 'WRE_Email_Sender' ) ) {
						WRE_Email_Sender::send_plan_reminder( $uid, ceil( $delta / DAY_IN_SECONDS ) );
						update_user_meta( $uid, self::META_SENT_PLAN_REMINDER, 1 );

						if ( class_exists( 'WRE_Logger' ) ) {
							WRE_Logger::add( "[CRON][SCAN] Reminder sent to user #$uid.", 'reminder' );
						}
					}
				}
			}
		}

		public static function scan_for_comeback_users() {
			$now   = current_time( 'timestamp', true );
			$users = get_users();

			foreach ( $users as $user ) {
				$uid    = $user->ID;
				$expiry = self::get_expiry_meta( $uid );

				if ( ! $expiry ) {
					continue;
				}

				$days = floor( ( $now - $expiry ) / DAY_IN_SECONDS );

				if ( $days >= 30 && ! get_user_meta( $uid, self::META_SENT_COMEBACK, true ) ) {
					if ( class_exists( 'WRE_Email_Sender' ) ) {
						WRE_Email_Sender::send_comeback_email( $uid, $days );
						update_user_meta( $uid, self::META_SENT_COMEBACK, 1 );

						if ( class_exists( 'WRE_Logger' ) ) {
							WRE_Logger::add( "[CRON][SCAN] Comeback email sent to user #$uid.", 'comeback' );
						}
					}
				}
			}
		}
	}
}
