<?php
/**
 * Core loader for the Wisdom Rain Email Engine plugin.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Core' ) ) {
    /**
     * Bootstrap class for initializing plugin functionality and lifecycle hooks.
     */
    class WRE_Core {
        /**
         * Stores the absolute path to the main plugin file.
         *
         * @var string
         */
        protected static $plugin_file = '';

        /**
         * Kick off the plugin bootstrapping sequence.
         *
         * @param string $plugin_file Absolute path to the plugin bootstrap file.
         */
        public static function boot( $plugin_file ) {
            self::$plugin_file = $plugin_file;

            self::define_constants();
            self::register_activation_hook();

            add_action( 'plugins_loaded', array( __CLASS__, 'init' ) );
        }

        /**
         * Define plugin-wide constants if they are not already defined.
         */
        protected static function define_constants() {
            if ( ! defined( 'WRE_VERSION' ) ) {
                define( 'WRE_VERSION', '1.0.0' );
            }

            if ( ! defined( 'WRE_PATH' ) && ! empty( self::$plugin_file ) ) {
                define( 'WRE_PATH', plugin_dir_path( self::$plugin_file ) );
            }

            if ( ! defined( 'WRE_URL' ) && ! empty( self::$plugin_file ) ) {
                define( 'WRE_URL', plugin_dir_url( self::$plugin_file ) );
            }
        }

        /**
         * Register plugin activation hook to prepare runtime assets.
         */
        protected static function register_activation_hook() {
            if ( empty( self::$plugin_file ) ) {
                return;
            }

            register_activation_hook( self::$plugin_file, array( __CLASS__, 'activate' ) );
        }

        /**
         * Prepare template directories required by the plugin.
         */
        public static function activate() {
            if ( ! defined( 'WRE_PATH' ) ) {
                return;
            }

            $template_root = trailingslashit( WRE_PATH ) . 'templates';
            $email_templates = trailingslashit( $template_root ) . 'emails';

            foreach ( array( $template_root, $email_templates ) as $directory ) {
                if ( ! file_exists( $directory ) ) {
                    wp_mkdir_p( $directory );
                }
            }

            $cron_file = trailingslashit( WRE_PATH ) . 'core/class-wre-cron.php';

            if ( file_exists( $cron_file ) ) {
                require_once $cron_file;
            }

            if ( class_exists( 'WRE_Cron' ) ) {
                \WRE_Cron::install_schedule();
            }

        }

        /**
         * Initialize the plugin by wiring dependencies and module bootstrapping.
         */
        public static function init() {
            self::load_dependencies();

            /**
             * 🔗 Bridge WRPA signup → WRE Verify
             * When a new user registers, send them the combined Welcome + Verify email.
             */
            if ( class_exists( 'WRE_Verify' ) && method_exists( 'WRE_Verify', 'send_verification_email_instant' ) ) {
                add_action( 'user_register', array( 'WRE_Verify', 'send_verification_email_instant' ), 10, 1 );

                if ( class_exists( 'WRE_Logger' ) ) {
                    \WRE_Logger::add( 'Hooked user_register → instant verification bridge active.', 'verify' );
                }
            } elseif ( class_exists( 'WRE_Logger' ) ) {
                \WRE_Logger::add( 'Unable to bridge user_register; WRE_Verify::send_verification_email_instant unavailable.', 'verify' );
            }

            self::initialize_modules();

            add_action( 'admin_notices', array( __CLASS__, 'test_notice' ) );

            error_log( 'WRE Core initialized' );
        }

        /**
         * Load PHP classes required for plugin execution.
         */
        protected static function load_dependencies() {
            if ( ! defined( 'WRE_PATH' ) ) {
                return;
            }

            $dependencies = array(
                'admin/class-wre-admin-notices.php',
                'admin/class-wre-admin.php',
                'admin/class-wre-campaigns.php',
                'admin/class-wre-logs.php',
                'core/class-wre-async-queue.php',
                'core/class-wre-logger.php',
                'core/class-wre-codex-command.php',
                'core/class-wre-email-sender.php',
                'core/class-wre-verify.php',
                'core/class-wre-templates.php',
                'core/class-wre-email-queue.php',
                'core/class-wre-cron.php',
                'core/class-wre-passwords.php',
                'core/class-wre-consent.php',
                'core/class-wre-orders.php',
                'core/class-wre-trials.php',
            );

            foreach ( $dependencies as $relative_path ) {
                $absolute_path = WRE_PATH . ltrim( $relative_path, '/' );

                if ( file_exists( $absolute_path ) ) {
                    require_once $absolute_path;
                }
            }
        }

        /**
         * Call initialization routines for loaded modules when available.
         */
        protected static function initialize_modules() {
            if ( class_exists( 'WRE_Admin_Notices' ) ) {
                \WRE_Admin_Notices::init();
            }

            if ( class_exists( 'WRE_Admin' ) ) {
                \WRE_Admin::init();
            }

            if ( class_exists( 'WRE_Campaigns' ) ) {
                \WRE_Campaigns::init();
            }

            if ( class_exists( 'WRE_Logs' ) ) {
                \WRE_Logs::init();
            }

            if ( class_exists( 'WRE_Async_Queue' ) ) {
                \WRE_Async_Queue::init();
            }

            if ( class_exists( 'WRE_Templates' ) ) {
                \WRE_Templates::init();
            }

            if ( class_exists( 'WRE_Codex_Command' ) && defined( 'WP_CLI' ) && WP_CLI ) {
                \WRE_Codex_Command::register();
            }

            if ( class_exists( 'WRE_Email' ) ) {
                \WRE_Email::init();
            }

            if ( class_exists( 'WRE_Email_Queue' ) ) {
                \WRE_Email_Queue::init();
            }

            if ( class_exists( 'WRE_Cron' ) ) {
                \WRE_Cron::init();
            }

            if ( class_exists( 'WRE_Email_Sender' ) ) {
                \WRE_Email_Sender::init();
            }

            if ( class_exists( 'WRE_Verify' ) ) {
                \WRE_Verify::init();
            }

            if ( class_exists( 'WRE_Passwords' ) ) {
                \WRE_Passwords::init();
            }

            if ( class_exists( 'WRE_Consent' ) ) {
                \WRE_Consent::init();
            }

            if ( class_exists( 'WRE_Orders' ) ) {
                \WRE_Orders::init();
            }

            if ( class_exists( 'WRE_Trials' ) ) {
                \WRE_Trials::init();
            }
        }

        /**
         * Temporary verification notice to confirm the core module is active.
         */
        public static function test_notice() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            echo '<div class="notice notice-success"><p><strong>Hello Email Engine</strong> — Core module active.</p></div>';
        }
    }
}
