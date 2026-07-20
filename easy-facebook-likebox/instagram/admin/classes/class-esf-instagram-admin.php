<?php

/**
 * Instagram Admin Class (Modern System)
 *
 * Registers the new Instagram dashboard page at ?page=esf-instagram.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/Admin
 * @since 6.8.0
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Instagram_Admin
 *
 * @since 6.8.0
 */
class ESF_Instagram_Admin {
    use ESF_Instagram_Singleton;
    /**
     * Admin page hook.
     *
     * @var string
     */
    private $page_hook = '';

    /**
     * Constructor.
     */
    private function __construct() {
        add_action( 'admin_menu', array($this, 'register_menu'), 105 );
        add_action( 'admin_enqueue_scripts', array($this, 'enqueue_assets') );
        add_action( 'admin_notices', array($this, 'maybe_render_reconnect_admin_notice') );
    }

    /**
     * Register modern Instagram submenu.
     *
     * @return void
     */
    public function register_menu() {
        $this->page_hook = add_submenu_page(
            ESF_Admin_Menu_Order::PARENT_SLUG,
            __( 'Instagram', 'easy-facebook-likebox' ),
            __( 'Instagram', 'easy-facebook-likebox' ),
            'manage_options',
            'esf-instagram',
            array($this, 'render_dashboard_page'),
            ESF_Admin_Menu_Order::INSTAGRAM
        );
    }

    /**
     * Enqueue dashboard assets.
     *
     * @param string $hook Current admin hook.
     * @return void
     */
    public function enqueue_assets( $hook ) {
        // Submenu is under `feed-them-all`; keep legacy id if the parent slug ever differed.
        $dashboard_hooks = array('feed-them-all_page_esf-instagram', 'easy-social-feed_page_esf-instagram');
        if ( !in_array( $hook, $dashboard_hooks, true ) ) {
            return;
        }
        $asset_file = ESF_INSTAGRAM_DIR . 'admin/assets/build/dashboard.asset.php';
        $css_file = ESF_INSTAGRAM_DIR . 'admin/assets/build/dashboard.css';
        $css_version = ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );
        if ( file_exists( $asset_file ) ) {
            $asset = (include $asset_file);
            if ( isset( $asset['version'] ) ) {
                $css_version = $asset['version'];
            }
            wp_enqueue_script(
                'esf-instagram-dashboard',
                ESF_INSTAGRAM_URL . 'admin/assets/build/dashboard.js',
                ( isset( $asset['dependencies'] ) ? $asset['dependencies'] : array() ),
                ( isset( $asset['version'] ) ? $asset['version'] : (( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' )) ),
                true
            );
            if ( file_exists( $css_file ) ) {
                wp_enqueue_style(
                    'esf-instagram-dashboard',
                    ESF_INSTAGRAM_URL . 'admin/assets/build/dashboard.css',
                    array('wp-components'),
                    $css_version
                );
            }
            $has_instagram_plan = function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan();
            $pro_promo = ( function_exists( 'esf_get_pro_promo_offer' ) ? esf_get_pro_promo_offer() : array(
                'discount' => '17%',
                'coupon'   => 'ESPF17',
            ) );
            $layouts = array();
            if ( class_exists( '\\EasySocialFeed\\Layouts\\LayoutRegistry' ) ) {
                $layouts = \EasySocialFeed\Layouts\LayoutRegistry::for( 'instagram' )->to_array();
            }
            wp_localize_script( 'esf-instagram-dashboard', 'esfInstagramData', array(
                'hasInstagramPlan'    => $has_instagram_plan,
                'hasMultifeed'        => function_exists( 'esf_instagram_multifeed_active' ) && esf_instagram_multifeed_active(),
                'multifeedUpgradeUrl' => esc_url( admin_url( 'admin.php?slug=esf-multifeed&page=feed-them-all-addons' ) ),
                'upgradeUrl'          => esc_url( ( function_exists( 'esf_get_upgrade_url' ) ? esf_get_upgrade_url( 'instagram' ) : 'https://easysocialfeed.com/pricing/?utm_source=easy_social_feed&utm_medium=wordpress_plugin_install&utm_content=instagram' ) ),
                'proDiscount'         => ( isset( $pro_promo['discount'] ) ? (string) $pro_promo['discount'] : '17%' ),
                'proCoupon'           => ( isset( $pro_promo['coupon'] ) ? (string) $pro_promo['coupon'] : 'ESPF17' ),
                'defaultSettings'     => ESF_Instagram_Feed_Repository::get_default_settings(),
                'layouts'             => $layouts,
            ) );
        }
        wp_enqueue_style( 'wp-components' );
        wp_enqueue_style( 'dashicons' );
    }

    /**
     * Render dashboard shell.
     *
     * @return void
     */
    public function render_dashboard_page() {
        require_once ESF_INSTAGRAM_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Warn on other admin screens when any Instagram account needs manual reconnect.
     *
     * The Instagram dashboard tab shows its own inline notice; skip this screen.
     *
     * @since 6.8.0
     * @return void
     */
    public function maybe_render_reconnect_admin_notice() {
        if ( !is_admin() || !function_exists( 'esf_instagram_user_can_manage' ) || !esf_instagram_user_can_manage() ) {
            return;
        }
        if ( !function_exists( 'esf_instagram_use_new_system' ) || !esf_instagram_use_new_system() ) {
            return;
        }
        $screen = ( function_exists( 'get_current_screen' ) ? get_current_screen() : null );
        $skip_screen_ids = array('feed-them-all_page_esf-instagram', 'easy-social-feed_page_esf-instagram');
        if ( $screen && in_array( (string) $screen->id, $skip_screen_ids, true ) ) {
            return;
        }
        if ( !class_exists( 'ESF_Instagram_Account_Repository' ) || !function_exists( 'esf_instagram_account_needs_reconnect' ) ) {
            return;
        }
        $rows = ESF_Instagram_Account_Repository::get_instance()->get_all();
        $count = 0;
        foreach ( $rows as $row ) {
            if ( esf_instagram_account_needs_reconnect( $row ) ) {
                ++$count;
            }
        }
        if ( $count <= 0 ) {
            return;
        }
        $url = admin_url( 'admin.php?page=esf-instagram' );
        $msg = sprintf( 
            /* translators: %d: number of Instagram accounts that need reconnect */
            _n(
                'Easy Social Feed: %d Instagram account must be reconnected (token expired or automatic renewal failed). Feeds for that account may stop updating until you authorize again.',
                'Easy Social Feed: %d Instagram accounts must be reconnected (tokens expired or automatic renewal failed). Feeds for those accounts may stop updating until you authorize again.',
                $count,
                'easy-facebook-likebox'
            ),
            $count
         );
        ?>
		<div class="notice notice-warning">
			<p><?php 
        echo esc_html( $msg );
        ?></p>
			<p>
				<a href="<?php 
        echo esc_url( $url );
        ?>">
					<?php 
        esc_html_e( 'Open Instagram settings', 'easy-facebook-likebox' );
        ?>
				</a>
			</p>
		</div>
		<?php 
    }

}
