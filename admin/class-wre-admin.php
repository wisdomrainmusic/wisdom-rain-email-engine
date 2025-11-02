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
            add_action( 'admin_post_wre_test_send_template', array( __CLASS__, 'handle_test_send' ) );
            add_action( 'admin_post_wre_run_queue_now', array( __CLASS__, 'handle_run_queue_now' ) );
            add_action( 'admin_post_wre_update_queue_settings', array( __CLASS__, 'handle_update_queue_settings' ) );
            add_action( 'admin_post_wre_cleanup_logs', array( __CLASS__, 'handle_cleanup_logs' ) );
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
                <?php settings_errors( 'wre_preview' ); ?>
                <?php settings_errors( 'wre_test_send' ); ?>
                <?php settings_errors( 'wre_tools' ); ?>

                <?php
                switch ( $current_tab ) {
                    case 'templates':
                        self::render_templates_tab();
                        break;
                    case 'preview':
                        self::render_preview_tab();
                        break;
                    case 'test-send':
                        self::render_test_send_tab();
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
         * Render live template previews with mock context.
         */
        protected static function render_preview_tab() {
            if ( ! class_exists( 'WRE_Templates' ) ) {
                echo '<p>' . esc_html__( 'Templates module unavailable. Unable to render previews.', 'wisdom-rain-email-engine' ) . '</p>';

                return;
            }

            $templates = self::get_template_options();

            if ( empty( $templates ) ) {
                echo '<p>' . esc_html__( 'No templates are registered yet.', 'wisdom-rain-email-engine' ) . '</p>';

                return;
            }

            $selected = isset( $_GET['wre_preview_template'] )
                ? sanitize_key( wp_unslash( $_GET['wre_preview_template'] ) )
                : key( $templates );

            if ( ! isset( $templates[ $selected ] ) ) {
                $selected = key( $templates );
            }

            $context = self::get_preview_context( $selected );
            $preview = \WRE_Templates::render_template( $selected, $context );

            $label = self::get_template_label( $templates, $selected );

            echo '<p>' . esc_html__( 'Inspect email output using representative sample data before enabling automations.', 'wisdom-rain-email-engine' ) . '</p>';

            echo '<form method="get" class="wre-preview-selector">';
            echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
            echo '<input type="hidden" name="tab" value="preview" />';
            echo '<label for="wre_preview_template" class="screen-reader-text">' . esc_html__( 'Select template', 'wisdom-rain-email-engine' ) . '</label>';
            echo '<select name="wre_preview_template" id="wre_preview_template">';

            foreach ( $templates as $slug => $meta ) {
                $template_label = self::get_template_label( $templates, $slug );
                echo '<option value="' . esc_attr( $slug ) . '"' . selected( $selected, $slug, false ) . '>' . esc_html( $template_label ) . '</option>';
            }

            echo '</select>';
            submit_button( __( 'Refresh Preview', 'wisdom-rain-email-engine' ), 'secondary', 'submit', false );
            echo '</form>';

            echo '<h3>' . esc_html( sprintf( __( 'Previewing: %s', 'wisdom-rain-email-engine' ), $label ) ) . '</h3>';

            echo '<div class="wre-preview-context">';
            echo '<details open><summary>' . esc_html__( 'Mock Data Used', 'wisdom-rain-email-engine' ) . '</summary>';
            echo self::format_preview_context( $context );
            echo '</details>';
            echo '</div>';

            echo '<div class="wre-preview-frame" style="margin-top:1rem;border:1px solid #ccd0d4;background:#fff;padding:0;overflow:auto;max-height:700px;">';

            if ( '' === $preview ) {
                echo '<p style="padding:1rem;">' . esc_html__( 'Template content not found. Ensure an email file exists in templates/emails/.', 'wisdom-rain-email-engine' ) . '</p>';
            } else {
                echo wp_kses_post( $preview );
            }

            echo '</div>';
        }

        /**
         * Render the test send UI to dispatch individual templates.
         */
        protected static function render_test_send_tab() {
            if ( ! class_exists( 'WRE_Templates' ) ) {
                echo '<p>' . esc_html__( 'Templates module unavailable. Unable to send previews.', 'wisdom-rain-email-engine' ) . '</p>';

                return;
            }

            $templates = self::get_template_options();

            if ( empty( $templates ) ) {
                echo '<p>' . esc_html__( 'No templates are registered yet.', 'wisdom-rain-email-engine' ) . '</p>';

                return;
            }

            $selected = isset( $_GET['template'] )
                ? sanitize_key( wp_unslash( $_GET['template'] ) )
                : key( $templates );

            if ( ! isset( $templates[ $selected ] ) ) {
                $selected = key( $templates );
            }

            $recipient = isset( $_GET['recipient'] ) ? sanitize_email( wp_unslash( $_GET['recipient'] ) ) : self::get_default_test_email();

            if ( '' === $recipient ) {
                $recipient = self::get_default_test_email();
            }

            $context = self::get_preview_context( $selected );
            $preview = \WRE_Templates::render_template( $selected, $context );
            $label   = self::get_template_label( $templates, $selected );

            echo '<p>' . esc_html__( 'Send a single template to your inbox without touching the background queue.', 'wisdom-rain-email-engine' ) . '</p>';

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wre-test-send-form">';
            wp_nonce_field( 'wre_test_send_template', 'wre_test_send_nonce' );
            echo '<input type="hidden" name="action" value="wre_test_send_template" />';

            echo '<table class="form-table"><tbody>';
            echo '<tr><th scope="row"><label for="wre_test_send_template">' . esc_html__( 'Template', 'wisdom-rain-email-engine' ) . '</label></th><td>';
            echo '<select name="wre_test_send_template" id="wre_test_send_template">';

            foreach ( $templates as $slug => $meta ) {
                $template_label = self::get_template_label( $templates, $slug );
                echo '<option value="' . esc_attr( $slug ) . '"' . selected( $selected, $slug, false ) . '>' . esc_html( $template_label ) . '</option>';
            }

            echo '</select>';
            echo '</td></tr>';

            echo '<tr><th scope="row"><label for="wre_test_send_recipient">' . esc_html__( 'Recipient address', 'wisdom-rain-email-engine' ) . '</label></th><td>';
            echo '<input type="email" class="regular-text" name="wre_test_send_recipient" id="wre_test_send_recipient" value="' . esc_attr( $recipient ) . '" placeholder="you@example.com" required />';
            echo '<p class="description">' . esc_html__( 'Uses the mock data shown below and bypasses the queue entirely.', 'wisdom-rain-email-engine' ) . '</p>';
            echo '</td></tr>';

            echo '</tbody></table>';

            submit_button( __( 'Send Test Email', 'wisdom-rain-email-engine' ) );
            echo '</form>';

            echo '<h3>' . esc_html( sprintf( __( 'Preview: %s', 'wisdom-rain-email-engine' ), $label ) ) . '</h3>';
            echo '<div class="wre-preview-context">';
            echo '<details open><summary>' . esc_html__( 'Mock Data Used', 'wisdom-rain-email-engine' ) . '</summary>';
            echo self::format_preview_context( $context );
            echo '</details>';
            echo '</div>';

            echo '<div class="wre-preview-frame" style="margin-top:1rem;border:1px solid #ccd0d4;background:#fff;padding:0;overflow:auto;max-height:700px;">';

            if ( '' === $preview ) {
                echo '<p style="padding:1rem;">' . esc_html__( 'Template content not found. Ensure an email file exists in templates/emails/.', 'wisdom-rain-email-engine' ) . '</p>';
            } else {
                echo wp_kses_post( $preview );
            }

            echo '</div>';
        }

        /**
         * Retrieve template metadata used across admin tabs.
         *
         * @return array<string, array<string, string>>
         */
        protected static function get_template_options() {
            if ( ! class_exists( 'WRE_Templates' ) ) {
                return array();
            }

            $templates = \WRE_Templates::get_registered_templates();

            return is_array( $templates ) ? $templates : array();
        }

        /**
         * Resolve a human-friendly label for a template slug.
         *
         * @param array<string, array<string, string>> $templates Template metadata.
         * @param string                                $slug      Template identifier.
         *
         * @return string
         */
        protected static function get_template_label( $templates, $slug ) {
            $slug = sanitize_key( $slug );

            if ( isset( $templates[ $slug ]['label'] ) && '' !== $templates[ $slug ]['label'] ) {
                return $templates[ $slug ]['label'];
            }

            return ucwords( str_replace( '-', ' ', $slug ) );
        }

        /**
         * Format preview context into a definition list for display.
         *
         * @param array<string, mixed> $context Preview context.
         *
         * @return string
         */
        protected static function format_preview_context( $context ) {
            if ( empty( $context ) || ! is_array( $context ) ) {
                return '<p>' . esc_html__( 'No preview data available.', 'wisdom-rain-email-engine' ) . '</p>';
            }

            $rows = array();

            foreach ( $context as $key => $value ) {
                $label = ucwords( str_replace( '_', ' ', sanitize_key( $key ) ) );

                if ( is_array( $value ) ) {
                    $value = wp_json_encode( $value );
                } elseif ( is_bool( $value ) ) {
                    $value = $value ? 'true' : 'false';
                } elseif ( is_numeric( $value ) || is_string( $value ) ) {
                    $value = (string) $value;
                } else {
                    $value = '';
                }

                if ( '' === $value ) {
                    continue;
                }

                $rows[] = '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
            }

            if ( empty( $rows ) ) {
                return '<p>' . esc_html__( 'No preview data available.', 'wisdom-rain-email-engine' ) . '</p>';
            }

            return '<table class="widefat fixed striped" style="max-width:480px"><tbody>' . implode( '', $rows ) . '</tbody></table>';
        }

        /**
         * Provide mock placeholder data for previews and test sends.
         *
         * @param string $template Template slug.
         *
         * @return array<string, mixed>
         */
        protected static function get_preview_context( $template ) {
            $template = sanitize_key( $template );

            $site_name    = get_bloginfo( 'name' );
            $site_url     = home_url( '/' );
            $admin_email  = get_option( 'admin_email', '' );
            $site_tagline = get_bloginfo( 'description' );
            $current_time = current_time( 'timestamp' );
            $three_days   = $current_time + ( 3 * DAY_IN_SECONDS );
            $seven_days   = $current_time + ( 7 * DAY_IN_SECONDS );
            $thirty_days  = $current_time + ( 30 * DAY_IN_SECONDS );
            $yesterday    = $current_time - DAY_IN_SECONDS;
            $order_number = 'WR-' . gmdate( 'Ymd' ) . '-001';
            $support_url  = home_url( '/support' );
            $member_hub   = home_url( '/members' );
            $blog_domain  = wp_parse_url( $site_url, PHP_URL_HOST );
            $from_address = $blog_domain ? 'hello@' . $blog_domain : 'hello@example.com';

            $base = array(
                'subject'                 => __( 'Wisdom Rain Email Preview', 'wisdom-rain-email-engine' ),
                'recipient_name'          => __( 'Wisdom Rain Member', 'wisdom-rain-email-engine' ),
                'recipient_email'         => sanitize_email( $admin_email ? $admin_email : 'member@example.com' ),
                'site_name'               => $site_name ? $site_name : __( 'Wisdom Rain', 'wisdom-rain-email-engine' ),
                'site_tagline'            => $site_tagline ? $site_tagline : __( 'Daily practices for calm and clarity', 'wisdom-rain-email-engine' ),
                'site_url'                => esc_url( $site_url ),
                'support_email'           => sanitize_email( $admin_email ),
                'support_url'             => esc_url( $support_url ),
                'unsubscribe_url'         => esc_url( home_url( '/unsubscribe-preview' ) ),
                'verification_url'        => esc_url( home_url( '/verify-email?token=preview' ) ),
                'reset_url'               => esc_url( home_url( '/reset-password?token=preview' ) ),
                'reset_window'            => __( '24 hours', 'wisdom-rain-email-engine' ),
                'cta_text'                => __( 'Visit Wisdom Rain', 'wisdom-rain-email-engine' ),
                'cta_url'                 => esc_url( $member_hub ),
                'order_id'                => $order_number,
                'order_number'            => $order_number,
                'order_date'              => date_i18n( get_option( 'date_format' ), $current_time ),
                'order_total'             => '$99.00',
                'product_name'            => __( 'Wisdom Rain Membership', 'wisdom-rain-email-engine' ),
                'plan_name'               => __( 'Awakening Annual Plan', 'wisdom-rain-email-engine' ),
                'plan_interval'           => __( 'Annual', 'wisdom-rain-email-engine' ),
                'days_remaining'          => 3,
                'days_since_expiry'       => 14,
                'expired_date'            => date_i18n( get_option( 'date_format' ), $yesterday ),
                'trial_end_date'          => date_i18n( get_option( 'date_format' ), $seven_days ),
                'subscription_expiry'     => date_i18n( get_option( 'date_format' ), $thirty_days ),
                'subscription_date'       => date_i18n( get_option( 'date_format' ), $current_time ),
                'renew_url'               => esc_url( home_url( '/renew-membership' ) ),
                'event_name'              => __( 'Breathwork + Sound Bath', 'wisdom-rain-email-engine' ),
                'event_date'              => date_i18n( get_option( 'date_format' ), $three_days ),
                'event_time'              => date_i18n( get_option( 'time_format' ), $three_days ),
                'event_location'          => __( 'Wisdom Rain Virtual Studio', 'wisdom-rain-email-engine' ),
                'event_access_url'        => esc_url( home_url( '/events/live-circle' ) ),
                'event_manage_url'        => esc_url( home_url( '/account/events' ) ),
                'event_rsvp_url'          => esc_url( home_url( '/events/rsvp' ) ),
                'campaign_offer'          => __( 'Save 20% on annual membership through Sunday.', 'wisdom-rain-email-engine' ),
                'headline'                => __( 'Step into the season with us', 'wisdom-rain-email-engine' ),
                'seasonal_message'        => __( 'Settle into the season with a grounding meditation collection.', 'wisdom-rain-email-engine' ),
                'offer_name'              => __( 'Awakening Annual Membership', 'wisdom-rain-email-engine' ),
                'welcome_sequence_count'  => 3,
                'from_address'            => sanitize_email( $from_address ),
                'sender_signature'        => __( 'With gratitude, The Wisdom Rain Team', 'wisdom-rain-email-engine' ),
                'experience_name'         => __( '14-Day Embodied Trial', 'wisdom-rain-email-engine' ),
                'feedback_url'            => esc_url( home_url( '/feedback/trial-experience' ) ),
                'newsletter_title'        => __( 'Weekly Wisdom Highlights', 'wisdom-rain-email-engine' ),
                'newsletter_date'         => date_i18n( get_option( 'date_format' ), $current_time ),
                'opening_paragraph'       => __( 'Here is what’s new in your practice library this week.', 'wisdom-rain-email-engine' ),
                'highlight_one_title'     => __( 'New: Lunar Flow Sequence', 'wisdom-rain-email-engine' ),
                'highlight_one_summary'   => __( 'A calming vinyasa practice to unwind in the evenings.', 'wisdom-rain-email-engine' ),
                'highlight_two_title'     => __( 'Featured: Sound Bath Journey', 'wisdom-rain-email-engine' ),
                'highlight_two_summary'   => __( 'Immerse in 25 minutes of restorative sound healing.', 'wisdom-rain-email-engine' ),
                'highlight_three_title'   => __( 'Member Q&A Replay', 'wisdom-rain-email-engine' ),
                'highlight_three_summary' => __( 'Catch the latest session with founder Devon Blake.', 'wisdom-rain-email-engine' ),
                'weekly_reflection'       => __( 'What shifted for you after last week’s practice?', 'wisdom-rain-email-engine' ),
                'digest_title'            => __( 'Wisdom Rain Weekly Digest', 'wisdom-rain-email-engine' ),
                'digest_intro'            => __( 'A snapshot of highlights from the community.', 'wisdom-rain-email-engine' ),
                'digest_new_teachings'    => __( '• Grounding breathwork with Maya\n• Compassion meditation with Eli', 'wisdom-rain-email-engine' ),
                'digest_community_highlights' => __( 'Members are hosting sunrise meditations daily at 6 a.m. PT.', 'wisdom-rain-email-engine' ),
                'digest_upcoming_events'  => __( 'Join the mantra circle on Saturday and a live Q&A on Sunday.', 'wisdom-rain-email-engine' ),
                'digest_cta_url'          => esc_url( $member_hub ),
                'digest_cta_text'         => __( 'Explore the member hub', 'wisdom-rain-email-engine' ),
            );

            switch ( $template ) {
                case 'welcome-verify':
                    $base['subject'] = __( 'Welcome to Wisdom Rain', 'wisdom-rain-email-engine' );
                    break;
                case 'password-reset':
                    $base['subject'] = __( 'Reset your Wisdom Rain password', 'wisdom-rain-email-engine' );
                    break;
                case 'plan-reminder':
                    $base['subject'] = __( 'Your plan renews soon', 'wisdom-rain-email-engine' );
                    break;
                case 'comeback':
                    $base['subject'] = __( 'We miss you at Wisdom Rain', 'wisdom-rain-email-engine' );
                    break;
                case 'payment-receipt':
                    $base['subject'] = __( 'Your Wisdom Rain receipt', 'wisdom-rain-email-engine' );
                    break;
                case 'trial-expired':
                    $base['subject'] = __( 'Your Wisdom Rain trial has ended', 'wisdom-rain-email-engine' );
                    break;
                case 'subscription-expired':
                    $base['subject'] = __( 'Your subscription is now paused', 'wisdom-rain-email-engine' );
                    break;
                case 'event-invite':
                    $base['subject'] = __( 'Join our upcoming practice circle', 'wisdom-rain-email-engine' );
                    $base['cta_text'] = __( 'Reserve my spot', 'wisdom-rain-email-engine' );
                    break;
                case 'event-reminder':
                    $base['subject'] = __( 'Your event begins soon', 'wisdom-rain-email-engine' );
                    $base['cta_text'] = __( 'View event details', 'wisdom-rain-email-engine' );
                    break;
                case 'newsletter':
                    $base['subject'] = __( 'This week at Wisdom Rain', 'wisdom-rain-email-engine' );
                    break;
                default:
                    break;
            }

            /**
             * Filter the preview context used by the admin preview and test send tools.
             *
             * @param array  $base     Default preview context.
             * @param string $template Template slug.
             */
            return apply_filters( 'wre_admin_preview_context', $base, $template );
        }

        /**
         * Suggest a sensible default email address for test sends.
         *
         * @return string
         */
        protected static function get_default_test_email() {
            $current_user = wp_get_current_user();

            if ( $current_user && $current_user->exists() && ! empty( $current_user->user_email ) ) {
                return sanitize_email( $current_user->user_email );
            }

            return sanitize_email( get_option( 'admin_email', '' ) );
        }

        /**
         * Render tooling utilities available to administrators.
         */
        protected static function render_tools_tab() {
            if ( ! current_user_can( 'manage_options' ) ) {
                echo '<p>' . esc_html__( 'You do not have permission to access these tools.', 'wisdom-rain-email-engine' ) . '</p>';
                return;
            }

            $next_run      = class_exists( 'WRE_Cron' ) ? wp_next_scheduled( \WRE_Cron::CRON_HOOK ) : false;
            $now           = current_time( 'timestamp' );
            $is_due_now    = $next_run && $next_run <= $now;
            $last_order_id = class_exists( 'WRE_Orders' ) ? \WRE_Orders::get_last_order_id() : 0;
            $last_trial    = class_exists( 'WRE_Trials' ) ? \WRE_Trials::get_last_expiration() : array();

            echo '<p>' . esc_html__( 'Trigger maintenance helpers that operate on existing queues and reminders.', 'wisdom-rain-email-engine' ) . '</p>';

            $rate_limit   = class_exists( 'WRE_Email_Queue' ) ? \WRE_Email_Queue::get_rate_limit() : 100;
            $queue_length = class_exists( 'WRE_Email_Queue' ) ? \WRE_Email_Queue::get_queue_length() : 0;
            $queue_next   = class_exists( 'WRE_Email_Queue' ) ? wp_next_scheduled( \WRE_Email_Queue::CRON_HOOK ) : false;

            echo '<div class="wre-tools-rate-limit">';
            echo '<h3>' . esc_html__( 'Send Rate Limit', 'wisdom-rain-email-engine' ) . '</h3>';

            if ( class_exists( 'WRE_Email_Queue' ) ) {
                echo '<p>' . esc_html__( 'Control how many transactional emails can leave the queue per hour.', 'wisdom-rain-email-engine' ) . '</p>';

                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                wp_nonce_field( 'wre_update_queue_settings', 'wre_update_queue_settings_nonce' );
                echo '<input type="hidden" name="action" value="wre_update_queue_settings" />';
                echo '<label for="wre_rate_limit" class="screen-reader-text">' . esc_html__( 'Emails per hour', 'wisdom-rain-email-engine' ) . '</label>';
                echo '<input type="number" min="' . esc_attr( \WRE_Email_Queue::MIN_PER_HOUR ) . '" max="' . esc_attr( \WRE_Email_Queue::MAX_CONFIG_PER_HOUR ) . '" step="10" name="wre_rate_limit" id="wre_rate_limit" value="' . esc_attr( $rate_limit ) . '" class="small-text" />';
                echo '<span class="description">' . esc_html__( 'Allowed range: 50–200 emails per hour.', 'wisdom-rain-email-engine' ) . '</span>';
                submit_button( __( 'Update Rate Limit', 'wisdom-rain-email-engine' ), 'secondary', 'submit', false );
                echo '</form>';
            } else {
                echo '<p>' . esc_html__( 'Queue module is unavailable. Rate limits cannot be configured.', 'wisdom-rain-email-engine' ) . '</p>';
            }

            echo '</div>';

            echo '<div class="wre-tools-queue">';
            echo '<h3>' . esc_html__( 'Queue Dispatch', 'wisdom-rain-email-engine' ) . '</h3>';

            if ( class_exists( 'WRE_Email_Queue' ) ) {
                $queue_message = $queue_length > 0
                    ? sprintf( _n( 'Currently %d email is waiting in the queue.', 'Currently %d emails are waiting in the queue.', $queue_length, 'wisdom-rain-email-engine' ), $queue_length )
                    : __( 'The queue is empty. New jobs will appear here when campaigns or automations run.', 'wisdom-rain-email-engine' );

                echo '<p>' . esc_html( $queue_message ) . '</p>';

                if ( $queue_next ) {
                    $queue_formatted = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $queue_next );
                    echo '<p class="description">' . esc_html( sprintf( __( 'Next automatic queue sweep: %s.', 'wisdom-rain-email-engine' ), $queue_formatted ) ) . '</p>';
                }

                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                wp_nonce_field( 'wre_run_queue_now', 'wre_run_queue_now_nonce' );
                echo '<input type="hidden" name="action" value="wre_run_queue_now" />';
                submit_button( __( 'Dispatch Pending Emails', 'wisdom-rain-email-engine' ), 'secondary', 'submit', false, array( 'aria-describedby' => 'wre-queue-help' ) );
                echo '</form>';

                echo '<p id="wre-queue-help" class="description">' . esc_html__( 'Hands the current queue off to the async dispatcher immediately.', 'wisdom-rain-email-engine' ) . '</p>';
            } else {
                echo '<p>' . esc_html__( 'Queue module is unavailable. Manual dispatch cannot be performed.', 'wisdom-rain-email-engine' ) . '</p>';
            }

            echo '</div>';

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

            $help_text = __( 'This manual trigger runs due jobs immediately. Use it to verify reminder campaigns without waiting for the next scheduled window.', 'wisdom-rain-email-engine' );
            echo '<p id="wre-run-now-help" class="description">' . esc_html( $help_text ) . '</p>';
            echo '</div>';

            echo '<div class="wre-tools-realtime">';
            echo '<h3>' . esc_html__( 'Run Real-Time Email Test', 'wisdom-rain-email-engine' ) . '</h3>';

            if ( $last_order_id ) {
                echo '<p>' . esc_html( sprintf( __( 'Most recent completed order ID: %d.', 'wisdom-rain-email-engine' ), $last_order_id ) ) . '</p>';
            } else {
                echo '<p>' . esc_html__( 'No completed orders detected yet for replay.', 'wisdom-rain-email-engine' ) . '</p>';
            }

            if ( ! empty( $last_trial ) && ! empty( $last_trial['user_id'] ) ) {
                $trial_user = absint( $last_trial['user_id'] );
                $recorded   = isset( $last_trial['recorded_at'] ) ? absint( $last_trial['recorded_at'] ) : 0;

                if ( $recorded > 0 ) {
                    $formatted = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $recorded );
                    echo '<p>' . esc_html( sprintf( /* translators: 1: user ID, 2: recorded datetime. */ __( 'Most recent expired trial: user #%1$d on %2$s.', 'wisdom-rain-email-engine' ), $trial_user, $formatted ) ) . '</p>';
                } else {
                    echo '<p>' . esc_html( sprintf( __( 'Most recent expired trial recorded for user #%d.', 'wisdom-rain-email-engine' ), $trial_user ) ) . '</p>';
                }
            } else {
                echo '<p>' . esc_html__( 'No expired trials detected yet for replay.', 'wisdom-rain-email-engine' ) . '</p>';
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

            echo '<p id="wre-realtime-help" class="description">' . esc_html__( 'Resends the latest order confirmation and most recent trial expiration notice instantly for verification.', 'wisdom-rain-email-engine' ) . '</p>';
            echo '</div>';

            echo '<div class="wre-tools-logs">';
            echo '<h3>' . esc_html__( 'Log Maintenance', 'wisdom-rain-email-engine' ) . '</h3>';
            echo '<p>' . esc_html__( 'Clear stored queue and delivery logs to start fresh when debugging.', 'wisdom-rain-email-engine' ) . '</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'wre_cleanup_logs', 'wre_cleanup_logs_nonce' );
            echo '<input type="hidden" name="action" value="wre_cleanup_logs" />';
            submit_button( __( 'Clean Up Logs', 'wisdom-rain-email-engine' ), 'delete', 'submit', false, array( 'aria-describedby' => 'wre-logs-help' ) );
            echo '</form>';
            echo '<p id="wre-logs-help" class="description">' . esc_html__( 'Removes all structured log entries and resets aggregate counters.', 'wisdom-rain-email-engine' ) . '</p>';
            echo '</div>';
        }

        /**
         * Handle manual queue dispatch requests.
         */
        public static function handle_run_queue_now() {
            $redirect = add_query_arg(
                array(
                    'page' => self::MENU_SLUG,
                    'tab'  => 'tools',
                ),
                admin_url( 'admin.php' )
            );

            if ( ! current_user_can( 'manage_options' ) ) {
                self::persist_tools_notice( 'wre_tools_queue_cap', __( 'You do not have permission to dispatch the queue manually.', 'wisdom-rain-email-engine' ), 'error' );
                wp_safe_redirect( $redirect );

                return;
            }

            if ( ! isset( $_POST['wre_run_queue_now_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wre_run_queue_now_nonce'] ) ), 'wre_run_queue_now' ) ) {
                self::persist_tools_notice( 'wre_tools_queue_nonce', __( 'Security check failed. Please try again.', 'wisdom-rain-email-engine' ), 'error' );
                wp_safe_redirect( $redirect );

                return;
            }

            if ( ! class_exists( 'WRE_Email_Queue' ) ) {
                self::persist_tools_notice( 'wre_tools_queue_missing', __( 'Queue module is unavailable. No jobs were dispatched.', 'wisdom-rain-email-engine' ), 'error' );
                wp_safe_redirect( $redirect );

                return;
            }

            $queued_before = \WRE_Email_Queue::get_queue_length();

            if ( $queued_before <= 0 ) {
                self::persist_tools_notice( 'wre_tools_queue_empty', __( 'The queue is currently empty — nothing to dispatch.', 'wisdom-rain-email-engine' ), 'info' );
                wp_safe_redirect( $redirect );

                return;
            }

            \WRE_Email_Queue::process_queue();

            $queued_after = \WRE_Email_Queue::get_queue_length();
            $dispatched   = max( 0, $queued_before - $queued_after );

            if ( class_exists( 'WRE_Logger' ) ) {
                \WRE_Logger::add(
                    sprintf( 'Manual queue dispatch triggered by user #%d. Jobs before: %d, remaining: %d.', absint( get_current_user_id() ), $queued_before, $queued_after ),
                    'queue',
                    array(
                        'status'    => 'manual-dispatch',
                        'before'    => $queued_before,
                        'remaining' => $queued_after,
                    )
                );
            }

            if ( $dispatched > 0 ) {
                self::persist_tools_notice(
                    'wre_tools_queue_dispatched',
                    sprintf( _n( 'Dispatched %d email to the async workers.', 'Dispatched %d emails to the async workers.', $dispatched, 'wisdom-rain-email-engine' ), $dispatched ),
                    'updated'
                );
            } else {
                self::persist_tools_notice( 'wre_tools_queue_inflight', __( 'Queue dispatch initiated. Remaining jobs will continue processing in the background.', 'wisdom-rain-email-engine' ), 'updated' );
            }

            wp_safe_redirect( $redirect );

            return;
        }

        /**
         * Handle template test send submissions.
         */
        public static function handle_test_send() {
            $redirect = add_query_arg(
                array(
                    'page' => self::MENU_SLUG,
                    'tab'  => 'test-send',
                ),
                admin_url( 'admin.php' )
            );

            if ( ! current_user_can( 'manage_options' ) ) {
                add_settings_error( 'wre_test_send', 'wre_test_send_cap', __( 'You do not have permission to send test emails.', 'wisdom-rain-email-engine' ), 'error' );
                wp_safe_redirect( $redirect );

                return;
            }

            if ( ! isset( $_POST['wre_test_send_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wre_test_send_nonce'] ) ), 'wre_test_send_template' ) ) {
                add_settings_error( 'wre_test_send', 'wre_test_send_nonce', __( 'Security check failed. Please try again.', 'wisdom-rain-email-engine' ), 'error' );
                wp_safe_redirect( $redirect );

                return;
            }

            if ( ! class_exists( 'WRE_Templates' ) ) {
                add_settings_error( 'wre_test_send', 'wre_test_send_templates', __( 'Templates module unavailable. Unable to send the preview email.', 'wisdom-rain-email-engine' ), 'error' );
                wp_safe_redirect( $redirect );

                return;
            }

            $templates = self::get_template_options();

            if ( empty( $templates ) ) {
                add_settings_error( 'wre_test_send', 'wre_test_send_missing', __( 'No templates are registered yet.', 'wisdom-rain-email-engine' ), 'error' );
                wp_safe_redirect( $redirect );

                return;
            }

            $template = isset( $_POST['wre_test_send_template'] ) ? sanitize_key( wp_unslash( $_POST['wre_test_send_template'] ) ) : key( $templates );

            if ( ! isset( $templates[ $template ] ) ) {
                $template = key( $templates );
            }

            $recipient = isset( $_POST['wre_test_send_recipient'] ) ? sanitize_email( wp_unslash( $_POST['wre_test_send_recipient'] ) ) : '';

            if ( '' === $recipient ) {
                add_settings_error( 'wre_test_send', 'wre_test_send_email', __( 'Please provide a valid recipient address.', 'wisdom-rain-email-engine' ), 'error' );
                wp_safe_redirect( add_query_arg( array( 'template' => $template ), $redirect ) );

                return;
            }

            $context = self::get_preview_context( $template );
            $body    = \WRE_Templates::render_template( $template, $context );

            if ( '' === $body ) {
                add_settings_error( 'wre_test_send', 'wre_test_send_empty', __( 'Selected template did not return any content.', 'wisdom-rain-email-engine' ), 'error' );
                wp_safe_redirect( add_query_arg( array( 'template' => $template, 'recipient' => $recipient ), $redirect ) );

                return;
            }

            $subject = isset( $context['subject'] ) && '' !== $context['subject']
                ? wp_strip_all_tags( $context['subject'] )
                : sprintf( __( 'Wisdom Rain Preview: %s', 'wisdom-rain-email-engine' ), self::get_template_label( $templates, $template ) );

            $headers = array( 'Content-Type: text/html; charset=UTF-8' );
            $sent    = false;

            if ( class_exists( 'WRE_Email_Sender' ) && method_exists( 'WRE_Email_Sender', 'send_raw_email' ) ) {
                $sent = \WRE_Email_Sender::send_raw_email( $recipient, $subject, $body, $headers, array(
                    'template'      => $template,
                    'delivery_mode' => 'test',
                    'context'       => 'admin',
                ) );
            }

            if ( false === $sent ) {
                $sent = wp_mail( $recipient, $subject, $body, $headers );
            }

            if ( $sent ) {
                add_settings_error(
                    'wre_test_send',
                    'wre_test_send_success',
                    sprintf( __( 'Test email sent to %s.', 'wisdom-rain-email-engine' ), esc_html( $recipient ) ),
                    'updated'
                );

                if ( class_exists( 'WRE_Logger' ) ) {
                    \WRE_Logger::add(
                        sprintf( 'Test email for template %s sent to %s.', $template, $recipient ),
                        'queue',
                        array(
                            'status'    => 'test-send',
                            'template'  => $template,
                            'recipient' => $recipient,
                        )
                    );
                }
            } else {
                add_settings_error( 'wre_test_send', 'wre_test_send_failed', __( 'Unable to send the test email. Please check your email configuration.', 'wisdom-rain-email-engine' ), 'error' );
            }

            wp_safe_redirect( add_query_arg( array( 'template' => $template, 'recipient' => $recipient ), $redirect ) );

            return;
        }

        /**
         * Handle updates to queue configuration options.
         */
        public static function handle_update_queue_settings() {
            $redirect = add_query_arg(
                array(
                    'page' => self::MENU_SLUG,
                    'tab'  => 'tools',
                ),
                admin_url( 'admin.php' )
            );

            if ( ! current_user_can( 'manage_options' ) ) {
                self::persist_tools_notice( 'wre_tools_rate_cap', __( 'You do not have permission to update the queue rate limit.', 'wisdom-rain-email-engine' ), 'error' );
                wp_safe_redirect( $redirect );

                return;
            }

            if ( ! isset( $_POST['wre_update_queue_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wre_update_queue_settings_nonce'] ) ), 'wre_update_queue_settings' ) ) {
                self::persist_tools_notice( 'wre_tools_rate_nonce', __( 'Security check failed. Please try again.', 'wisdom-rain-email-engine' ), 'error' );
                wp_safe_redirect( $redirect );

                return;
            }

            if ( ! class_exists( 'WRE_Email_Queue' ) ) {
                self::persist_tools_notice( 'wre_tools_rate_missing', __( 'Queue module is unavailable. Rate limits cannot be saved.', 'wisdom-rain-email-engine' ), 'error' );
                wp_safe_redirect( $redirect );

                return;
            }

            $rate_limit = isset( $_POST['wre_rate_limit'] ) ? absint( $_POST['wre_rate_limit'] ) : 0;

            if ( $rate_limit <= 0 ) {
                self::persist_tools_notice( 'wre_tools_rate_invalid', __( 'Please enter a valid hourly send limit.', 'wisdom-rain-email-engine' ), 'error' );
                wp_safe_redirect( $redirect );

                return;
            }

            \WRE_Email_Queue::update_rate_limit( $rate_limit );

            if ( class_exists( 'WRE_Logger' ) ) {
                \WRE_Logger::add(
                    sprintf( 'Queue rate limit updated to %d emails/hour.', $rate_limit ),
                    'queue',
                    array(
                        'status' => 'rate-limit',
                        'limit'  => $rate_limit,
                    )
                );
            }

            self::persist_tools_notice( 'wre_tools_rate_success', sprintf( __( 'Queue rate limit set to %d emails per hour.', 'wisdom-rain-email-engine' ), $rate_limit ), 'updated' );

            wp_safe_redirect( $redirect );

            return;
        }

        /**
         * Handle log cleanup requests from the Tools tab.
         */
        public static function handle_cleanup_logs() {
            $redirect = add_query_arg(
                array(
                    'page' => self::MENU_SLUG,
                    'tab'  => 'tools',
                ),
                admin_url( 'admin.php' )
            );

            if ( ! current_user_can( 'manage_options' ) ) {
                self::persist_tools_notice( 'wre_tools_logs_cap', __( 'You do not have permission to clear logs.', 'wisdom-rain-email-engine' ), 'error' );
                wp_safe_redirect( $redirect );

                return;
            }

            if ( ! isset( $_POST['wre_cleanup_logs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wre_cleanup_logs_nonce'] ) ), 'wre_cleanup_logs' ) ) {
                self::persist_tools_notice( 'wre_tools_logs_nonce', __( 'Security check failed. Please try again.', 'wisdom-rain-email-engine' ), 'error' );
                wp_safe_redirect( $redirect );

                return;
            }

            if ( class_exists( 'WRE_Logger' ) ) {
                \WRE_Logger::clear();
                \WRE_Logger::add( 'Admin cleared all WRE logs.', 'queue', array( 'status' => 'logs-cleared', 'user_id' => absint( get_current_user_id() ) ) );
            }

            self::persist_tools_notice( 'wre_tools_logs_cleared', __( 'Log entries and counters have been cleared.', 'wisdom-rain-email-engine' ), 'updated' );

            wp_safe_redirect( $redirect );

            return;
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
                    /* translators: 1: user ID, 2: forced run time, 3: scheduled time */
                    __( 'Manual cron run triggered by user #%1$d at %2$s. Original schedule was due at %3$s.', 'wisdom-rain-email-engine' ),
                    absint( $user_id ),
                    $forced_stamp,
                    $due_stamp
                );

                \WRE_Logger::add( $log_message, 'cron' );
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

            if ( ! class_exists( 'WRE_Orders' ) && ! class_exists( 'WRE_Trials' ) ) {
                self::persist_tools_notice(
                    'wre_tools_event_missing',
                    __( 'Orders and trials modules are unavailable. Real-time tests cannot run.', 'wisdom-rain-email-engine' ),
                    'error'
                );
                wp_safe_redirect( $redirect );
                exit;
            }

            $type          = 'updated';
            $order_result  = class_exists( 'WRE_Orders' ) ? \WRE_Orders::replay_last_confirmation() : null;
            $trial_result  = class_exists( 'WRE_Trials' ) ? \WRE_Trials::replay_last_expiration_notice() : null;

            $order_message = __( 'No completed orders detected for replay.', 'wisdom-rain-email-engine' );

            if ( true === $order_result ) {
                $order_message = __( 'Latest order confirmation email sent successfully.', 'wisdom-rain-email-engine' );
            } elseif ( false === $order_result ) {
                $order_message = __( 'Order confirmation replay failed. Check logs for details.', 'wisdom-rain-email-engine' );
                $type          = 'error';
            }

            $trial_message = __( 'No trial expiration events available for replay.', 'wisdom-rain-email-engine' );

            if ( true === $trial_result ) {
                $trial_message = __( 'Latest trial expiration notice sent successfully.', 'wisdom-rain-email-engine' );
            } elseif ( false === $trial_result ) {
                $trial_message = __( 'Trial expiration notice replay failed. Check logs for details.', 'wisdom-rain-email-engine' );
                $type          = 'error';
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
