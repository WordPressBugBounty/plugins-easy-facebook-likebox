<?php

/**
 * YouTube Admin Class
 *
 * Handles all admin-related functionality for the YouTube module.
 * Manages menu registration, asset enqueuing, and dashboard rendering.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/Admin
 * @since 6.7.5
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_YouTube_Admin
 *
 * Manages WordPress admin integration for YouTube module.
 * Implements singleton pattern for single instance throughout request.
 *
 * @since 6.7.5
 */
class ESF_YouTube_Admin {
    use ESF_YouTube_Singleton;
    /**
     * Admin page hook suffix.
     *
     * @since 6.7.5
     * @var string
     */
    private $page_hook = '';

    /**
     * Constructor.
     *
     * Initializes admin hooks and checks.
     * Private to enforce singleton pattern.
     *
     * @since 6.7.5
     */
    private function __construct() {
        // Only initialize if module is active.
        if ( !$this->is_module_active() ) {
            return;
        }
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks.
     *
     * @since 6.7.5
     * @return void
     */
    private function init_hooks() {
        // Register admin menu.
        // Use priority 100 (same as Instagram) so it appears after Instagram but before Settings (101).
        add_action( 'admin_menu', array($this, 'register_menu'), 100 );
        // Enqueue admin assets.
        add_action( 'admin_enqueue_scripts', array($this, 'enqueue_assets') );
    }

    /**
     * Register admin menu.
     *
     * Adds YouTube submenu under Easy Social Feed main menu.
     * Position 100 ensures it appears after Facebook and Instagram.
     *
     * @since 6.7.5
     * @return void
     */
    public function register_menu() {
        $this->page_hook = add_submenu_page(
            'feed-them-all',
            __( 'YouTube', 'easy-facebook-likebox' ),
            __( 'YouTube', 'easy-facebook-likebox' ),
            'manage_options',
            'esf-youtube',
            array($this, 'render_dashboard_page'),
            3
        );
    }

    /**
     * Enqueue admin assets.
     *
     * Loads CSS and JavaScript only on YouTube admin pages.
     * Uses WordPress dependency system for proper loading order.
     *
     * @since 6.7.5
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_assets( $hook ) {
        // Only load on YouTube admin page.
        if ( 'easy-social-feed_page_esf-youtube' !== $hook ) {
            return;
        }
        // Check if React build exists.
        $asset_file = ESF_YOUTUBE_DIR . 'admin/assets/build/dashboard.asset.php';
        if ( file_exists( $asset_file ) ) {
            // Load React dashboard (Phase 2).
            $this->enqueue_react_dashboard( $asset_file );
        } else {
            // Load basic admin styles (Phase 1 - fallback).
            $this->enqueue_basic_assets();
        }
        // Enqueue common admin styles.
        $this->enqueue_common_styles();
    }

    /**
     * Enqueue React dashboard assets.
     *
     * Loads compiled React application with WordPress dependencies.
     *
     * @since 6.7.5
     * @param string $asset_file Path to asset file.
     * @return void
     */
    private function enqueue_react_dashboard( $asset_file ) {
        $asset = (include $asset_file);
        // Enqueue React dashboard script.
        wp_enqueue_script(
            'esf-youtube-dashboard',
            ESF_YOUTUBE_ASSETS_URL . 'build/dashboard.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );
        // Enqueue React dashboard styles.
        wp_enqueue_style(
            'esf-youtube-dashboard',
            ESF_YOUTUBE_ASSETS_URL . 'build/dashboard.css',
            array('wp-components'),
            $asset['version']
        );
        // Pass data to React.
        $this->localize_dashboard_script();
    }

    /**
     * Enqueue basic admin assets.
     *
     * Fallback for when React build doesn't exist yet.
     *
     * @since 6.7.5
     * @return void
     */
    private function enqueue_basic_assets() {
        // Enqueue basic admin CSS (compiled from SCSS).
        wp_enqueue_style(
            'esf-youtube-admin-basic',
            ESF_YOUTUBE_ASSETS_URL . 'css/esf-youtube-admin.css',
            array(),
            FTA_VERSION
        );
    }

    /**
     * Enqueue common admin styles.
     *
     * Styles that are always loaded on YouTube admin pages.
     *
     * @since 6.7.5
     * @return void
     */
    private function enqueue_common_styles() {
        // WordPress components styles.
        wp_enqueue_style( 'wp-components' );
        // Dashicons for icons.
        wp_enqueue_style( 'dashicons' );
    }

    /**
     * Localize dashboard script.
     *
     * Passes PHP data to JavaScript for React components.
     *
     * @since 6.7.5
     * @return void
     */
    private function localize_dashboard_script() {
        // Dashboard uses apiFetch (REST root/nonce from WordPress) and @wordpress/i18n.
        // Only data added by the filter is exposed.
        $repo = ESF_YouTube_Account_Repository::get_instance();
        $has_youtube_plan = function_exists( 'esf_youtube_has_youtube_plan' ) && esf_youtube_has_youtube_plan();
        $settings = esf_get_youtube_settings();
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
        $data = array(
            'hasYoutubePlan'       => $has_youtube_plan,
            'canAddAnotherAccount' => $has_youtube_plan || $repo->get_total_account_count() < 1,
            'upgradeUrl'           => ( function_exists( 'efl_fs' ) ? esc_url( efl_fs()->get_upgrade_url() ) : '' ),
            'defaultSettings'      => ESF_YouTube_Feed_Repository::get_default_settings(),
            'youtubeSettings'      => array(
                'cache_duration' => $cache_duration,
            ),
            'canUsePopup'          => $has_youtube_plan,
        );
        /**
         * Filters dashboard localization data.
         *
         * @since 6.7.5
         * @param array $data Dashboard data.
         */
        $data = apply_filters( 'esf_youtube_dashboard_data', $data );
        wp_localize_script( 'esf-youtube-dashboard', 'esfYouTubeData', $data );
    }

    /**
     * Render dashboard page.
     *
     * Includes the main dashboard template file.
     * When loaded as OAuth popup result (esf_yt_done=1), renders bridge HTML
     * so the popup can postMessage to opener and close.
     *
     * @since 6.7.5
     * @return void
     */
    public function render_dashboard_page() {
        // Check user capabilities.
        if ( !current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'easy-facebook-likebox' ) );
        }
        // OAuth popup bridge: minimal page that notifies opener and closes.
        if ( isset( $_GET['esf_yt_done'], $_GET['status'] ) && '1' === $_GET['esf_yt_done'] ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $this->render_oauth_bridge_page();
            return;
        }
        // Get module stats for display.
        $stats = ESF_YouTube_DB_Installer::get_table_stats();
        // Include dashboard view.
        include ESF_YOUTUBE_DIR . 'admin/views/html-admin-dashboard.php';
    }

    /**
     * Render OAuth bridge page for popup window.
     *
     * Outputs minimal HTML that posts connection result to window.opener
     * and closes the popup. Used when user returns from OAuth in a popup.
     *
     * @since 6.7.5
     * @return void
     */
    private function render_oauth_bridge_page() {
        $status = sanitize_text_field( wp_unslash( $_GET['status'] ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $allowed = array('connected', 'error', 'limit_reached');
        if ( !in_array( $status, $allowed, true ) ) {
            $status = 'error';
        }
        $success = 'connected' === $status;
        ?>
<!DOCTYPE html>
<html <?php 
        language_attributes();
        ?>>
<head>
	<meta charset="<?php 
        bloginfo( 'charset' );
        ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php 
        esc_html_e( 'Connecting…', 'easy-facebook-likebox' );
        ?></title>
</head>
<body>
	<p><?php 
        esc_html_e( 'Connection complete. This window will close automatically.', 'easy-facebook-likebox' );
        ?></p>
	<script>
	( function () {
		var status = <?php 
        echo wp_json_encode( $status );
        ?>;
		var success = <?php 
        echo wp_json_encode( $success );
        ?>;
		if ( window.opener && ! window.opener.closed ) {
			try {
				window.opener.postMessage( {
					source: 'esf-youtube-connect',
					success: success,
					status: status
				}, window.location.origin );
			} catch ( e ) {}
		}
		window.close();
	}() );
	</script>
</body>
</html>
		<?php 
    }

    /**
     * Check if module is active.
     *
     * @since 6.7.5
     * @return bool True if active, false otherwise.
     */
    private function is_module_active() {
        return esf_is_youtube_active();
    }

}
