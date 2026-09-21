<?php

/**
 * Facebook Admin Class
 *
 * Registers the modern Facebook admin menu and enqueues the React SPA dashboard.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/Admin
 * @since 6.7.9
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Facebook_Admin
 *
 * @since 6.7.9
 */
class ESF_Facebook_Admin {
    use ESF_Facebook_Singleton;
    /**
     * Admin page hook suffix.
     *
     * @since 6.7.9
     * @var string
     */
    private $page_hook = '';

    /**
     * Constructor.
     *
     * @since 6.7.9
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Register WordPress hooks.
     *
     * @since 6.7.9
     * @return void
     */
    private function init_hooks() {
        add_action( 'admin_menu', array($this, 'register_menu'), 105 );
        add_action( 'admin_enqueue_scripts', array($this, 'enqueue_assets') );
    }

    /**
     * Register admin submenu.
     *
     * @since 6.7.9
     * @return void
     */
    public function register_menu() {
        $position = ( class_exists( 'ESF_Admin_Menu_Order' ) ? ESF_Admin_Menu_Order::FACEBOOK : null );
        $parent = ( class_exists( 'ESF_Admin_Menu_Order' ) ? ESF_Admin_Menu_Order::PARENT_SLUG : 'easy-social-feed' );
        $this->page_hook = add_submenu_page(
            $parent,
            __( 'Facebook', 'easy-facebook-likebox' ),
            __( 'Facebook', 'easy-facebook-likebox' ),
            'manage_options',
            'esf-facebook',
            array($this, 'render_dashboard_page'),
            $position
        );
    }

    /**
     * Plugin base directory (modern constants with legacy fallback).
     *
     * @since 6.7.9
     * @return string
     */
    private function get_plugin_dir() {
        if ( defined( 'ESF_FACEBOOK_DIR' ) ) {
            return ESF_FACEBOOK_DIR;
        }
        if ( defined( 'EFBL_PLUGIN_DIR' ) ) {
            return EFBL_PLUGIN_DIR;
        }
        return dirname( dirname( __DIR__ ) ) . '/';
    }

    /**
     * Plugin base URL.
     *
     * @since 6.7.9
     * @return string
     */
    private function get_plugin_url() {
        if ( defined( 'ESF_FACEBOOK_URL' ) ) {
            return ESF_FACEBOOK_URL;
        }
        if ( defined( 'EFBL_PLUGIN_URL' ) ) {
            return EFBL_PLUGIN_URL;
        }
        return '';
    }

    /**
     * Enqueue admin assets on the Facebook page only.
     *
     * @since 6.7.9
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_assets( $hook ) {
        $dashboard_hooks = array('easy-social-feed_page_esf-facebook', 'feed-them-all_page_esf-facebook');
        if ( !in_array( $hook, $dashboard_hooks, true ) ) {
            return;
        }
        $dir = $this->get_plugin_dir();
        $asset_file = $dir . 'admin/assets/build/dashboard.asset.php';
        if ( file_exists( $asset_file ) ) {
            $this->enqueue_react_dashboard( $asset_file );
        } else {
            $this->enqueue_basic_assets();
        }
        wp_enqueue_style( 'wp-components' );
        wp_enqueue_style( 'dashicons' );
        // Free customize preview: shared simple popup (Pro uses FancyBox below).
        if ( function_exists( 'esf_facebook_has_facebook_plan' ) && !esf_facebook_has_facebook_plan() ) {
            if ( function_exists( 'esf_facebook_enqueue_free_popup_assets' ) ) {
                esf_facebook_enqueue_free_popup_assets();
            }
        }
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan() ) {
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
     * @since 6.7.9
     * @param string $asset_file Path to the Webpack asset manifest.
     * @return void
     */
    private function enqueue_react_dashboard( $asset_file ) {
        $asset = (include $asset_file);
        $url = $this->get_plugin_url();
        $dir = $this->get_plugin_dir();
        wp_enqueue_script(
            'esf-facebook-dashboard',
            $url . 'admin/assets/build/dashboard.js',
            ( isset( $asset['dependencies'] ) ? $asset['dependencies'] : array() ),
            ( isset( $asset['version'] ) ? $asset['version'] : (( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' )) ),
            true
        );
        $css_file = $dir . 'admin/assets/build/dashboard.css';
        if ( file_exists( $css_file ) ) {
            wp_enqueue_style(
                'esf-facebook-dashboard',
                $url . 'admin/assets/build/dashboard.css',
                array('wp-components'),
                ( isset( $asset['version'] ) ? $asset['version'] : (( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' )) )
            );
        }
        $this->localize_dashboard_script();
    }

    /**
     * Fallback when React build is missing.
     *
     * @since 6.7.9
     * @return void
     */
    private function enqueue_basic_assets() {
        $dir = $this->get_plugin_dir();
        $url = $this->get_plugin_url();
        $css_path = $dir . 'admin/assets/css/esf-facebook-admin.css';
        if ( file_exists( $css_path ) ) {
            wp_enqueue_style(
                'esf-facebook-admin',
                $url . 'admin/assets/css/esf-facebook-admin.css',
                array(),
                ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' )
            );
        }
    }

    /**
     * Localize data for the React dashboard (`esfFacebookData`).
     *
     * @since 6.7.9
     * @return void
     */
    private function localize_dashboard_script() {
        $page_repo = ( class_exists( 'ESF_Facebook_Page_Repository' ) ? ESF_Facebook_Page_Repository::get_instance() : null );
        $account_repo = ( class_exists( 'ESF_Facebook_Account_Repository' ) ? ESF_Facebook_Account_Repository::get_instance() : null );
        $has_plan = function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan();
        $can_multifeed = function_exists( 'esf_facebook_can_use_multifeed' ) && esf_facebook_can_use_multifeed();
        $settings = ( function_exists( 'esf_get_facebook_settings' ) ? esf_get_facebook_settings() : array() );
        $cache_duration = ( isset( $settings['cache_duration'] ) ? (int) $settings['cache_duration'] : 43200 );
        $page_count = 0;
        if ( $page_repo && method_exists( $page_repo, 'get_all' ) ) {
            $pages = $page_repo->get_all();
            $page_count = ( is_array( $pages ) ? count( $pages ) : 0 );
        }
        $account_count = 0;
        $needs_reconnect = false;
        if ( $account_repo && method_exists( $account_repo, 'get_all' ) ) {
            $account_rows = $account_repo->get_all();
            $account_count = ( is_array( $account_rows ) ? count( $account_rows ) : 0 );
            foreach ( (array) $account_rows as $account_row ) {
                $status = ( is_object( $account_row ) && isset( $account_row->status ) ? (string) $account_row->status : '' );
                if ( in_array( $status, array('expired', 'invalid'), true ) ) {
                    $needs_reconnect = true;
                    break;
                }
            }
        }
        $can_add_another_account = $has_plan || $account_count < 1 || $needs_reconnect;
        $pro_promo = ( function_exists( 'esf_get_pro_promo_offer' ) ? esf_get_pro_promo_offer() : array(
            'discount' => '17%',
            'coupon'   => 'ESPF17',
        ) );
        $layouts = array();
        if ( class_exists( '\\EasySocialFeed\\Layouts\\LayoutRegistry' ) ) {
            $layouts = \EasySocialFeed\Layouts\LayoutRegistry::for( 'facebook' )->to_array();
        }
        $upgrade_url = ( function_exists( 'esf_get_upgrade_url' ) ? esc_url( esf_get_upgrade_url( 'facebook' ) ) : '' );
        // Multifeed upgrade URL: Point to Pro pricing (native feature in 6.10.0+).
        $multifeed_upgrade_url = ( class_exists( 'ESF_Multifeed' ) ? esc_url( ESF_Multifeed::get_upgrade_url( 'facebook' ) ) : esc_url( esf_get_upgrade_url( 'facebook_premium' ) ) );
        $admin_url = ( function_exists( 'esf_get_facebook_admin_url' ) ? esf_get_facebook_admin_url() : admin_url( 'admin.php?page=esf-facebook' ) );
        $pages_bootstrap = array();
        if ( $page_repo && method_exists( $page_repo, 'get_all' ) ) {
            foreach ( (array) $page_repo->get_all() as $row ) {
                if ( is_object( $row ) && method_exists( $page_repo, 'format_public' ) ) {
                    $row = ESF_Facebook_Page_Repository::format_public( $row );
                }
                if ( !is_array( $row ) ) {
                    continue;
                }
                $pages_bootstrap[] = array(
                    'id'                 => ( isset( $row['id'] ) ? (int) $row['id'] : 0 ),
                    'account_id'         => ( isset( $row['account_id'] ) ? (int) $row['account_id'] : 0 ),
                    'page_id'            => ( isset( $row['page_id'] ) ? (string) $row['page_id'] : '' ),
                    'page_name'          => ( isset( $row['page_name'] ) ? (string) $row['page_name'] : '' ),
                    'username'           => ( isset( $row['username'] ) ? (string) $row['username'] : '' ),
                    'page_link'          => ( isset( $row['page_link'] ) ? (string) $row['page_link'] : '' ),
                    'category'           => ( isset( $row['category'] ) ? (string) $row['category'] : '' ),
                    'fan_count'          => ( isset( $row['fan_count'] ) ? (int) $row['fan_count'] : 0 ),
                    'profile_image_url'  => ( isset( $row['profile_image_url'] ) ? (string) $row['profile_image_url'] : '' ),
                    'status'             => ( isset( $row['status'] ) ? (string) $row['status'] : '' ),
                    'stats_refreshed_at' => ( isset( $row['stats_refreshed_at'] ) ? (string) $row['stats_refreshed_at'] : '' ),
                );
            }
        }
        $feeds_bootstrap = array();
        if ( class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
            $feed_repo = ESF_Facebook_Feed_Repository::get_instance();
            if ( method_exists( $feed_repo, 'get_all' ) ) {
                foreach ( (array) $feed_repo->get_all() as $feed ) {
                    if ( !is_array( $feed ) ) {
                        continue;
                    }
                    $feeds_bootstrap[] = array(
                        'id'           => ( isset( $feed['id'] ) ? (int) $feed['id'] : 0 ),
                        'name'         => ( isset( $feed['name'] ) ? (string) $feed['name'] : '' ),
                        'page_id'      => ( isset( $feed['page_id'] ) ? (int) $feed['page_id'] : 0 ),
                        'content_type' => ( isset( $feed['content_type'] ) ? (string) $feed['content_type'] : 'feed' ),
                        'status'       => ( isset( $feed['status'] ) ? (string) $feed['status'] : '' ),
                    );
                }
            }
        }
        $data = array(
            'restUrl'              => esc_url_raw( rest_url( 'esf/v1/facebook' ) ),
            'nonce'                => wp_create_nonce( 'wp_rest' ),
            'adminUrl'             => esc_url( $admin_url ),
            'hasFacebookPlan'      => $has_plan,
            'hasMultifeed'         => $can_multifeed,
            'canUseMultifeed'      => $can_multifeed,
            'multifeedUpgradeUrl'  => $multifeed_upgrade_url,
            'canAddAnotherAccount' => $can_add_another_account,
            'canAddAnotherPage'    => $can_add_another_account,
            'upgradeUrl'           => $upgrade_url,
            'currentPlan'          => ( function_exists( 'esf_get_active_plan_slug' ) ? esf_get_active_plan_slug() : '' ),
            'needsComboUpgrade'    => function_exists( 'esf_needs_combo_upgrade_for_module' ) && esf_needs_combo_upgrade_for_module( 'facebook' ),
            'proDiscount'          => ( isset( $pro_promo['discount'] ) ? (string) $pro_promo['discount'] : '17%' ),
            'proCoupon'            => ( isset( $pro_promo['coupon'] ) ? (string) $pro_promo['coupon'] : 'ESPF17' ),
            'defaultSettings'      => ( class_exists( 'ESF_Facebook_Feed_Repository' ) ? ESF_Facebook_Feed_Repository::normalize_settings( array() ) : array() ),
            'layouts'              => $layouts,
            'pages'                => $pages_bootstrap,
            'feeds'                => $feeds_bootstrap,
            'facebookSettings'     => array(
                'cache_duration'         => $cache_duration,
                'notify_token_reconnect' => !empty( $settings['notify_token_reconnect'] ),
            ),
            'locales'              => ( function_exists( 'efbl_get_locales' ) ? efbl_get_locales() : array(
                'en_US' => 'English (US)',
            ) ),
            'i18n'                 => array(
                'pages'    => __( 'Pages', 'easy-facebook-likebox' ),
                'feeds'    => __( 'Feeds', 'easy-facebook-likebox' ),
                'settings' => __( 'Settings', 'easy-facebook-likebox' ),
            ),
            'pageCount'            => $page_count,
        );
        /**
         * Filter data passed to the Facebook React dashboard.
         *
         * @since 6.7.9
         * @param array $data Localised data.
         */
        $data = apply_filters( 'esf_facebook_dashboard_data', $data );
        wp_localize_script( 'esf-facebook-dashboard', 'esfFacebookData', $data );
    }

    /**
     * Render the Facebook admin page.
     *
     * @since 6.7.9
     * @return void
     */
    public function render_dashboard_page() {
        if ( !current_user_can( 'manage_options' ) ) {
            return;
        }
        $view = $this->get_plugin_dir() . 'admin/views/dashboard.php';
        if ( file_exists( $view ) ) {
            include $view;
            return;
        }
        echo '<div class="wrap esf-dsh-wrap esf-facebook-admin"><hr class="wp-header-end" /><div id="esf-facebook-dashboard"></div></div>';
    }

}
