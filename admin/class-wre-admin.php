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
                25
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

                <?php
                switch ( $current_tab ) {
                    case 'templates':
                        self::render_templates_tab();
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
    }
}
