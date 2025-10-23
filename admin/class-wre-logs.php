<?php
/**
 * Logs & Performance Dashboard
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Logs' ) ) {
    /**
     * Admin controller that renders the Logs & Performance screen.
     */
    class WRE_Logs {

        /**
         * Register the submenu page when in the admin dashboard.
         */
        public static function init() {
            if ( ! is_admin() ) {
                return;
            }

            add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
        }

        /**
         * Register the Logs submenu under the core dashboard menu.
         */
        public static function register_page() {
            $parent_slug = class_exists( 'WRE_Admin' ) ? WRE_Admin::MENU_SLUG : 'wre-dashboard';

            add_submenu_page(
                $parent_slug,
                __( 'Logs & Performance', 'wisdom-rain-email-engine' ),
                __( 'Logs', 'wisdom-rain-email-engine' ),
                'manage_options',
                'wre-logs',
                array( __CLASS__, 'render_page' )
            );
        }

        /**
         * Render the admin page output.
         */
        public static function render_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            if ( isset( $_POST['wre_clear_logs'] ) && check_admin_referer( 'wre_clear_logs' ) ) {
                WRE_Logger::clear();

                echo '<div class="updated"><p>' . esc_html__( 'Logs cleared.', 'wisdom-rain-email-engine' ) . '</p></div>';
            }

            $logs     = WRE_Logger::get();
            $stats    = WRE_Logger::get_stats( $logs );
            $summary  = WRE_Logger::summarize_context( $logs );
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( '📊 WRE Logs & Performance', 'wisdom-rain-email-engine' ); ?></h1>

                <form method="post">
                    <?php wp_nonce_field( 'wre_clear_logs' ); ?>
                    <?php submit_button( __( 'Clear All Logs', 'wisdom-rain-email-engine' ), 'secondary', 'wre_clear_logs', false ); ?>
                </form>

                <div class="wre-log-stats">
                    <ul class="wre-log-stats__list">
                        <?php
                        $labels = array(
                            'sent'    => __( 'Total Sent', 'wisdom-rain-email-engine' ),
                            'instant' => __( 'Instant Sends', 'wisdom-rain-email-engine' ),
                            'failed'  => __( 'Total Failed', 'wisdom-rain-email-engine' ),
                            'queue'   => __( 'Total Queued', 'wisdom-rain-email-engine' ),
                            'cron'    => __( 'Cron Runs', 'wisdom-rain-email-engine' ),
                            'total'   => __( 'Total Events', 'wisdom-rain-email-engine' ),
                        );

                        foreach ( $labels as $key => $label ) :
                            $value = isset( $stats[ $key ] ) ? absint( $stats[ $key ] ) : 0;
                            ?>
                            <li class="wre-log-stats__item">
                                <span class="wre-log-stats__label"><?php echo esc_html( $label ); ?></span>
                                <span class="wre-log-stats__value"><?php echo esc_html( number_format_i18n( $value ) ); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <?php if ( self::has_summary_data( $summary ) ) : ?>
                    <div class="wre-log-summary">
                        <h2><?php esc_html_e( 'Context Summary', 'wisdom-rain-email-engine' ); ?></h2>
                        <div class="wre-log-summary__grid">
                            <?php
                            $summary_labels = array(
                                'templates'      => __( 'Templates', 'wisdom-rain-email-engine' ),
                                'delivery_modes' => __( 'Delivery Modes', 'wisdom-rain-email-engine' ),
                                'statuses'       => __( 'Statuses', 'wisdom-rain-email-engine' ),
                                'log_types'      => __( 'Log Types', 'wisdom-rain-email-engine' ),
                            );

                            foreach ( $summary_labels as $key => $title ) {
                                $items = isset( $summary[ $key ] ) ? $summary[ $key ] : array();

                                self::render_summary_panel( $title, $items );
                            }
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( empty( $logs ) ) : ?>
                    <p><?php esc_html_e( 'No logs found yet.', 'wisdom-rain-email-engine' ); ?></p>
                </div>
                <?php
                    return;
                endif;
                ?>

                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Time', 'wisdom-rain-email-engine' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'wisdom-rain-email-engine' ); ?></th>
                            <th><?php esc_html_e( 'Message', 'wisdom-rain-email-engine' ); ?></th>
                            <th><?php esc_html_e( 'Details', 'wisdom-rain-email-engine' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $logs as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html( isset( $log['time'] ) ? $log['time'] : '' ); ?></td>
                                <td><strong><?php echo esc_html( isset( $log['type'] ) ? $log['type'] : '' ); ?></strong></td>
                                <td><?php echo esc_html( isset( $log['msg'] ) ? $log['msg'] : '' ); ?></td>
                                <td><?php echo wp_kses_post( self::format_log_context( isset( $log['context'] ) ? $log['context'] : array() ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
        }

        /**
         * Render human-friendly context details for a log entry.
         *
         * @param mixed $context Raw context payload.
         *
         * @return string
         */
        protected static function format_log_context( $context ) {
            if ( empty( $context ) || ! is_array( $context ) ) {
                return '&mdash;';
            }

            $items = array();

            foreach ( $context as $key => $value ) {
                $label = ucwords( str_replace( '_', ' ', sanitize_key( $key ) ) );

                if ( is_array( $value ) ) {
                    $value = wp_json_encode( $value );
                } elseif ( is_bool( $value ) ) {
                    $value = $value ? 'true' : 'false';
                } elseif ( is_scalar( $value ) ) {
                    $value = (string) $value;
                } else {
                    $value = '';
                }

                if ( '' === $value ) {
                    continue;
                }

                $items[] = sprintf(
                    '<span class="wre-log-context__item"><strong>%s:</strong> %s</span>',
                    esc_html( $label ),
                    esc_html( $value )
                );
            }

            if ( empty( $items ) ) {
                return '&mdash;';
            }

            return implode( '<br />', $items );
        }

        /**
         * Determine if the provided summary payload contains displayable data.
         *
         * @param mixed $summary Summary payload from the logger.
         * @return bool
         */
        protected static function has_summary_data( $summary ) {
            if ( empty( $summary ) || ! is_array( $summary ) ) {
                return false;
            }

            foreach ( $summary as $group ) {
                if ( ! empty( $group ) && is_array( $group ) ) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Render a list of summary items for a context dimension.
         *
         * @param string                    $title Section title.
         * @param array<string|int, int>    $items Summary data keyed by slug.
         * @return void
         */
        protected static function render_summary_panel( $title, $items ) {
            if ( empty( $items ) || ! is_array( $items ) ) {
                return;
            }

            echo '<div class="wre-log-summary__section">';
            echo '<h3>' . esc_html( $title ) . '</h3>';
            echo '<ul class="wre-log-summary__list">';

            foreach ( $items as $slug => $count ) {
                $label = is_string( $slug ) ? $slug : (string) $slug;
                $value = number_format_i18n( absint( $count ) );

                echo '<li class="wre-log-summary__item">';
                echo '<span class="wre-log-summary__label">' . esc_html( self::format_summary_label( $label ) ) . '</span>';
                echo '<span class="wre-log-summary__value">' . esc_html( $value ) . '</span>';
                echo '</li>';
            }

            echo '</ul>';
            echo '</div>';
        }

        /**
         * Convert a context slug into a human-friendly label.
         *
         * @param string $value Raw slug value.
         * @return string
         */
        protected static function format_summary_label( $value ) {
            $value = (string) $value;

            if ( '' === $value ) {
                return '';
            }

            $label = str_replace( array( '-', '_' ), ' ', strtolower( $value ) );

            return ucwords( $label );
        }
    }
}
