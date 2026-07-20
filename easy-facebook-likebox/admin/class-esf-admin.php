<?php

/*
* Stop execution if someone tried to get file directly.
*/
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !class_exists( 'ESF_Admin' ) ) {
    class ESF_Admin {
        function __construct() {
            $this->load_welcome_dependencies();
            add_action( 'rest_api_init', array($this, 'register_welcome_rest_routes') );
            add_action( 'admin_init', array($this, 'maybe_redirect_completed_welcome') );
            add_action( 'admin_init', array('ESF_Admin_Paths', 'maybe_redirect_legacy_hub'), 1 );
            add_action( 'admin_menu', array($this, 'esf_menu') );
            add_action( 'admin_menu', array($this, 'esf_settings_submenu'), 101 );
            add_action( 'admin_menu', array('ESF_Admin_Menu_Order', 'reorder_submenus'), 999 );
            add_action( 'admin_head', array($this, 'esf_debug_token') );
            add_action( 'admin_enqueue_scripts', array($this, 'esf_admin_assets') );
            add_action( 'wp_ajax_esf_change_module_status', array($this, 'esf_change_module_status') );
            add_action( 'wp_ajax_esf_remove_access_token', array($this, 'esf_remove_access_token') );
            add_action( 'wp_ajax_esf_save_general_settings', array($this, 'esf_save_general_settings') );
            add_action( 'wp_ajax_esf_save_gdpr_settings', array($this, 'esf_save_gdpr_settings') );
            add_action( 'wp_ajax_esf_save_translation_settings', array($this, 'esf_save_translation_settings') );
            add_action( 'admin_notices', array($this, 'esf_admin_notice') );
            add_action( 'wp_ajax_esf_hide_rating_notice', array($this, 'esf_hide_rating_notice') );
            add_action( 'wp_ajax_esf_hide_row_notice', array($this, 'hide_row_notice') );
            add_action( 'admin_head', array($this, 'esf_hide_notices') );
            add_action( 'pre_get_posts', array($this, 'esf_exclude_demo_pages'), 1 );
        }

        /**
         * Load welcome wizard dependencies once per request.
         *
         * Kept private and explicit so the dependency graph for the new
         * wizard never relies on autoloaders the rest of the plugin doesn't
         * have. Wraps each include in file_exists guards because the wizard
         * code lives outside the legacy module trees.
         *
         * @since 6.8.0
         * @return void
         */
        private function load_welcome_dependencies() {
            $base = ( defined( 'FTA_PLUGIN_DIR' ) ? FTA_PLUGIN_DIR : plugin_dir_path( dirname( __FILE__ ) ) );
            $state_file = $base . 'includes/welcome/class-esf-welcome-state.php';
            if ( !class_exists( 'ESF_Welcome_State' ) && file_exists( $state_file ) ) {
                require_once $state_file;
            }
            $api_file = $base . 'admin/api/class-esf-api-welcome.php';
            if ( !class_exists( 'ESF_API_Welcome' ) && file_exists( $api_file ) ) {
                require_once $api_file;
            }
            $missing_feed_api = $base . 'admin/api/class-esf-api-missing-feed.php';
            if ( !class_exists( 'ESF_API_Missing_Feed' ) && file_exists( $missing_feed_api ) ) {
                require_once $missing_feed_api;
            }
            if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
                $moderation_api = $base . 'admin/api/class-esf-api-moderation.php';
                if ( !class_exists( 'ESF_API_Moderation' ) && file_exists( $moderation_api ) ) {
                    require_once $moderation_api;
                }
                $shoppable_api = $base . 'admin/api/class-esf-api-shoppable.php';
                if ( !class_exists( 'ESF_API_Shoppable' ) && file_exists( $shoppable_api ) ) {
                    require_once $shoppable_api;
                }
            }
            $review_request_api = $base . 'admin/api/class-esf-api-review-request.php';
            if ( !class_exists( 'ESF_API_Review_Request' ) && file_exists( $review_request_api ) ) {
                require_once $review_request_api;
            }
            $hub_api = $base . 'admin/api/class-esf-api-hub.php';
            if ( !class_exists( 'ESF_API_Hub' ) && file_exists( $hub_api ) ) {
                require_once $hub_api;
            }
            $settings_api = $base . 'admin/api/class-esf-api-settings.php';
            if ( !class_exists( 'ESF_API_Settings' ) && file_exists( $settings_api ) ) {
                require_once $settings_api;
            }
        }

        /**
         * Register the welcome wizard REST routes on rest_api_init.
         *
         * @since 6.8.0
         * @return void
         */
        public function register_welcome_rest_routes() {
            if ( class_exists( 'ESF_API_Welcome' ) ) {
                ESF_API_Welcome::register_routes();
            }
            if ( class_exists( 'ESF_API_Missing_Feed' ) ) {
                ESF_API_Missing_Feed::register_routes();
            }
            if ( class_exists( 'ESF_API_Moderation' ) ) {
                ESF_API_Moderation::register_routes();
            }
            if ( class_exists( 'ESF_API_Shoppable' ) ) {
                ESF_API_Shoppable::register_routes();
            }
            if ( class_exists( 'ESF_API_Review_Request' ) ) {
                ESF_API_Review_Request::register_routes();
            }
            if ( class_exists( 'ESF_API_Hub' ) ) {
                ESF_API_Hub::register_routes();
            }
            if ( class_exists( 'ESF_API_Settings' ) ) {
                ESF_API_Settings::register_routes();
            }
        }

        /**
         * Send already-onboarded users straight to the dashboard.
         *
         * The wizard is a strictly first-run experience: once a user has
         * completed (or skipped) it, requests to ?page=esf_welcome bounce to
         * the main dashboard. An undocumented `&force=1` escape hatch lets
         * developers and support staff revisit the wizard for testing.
         *
         * @since 6.8.0
         * @return void
         */
        public function maybe_redirect_completed_welcome() {
            if ( !is_admin() || wp_doing_ajax() ) {
                return;
            }
            $page = ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' );
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision.
            if ( 'esf_welcome' !== $page ) {
                return;
            }
            $force = ( isset( $_GET['force'] ) ? sanitize_text_field( wp_unslash( $_GET['force'] ) ) : '' );
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision.
            if ( '1' === $force ) {
                return;
            }
            if ( !class_exists( 'ESF_Welcome_State' ) || !ESF_Welcome_State::is_completed() ) {
                return;
            }
            wp_safe_redirect( ESF_Admin_Paths::hub_admin_url() );
            exit;
        }

        /**
         * Enqueue React + SCSS bundle for the welcome wizard page.
         *
         * Mirrors the pattern used by ESF_Twitter_Admin / ESF_YouTube_Admin:
         * read the wp-scripts asset manifest for an accurate dependency list
         * and version, then localise a small bootstrap object the wizard
         * uses for its initial render.
         *
         * @since 6.8.0
         * @return void
         */
        public function enqueue_welcome_assets() {
            $base_url = ( defined( 'FTA_PLUGIN_URL' ) ? FTA_PLUGIN_URL : plugin_dir_url( dirname( __FILE__ ) ) );
            $base_dir = ( defined( 'FTA_PLUGIN_DIR' ) ? FTA_PLUGIN_DIR : plugin_dir_path( dirname( __FILE__ ) ) );
            $asset_path = $base_dir . 'admin/assets/build/welcome/welcome.asset.php';
            if ( !file_exists( $asset_path ) ) {
                return;
            }
            $asset = (include $asset_path);
            if ( !is_array( $asset ) ) {
                return;
            }
            $dependencies = ( isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array() );
            $version = ( isset( $asset['version'] ) ? (string) $asset['version'] : (( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' )) );
            wp_enqueue_script(
                'esf-welcome-wizard',
                $base_url . 'admin/assets/build/welcome/welcome.js',
                $dependencies,
                $version,
                true
            );
            wp_enqueue_style(
                'esf-welcome-wizard',
                $base_url . 'admin/assets/build/welcome/welcome.css',
                array('wp-components'),
                $version
            );
            wp_set_script_translations( 'esf-welcome-wizard', 'easy-facebook-likebox' );
            wp_localize_script( 'esf-welcome-wizard', 'esfWelcomeBootstrap', array(
                'restUrl'   => esc_url_raw( rest_url() ),
                'restNonce' => wp_create_nonce( 'wp_rest' ),
                'pluginUrl' => esc_url_raw( $base_url ),
                'logoUrl'   => esc_url_raw( $base_url . 'admin/assets/images/plugin-logo.png' ),
                'isPro'     => function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only(),
            ) );
        }

        /**
         * Enqueue React + SCSS bundle for the main module hub page.
         *
         * @since 6.9.0
         * @return void
         */
        public function enqueue_hub_assets() {
            $base_url = ( defined( 'FTA_PLUGIN_URL' ) ? FTA_PLUGIN_URL : plugin_dir_url( dirname( __FILE__ ) ) );
            $base_dir = ( defined( 'FTA_PLUGIN_DIR' ) ? FTA_PLUGIN_DIR : plugin_dir_path( dirname( __FILE__ ) ) );
            $asset_path = $base_dir . 'admin/assets/build/hub/hub.asset.php';
            if ( !file_exists( $asset_path ) ) {
                return;
            }
            $asset = (include $asset_path);
            if ( !is_array( $asset ) ) {
                return;
            }
            $dependencies = ( isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array() );
            $version = ( isset( $asset['version'] ) ? (string) $asset['version'] : (( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' )) );
            wp_enqueue_script(
                'esf-module-hub',
                $base_url . 'admin/assets/build/hub/hub.js',
                $dependencies,
                $version,
                true
            );
            wp_enqueue_style(
                'esf-module-hub',
                $base_url . 'admin/assets/build/hub/hub.css',
                array('wp-components'),
                $version
            );
            wp_set_script_translations( 'esf-module-hub', 'easy-facebook-likebox' );
            wp_localize_script( 'esf-module-hub', 'esfHubBootstrap', array(
                'restUrl'   => esc_url_raw( rest_url() ),
                'restNonce' => wp_create_nonce( 'wp_rest' ),
                'pluginUrl' => esc_url_raw( $base_url ),
                'logoUrl'   => esc_url_raw( $base_url . 'admin/assets/images/plugin-logo.png' ),
            ) );
        }

        /**
         * Enqueue React + SCSS bundle for the global settings page.
         *
         * @since 6.9.0
         * @return void
         */
        public function enqueue_settings_assets() {
            $base_url = ( defined( 'FTA_PLUGIN_URL' ) ? FTA_PLUGIN_URL : plugin_dir_url( dirname( __FILE__ ) ) );
            $base_dir = ( defined( 'FTA_PLUGIN_DIR' ) ? FTA_PLUGIN_DIR : plugin_dir_path( dirname( __FILE__ ) ) );
            $asset_path = $base_dir . 'admin/assets/build/settings/settings.asset.php';
            if ( !file_exists( $asset_path ) ) {
                return;
            }
            $asset = (include $asset_path);
            if ( !is_array( $asset ) ) {
                return;
            }
            $dependencies = ( isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array() );
            $version = ( isset( $asset['version'] ) ? (string) $asset['version'] : (( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' )) );
            wp_enqueue_script(
                'esf-settings',
                $base_url . 'admin/assets/build/settings/settings.js',
                $dependencies,
                $version,
                true
            );
            wp_enqueue_style(
                'esf-settings',
                $base_url . 'admin/assets/build/settings/settings.css',
                array('wp-components'),
                $version
            );
            wp_set_script_translations( 'esf-settings', 'easy-facebook-likebox' );
            wp_localize_script( 'esf-settings', 'esfSettingsBootstrap', array(
                'restUrl'   => esc_url_raw( rest_url() ),
                'restNonce' => wp_create_nonce( 'wp_rest' ),
                'pluginUrl' => esc_url_raw( $base_url ),
                'logoUrl'   => esc_url_raw( $base_url . 'admin/assets/images/plugin-logo.png' ),
                'isPro'     => function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only(),
            ) );
        }

        /**
         * On ESF admin pages, show only notices from this plugin (and Freemius) and hide all others via CSS.
         * ESF notices use .fta_msg, Freemius notices use .fs-notice; other plugins use .notice or .update-nag.
         */
        public function esf_hide_notices() {
            $screen = get_current_screen();
            if ( !isset( $screen->id ) ) {
                echo '<style>.toplevel_page_easy-social-feed .wp-menu-image img{padding-top: 4px!important;}</style>';
                return;
            }
            $esf_admin_screens = ( class_exists( 'ESF_Review_Request' ) ? ESF_Review_Request::get_esf_admin_screen_ids() : (( class_exists( 'ESF_Admin_Paths' ) ? ESF_Admin_Paths::get_esf_admin_screen_ids() : array('toplevel_page_easy-social-feed') )) );
            if ( in_array( $screen->id, $esf_admin_screens, true ) ) {
                $body_class = esc_attr( sanitize_html_class( $screen->id ) );
                echo '<style>';
                echo '.toplevel_page_easy-social-feed .wp-menu-image img{padding-top: 4px!important;}';
                // Hide other plugins' notices: show only our Freemius (.fs-slug-easy-facebook-likebox) and ESF (.fta_msg).
                echo "body.{$body_class} .notice:not(.fs-notice){display:none !important;}";
                echo "body.{$body_class} .fs-notice:not(.fs-slug-easy-facebook-likebox){display:none !important;}";
                echo "body.{$body_class} .update-nag:not(.fta_msg){display:none !important;}";
                echo '</style>';
            } else {
                echo '<style>.toplevel_page_easy-social-feed .wp-menu-image img{padding-top: 4px!important;}</style>';
            }
        }

        /**
         * Includes common admin scripts and styles for FB and Insta.
         *
         * @since 1.0.0
         *
         * @param $hook
         */
        public function esf_admin_assets( $hook ) {
            // load plugin files only on it's pages
            if ( ESF_Admin_Paths::hub_screen_id() !== $hook && ESF_Admin_Paths::submenu_screen_id( 'mif' ) !== $hook && ESF_Admin_Paths::submenu_screen_id( 'easy-facebook-likebox' ) !== $hook && ESF_Admin_Paths::submenu_screen_id( ESF_Admin_Paths::SETTINGS_SLUG ) !== $hook && 'admin_page_' . ESF_Admin_Paths::WELCOME_SLUG !== $hook ) {
                return false;
            }
            // Welcome wizard owns its own React bundle and does not need
            // the legacy admin assets (Materialize-era jQuery + CSS). Loading
            // them would conflict with the wp-components styles and add ~80KB
            // of unused JS to the wizard page.
            if ( 'admin_page_' . ESF_Admin_Paths::WELCOME_SLUG === $hook ) {
                $this->enqueue_welcome_assets();
                return false;
            }
            if ( ESF_Admin_Paths::hub_screen_id() === $hook ) {
                $this->enqueue_hub_assets();
                return false;
            }
            if ( ESF_Admin_Paths::submenu_screen_id( ESF_Admin_Paths::SETTINGS_SLUG ) === $hook ) {
                $this->enqueue_settings_assets();
                return false;
            }
            wp_deregister_script( 'bootstrap.min' );
            wp_deregister_script( 'bootstrap' );
            wp_deregister_script( 'jquery-ui-tabs' );
            wp_enqueue_style( 'esf-animations', FTA_PLUGIN_URL . 'admin/assets/css/esf-animations.css' );
            wp_enqueue_style( 'esf-admin', FTA_PLUGIN_URL . 'admin/assets/css/esf-admin.css' );
            wp_enqueue_script( 'jquery-effects-slide' );
            wp_enqueue_script(
                'clipboard-js',
                FTA_PLUGIN_URL . 'admin/assets/js/clipboard.min.js',
                array(),
                false,
                true
            );
            wp_enqueue_script(
                'esf-admin',
                FTA_PLUGIN_URL . 'admin/assets/js/esf-admin.js',
                array('jquery', 'clipboard-js'),
                false,
                true
            );
            wp_localize_script( 'esf-admin', 'fta', array(
                'copied'              => __( 'Copied', 'easy-facebook-likebox' ),
                'deleting'            => __( 'Deleting', 'easy-facebook-likebox' ),
                'error'               => __( 'Something went wrong!', 'easy-facebook-likebox' ),
                'saving'              => __( 'Saving…', 'easy-facebook-likebox' ),
                'nothing_to_autofill' => __( 'Nothing to autofill.', 'easy-facebook-likebox' ),
                'autofill_success'    => __( 'Translations filled and saved.', 'easy-facebook-likebox' ),
                'reset_confirm'       => __( 'Reset all custom text to defaults?', 'easy-facebook-likebox' ),
                'reset_done'          => __( 'Defaults restored.', 'easy-facebook-likebox' ),
                'ajax_url'            => admin_url( 'admin-ajax.php' ),
                'nonce'               => wp_create_nonce( 'esf-ajax-nonce' ),
                'rest_url'            => esc_url_raw( rest_url( 'esf/v1/' ) ),
                'rest_nonce'          => wp_create_nonce( 'wp_rest' ),
                'review_url'          => ( class_exists( 'ESF_Review_Request' ) ? ESF_Review_Request::REVIEW_URL : 'https://wordpress.org/support/plugin/easy-facebook-likebox/reviews/?filter=5#new-post' ),
            ) );
            wp_enqueue_script( 'thickbox' );
            wp_enqueue_style( 'thickbox' );
            wp_enqueue_script( 'media-upload' );
            wp_enqueue_script(
                'esf-image-uploader',
                FTA_PLUGIN_URL . 'admin/assets/js/esf-image-uploader.js',
                array('jquery', 'media-upload', 'thickbox'),
                '1.0.0',
                true
            );
            wp_localize_script( 'esf-image-uploader', 'esf_image_uploader', array(
                'title'    => __( 'Select or Upload Image', 'easy-facebook-likebox' ),
                'btn_text' => __( 'Use this Image', 'easy-facebook-likebox' ),
            ) );
            wp_enqueue_media();
            return false;
        }

        /**
         * Add plugin menu
         *
         * @since 1.0.0
         */
        public function esf_menu() {
            add_menu_page(
                __( 'Easy Social Feed', 'easy-facebook-likebox' ),
                __( 'Easy Social Feed', 'easy-facebook-likebox' ),
                'administrator',
                ESF_Admin_Paths::HUB_SLUG,
                array($this, 'esf_page'),
                FTA_PLUGIN_URL . 'admin/assets/images/plugin_icon.png',
                25
            );
            add_submenu_page(
                'hidden',
                __( 'Welcome', 'easy-facebook-likebox' ),
                __( 'Welcome', 'easy-facebook-likebox' ),
                'administrator',
                'esf_welcome',
                array($this, 'esf_welcome_page')
            );
        }

        /**
         * Add Settings submenu after Facebook and Instagram (runs at priority 101).
         *
         * @since 6.8.0
         */
        public function esf_settings_submenu() {
            add_submenu_page(
                ESF_Admin_Paths::HUB_SLUG,
                __( 'Settings', 'easy-facebook-likebox' ),
                __( 'Settings', 'easy-facebook-likebox' ),
                'manage_options',
                'esf-settings',
                array($this, 'esf_settings_page'),
                ESF_Admin_Menu_Order::SETTINGS
            );
        }

        /**
         * Render the first-run setup wizard page.
         *
         * @since 1.0.0
         * @since 6.8.0 Switched to React-based wizard mount (html-admin-page-welcome.php).
         * @return void
         */
        function esf_welcome_page() {
            include_once FTA_PLUGIN_DIR . 'admin/views/html-admin-page-welcome.php';
        }

        /**
         * Includes view of Easy Soical Feed page
         *
         * @since 1.0.0
         */
        function esf_page() {
            include_once FTA_PLUGIN_DIR . 'admin/views/html-admin-page-easy-social-feed.php';
        }

        /**
         * Settings page (GDPR and other global settings).
         *
         * @since 6.8.0
         */
        function esf_settings_page() {
            include_once FTA_PLUGIN_DIR . 'admin/views/html-admin-page-esf-settings.php';
        }

        /**
         * Save global General settings (e.g. preserve on uninstall) via AJAX.
         *
         * @since 6.8.0
         */
        public function esf_save_general_settings() {
            esf_check_ajax_referer();
            $preserve = isset( $_POST['preserve_settings_on_uninstall'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['preserve_settings_on_uninstall'] ) );
            $api_locale = ( isset( $_POST['api_locale'] ) ? sanitize_text_field( wp_unslash( $_POST['api_locale'] ) ) : '' );
            if ( class_exists( 'ESF_Settings' ) ) {
                ESF_Settings::save_general( $preserve, $api_locale );
                wp_send_json_success( __( 'Settings saved successfully!', 'easy-facebook-likebox' ) );
            }
            $FTA = new Feed_Them_All();
            $fta_settings = $FTA->fta_get_settings();
            if ( !is_array( $fta_settings ) ) {
                $fta_settings = array();
            }
            $preserve = isset( $_POST['preserve_settings_on_uninstall'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['preserve_settings_on_uninstall'] ) );
            $fta_settings['preserve_settings_on_uninstall'] = ( $preserve ? 1 : 0 );
            $previous_api_locale = ( isset( $fta_settings['api_locale'] ) ? $fta_settings['api_locale'] : '' );
            $supported_locales = array_keys( esf_get_supported_api_locales() );
            if ( isset( $_POST['api_locale'] ) ) {
                $api_locale = sanitize_text_field( wp_unslash( $_POST['api_locale'] ) );
                $fta_settings['api_locale'] = ( in_array( $api_locale, $supported_locales, true ) ? $api_locale : '' );
            }
            if ( isset( $fta_settings['api_locale'] ) && $fta_settings['api_locale'] !== $previous_api_locale ) {
                if ( function_exists( 'esf_clear_feed_transients' ) ) {
                    esf_clear_feed_transients();
                }
                if ( class_exists( 'ESF_YouTube_Cache' ) && method_exists( 'ESF_YouTube_Cache', 'flush_api_cache' ) ) {
                    ESF_YouTube_Cache::flush_api_cache();
                }
            }
            update_option( 'fta_settings', $fta_settings );
            // update_option returns false when value is unchanged; that is still success.
            wp_send_json_success( __( 'Settings saved successfully!', 'easy-facebook-likebox' ) );
        }

        /**
         * Save global GDPR setting via AJAX.
         *
         * @since 6.8.0
         */
        public function esf_save_gdpr_settings() {
            esf_check_ajax_referer();
            if ( isset( $_POST['gdpr'] ) && class_exists( 'ESF_Settings' ) ) {
                $gdpr = sanitize_text_field( wp_unslash( $_POST['gdpr'] ) );
                if ( ESF_Settings::save_gdpr( $gdpr ) ) {
                    wp_send_json_success( __( 'Settings saved successfully!', 'easy-facebook-likebox' ) );
                }
                wp_send_json_error( __( 'Something went wrong! Please try again.', 'easy-facebook-likebox' ) );
            }
            $FTA = new Feed_Them_All();
            $fta_settings = $FTA->fta_get_settings();
            $original = $fta_settings;
            if ( isset( $_POST['gdpr'] ) ) {
                $gdpr = sanitize_text_field( wp_unslash( $_POST['gdpr'] ) );
                if ( in_array( $gdpr, array('auto', 'yes', 'no'), true ) ) {
                    $fta_settings['gdpr'] = $gdpr;
                }
            }
            if ( $fta_settings === $original ) {
                wp_send_json_success( __( 'Settings already saved.', 'easy-facebook-likebox' ) );
            }
            $updated = update_option( 'fta_settings', $fta_settings );
            if ( !is_wp_error( $updated ) ) {
                wp_send_json_success( __( 'Settings saved successfully!', 'easy-facebook-likebox' ) );
            }
            wp_send_json_error( __( 'Something went wrong! Please try again.', 'easy-facebook-likebox' ) );
        }

        /**
         * Save global translation (custom text) settings via AJAX.
         *
         * @since 6.8.0
         */
        public function esf_save_translation_settings() {
            esf_check_ajax_referer();
            $posted = ( isset( $_POST['esf_translation'] ) && is_array( $_POST['esf_translation'] ) ? wp_unslash( $_POST['esf_translation'] ) : array() );
            if ( class_exists( 'ESF_Settings' ) ) {
                $flat = array();
                foreach ( $posted as $key => $value ) {
                    if ( is_string( $key ) && is_string( $value ) ) {
                        $flat[$key] = $value;
                    }
                }
                ESF_Settings::save_translation( $flat );
                wp_send_json_success( __( 'Settings saved successfully!', 'easy-facebook-likebox' ) );
            }
            $FTA = new Feed_Them_All();
            $fta_settings = $FTA->fta_get_settings();
            if ( !is_array( $fta_settings ) ) {
                $fta_settings = array();
            }
            $allowed_keys = array();
            $all_strings = ESF_Translation_Strings::get_all_strings();
            foreach ( $all_strings as $category ) {
                foreach ( $category['strings'] as $item ) {
                    if ( !empty( $item['key'] ) ) {
                        $allowed_keys[$item['key']] = true;
                    }
                }
            }
            $posted = ( isset( $_POST['esf_translation'] ) && is_array( $_POST['esf_translation'] ) ? wp_unslash( $_POST['esf_translation'] ) : array() );
            $saved = array();
            foreach ( $posted as $key => $value ) {
                if ( isset( $allowed_keys[$key] ) && is_string( $value ) ) {
                    $saved[sanitize_text_field( $key )] = sanitize_text_field( $value );
                }
            }
            $fta_settings['translation'] = $saved;
            update_option( 'fta_settings', $fta_settings );
            // update_option returns false when value is unchanged; that is still success.
            wp_send_json_success( __( 'Settings saved successfully!', 'easy-facebook-likebox' ) );
        }

        /**
         * Changes the module status like enable or disable Facebook/Instagram modules
         *
         * @since 1.0.0
         */
        function esf_change_module_status() {
            esf_check_ajax_referer();
            $module_name = sanitize_text_field( $_POST['plugin'] );
            $module_status = sanitize_text_field( $_POST['status'] );
            if ( class_exists( 'ESF_Settings' ) ) {
                $status_updated = ESF_Settings::set_module_status( $module_name, $module_status );
            } else {
                $Feed_Them_All = new Feed_Them_All();
                $esf_settings = $Feed_Them_All->fta_get_settings();
                $esf_settings['plugins'][$module_name]['status'] = $module_status;
                $status_updated = update_option( 'fta_settings', $esf_settings );
            }
            if ( 'deactivated' === $module_status && 'twitter' === $module_name ) {
                esf_twitter_teardown_scheduled_jobs();
            }
            if ( $module_status === 'activated' ) {
                $status = __( ' Activated', 'easy-facebook-likebox' );
            } else {
                $status = __( ' Deactivated', 'easy-facebook-likebox' );
            }
            if ( isset( $status_updated ) ) {
                wp_send_json_success( __( ucfirst( $module_name ) . $status . ' Successfully', 'easy-facebook-likebox' ) );
            } else {
                wp_send_json_error( __( 'Something Went Wrong! Please try again.', 'easy-facebook-likebox' ) );
            }
        }

        /**
         * Removes the access token and deletes users access to the app.
         *
         * @since 1.0.0
         */
        function esf_remove_access_token() {
            esf_check_ajax_referer();
            $Feed_Them_All = new Feed_Them_All();
            $esf_settings = $Feed_Them_All->fta_get_settings();
            $access_token = $esf_settings['plugins']['facebook']['access_token'];
            if ( isset( $esf_settings['plugins']['facebook']['approved_pages'] ) ) {
                unset($esf_settings['plugins']['facebook']['approved_pages']);
            }
            if ( isset( $esf_settings['plugins']['facebook']['approved_groups'] ) ) {
                unset($esf_settings['plugins']['facebook']['approved_groups']);
            }
            unset($esf_settings['plugins']['facebook']['access_token']);
            $esf_settings['plugins']['instagram']['selected_type'] = 'personal';
            $delted_data = update_option( 'fta_settings', $esf_settings );
            $response = wp_remote_request( 'https://graph.facebook.com/v4.0/me/permissions?access_token=' . $access_token . '', array(
                'method' => 'DELETE',
            ) );
            wp_remote_retrieve_body( $response );
            if ( $delted_data ) {
                wp_send_json_success( __( 'Deleted', 'easy-facebook-likebox' ) );
            } else {
                wp_send_json_error( __( 'Something Went Wrong! Please try again.', 'easy-facebook-likebox' ) );
            }
        }

        /**
         * Displays the shared review-request admin notice on legacy ESF pages.
         *
         * @since 1.0.0
         * @return void
         */
        public function esf_admin_notice() {
            if ( class_exists( 'ESF_Review_Request' ) ) {
                ESF_Review_Request::render_legacy_admin_notice();
            }
        }

        /**
         * Legacy AJAX handler for old rating notice dismiss controls.
         *
         * @since 1.0.0
         * @return void
         */
        public function esf_hide_rating_notice() {
            if ( class_exists( 'ESF_Review_Request' ) ) {
                ESF_Review_Request::handle_action( 'dismiss' );
            } else {
                update_site_option( 'fta_supported', 'yes' );
            }
            echo wp_json_encode( 'success' );
            wp_die();
        }

        /**
         * Hide row layout notice permenately
         */
        public function hide_row_notice() {
            update_site_option( 'fta_row_layout_notice', 'yes' );
            echo wp_json_encode( array('success') );
            wp_die();
        }

        /**
         * Exclude demo pages from query
         *
         * @since 1.0.0
         *
         * @param $query
         *
         * @return mixed
         */
        function esf_exclude_demo_pages( $query ) {
            if ( !is_admin() ) {
                return $query;
            }
            global $pagenow;
            if ( 'edit.php' === $pagenow && (get_query_var( 'post_type' ) && 'page' === get_query_var( 'post_type' )) ) {
                $fta_class = new Feed_Them_All();
                $fta_settings = $fta_class->fta_get_settings();
                $fb_id = ( isset( $fta_settings['plugins']['facebook']['default_page_id'] ) ? absint( $fta_settings['plugins']['facebook']['default_page_id'] ) : 0 );
                $insta_id = ( isset( $fta_settings['plugins']['instagram']['default_page_id'] ) ? absint( $fta_settings['plugins']['instagram']['default_page_id'] ) : 0 );
                $exclude_ids = array_values( array_filter( array($fb_id, $insta_id) ) );
                if ( !empty( $exclude_ids ) ) {
                    $query->set( 'post__not_in', $exclude_ids );
                }
            }
            return $query;
        }

        /**
         * Debug the token and save info in DB
         */
        public function esf_debug_token() {
            if ( class_exists( 'Feed_Them_All' ) ) {
                $FTA = new Feed_Them_All();
                $fta_settings = $FTA->fta_get_settings();
                $access_token = '';
                $access_token_info = '';
                if ( isset( $fta_settings['plugins']['facebook']['access_token'] ) ) {
                    $access_token = $fta_settings['plugins']['facebook']['access_token'];
                }
                if ( isset( $fta_settings['plugins']['facebook']['access_token_info'] ) ) {
                    $access_token_info = $fta_settings['plugins']['facebook']['access_token_info'];
                }
            }
            if ( !$access_token ) {
                return;
            }
            if ( $access_token_info ) {
                return;
            }
            /*
             * Access token debug API endpoint
             */
            $fb_token_debug_url = add_query_arg( array(
                'input_token'  => $access_token,
                'access_token' => $access_token,
            ), 'https://graph.facebook.com/v6.0/debug_token' );
            $fb_token_info = wp_remote_get( $fb_token_debug_url );
            if ( is_array( $fb_token_info ) && !is_wp_error( $fb_token_info ) ) {
                $fb_token_info = json_decode( $fb_token_info['body'] );
                if ( isset( $fb_token_info->error ) ) {
                    return;
                }
                if ( isset( $fb_token_info->data ) ) {
                    $fta_settings['plugins']['facebook']['access_token_info']['data_access_expires_at'] = $fb_token_info->data->data_access_expires_at;
                    $fta_settings['plugins']['facebook']['access_token_info']['expires_at'] = $fb_token_info->data->expires_at;
                    $fta_settings['plugins']['facebook']['access_token_info']['is_valid'] = $fb_token_info->data->is_valid;
                    $fta_settings['plugins']['facebook']['access_token_info']['issued_at'] = $fb_token_info->data->issued_at;
                    $fta_settings['plugins']['facebook']['access_token_info']['app_id'] = $fb_token_info->data->app_id;
                    update_option( 'fta_settings', $fta_settings );
                    return;
                }
            }
        }

        /**
         * Check the access token validity if exists.
         *
         * @return $return_arr and reason
         */
        public function esf_access_token_valid() {
            if ( class_exists( 'Feed_Them_All' ) ) {
                $FTA = new Feed_Them_All();
                $fta_settings = $FTA->fta_get_settings();
                $access_token_info = '';
                if ( isset( $fta_settings['plugins']['facebook']['access_token_info'] ) ) {
                    $access_token_info = $fta_settings['plugins']['facebook']['access_token_info'];
                    $data_access_expires_at = $access_token_info['data_access_expires_at'];
                    $expires_at = $access_token_info['expires_at'];
                    $is_valid = $access_token_info['is_valid'];
                }
            }
            if ( !$access_token_info ) {
                return array(
                    'is_valid' => true,
                );
            }
            $return_arr = array(
                'is_valid' => true,
            );
            $current_timestamp = time();
            if ( $data_access_expires_at <= $current_timestamp ) {
                $return_arr = array(
                    'is_valid'      => false,
                    'reason'        => 'data_access_expired',
                    'error_message' => __( 'Attention! Data access to the current access token is expired. Please re-authenticate the app.', 'easy-facebook-likebox' ),
                );
            }
            if ( $expires_at > 0 && $expires_at <= $current_timestamp ) {
                $return_arr = array(
                    'is_valid'      => false,
                    'reason'        => 'token_expired',
                    'error_message' => __( 'Attention! Access token is expired. Please re-authenticate the app.', 'easy-facebook-likebox' ),
                );
            }
            return $return_arr;
        }

        /**
         * Get upgrade banner info from main site
         *
         * Discount/coupon come from {@see esf_get_pro_promo_offer()} so promo
         * numbers stay in one place.
         *
         * @return mixed|string[]
         */
        public function esf_upgrade_banner() {
            $promo = ( function_exists( 'esf_get_pro_promo_offer' ) ? esf_get_pro_promo_offer() : array(
                'discount' => '17%',
                'coupon'   => 'ESPF17',
            ) );
            $discount = ( isset( $promo['discount'] ) ? (string) $promo['discount'] : '17%' );
            $coupon = ( isset( $promo['coupon'] ) ? (string) $promo['coupon'] : 'ESPF17' );
            $banner_info = array(
                'name'              => 'Easy Social Feed',
                'bold'              => 'PRO',
                'fb-description'    => sprintf( 'Increase social followers, engage more users and get 10x traffic with %s off on all plans (including monthly billings). So grab this offer now before it will go forever.', $discount ),
                'insta-description' => sprintf( 'Increase social followers, engage more users and get 10x traffic with %s off on all plans (including monthly billings). So grab this offer now before it will go forever.', $discount ),
                'discount-text'     => '',
                'coupon'            => $coupon,
                'discount'          => $discount,
                'button-text'       => 'Upgrade Now',
                'button-url'        => esf_get_upgrade_url( 'general' ),
                'target'            => '_blank',
            );
            return $banner_info;
        }

    }

    new ESF_Admin();
}