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

            $next_run   = class_exists( 'WRE_Cron' ) ? wp_next_scheduled( \WRE_Cron::CRON_HOOK ) : false;
            $now        = current_time( 'timestamp' );
            $is_due_now = $next_run && $next_run <= $now;

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

            echo '<p id="wre-run-now-help" class="description">' . esc_html__( 'This manual trigger only runs jobs that are already due. Future-dated campaigns stay dormant until their scheduled date.', 'wisdom-rain-email-engine' ) . '</p>';
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

            $current_time = current_time( 'timestamp' );

            if ( $next_run > $current_time ) {
                $formatted = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run );
                self::persist_tools_notice(
                    'wre_tools_not_due',
                    sprintf( __( 'Tasks are not due yet. Next run is scheduled for %s.', 'wisdom-rain-email-engine' ), $formatted ),
                    'info'
                );
                wp_safe_redirect( $redirect );
                exit;
            }

            wp_unschedule_event( $next_run, \WRE_Cron::CRON_HOOK );
            \WRE_Cron::run_tasks();
            \WRE_Cron::ensure_schedule();

            $user_id      = get_current_user_id();
            $forced_stamp = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $current_time );
            $due_stamp    = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run );

            if ( class_exists( 'WRE_Logger' ) ) {
                \WRE_Logger::add(
                    sprintf(
                        /* translators: 1: user ID, 2: forced run time, 3: scheduled time */
                        __( 'Manual cron run triggered by user #%1$d at %2$s. Original schedule was due at %3$s.', 'wisdom-rain-email-engine' ),
                        absint( $user_id ),
                        $forced_stamp,
                        $due_stamp
                    ),
                    'cron'
                );
            }

            self::persist_tools_notice(
                'wre_tools_success',
                __( 'Due scheduled tasks were executed successfully. Future-dated campaigns remain queued for their original send times.', 'wisdom-rain-email-engine' ),
                'updated'
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
