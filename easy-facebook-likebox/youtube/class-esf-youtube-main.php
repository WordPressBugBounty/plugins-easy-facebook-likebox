<?php

/**
 * YouTube Module Main Class
 *
 * Bootstrap class that initializes the YouTube module.
 * Handles module lifecycle, hook registration, and component loading.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube
 * @since 6.7.5
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_YouTube_Main
 *
 * Main entry point for the YouTube module.
 * Implements singleton pattern and handles module initialization.
 *
 * @since 6.7.5
 */
class ESF_YouTube_Main {
    use ESF_YouTube_Singleton;
    /**
     * Constructor.
     *
     * Initializes the YouTube module.
     * Private to enforce singleton pattern.
     *
     * @since 6.7.5
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
        $this->maybe_initialize_components();
    }

    /**
     * Initialize WordPress hooks.
     *
     * Registers initialization and status hooks.
     *
     * @since 6.7.5
     * @return void
     */
    private function init_hooks() {
        // Initialize after WordPress is fully loaded.
        add_action( 'init', array($this, 'init'), 5 );
        // Module status check.
        add_action( 'init', array($this, 'check_module_status'), 10 );
        // Register REST API routes when REST API is initialized.
        add_action( 'rest_api_init', array($this, 'register_rest_routes') );
        // Register custom cron schedules.
        add_filter( 'cron_schedules', array($this, 'add_cron_schedules') );
        // Feed cache refresh cron (respects cache_duration and auto_refresh_cache).
        add_action( 'esf_youtube_refresh_feed_cache', array($this, 'run_feed_cache_refresh') );
    }

    /**
     * Load module dependencies.
     *
     * Includes required files based on context (admin/frontend).
     *
     * @since 6.7.5
     * @return void
     */
    private function load_dependencies() {
        // API services.
        require_once ESF_YOUTUBE_DIR . 'includes/api/class-esf-youtube-api-service.php';
        // Models / repositories.
        require_once ESF_YOUTUBE_DIR . 'includes/models/class-esf-youtube-account-repository.php';
        require_once ESF_YOUTUBE_DIR . 'includes/models/class-esf-youtube-feed-repository.php';
        // Token manager for auto-refresh.
        require_once ESF_YOUTUBE_DIR . 'includes/class-esf-youtube-token-manager.php';
        // Load admin components only in admin area.
        if ( is_admin() ) {
            require_once ESF_YOUTUBE_DIR . 'admin/classes/class-esf-youtube-admin.php';
            require_once ESF_YOUTUBE_DIR . 'includes/class-esf-youtube-auth-callback-listener.php';
        }
        // REST API controllers.
        if ( function_exists( 'register_rest_route' ) ) {
            require_once ESF_YOUTUBE_DIR . 'includes/api/class-esf-youtube-api-oauth.php';
            require_once ESF_YOUTUBE_DIR . 'includes/api/class-esf-youtube-api-accounts.php';
            require_once ESF_YOUTUBE_DIR . 'includes/api/class-esf-youtube-api-feeds.php';
            require_once ESF_YOUTUBE_DIR . 'includes/api/class-esf-youtube-api-refresh.php';
            require_once ESF_YOUTUBE_DIR . 'includes/api/class-esf-youtube-api-preview.php';
            require_once ESF_YOUTUBE_DIR . 'includes/api/class-esf-youtube-api-settings.php';
        }
        // Cache and frontend (shortcode, layouts, renderer).
        require_once ESF_YOUTUBE_DIR . 'includes/class-esf-youtube-cache.php';
        require_once ESF_YOUTUBE_DIR . 'includes/layouts/class-esf-youtube-layout-base.php';
        require_once ESF_YOUTUBE_DIR . 'includes/layouts/class-esf-youtube-layout-grid.php';
        require_once ESF_YOUTUBE_DIR . 'includes/layouts/class-esf-youtube-layout-registry.php';
        require_once ESF_YOUTUBE_DIR . 'includes/class-esf-youtube-renderer.php';
        require_once ESF_YOUTUBE_DIR . 'frontend/class-esf-youtube-frontend.php';
    }

    /**
     * Initialize module components.
     *
     * Only initializes if module is activated.
     *
     * @since 6.7.5
     * @return void
     */
    private function maybe_initialize_components() {
        if ( !$this->is_module_active() ) {
            return;
        }
        // Ensure database tables exist whenever the module is active.
        ESF_YouTube_DB_Installer::create_tables();
        // Initialize token manager for auto-refresh.
        if ( class_exists( 'ESF_YouTube_Token_Manager' ) ) {
            ESF_YouTube_Token_Manager::get_instance()->init();
        }
        // Schedule feed cache refresh cron if auto_refresh_cache is enabled.
        $this->maybe_schedule_feed_cache_refresh();
        // Initialize admin component.
        if ( is_admin() ) {
            if ( class_exists( 'ESF_YouTube_Admin' ) ) {
                ESF_YouTube_Admin::get_instance();
            }
            // Register auth callback listener.
            if ( class_exists( 'ESF_YouTube_Auth_Callback_Listener' ) ) {
                add_action( 'admin_init', array(ESF_YouTube_Auth_Callback_Listener::get_instance(), 'handle') );
            }
        }
        // Initialize frontend (shortcode, layout registry).
        if ( class_exists( 'ESF_YouTube_Frontend' ) ) {
            ESF_YouTube_Frontend::get_instance();
            do_action( 'esf_youtube_register_layouts' );
        }
    }

    /**
     * Initialize module.
     *
     * Called on WordPress 'init' hook.
     * Can be used for registering post types, taxonomies, etc.
     *
     * @since 6.7.5
     * @return void
     */
    public function init() {
        /**
         * Fires after YouTube module initialization.
         *
         * @since 6.7.5
         * @param ESF_YouTube_Main $this Main instance.
         */
        do_action( 'esf_youtube_init', $this );
    }

    /**
     * Register REST API routes.
     *
     * @since 6.7.5
     * @return void
     */
    public function register_rest_routes() {
        if ( !$this->is_module_active() ) {
            return;
        }
        // OAuth controller.
        if ( class_exists( 'ESF_YouTube_API_OAuth' ) ) {
            ESF_YouTube_API_OAuth::register_routes();
        }
        // Accounts controller.
        if ( class_exists( 'ESF_YouTube_API_Accounts' ) ) {
            ESF_YouTube_API_Accounts::register_routes();
        }
        // Feeds controller.
        if ( class_exists( 'ESF_YouTube_API_Feeds' ) ) {
            ESF_YouTube_API_Feeds::register_routes();
        }
        // Token refresh controller.
        if ( class_exists( 'ESF_YouTube_API_Refresh' ) ) {
            ESF_YouTube_API_Refresh::get_instance()->register_routes();
        }
        // Feed preview controller (dashboard live preview).
        if ( class_exists( 'ESF_YouTube_API_Preview' ) ) {
            ESF_YouTube_API_Preview::register_routes();
        }
        // Settings controller (cache duration, etc.).
        if ( class_exists( 'ESF_YouTube_API_Settings' ) ) {
            ESF_YouTube_API_Settings::register_routes();
        }
    }

    /**
     * Check module activation status.
     *
     * Monitors module status and performs cleanup if deactivated.
     *
     * @since 6.7.5
     * @return void
     */
    public function check_module_status() {
        if ( !$this->is_module_active() ) {
            // Module is deactivated - perform cleanup.
            $this->on_module_deactivate();
            return;
        }
        /**
         * Fires when YouTube module is active and running.
         *
         * @since 6.7.5
         */
        do_action( 'esf_youtube_active' );
    }

    /**
     * Plugin activation callback.
     *
     * Runs on plugin activation.
     * Creates database tables and sets default options.
     *
     * @since 6.7.5
     * @return void
     */
    public function on_activation() {
        // Create database tables.
        ESF_YouTube_DB_Installer::create_tables();
        // Set default options.
        $this->set_default_options();
        /**
         * Fires after YouTube module activation tasks.
         *
         * @since 6.7.5
         */
        do_action( 'esf_youtube_activated' );
    }

    /**
     * Module deactivation callback.
     *
     * Runs when module is deactivated from the modules page.
     * Cleans up transients and scheduled events.
     *
     * @since 6.7.5
     * @return void
     */
    private function on_module_deactivate() {
        // Clear scheduled cron events.
        $timestamp = wp_next_scheduled( 'esf_youtube_clean_cache' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'esf_youtube_clean_cache' );
        }
        $timestamp = wp_next_scheduled( 'esf_youtube_refresh_feed_cache' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'esf_youtube_refresh_feed_cache' );
        }
        // Unschedule token auto-refresh.
        if ( class_exists( 'ESF_YouTube_Token_Manager' ) ) {
            ESF_YouTube_Token_Manager::get_instance()->unschedule_cron();
        }
        /**
         * Fires when YouTube module is deactivated.
         *
         * @since 6.7.5
         */
        do_action( 'esf_youtube_deactivated' );
    }

    /**
     * Set default options.
     *
     * Creates default settings for the YouTube module.
     *
     * @since 6.7.5
     * @return void
     */
    private function set_default_options() {
        $defaults = array(
            'cache_duration'     => 43200,
            'auto_refresh_cache' => true,
            'debug_mode'         => false,
        );
        // Only add defaults if option doesn't exist.
        if ( false === get_option( 'esf_youtube_settings' ) ) {
            add_option( 'esf_youtube_settings', $defaults );
        }
    }

    /**
     * Check if module is currently active.
     *
     * @since 6.7.5
     * @return bool True if module is active, false otherwise.
     */
    public function is_module_active() {
        $fta_settings = get_option( 'fta_settings', array() );
        $status = ( isset( $fta_settings['plugins']['youtube']['status'] ) ? $fta_settings['plugins']['youtube']['status'] : 'activated' );
        return 'activated' === $status;
    }

    /**
     * Schedule feed cache refresh cron if auto_refresh_cache is enabled.
     * Recurrence matches the selected cache duration (e.g. every 6 hours if "Every 6 hours" is selected).
     *
     * @since 6.7.5
     * @return void
     */
    private function maybe_schedule_feed_cache_refresh() {
        $settings = esf_get_youtube_settings();
        $enabled = ( isset( $settings['auto_refresh_cache'] ) ? (bool) $settings['auto_refresh_cache'] : true );
        $cache_duration = ( isset( $settings['cache_duration'] ) ? (int) $settings['cache_duration'] : 43200 );
        $schedule_slug = $this->get_cache_refresh_schedule_slug( $cache_duration );
        if ( !$enabled ) {
            return;
        }
        $event = wp_get_scheduled_event( 'esf_youtube_refresh_feed_cache' );
        if ( $event && $event->schedule === $schedule_slug ) {
            return;
        }
        $timestamp = wp_next_scheduled( 'esf_youtube_refresh_feed_cache' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'esf_youtube_refresh_feed_cache' );
        }
        wp_schedule_event( time(), $schedule_slug, 'esf_youtube_refresh_feed_cache' );
    }

    /**
     * Map cache duration (seconds) to cron schedule slug for feed cache refresh.
     *
     * @since 6.7.5
     * @param int $cache_duration_secs Cache duration in seconds.
     * @return string Schedule slug (e.g. esf_youtube_6h).
     */
    private function get_cache_refresh_schedule_slug( $cache_duration_secs ) {
        $map = array(
            3600   => 'esf_youtube_1h',
            10800  => 'esf_youtube_3h',
            21600  => 'esf_youtube_6h',
            43200  => 'esf_youtube_12h',
            86400  => 'esf_youtube_24h',
            604800 => 'esf_youtube_7d',
        );
        return ( isset( $map[(int) $cache_duration_secs] ) ? $map[(int) $cache_duration_secs] : 'esf_youtube_12h' );
    }

    /**
     * Reschedule feed cache refresh cron (e.g. after cache_duration is changed in settings).
     * Call this from the settings API after updating cache_duration.
     *
     * @since 6.7.5
     * @return void
     */
    public function reschedule_feed_cache_refresh() {
        $timestamp = wp_next_scheduled( 'esf_youtube_refresh_feed_cache' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'esf_youtube_refresh_feed_cache' );
        }
        $this->maybe_schedule_feed_cache_refresh();
    }

    /**
     * Cron callback: refresh feed caches that have expired (respects cache_duration).
     *
     * Only refreshes feeds whose cache is missing or expired. Uses existing Renderer
     * to fetch from API and set cache. Respects auto_refresh_cache setting.
     *
     * @since 6.7.5
     * @return void
     */
    public function run_feed_cache_refresh() {
        $settings = esf_get_youtube_settings();
        $enabled = ( isset( $settings['auto_refresh_cache'] ) ? (bool) $settings['auto_refresh_cache'] : true );
        if ( !$enabled ) {
            return;
        }
        $cache_duration = ( isset( $settings['cache_duration'] ) ? (int) $settings['cache_duration'] : 43200 );
        if ( $cache_duration <= 0 ) {
            $cache_duration = 43200;
        }
        // Refresh feed caches (videos) that have expired.
        $repo = ESF_YouTube_Feed_Repository::get_instance();
        $feeds = $repo->get_all( array(
            'status' => 'active',
        ) );
        if ( !empty( $feeds ) ) {
            $now_utc = current_time( 'mysql', true );
            $renderer = new ESF_YouTube_Renderer();
            foreach ( $feeds as $feed ) {
                $feed_id = (int) $feed->id;
                $account_id = ( isset( $feed->account_id ) ? (int) $feed->account_id : 0 );
                if ( $feed_id <= 0 || $account_id <= 0 ) {
                    continue;
                }
                $cache_key = 'esf_yt_videos_' . $feed_id . '_' . $account_id;
                $expires_at = ESF_YouTube_Cache::get_expires_at( $cache_key );
                if ( null !== $expires_at && $expires_at > $now_utc ) {
                    continue;
                }
                $renderer->get_videos_for_feed_by_id( $feed_id );
            }
        }
        // Refresh account stats (subscriber_count, description, etc.) for accounts due by cache_duration.
        $account_repo = ESF_YouTube_Account_Repository::get_instance();
        $accounts_due = $account_repo->get_accounts_due_for_stats_refresh( $cache_duration );
        $token_manager = ESF_YouTube_Token_Manager::get_instance();
        $api_service = ESF_YouTube_API_Service::get_instance();
        foreach ( $accounts_due as $account ) {
            $access_token = $token_manager->get_valid_access_token( (int) $account->id );
            if ( empty( $access_token ) ) {
                continue;
            }
            $channel_data = $api_service->validate_token_and_fetch_channel( $access_token );
            if ( is_wp_error( $channel_data ) ) {
                continue;
            }
            $account_repo->update_channel_stats( (int) $account->id, $channel_data );
        }
    }

    /**
     * Add custom cron schedules.
     *
     * Adds 30-minute interval for token auto-refresh and cache-duration intervals for feed cache refresh.
     *
     * @since 6.7.5
     *
     * @param array $schedules Existing cron schedules.
     *
     * @return array Modified cron schedules.
     */
    public function add_cron_schedules( $schedules ) {
        if ( !isset( $schedules['thirty_minutes'] ) ) {
            $schedules['thirty_minutes'] = array(
                'interval' => 1800,
                'display'  => __( 'Every 30 Minutes', 'easy-facebook-likebox' ),
            );
        }
        $cache_schedules = array(
            'esf_youtube_1h'  => array(
                'interval' => 3600,
                'display'  => __( 'Every 1 Hour', 'easy-facebook-likebox' ),
            ),
            'esf_youtube_3h'  => array(
                'interval' => 10800,
                'display'  => __( 'Every 3 Hours', 'easy-facebook-likebox' ),
            ),
            'esf_youtube_6h'  => array(
                'interval' => 21600,
                'display'  => __( 'Every 6 Hours', 'easy-facebook-likebox' ),
            ),
            'esf_youtube_12h' => array(
                'interval' => 43200,
                'display'  => __( 'Every 12 Hours', 'easy-facebook-likebox' ),
            ),
            'esf_youtube_24h' => array(
                'interval' => 86400,
                'display'  => __( 'Every 24 Hours', 'easy-facebook-likebox' ),
            ),
            'esf_youtube_7d'  => array(
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

}
