<?php

/**
 * Twitter Admin Class
 *
 * Registers the Twitter admin menu and enqueues assets for the
 * React SPA dashboard. Mirrors the structure of ESF_YouTube_Admin.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/Admin
 * @since 6.7.6
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Twitter_Admin
 *
 * @since 6.7.6
 */
class ESF_Twitter_Admin {
    use ESF_Twitter_Singleton;
    /**
     * Admin page hook suffix.
     *
     * @since 6.7.6
     * @var string
     */
    private $page_hook = '';

    /**
     * Constructor.
     *
     * @since 6.7.6
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Register WordPress hooks.
     *
     * @since 6.7.6
     * @return void
     */
    private function init_hooks() {
        add_action( 'admin_menu', array($this, 'register_menu'), 105 );
        add_action( 'admin_enqueue_scripts', array($this, 'enqueue_assets') );
    }

    /**
     * Register admin submenu.
     *
     * @since 6.7.6
     * @return void
     */
    public function register_menu() {
        $this->page_hook = add_submenu_page(
            'feed-them-all',
            __( 'X / Twitter', 'easy-facebook-likebox' ),
            __( 'X / Twitter', 'easy-facebook-likebox' ),
            'manage_options',
            'esf-twitter',
            array($this, 'render_dashboard_page'),
            4
        );
    }

    /**
     * Enqueue admin assets.
     *
     * @since 6.7.6
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_assets( $hook ) {
        if ( 'easy-social-feed_page_esf-twitter' !== $hook ) {
            return;
        }
        $asset_file = ESF_TWITTER_DIR . 'admin/assets/build/dashboard.asset.php';
        if ( file_exists( $asset_file ) ) {
            $this->enqueue_react_dashboard( $asset_file );
        } else {
            $this->enqueue_basic_assets();
        }
        wp_enqueue_style( 'wp-components' );
        wp_enqueue_style( 'dashicons' );
    }

    /**
     * Enqueue compiled React dashboard.
     *
     * @since 6.7.6
     * @param string $asset_file Path to the Webpack asset manifest.
     * @return void
     */
    private function enqueue_react_dashboard( $asset_file ) {
        $asset = (include $asset_file);
        wp_enqueue_script(
            'esf-twitter-dashboard',
            ESF_TWITTER_URL . 'admin/assets/build/dashboard.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );
        wp_enqueue_style(
            'esf-twitter-dashboard',
            ESF_TWITTER_URL . 'admin/assets/build/dashboard.css',
            array('wp-components'),
            $asset['version']
        );
        $this->localize_dashboard_script();
    }

    /**
     * Fallback basic assets when the React build is missing.
     *
     * @since 6.7.6
     * @return void
     */
    private function enqueue_basic_assets() {
        $css_path = ESF_TWITTER_DIR . 'admin/assets/css/esf-twitter-admin.css';
        if ( file_exists( $css_path ) ) {
            wp_enqueue_style(
                'esf-twitter-admin',
                ESF_TWITTER_URL . 'admin/assets/css/esf-twitter-admin.css',
                array(),
                ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' )
            );
        }
    }

    /**
     * Localize data for the React dashboard.
     *
     * @since 6.7.6
     * @return void
     */
    private function localize_dashboard_script() {
        $repo = ESF_Twitter_Account_Repository::get_instance();
        $has_twitter_plan = function_exists( 'esf_twitter_has_twitter_plan' ) && esf_twitter_has_twitter_plan();
        $has_load_more_plan = $has_twitter_plan;
        $settings = esf_get_twitter_settings();
        $cache_duration = ( isset( $settings['cache_duration'] ) ? (int) $settings['cache_duration'] : 43200 );
        $allowed_durations = array(
            3600,
            10800,
            21600,
            43200,
            86400,
            604800
        );
        if ( !in_array( $cache_duration, $allowed_durations, true ) ) {
            $cache_duration = 43200;
        }
        if ( !$has_twitter_plan ) {
            $cache_duration = 604800;
        }
        $data = array(
            'hasTwitterPlan'                => $has_twitter_plan,
            'hasTwitterLoadMorePlan'        => $has_load_more_plan,
            'canAddAnotherConnectedAccount' => $has_twitter_plan || $repo->get_total_connected_account_count() < 1,
            'upgradeUrl'                    => ( function_exists( 'efl_fs' ) ? esc_url( efl_fs()->get_upgrade_url() ) : '' ),
            'defaultSettings'               => ESF_Twitter_Feed_Repository::get_default_settings(),
            'twitterSettings'               => array(
                'cache_duration'      => $cache_duration,
                'notify_token_expiry' => !empty( $settings['notify_token_expiry'] ),
            ),
        );
        /**
         * Filter data passed to the Twitter React dashboard.
         *
         * @since 6.7.6
         * @param array $data Localised data.
         */
        $data = apply_filters( 'esf_twitter_dashboard_data', $data );
        wp_localize_script( 'esf-twitter-dashboard', 'esfTwitterData', $data );
    }

    /**
     * Render the dashboard page HTML shell.
     *
     * React mounts on #esf-twitter-dashboard.
     *
     * @since 6.7.6
     * @return void
     */
    public function render_dashboard_page() {
        require_once ESF_TWITTER_DIR . 'admin/views/dashboard.php';
    }

}
