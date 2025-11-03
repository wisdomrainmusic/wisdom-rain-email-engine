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
                        add_filter(
                                'cron_schedules',
                                function( $schedules ) {
                                        $schedules['wre_five_minutes'] = array(
                                                'interval' => 300,
                                                'display'  => __( 'Every 5 Minutes', 'wisdom-rain-email-engine' ),
                                        );
                                        $schedules['wre_four_hours']   = array(
                                                'interval' => 4 * HOUR_IN_SECONDS,
                                                'display'  => __( 'Every 4 Hours', 'wisdom-rain-email-engine' ),
                                        );

                                        return $schedules;
                                }
                        );
                        add_action( 'wre_cron_run_tasks', array( __CLASS__, 'run_tasks' ) );

                        $cleared = false;

                        while ( false !== ( $timestamp = wp_next_scheduled( 'wre_cron_run_tasks' ) ) ) {
                                wp_unschedule_event( $timestamp, 'wre_cron_run_tasks' );
                                $cleared = true;
                        }

                        if ( $cleared ) {
                                if ( class_exists( 'WRE_Logger' ) ) {
                                        \WRE_Logger::add( '[CRON] Cleared previously scheduled wre_cron_run_tasks events.', 'cron' );
                                } else {
                                        error_log( 'WRE CRON → Cleared previously scheduled wre_cron_run_tasks events.' );
                                }
                        }

                        $scheduled = wp_schedule_event( time(), 'wre_four_hours', 'wre_cron_run_tasks' );

                        if ( $scheduled ) {
                                if ( class_exists( 'WRE_Logger' ) ) {
                                        \WRE_Logger::add( '[CRON] Registered wre_cron_run_tasks on 4-hour schedule.', 'cron' );
                                } else {
                                        error_log( 'WRE CRON → Registered wre_cron_run_tasks on 4-hour schedule.' );
                                }
                        } else {
                                if ( class_exists( 'WRE_Logger' ) ) {
                                        \WRE_Logger::add( '[CRON] Failed to register wre_cron_run_tasks on 4-hour schedule.', 'cron' );
                                } else {
                                        error_log( 'WRE CRON → Failed to register wre_cron_run_tasks on 4-hour schedule.' );
                                }
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

                       $now        = current_time( 'timestamp', true );
                       $window_end = $now + DAY_IN_SECONDS;

                       $rows = $wpdb->get_results(
                               $wpdb->prepare(
                                       "SELECT user_id, CAST(meta_value AS UNSIGNED) AS expiry FROM {$wpdb->usermeta}
                                        WHERE meta_key = %s
                                        AND CAST(meta_value AS UNSIGNED) > 0
                                        AND CAST(meta_value AS UNSIGNED) <= %d",
                                       'wrpa_access_expiry',
                                       $window_end
                               ),
                               ARRAY_A
                       );

                       if ( empty( $rows ) ) {
                               if ( class_exists( 'WRE_Logger' ) ) {
                                       \WRE_Logger::add( '[SCAN] No expired users found.', 'CRON' );
                               }

                               return array();
                       }

                       $expired_users = array();
                       $allowed_plans = array( '2895', '2894', '2893' );
                       $plan_map      = array(
                               'trial'   => '2895',
                               'monthly' => '2894',
                               'yearly'  => '2893',
                               'month'   => '2894',
                               'year'    => '2893',
                               'expired' => '2895',
                               'none'    => '2895',
                               '2895'    => '2895',
                               '2894'    => '2894',
                               '2893'    => '2893',
                       );

                       foreach ( (array) $rows as $row ) {
                               $user_id = isset( $row['user_id'] ) ? absint( $row['user_id'] ) : 0;
                               $expiry  = isset( $row['expiry'] ) ? absint( $row['expiry'] ) : 0;

                               if ( $user_id <= 0 || $expiry <= 0 ) {
                                       continue;
                               }

                               $plan = get_user_meta( $user_id, 'wrpa_active_plan', true );
                               $plan = is_scalar( $plan ) ? (string) $plan : '';
                               $plan = trim( strtolower( $plan ) );

                               if ( '' === $plan ) {
                                       $fallback_plan = get_user_meta( $user_id, 'wrpa_membership_status', true );
                                       $plan          = is_scalar( $fallback_plan ) ? (string) $fallback_plan : '';
                                       $plan          = trim( strtolower( $plan ) );
                               }

                               // Normalize and map plan key before expiry check.
                               $plan_raw = $plan;
                               $plan_id  = isset( $plan_map[ $plan_raw ] ) ? $plan_map[ $plan_raw ] : '';

                               if ( class_exists( 'WRE_Logger' ) ) {
                                       \WRE_Logger::add(
                                               sprintf(
                                                       '[SCAN] Normalized plan: %s → %s',
                                                       '' !== $plan_raw ? $plan_raw : '(empty)',
                                                       '' !== $plan_id ? $plan_id : 'null'
                                               ),
                                               'CRON'
                                       );
                               }

                               if ( '' === $plan_id ) {
                                       if ( class_exists( 'WRE_Logger' ) ) {
                                               \WRE_Logger::add(
                                                       sprintf(
                                                               '[SCAN] Skipped user #%d: invalid or missing plan (%s)',
                                                               $user_id,
                                                               '' !== $plan_raw ? $plan_raw : '(empty)'
                                                       ),
                                                       'CRON'
                                               );
                                       }

                                       continue;
                               }

                               $diff = $expiry - $now;

                               if ( in_array( $plan_id, $allowed_plans, true ) ) {
                                       $template = '';

                                       if ( $diff <= 0 ) {
                                               if ( class_exists( 'WRE_Logger' ) ) {
                                                       \WRE_Logger::add(
                                                               sprintf( '[SCAN] Found expired user #%d (plan expired)', $user_id ),
                                                               'CRON'
                                                       );
                                               }

                                               $template = 'plan-expired';
                                       } elseif ( $diff <= DAY_IN_SECONDS ) {
                                               if ( class_exists( 'WRE_Logger' ) ) {
                                                       \WRE_Logger::add(
                                                               sprintf( '[SCAN] Found expiring soon user #%d (plan reminder)', $user_id ),
                                                               'CRON'
                                                       );
                                               }

                                               $template = 'plan-reminder';
                                       }

                                       if ( '' !== $template ) {
                                               $payload = array(
                                                       'plan' => $plan_id,
                                               );

                                               if ( $diff > 0 ) {
                                                       $payload['days_remaining'] = max( 0, (int) ceil( $diff / DAY_IN_SECONDS ) );
                                               }

                                               $queued = \WRE_Email_Queue::queue_email(
                                                       $user_id,
                                                       $template,
                                                       $payload
                                               );

                                               if ( $queued ) {
                                                       if ( class_exists( 'WRE_Logger' ) ) {
                                                               \WRE_Logger::add(
                                                                       sprintf( '[CRON][SCAN] Queued %s for user #%d (plan=%s).', $template, $user_id, $plan_id ),
                                                                       'cron'
                                                               );
                                                       } else {
                                                               error_log( sprintf( 'WRE CRON → Queued %s for user #%d (plan=%s).', $template, $user_id, $plan_id ) );
                                                       }
                                               } else {
                                                       if ( class_exists( 'WRE_Logger' ) ) {
                                                               \WRE_Logger::add(
                                                                       sprintf( '[CRON][SCAN] Failed to queue %s for user #%d (plan=%s).', $template, $user_id, $plan_id ),
                                                                       'cron'
                                                               );
                                                       } else {
                                                               error_log( sprintf( 'WRE CRON → Failed to queue %s for user #%d (plan=%s).', $template, $user_id, $plan_id ) );
                                                       }
                                               }

                                               if ( $diff <= 0 ) {
                                                       $expired_users[ $user_id ] = $expiry;
                                               }
                                       }
                               } else {
                                       if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                               if ( class_exists( 'WRE_Logger' ) ) {
                                                       \WRE_Logger::add(
                                                               sprintf( '[CRON][SCAN] Skipped user #%d (plan=%s).', $user_id, $plan_id ),
                                                               'cron'
                                                       );
                                               } else {
                                                       error_log( sprintf( 'WRE CRON → Skipped user #%d (plan=%s).', $user_id, $plan_id ) );
                                               }
                                       }
                               }

                               if ( $diff <= 0 && ! isset( $expired_users[ $user_id ] ) ) {
                                       $expired_users[ $user_id ] = $expiry;
                               }
                       }

                       if ( empty( $expired_users ) ) {
                               if ( class_exists( 'WRE_Logger' ) ) {
                                       \WRE_Logger::add( '[SCAN] No expired users found.', 'CRON' );
                               }

                               return array();
                       }

                       if ( class_exists( 'WRE_Logger' ) ) {
                               \WRE_Logger::add(
                                       sprintf( '[SCAN] Found %d expired users based on wrpa_access_expiry.', count( $expired_users ) ),
                                       'CRON'
                               );
                       }

                       return $expired_users;
               }

               protected static function trigger_expiration_events( $expired_users ) {
                       $expired_users = is_array( $expired_users ) ? $expired_users : array();

                       $trial_triggered         = 0;
                       $subscription_triggered  = 0;

                       foreach ( $expired_users as $user_id => $expiry ) {
                               $user_id = absint( $user_id );
                               $expiry  = absint( $expiry );

                               if ( $user_id <= 0 || $expiry <= 0 ) {
                                       continue;
                               }

                               $status = self::get_status( $user_id );

                               if ( 'trial' === $status ) {
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
