<?php

/**
 * Facebook Module Main Class
 *
 * Orchestrator for the modern Facebook module: DB tables, REST routes, cron,
 * layout registry, frontend dispatcher, and legacy migration/compat.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Facebook_Main
 *
 * @since 6.7.9
 */
class ESF_Facebook_Main {
    use ESF_Facebook_Singleton;
    /**
     * Current DB schema version option value.
     *
     * @since 6.7.9
     * @var string
     */
    const DB_SCHEMA_VERSION = '1.6';

    /**
     * Cron hook for feed posts cache refresh (pre-warm before TTL expiry).
     *
     * @since 6.7.9
     * @var string
     */
    const CRON_CACHE_REFRESH_HOOK = 'esf_facebook_refresh_feed_cache';

    /**
     * Constructor.
     *
     * @since 6.7.9
     */
    private function __construct() {
        $this->init_hooks();
        /*
         * Load immediately: parent plugin calls includes() on init, which loads this
         * autoload and constructs Main during the same init pass. Hooking
         * load_dependencies to init would register too late for that request, so
         * admin_menu (ESF_Facebook_Admin) would never register and migration
         * redirects to ?page=esf-facebook would 403.
         */
        $this->load_dependencies();
        $this->maybe_initialize_components();
        $this->register_layouts();
        $this->maybe_init_frontend();
        $this->register_local_media_cron();
        $this->register_live_status_cron();
    }

    /**
     * Register WordPress hooks.
     *
     * @since 6.7.9
     * @return void
     */
    private function init_hooks() {
        add_filter( 'cron_schedules', array($this, 'add_cron_schedules') );
        add_action( self::CRON_CACHE_REFRESH_HOOK, array($this, 'run_feed_cache_refresh') );
        /*
         * Parent Feed_Them_All::includes() loads this module on init (priority 10).
         * Token manager / migration follow-ups must run after that pass completes.
         */
        add_action( 'init', array($this, 'maybe_init_token_manager'), 11 );
        add_action( 'init', array($this, 'maybe_migrate_legacy_widgets'), 20 );
        add_action( 'init', array($this, 'maybe_build_legacy_feed_map'), 21 );
        add_action( 'init', array($this, 'maybe_start_legacy_shortcode_rewrite'), 22 );
        add_action( 'rest_api_init', array($this, 'register_rest_routes') );
        add_action( 'admin_init', array($this, 'maybe_handle_oauth_callback') );
        add_action( 'esf_facebook_rewrite_legacy_shortcodes', array($this, 'run_legacy_shortcode_rewrite') );
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan() ) {
                if ( method_exists( $this, 'filter_layout_can_load_more__premium_only' ) ) {
                    add_filter(
                        'esf_facebook_layout_can_load_more',
                        array($this, 'filter_layout_can_load_more__premium_only'),
                        10,
                        4
                    );
                }
            }
        }
    }

    /**
     * Register local media warm-cron callback.
     *
     * @since 6.7.9
     * @return void
     */
    private function register_local_media_cron() {
        if ( class_exists( 'ESF_Facebook_Local_Media' ) ) {
            add_action(
                ESF_Facebook_Local_Media::WARM_CRON_HOOK,
                array('ESF_Facebook_Local_Media', 'run_warm_cron'),
                10,
                2
            );
        }
    }

    /**
     * Register chained live-status refresh cron callback.
     *
     * @since 6.7.9
     * @return void
     */
    private function register_live_status_cron() {
        if ( class_exists( 'ESF_Facebook_Renderer' ) ) {
            add_action(
                ESF_Facebook_Renderer::LIVE_STATUS_FOLLOW_UP_HOOK,
                array('ESF_Facebook_Renderer', 'run_live_status_follow_up'),
                10,
                1
            );
        }
    }

    /**
     * Load module PHP dependencies.
     *
     * @since 6.7.9
     * @return void
     */
    public function load_dependencies() {
        $dir = ( defined( 'EFBL_PLUGIN_DIR' ) ? EFBL_PLUGIN_DIR : __DIR__ . '/' );
        require_once $dir . 'includes/traits/trait-esf-facebook-singleton.php';
        require_once $dir . 'includes/helpers/facebook-helper-functions.php';
        require_once $dir . 'includes/database/class-esf-facebook-db-installer.php';
        require_once $dir . 'includes/class-esf-facebook-cache.php';
        require_once $dir . 'includes/cache/class-esf-facebook-cache-refresher.php';
        require_once $dir . 'includes/models/class-esf-facebook-account-repository.php';
        require_once $dir . 'includes/models/class-esf-facebook-page-repository.php';
        require_once $dir . 'includes/models/class-esf-facebook-feed-repository.php';
        require_once $dir . 'includes/class-esf-facebook-graph-client.php';
        require_once $dir . 'includes/class-esf-facebook-embed-enricher.php';
        require_once $dir . 'includes/class-esf-facebook-feed-query.php';
        require_once $dir . 'includes/class-esf-facebook-oauth-service.php';
        require_once $dir . 'includes/class-esf-facebook-auth-callback-listener.php';
        require_once $dir . 'includes/class-esf-facebook-token-refresh-client.php';
        require_once $dir . 'includes/class-esf-facebook-token-manager.php';
        require_once $dir . 'includes/class-esf-facebook-token-notifications.php';
        require_once $dir . 'includes/class-esf-facebook-renderer.php';
        require_once $dir . 'includes/class-esf-facebook-likebox-renderer.php';
        require_once $dir . 'includes/class-esf-facebook-local-media.php';
        require_once $dir . 'includes/api/class-esf-facebook-api-oauth.php';
        require_once $dir . 'includes/api/class-esf-facebook-api-accounts.php';
        require_once $dir . 'includes/api/class-esf-facebook-api-pages.php';
        require_once $dir . 'includes/api/class-esf-facebook-api-feeds.php';
        require_once $dir . 'includes/api/class-esf-facebook-api-settings.php';
        require_once $dir . 'includes/api/class-esf-facebook-api-migrate.php';
        $load_more_file = $dir . 'includes/api/premium/class-esf-facebook-api-load-more.php';
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan() && file_exists( $load_more_file ) ) {
                require_once $load_more_file;
            }
        }
        require_once $dir . 'includes/api/class-esf-facebook-api-preview.php';
        require_once $dir . 'includes/api/class-esf-facebook-api-likebox.php';
        require_once $dir . 'includes/api/class-esf-facebook-api-comments.php';
        require_once $dir . 'includes/api/trait-esf-facebook-popup-api-support.php';
        require_once $dir . 'includes/api/class-esf-facebook-api-post-comments.php';
        require_once $dir . 'includes/api/class-esf-facebook-api-comment-replies.php';
        require_once $dir . 'includes/api/class-esf-facebook-api-album-photos.php';
        $moderate_file = $dir . 'includes/api/premium/class-esf-facebook-api-moderate.php';
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan() && file_exists( $moderate_file ) ) {
                require_once $moderate_file;
            }
        }
        require_once $dir . 'includes/class-esf-facebook-migrator.php';
        require_once $dir . 'includes/class-esf-facebook-legacy-feed-map.php';
        require_once $dir . 'includes/class-esf-facebook-widget-migrator.php';
        require_once $dir . 'includes/class-esf-facebook-legacy-shortcode-rewriter.php';
        if ( !class_exists( 'ESF_Feed_Widget', false ) ) {
            $shared_widget = dirname( $dir ) . '/includes/class-esf-feed-widget.php';
            if ( defined( 'FTA_PLUGIN_DIR' ) ) {
                $shared_widget = FTA_PLUGIN_DIR . 'includes/class-esf-feed-widget.php';
            }
            if ( file_exists( $shared_widget ) ) {
                require_once $shared_widget;
            }
        }
        require_once $dir . 'includes/class-esf-facebook-modern-feed-widget.php';
        require_once $dir . 'frontend/class-esf-facebook-frontend.php';
        if ( is_admin() ) {
            require_once $dir . 'admin/classes/class-esf-facebook-admin.php';
        }
        if ( class_exists( 'ESF_Safe_Cache_Refresh' ) && class_exists( 'ESF_Facebook_Cache_Refresher' ) ) {
            ESF_Safe_Cache_Refresh::register( new ESF_Facebook_Cache_Refresher() );
        }
    }

    /**
     * Initialise module components after dependencies are loaded.
     *
     * @since 6.7.9
     * @return void
     */
    public function maybe_initialize_components() {
        if ( function_exists( 'esf_is_facebook_active' ) && !esf_is_facebook_active() ) {
            return;
        }
        $this->maybe_migrate_db_schema();
        $this->maybe_schedule_feed_cache_refresh();
        if ( is_admin() && class_exists( 'ESF_Facebook_Admin' ) ) {
            ESF_Facebook_Admin::get_instance();
        }
    }

    /**
     * Ensure DB tables exist / schema version is current.
     *
     * @since 6.7.9
     * @return void
     */
    public function maybe_migrate_db_schema() {
        if ( !class_exists( 'ESF_Facebook_DB_Installer' ) ) {
            return;
        }
        $installed = get_option( ESF_Facebook_DB_Installer::SCHEMA_OPTION, '' );
        if ( self::DB_SCHEMA_VERSION === $installed && ESF_Facebook_DB_Installer::tables_exist() ) {
            return;
        }
        ESF_Facebook_DB_Installer::create_tables();
    }

    /**
     * Add custom WP-Cron schedules for feed cache refresh.
     *
     * @since 6.7.9
     *
     * @param array<string,array<string,mixed>> $schedules Existing schedules.
     * @return array<string,array<string,mixed>>
     */
    public function add_cron_schedules( $schedules ) {
        if ( function_exists( 'esf_cron_schedules_add_thirty_minutes' ) ) {
            $schedules = esf_cron_schedules_add_thirty_minutes( $schedules );
        }
        if ( function_exists( 'esf_feed_cache_add_cron_schedules' ) ) {
            return esf_feed_cache_add_cron_schedules( $schedules, 'facebook' );
        }
        return ( is_array( $schedules ) ? $schedules : array() );
    }

    /**
     * Schedule feed cache refresh cron based on Settings → cache duration.
     *
     * @since 6.7.9
     * @return void
     */
    private function maybe_schedule_feed_cache_refresh() {
        if ( function_exists( 'esf_is_facebook_active' ) && !esf_is_facebook_active() ) {
            return;
        }
        if ( !function_exists( 'esf_feed_cache_maybe_schedule_refresh' ) ) {
            return;
        }
        $cache_duration = ( function_exists( 'esf_get_facebook_settings' ) ? (int) esf_get_facebook_settings( 'cache_duration' ) : 43200 );
        $schedule_slug = $this->get_cache_refresh_schedule_slug( $cache_duration );
        esf_feed_cache_maybe_schedule_refresh( self::CRON_CACHE_REFRESH_HOOK, $schedule_slug );
    }

    /**
     * Reschedule feed cache refresh after Settings cache duration changes.
     *
     * @since 6.7.9
     * @return void
     */
    public function reschedule_feed_cache_refresh() {
        $cache_duration = ( function_exists( 'esf_get_facebook_settings' ) ? (int) esf_get_facebook_settings( 'cache_duration' ) : 43200 );
        $schedule_slug = $this->get_cache_refresh_schedule_slug( $cache_duration );
        if ( function_exists( 'esf_feed_cache_reschedule_refresh' ) ) {
            esf_feed_cache_reschedule_refresh( self::CRON_CACHE_REFRESH_HOOK, $schedule_slug );
            return;
        }
        $timestamp = wp_next_scheduled( self::CRON_CACHE_REFRESH_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_CACHE_REFRESH_HOOK );
        }
        $this->maybe_schedule_feed_cache_refresh();
    }

    /**
     * Map cache duration (seconds) to a Facebook cron schedule slug.
     *
     * @since 6.7.9
     *
     * @param int $cache_duration_secs Cache duration in seconds.
     * @return string
     */
    private function get_cache_refresh_schedule_slug( $cache_duration_secs ) {
        if ( function_exists( 'esf_feed_cache_cron_schedule_slug' ) ) {
            return esf_feed_cache_cron_schedule_slug( $cache_duration_secs, 'facebook' );
        }
        return 'esf_facebook_12h';
    }

    /**
     * Cron: re-warm expired Facebook posts caches for active feeds.
     *
     * Uses {@see ESF_Facebook_Renderer::get_posts_for_feed_by_id()} with force_refresh so
     * stale rows do not block Graph fetches (visitor requests still use stale-while-revalidate).
     *
     * @since 6.7.9
     * @return void
     */
    public function run_feed_cache_refresh() {
        if ( !class_exists( 'ESF_Facebook_Feed_Repository' ) || !class_exists( 'ESF_Facebook_Cache' ) ) {
            return;
        }
        if ( function_exists( 'esf_is_facebook_active' ) && !esf_is_facebook_active() ) {
            return;
        }
        $feeds = ESF_Facebook_Feed_Repository::get_instance()->get_all( array(
            'status' => 'active',
        ) );
        if ( empty( $feeds ) || !is_array( $feeds ) ) {
            return;
        }
        if ( !class_exists( 'ESF_Facebook_Renderer' ) ) {
            return;
        }
        $renderer = new ESF_Facebook_Renderer();
        $page_repo = ( class_exists( 'ESF_Facebook_Page_Repository' ) ? ESF_Facebook_Page_Repository::get_instance() : null );
        $seen_keys = array();
        foreach ( $feeds as $feed ) {
            $feed_id = ( isset( $feed->id ) ? (int) $feed->id : 0 );
            if ( $feed_id <= 0 ) {
                continue;
            }
            $content_type = ( isset( $feed->content_type ) ? sanitize_key( (string) $feed->content_type ) : 'feed' );
            if ( 'likebox' === $content_type ) {
                continue;
            }
            $feed_status = ( isset( $feed->status ) ? sanitize_key( (string) $feed->status ) : 'active' );
            if ( 'active' !== $feed_status ) {
                continue;
            }
            if ( ESF_Facebook_Cache::feed_has_pinned_cache_rows( $feed_id ) ) {
                continue;
            }
            $page_row_id = ( isset( $feed->page_id ) ? (int) $feed->page_id : 0 );
            $page_row = ( $page_repo && $page_row_id > 0 ? $page_repo->get_by_id( $page_row_id ) : null );
            if ( class_exists( 'ESF_Feed_Disconnected_Cache' ) && ESF_Feed_Disconnected_Cache::facebook_source_is_disconnected( $feed, $page_row ) ) {
                continue;
            }
            $settings = ( isset( $feed->settings ) && is_array( $feed->settings ) ? $feed->settings : (( function_exists( 'ESF_Facebook_Feed_Repository' ) ? ESF_Facebook_Feed_Repository::get_default_settings() : array() )) );
            $feed_obj = (object) array(
                'id'           => $feed_id,
                'page_id'      => $page_row_id,
                'feed_type'    => ( isset( $feed->feed_type ) ? (string) $feed->feed_type : 'timeline' ),
                'settings'     => $settings,
                'content_type' => 'feed',
                'status'       => $feed_status,
            );
            if ( class_exists( 'ESF_Facebook_Feed_Query' ) ) {
                $feed_obj = ESF_Facebook_Feed_Query::from_feed( $feed_obj )->apply_to_feed_object( $feed_obj );
                $feed_obj->page_id = $page_row_id;
            }
            $keys = ESF_Facebook_Renderer::resolve_posts_cache_keys( $feed_obj );
            $cache_key = ( isset( $keys['cache_key'] ) ? (string) $keys['cache_key'] : '' );
            if ( '' === $cache_key || isset( $seen_keys[$cache_key] ) ) {
                continue;
            }
            $expires_at = ESF_Facebook_Cache::get_expires_at( $cache_key );
            if ( function_exists( 'esf_feed_cache_is_due_for_refresh' ) && !esf_feed_cache_is_due_for_refresh( $expires_at ) ) {
                $seen_keys[$cache_key] = true;
                continue;
            }
            $renderer->get_posts_for_feed_by_id( $feed_id, array(
                'force_refresh' => true,
            ) );
            $seen_keys[$cache_key] = true;
        }
    }

    /**
     * Clear scheduled cron jobs when the module or plugin is deactivated.
     *
     * @since 6.7.9
     * @return void
     */
    public static function on_deactivation() {
        $timestamp = wp_next_scheduled( self::CRON_CACHE_REFRESH_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_CACHE_REFRESH_HOOK );
        }
        if ( class_exists( 'ESF_Facebook_Token_Manager' ) && function_exists( 'esf_cron_unschedule_recurring_job' ) ) {
            esf_cron_unschedule_recurring_job( ESF_Facebook_Token_Manager::CRON_HOOK );
        }
    }

    /**
     * Attach token manager cron.
     *
     * @since 6.7.9
     * @return void
     */
    public function maybe_init_token_manager() {
        if ( function_exists( 'esf_is_facebook_active' ) && !esf_is_facebook_active() ) {
            return;
        }
        if ( class_exists( 'ESF_Facebook_Token_Manager' ) ) {
            ESF_Facebook_Token_Manager::get_instance()->init();
        }
    }

    /**
     * Boot the frontend shortcode + conditional asset loader.
     *
     * @since 6.7.9
     * @return void
     */
    public function maybe_init_frontend() {
        if ( function_exists( 'esf_is_facebook_active' ) && !esf_is_facebook_active() ) {
            return;
        }
        if ( class_exists( 'ESF_Facebook_Frontend' ) ) {
            ESF_Facebook_Frontend::get_instance();
        }
    }

    /**
     * Register Facebook layouts in the shared LayoutRegistry.
     *
     * @since 6.7.9
     * @return void
     */
    public function register_layouts() {
        if ( function_exists( 'esf_is_facebook_active' ) && !esf_is_facebook_active() ) {
            return;
        }
        if ( !class_exists( '\\EasySocialFeed\\Layouts\\LayoutRegistry' ) ) {
            return;
        }
        $registry = \EasySocialFeed\Layouts\LayoutRegistry::for( 'facebook' );
        $base_url = ( defined( 'ESF_FACEBOOK_URL' ) ? ESF_FACEBOOK_URL : (( defined( 'EFBL_PLUGIN_URL' ) ? EFBL_PLUGIN_URL : '' )) );
        $version = ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );
        $base_dir = ( defined( 'ESF_FACEBOOK_DIR' ) ? ESF_FACEBOOK_DIR : (( defined( 'EFBL_PLUGIN_DIR' ) ? EFBL_PLUGIN_DIR : __DIR__ . '/' )) );
        $base_css_path = $base_dir . 'frontend/assets/css/esf-facebook-feed.css';
        $base_css_ver = ( file_exists( $base_css_path ) ? (string) filemtime( $base_css_path ) : $version );
        $base_css = array(
            'handle' => 'esf-facebook-feed',
            'src'    => $base_url . 'frontend/assets/css/esf-facebook-feed.css',
            'deps'   => array(),
            'ver'    => $base_css_ver,
        );
        /**
         * Map registry slug → CSS file stem under frontend/assets/css/layouts/.
         *
         * @var array<string,string>
         */
        $layout_css_files = array(
            'half_width' => 'half-width',
            'full_width' => 'full-width',
            'thumbnail'  => 'thumbnail',
            'row'        => 'row',
            'grid'       => 'grid',
            'masonry'    => 'masonry',
            'carousel'   => 'carousel',
        );
        $locate_layout_css = static function ( $stem ) use($base_dir, $base_url) {
            $fallback_path = $base_dir . 'frontend/assets/css/layouts/' . $stem . '.css';
            $fallback_src = $base_url . 'frontend/assets/css/layouts/' . $stem . '.css';
            if ( function_exists( 'esf_locate_premium_only_asset' ) ) {
                $located = esf_locate_premium_only_asset(
                    $base_dir . 'frontend/assets/css/layouts',
                    $base_url . 'frontend/assets/css/layouts',
                    $stem,
                    'css'
                );
                if ( '' !== $located['path'] ) {
                    return $located;
                }
            }
            return array(
                'path' => $fallback_path,
                'url'  => $fallback_src,
            );
        };
        $layout_js = array(
            'handle'    => 'esf-facebook-caption',
            'src'       => $base_url . 'frontend/assets/js/esf-facebook-caption.js',
            'deps'      => array(),
            'ver'       => $version,
            'in_footer' => true,
        );
        $free = array(
            'half_width' => array(
                'label'      => __( 'Half Width', 'easy-facebook-likebox' ),
                'class_name' => \EasySocialFeed\Facebook\Layouts\Layouts\HalfWidth::class,
            ),
            'full_width' => array(
                'label'      => __( 'Full Width', 'easy-facebook-likebox' ),
                'class_name' => \EasySocialFeed\Facebook\Layouts\Layouts\FullWidth::class,
            ),
            'thumbnail'  => array(
                'label'      => __( 'Thumbnail', 'easy-facebook-likebox' ),
                'class_name' => \EasySocialFeed\Facebook\Layouts\Layouts\Thumbnail::class,
            ),
            'row'        => array(
                'label'      => __( 'Row', 'easy-facebook-likebox' ),
                'class_name' => \EasySocialFeed\Facebook\Layouts\Layouts\Row::class,
            ),
        );
        foreach ( $free as $slug => $meta ) {
            if ( !class_exists( $meta['class_name'] ) ) {
                continue;
            }
            $css_stem = ( isset( $layout_css_files[$slug] ) ? $layout_css_files[$slug] : $slug );
            $layout_css = $locate_layout_css( $css_stem );
            $layout_css_path = $layout_css['path'];
            $layout_css_ver = ( file_exists( $layout_css_path ) ? (string) filemtime( $layout_css_path ) : $version );
            $registry->register( new \EasySocialFeed\Layouts\ValueObjects\LayoutDefinition(array(
                'slug'             => $slug,
                'label'            => $meta['label'],
                'description'      => '',
                'is_pro'           => false,
                'supports'         => array('header', 'load_more', 'custom_css'),
                'class_name'       => $meta['class_name'],
                'default_settings' => array(
                    'layout' => array(
                        'type' => $slug,
                    ),
                ),
                'assets'           => array(
                    'base_css'   => $base_css,
                    'layout_css' => array(
                        'handle' => 'esf-facebook-layout-' . $css_stem,
                        'src'    => $layout_css['url'],
                        'deps'   => array('esf-facebook-feed'),
                        'ver'    => $layout_css_ver,
                    ),
                    'layout_js'  => $layout_js,
                ),
            )) );
        }
        /*
         * Note: legacy shortcode/widget/block attributes may pass the short
         * `half` / `full` layout slugs. These are NOT registered as separate
         * LayoutRegistry entries (that duplicated "Half Width" / "Full Width"
         * tiles in the dashboard's Layout picker). Every call site normalizes
         * via `esf_facebook_normalize_layout_type()` (half → half_width,
         * full → full_width) before ever touching the registry. Legacy
         * `thumbnail` keeps the modern Thumbnail Free layout (canonical slug).
         */
        $pro = array(
            'grid'     => \EasySocialFeed\Facebook\Layouts\Layouts\Grid::class,
            'masonry'  => ( class_exists( '\\EasySocialFeed\\Facebook\\Layouts\\Layouts\\Masonry' ) ? \EasySocialFeed\Facebook\Layouts\Layouts\Masonry::class : '' ),
            'carousel' => ( class_exists( '\\EasySocialFeed\\Facebook\\Layouts\\Layouts\\Carousel' ) ? \EasySocialFeed\Facebook\Layouts\Layouts\Carousel::class : '' ),
        );
        $has_plan = function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan();
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() && $has_plan ) {
            foreach ( $pro as $slug => $class_name ) {
                if ( '' === $class_name || !class_exists( $class_name ) ) {
                    continue;
                }
                $css_stem = ( isset( $layout_css_files[$slug] ) ? $layout_css_files[$slug] : $slug );
                $layout_css = $locate_layout_css( $css_stem );
                $layout_css_path = $layout_css['path'];
                $layout_css_ver = ( file_exists( $layout_css_path ) ? (string) filemtime( $layout_css_path ) : $version );
                $pro_layout_js = $layout_js;
                if ( 'masonry' === $slug ) {
                    $masonry_js_path = $base_dir . 'frontend/assets/js/esf-facebook-masonry__premium_only.js';
                    if ( function_exists( 'esf_register_masonry_pack_script' ) ) {
                        esf_register_masonry_pack_script();
                    }
                    $pro_layout_js = array(
                        'handle'    => 'esf-facebook-masonry',
                        'src'       => $base_url . 'frontend/assets/js/esf-facebook-masonry__premium_only.js',
                        'deps'      => array('esf-masonry-pack'),
                        'ver'       => ( file_exists( $masonry_js_path ) ? (string) filemtime( $masonry_js_path ) : $version ),
                        'in_footer' => true,
                    );
                } elseif ( 'carousel' === $slug ) {
                    $plugin_root_url = ( defined( 'FTA_PLUGIN_URL' ) ? FTA_PLUGIN_URL : trailingslashit( dirname( rtrim( $base_url, '/' ) ) ) );
                    $plugin_root_dir = ( defined( 'FTA_PLUGIN_DIR' ) ? FTA_PLUGIN_DIR : trailingslashit( dirname( rtrim( $base_dir, '/' ) ) ) );
                    $carousel_js_path = $plugin_root_dir . 'admin/shared/frontend/js/esf-carousel__premium_only.js';
                    $pro_layout_js = array(
                        'handle'    => 'esf-facebook-layout-carousel',
                        'src'       => $plugin_root_url . 'admin/shared/frontend/js/esf-carousel__premium_only.js',
                        'deps'      => array(),
                        'ver'       => ( file_exists( $carousel_js_path ) ? (string) filemtime( $carousel_js_path ) : $version ),
                        'in_footer' => true,
                    );
                }
                $pro_label = ucfirst( $slug );
                if ( 'grid' === $slug ) {
                    $pro_label = __( 'Grid', 'easy-facebook-likebox' );
                } elseif ( 'masonry' === $slug ) {
                    $pro_label = __( 'Masonry', 'easy-facebook-likebox' );
                } elseif ( 'carousel' === $slug ) {
                    $pro_label = __( 'Carousel', 'easy-facebook-likebox' );
                }
                $registry->register( new \EasySocialFeed\Layouts\ValueObjects\LayoutDefinition(array(
                    'slug'             => $slug,
                    'label'            => $pro_label,
                    'description'      => '',
                    'is_pro'           => true,
                    'supports'         => array('header', 'load_more', 'custom_css'),
                    'class_name'       => $class_name,
                    'default_settings' => array(
                        'layout' => array(
                            'type' => $slug,
                        ),
                    ),
                    'assets'           => array(
                        'base_css'   => $base_css,
                        'layout_css' => array(
                            'handle' => 'esf-facebook-layout-' . $css_stem,
                            'src'    => $layout_css['url'],
                            'deps'   => array('esf-facebook-feed'),
                            'ver'    => $layout_css_ver,
                        ),
                        'layout_js'  => $pro_layout_js,
                    ),
                )) );
            }
        }
        /**
         * Fires after free Facebook layouts are registered.
         *
         * @since 6.7.9
         * @param \EasySocialFeed\Layouts\LayoutRegistry $registry Registry.
         */
        do_action( 'esf_facebook_register_layouts', $registry );
    }

    /**
     * Register REST API routes.
     *
     * @since 6.7.9
     * @return void
     */
    public function register_rest_routes() {
        if ( function_exists( 'esf_is_facebook_active' ) && !esf_is_facebook_active() ) {
            return;
        }
        ESF_Facebook_API_OAuth::register_routes();
        ESF_Facebook_API_Accounts::register_routes();
        ESF_Facebook_API_Pages::register_routes();
        ESF_Facebook_API_Feeds::register_routes();
        ESF_Facebook_API_Settings::register_routes();
        ESF_Facebook_API_Migrate::register_routes();
        if ( class_exists( 'ESF_Facebook_API_Preview' ) ) {
            ESF_Facebook_API_Preview::register_routes();
        }
        if ( class_exists( 'ESF_Facebook_API_Likebox' ) ) {
            ESF_Facebook_API_Likebox::register_routes();
        }
        if ( function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan() ) {
            if ( class_exists( 'ESF_Facebook_API_Load_More' ) && method_exists( 'ESF_Facebook_API_Load_More', 'register_routes__premium_only' ) ) {
                ESF_Facebook_API_Load_More::register_routes__premium_only();
            }
            if ( class_exists( 'ESF_Facebook_API_Post_Comments' ) && method_exists( 'ESF_Facebook_API_Post_Comments', 'register_routes__premium_only' ) ) {
                ESF_Facebook_API_Post_Comments::register_routes__premium_only();
            }
            if ( class_exists( 'ESF_Facebook_API_Comment_Replies' ) && method_exists( 'ESF_Facebook_API_Comment_Replies', 'register_routes__premium_only' ) ) {
                ESF_Facebook_API_Comment_Replies::register_routes__premium_only();
            }
            if ( class_exists( 'ESF_Facebook_API_Album_Photos' ) && method_exists( 'ESF_Facebook_API_Album_Photos', 'register_routes__premium_only' ) ) {
                ESF_Facebook_API_Album_Photos::register_routes__premium_only();
            }
            if ( class_exists( 'ESF_Facebook_API_Moderate' ) && method_exists( 'ESF_Facebook_API_Moderate', 'register_routes__premium_only' ) ) {
                ESF_Facebook_API_Moderate::register_routes__premium_only();
            }
        }
    }

    /**
     * Handle OAuth callback on admin_init.
     *
     * @since 6.7.9
     * @return void
     */
    public function maybe_handle_oauth_callback() {
        if ( class_exists( 'ESF_Facebook_Auth_Callback_Listener' ) ) {
            ESF_Facebook_Auth_Callback_Listener::get_instance()->handle();
        }
    }

    /**
     * WP-Cron callback for batched legacy shortcode rewrites.
     *
     * @since 6.7.9
     * @return void
     */
    public function run_legacy_shortcode_rewrite() {
        if ( !class_exists( 'ESF_Facebook_Legacy_Shortcode_Rewriter', false ) ) {
            return;
        }
        ESF_Facebook_Legacy_Shortcode_Rewriter::handle_cron();
    }

    /**
     * One-time remap of legacy widget instances for sites already on modern.
     *
     * @since 6.7.9
     * @return void
     */
    public function maybe_migrate_legacy_widgets() {
        if ( !class_exists( 'ESF_Facebook_Widget_Migrator', false ) ) {
            return;
        }
        if ( '1' === (string) get_option( ESF_Facebook_Widget_Migrator::DONE_OPTION, '' ) ) {
            return;
        }
        $default_feed_id = ( function_exists( 'esf_facebook_get_migration_default_feed_id' ) ? (int) esf_facebook_get_migration_default_feed_id() : 0 );
        ( new ESF_Facebook_Widget_Migrator() )->migrate_widgets( $default_feed_id );
    }

    /**
     * One-time build of legacy shortcode → feed map for sites already on modern.
     *
     * @since 6.7.9
     * @return void
     */
    public function maybe_build_legacy_feed_map() {
        if ( !class_exists( 'ESF_Facebook_Legacy_Feed_Map', false ) ) {
            return;
        }
        if ( '1' === (string) get_option( ESF_Facebook_Legacy_Feed_Map::DONE_OPTION, '' ) && !ESF_Facebook_Legacy_Feed_Map::needs_rebuild() ) {
            return;
        }
        $default_feed_id = ( function_exists( 'esf_facebook_get_migration_default_feed_id' ) ? (int) esf_facebook_get_migration_default_feed_id() : 0 );
        ( new ESF_Facebook_Legacy_Feed_Map() )->build( $default_feed_id );
    }

    /**
     * Resume shortcode rewrite if a previous migrate left it running.
     *
     * @since 6.7.9
     * @return void
     */
    public function maybe_start_legacy_shortcode_rewrite() {
        if ( !class_exists( 'ESF_Facebook_Legacy_Shortcode_Rewriter', false ) ) {
            return;
        }
        if ( !ESF_Facebook_Legacy_Shortcode_Rewriter::is_running() ) {
            return;
        }
        ( new ESF_Facebook_Legacy_Shortcode_Rewriter() )->start( false );
    }

}
