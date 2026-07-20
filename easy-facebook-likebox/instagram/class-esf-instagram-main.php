<?php

/**
 * Instagram Module Main Class
 *
 * Boots modern Instagram architecture (tables + REST + repositories).
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.8.0
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Instagram_Main
 *
 * @since 6.8.0
 */
class ESF_Instagram_Main {
    use ESF_Instagram_Singleton;
    /**
     * DB schema version.
     *
     * @var string
     */
    const DB_SCHEMA_VERSION = '1.1';

    /**
     * Constructor.
     */
    private function __construct() {
        $this->init_hooks();
        /*
         * Load immediately: parent plugin calls includes() on init, which loads this autoload
         * and constructs Main during the same init pass. Hooking load_dependencies to init would
         * register too late for that request, while admin_init (OAuth listener) still runs afterward.
         */
        $this->load_dependencies();
        $this->register_warm_cron_hook();
        $this->maybe_initialize_components();
        $this->register_layouts();
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    private function init_hooks() {
        add_filter( 'cron_schedules', 'esf_cron_schedules_add_thirty_minutes' );
        add_filter( 'esf_instagram_layout_render_settings', 'esf_instagram_normalize_feed_settings' );
        /*
         * Parent `Feed_Them_All::includes()` loads this module on `init` (priority 10). Token manager
         * must run after that so `wp_schedule_event` runs and WP Crontrol can list `esf_instagram_auto_refresh_tokens`.
         */
        add_action( 'init', array($this, 'maybe_init_token_manager'), 11 );
        add_action( 'rest_api_init', array($this, 'register_rest_routes') );
        add_action( 'admin_init', array($this, 'maybe_handle_oauth_callback') );
    }

    /**
     * Start WP-Cron handler for Instagram token refresh.
     *
     * @since 6.8.0
     * @return void
     */
    public function maybe_init_token_manager() {
        if ( class_exists( 'ESF_Instagram_Token_Manager' ) ) {
            ESF_Instagram_Token_Manager::get_instance()->init();
        }
    }

    /**
     * Load modern Instagram class files.
     *
     * @return void
     */
    public function load_dependencies() {
        require_once ESF_INSTAGRAM_DIR . 'includes/traits/trait-esf-instagram-singleton.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/database/class-esf-instagram-db-installer.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/models/class-esf-instagram-account-repository.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/models/class-esf-instagram-feed-repository.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/api/class-esf-instagram-api-oauth.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/class-esf-instagram-token-refresh-client.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/class-esf-instagram-token-manager.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/class-esf-instagram-token-notifications.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/class-esf-instagram-migrator.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/class-esf-instagram-facebook-graph.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/class-esf-instagram-facebook-oauth-service.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/class-esf-instagram-auth-callback-listener.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/api/class-esf-instagram-api-accounts.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/api/class-esf-instagram-api-feeds.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/api/class-esf-instagram-api-migrate.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/api/class-esf-instagram-api-settings.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/api/class-esf-instagram-api-preview.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/api/class-esf-instagram-api-warm-media.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/api/class-esf-instagram-api-media.php';
        $stories_file = ESF_INSTAGRAM_DIR . 'includes/api/class-esf-instagram-api-stories.php';
        $hashtag_file = ESF_INSTAGRAM_DIR . 'includes/api/class-esf-instagram-api-hashtag.php';
        $moderate_file = ESF_INSTAGRAM_DIR . 'includes/api/class-esf-instagram-api-moderate.php';
        $shoppable_file = ESF_INSTAGRAM_DIR . 'includes/api/class-esf-instagram-api-shoppable.php';
        $load_more_file = ESF_INSTAGRAM_DIR . 'includes/api/class-esf-instagram-api-load-more.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/api/class-esf-instagram-api-comments.php';
        $post_comments_file = ESF_INSTAGRAM_DIR . 'includes/api/class-esf-instagram-api-post-comments.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/class-esf-instagram-cache.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/class-esf-instagram-local-media.php';
        require_once ESF_INSTAGRAM_DIR . 'includes/class-esf-instagram-renderer.php';
        require_once ESF_INSTAGRAM_DIR . 'frontend/class-esf-instagram-frontend.php';
        if ( is_admin() ) {
            require_once ESF_INSTAGRAM_DIR . 'admin/classes/class-esf-instagram-admin.php';
        }
    }

    /**
     * Create required tables if missing.
     *
     * @return void
     */
    public function maybe_initialize_components() {
        $installed = (string) get_option( 'esf_instagram_db_schema_version', '0' );
        if ( !ESF_Instagram_DB_Installer::tables_exist() || self::DB_SCHEMA_VERSION !== $installed ) {
            ESF_Instagram_DB_Installer::create_tables();
            update_option( 'esf_instagram_db_schema_version', self::DB_SCHEMA_VERSION );
        }
        if ( is_admin() ) {
            ESF_Instagram_Admin::get_instance();
        }
        ESF_Instagram_Frontend::get_instance();
    }

    /**
     * Register WP-Cron handler for batched local media warm.
     *
     * @since 6.9.0
     * @return void
     */
    private function register_warm_cron_hook() {
        if ( class_exists( 'ESF_Instagram_Local_Media' ) ) {
            add_action(
                ESF_Instagram_Local_Media::WARM_CRON_HOOK,
                array('ESF_Instagram_Local_Media', 'run_warm_cron'),
                10,
                2
            );
        }
    }

    /**
     * Register modern Instagram REST routes.
     *
     * @return void
     */
    public function register_rest_routes() {
        ESF_Instagram_API_Accounts::register_routes();
        ESF_Instagram_API_Feeds::register_routes();
        ESF_Instagram_API_Migrate::register_routes();
        ESF_Instagram_API_OAuth::register_routes();
        ESF_Instagram_API_Settings::register_routes();
        ESF_Instagram_API_Preview::register_routes();
        ESF_Instagram_API_Warm_Media::register_routes();
    }

    /**
     * Whether the site has an active Instagram Pro plan.
     *
     * @since 6.9.0
     * @return bool
     */
    private function has_instagram_plan() {
        return function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan();
    }

    /**
     * Register the Instagram layouts in the shared LayoutRegistry.
     *
     * Free layouts are registered here. Pro layouts hook
     * `esf_instagram_register_layouts` to inject themselves only when the
     * premium build is loaded.
     *
     * @return void
     */
    public function register_layouts() {
        $registry = \EasySocialFeed\Layouts\LayoutRegistry::for( 'instagram' );
        $base_css_url = ESF_INSTAGRAM_URL . 'frontend/assets/css/esf-ig-feed.css';
        $grid_css_url = ESF_INSTAGRAM_URL . 'frontend/assets/css/layouts/grid.css';
        $row_css_url = ESF_INSTAGRAM_URL . 'frontend/assets/css/layouts/row.css';
        $row_js_path = ESF_INSTAGRAM_DIR . 'frontend/assets/js/esf-ig-row-wave.js';
        $row_js_url = ESF_INSTAGRAM_URL . 'frontend/assets/js/esf-ig-row-wave.js';
        $version = ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );
        $base_css = array(
            'handle' => 'esf-instagram-feed',
            'src'    => $base_css_url,
            'deps'   => array(),
            'ver'    => $version,
        );
        $registry->register( new \EasySocialFeed\Layouts\ValueObjects\LayoutDefinition(array(
            'slug'             => 'grid',
            'label'            => __( 'Grid', 'easy-facebook-likebox' ),
            'description'      => __( 'Square tile grid — matches the Instagram profile look.', 'easy-facebook-likebox' ),
            'is_pro'           => false,
            'supports'         => array('header', 'load_more', 'custom_css'),
            'class_name'       => \EasySocialFeed\Instagram\Layouts\Layouts\Grid::class,
            'default_settings' => array(
                'layout' => array(
                    'type' => 'grid',
                    'grid' => array(
                        'columns'        => 3,
                        'columns_tablet' => 2,
                        'columns_mobile' => 1,
                        'gap'            => 8,
                    ),
                ),
            ),
            'assets'           => array(
                'base_css'   => $base_css,
                'layout_css' => array(
                    'handle' => 'esf-instagram-feed-grid',
                    'src'    => $grid_css_url,
                    'deps'   => array('esf-instagram-feed'),
                    'ver'    => $version,
                ),
            ),
        )) );
        $registry->register( new \EasySocialFeed\Layouts\ValueObjects\LayoutDefinition(array(
            'slug'             => 'row',
            'label'            => __( 'Row', 'easy-facebook-likebox' ),
            'description'      => __( 'A sleek, single-row layout — great for headers, footers or sections.', 'easy-facebook-likebox' ),
            'is_pro'           => false,
            'supports'         => array('header', 'load_more', 'custom_css'),
            'class_name'       => \EasySocialFeed\Instagram\Layouts\Layouts\Row::class,
            'default_settings' => array(
                'layout' => array(
                    'type' => 'row',
                    'row'  => array(
                        'columns'        => 6,
                        'columns_tablet' => 3,
                        'columns_mobile' => 2,
                        'gap'            => 0,
                    ),
                ),
            ),
            'assets'           => array(
                'base_css'   => $base_css,
                'layout_css' => array(
                    'handle' => 'esf-instagram-feed-row',
                    'src'    => $row_css_url,
                    'deps'   => array('esf-instagram-feed'),
                    'ver'    => $version,
                ),
                'layout_js'  => array(
                    'handle'    => 'esf-instagram-feed-row-wave',
                    'src'       => $row_js_url,
                    'deps'      => array(),
                    'ver'       => ( file_exists( $row_js_path ) ? (string) filemtime( $row_js_path ) : $version ),
                    'in_footer' => true,
                ),
            ),
        )) );
        // Pro layouts: register most-used options first (after free Grid + Row).
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() && method_exists( $this, 'register_full_width_layout__premium_only' ) ) {
            $this->register_full_width_layout__premium_only( $registry, $base_css, $version );
        }
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() && method_exists( $this, 'register_masonry_layout__premium_only' ) ) {
            $this->register_masonry_layout__premium_only( $registry, $base_css, $version );
        }
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() && method_exists( $this, 'register_carousel_layout__premium_only' ) ) {
            $this->register_carousel_layout__premium_only( $registry, $base_css, $version );
        }
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() && method_exists( $this, 'register_half_width_layout__premium_only' ) ) {
            $this->register_half_width_layout__premium_only( $registry, $base_css, $version );
        }
        /**
         * Fires after the free Instagram layouts have been registered. Pro
         * builds listen on this hook to inject premium layouts via
         * `LayoutRegistry::for('instagram')->register( ... )`.
         *
         * @since 6.9.0
         *
         * @param \EasySocialFeed\Layouts\LayoutRegistry $registry Per-module registry.
         */
        do_action( 'esf_instagram_register_layouts', $registry );
    }

    /**
     * Delegate OAuth callback handling.
     *
     * @return void
     */
    public function maybe_handle_oauth_callback() {
        ESF_Instagram_Auth_Callback_Listener::get_instance()->handle();
    }

}
