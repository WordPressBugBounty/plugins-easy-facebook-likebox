<?php

/**
 * Twitter Module Main Class
 *
 * Bootstraps the Twitter module: registers hooks, loads dependencies,
 * initialises components, and manages cron schedules.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter
 * @since 6.7.6
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Twitter_Main
 *
 * @since 6.7.6
 */
class ESF_Twitter_Main {
    use ESF_Twitter_Singleton;
    /**
     * WP-Cron schedule slug for 30-minute intervals.
     *
     * @since 6.7.6
     * @var string
     */
    const CRON_THIRTY_MIN = 'thirty_minutes';

    const CRON_CACHE_REFRESH_HOOK = 'esf_twitter_refresh_feed_cache';

    const DB_SCHEMA_VERSION = '4';

    /**
     * Constructor.
     *
     * @since 6.7.6
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Register WordPress hooks that are safe to hook during plugins_loaded/init.
     *
     * @since 6.7.6
     * @return void
     */
    private function init_hooks() {
        add_action( 'init', array($this, 'load_dependencies') );
        /*
         * Layout registration runs at priority 11 to ensure translations are loaded first (priority 10).
         * Token cron wiring runs after Feed_Them_All::includes() on init (priority 10)
         * so shared helpers are loaded first.
         */
        add_action( 'init', array($this, 'maybe_initialize_components'), 11 );
        add_action( 'init', array($this, 'maybe_init_token_manager'), 11 );
        add_action( 'rest_api_init', array($this, 'register_rest_routes') );
        add_filter( 'cron_schedules', array($this, 'add_cron_schedules') );
        add_action( 'admin_init', array($this, 'maybe_handle_oauth_callback') );
        add_action( self::CRON_CACHE_REFRESH_HOOK, array($this, 'run_feed_cache_refresh') );
        $this->register_warm_cron_hook();
    }

    /**
     * Load all module PHP dependencies.
     *
     * @since 6.7.6
     * @return void
     */
    public function load_dependencies() {
        require_once ESF_TWITTER_DIR . 'includes/traits/trait-esf-twitter-singleton.php';
        require_once ESF_TWITTER_DIR . 'includes/helpers/twitter-helper-functions.php';
        require_once ESF_TWITTER_DIR . 'includes/database/class-esf-twitter-db-installer.php';
        require_once ESF_TWITTER_DIR . 'includes/class-esf-twitter-cache.php';
        require_once ESF_TWITTER_DIR . 'includes/class-esf-twitter-local-media.php';
        require_once ESF_TWITTER_DIR . 'includes/api/class-esf-twitter-api-warm-media.php';
        require_once ESF_TWITTER_DIR . 'includes/api/class-esf-twitter-api-video-proxy.php';
        require_once ESF_TWITTER_DIR . 'includes/models/class-esf-twitter-account-repository.php';
        require_once ESF_TWITTER_DIR . 'includes/models/class-esf-twitter-feed-repository.php';
        require_once ESF_TWITTER_DIR . 'includes/api/class-esf-twitter-api-service.php';
        require_once ESF_TWITTER_DIR . 'includes/api/class-esf-twitter-api-oauth.php';
        require_once ESF_TWITTER_DIR . 'includes/api/class-esf-twitter-api-accounts.php';
        require_once ESF_TWITTER_DIR . 'includes/api/class-esf-twitter-api-feeds.php';
        require_once ESF_TWITTER_DIR . 'includes/api/class-esf-twitter-api-settings.php';
        require_once ESF_TWITTER_DIR . 'includes/api/class-esf-twitter-api-preview.php';
        $load_more_file = ESF_TWITTER_DIR . 'includes/api/premium/class-esf-twitter-api-load-more.php';
        require_once ESF_TWITTER_DIR . 'includes/class-esf-twitter-token-manager.php';
        require_once ESF_TWITTER_DIR . 'includes/class-esf-twitter-token-notifications.php';
        require_once ESF_TWITTER_DIR . 'includes/class-esf-twitter-auth-callback-listener.php';
        require_once ESF_TWITTER_DIR . 'includes/class-esf-twitter-manual-oauth-service.php';
        require_once ESF_TWITTER_DIR . 'includes/layouts/class-esf-twitter-layout-registry.php';
        require_once ESF_TWITTER_DIR . 'includes/layouts/class-esf-twitter-layout-base.php';
        require_once ESF_TWITTER_DIR . 'includes/layouts/class-esf-twitter-layout-timeline.php';
        require_once ESF_TWITTER_DIR . 'includes/layouts/class-esf-twitter-layout-row.php';
        // This entire code block will be removed from the free version.
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            $carousel_file = ESF_TWITTER_DIR . 'includes/layouts/premium/class-esf-twitter-layout-carousel.php';
            if ( file_exists( $carousel_file ) ) {
                require_once $carousel_file;
            }
            $half_width_file = ESF_TWITTER_DIR . 'includes/layouts/premium/class-esf-twitter-layout-half-width.php';
            if ( file_exists( $half_width_file ) ) {
                require_once $half_width_file;
            }
            $masonry_file = ESF_TWITTER_DIR . 'includes/layouts/premium/class-esf-twitter-layout-masonry.php';
            if ( file_exists( $masonry_file ) ) {
                require_once $masonry_file;
            }
            $grid_file = ESF_TWITTER_DIR . 'includes/layouts/premium/class-esf-twitter-layout-grid.php';
            if ( file_exists( $grid_file ) ) {
                require_once $grid_file;
            }
        }
        require_once ESF_TWITTER_DIR . 'includes/class-esf-twitter-renderer.php';
        if ( !is_admin() ) {
            require_once ESF_TWITTER_DIR . 'frontend/class-esf-twitter-frontend.php';
        }
        if ( is_admin() ) {
            require_once ESF_TWITTER_DIR . 'admin/classes/class-esf-twitter-admin.php';
        }
    }

    /**
     * Initialise module components after dependencies are loaded.
     *
     * @since 6.7.6
     * @return void
     */
    public function maybe_initialize_components() {
        if ( function_exists( 'esf_is_twitter_active' ) && !esf_is_twitter_active() ) {
            return;
        }
        // Register layouts in shared registry
        $version = ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );
        $registry = \EasySocialFeed\Layouts\LayoutRegistry::for( 'twitter' );
        $timeline_definition = new \EasySocialFeed\Layouts\ValueObjects\LayoutDefinition(array(
            'slug'        => 'timeline',
            'label'       => __( 'Timeline', 'easy-facebook-likebox' ),
            'description' => __( 'Vertical list of tweets with images on the left', 'easy-facebook-likebox' ),
            'icon'        => 'list',
            'class_name'  => ESF_Twitter_Layout_Timeline::class,
            'is_pro'      => false,
            'supports'    => array('header', 'metrics', 'load_more'),
            'assets'      => array(
                'layout_css' => array(
                    'handle' => 'esf-twitter-layout-timeline',
                    'src'    => ESF_TWITTER_URL . 'frontend/assets/css/layouts/timeline.css',
                    'deps'   => array(),
                    'ver'    => $version,
                ),
            ),
        ));
        $registry->register( $timeline_definition );
        // Keep private Load More registry in sync with shared LayoutRegistry.
        if ( class_exists( 'ESF_Twitter_Layout_Registry' ) ) {
            ESF_Twitter_Layout_Registry::register( 'timeline', ESF_Twitter_Layout_Timeline::class );
        }
        $this->register_row_layout();
        // This entire code block will be removed from the free version.
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( method_exists( $this, 'register_carousel_layout__premium_only' ) ) {
                $this->register_carousel_layout__premium_only();
            }
            if ( method_exists( $this, 'register_half_width_layout__premium_only' ) ) {
                $this->register_half_width_layout__premium_only();
            }
            if ( method_exists( $this, 'register_masonry_layout__premium_only' ) ) {
                $this->register_masonry_layout__premium_only();
            }
            if ( method_exists( $this, 'register_grid_layout__premium_only' ) ) {
                $this->register_grid_layout__premium_only();
            }
        }
        $this->maybe_schedule_feed_cache_refresh();
        $this->maybe_migrate_db_schema();
        if ( is_admin() ) {
            ESF_Twitter_Admin::get_instance();
        } else {
            ESF_Twitter_Frontend::get_instance();
        }
    }

    /**
     * Register the Row layout.
     *
     * Free layout (Instagram pattern). Wave stagger remains Pro-only via
     * `esf_twitter_has_twitter_plan()` inside the layout class. Wave JS is
     * Freemius-stripped from free zips and only enqueued when the file exists.
     *
     * @since 6.15.0
     * @return void
     */
    private function register_row_layout() {
        if ( !class_exists( 'ESF_Twitter_Layout_Row' ) ) {
            return;
        }
        $version = ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );
        $row_js = ESF_TWITTER_DIR . 'frontend/assets/js/esf-twitter-row-wave.js';
        $assets = array(
            'layout_css' => array(
                'handle' => ESF_Twitter_Layout_Row::CSS_HANDLE,
                'src'    => ESF_TWITTER_URL . 'frontend/assets/css/layouts/row.css',
                'deps'   => array(),
                'ver'    => $version,
            ),
        );
        if ( file_exists( $row_js ) ) {
            $assets['layout_js'] = array(
                'handle'    => ESF_Twitter_Layout_Row::JS_HANDLE,
                'src'       => ESF_TWITTER_URL . 'frontend/assets/js/esf-twitter-row-wave.js',
                'deps'      => array(),
                'ver'       => (string) filemtime( $row_js ),
                'in_footer' => true,
            );
        }
        $registry = \EasySocialFeed\Layouts\LayoutRegistry::for( 'twitter' );
        $registry->register( new \EasySocialFeed\Layouts\ValueObjects\LayoutDefinition(array(
            'slug'             => 'row',
            'label'            => __( 'Row', 'easy-facebook-likebox' ),
            'description'      => __( 'A sleek single-row strip — great for headers and sections', 'easy-facebook-likebox' ),
            'icon'             => 'row',
            'class_name'       => ESF_Twitter_Layout_Row::class,
            'is_pro'           => false,
            'supports'         => array('header', 'metrics', 'load_more'),
            'assets'           => $assets,
            'default_settings' => array(
                'layout' => array(
                    'type' => 'row',
                    'row'  => array(
                        'row_style'          => 'standard',
                        'wave_offset'        => 16,
                        'media_aspect_ratio' => '1:1',
                        'columns'            => 6,
                        'columns_tablet'     => 3,
                        'columns_mobile'     => 2,
                        'gap'                => 0,
                        'tile_border_radius' => 0,
                        'tile_shadow'        => false,
                        'tile_shadow_color'  => 'rgba(0,0,0,0.12)',
                    ),
                ),
            ),
        )) );
        if ( class_exists( 'ESF_Twitter_Layout_Registry' ) ) {
            ESF_Twitter_Layout_Registry::register( 'row', ESF_Twitter_Layout_Row::class );
        }
    }

    /**
     * Start WP-Cron handler for Twitter token refresh.
     *
     * @since 6.9.0
     * @return void
     */
    public function maybe_init_token_manager() {
        if ( function_exists( 'esf_is_twitter_active' ) && !esf_is_twitter_active() ) {
            return;
        }
        if ( class_exists( 'ESF_Twitter_Token_Manager' ) ) {
            ESF_Twitter_Token_Manager::get_instance()->init();
        }
    }

    /**
     * Register module REST routes.
     *
     * @since 6.7.6
     * @return void
     */
    public function register_rest_routes() {
        ESF_Twitter_API_OAuth::register_routes();
        ESF_Twitter_API_Accounts::register_routes();
        ESF_Twitter_API_Feeds::register_routes();
        ESF_Twitter_API_Settings::register_routes();
        ESF_Twitter_API_Preview::register_routes();
        if ( class_exists( 'ESF_Twitter_API_Warm_Media' ) ) {
            ESF_Twitter_API_Warm_Media::register_routes();
        }
        if ( class_exists( 'ESF_Twitter_API_Video_Proxy' ) ) {
            ESF_Twitter_API_Video_Proxy::register_routes();
        }
    }

    /**
     * Check whether the site has an active Twitter/X plan.
     *
     * @since 6.7.6
     * @return bool
     */
    private function has_twitter_plan() {
        return function_exists( 'esf_twitter_has_twitter_plan' ) && esf_twitter_has_twitter_plan();
    }

    /**
     * Register the local-media warm cron hook.
     *
     * @since 6.14.1
     * @return void
     */
    private function register_warm_cron_hook() {
        if ( class_exists( 'ESF_Twitter_Local_Media' ) ) {
            add_action(
                ESF_Twitter_Local_Media::WARM_CRON_HOOK,
                array('ESF_Twitter_Local_Media', 'run_warm_cron'),
                10,
                2
            );
        }
    }

    /**
     * Add custom WP-Cron schedules.
     *
     * @since 6.7.6
     * @param array $schedules Existing schedules.
     * @return array Modified schedules.
     */
    public function add_cron_schedules( $schedules ) {
        $schedules = esf_cron_schedules_add_thirty_minutes( $schedules );
        $cache_schedules = array(
            'esf_twitter_12h' => array(
                'interval' => 43200,
                'display'  => __( 'Every 12 Hours', 'easy-facebook-likebox' ),
            ),
            'esf_twitter_24h' => array(
                'interval' => 86400,
                'display'  => __( 'Every 24 Hours', 'easy-facebook-likebox' ),
            ),
            'esf_twitter_7d'  => array(
                'interval' => 604800,
                'display'  => __( 'Every 7 Days', 'easy-facebook-likebox' ),
            ),
        );
        foreach ( $cache_schedules as $slug => $args ) {
            if ( !isset( $schedules[$slug] ) ) {
                $schedules[$slug] = $args;
            }
        }
        return $schedules;
    }

    /**
     * Schedule feed cache refresh cron based on selected cache duration.
     *
     * @since 6.7.6
     * @return void
     */
    private function maybe_schedule_feed_cache_refresh() {
        $cache_duration = (int) esf_get_twitter_settings( 'cache_duration' );
        $schedule_slug = $this->get_cache_refresh_schedule_slug( $cache_duration );
        $event = wp_get_scheduled_event( self::CRON_CACHE_REFRESH_HOOK );
        if ( $event && $event->schedule === $schedule_slug ) {
            return;
        }
        $timestamp = wp_next_scheduled( self::CRON_CACHE_REFRESH_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_CACHE_REFRESH_HOOK );
        }
        wp_schedule_event( time(), $schedule_slug, self::CRON_CACHE_REFRESH_HOOK );
    }

    /**
     * Reschedule feed cache refresh cron.
     *
     * @since 6.7.6
     * @return void
     */
    public function reschedule_feed_cache_refresh() {
        $timestamp = wp_next_scheduled( self::CRON_CACHE_REFRESH_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_CACHE_REFRESH_HOOK );
        }
        $this->maybe_schedule_feed_cache_refresh();
    }

    /**
     * Map cache duration to cron schedule slug.
     *
     * @since 6.7.6
     * @param int $cache_duration_secs Cache duration in seconds.
     * @return string
     */
    private function get_cache_refresh_schedule_slug( $cache_duration_secs ) {
        $map = array(
            43200  => 'esf_twitter_12h',
            86400  => 'esf_twitter_24h',
            604800 => 'esf_twitter_7d',
        );
        return ( isset( $map[(int) $cache_duration_secs] ) ? $map[(int) $cache_duration_secs] : 'esf_twitter_12h' );
    }

    /**
     * Cron callback: refresh expired feed cache and stale account stats.
     *
     * @since 6.7.6
     * @return void
     */
    public function run_feed_cache_refresh() {
        $cache_duration = (int) esf_get_twitter_settings( 'cache_duration' );
        if ( $cache_duration <= 0 ) {
            $cache_duration = 43200;
        }
        $feed_repo = ESF_Twitter_Feed_Repository::get_instance();
        $feeds = $feed_repo->get_all( array(
            'status' => 'active',
        ) );
        if ( !empty( $feeds ) ) {
            $renderer = new ESF_Twitter_Renderer();
            foreach ( $feeds as $feed ) {
                $feed_id = ( isset( $feed->id ) ? (int) $feed->id : 0 );
                $account_id = ( isset( $feed->account_id ) ? (int) $feed->account_id : 0 );
                if ( $feed_id <= 0 || $account_id <= 0 ) {
                    continue;
                }
                $cache_key = ( function_exists( 'esf_twitter_tweets_cache_key' ) ? esf_twitter_tweets_cache_key( $account_id ) : 'esf_tw_tweets_' . $account_id );
                $expires_at = ESF_Twitter_Cache::get_expires_at( $cache_key );
                if ( function_exists( 'esf_feed_cache_is_due_for_refresh' ) && !esf_feed_cache_is_due_for_refresh( $expires_at ) ) {
                    continue;
                    // still outside lead window
                }
                $renderer->get_tweets_for_feed_by_id( $feed_id );
            }
        }
        $account_repo = ESF_Twitter_Account_Repository::get_instance();
        $accounts_due = $account_repo->get_accounts_due_for_stats_refresh( $cache_duration );
        $token_manager = ESF_Twitter_Token_Manager::get_instance();
        $api_service = ESF_Twitter_API_Service::get_instance();
        foreach ( $accounts_due as $account ) {
            $account_id = ( isset( $account->id ) ? (int) $account->id : 0 );
            if ( $account_id <= 0 ) {
                continue;
            }
            $account_type = ( isset( $account->account_type ) ? (string) $account->account_type : 'connected' );
            $user_data = null;
            if ( 'public' === $account_type ) {
                $username = ( isset( $account->username ) ? (string) $account->username : '' );
                if ( '' === $username ) {
                    continue;
                }
                $user_data = $api_service->fetch_public_user( $username );
            } else {
                $access_token = $token_manager->get_valid_access_token( $account_id );
                if ( !$access_token ) {
                    continue;
                }
                $user_data = $api_service->validate_token_and_fetch_user( $access_token );
            }
            if ( is_wp_error( $user_data ) || !is_array( $user_data ) ) {
                continue;
            }
            $account_repo->update_account_stats( $account_id, $user_data );
        }
    }

    /**
     * Delegate OAuth callback handling to the listener.
     *
     * @since 6.7.6
     * @return void
     */
    public function maybe_handle_oauth_callback() {
        ESF_Twitter_Auth_Callback_Listener::get_instance()->handle();
    }

    /**
     * Activation hook callback.
     *
     * Creates the required database tables on module activation.
     *
     * @since 6.7.6
     * @return void
     */
    public static function on_activation() {
        require_once ESF_TWITTER_DIR . 'includes/traits/trait-esf-twitter-singleton.php';
        require_once ESF_TWITTER_DIR . 'includes/database/class-esf-twitter-db-installer.php';
        ESF_Twitter_DB_Installer::create_tables();
        ESF_Twitter_DB_Installer::maybe_drop_cache_feed_id_column();
        update_option( 'esf_twitter_db_schema_version', self::DB_SCHEMA_VERSION );
    }

    /**
     * Run idempotent DB schema migrations when needed.
     *
     * @since 6.7.6
     * @return void
     */
    private function maybe_migrate_db_schema() {
        $installed_version = (string) get_option( 'esf_twitter_db_schema_version', '1' );
        if ( ESF_Twitter_DB_Installer::tables_exist() && self::DB_SCHEMA_VERSION === $installed_version ) {
            return;
        }
        ESF_Twitter_DB_Installer::create_tables();
        ESF_Twitter_DB_Installer::maybe_drop_cache_feed_id_column();
        update_option( 'esf_twitter_db_schema_version', self::DB_SCHEMA_VERSION );
    }

    /**
     * Deactivation hook callback.
     *
     * Removes scheduled cron events for the Twitter module.
     *
     * @since 6.7.6
     * @return void
     */
    public static function on_deactivation() {
        $timestamp = wp_next_scheduled( ESF_Twitter_Token_Manager::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, ESF_Twitter_Token_Manager::CRON_HOOK );
        }
        $timestamp = wp_next_scheduled( self::CRON_CACHE_REFRESH_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_CACHE_REFRESH_HOOK );
        }
    }

}
