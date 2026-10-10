<?php

/**
 * TikTok Module Main Class
 *
 * Bootstraps the TikTok module: registers hooks, loads dependencies,
 * OAuth callback, REST routes, layouts, token/cache cron, and admin/frontend.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok
 * @since 6.8.0
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_TikTok_Main
 *
 * @since 6.8.0
 */
class ESF_TikTok_Main {
    use ESF_TikTok_Singleton;
    /**
     * Current DB schema version option value.
     *
     * @since 6.8.0
     * @var string
     */
    const DB_SCHEMA_VERSION = '1.1';

    /**
     * Cron hook for account-scoped feed cache refresh.
     *
     * @since 6.8.0
     * @var string
     */
    const CRON_CACHE_REFRESH_HOOK = 'esf_tiktok_feed_cache_refresh';

    /**
     * Constructor.
     *
     * @since 6.8.0
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Register WordPress hooks.
     *
     * @since 6.8.0
     * @return void
     */
    private function init_hooks() {
        add_action( 'init', array($this, 'load_dependencies') );
        add_action( 'init', array($this, 'maybe_initialize_components'), 11 );
        add_action( 'init', array($this, 'maybe_init_token_manager'), 11 );
        add_action( 'rest_api_init', array($this, 'register_rest_routes') );
        add_action( 'admin_init', array($this, 'maybe_handle_oauth_callback') );
        add_filter( 'cron_schedules', array($this, 'add_cron_schedules') );
        add_action( self::CRON_CACHE_REFRESH_HOOK, array($this, 'run_feed_cache_refresh') );
    }

    /**
     * Load module PHP dependencies.
     *
     * @since 6.8.0
     * @return void
     */
    public function load_dependencies() {
        require_once ESF_TIKTOK_DIR . 'includes/traits/trait-esf-tiktok-singleton.php';
        require_once ESF_TIKTOK_DIR . 'includes/helpers/tiktok-helper-functions.php';
        require_once ESF_TIKTOK_DIR . 'includes/database/class-esf-tiktok-db-installer.php';
        require_once ESF_TIKTOK_DIR . 'includes/class-esf-tiktok-cache.php';
        require_once ESF_TIKTOK_DIR . 'includes/cache/class-esf-tiktok-cache-refresher.php';
        require_once ESF_TIKTOK_DIR . 'includes/class-esf-tiktok-local-media.php';
        require_once ESF_TIKTOK_DIR . 'includes/class-esf-tiktok-api-error.php';
        require_once ESF_TIKTOK_DIR . 'includes/models/class-esf-tiktok-account-repository.php';
        require_once ESF_TIKTOK_DIR . 'includes/models/class-esf-tiktok-feed-repository.php';
        require_once ESF_TIKTOK_DIR . 'includes/api/class-esf-tiktok-api-service.php';
        require_once ESF_TIKTOK_DIR . 'includes/api/class-esf-tiktok-api-oauth.php';
        require_once ESF_TIKTOK_DIR . 'includes/api/class-esf-tiktok-api-accounts.php';
        require_once ESF_TIKTOK_DIR . 'includes/api/class-esf-tiktok-api-feeds.php';
        require_once ESF_TIKTOK_DIR . 'includes/api/class-esf-tiktok-api-settings.php';
        require_once ESF_TIKTOK_DIR . 'includes/api/class-esf-tiktok-api-preview.php';
        require_once ESF_TIKTOK_DIR . 'includes/api/class-esf-tiktok-api-warm-media.php';
        require_once ESF_TIKTOK_DIR . 'includes/class-esf-tiktok-auth-callback-listener.php';
        require_once ESF_TIKTOK_DIR . 'includes/class-esf-tiktok-manual-oauth-service.php';
        require_once ESF_TIKTOK_DIR . 'includes/class-esf-tiktok-token-notifications.php';
        require_once ESF_TIKTOK_DIR . 'includes/class-esf-tiktok-token-manager.php';
        require_once ESF_TIKTOK_DIR . 'includes/class-esf-tiktok-renderer.php';
        if ( !is_admin() ) {
            require_once ESF_TIKTOK_DIR . 'frontend/class-esf-tiktok-frontend.php';
        }
        if ( is_admin() ) {
            require_once ESF_TIKTOK_DIR . 'admin/classes/class-esf-tiktok-admin.php';
        }
        if ( class_exists( 'ESF_Safe_Cache_Refresh' ) && class_exists( 'ESF_TikTok_Cache_Refresher' ) ) {
            ESF_Safe_Cache_Refresh::register( new ESF_TikTok_Cache_Refresher() );
        }
    }

    /**
     * Initialise module components after dependencies are loaded.
     *
     * @since 6.8.0
     * @return void
     */
    public function maybe_initialize_components() {
        if ( function_exists( 'esf_is_tiktok_active' ) && !esf_is_tiktok_active() ) {
            return;
        }
        $this->register_layouts();
        $this->maybe_migrate_db_schema();
        $this->maybe_schedule_feed_cache_refresh();
        if ( class_exists( 'ESF_TikTok_Local_Media' ) ) {
            add_action(
                ESF_TikTok_Local_Media::WARM_CRON_HOOK,
                array('ESF_TikTok_Local_Media', 'run_warm_cron'),
                10,
                2
            );
        }
        if ( is_admin() && class_exists( 'ESF_TikTok_Admin' ) ) {
            ESF_TikTok_Admin::get_instance();
        } elseif ( !is_admin() && class_exists( 'ESF_TikTok_Frontend' ) ) {
            ESF_TikTok_Frontend::get_instance();
        }
    }

    /**
     * Register shared layout definitions for TikTok.
     *
     * @since 6.8.0
     * @return void
     */
    public function register_layouts() {
        if ( !class_exists( '\\EasySocialFeed\\Layouts\\LayoutRegistry' ) ) {
            return;
        }
        $registry = \EasySocialFeed\Layouts\LayoutRegistry::for( 'tiktok' );
        $version = ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );
        $registry->register( new \EasySocialFeed\Layouts\ValueObjects\LayoutDefinition(array(
            'slug'             => 'grid',
            'label'            => __( 'Grid', 'easy-facebook-likebox' ),
            'is_pro'           => false,
            'class_name'       => \EasySocialFeed\TikTok\Layouts\Layouts\Grid::class,
            'supports'         => array('header', 'custom_css', 'load_more'),
            'default_settings' => array(
                'layout' => array(
                    'type'    => 'grid',
                    'columns' => 3,
                ),
            ),
            'assets'           => array(
                'base_css'   => array(
                    'handle' => 'esf-tiktok-feed',
                    'src'    => ESF_TIKTOK_URL . 'frontend/assets/css/esf-tiktok-feed.css',
                    'ver'    => $version,
                ),
                'layout_css' => array(
                    'handle' => 'esf-tiktok-layout-grid',
                    'src'    => ESF_TIKTOK_URL . 'frontend/assets/css/layouts/grid.css',
                    'deps'   => array('esf-tiktok-feed'),
                    'ver'    => $version,
                ),
            ),
        )) );
        $registry->register( new \EasySocialFeed\Layouts\ValueObjects\LayoutDefinition(array(
            'slug'             => 'row',
            'label'            => __( 'Row', 'easy-facebook-likebox' ),
            'is_pro'           => false,
            'class_name'       => \EasySocialFeed\TikTok\Layouts\Layouts\Row::class,
            'supports'         => array('header', 'custom_css', 'load_more'),
            'default_settings' => array(
                'layout' => array(
                    'type'    => 'row',
                    'columns' => 6,
                ),
            ),
            'assets'           => array(
                'base_css'   => array(
                    'handle' => 'esf-tiktok-feed',
                    'src'    => ESF_TIKTOK_URL . 'frontend/assets/css/esf-tiktok-feed.css',
                    'ver'    => $version,
                ),
                'layout_css' => array(
                    'handle' => 'esf-tiktok-layout-row',
                    'src'    => ESF_TIKTOK_URL . 'frontend/assets/css/layouts/row.css',
                    'deps'   => array('esf-tiktok-feed'),
                    'ver'    => ( file_exists( ESF_TIKTOK_DIR . 'frontend/assets/css/layouts/row.css' ) ? (string) filemtime( ESF_TIKTOK_DIR . 'frontend/assets/css/layouts/row.css' ) : $version ),
                ),
                'layout_js'  => array(
                    'handle'    => 'esf-tiktok-feed-row-wave',
                    'src'       => ESF_TIKTOK_URL . 'frontend/assets/js/esf-tiktok-row-wave__premium_only.js',
                    'deps'      => array(),
                    'ver'       => ( file_exists( ESF_TIKTOK_DIR . 'frontend/assets/js/esf-tiktok-row-wave__premium_only.js' ) ? (string) filemtime( ESF_TIKTOK_DIR . 'frontend/assets/js/esf-tiktok-row-wave__premium_only.js' ) : $version ),
                    'in_footer' => true,
                ),
            ),
        )) );
        /**
         * Fires after TikTok layouts are registered.
         *
         * @since 6.8.0
         * @param \EasySocialFeed\Layouts\LayoutRegistry $registry Registry.
         */
        do_action( 'esf_tiktok_register_layouts', $registry );
    }

    /**
     * Initialise token auto-refresh cron.
     *
     * @since 6.8.0
     * @return void
     */
    public function maybe_init_token_manager() {
        if ( function_exists( 'esf_is_tiktok_active' ) && !esf_is_tiktok_active() ) {
            return;
        }
        if ( class_exists( 'ESF_TikTok_Token_Manager' ) ) {
            ESF_TikTok_Token_Manager::get_instance()->init();
        }
    }

    /**
     * Register REST API routes.
     *
     * @since 6.8.0
     * @return void
     */
    public function register_rest_routes() {
        if ( function_exists( 'esf_is_tiktok_active' ) && !esf_is_tiktok_active() ) {
            return;
        }
        if ( class_exists( 'ESF_TikTok_API_OAuth' ) ) {
            ESF_TikTok_API_OAuth::register_routes();
        }
        if ( class_exists( 'ESF_TikTok_API_Accounts' ) ) {
            ESF_TikTok_API_Accounts::register_routes();
        }
        if ( class_exists( 'ESF_TikTok_API_Feeds' ) ) {
            ESF_TikTok_API_Feeds::register_routes();
        }
        if ( class_exists( 'ESF_TikTok_API_Settings' ) ) {
            ESF_TikTok_API_Settings::register_routes();
        }
        if ( class_exists( 'ESF_TikTok_API_Preview' ) ) {
            ESF_TikTok_API_Preview::register_routes();
        }
        if ( class_exists( 'ESF_TikTok_API_Warm_Media' ) ) {
            ESF_TikTok_API_Warm_Media::register_routes();
        }
    }

    /**
     * Delegate OAuth callback handling to the listener.
     *
     * @since 6.8.0
     * @return void
     */
    public function maybe_handle_oauth_callback() {
        if ( class_exists( 'ESF_TikTok_Auth_Callback_Listener' ) ) {
            ESF_TikTok_Auth_Callback_Listener::get_instance()->handle();
        }
    }

    /**
     * Add custom cron schedules for feed cache refresh.
     *
     * @since 6.8.0
     * @param array<string,array<string,mixed>> $schedules Schedules.
     * @return array<string,array<string,mixed>>
     */
    public function add_cron_schedules( $schedules ) {
        if ( function_exists( 'esf_feed_cache_add_cron_schedules' ) ) {
            // TikTok Settings currently expose 12h / 24h / 7d (no 1h option).
            return esf_feed_cache_add_cron_schedules( $schedules, 'tiktok', array(
                43200  => '12h',
                86400  => '24h',
                604800 => '7d',
            ) );
        }
        if ( !is_array( $schedules ) ) {
            $schedules = array();
        }
        $schedules['esf_tiktok_12h'] = array(
            'interval' => 12 * HOUR_IN_SECONDS,
            'display'  => __( 'Every 12 Hours (ESF TikTok)', 'easy-facebook-likebox' ),
        );
        $schedules['esf_tiktok_24h'] = array(
            'interval' => DAY_IN_SECONDS,
            'display'  => __( 'Every 24 Hours (ESF TikTok)', 'easy-facebook-likebox' ),
        );
        $schedules['esf_tiktok_7d'] = array(
            'interval' => WEEK_IN_SECONDS,
            'display'  => __( 'Every 7 Days (ESF TikTok)', 'easy-facebook-likebox' ),
        );
        return $schedules;
    }

    /**
     * Schedule feed cache refresh cron based on settings.
     *
     * Replaces a mismatched recurrence when cache duration changes (Instagram /
     * LinkedIn parity).
     *
     * @since 6.8.0
     * @return void
     */
    private function maybe_schedule_feed_cache_refresh() {
        $ttl = (int) esf_get_tiktok_settings( 'cache_duration' );
        $schedule_slug = $this->get_cache_refresh_schedule_slug( $ttl );
        if ( function_exists( 'esf_feed_cache_maybe_schedule_refresh' ) ) {
            esf_feed_cache_maybe_schedule_refresh( self::CRON_CACHE_REFRESH_HOOK, $schedule_slug );
            return;
        }
        $event = ( function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( self::CRON_CACHE_REFRESH_HOOK ) : false );
        if ( is_object( $event ) && isset( $event->schedule ) && $event->schedule === $schedule_slug ) {
            return;
        }
        $timestamp = wp_next_scheduled( self::CRON_CACHE_REFRESH_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_CACHE_REFRESH_HOOK );
        }
        wp_schedule_event( time(), $schedule_slug, self::CRON_CACHE_REFRESH_HOOK );
    }

    /**
     * Reschedule feed cache refresh after Settings TTL changes.
     *
     * @since 6.9.0
     * @return void
     */
    public function reschedule_feed_cache_refresh() {
        $ttl = (int) esf_get_tiktok_settings( 'cache_duration' );
        $schedule_slug = $this->get_cache_refresh_schedule_slug( $ttl );
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
     * Map cache duration (seconds) to a TikTok cron schedule slug.
     *
     * @since 6.9.0
     * @param int $cache_duration_secs Cache duration in seconds.
     * @return string
     */
    private function get_cache_refresh_schedule_slug( $cache_duration_secs ) {
        $suffixes = array(
            43200  => '12h',
            86400  => '24h',
            604800 => '7d',
        );
        if ( function_exists( 'esf_feed_cache_cron_schedule_slug' ) ) {
            return esf_feed_cache_cron_schedule_slug(
                $cache_duration_secs,
                'tiktok',
                43200,
                $suffixes
            );
        }
        return 'esf_tiktok_12h';
    }

    /**
     * Re-warm expired account video caches.
     *
     * @since 6.8.0
     * @return void
     */
    public function run_feed_cache_refresh() {
        if ( !class_exists( 'ESF_TikTok_Account_Repository' ) || !class_exists( 'ESF_TikTok_Renderer' ) ) {
            return;
        }
        $accounts = ESF_TikTok_Account_Repository::get_instance()->get_all( array(
            'status' => 'active',
            'limit'  => 50,
        ) );
        if ( empty( $accounts ) || !is_array( $accounts ) ) {
            return;
        }
        $renderer = new ESF_TikTok_Renderer();
        $feeds = ( class_exists( 'ESF_TikTok_Feed_Repository' ) ? ESF_TikTok_Feed_Repository::get_instance()->get_all( array(
            'limit' => 100,
        ) ) : array() );
        $account_ids_with_feeds = array();
        foreach ( $feeds as $feed ) {
            if ( !empty( $feed->account_id ) ) {
                $account_ids_with_feeds[(int) $feed->account_id] = true;
            }
        }
        foreach ( $accounts as $account ) {
            $account_id = ( isset( $account->id ) ? (int) $account->id : 0 );
            if ( $account_id <= 0 || empty( $account_ids_with_feeds[$account_id] ) ) {
                continue;
            }
            $cache_key = ESF_TikTok_Cache::posts_cache_key( $account_id );
            $expires_at = ESF_TikTok_Cache::get_expires_at( $cache_key );
            if ( function_exists( 'esf_feed_cache_is_due_for_refresh' ) && !esf_feed_cache_is_due_for_refresh( $expires_at ) ) {
                continue;
                // still outside lead window
            }
            // Find any feed for this account to drive the warm path.
            foreach ( $feeds as $feed ) {
                if ( (int) $feed->account_id === $account_id ) {
                    $renderer->get_posts_for_feed_by_id( (int) $feed->id );
                    break;
                }
            }
        }
    }

    /**
     * Activation hook callback.
     *
     * @since 6.8.0
     * @return void
     */
    public static function on_activation() {
        require_once ESF_TIKTOK_DIR . 'includes/traits/trait-esf-tiktok-singleton.php';
        require_once ESF_TIKTOK_DIR . 'includes/database/class-esf-tiktok-db-installer.php';
        ESF_TikTok_DB_Installer::create_tables();
        update_option( 'esf_tiktok_db_schema_version', self::DB_SCHEMA_VERSION );
    }

    /**
     * Run idempotent DB schema migrations when needed.
     *
     * @since 6.8.0
     * @return void
     */
    private function maybe_migrate_db_schema() {
        $installed_version = (string) get_option( 'esf_tiktok_db_schema_version', '' );
        if ( ESF_TikTok_DB_Installer::tables_exist() && self::DB_SCHEMA_VERSION === $installed_version ) {
            return;
        }
        ESF_TikTok_DB_Installer::create_tables();
        update_option( 'esf_tiktok_db_schema_version', self::DB_SCHEMA_VERSION );
    }

    /**
     * Deactivation hook callback.
     *
     * @since 6.8.0
     * @return void
     */
    public static function on_deactivation() {
        if ( !defined( 'ESF_TIKTOK_DIR' ) ) {
            return;
        }
        require_once ESF_TIKTOK_DIR . 'includes/traits/trait-esf-tiktok-singleton.php';
        $token_manager = ESF_TIKTOK_DIR . 'includes/class-esf-tiktok-token-manager.php';
        if ( file_exists( $token_manager ) ) {
            require_once $token_manager;
            if ( class_exists( 'ESF_TikTok_Token_Manager' ) ) {
                ESF_TikTok_Token_Manager::get_instance()->unschedule_cron();
            }
        }
        $timestamp = wp_next_scheduled( self::CRON_CACHE_REFRESH_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_CACHE_REFRESH_HOOK );
        }
    }

}
