<?php
/**
 * Email template management for the Wisdom Rain Email Engine plugin.
 *
 * @package WisdomRain\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WRE_Templates' ) ) {
    /**
     * Provides read/write access to email templates with safe overrides.
     */
    class WRE_Templates {
        const STORAGE_DIRECTORY = 'wre-templates';

        /**
         * Map of available template slugs to their labels and descriptions.
         *
         * @return array<string, array<string, string>>
         */
        public static function get_registered_templates() {
            return array(
                'welcome-verify'        => array(
                    'label'       => __( 'Welcome & Verify', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Greets new members and requests account verification.', 'wisdom-rain-email-engine' ),
                ),
                'password-reset'        => array(
                    'label'       => __( 'Password Reset', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Guides users through resetting their password securely.', 'wisdom-rain-email-engine' ),
                ),
                'subscription-confirm'  => array(
                    'label'       => __( 'Subscription Confirmation', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Confirms a new subscription or enrollment.', 'wisdom-rain-email-engine' ),
                ),
                'payment-receipt'       => array(
                    'label'       => __( 'Payment Receipt', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Provides a receipt after a successful payment.', 'wisdom-rain-email-engine' ),
                ),
                'trial-expired'         => array(
                    'label'       => __( 'Trial Expired', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Informs members that their trial access has ended.', 'wisdom-rain-email-engine' ),
                ),
                'subscription-expired'  => array(
                    'label'       => __( 'Subscription Expired', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Alerts members that their subscription has ended.', 'wisdom-rain-email-engine' ),
                ),
                'event-invite'          => array(
                    'label'       => __( 'Event Invitation', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Invites users to upcoming Wisdom Rain experiences.', 'wisdom-rain-email-engine' ),
                ),
                'event-reminder'        => array(
                    'label'       => __( 'Event Reminder', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Reminds attendees about an upcoming session.', 'wisdom-rain-email-engine' ),
                ),
                'newsletter'            => array(
                    'label'       => __( 'Community Newsletter', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Shares the latest updates and inspiration.', 'wisdom-rain-email-engine' ),
                ),
                'feedback-request'      => array(
                    'label'       => __( 'Feedback Request', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Encourages members to share reflections and feedback.', 'wisdom-rain-email-engine' ),
                ),
                'weekly-digest'         => array(
                    'label'       => __( 'Weekly Digest', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Summarises recent activity and upcoming highlights.', 'wisdom-rain-email-engine' ),
                ),
                'verify-reminder'       => array(
                    'label'       => __( 'Verify Reminder', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Reminds members to confirm their email address.', 'wisdom-rain-email-engine' ),
                ),
                'plan-reminder'         => array(
                    'label'       => __( 'Plan Renewal Reminder', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Gently prompts members about an upcoming plan renewal.', 'wisdom-rain-email-engine' ),
                ),
                'comeback'              => array(
                    'label'       => __( 'Come Back Message', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Invites lapsed members to rejoin the community.', 'wisdom-rain-email-engine' ),
                ),
                'newyear'               => array(
                    'label'       => __( 'New Year Renewal', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Seasonal campaign encouraging members to recommit at the start of the year.', 'wisdom-rain-email-engine' ),
                ),
                'valentine'             => array(
                    'label'       => __( 'Valentine Gratitude', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Celebrates community connections with a Valentine themed message.', 'wisdom-rain-email-engine' ),
                ),
                'summer'                => array(
                    'label'       => __( 'Summer Reset', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Highlights warm-season programming for members seeking a reset.', 'wisdom-rain-email-engine' ),
                ),
                'exclusive'             => array(
                    'label'       => __( 'Exclusive Drop', 'wisdom-rain-email-engine' ),
                    'description' => __( 'Announces limited releases or premium practice bundles.', 'wisdom-rain-email-engine' ),
                ),
            );
        }

        /**
         * Register hooks required for managing template storage and UI.
         */
        public static function init() {
            add_action( 'init', array( __CLASS__, 'ensure_storage_directory' ) );
            add_action( 'admin_menu', array( __CLASS__, 'register_templates_page' ) );
        }

        /**
         * Register Templates tab in Admin.
         */
        public static function register_templates_page() {
            add_submenu_page(
                'wre-dashboard',
                __( 'Email Templates', 'wisdom-rain-email-engine' ),
                __( 'Templates', 'wisdom-rain-email-engine' ),
                'manage_options',
                'wre-templates',
                array( __CLASS__, 'render_templates_page' )
            );
        }

        /**
         * Render template editor UI within its own admin page.
         */
        public static function render_templates_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $templates = self::get_registered_templates();
            $template  = isset( $_GET['template'] ) ? self::normalize_template_slug( wp_unslash( $_GET['template'] ) ) : '';

            if ( $template && ! isset( $templates[ $template ] ) ) {
                $template = '';
            }

            echo '<div class="wrap"><h1>📧 ' . esc_html__( 'Email Templates', 'wisdom-rain-email-engine' ) . '</h1>';

            if ( empty( $template ) ) {
                self::render_templates_list( $templates );
                echo '</div>';
                return;
            }

            self::render_template_editor( $template, $templates );

            echo '</div>';
        }

        /**
         * Output the templates overview list.
         *
         * @param array<string, array<string, string>> $templates Template metadata map.
         */
        protected static function render_templates_list( $templates ) {
            if ( empty( $templates ) ) {
                echo '<p>' . esc_html__( 'No templates registered yet.', 'wisdom-rain-email-engine' ) . '</p>';
                return;
            }

            echo '<p>' . esc_html__( 'Select a template to customise. Overrides are stored safely in wp-content/uploads/wre-templates/.', 'wisdom-rain-email-engine' ) . '</p>';
            echo '<ul class="wre-template-list">';

            foreach ( $templates as $slug => $meta ) {
                $label       = isset( $meta['label'] ) ? $meta['label'] : $slug;
                $description = isset( $meta['description'] ) ? $meta['description'] : '';
                $url         = add_query_arg(
                    array(
                        'page'     => 'wre-templates',
                        'template' => $slug,
                    ),
                    admin_url( 'admin.php' )
                );

                echo '<li class="wre-template-list__item">';
                echo '<h2><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></h2>';

                if ( $description ) {
                    echo '<p class="description">' . esc_html( $description ) . '</p>';
                }

                echo '</li>';
            }

            echo '</ul>';
        }

        /**
         * Render the editing interface for a single template.
         *
         * @param string                                       $template_slug Template identifier.
         * @param array<string, array<string, string>>|string[] $templates     Template metadata map.
         */
        protected static function render_template_editor( $template_slug, $templates ) {
            $template_slug = self::normalize_template_slug( $template_slug );
            $content       = self::get_template_content( $template_slug );
            $message       = '';
            $message_type  = 'updated';

            if ( isset( $_POST['wre_save_template'] ) ) {
                check_admin_referer( 'wre_save_template_' . $template_slug );

                $raw_content = isset( $_POST['wre_template_content'] ) ? wp_unslash( $_POST['wre_template_content'] ) : '';
                $result      = self::save_template_content( $template_slug, $raw_content );

                if ( is_wp_error( $result ) ) {
                    $message      = $result->get_error_message();
                    $message_type = 'error';
                } else {
                    $message = __( 'Template saved successfully.', 'wisdom-rain-email-engine' );
                    $content = self::get_template_content( $template_slug );
                }
            }

            echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=wre-templates' ) ) . '">' . esc_html__( '← Back to template list', 'wisdom-rain-email-engine' ) . '</a></p>';

            if ( $message ) {
                printf(
                    '<div class="notice notice-%1$s"><p>%2$s</p></div>',
                    esc_attr( $message_type ),
                    esc_html( $message )
                );
            }

            $label = isset( $templates[ $template_slug ]['label'] ) ? $templates[ $template_slug ]['label'] : $template_slug;

            echo '<h2>' . esc_html( sprintf( __( 'Editing: %s', 'wisdom-rain-email-engine' ), $label ) ) . '</h2>';

            echo '<form method="post">';
            wp_nonce_field( 'wre_save_template_' . $template_slug );
            wp_editor( $content, 'wre_template_content', array(
                'textarea_rows' => 25,
                'media_buttons' => false,
            ) );
            submit_button( __( 'Save Template', 'wisdom-rain-email-engine' ), 'primary', 'wre_save_template' );
            echo '</form>';
        }

        /**
         * Ensure the upload directory for template overrides exists.
         */
        public static function ensure_storage_directory() {
            $paths = self::get_storage_paths();

            if ( empty( $paths['path'] ) || file_exists( $paths['path'] ) ) {
                return;
            }

            wp_mkdir_p( $paths['path'] );
        }

        /**
         * Retrieve the HTML content for a template.
         *
         * @param string $slug Template identifier.
         *
         * @return string
         */
        public static function get_template_content( $slug ) {
            $slug = self::normalize_template_slug( $slug );

            $paths = self::get_template_paths( $slug );

            foreach ( $paths as $path ) {
                if ( ! empty( $path ) && file_exists( $path ) ) {
                    $contents = file_get_contents( $path );

                    if ( false !== $contents ) {
                        return $contents;
                    }
                }
            }

            return '';
        }

        /**
         * Retrieve the full path to the effective template file.
         *
         * @param string $slug Template identifier.
         *
         * @return string
         */
        public static function get_template_path( $slug ) {
            $slug  = self::normalize_template_slug( $slug );
            $paths = self::get_template_paths( $slug );

            foreach ( $paths as $path ) {
                if ( ! empty( $path ) && file_exists( $path ) ) {
                    return $path;
                }
            }

            return '';
        }

        /**
         * Persist the supplied HTML to the override directory.
         *
         * @param string $slug     Template identifier.
         * @param string $contents Template markup.
         *
         * @return true|WP_Error
         */
        public static function save_template_content( $slug, $contents ) {
            $slug = self::normalize_template_slug( $slug );

            $templates = self::get_registered_templates();

            if ( ! isset( $templates[ $slug ] ) ) {
                return new WP_Error( 'wre_invalid_template', __( 'Unknown email template.', 'wisdom-rain-email-engine' ) );
            }

            $paths = self::get_storage_paths();

            if ( empty( $paths['path'] ) ) {
                return new WP_Error( 'wre_storage_unavailable', __( 'Template storage directory unavailable.', 'wisdom-rain-email-engine' ) );
            }

            self::ensure_storage_directory();

            $file = trailingslashit( $paths['path'] ) . self::get_template_filename( $slug );

            $sanitized = self::sanitize_template_content( $contents );

            $result = file_put_contents( $file, $sanitized );

            if ( false === $result ) {
                return new WP_Error( 'wre_write_failure', __( 'Unable to write template override.', 'wisdom-rain-email-engine' ) );
            }

            return true;
        }

        /**
         * Render a template with the provided placeholder values.
         *
         * @param string $slug      Template identifier.
         * @param array  $context   Placeholder map in the form token => value.
         *
         * @return string
         */
        public static function render_template( $slug, $context = array() ) {
            $slug = self::normalize_template_slug( $slug );

            $template = self::get_template_content( $slug );

            if ( '' === $template ) {
                return '';
            }

            $tokens = self::prepare_template_tokens( $slug, $context );

            if ( ! empty( $tokens ) ) {
                $search  = array();
                $replace = array();

                foreach ( $tokens as $token => $value ) {
                    $search[]  = '{{' . $token . '}}';
                    $search[]  = '{{ ' . $token . ' }}';
                    $replace[] = $value;
                    $replace[] = $value;
                }

                $template = str_replace( $search, $replace, $template );
            }

            // Remove any unreplaced tokens to avoid leaking placeholders in the final email.
            $template = preg_replace( '/{{\s*([\w\-]+)\s*}}/', '', $template );

            return $template;
        }

        /**
         * Prepare markup for safe storage.
         *
         * @param string $contents Template HTML.
         *
         * @return string
         */
        protected static function sanitize_template_content( $contents ) {
            if ( ! function_exists( 'wp_kses_post' ) ) {
                return $contents;
            }

            return wp_kses_post( $contents );
        }

        /**
         * Retrieve storage path and URL for overrides.
         *
         * @return array{path:string,url:string}
         */
        protected static function get_storage_paths() {
            $uploads = wp_upload_dir();

            if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
                return array(
                    'path' => '',
                    'url'  => '',
                );
            }

            $path = trailingslashit( $uploads['basedir'] ) . self::STORAGE_DIRECTORY;
            $url  = trailingslashit( $uploads['baseurl'] ) . self::STORAGE_DIRECTORY;

            return array(
                'path' => $path,
                'url'  => $url,
            );
        }

        /**
         * Merge default placeholders with provided context and sanitize values for output.
         *
         * @param string $slug    Template slug for context-aware filters.
         * @param array  $context Placeholder values supplied by the caller.
         *
         * @return array<string, string>
         */
        protected static function prepare_template_tokens( $slug, $context ) {
            $defaults = array(
                'site_name'       => get_bloginfo( 'name' ),
                'site_tagline'    => get_bloginfo( 'description' ),
                'site_url'        => home_url(),
                'support_email'   => get_option( 'admin_email' ),
                'unsubscribe_url' => '',
            );

            $context = wp_parse_args( $context, $defaults );
            $context = apply_filters( 'wre_template_tokens', $context, $defaults, $slug );

            $tokens = array();

            foreach ( $context as $key => $value ) {
                if ( ! is_scalar( $value ) || '' === $value ) {
                    continue;
                }

                $token = strtolower( sanitize_key( $key ) );

                if ( '' === $token ) {
                    continue;
                }

                $tokens[ $token ] = esc_html( (string) $value );
            }

            return $tokens;
        }

        /**
         * Provide the absolute paths to check for a template.
         *
         * @param string $slug Template identifier.
         *
         * @return array<int, string>
         */
        protected static function get_template_paths( $slug ) {
            $paths     = array();
            $filenames = self::get_template_filenames( $slug );
            $storage   = self::get_storage_paths();

            foreach ( $filenames as $filename ) {
                if ( '' === $filename ) {
                    continue;
                }

                if ( ! empty( $storage['path'] ) ) {
                    $paths[] = trailingslashit( $storage['path'] ) . $filename;
                }

                if ( defined( 'WRE_PATH' ) ) {
                    $paths[] = trailingslashit( WRE_PATH ) . 'templates/emails/' . $filename;
                }
            }

            return array_values( array_unique( $paths ) );
        }

        /**
         * Generate the filename used for a template slug.
         *
         * @param string $slug Template identifier.
         *
         * @return string
         */
        protected static function get_template_filename( $slug ) {
            $filenames = self::get_template_filenames( $slug );

            $filename = reset( $filenames );

            return $filename ? $filename : 'email-' . sanitize_key( $slug ) . '.html';
        }

        /**
         * Retrieve candidate filenames for a template slug, supporting legacy and PHP variants.
         *
         * @param string $slug Template identifier.
         *
         * @return array<int, string>
         */
        protected static function get_template_filenames( $slug ) {
            $base = 'email-' . sanitize_key( $slug );

            $filenames = array(
                $base . '.html',
                $base . '.html.php',
            );

            /**
             * Filter the list of template filenames to check for a given slug.
             *
             * @param array<int, string> $filenames Candidate filenames.
             * @param string             $slug      Template identifier.
             */
            $filenames = apply_filters( 'wre_template_filenames', $filenames, $slug );

            return array_values( array_unique( array_filter( $filenames ) ) );
        }

        /**
         * Normalise template slug values to ensure compatibility between legacy and new identifiers.
         *
         * @param string $slug Potentially prefixed template identifier.
         *
         * @return string
         */
        protected static function normalize_template_slug( $slug ) {
            $slug = sanitize_key( $slug );

            if ( 0 === strpos( $slug, 'email-' ) ) {
                $slug = substr( $slug, 6 );
            }

            return $slug;
        }
    }
}
