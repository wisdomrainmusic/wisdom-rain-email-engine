<?php
/**
 * Admin UI for the Wisdom Rain Email Engine plugin.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Admin' ) ) {
    /**
     * Handles the plugin admin dashboard experience.
     */
    class WRE_Admin {
        const MENU_SLUG = 'wre-dashboard';

        /**
         * Register admin hooks for the dashboard UI.
         */
        public static function init() {
            if ( ! is_admin() ) {
                return;
            }

            add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
            add_action( 'admin_post_wre_run_due_tasks', array( __CLASS__, 'handle_run_due_tasks' ) );
            add_action( 'admin_post_wre_manual_event_test', array( __CLASS__, 'handle_manual_event_test' ) );
        }

        /**
         * Register the top-level admin menu for the plugin.
         */
        public static function register_menu() {
            add_menu_page(
                __( 'WRE Email Engine', 'wisdom-rain-email-engine' ),
                __( 'WRE Email', 'wisdom-rain-email-engine' ),
                'manage_options',
                self::MENU_SLUG,
                array( __CLASS__, 'render_admin_page' ),
                'dashicons-email-alt2',
                3
            );
        }

        /**
         * Render the admin dashboard container with placeholder sections.
         */
        public static function render_admin_page() {
            $tabs = self::get_admin_tabs();
            $current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'templates';

            if ( ! array_key_exists( $current_tab, $tabs ) ) {
                $current_tab = 'templates';
            }
            ?>
            <div class="wrap wre-admin">
                <h1><?php esc_html_e( '📧 Wisdom Rain Email Engine', 'wisdom-rain-email-engine' ); ?></h1>

                <p><?php esc_html_e( 'Manage the presentation and delivery of Wisdom Rain emails.', 'wisdom-rain-email-engine' ); ?></p>

                <h2 class="nav-tab-wrapper">
                    <?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
                        <?php
                        $url   = add_query_arg(
                            array(
                                'page' => self::MENU_SLUG,
                                'tab'  => $tab_slug,
                            ),
                            admin_url( 'admin.php' )
                        );
                        $class = 'nav-tab' . ( $tab_slug === $current_tab ? ' nav-tab-active' : '' );
                        ?>
                        <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $tab_label ); ?></a>
                    <?php endforeach; ?>
                </h2>

                <?php settings_errors( 'wre_templates' ); ?>
                <?php settings_errors( 'wre_campaigns' ); ?>
                <?php settings_errors( 'wre_tools' ); ?>

                <?php
                switch ( $current_tab ) {
                    case 'templates':
                        self::render_templates_tab();
                        break;
                    case 'campaigns':
                        if ( class_exists( 'WRE_Campaigns' ) ) {
                            \WRE_Campaigns::render_admin_tab();
                        } else {
                            self::render_placeholder_tab( $tabs[ $current_tab ] );
                        }
                        break;
                    case 'tools':
                        self::render_tools_tab();
                        break;
                    default:
                        self::render_placeholder_tab( $tabs[ $current_tab ] );
                        break;
                }
                ?>
            </div>
            <?php
        }

        /**
         * Retrieve the available admin tabs.
         *
         * @return array<string, string>
         */
        protected static function get_admin_tabs() {
            return array(
                'templates' => __( 'Templates', 'wisdom-rain-email-engine' ),
                'preview'   => __( 'Preview', 'wisdom-rain-email-engine' ),
                'test-send' => __( 'Test Send', 'wisdom-rain-email-engine' ),
                'campaigns' => __( 'Campaigns', 'wisdom-rain-email-engine' ),
                'tools'     => __( 'Tools', 'wisdom-rain-email-engine' ),
            );
        }

        /**
         * Render the email templates management tab.
         */
        protected static function render_templates_tab() {
            if ( ! class_exists( 'WRE_Templates' ) ) {
                echo '<p>' . esc_html__( 'Templates module unavailable.', 'wisdom-rain-email-engine' ) . '</p>';
                return;
            }

            $templates = \WRE_Templates::get_registered_templates();

            if ( empty( $templates ) ) {
                echo '<p>' . esc_html__( 'No templates registered yet.', 'wisdom-rain-email-engine' ) . '</p>';
                return;
            }

            $manage_url = admin_url( 'admin.php' );

            echo '<p>' . esc_html__( 'Use the dedicated template manager to customise email layouts. Overrides are stored safely in wp-content/uploads/wre-templates/.', 'wisdom-rain-email-engine' ) . '</p>';

            echo '<table class="widefat fixed striped">';
            echo '<thead><tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<th>' . esc_html__( 'Template', 'wisdom-rain-email-engine' ) . '</th>';
            echo '<th>' . esc_html__( 'Description', 'wisdom-rain-email-engine' ) . '</th>';
            echo '<th class="column-actions">' . esc_html__( 'Actions', 'wisdom-rain-email-engine' ) . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ( $templates as $slug => $meta ) {
                $label       = isset( $meta['label'] ) ? $meta['label'] : $slug;
                $description = isset( $meta['description'] ) ? $meta['description'] : '';
                $edit_url = add_query_arg(
                    array(
                        'page'     => 'wre-templates',
                        'template' => $slug,
                    ),
                    $manage_url
                );

                echo '<tr>';
                echo '<td><strong>' . esc_html( $label ) . '</strong></td>';
                echo '<td>' . esc_html( $description ) . '</td>';
                echo '<td><a class="button button-primary" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'wisdom-rain-email-engine' ) . '</a></td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
        }

        /**
         * Render placeholder content for tabs that are not yet implemented.
         *
         * @param string $label Tab label.
         */
        protected static function render_placeholder_tab( $label ) {
            echo '<p>' . esc_html( sprintf( __( '%s tools are coming soon.', 'wisdom-rain-email-engine' ), $label ) ) . '</p>';
        }

        /**
         * Render tooling utilities available to administrators.
         */
        protected static function render_tools_tab() {
            if ( ! current_user_can( 'manage_options' ) ) {
                echo '<p>' . esc_html__( 'You do not have permission to access these tools.', 'wisdom-rain-email-engine' ) . '</p>';
                return;
            }

            $next_run          = class_exists( 'WRE_Cron' ) ? wp_next_scheduled( \WRE_Cron::CRON_HOOK ) : false;
            $now               = current_time( 'timestamp' );
            $is_due_now        = $next_run && $next_run <= $now;
            $has_pending_trials = class_exists( 'WRE_Orders' ) && \WRE_Orders::has_pending_trial_jobs();
            $last_order_id     = class_exists( 'WRE_Orders' ) ? \WRE_Orders::get_last_order_id() : 0;

            echo '<p>' . esc_html__( 'Trigger maintenance helpers that operate on existing queues and reminders.', 'wisdom-rain-email-engine' ) . '</p>';

            echo '<div class="wre-tools-run-now">';
            echo '<h3>' . esc_html__( 'Run Scheduled Tasks', 'wisdom-rain-email-engine' ) . '</h3>';

            if ( $next_run ) {
                $formatted = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run );
                echo '<p>' . esc_html( sprintf( __( 'Next automatic run: %s.', 'wisdom-rain-email-engine' ), $formatted ) );

                if ( $is_due_now ) {
                    echo ' ' . esc_html__( 'Tasks are currently due and can be run safely.', 'wisdom-rain-email-engine' );
                }

                echo '</p>';
            } else {
                echo '<p>' . esc_html__( 'No recurring schedule is currently registered.', 'wisdom-rain-email-engine' ) . '</p>';
            }

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'wre_run_due_tasks', 'wre_run_due_tasks_nonce' );
            echo '<input type="hidden" name="action" value="wre_run_due_tasks" />';
            submit_button(
                __( 'Run Scheduled Tasks Now', 'wisdom-rain-email-engine' ),
                'primary',
                'submit',
                false,
                array(
                    'aria-describedby' => 'wre-run-now-help',
                )
            );
            echo '</form>';

            $help_text = __( 'This manual trigger runs due jobs immediately. Trial expiration notices are dispatched if they are waiting, even when the next schedule time has not yet arrived.', 'wisdom-rain-email-engine' );
            echo '<p id="wre-run-now-help" class="description">' . esc_html( $help_text ) . '</p>';
            echo '</div>';

            echo '<div class="wre-tools-realtime">';
            echo '<h3>' . esc_html__( 'Run Real-Time Email Test', 'wisdom-rain-email-engine' ) . '</h3>';

            if ( $last_order_id ) {
                echo '<p>' . esc_html( sprintf( __( 'Most recent completed order ID: %d.', 'wisdom-rain-email-engine' ), $last_order_id ) ) . '</p>';
            } else {
                echo '<p>' . esc_html__( 'No completed orders detected yet for replay.', 'wisdom-rain-email-engine' ) . '</p>';
            }

            if ( $has_pending_trials ) {
                echo '<p>' . esc_html__( 'Trial-expired emails are waiting to be sent and will be dispatched in this test run.', 'wisdom-rain-email-engine' ) . '</p>';
            } else {
                echo '<p>' . esc_html__( 'No pending trial-expired emails are currently queued.', 'wisdom-rain-email-engine' ) . '</p>';
            }

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'wre_manual_event_test', 'wre_manual_event_test_nonce' );
            echo '<input type="hidden" name="action" value="wre_manual_event_test" />';
            submit_button(
                __( 'Run Real-Time Email Test', 'wisdom-rain-email-engine' ),
                'secondary',
                'submit',
                false,
                array(
                    'aria-describedby' => 'wre-realtime-help',
                )
            );
            echo '</form>';

            echo '<p id="wre-realtime-help" class="description">' . esc_html__( 'Resends the latest order confirmation and any queued trial-expired notices immediately for verification.', 'wisdom-rain-email-engine' ) . '</p>';
            echo '</div>';
        }

        /**
         * Handle the "Run Scheduled Tasks Now" action from the Tools tab.
         */
        public static function handle_run_due_tasks() {
            $redirect = add_query_arg(
                array(
                    'page' => self::MENU_SLUG,
                    'tab'  => 'tools',
                ),
                admin_url( 'admin.php' )
            );

            if ( ! current_user_can( 'manage_options' ) ) {
                self::persist_tools_notice(
                    'wre_tools_cap',
                    __( 'You do not have permission to run scheduled tasks.', 'wisdom-rain-email-engine' ),
                    'error'
                );
                wp_safe_redirect( $redirect );
                exit;
            }

            if ( ! isset( $_POST['wre_run_due_tasks_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wre_run_due_tasks_nonce'] ) ), 'wre_run_due_tasks' ) ) {
                self::persist_tools_notice(
                    'wre_tools_nonce',
                    __( 'Security check failed. Please try again.', 'wisdom-rain-email-engine' ),
                    'error'
                );
                wp_safe_redirect( $redirect );
                exit;
            }

            if ( ! class_exists( 'WRE_Cron' ) ) {
                self::persist_tools_notice(
                    'wre_tools_missing_cron',
                    __( 'Cron module is unavailable. Tasks cannot be executed.', 'wisdom-rain-email-engine' ),
                    'error'
                );
                wp_safe_redirect( $redirect );
                exit;
            }

            $next_run = wp_next_scheduled( \WRE_Cron::CRON_HOOK );

            if ( ! $next_run ) {
                self::persist_tools_notice(
                    'wre_tools_no_schedule',
                    __( 'No scheduled tasks are pending. The recurring schedule will be restored automatically.', 'wisdom-rain-email-engine' ),
                    'info'
                );
                \WRE_Cron::ensure_schedule();
                wp_safe_redirect( $redirect );
                exit;
            }

            $current_time       = current_time( 'timestamp' );
            $has_pending_trials = class_exists( 'WRE_Orders' ) && \WRE_Orders::has_pending_trial_jobs();

            if ( $next_run > $current_time && ! $has_pending_trials ) {
                $formatted = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run );
                self::persist_tools_notice(
                    'wre_tools_not_due',
                    sprintf( __( 'Tasks are not due yet. Next run is scheduled for %s.', 'wisdom-rain-email-engine' ), $formatted ),
                    'info'
                );
                wp_safe_redirect( $redirect );
                exit;
            }

            $trial_dispatches = 0;

            if ( class_exists( 'WRE_Orders' ) ) {
                $trial_dispatches = \WRE_Orders::process_pending_trial_jobs( true );
            }

            if ( $next_run ) {
                wp_unschedule_event( $next_run, \WRE_Cron::CRON_HOOK );
            }

            \WRE_Cron::run_tasks();
            \WRE_Cron::ensure_schedule();

            $user_id      = get_current_user_id();
            $forced_stamp = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $current_time );
            $due_stamp    = $next_run
                ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run )
                : __( 'not scheduled', 'wisdom-rain-email-engine' );

            if ( class_exists( 'WRE_Logger' ) ) {
                $log_message = sprintf(
                    /* translators: 1: user ID, 2: forced run time, 3: scheduled time, 4: number of trial emails */
                    __( 'Manual cron run triggered by user #%1$d at %2$s. Original schedule was due at %3$s. Trial expirations dispatched: %4$d.', 'wisdom-rain-email-engine' ),
                    absint( $user_id ),
                    $forced_stamp,
                    $due_stamp,
                    absint( $trial_dispatches )
                );

                \WRE_Logger::add( $log_message, 'cron' );
            }

            $notice_parts = array();

            if ( $has_pending_trials ) {
                if ( $trial_dispatches > 0 ) {
                    $notice_parts[] = sprintf(
                        /* translators: %d: number of trial emails dispatched */
                        _n( 'Dispatched %d trial-expired email immediately.', 'Dispatched %d trial-expired emails immediately.', $trial_dispatches, 'wisdom-rain-email-engine' ),
                        $trial_dispatches
                    );
                } else {
                    $notice_parts[] = __( 'Trial-expired emails remain queued. Check logs for delivery attempts.', 'wisdom-rain-email-engine' );
                }
            }

            $notice_parts[] = __( 'Due scheduled tasks were executed successfully. Future-dated campaigns remain queued for their original send times.', 'wisdom-rain-email-engine' );

            $notice_type = ( $has_pending_trials && 0 === $trial_dispatches ) ? 'error' : 'updated';

            self::persist_tools_notice(
                'wre_tools_success',
                implode( ' ', $notice_parts ),
                $notice_type
            );

            wp_safe_redirect( $redirect );
            exit;
        }

        /**
         * Execute manual real-time event tests from the Tools tab.
         */
        public static function handle_manual_event_test() {
            $redirect = add_query_arg(
                array(
                    'page' => self::MENU_SLUG,
                    'tab'  => 'tools',
                ),
                admin_url( 'admin.php' )
            );

            if ( ! current_user_can( 'manage_options' ) ) {
                self::persist_tools_notice(
                    'wre_tools_event_cap',
                    __( 'You do not have permission to run the real-time event test.', 'wisdom-rain-email-engine' ),
                    'error'
                );
                wp_safe_redirect( $redirect );
                exit;
            }

            if ( ! isset( $_POST['wre_manual_event_test_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wre_manual_event_test_nonce'] ) ), 'wre_manual_event_test' ) ) {
                self::persist_tools_notice(
                    'wre_tools_event_nonce',
                    __( 'Security check failed. Please try again.', 'wisdom-rain-email-engine' ),
                    'error'
                );
                wp_safe_redirect( $redirect );
                exit;
            }

            if ( ! class_exists( 'WRE_Orders' ) ) {
                self::persist_tools_notice(
                    'wre_tools_event_missing',
                    __( 'Orders module is unavailable. Real-time tests cannot run.', 'wisdom-rain-email-engine' ),
                    'error'
                );
                wp_safe_redirect( $redirect );
                exit;
            }

            $results = \WRE_Orders::run_manual_tests();

            $order_message = __( 'No completed orders detected for replay.', 'wisdom-rain-email-engine' );
            $type          = 'updated';

            if ( true === $results['order'] ) {
                $order_message = __( 'Latest order confirmation email sent successfully.', 'wisdom-rain-email-engine' );
            } elseif ( false === $results['order'] ) {
                $order_message = __( 'Order confirmation replay failed. Check logs for details.', 'wisdom-rain-email-engine' );
                $type          = 'error';
            }

            $trial_message = __( 'No pending trial-expired emails were queued.', 'wisdom-rain-email-engine' );

            if ( $results['trials'] > 0 ) {
                $trial_message = sprintf(
                    /* translators: %d: number of trial emails dispatched */
                    _n( 'Dispatched %d trial-expired email.', 'Dispatched %d trial-expired emails.', $results['trials'], 'wisdom-rain-email-engine' ),
                    $results['trials']
                );
            }

            self::persist_tools_notice(
                'wre_tools_event_summary',
                $order_message . ' ' . $trial_message,
                $type
            );

            wp_safe_redirect( $redirect );
            exit;
        }

        /**
         * Store a settings notice for the Tools tab across redirects.
         *
         * @param string $code    Message code.
         * @param string $message Message body.
         * @param string $type    Message type.
         */
        protected static function persist_tools_notice( $code, $message, $type = 'error' ) {
            add_settings_error( 'wre_tools', $code, $message, $type );

            $errors = get_settings_errors();

            if ( empty( $errors ) ) {
                return;
            }

            set_transient( 'settings_errors', $errors, 30 );
        }
    }
}
