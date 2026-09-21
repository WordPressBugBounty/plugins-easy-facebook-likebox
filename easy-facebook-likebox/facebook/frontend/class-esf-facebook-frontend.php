<?php

/**
 * Facebook Frontend Class.
 *
 * Registers [esf_facebook_feed] and conditionally enqueues CSS/JS when a
 * feed or likebox actually rendered during the request.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/Frontend
 * @since 6.7.9
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Facebook_Frontend
 *
 * @since 6.7.9
 */
class ESF_Facebook_Frontend {
    use ESF_Facebook_Singleton;
    /**
     * Constructor.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Register WordPress hooks.
     *
     * @return void
     */
    private function init_hooks() {
        add_shortcode( 'esf_facebook_feed', array($this, 'render_shortcode') );
        // Permanent aliases for legacy shortcodes after migration.
        add_shortcode( 'efb_feed', array($this, 'render_legacy_feed_shortcode') );
        add_shortcode( 'efb_likebox', array($this, 'render_legacy_likebox_shortcode') );
        add_shortcode( 'efb_pageplugin', array($this, 'render_legacy_likebox_shortcode') );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_frontend_assets') );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_loading_script') );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_free_popup_assets'), 5 );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_likebox_script'), 4 );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_live_stream_assets'), 5 );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_missing_feed_admin_assets'), 8 );
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan() ) {
                if ( method_exists( $this, 'maybe_enqueue_load_more_script__premium_only' ) ) {
                    add_action( 'wp_footer', array($this, 'maybe_enqueue_load_more_script__premium_only') );
                }
                if ( method_exists( $this, 'maybe_enqueue_lightbox_assets__premium_only' ) ) {
                    add_action( 'wp_footer', array($this, 'maybe_enqueue_lightbox_assets__premium_only'), 6 );
                }
                if ( method_exists( $this, 'maybe_enqueue_infinite_scroll_flag__premium_only' ) ) {
                    add_action( 'wp_footer', array($this, 'maybe_enqueue_infinite_scroll_flag__premium_only'), 5 );
                }
                if ( method_exists( $this, 'maybe_enqueue_row_wave_script__premium_only' ) ) {
                    add_action( 'wp_footer', array($this, 'maybe_enqueue_row_wave_script__premium_only'), 5 );
                }
            }
        }
    }

    /**
     * Render [esf_facebook_feed id="1"].
     *
     * Branches on content_type: feed → Renderer, likebox → Likebox_Renderer.
     * Asset flags are set inside the respective renderers / layout traits.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render_shortcode( $atts ) {
        $atts = ( is_array( $atts ) ? $atts : array() );
        $settings_override = array();
        if ( isset( $atts['_esf_fb_settings_override'] ) && is_array( $atts['_esf_fb_settings_override'] ) ) {
            $settings_override = $atts['_esf_fb_settings_override'];
        }
        $atts = shortcode_atts( array(
            'id' => 0,
        ), $atts, 'esf_facebook_feed' );
        $feed_id = (int) $atts['id'];
        if ( $feed_id <= 0 ) {
            return '';
        }
        if ( !class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
            return '';
        }
        $feed_row = ESF_Facebook_Feed_Repository::get_instance()->get_by_id( $feed_id );
        if ( !$feed_row ) {
            if ( class_exists( 'ESF_Facebook_Renderer' ) ) {
                return ( new ESF_Facebook_Renderer() )->render( $feed_id, $settings_override, true );
            }
            return '';
        }
        $content_type = ( isset( $feed_row->content_type ) ? sanitize_key( (string) $feed_row->content_type ) : 'feed' );
        if ( 'likebox' === $content_type ) {
            return '';
        }
        if ( !class_exists( 'ESF_Facebook_Renderer' ) ) {
            return '';
        }
        return ( new ESF_Facebook_Renderer() )->render( $feed_id, $settings_override, true );
    }

    /**
     * Permanent alias for legacy [efb_feed] shortcodes.
     *
     * Resolves fanpage_id (+ filters) onto a modern feed via the legacy feed map.
     *
     * @since 6.7.9
     * @param array $atts Legacy shortcode attributes.
     * @return string
     */
    public function render_legacy_feed_shortcode( $atts ) {
        $atts = ( is_array( $atts ) ? $atts : array() );
        $default_feed_id = ( function_exists( 'esf_facebook_get_migration_default_feed_id' ) ? esf_facebook_get_migration_default_feed_id() : 0 );
        $feed_id = ( function_exists( 'esf_facebook_resolve_legacy_shortcode_feed_id' ) ? (int) esf_facebook_resolve_legacy_shortcode_feed_id( $atts, $default_feed_id ) : (int) $default_feed_id );
        if ( $feed_id <= 0 ) {
            return '';
        }
        $render_atts = array(
            'id' => $feed_id,
        );
        if ( isset( $atts['show_like_box'] ) ) {
            $render_atts['_esf_fb_settings_override'] = array(
                'feed' => array(
                    'show_like_box' => !empty( $atts['show_like_box'] ),
                ),
            );
        }
        return $this->render_shortcode( $render_atts );
    }

    /**
     * Permanent alias for legacy [efb_likebox] / [efb_pageplugin] shortcodes.
     *
     * @since 6.7.9
     * @param array $atts Legacy shortcode attributes.
     * @return string
     */
    public function render_legacy_likebox_shortcode( $atts ) {
        $atts = ( is_array( $atts ) ? $atts : array() );
        if ( class_exists( 'ESF_Facebook_Likebox_Renderer', false ) ) {
            $html = ( new ESF_Facebook_Likebox_Renderer() )->render_from_legacy_atts( $atts );
            if ( '' !== $html ) {
                return $html;
            }
        }
        return '';
    }

    /**
     * Enqueue feed CSS when a feed rendered this request.
     *
     * @return void
     */
    public function maybe_enqueue_frontend_assets() {
        if ( !function_exists( 'esf_facebook_needs_feed_script' ) || !esf_facebook_needs_feed_script() ) {
            return;
        }
        $version = ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );
        $dir = ( defined( 'ESF_FACEBOOK_DIR' ) ? ESF_FACEBOOK_DIR : (( defined( 'EFBL_PLUGIN_DIR' ) ? EFBL_PLUGIN_DIR : '' )) );
        $url = ( defined( 'ESF_FACEBOOK_URL' ) ? ESF_FACEBOOK_URL : (( defined( 'EFBL_PLUGIN_URL' ) ? EFBL_PLUGIN_URL : '' )) );
        $css = $dir . 'frontend/assets/css/esf-facebook-feed.css';
        if ( '' !== $dir && file_exists( $css ) && !wp_style_is( 'esf-facebook-feed', 'enqueued' ) ) {
            wp_enqueue_style(
                'esf-facebook-feed',
                $url . 'frontend/assets/css/esf-facebook-feed.css',
                array(),
                $version
            );
        }
        if ( class_exists( 'ESF_GDPR_Integrations' ) ) {
            ESF_GDPR_Integrations::maybe_enqueue_consent_script();
        }
        $caption_js = $dir . 'frontend/assets/js/esf-facebook-caption.js';
        if ( '' !== $dir && file_exists( $caption_js ) && !wp_script_is( 'esf-facebook-caption', 'enqueued' ) ) {
            wp_enqueue_script(
                'esf-facebook-caption',
                $url . 'frontend/assets/js/esf-facebook-caption.js',
                array(),
                (string) filemtime( $caption_js ),
                true
            );
        }
    }

    /**
     * Enqueue the shared simple-loader hide script when a feed rendered.
     *
     * Overlay HTML comes from esf_get_simple_loader_html().
     *
     * @since 6.7.9
     * @return void
     */
    public function maybe_enqueue_loading_script() {
        if ( !function_exists( 'esf_facebook_needs_feed_script' ) || !esf_facebook_needs_feed_script() ) {
            return;
        }
        if ( function_exists( 'esf_enqueue_feed_loading_script' ) ) {
            esf_enqueue_feed_loading_script();
        }
    }

    /**
     * Enqueue shared Free popup assets.
     *
     * @return void
     */
    public function maybe_enqueue_free_popup_assets() {
        if ( !function_exists( 'esf_facebook_needs_free_popup_script' ) || !esf_facebook_needs_free_popup_script() ) {
            return;
        }
        if ( function_exists( 'esf_facebook_enqueue_free_popup_assets' ) ) {
            esf_facebook_enqueue_free_popup_assets();
        }
    }

    /**
     * Enqueue Facebook SDK for Likebox embeds only.
     *
     * @return void
     */
    public function maybe_enqueue_likebox_script() {
        if ( !function_exists( 'esf_facebook_needs_likebox_script' ) || !esf_facebook_needs_likebox_script() ) {
            return;
        }
        $locale = 'en_US';
        $app_id = '395202813876688';
        // Prefer locale from the Like Box that flagged the SDK (Page Plugin language).
        if ( function_exists( 'esf_facebook_get_likebox_sdk_locale' ) ) {
            $locale = esf_facebook_get_likebox_sdk_locale();
        } elseif ( function_exists( 'esf_get_effective_api_locale' ) ) {
            $locale = esf_get_effective_api_locale();
        }
        wp_enqueue_script(
            'esf-facebook-sdk',
            // xfbml=0: we parse scoped `.esf-facebook-likebox` roots via esf-facebook-likebox.js
            'https://connect.facebook.net/' . rawurlencode( $locale ) . '/sdk.js#xfbml=0&version=v21.0&appId=' . rawurlencode( $app_id ),
            array(),
            null,
            true
        );
        $dir = ( defined( 'ESF_FACEBOOK_DIR' ) ? ESF_FACEBOOK_DIR : (( defined( 'EFBL_PLUGIN_DIR' ) ? EFBL_PLUGIN_DIR : '' )) );
        $url = ( defined( 'ESF_FACEBOOK_URL' ) ? ESF_FACEBOOK_URL : (( defined( 'EFBL_PLUGIN_URL' ) ? EFBL_PLUGIN_URL : '' )) );
        $js = $dir . 'frontend/assets/js/esf-facebook-likebox.js';
        if ( '' !== $dir && file_exists( $js ) ) {
            wp_enqueue_script(
                'esf-facebook-likebox',
                $url . 'frontend/assets/js/esf-facebook-likebox.js',
                array('esf-facebook-sdk'),
                (string) filemtime( $js ),
                true
            );
        }
    }

    /**
     * Enqueue live-stream CSS when flagged.
     *
     * @return void
     */
    public function maybe_enqueue_live_stream_assets() {
        if ( !function_exists( 'esf_facebook_needs_live_stream_script' ) || !esf_facebook_needs_live_stream_script() ) {
            return;
        }
        $dir = ( defined( 'ESF_FACEBOOK_DIR' ) ? ESF_FACEBOOK_DIR : (( defined( 'EFBL_PLUGIN_DIR' ) ? EFBL_PLUGIN_DIR : '' )) );
        $url = ( defined( 'ESF_FACEBOOK_URL' ) ? ESF_FACEBOOK_URL : (( defined( 'EFBL_PLUGIN_URL' ) ? EFBL_PLUGIN_URL : '' )) );
        $css = $dir . 'frontend/assets/css/esf-facebook-live__premium_only.css';
        if ( '' !== $dir && file_exists( $css ) && !wp_style_is( 'esf-facebook-live', 'enqueued' ) ) {
            wp_enqueue_style(
                'esf-facebook-live',
                $url . 'frontend/assets/css/esf-facebook-live__premium_only.css',
                array(),
                (string) filemtime( $css )
            );
        }
    }

    /**
     * Enqueue shared missing-feed admin recovery assets
     *
     * @return void
     */
    public function maybe_enqueue_missing_feed_admin_assets() {
        if ( !function_exists( 'esf_facebook_user_can_manage' ) || !esf_facebook_user_can_manage() ) {
            return;
        }
        if ( !function_exists( 'esf_enqueue_missing_feed_admin_assets' ) ) {
            return;
        }
        esf_enqueue_missing_feed_admin_assets( array(
            '[esf_facebook_feed',
            '[efb_feed',
            '[efb_likebox',
            '[efb_pageplugin'
        ) );
    }

}
