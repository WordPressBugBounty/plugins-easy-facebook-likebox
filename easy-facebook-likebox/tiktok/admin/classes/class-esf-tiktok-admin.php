<?php

/**
 * TikTok Admin Class
 *
 * Registers the TikTok admin menu and enqueues the React SPA dashboard.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok/Admin
 * @since 6.8.0
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_TikTok_Admin
 *
 * @since 6.8.0
 */
class ESF_TikTok_Admin {
    use ESF_TikTok_Singleton;
    /**
     * Admin page hook suffix.
     *
     * @since 6.8.0
     * @var string
     */
    private $page_hook = '';

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
        add_action( 'admin_menu', array($this, 'register_menu'), 105 );
        add_action( 'admin_enqueue_scripts', array($this, 'enqueue_assets') );
    }

    /**
     * Register admin submenu.
     *
     * @since 6.8.0
     * @return void
     */
    public function register_menu() {
        $position = ( class_exists( 'ESF_Admin_Menu_Order' ) ? ESF_Admin_Menu_Order::TIKTOK : null );
        $parent = ( class_exists( 'ESF_Admin_Menu_Order' ) ? ESF_Admin_Menu_Order::PARENT_SLUG : 'easy-social-feed' );
        $this->page_hook = add_submenu_page(
            $parent,
            __( 'TikTok', 'easy-facebook-likebox' ),
            __( 'TikTok', 'easy-facebook-likebox' ),
            'manage_options',
            'esf-tiktok',
            array($this, 'render_dashboard_page'),
            $position
        );
    }

    /**
     * Enqueue admin assets on the TikTok page only.
     *
     * @since 6.8.0
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_assets( $hook ) {
        if ( 'easy-social-feed_page_esf-tiktok' !== $hook && 'feed-them-all_page_esf-tiktok' !== $hook ) {
            return;
        }
        $asset_file = ESF_TIKTOK_DIR . 'admin/assets/build/dashboard.asset.php';
        if ( file_exists( $asset_file ) ) {
            $this->enqueue_react_dashboard( $asset_file );
        } else {
            $this->enqueue_basic_assets();
        }
        wp_enqueue_style( 'wp-components' );
        wp_enqueue_style( 'dashicons' );
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_tiktok_has_tiktok_plan' ) && esf_tiktok_has_tiktok_plan() ) {
                if ( method_exists( $this, 'enqueue_dashboard_premium_script__premium_only' ) ) {
                    $this->enqueue_dashboard_premium_script__premium_only();
                }
                if ( method_exists( $this, 'enqueue_preview_lightbox_assets__premium_only' ) ) {
                    $this->enqueue_preview_lightbox_assets__premium_only();
                }
            }
        }
    }

    /**
     * Enqueue compiled React dashboard.
     *
     * @since 6.8.0
     * @param string $asset_file Path to the Webpack asset manifest.
     * @return void
     */
    private function enqueue_react_dashboard( $asset_file ) {
        $asset = (include $asset_file);
        wp_enqueue_script(
            'esf-tiktok-dashboard',
            ESF_TIKTOK_URL . 'admin/assets/build/dashboard.js',
            ( isset( $asset['dependencies'] ) ? $asset['dependencies'] : array() ),
            ( isset( $asset['version'] ) ? $asset['version'] : (( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' )) ),
            true
        );
        $css_file = ESF_TIKTOK_DIR . 'admin/assets/build/dashboard.css';
        if ( file_exists( $css_file ) ) {
            wp_enqueue_style(
                'esf-tiktok-dashboard',
                ESF_TIKTOK_URL . 'admin/assets/build/dashboard.css',
                array('wp-components'),
                ( isset( $asset['version'] ) ? $asset['version'] : (( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' )) )
            );
        }
        $this->localize_dashboard_script();
    }

    /**
     * Fallback when React build is missing.
     *
     * @since 6.8.0
     * @return void
     */
    private function enqueue_basic_assets() {
        $css_path = ESF_TIKTOK_DIR . 'admin/assets/css/esf-tiktok-admin.css';
        if ( file_exists( $css_path ) ) {
            wp_enqueue_style(
                'esf-tiktok-admin',
                ESF_TIKTOK_URL . 'admin/assets/css/esf-tiktok-admin.css',
                array(),
                ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' )
            );
        }
    }

    /**
     * Localize data for the React dashboard (`esfTikTokData`).
     *
     * @since 6.8.0
     * @return void
     */
    private function localize_dashboard_script() {
        $repo = ESF_TikTok_Account_Repository::get_instance();
        $has_plan = function_exists( 'esf_tiktok_has_tiktok_plan' ) && esf_tiktok_has_tiktok_plan();
        $settings = ( function_exists( 'esf_get_tiktok_settings' ) ? esf_get_tiktok_settings() : array() );
        $cache_duration = ( isset( $settings['cache_duration'] ) ? (int) $settings['cache_duration'] : 43200 );
        $account_count = ( method_exists( $repo, 'get_total_connected_account_count' ) ? (int) $repo->get_total_connected_account_count() : count( $repo->get_all() ) );
        $needs_reconnect = false;
        if ( $account_count > 0 && function_exists( 'esf_tiktok_account_needs_reconnect' ) ) {
            foreach ( $repo->get_all() as $row ) {
                if ( esf_tiktok_account_needs_reconnect( $row ) ) {
                    $needs_reconnect = true;
                    break;
                }
            }
        }
        $pro_promo = ( function_exists( 'esf_get_pro_promo_offer' ) ? esf_get_pro_promo_offer() : array(
            'discount' => '17%',
            'coupon'   => 'ESPF17',
        ) );
        $layouts = array();
        if ( class_exists( '\\EasySocialFeed\\Layouts\\LayoutRegistry' ) ) {
            $layouts = \EasySocialFeed\Layouts\LayoutRegistry::for( 'tiktok' )->to_array();
        }
        $upgrade_url = ( function_exists( 'esf_get_upgrade_url' ) ? esc_url( esf_get_upgrade_url( 'tiktok' ) ) : '' );
        $data = array(
            'hasTikTokPlan'        => $has_plan,
            'canAddAnotherAccount' => $has_plan || $account_count < 1 || $needs_reconnect,
            'upgradeUrl'           => $upgrade_url,
            'currentPlan'          => ( function_exists( 'esf_get_active_plan_slug' ) ? esf_get_active_plan_slug() : '' ),
            'needsComboUpgrade'    => function_exists( 'esf_needs_combo_upgrade_for_module' ) && esf_needs_combo_upgrade_for_module( 'tiktok' ),
            'proDiscount'          => ( isset( $pro_promo['discount'] ) ? (string) $pro_promo['discount'] : '17%' ),
            'proCoupon'            => ( isset( $pro_promo['coupon'] ) ? (string) $pro_promo['coupon'] : 'ESPF17' ),
            'defaultSettings'      => ( class_exists( 'ESF_TikTok_Feed_Repository' ) ? ESF_TikTok_Feed_Repository::get_default_settings() : array() ),
            'layouts'              => $layouts,
            'tiktokSettings'       => array(
                'cache_duration' => $cache_duration,
            ),
        );
        /**
         * Filter data passed to the TikTok React dashboard.
         *
         * @since 6.8.0
         * @param array $data Localised data.
         */
        $data = apply_filters( 'esf_tiktok_dashboard_data', $data );
        wp_localize_script( 'esf-tiktok-dashboard', 'esfTikTokData', $data );
    }

    /**
     * Render the TikTok admin page.
     *
     * @since 6.8.0
     * @return void
     */
    public function render_dashboard_page() {
        if ( !current_user_can( 'manage_options' ) ) {
            return;
        }
        $view = ESF_TIKTOK_DIR . 'admin/views/dashboard.php';
        if ( file_exists( $view ) ) {
            include $view;
            return;
        }
        echo '<div class="wrap esf-tiktok-admin"><div id="esf-tiktok-dashboard"></div></div>';
    }

}
