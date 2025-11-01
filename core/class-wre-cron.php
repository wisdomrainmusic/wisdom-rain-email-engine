<?php
/**
 * Wisdom Rain Email Engine – Cron and Reminder Scheduler (Plan-ID based)
 * Handles trial (2895), monthly (2894), and annual (2893) subscriptions.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WRE_Cron' ) ) {

class WRE_Cron {

    /** Hook name for wp-cron */
    const CRON_HOOK = 'wre_cron_run_tasks';

    /** Meta Keys */
    const META_WRPA_ACCESS_EXPIRY   = 'wrpa_access_expiry';
    const META_WRPA_LAST_PRODUCT    = 'wrpa_last_product_id';
    const META_SUBSCRIPTION_STATUS  = '_wre_subscription_status';
    const META_SENT_TRIAL_EXPIRED   = '_wre_sent_trial_expired';
    const META_SENT_SUB_EXPIRED     = '_wre_sent_subscription_expired';
    const META_SENT_PLAN_REMINDER   = '_wre_sent_plan_reminder';

    /** Statuses */
    const STATUS_TRIAL   = 'trial';
    const STATUS_ACTIVE  = 'active';
    const STATUS_EXPIRED = 'expired';

    /** Product IDs */
    const PLAN_TRIAL_ID     = 2895;
    const PLAN_MONTHLY_ID   = 2894;
    const PLAN_ANNUAL_ID    = 2893;

    /** Timing */
    const DELAY_SUBSCRIPTION_EXPIRE  = 3;   // 3 days after expiry
    const DELAY_PLAN_REMINDER        = 30;  // 30 days after expiry

    /** Limits */
    const MAX_QUEUE_PER_RUN = 100;
    protected static $queued_during_run = 0;

    /* --------------------------------
     * Bootstrapping
     * -------------------------------- */
    public static function init() {
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_tasks' ] );
        add_action( 'init', [ __CLASS__, 'ensure_schedule' ] );
        add_action( 'wre_run_scheduled_tasks', [ __CLASS__, 'run_scheduled_tasks' ] );
    }

    public static function ensure_schedule() {
        $next = wp_next_scheduled( self::CRON_HOOK );
        if ( $next && self::is_schedule_aligned( $next ) ) return;
        if ( $next ) wp_clear_scheduled_hook( self::CRON_HOOK );
        wp_schedule_event( self::get_next_runtime(), 'twicedaily', self::CRON_HOOK );
    }

    /* --------------------------------
     * Entrypoint
     * -------------------------------- */
    public static function run_tasks() {
        self::$queued_during_run = 0;
        self::run_scheduled_tasks();

        if ( class_exists( 'WRE_Email_Queue' ) )
            \WRE_Email_Queue::process_queue();

        if ( class_exists( 'WRE_Logger' ) )
            \WRE_Logger::add( sprintf( '[CRON] Tasks executed; %d queued.', self::$queued_during_run ), 'cron' );
    }

    public static function run_scheduled_tasks() {
        self::scan_for_trial_expired();
        self::scan_for_subscription_expired();
        self::scan_for_plan_reminder();
    }

    /* --------------------------------
     * 1️⃣ TRIAL EXPIRED – product_id = 2895
     * -------------------------------- */
    protected static function scan_for_trial_expired() {
        $users = get_users([
            'fields' => 'ids',
            'number' => -1,
            'meta_query' => [
                ['key' => self::META_WRPA_LAST_PRODUCT, 'value' => self::PLAN_TRIAL_ID],
                ['key' => self::META_WRPA_ACCESS_EXPIRY, 'compare' => 'EXISTS'],
            ],
        ]);

        $now = current_time('timestamp');

        foreach ( $users as $user_id ) {
            $expires = absint( get_user_meta( $user_id, self::META_WRPA_ACCESS_EXPIRY, true ) );
            if ( $expires <= 0 || $expires > $now ) continue;

            $sent = get_user_meta( $user_id, self::META_SENT_TRIAL_EXPIRED, true );
            if ( $sent && intval($sent) === $expires ) continue;

            if ( self::queue_email( 'trial-expired', $user_id, [
                'expired_at' => $expires,
                'plan'       => '3-Day Trial',
                'source'     => 'cron-trial',
            ])) {
                update_user_meta( $user_id, self::META_SENT_TRIAL_EXPIRED, $expires );
                update_user_meta( $user_id, self::META_SUBSCRIPTION_STATUS, self::STATUS_EXPIRED );
                self::log('[TRIAL] Trial expired email sent for user #'.$user_id);
            }
        }
    }

    /* --------------------------------
     * 2️⃣ SUBSCRIPTION EXPIRED – product_id = 2894, 2893
     * -------------------------------- */
    protected static function scan_for_subscription_expired() {
        $users = get_users([
            'fields' => 'ids',
            'number' => -1,
            'meta_query' => [
                ['key' => self::META_WRPA_LAST_PRODUCT, 'value' => [self::PLAN_MONTHLY_ID, self::PLAN_ANNUAL_ID], 'compare' => 'IN'],
                ['key' => self::META_WRPA_ACCESS_EXPIRY, 'compare' => 'EXISTS'],
            ],
        ]);

        $now = current_time('timestamp');

        foreach ( $users as $user_id ) {
            $expires = absint( get_user_meta( $user_id, self::META_WRPA_ACCESS_EXPIRY, true ) );
            if ( $expires <= 0 ) continue;
            if ( $now < ( $expires + self::DELAY_SUBSCRIPTION_EXPIRE * DAY_IN_SECONDS ) ) continue;

            $sent = get_user_meta( $user_id, self::META_SENT_SUB_EXPIRED, true );
            if ( $sent && intval($sent) === $expires ) continue;

            if ( self::queue_email( 'subscription-expired', $user_id, [
                'expired_at' => $expires,
                'delay_days' => self::DELAY_SUBSCRIPTION_EXPIRE,
                'source'     => 'cron-subscription',
            ])) {
                update_user_meta( $user_id, self::META_SENT_SUB_EXPIRED, $expires );
                update_user_meta( $user_id, self::META_SUBSCRIPTION_STATUS, self::STATUS_EXPIRED );
                self::log('[SUBSCRIPTION] Expired email sent for user #'.$user_id);
            }
        }
    }

    /* --------------------------------
     * 3️⃣ PLAN REMINDER – all expired plans, 30 days after expiry
     * -------------------------------- */
    protected static function scan_for_plan_reminder() {
        $users = get_users([
            'fields' => 'ids',
            'number' => -1,
            'meta_query' => [
                ['key' => self::META_WRPA_ACCESS_EXPIRY, 'compare' => 'EXISTS'],
                ['key' => self::META_SUBSCRIPTION_STATUS, 'value' => self::STATUS_EXPIRED],
            ],
        ]);

        $now = current_time('timestamp');

        foreach ( $users as $user_id ) {
            $expires = absint( get_user_meta( $user_id, self::META_WRPA_ACCESS_EXPIRY, true ) );
            if ( $expires <= 0 ) continue;
            if ( $now < ( $expires + self::DELAY_PLAN_REMINDER * DAY_IN_SECONDS ) ) continue;

            $sent = get_user_meta( $user_id, self::META_SENT_PLAN_REMINDER, true );
            if ( $sent && intval($sent) === $expires ) continue;

            if ( self::queue_email( 'plan-reminder', $user_id, [
                'expired_at' => $expires,
                'days_since' => self::DELAY_PLAN_REMINDER,
                'source'     => 'cron-reminder',
            ])) {
                update_user_meta( $user_id, self::META_SENT_PLAN_REMINDER, $expires );
                self::log('[REMINDER] 30-day comeback email sent for user #'.$user_id);
            }
        }
    }

    /* --------------------------------
     * Helpers
     * -------------------------------- */
    protected static function queue_email( $template, $user_id, $context = [] ) {
        if ( ! class_exists( 'WRE_Email_Queue' ) ) return false;
        $ok = \WRE_Email_Queue::queue_email( $user_id, $template, $context );
        if ( $ok ) self::$queued_during_run++;
        return (bool)$ok;
    }

    protected static function log( $msg ) {
        if ( class_exists( 'WRE_Logger' ) )
            \WRE_Logger::add('[CRON][QUEUE] '.$msg, 'cron');
    }

    protected static function get_next_runtime() {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $now = new DateTime('now', $tz);
        $today = new DateTime('today', $tz);
        foreach ([1, 13] as $h) {
            $c = clone $today; $c->setTime($h,0,0);
            if ($c > $now) return $c->getTimestamp();
        }
        $t = new DateTime('tomorrow', $tz); $t->setTime(1,0,0);
        return $t->getTimestamp();
    }

    protected static function is_schedule_aligned( $ts ) {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $d = new DateTime('@'.absint($ts)); $d->setTimezone($tz);
        $h = (int)$d->format('G'); $m = (int)$d->format('i');
        return in_array($h,[1,13],true) && $m < 15;
    }
}
}
