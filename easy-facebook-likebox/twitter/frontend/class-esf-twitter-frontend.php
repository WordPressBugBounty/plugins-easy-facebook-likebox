<?php

/**
 * Twitter Frontend Class
 *
 * Registers the [esf_twitter_feed] shortcode and conditionally enqueues
 * CSS/JS on pages that render at least one Twitter feed.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/Frontend
 * @since 6.7.6
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Twitter_Frontend
 *
 * @since 6.7.6
 */
class ESF_Twitter_Frontend {
    use ESF_Twitter_Singleton;
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
        add_shortcode( 'esf_twitter_feed', array($this, 'render_shortcode') );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_frontend_assets') );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_loading_script'), 5 );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_missing_feed_admin_assets'), 8 );
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_twitter_has_twitter_plan' ) && esf_twitter_has_twitter_plan() ) {
                add_action( 'wp_footer', array($this, 'maybe_enqueue_lightbox_assets__premium_only'), 6 );
            }
        }
    }

    /**
     * Render [esf_twitter_feed id="1"] shortcode.
     *
     * @since 6.7.6
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'id' => 0,
        ), $atts, 'esf_twitter_feed' );
        $feed_id = (int) $atts['id'];
        if ( $feed_id <= 0 ) {
            return '';
        }
        esf_twitter_flag_feed_script();
        $renderer = new ESF_Twitter_Renderer();
        return $renderer->render( $feed_id, array(), true );
    }

    /**
     * Conditionally enqueue Twitter assets in wp_footer.
     *
     * Assets are only enqueued when at least one Twitter feed was rendered
     * during the current request. Keeps uncached pages clean.
     *
     * @since 6.7.6
     * @return void
     */
    public function maybe_enqueue_frontend_assets() {
        global $esf_twitter_feed_script_needed;
        if ( empty( $esf_twitter_feed_script_needed ) ) {
            return;
        }
        $version = ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );
        $base_css_path = ESF_TWITTER_DIR . 'frontend/assets/css/esf-twitter-feed.css';
        if ( file_exists( $base_css_path ) && !wp_style_is( 'esf-twitter-feed', 'enqueued' ) ) {
            wp_enqueue_style(
                'esf-twitter-feed',
                ESF_TWITTER_URL . 'frontend/assets/css/esf-twitter-feed.css',
                array(),
                $version
            );
        }
        if ( class_exists( 'ESF_GDPR_Integrations' ) ) {
            ESF_GDPR_Integrations::maybe_enqueue_consent_script();
        }
        $caption_js = ESF_TWITTER_DIR . 'frontend/assets/js/esf-twitter-caption.js';
        if ( file_exists( $caption_js ) && !wp_script_is( 'esf-twitter-caption', 'enqueued' ) ) {
            wp_enqueue_script(
                'esf-twitter-caption',
                ESF_TWITTER_URL . 'frontend/assets/js/esf-twitter-caption.js',
                array(),
                (string) filemtime( $caption_js ),
                true
            );
        }
        $this->maybe_enqueue_public_script();
    }

    /**
     * Enqueue skeleton loading script when a feed rendered this request.
     *
     * @since 6.7.6
     * @return void
     */
    public function maybe_enqueue_loading_script() {
        global $esf_twitter_feed_script_needed;
        if ( empty( $esf_twitter_feed_script_needed ) ) {
            return;
        }
        if ( function_exists( 'esf_enqueue_feed_loading_script' ) ) {
            esf_enqueue_feed_loading_script();
        }
    }

    /**
     * Enqueue shared missing-feed admin recovery assets when placeholders render.
     *
     * @since 6.11.0
     * @return void
     */
    public function maybe_enqueue_missing_feed_admin_assets() {
        if ( !function_exists( 'esf_twitter_user_can_manage' ) || !esf_twitter_user_can_manage() ) {
            return;
        }
        if ( function_exists( 'esf_enqueue_missing_feed_admin_assets' ) ) {
            esf_enqueue_missing_feed_admin_assets( array('[esf_twitter_feed') );
        }
    }

    /**
     * Enqueue public frontend script for feed interactions.
     *
     * @since 6.7.6
     * @return void
     */
    private function maybe_enqueue_public_script() {
        $script_path = ESF_TWITTER_DIR . 'frontend/assets/js/esf-twitter-public__premium_only.js';
        if ( !file_exists( $script_path ) ) {
            return;
        }
        wp_enqueue_script(
            'esf-twitter-public',
            ESF_TWITTER_URL . 'frontend/assets/js/esf-twitter-public__premium_only.js',
            array(),
            (string) filemtime( $script_path ),
            true
        );
        wp_localize_script( 'esf-twitter-public', 'esfTwitterPublic', array(
            'restUrl'     => rest_url( 'esf/v1' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'loadingText' => __( esf_get_translated_string( 'loading' ), 'easy-facebook-likebox' ),
        ) );
    }

}
