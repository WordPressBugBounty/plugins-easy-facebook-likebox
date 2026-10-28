<?php

/**
 * Plugin Name: Easy Social Feed
 * Plugin URI:        https://wordpress.org/plugins/easy-facebook-likebox
 * Description:       Display Instagram, Facebook, YouTube, X and TikTok feeds on your website using blocks, widgets or shortcodes, with responsive layouts and live customization. Combine all networks in one Social Wall with Pro. Formerly "Easy Facebook Like Box and Custom Facebook Feed".
 * Version:           6.8.2
 * Author:            Easy Social Feed
 * Author URI:        https://easysocialfeed.com/
 * Text Domain:       easy-facebook-likebox
 */
// Freemius // If this file is called directly, abort.
if ( !defined( 'WPINC' ) ) {
    die;
}
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Run plugin cleanup on uninstall (options, transients, upload folders).
 * Hooked to Freemius after_uninstall so uninstall feedback is sent first.
 * Skips cleanup when "Preserve settings on uninstall" is enabled in General settings.
 *
 * @since 6.8.0
 */
function efl_fs_uninstall_cleanup() {
    global $wpdb;
    $fta_settings = get_option( 'fta_settings', array() );
    if ( !empty( $fta_settings['preserve_settings_on_uninstall'] ) ) {
        return;
    }
    $options = array(
        'fta_settings',
        'fta_supported',
        'efbl_version',
        'efbl_installDate',
        'efbl_version_type',
        'efbl_account_id',
        'efbl_skin_id',
        'mif_skin_id',
        'mif_account_id',
        'esf_twitter_settings',
        'esf_twitter_db_schema_version',
        'esf_tiktok_settings',
        'esf_tiktok_db_schema_version',
        'esf_facebook_settings',
        'esf_facebook_db_schema_version',
        'esf_facebook_use_new_system',
        'esf_facebook_migration_default_feed_id',
        'esf_facebook_legacy_feed_map',
        'esf_facebook_migrate_lock'
    );
    foreach ( $options as $option ) {
        delete_option( $option );
    }
    if ( is_multisite() ) {
        delete_site_option( 'fta_supported' );
    }
    $option_table = $wpdb->options;
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$option_table} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'efbl_skin_' ) . '%', $wpdb->esc_like( 'mif_skin_' ) . '%' ) );
    $like_esf = $wpdb->esc_like( '_transient_esf_' ) . '%';
    $like_esf_timeout = $wpdb->esc_like( '_transient_timeout_esf_' ) . '%';
    $like_efbl = $wpdb->esc_like( '_transient_efbl_' ) . '%';
    $like_efbl_timeout = $wpdb->esc_like( '_transient_timeout_efbl_' ) . '%';
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$option_table} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $like_esf,
        $like_esf_timeout,
        $like_efbl,
        $like_efbl_timeout
    ) );
    $upload_dir = wp_upload_dir();
    if ( !empty( $upload_dir['basedir'] ) ) {
        $base = trailingslashit( $upload_dir['basedir'] );
        $dirs = array(
            'esf-instagram',
            'esf-facebook',
            'esf-youtube',
            'esf-twitter',
            'esf-tiktok'
        );
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $fs = new WP_Filesystem_Direct(false);
        foreach ( $dirs as $dir ) {
            $path = $base . $dir;
            if ( $fs->exists( $path ) && $fs->is_dir( $path ) ) {
                $fs->rmdir( $path, true );
            }
        }
    }
    $twitter_db_installer = plugin_dir_path( __FILE__ ) . 'twitter/includes/database/class-esf-twitter-db-installer.php';
    if ( file_exists( $twitter_db_installer ) ) {
        require_once $twitter_db_installer;
        if ( class_exists( 'ESF_Twitter_DB_Installer' ) ) {
            ESF_Twitter_DB_Installer::drop_tables();
        }
    }
    $tiktok_db_installer = plugin_dir_path( __FILE__ ) . 'tiktok/includes/database/class-esf-tiktok-db-installer.php';
    if ( file_exists( $tiktok_db_installer ) ) {
        require_once $tiktok_db_installer;
        if ( class_exists( 'ESF_TikTok_DB_Installer' ) ) {
            ESF_TikTok_DB_Installer::drop_tables();
        }
    }
    $facebook_db_installer = plugin_dir_path( __FILE__ ) . 'facebook/includes/database/class-esf-facebook-db-installer.php';
    if ( file_exists( $facebook_db_installer ) ) {
        require_once $facebook_db_installer;
        if ( class_exists( 'ESF_Facebook_DB_Installer' ) ) {
            ESF_Facebook_DB_Installer::drop_tables();
        }
    }
}

if ( function_exists( 'efl_fs' ) ) {
    efl_fs()->set_basename( false, __FILE__ );
    efl_fs()->add_action( 'after_uninstall', 'efl_fs_uninstall_cleanup' );
} else {
    if ( !function_exists( 'efl_fs' ) ) {
        // Create a helper function for easy SDK access.
        function efl_fs() {
            global $efl_fs;
            if ( !isset( $efl_fs ) ) {
                // Include Freemius SDK.
                require_once __DIR__ . '/vendor/freemius/start.php';
                $efl_fs = fs_dynamic_init( array(
                    'id'               => '4142',
                    'slug'             => 'easy-facebook-likebox',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_d982f4dff842224ca5e54c84f6822',
                    'is_premium'       => false,
                    'has_addons'       => false,
                    'has_paid_plans'   => true,
                    'trial'            => array(
                        'days'               => 7,
                        'is_require_payment' => true,
                    ),
                    'has_affiliation'  => 'all',
                    'menu'             => array(
                        'support'    => false,
                        'slug'       => 'easy-social-feed',
                        'first-path' => 'admin.php?page=esf_welcome',
                    ),
                    'is_live'          => true,
                    'is_org_compliant' => true,
                ) );
            }
            return $efl_fs;
        }

        // Init Freemius.
        efl_fs();
        efl_fs()->add_action( 'after_uninstall', 'efl_fs_uninstall_cleanup' );
        // Signal that SDK was initiated.
        do_action( 'efl_fs_loaded' );
    }
    //======================================================================
    // Code for the Main structure
    //======================================================================
    // Shared autoloaders must load after Freemius init (inside this boot path only).
    require_once __DIR__ . '/admin/shared/dashboard-core/layouts/autoload.php';
    require_once __DIR__ . '/admin/shared/seo/autoload.php';
    // Simple loading overlay helper for modern modules (Twitter, YouTube, etc.) - frontend only.
    require_once __DIR__ . '/admin/shared/frontend/php/simple-loader-helper.php';
    $options = get_option( 'fta_settings', array() );
    $fb_status = ( isset( $options['plugins']['facebook']['status'] ) ? $options['plugins']['facebook']['status'] : 'activated' );
    if ( isset( $options['plugins']['facebook'] ) ) {
        $fb = $options['plugins']['facebook'];
    } else {
        $fb = array();
    }
    if ( !array_key_exists( 'status', $fb ) ) {
        $fb_status = 'activated';
    }
    if ( 'activated' === $fb_status ) {
        /*
         * Mode helper must be available at widgets_init (init priority 1).
         * Full Facebook autoload still runs later via Feed_Them_All::includes()
         * (init priority 10) — without this early require, modern mode would
         * always register the legacy widgets.
         */
        if ( !function_exists( 'esf_facebook_use_new_system' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'facebook/includes/helpers/facebook-helper-functions.php';
        }
        /**
         * Facebook feed + likebox widgets.
         * Legacy: Easy_Custom_Facebook_Feed_Widget / Easy_Facebook_Page_Plugin_Widget.
         * Modern: same id_bases (`easy_facebook_feed`, `easy_facebook_page_plugin`)
         * so existing sidebar placements keep working after migration.
         */
        function register_fblx_widget() {
            if ( function_exists( 'esf_facebook_use_new_system' ) && esf_facebook_use_new_system() ) {
                if ( !class_exists( 'ESF_Feed_Widget', false ) ) {
                    require_once plugin_dir_path( __FILE__ ) . 'includes/class-esf-feed-widget.php';
                }
                if ( !class_exists( 'ESF_Facebook_Modern_Feed_Widget', false ) ) {
                    require_once plugin_dir_path( __FILE__ ) . 'facebook/includes/class-esf-facebook-modern-feed-widget.php';
                }
                if ( class_exists( 'ESF_Facebook_Modern_Feed_Widget', false ) ) {
                    register_widget( 'ESF_Facebook_Modern_Feed_Widget' );
                }
                if ( class_exists( 'ESF_Facebook_Modern_Likebox_Widget', false ) ) {
                    register_widget( 'ESF_Facebook_Modern_Likebox_Widget' );
                }
                return;
            }
            require_once plugin_dir_path( __FILE__ ) . 'facebook/includes/easy-custom-facebook-feed-widget.php';
            require_once plugin_dir_path( __FILE__ ) . 'facebook/includes/easy-facebook-page-plugin-widget.php';
            register_widget( 'Easy_Custom_Facebook_Feed_Widget' );
            register_widget( 'Easy_Facebook_Page_Plugin_Widget' );
        }

        add_action( 'widgets_init', 'register_fblx_widget' );
    }
    $insta_status = ( isset( $options['plugins']['instagram']['status'] ) ? $options['plugins']['instagram']['status'] : 'activated' );
    if ( isset( $options['plugins']['instagram'] ) ) {
        $insta = $options['plugins']['instagram'];
    } else {
        $insta = array();
    }
    if ( !array_key_exists( 'status', $insta ) ) {
        $insta_status = 'activated';
    }
    if ( $insta_status == 'activated' ) {
        require_once plugin_dir_path( __FILE__ ) . 'instagram/includes/esf-instagram-feed-widget.php';
        /*
         * Mode helper must be available at widgets_init (init priority 1).
         * Full Instagram autoload still runs later via Feed_Them_All::includes()
         * (init priority 10) — without this early require, modern mode always
         * registered the legacy widget and fataled on private Frontend::__construct().
         */
        if ( !function_exists( 'esf_instagram_use_new_system' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'instagram/includes/helpers/instagram-helper-functions.php';
        }
        /**
         * Instagram feed widget.
         * Legacy: skins / ESF_Instagram_Frontend shortcode attrs.
         * Modern: same id_base (`esf_instagram_feed`) + feed_id so existing
         * sidebar placements keep working after migration.
         */
        function register_insta_widget() {
            if ( function_exists( 'esf_instagram_use_new_system' ) && esf_instagram_use_new_system() ) {
                if ( !class_exists( 'ESF_Feed_Widget', false ) ) {
                    require_once plugin_dir_path( __FILE__ ) . 'includes/class-esf-feed-widget.php';
                }
                if ( !class_exists( 'ESF_Instagram_Modern_Feed_Widget', false ) ) {
                    require_once plugin_dir_path( __FILE__ ) . 'instagram/includes/class-esf-instagram-modern-feed-widget.php';
                }
                if ( class_exists( 'ESF_Instagram_Modern_Feed_Widget', false ) ) {
                    register_widget( 'ESF_Instagram_Modern_Feed_Widget' );
                }
                return;
            }
            register_widget( 'ESF_Instagram_Feed_Widget' );
        }

        add_action( 'widgets_init', 'register_insta_widget' );
    }
    // YouTube Module (default to activated when not explicitly set).
    $youtube_status = ( isset( $options['plugins']['youtube']['status'] ) ? $options['plugins']['youtube']['status'] : 'activated' );
    if ( isset( $options['plugins']['youtube'] ) ) {
        $youtube = $options['plugins']['youtube'];
    } else {
        $youtube = array();
    }
    // When status is missing or empty, treat YouTube as activated by default.
    if ( !array_key_exists( 'status', $youtube ) || '' === $youtube_status ) {
        $youtube_status = 'activated';
    }
    // Shared cron / Graph helpers must load before module bootstraps that schedule token refresh jobs.
    require_once __DIR__ . '/includes/class-esf-token-cron-helpers.php';
    require_once __DIR__ . '/includes/class-esf-feed-cache-helpers.php';
    require_once __DIR__ . '/includes/contracts/interface-esf-module-cache-refresher.php';
    require_once __DIR__ . '/includes/class-esf-safe-cache-refresh.php';
    require_once __DIR__ . '/includes/class-esf-oauth-token-helpers.php';
    require_once __DIR__ . '/includes/class-esf-manual-access-token.php';
    require_once __DIR__ . '/includes/class-esf-meta-graph-error.php';
    // Decodes Base64-wrapped (WAF-safe) request bodies for every esf/* REST route in one place.
    require_once __DIR__ . '/admin/api/class-esf-api-waf.php';
    ESF_API_WAF::init();
    if ( 'activated' === $youtube_status ) {
        require_once plugin_dir_path( __FILE__ ) . 'youtube/autoload.php';
    }
    // Twitter/X Module (default to activated when not explicitly set).
    $twitter_status = ( isset( $options['plugins']['twitter']['status'] ) ? $options['plugins']['twitter']['status'] : 'activated' );
    if ( isset( $options['plugins']['twitter'] ) ) {
        $twitter = $options['plugins']['twitter'];
    } else {
        $twitter = array();
    }
    if ( !array_key_exists( 'status', $twitter ) || '' === $twitter_status ) {
        $twitter_status = 'activated';
    }
    if ( 'activated' === $twitter_status ) {
        require_once plugin_dir_path( __FILE__ ) . 'twitter/autoload.php';
    }
    // TikTok Module (default to activated when not explicitly set).
    $tiktok_status = ( isset( $options['plugins']['tiktok']['status'] ) ? $options['plugins']['tiktok']['status'] : 'activated' );
    if ( isset( $options['plugins']['tiktok'] ) ) {
        $tiktok = $options['plugins']['tiktok'];
    } else {
        $tiktok = array();
    }
    if ( !array_key_exists( 'status', $tiktok ) || '' === $tiktok_status ) {
        $tiktok_status = 'activated';
    }
    if ( 'activated' === $tiktok_status ) {
        require_once plugin_dir_path( __FILE__ ) . 'tiktok/autoload.php';
    }
    if ( !class_exists( 'Feed_Them_All' ) ) {
        class Feed_Them_All {
            public $version = '6.8.2';

            public $fta_slug = 'easy-facebook-likebox';

            public $plug_slug = 'easy-facebook-likebox';

            function __construct() {
                add_action( 'init', array($this, 'constants') );
                add_action( 'init', array($this, 'includes') );
                add_action( 'init', array($this, 'create_uploads_folder') );
                add_action( 'plugins_loaded', array($this, 'load_textdomain'), 1 );
                register_activation_hook( __FILE__, array($this, 'fta_activated') );
            }

            /*
             * constants defines all the plugin constants.
             * Returns nothing.
             * defined() func used to define constant.
             */
            public function constants() {
                if ( !defined( 'FTA_VERSION' ) ) {
                    define( 'FTA_VERSION', $this->version );
                }
                if ( !defined( 'FTA_PLUGIN_DIR' ) ) {
                    define( 'FTA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
                }
                if ( !defined( 'FTA_PLUGIN_URL' ) ) {
                    define( 'FTA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
                }
                if ( !defined( 'FTA_PLUGIN_FILE' ) ) {
                    define( 'FTA_PLUGIN_FILE', __FILE__ );
                }
            }

            /**
             * Load Easy Facebook likebox textdomain.
             *
             * @since 6.2.1
             *
             * @return void
             * @access public
             */
            public function load_textdomain() {
                load_plugin_textdomain( 'easy-facebook-likebox', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
            }

            /*
             * fta_activated will Add all the necessary data into the database on plugin install.
             * Returns nothing.
             */
            public function fta_activated() {
                // Activation runs before `init`, so constants() has not fired yet.
                $this->constants();
                $install_date = $this->fta_get_settings( 'installDate' );
                $fta_settings = array(
                    'version'     => $this->version,
                    'installDate' => date( 'Y-m-d h:i:s' ),
                    'plugins'     => $this::fta_plugins(),
                );
                if ( empty( $install_date ) ) {
                    update_option( 'fta_settings', $fta_settings );
                    if ( !function_exists( 'esf_seed_activated_module_reconnect_alerts' ) ) {
                        require_once FTA_PLUGIN_DIR . '/includes/core-functions.php';
                    }
                    if ( function_exists( 'esf_seed_activated_module_reconnect_alerts' ) ) {
                        esf_seed_activated_module_reconnect_alerts( $fta_settings );
                    }
                }
            }

            /*
             * includes will include all the necessary files.
             * Returns nothing.
             * include() func used to include any file.
             */
            public function includes() {
                include FTA_PLUGIN_DIR . '/includes/class-module-search.php';
                include FTA_PLUGIN_DIR . '/includes/core-functions.php';
                // Social Wall helpers must load before Pro bootstrap (plan gate).
                $social_wall_helpers = FTA_PLUGIN_DIR . '/includes/social-wall/helpers/social-wall-helper-functions.php';
                if ( file_exists( $social_wall_helpers ) ) {
                    require_once $social_wall_helpers;
                }
                if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
                    $moderation_file = FTA_PLUGIN_DIR . '/includes/premium/class-esf-moderation.php';
                    if ( file_exists( $moderation_file ) ) {
                        require_once $moderation_file;
                    }
                    $multifeed_file = FTA_PLUGIN_DIR . '/includes/premium/class-esf-multifeed.php';
                    if ( file_exists( $multifeed_file ) ) {
                        require_once $multifeed_file;
                    }
                    $shoppable_file = FTA_PLUGIN_DIR . '/includes/premium/class-esf-shoppable.php';
                    if ( file_exists( $shoppable_file ) ) {
                        require_once $shoppable_file;
                    }
                    $social_wall_file = FTA_PLUGIN_DIR . '/includes/premium/social-wall/class-esf-social-wall.php';
                    if ( file_exists( $social_wall_file ) ) {
                        require_once $social_wall_file;
                        if ( class_exists( 'ESF_Social_Wall' ) ) {
                            ESF_Social_Wall::init__premium_only();
                        }
                    }
                }
                require_once FTA_PLUGIN_DIR . '/includes/class-esf-feed-name.php';
                require_once FTA_PLUGIN_DIR . '/includes/class-esf-module-system.php';
                require_once FTA_PLUGIN_DIR . '/includes/class-esf-module-catalogue.php';
                require_once FTA_PLUGIN_DIR . '/includes/class-esf-admin-paths.php';
                require_once FTA_PLUGIN_DIR . '/includes/class-esf-settings.php';
                require_once FTA_PLUGIN_DIR . '/includes/class-esf-admin-menu-order.php';
                require_once FTA_PLUGIN_DIR . '/includes/class-esf-review-request.php';
                require_once FTA_PLUGIN_DIR . '/includes/class-esf-token-cron-helpers.php';
                require_once FTA_PLUGIN_DIR . '/includes/class-esf-feed-cache-helpers.php';
                require_once FTA_PLUGIN_DIR . '/includes/class-esf-oauth-token-helpers.php';
                require_once FTA_PLUGIN_DIR . '/includes/class-esf-layout-dimensions.php';
                require_once FTA_PLUGIN_DIR . '/includes/class-esf-meta-graph-error.php';
                require_once FTA_PLUGIN_DIR . '/includes/class-esf-token-reconnect-mailer.php';
                include FTA_PLUGIN_DIR . '/includes/class-esf-translation-strings.php';
                include FTA_PLUGIN_DIR . '/includes/class-esf-gdpr-integrations.php';
                include FTA_PLUGIN_DIR . '/includes/class-esf-feed-disconnected-cache.php';
                if ( class_exists( 'ESF_GDPR_Integrations' ) ) {
                    ESF_GDPR_Integrations::init();
                }
                if ( class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
                    ESF_Feed_Disconnected_Cache::init();
                }
                include FTA_PLUGIN_DIR . 'admin/class-esf-admin.php';
                $social_wall_db = FTA_PLUGIN_DIR . '/includes/social-wall/database/class-esf-social-wall-db-installer.php';
                if ( file_exists( $social_wall_db ) ) {
                    require_once $social_wall_db;
                }
                $social_wall_repo = FTA_PLUGIN_DIR . '/includes/social-wall/models/class-esf-social-wall-feed-repository.php';
                if ( file_exists( $social_wall_repo ) ) {
                    require_once $social_wall_repo;
                }
                $social_wall_admin = FTA_PLUGIN_DIR . '/includes/social-wall/class-esf-social-wall-admin.php';
                if ( file_exists( $social_wall_admin ) ) {
                    require_once $social_wall_admin;
                    if ( class_exists( 'ESF_Social_Wall_Admin' ) ) {
                        ESF_Social_Wall_Admin::get_instance();
                    }
                }
                $fta_plugins = $this->fta_plugins();
                $instagram_status = $fta_plugins['instagram']['status'];
                if ( $instagram_status == 'activated' ) {
                    include FTA_PLUGIN_DIR . 'instagram/autoload.php';
                }
                $fb_status = $fta_plugins['facebook']['status'];
                if ( $fb_status == 'activated' ) {
                    include FTA_PLUGIN_DIR . 'facebook/autoload.php';
                }
            }

            /**
             * Create uploads folder if not exists.
             *
             * @since 6.4.4
             */
            public function create_uploads_folder() {
                $upload_dir = wp_upload_dir();
                $base = $upload_dir['basedir'];
                $folders = array(
                    'esf-instagram',
                    'esf-facebook',
                    'esf-youtube',
                    'esf-twitter',
                    'esf-tiktok'
                );
                foreach ( $folders as $folder ) {
                    $path = $base . '/' . $folder;
                    if ( !file_exists( $path ) ) {
                        wp_mkdir_p( $path );
                    }
                }
            }

            /*
             * fta_plugins Holds all the FTA plugins data
             */
            public function fta_plugins() {
                $feed_them_all = new Feed_Them_All();
                $settings = $feed_them_all->fta_get_settings();
                $fb_status = ( isset( $settings['plugins']['facebook']['status'] ) ? $settings['plugins']['facebook']['status'] : 'activated' );
                $insta_status = ( isset( $settings['plugins']['instagram']['status'] ) ? $settings['plugins']['instagram']['status'] : 'activated' );
                // Default YouTube to activated when no explicit status is stored.
                $youtube_status = ( isset( $settings['plugins']['youtube']['status'] ) ? $settings['plugins']['youtube']['status'] : 'activated' );
                // Default Twitter to activated when no explicit status is stored.
                $twitter_status = ( isset( $settings['plugins']['twitter']['status'] ) ? $settings['plugins']['twitter']['status'] : 'activated' );
                // Default TikTok to activated when no explicit status is stored.
                $tiktok_status = ( isset( $settings['plugins']['tiktok']['status'] ) ? $settings['plugins']['tiktok']['status'] : 'activated' );
                if ( empty( $fb_status ) ) {
                    $fb_status = 'activated';
                }
                if ( empty( $insta_status ) ) {
                    $insta_status = 'activated';
                }
                if ( empty( $youtube_status ) ) {
                    $youtube_status = 'activated';
                }
                if ( empty( $twitter_status ) ) {
                    $twitter_status = 'activated';
                }
                if ( empty( $tiktok_status ) ) {
                    $tiktok_status = 'activated';
                }
                /*
                 * Making an array of all plugins
                 */
                $fta_plugins = array(
                    'facebook'  => array(
                        'name'          => __( 'Custom Facebook Feed - Page Plugin (Likebox)', 'easy-facebook-likebox' ),
                        'slug'          => 'easy-facebook-likebox',
                        'activate_slug' => 'facebook',
                        'description'   => __( 'Display customizable Facebook posts, photos, videos, events, albums, and the Page Plugin using shortcodes, widgets, and Gutenberg blocks.', 'easy-facebook-likebox' ),
                        'img_name'      => 'fb_cover.png',
                        'status'        => $fb_status,
                    ),
                    'instagram' => array(
                        'name'          => __( 'Custom Instagram Feed', 'easy-facebook-likebox' ),
                        'slug'          => 'mif',
                        'activate_slug' => 'instagram',
                        'description'   => __( 'Display Instagram photos and videos with grid, row, carousel, and masonry layouts, plus hashtag feeds, stories, and popup gallery.', 'easy-facebook-likebox' ),
                        'img_name'      => 'insta_cover.png',
                        'status'        => $insta_status,
                    ),
                    'youtube'   => array(
                        'name'          => __( 'YouTube Feed', 'easy-facebook-likebox' ),
                        'slug'          => 'esf-youtube',
                        'activate_slug' => 'youtube',
                        'description'   => __( 'Display YouTube videos from your channel, playlists, or search using secure OAuth with customizable layouts and caching.', 'easy-facebook-likebox' ),
                        'img_name'      => 'youtube_cover.png',
                        'status'        => $youtube_status,
                    ),
                    'twitter'   => array(
                        'name'          => __( 'X / Twitter Feed', 'easy-facebook-likebox' ),
                        'slug'          => 'esf-twitter',
                        'activate_slug' => 'twitter',
                        'description'   => __( 'Display your X (Twitter) timeline using secure OAuth connection with DB-backed caching. Connect your own account (free) or display any public account feed (Pro).', 'easy-facebook-likebox' ),
                        'img_name'      => 'twitter_cover.png',
                        'status'        => $twitter_status,
                    ),
                    'tiktok'    => array(
                        'name'          => __( 'TikTok Feed', 'easy-facebook-likebox' ),
                        'slug'          => 'esf-tiktok',
                        'activate_slug' => 'tiktok',
                        'description'   => __( 'Display TikTok videos from your account with grid and row layouts, popup lightbox, and DB-backed caching via secure Login Kit OAuth.', 'easy-facebook-likebox' ),
                        'img_name'      => 'tiktok_cover.png',
                        'status'        => $tiktok_status,
                    ),
                );
                return $fta_plugins;
            }

            /*
             * It will get the saved settings.
             */
            public function fta_get_settings( $key = null ) {
                $fta_settings = ( class_exists( 'ESF_Settings' ) ? ESF_Settings::get_legacy_compatible_array() : get_option( 'fta_settings', false ) );
                if ( $key && is_array( $fta_settings ) && isset( $fta_settings[$key] ) ) {
                    $fta_settings = $fta_settings[$key];
                }
                return $fta_settings;
            }

            /*
             * fta_settings_link Will add the My Instagram settings page link in the plugin area.
             */
            public function fta_settings_link( $links ) {
                $fta_link = array('<a href="' . esc_url( ESF_Admin_Paths::hub_admin_url() ) . '">' . __( 'Settings', 'easy-facebook-likebox' ) . '</a>');
                return array_merge( $fta_link, $links );
            }

            /**
             * Return module status
             *
             * @since 6.2.8
             *
             * @param $slug
             *
             * @return mixed|string
             */
            public function module_status( $slug ) {
                $settings = $this->fta_get_settings();
                $status = $settings['plugins'][$slug]['status'];
                if ( empty( $status ) ) {
                    $status = 'activated';
                }
                return $status;
            }

        }

        new Feed_Them_All();
    }
}