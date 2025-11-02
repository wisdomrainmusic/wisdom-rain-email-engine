<?php
/**
 * Campaign management UI for the Wisdom Rain Email Engine plugin.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Campaigns' ) ) {
    /**
     * Provides a streamlined campaign orchestration interface within the admin UI.
     */
    class WRE_Campaigns {
        const NONCE_ACTION = 'wre_campaign_send';
        const NONCE_FIELD  = 'wre_campaign_nonce';
        const NOTICE_GROUP = 'wre_campaigns';

        /**
         * Persisted form selections for the current request lifecycle.
         *
         * @var array<string, string>
         */
        protected static $form_state = array(
            'template'   => 'newyear',
            'target'     => 'all',
            'test_email' => '',
        );

        /**
         * Submenu slug registered under the WRE dashboard menu.
         */
        const MENU_SLUG = 'wre-campaigns';

        /**
         * Register handlers required for form processing.
         */
        public static function init() {
            if ( ! is_admin() ) {
                return;
            }

            add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
            add_action( 'admin_init', array( __CLASS__, 'handle_form_submission' ) );
        }

        /**
         * Register the Campaigns submenu within the WRE dashboard.
         */
        public static function register_page() {
            $parent_slug = defined( 'WRE_Admin::MENU_SLUG' ) ? WRE_Admin::MENU_SLUG : 'wre-dashboard';

            add_submenu_page(
                $parent_slug,
                __( 'Email Campaigns', 'wisdom-rain-email-engine' ),
                __( 'Campaigns', 'wisdom-rain-email-engine' ),
                'manage_options',
                self::MENU_SLUG,
                array( __CLASS__, 'render_page' )
            );
        }

        /**
         * Render standalone Campaigns admin page.
         */
        public static function render_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $context = self::prepare_view_context();
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( '🎯 Email Campaigns', 'wisdom-rain-email-engine' ); ?></h1>
                <?php settings_errors( self::NOTICE_GROUP ); ?>
                <?php self::render_interface( $context ); ?>
            </div>
            <?php
        }

        /**
         * Render the Campaigns admin tab UI.
         */
        public static function render_admin_tab() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $context = self::prepare_view_context();

            ?>
            <div class="wre-campaigns-tab">
                <?php self::render_interface( $context ); ?>
            </div>
            <?php
        }

        /**
         * Process campaign form submissions and provide feedback notices.
         */
        public static function handle_form_submission() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $templates = self::get_templates();
            $targets   = self::get_target_groups();

            $template = isset( $_REQUEST['wre_campaign_template'] )
                ? self::sanitize_template( wp_unslash( $_REQUEST['wre_campaign_template'] ), $templates )
                : self::$form_state['template'];

            $target = isset( $_REQUEST['wre_campaign_target'] )
                ? self::sanitize_target( wp_unslash( $_REQUEST['wre_campaign_target'] ), $targets )
                : self::$form_state['target'];

            $test_email = isset( $_REQUEST['wre_campaign_test_email'] )
                ? sanitize_email( wp_unslash( $_REQUEST['wre_campaign_test_email'] ) )
                : self::$form_state['test_email'];

            self::$form_state = array(
                'template'   => $template,
                'target'     => $target,
                'test_email' => $test_email,
            );

            if ( ! isset( $_POST['wre_campaign_action'] ) ) {
                return;
            }

            if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
                add_settings_error(
                    self::NOTICE_GROUP,
                    'wre_campaign_nonce',
                    __( 'Security check failed. Please try again.', 'wisdom-rain-email-engine' ),
                    'error'
                );

                return;
            }

            $action = sanitize_key( wp_unslash( $_POST['wre_campaign_action'] ) );

            switch ( $action ) {
                case 'test':
                    self::process_test_send( $template, $test_email );
                    break;
                case 'queue':
                    self::process_queue_request( $template, $target );
                    break;
                default:
                    break;
            }
        }

        /**
         * Retrieve available campaign templates.
         *
         * @return array<string, string>
         */
        protected static function get_templates() {
            return array(
                'newyear'   => array(
                    'label'            => __( 'New Year Renewal', 'wisdom-rain-email-engine' ),
                    'subject'          => __( 'Start the year with a renewed practice', 'wisdom-rain-email-engine' ),
                    'headline'         => __( 'Welcome a Year of Steady Practice', 'wisdom-rain-email-engine' ),
                    'seasonal_message' => __( 'A fresh calendar is a beautiful cue to recommit to your inner work.', 'wisdom-rain-email-engine' ),
                    'campaign_offer'   => __( 'Renew now and receive a bonus live session to anchor your intentions.', 'wisdom-rain-email-engine' ),
                    'cta_text'         => __( 'Renew Membership', 'wisdom-rain-email-engine' ),
                ),
                'valentine' => array(
                    'label'            => __( 'Valentine Gratitude', 'wisdom-rain-email-engine' ),
                    'subject'          => __( 'A heartfelt Valentine from Wisdom Rain', 'wisdom-rain-email-engine' ),
                    'headline'         => __( 'We Love Practicing Alongside You', 'wisdom-rain-email-engine' ),
                    'seasonal_message' => __( 'This season of care is the perfect moment to treat yourself to deeper support.', 'wisdom-rain-email-engine' ),
                    'campaign_offer'   => __( 'Gift yourself a premium upgrade and unlock bonus meditations all month.', 'wisdom-rain-email-engine' ),
                    'cta_text'         => __( 'Explore Premium', 'wisdom-rain-email-engine' ),
                ),
                'summer'    => array(
                    'label'            => __( 'Summer Reset', 'wisdom-rain-email-engine' ),
                    'subject'          => __( 'Summer rhythms to keep you glowing', 'wisdom-rain-email-engine' ),
                    'headline'         => __( 'Step into a Brighter Summer Practice', 'wisdom-rain-email-engine' ),
                    'seasonal_message' => __( 'Let the season invite you to move, breathe, and reset in community.', 'wisdom-rain-email-engine' ),
                    'campaign_offer'   => __( 'Join our Summer Reset track and enjoy weekly live breathwork circles.', 'wisdom-rain-email-engine' ),
                    'cta_text'         => __( 'Join the Reset', 'wisdom-rain-email-engine' ),
                ),
                'exclusive' => array(
                    'label'            => __( 'Exclusive Drop', 'wisdom-rain-email-engine' ),
                    'subject'          => __( 'Your exclusive Wisdom Rain release awaits', 'wisdom-rain-email-engine' ),
                    'headline'         => __( 'A Limited Practice Drop for You', 'wisdom-rain-email-engine' ),
                    'seasonal_message' => __( 'We saved a special collection of practices for the members who show up again and again.', 'wisdom-rain-email-engine' ),
                    'campaign_offer'   => __( 'Upgrade today and unlock early access to new series and guided immersions.', 'wisdom-rain-email-engine' ),
                    'cta_text'         => __( 'Unlock Access', 'wisdom-rain-email-engine' ),
                ),
            );
        }

        /**
         * Retrieve configured target groups.
         *
         * @return array<string, string>
         */
        protected static function get_target_groups() {
            return array(
                'all'     => __( 'All Members', 'wisdom-rain-email-engine' ),
                'active'  => __( 'Active Premium', 'wisdom-rain-email-engine' ),
                'expired' => __( 'Expired Members', 'wisdom-rain-email-engine' ),
                'trial'   => __( 'Trial Members', 'wisdom-rain-email-engine' ),
            );
        }

        /**
         * Build template, target, and test email context for the UI.
         *
         * @return array<string, mixed>
         */
        protected static function prepare_view_context() {
            $templates = self::get_templates();
            $targets   = self::get_target_groups();

            $template = isset( self::$form_state['template'], $templates[ self::$form_state['template'] ] )
                ? self::$form_state['template']
                : key( $templates );

            $target = isset( self::$form_state['target'], $targets[ self::$form_state['target'] ] )
                ? self::$form_state['target']
                : key( $targets );

            $test_email = self::$form_state['test_email'];

            if ( '' === $test_email ) {
                $test_email = self::get_default_test_email();
            }

            $template_label = isset( $templates[ $template ]['label'] ) ? $templates[ $template ]['label'] : $template;

            return array(
                'templates'      => $templates,
                'targets'        => $targets,
                'template'       => $template,
                'target'         => $target,
                'test_email'     => $test_email,
                'template_label' => $template_label,
            );
        }

        /**
         * Ensure provided template slug is valid.
         *
         * @param string               $template  Raw template identifier.
         * @param array<string, string> $templates Map of allowed templates.
         *
         * @return string
         */
        protected static function sanitize_template( $template, $templates ) {
            $template = sanitize_key( $template );

            return isset( $templates[ $template ] ) ? $template : key( $templates );
        }

        /**
         * Ensure provided target slug is valid.
         *
         * @param string               $target  Raw target identifier.
         * @param array<string, string> $targets Map of allowed targets.
         *
         * @return string
         */
        protected static function sanitize_target( $target, $targets ) {
            $target = sanitize_key( $target );

            return isset( $targets[ $target ] ) ? $target : key( $targets );
        }

        /**
         * Resolve a list of target users based on the selected group.
         *
         * @param string $type Target group identifier.
         *
         * @return array<int, mixed>
         */
        protected static function get_targets( $type ) {
            if ( class_exists( 'WRPA_Access' ) ) {
                switch ( $type ) {
                    case 'active':
                        if ( method_exists( 'WRPA_Access', 'get_active_members' ) ) {
                            return WRPA_Access::get_active_members();
                        }
                        break;
                    case 'expired':
                        if ( method_exists( 'WRPA_Access', 'get_expired_members' ) ) {
                            return WRPA_Access::get_expired_members();
                        }
                        break;
                    case 'trial':
                        if ( method_exists( 'WRPA_Access', 'get_trial_members' ) ) {
                            return WRPA_Access::get_trial_members();
                        }
                        break;
                    default:
                        break;
                }
            }

            if ( 'trial' === $type ) {
                $trial_users = self::get_trial_members_from_meta();

                if ( ! empty( $trial_users ) ) {
                    return $trial_users;
                }

                if ( class_exists( 'WRE_Trials' ) && method_exists( 'WRE_Trials', 'get_last_expiration' ) ) {
                    $last_expiration = \WRE_Trials::get_last_expiration();

                    if ( ! empty( $last_expiration ) && ! empty( $last_expiration['user_id'] ) ) {
                        $user = get_userdata( absint( $last_expiration['user_id'] ) );

                        if ( $user instanceof WP_User ) {
                            return array( $user );
                        }
                    }
                }
            }

            return get_users(
                array(
                    'role__in' => array( 'subscriber', 'customer' ),
                )
            );
        }

        /**
         * Normalise recipient collections to a flat array of WP_User objects.
         *
         * @param mixed $users Raw user collection.
         *
         * @return array<int, WP_User>
         */
        protected static function normalize_users( $users ) {
            if ( $users instanceof WP_User_Query ) {
                $users = $users->get_results();
            }

            if ( $users instanceof Traversable ) {
                $users = iterator_to_array( $users );
            }

            if ( ! is_array( $users ) ) {
                return array();
            }

            $normalized = array();

            foreach ( $users as $user ) {
                if ( $user instanceof WP_User ) {
                    $normalized[] = $user;
                    continue;
                }

                if ( is_numeric( $user ) ) {
                    $maybe_user = get_userdata( (int) $user );

                    if ( $maybe_user instanceof WP_User ) {
                        $normalized[] = $maybe_user;
                    }
                }
            }

            return $normalized;
        }

        /**
         * Retrieve trial members via stored subscription status metadata.
         *
         * @return array<int, WP_User>
         */
        protected static function get_trial_members_from_meta() {
            if ( ! class_exists( 'WP_User_Query' ) ) {
                return array();
            }

            $status   = class_exists( 'WRE_Cron' ) ? \WRE_Cron::SUBSCRIPTION_STATUS_TRIAL : 'trial';
            $meta_key = class_exists( 'WRE_Cron' ) ? \WRE_Cron::META_SUBSCRIPTION_STATUS : '_wre_subscription_status';

            $candidate_keys = array_unique(
                apply_filters(
                    'wre_campaign_trial_meta_keys',
                    array(
                        $meta_key,
                        '_wre_subscription_status',
                        'wrpa_subscription_status',
                    )
                )
            );

            $meta_query = array( 'relation' => 'OR' );

            foreach ( $candidate_keys as $candidate ) {
                $candidate = sanitize_key( $candidate );

                if ( '' === $candidate ) {
                    continue;
                }

                $meta_query[] = array(
                    'key'   => $candidate,
                    'value' => $status,
                );
            }

            if ( count( $meta_query ) <= 1 ) {
                return array();
            }

            $args = apply_filters(
                'wre_campaign_trial_query_args',
                array(
                    'fields'     => 'all',
                    'number'     => 200,
                    'role__in'   => array( 'subscriber', 'customer' ),
                    'meta_query' => $meta_query,
                )
            );

            $query = new WP_User_Query( $args );

            if ( ! $query instanceof WP_User_Query ) {
                return array();
            }

            return array_filter(
                $query->get_results(),
                static function ( $user ) {
                    return $user instanceof WP_User;
                }
            );
        }

        /**
         * Determine a reasonable default email address for test sends.
         *
         * @return string
         */
        protected static function get_default_test_email() {
            $current_user = wp_get_current_user();

            if ( $current_user && $current_user->exists() && ! empty( $current_user->user_email ) ) {
                return sanitize_email( $current_user->user_email );
            }

            $admin_email = get_option( 'admin_email', '' );

            return sanitize_email( $admin_email );
        }

        /**
         * Dispatch a single test email for the selected template.
         *
         * @param string $template  Template slug.
         * @param string $test_email Recipient email address.
         */
        protected static function process_test_send( $template, $test_email ) {
            $test_email = sanitize_email( $test_email );

            if ( '' === $test_email ) {
                add_settings_error(
                    self::NOTICE_GROUP,
                    'wre_campaign_test_email_missing',
                    __( 'Please provide a valid email address for the test send.', 'wisdom-rain-email-engine' ),
                    'error'
                );

                return;
            }

            if ( ! class_exists( 'WRE_Templates' ) ) {
                add_settings_error(
                    self::NOTICE_GROUP,
                    'wre_campaign_templates_missing',
                    __( 'Templates module unavailable. Unable to render test email.', 'wisdom-rain-email-engine' ),
                    'error'
                );

                return;
            }

            $context = self::get_template_context( $template );

            $body = \WRE_Templates::render_template( $template, $context );

            if ( '' === $body ) {
                add_settings_error(
                    self::NOTICE_GROUP,
                    'wre_campaign_template_empty',
                    __( 'Selected template does not have any content to preview.', 'wisdom-rain-email-engine' ),
                    'error'
                );

                return;
            }

            $templates = self::get_templates();
            $subject   = isset( $templates[ $template ]['subject'] ) ? $templates[ $template ]['subject'] : __( 'Wisdom Rain Campaign Preview', 'wisdom-rain-email-engine' );
            $headers   = array( 'Content-Type: text/html; charset=UTF-8' );

            $sent = wp_mail( $test_email, $subject, $body, $headers );

            if ( $sent ) {
                add_settings_error(
                    self::NOTICE_GROUP,
                    'wre_campaign_test_success',
                    sprintf(
                        /* translators: %s: email address */
                        esc_html__( 'Test email sent to %s.', 'wisdom-rain-email-engine' ),
                        esc_html( $test_email )
                    ),
                    'updated'
                );

                return;
            }

            add_settings_error(
                self::NOTICE_GROUP,
                'wre_campaign_test_failed',
                __( 'Unable to send the test email. Please check your email configuration.', 'wisdom-rain-email-engine' ),
                'error'
            );
        }

        /**
         * Queue emails for the selected target group.
         *
         * @param string $template Template slug.
         * @param string $target   Target group slug.
         */
        protected static function process_queue_request( $template, $target ) {
            if ( ! class_exists( 'WRE_Email_Queue' ) ) {
                add_settings_error(
                    self::NOTICE_GROUP,
                    'wre_campaign_queue_missing',
                    __( 'Email queue module is unavailable.', 'wisdom-rain-email-engine' ),
                    'error'
                );

                return;
            }

            $recipients = self::normalize_users( self::get_targets( $target ) );

            if ( empty( $recipients ) ) {
                add_settings_error(
                    self::NOTICE_GROUP,
                    'wre_campaign_no_users',
                    __( 'No matching recipients found for the selected group.', 'wisdom-rain-email-engine' ),
                    'warning'
                );

                return;
            }

            $queued = 0;

            foreach ( $recipients as $user ) {
                if ( isset( $user->ID ) && \WRE_Email_Queue::add_to_queue( $user->ID, $template, self::get_template_context( $template ) ) ) {
                    $queued++;
                }
            }

            if ( $queued > 0 ) {
                add_settings_error(
                    self::NOTICE_GROUP,
                    'wre_campaign_success',
                    sprintf(
                        /* translators: %d: number of users queued */
                        _n( 'Campaign queued for %d member.', 'Campaign queued for %d members.', $queued, 'wisdom-rain-email-engine' ),
                        $queued
                    ),
                    'updated'
                );

                return;
            }

            add_settings_error(
                self::NOTICE_GROUP,
                'wre_campaign_failed',
                __( 'Unable to queue the selected campaign.', 'wisdom-rain-email-engine' ),
                'error'
            );
        }

        /**
         * Output shared UI markup for both the tab and submenu page contexts.
         *
         * @param array<string, mixed> $context Prepared view context.
         */
        protected static function render_interface( $context ) {
            $templates      = isset( $context['templates'] ) && is_array( $context['templates'] ) ? $context['templates'] : array();
            $targets        = isset( $context['targets'] ) && is_array( $context['targets'] ) ? $context['targets'] : array();
            $template       = isset( $context['template'] ) ? $context['template'] : key( $templates );
            $target         = isset( $context['target'] ) ? $context['target'] : key( $targets );
            $test_email     = isset( $context['test_email'] ) ? $context['test_email'] : self::get_default_test_email();
            $template_label = isset( $context['template_label'] ) ? $context['template_label'] : $template;

            ?>
            <p class="description"><?php esc_html_e( 'Preview seasonal templates, send a test email, or queue a campaign for targeted membership groups.', 'wisdom-rain-email-engine' ); ?></p>

            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="wre_campaign_template"><?php esc_html_e( 'Template', 'wisdom-rain-email-engine' ); ?></label>
                            </th>
                            <td>
                                <select name="wre_campaign_template" id="wre_campaign_template">
                                    <?php foreach ( $templates as $slug => $meta ) :
                                        $label = isset( $meta['label'] ) ? $meta['label'] : $slug;
                                        ?>
                                        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $template, $slug ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="wre_campaign_target"><?php esc_html_e( 'Target group', 'wisdom-rain-email-engine' ); ?></label>
                            </th>
                            <td>
                                <select name="wre_campaign_target" id="wre_campaign_target">
                                    <?php foreach ( $targets as $slug => $label ) : ?>
                                        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $target, $slug ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Membership filters rely on WRPA Access when available.', 'wisdom-rain-email-engine' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="wre_campaign_test_email"><?php esc_html_e( 'Test email address', 'wisdom-rain-email-engine' ); ?></label>
                            </th>
                            <td>
                                <input type="email" name="wre_campaign_test_email" id="wre_campaign_test_email" value="<?php echo esc_attr( $test_email ); ?>" class="regular-text" placeholder="<?php echo esc_attr( self::get_default_test_email() ); ?>" />
                                <p class="description"><?php esc_html_e( 'Use “Send Test” to dispatch a single preview message without affecting the queue.', 'wisdom-rain-email-engine' ); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" name="wre_campaign_action" value="test" class="button">
                        <?php esc_html_e( 'Send Test', 'wisdom-rain-email-engine' ); ?>
                    </button>
                    <button type="submit" name="wre_campaign_action" value="queue" class="button button-primary">
                        <?php esc_html_e( 'Add to Queue', 'wisdom-rain-email-engine' ); ?>
                    </button>
                </p>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Template Preview', 'wisdom-rain-email-engine' ); ?></h2>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: campaign template label */
                    esc_html__( 'Previewing: %s', 'wisdom-rain-email-engine' ),
                    esc_html( $template_label )
                );
                ?>
            </p>

            <?php self::render_preview_panel( $template ); ?>
            <?php
        }

        /**
         * Generate placeholder content for previews and sends.
         *
         * @param string $template Template slug.
         *
         * @return array<string, string>
         */
        protected static function get_template_context( $template ) {
            $template = sanitize_key( $template );
            $templates = self::get_templates();

            $site_name     = get_bloginfo( 'name' );
            $site_url      = home_url( '/' );
            $support_email = get_option( 'admin_email', '' );

            $base = array(
                'subject'        => __( 'Wisdom Rain Campaign', 'wisdom-rain-email-engine' ),
                'headline'       => __( 'Wisdom Rain Email', 'wisdom-rain-email-engine' ),
                'seasonal_message' => __( 'This is a seasonal update from the Wisdom Rain team.', 'wisdom-rain-email-engine' ),
                'campaign_offer' => __( 'Update this section with your latest offer details.', 'wisdom-rain-email-engine' ),
                'cta_text'       => __( 'Explore Now', 'wisdom-rain-email-engine' ),
                'cta_url'        => esc_url( home_url( '/' ) ),
                'site_name'      => $site_name ? $site_name : __( 'Wisdom Rain', 'wisdom-rain-email-engine' ),
                'site_url'       => esc_url( $site_url ),
                'support_email'  => sanitize_email( $support_email ),
                'recipient_name' => __( 'Wisdom Rain Member', 'wisdom-rain-email-engine' ),
            );

            if ( isset( $templates[ $template ] ) ) {
                $base = array_merge( $base, array_filter( $templates[ $template ] ) );
            }

            return $base;
        }

        /**
         * Output the preview markup for the selected template.
         *
         * @param string $template Template slug.
         */
        protected static function render_preview_panel( $template ) {
            if ( ! class_exists( 'WRE_Templates' ) ) {
                echo '<p>' . esc_html__( 'Templates module unavailable. Unable to render preview.', 'wisdom-rain-email-engine' ) . '</p>';

                return;
            }

            $context = self::get_template_context( $template );
            $preview = \WRE_Templates::render_template( $template, $context );

            if ( '' === $preview ) {
                echo '<p>' . esc_html__( 'Template content not found. Ensure the file exists in templates/emails/.', 'wisdom-rain-email-engine' ) . '</p>';

                return;
            }

            echo '<div class="wre-campaign-preview" style="border:1px solid #ccd0d4;border-radius:6px;overflow:hidden;background:#fff;">';
            echo wp_kses_post( $preview );
            echo '</div>';
        }
    }
}
