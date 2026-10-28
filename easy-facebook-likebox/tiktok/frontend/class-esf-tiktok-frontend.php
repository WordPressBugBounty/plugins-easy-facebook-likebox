<?php

/**
 * TikTok Frontend Class
 *
 * Registers [esf_tiktok_feed] and conditionally enqueues CSS.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok/Frontend
 * @since 6.9.0
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_TikTok_Frontend
 *
 * @since 6.9.0
 */
class ESF_TikTok_Frontend {
    use ESF_TikTok_Singleton;
    /**
     * Constructor.
     *
     * @since 6.9.0
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Register hooks.
     *
     * @since 6.9.0
     * @return void
     */
    private function init_hooks() {
        add_shortcode( 'esf_tiktok_feed', array($this, 'render_shortcode') );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_frontend_assets') );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_loading_script'), 5 );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_missing_feed_admin_assets'), 8 );
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_tiktok_has_tiktok_plan' ) && esf_tiktok_has_tiktok_plan() ) {
                if ( method_exists( $this, 'maybe_enqueue_load_more_script__premium_only' ) ) {
                    add_action( 'wp_footer', array($this, 'maybe_enqueue_load_more_script__premium_only') );
                }
                if ( method_exists( $this, 'maybe_enqueue_lightbox_assets__premium_only' ) ) {
                    add_action( 'wp_footer', array($this, 'maybe_enqueue_lightbox_assets__premium_only'), 6 );
                }
            }
        }
    }

    /**
     * Render [esf_tiktok_feed id="1"].
     *
     * @since 6.9.0
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'id' => 0,
        ), $atts, 'esf_tiktok_feed' );
        $feed_id = (int) $atts['id'];
        if ( $feed_id <= 0 ) {
            return '';
        }
        esf_tiktok_flag_feed_script();
        $renderer = new ESF_TikTok_Renderer();
        return $renderer->render( $feed_id, array(), true );
    }

    /**
     * Enqueue assets when a feed rendered this request.
     *
     * @since 6.9.0
     * @return void
     */
    public function maybe_enqueue_frontend_assets() {
        global $esf_tiktok_feed_script_needed;
        if ( empty( $esf_tiktok_feed_script_needed ) ) {
            return;
        }
        $version = ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );
        $base = ESF_TIKTOK_DIR . 'frontend/assets/css/esf-tiktok-feed.css';
        if ( file_exists( $base ) && !wp_style_is( 'esf-tiktok-feed', 'enqueued' ) ) {
            wp_enqueue_style(
                'esf-tiktok-feed',
                ESF_TIKTOK_URL . 'frontend/assets/css/esf-tiktok-feed.css',
                array(),
                $version
            );
        }
        if ( class_exists( 'ESF_GDPR_Integrations' ) ) {
            ESF_GDPR_Integrations::maybe_enqueue_consent_script();
        }
        $caption_js = ESF_TIKTOK_DIR . 'frontend/assets/js/esf-tiktok-caption.js';
        if ( file_exists( $caption_js ) && !wp_script_is( 'esf-tiktok-caption', 'enqueued' ) ) {
            wp_enqueue_script(
                'esf-tiktok-caption',
                ESF_TIKTOK_URL . 'frontend/assets/js/esf-tiktok-caption.js',
                array(),
                (string) filemtime( $caption_js ),
                true
            );
        }
    }

    /**
     * Enqueue skeleton loading script when a feed rendered this request.
     *
     * @since 6.9.0
     * @return void
     */
    public function maybe_enqueue_loading_script() {
        global $esf_tiktok_feed_script_needed;
        if ( empty( $esf_tiktok_feed_script_needed ) ) {
            return;
        }
        if ( function_exists( 'esf_enqueue_feed_loading_script' ) ) {
            esf_enqueue_feed_loading_script();
        }
    }

    /**
     * Enqueue shared missing-feed admin recovery assets when placeholders rendered.
     *
     * @since 6.9.0
     * @return void
     */
    public function maybe_enqueue_missing_feed_admin_assets() {
        if ( !function_exists( 'esf_tiktok_user_can_manage' ) || !esf_tiktok_user_can_manage() ) {
            return;
        }
        $should_enqueue = false;
        if ( function_exists( 'esf_missing_feed_get_queue' ) && !empty( esf_missing_feed_get_queue() ) ) {
            $should_enqueue = true;
        }
        if ( !$should_enqueue && function_exists( 'is_singular' ) && is_singular() ) {
            $post = get_post();
            if ( $post instanceof WP_Post && false !== strpos( (string) $post->post_content, '[esf_tiktok_feed' ) ) {
                $should_enqueue = true;
            }
        }
        if ( !$should_enqueue ) {
            return;
        }
        $script_path = FTA_PLUGIN_DIR . 'admin/shared/frontend/js/esf-missing-feed-admin.js';
        if ( !file_exists( $script_path ) ) {
            return;
        }
        $style_path = FTA_PLUGIN_DIR . 'admin/shared/frontend/css/esf-missing-feed.css';
        if ( file_exists( $style_path ) && !wp_style_is( 'esf-missing-feed', 'enqueued' ) ) {
            wp_enqueue_style(
                'esf-missing-feed',
                FTA_PLUGIN_URL . 'admin/shared/frontend/css/esf-missing-feed.css',
                array(),
                (string) filemtime( $style_path )
            );
        }
        if ( !wp_script_is( 'esf-missing-feed-admin', 'enqueued' ) ) {
            wp_enqueue_script(
                'esf-missing-feed-admin',
                FTA_PLUGIN_URL . 'admin/shared/frontend/js/esf-missing-feed-admin.js',
                array(),
                (string) filemtime( $script_path ),
                true
            );
            wp_localize_script( 'esf-missing-feed-admin', 'esfMissingFeedAdmin', array(
                'restUrl'              => rest_url( 'esf/v1' ),
                'nonce'                => wp_create_nonce( 'wp_rest' ),
                'loadingText'          => __( 'Loading…', 'easy-facebook-likebox' ),
                'titleText'            => __( 'Feed not found', 'easy-facebook-likebox' ),
                'adminIntroText'       => __( 'This shortcode points to a feed that was deleted. Choose another feed to preview or update this page.', 'easy-facebook-likebox' ),
                'selectFeedText'       => __( 'Select a feed', 'easy-facebook-likebox' ),
                'previewText'          => __( 'Preview feed', 'easy-facebook-likebox' ),
                'saveText'             => __( 'Save to this page', 'easy-facebook-likebox' ),
                'savedText'            => __( 'Shortcode updated. Reloading…', 'easy-facebook-likebox' ),
                'copyShortcodeText'    => __( 'Copy shortcode', 'easy-facebook-likebox' ),
                'copiedText'           => __( 'Copied!', 'easy-facebook-likebox' ),
                'dashboardText'        => __( 'Open dashboard', 'easy-facebook-likebox' ),
                'noFeedsText'          => __( 'No feeds available. Create one in the dashboard first.', 'easy-facebook-likebox' ),
                'noPostText'           => __( 'This shortcode is not inside editable page content. Copy the new shortcode and paste it where needed.', 'easy-facebook-likebox' ),
                'previewErrorText'     => __( 'Could not load feed preview.', 'easy-facebook-likebox' ),
                'saveErrorText'        => __( 'Could not update the shortcode on this page.', 'easy-facebook-likebox' ),
                'replaceShortcodePath' => '/missing-feed/replace-shortcode',
            ) );
        }
    }

}
