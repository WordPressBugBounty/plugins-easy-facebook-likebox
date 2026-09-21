<?php

/**
 * YouTube Frontend.
 *
 * Registers shortcode and enqueues feed assets when shortcode is used.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/Frontend
 * @since 6.7.5
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_YouTube_Frontend
 *
 * @since 6.7.5
 */
class ESF_YouTube_Frontend {
    use ESF_YouTube_Singleton;
    /**
     * Shortcode tag.
     *
     * @since 6.7.5
     * @var string
     */
    const SHORTCODE_TAG = 'esf_youtube_feed';

    /**
     * Renderer instance.
     *
     * @since 6.7.5
     * @var ESF_YouTube_Renderer
     */
    protected $renderer;

    /**
     * Constructor.
     *
     * @since 6.7.5
     */
    private function __construct() {
        $this->renderer = new ESF_YouTube_Renderer();
        $this->register_hooks();
    }

    /**
     * Register shortcode and hooks.
     *
     * @since 6.7.5
     * @return void
     */
    protected function register_hooks() {
        add_shortcode( self::SHORTCODE_TAG, array($this, 'render_shortcode') );
        add_action( 'esf_youtube_register_layouts', array($this, 'register_default_layouts') );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_frontend_assets') );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_loading_script'), 5 );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_missing_feed_admin_assets'), 8 );
    }

    /**
     * Register default layout (grid) and Pro carousel when available.
     *
     * @since 6.7.5
     * @return void
     */
    public function register_default_layouts() {
        // Register layouts in shared registry
        $version = ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );
        $registry = \EasySocialFeed\Layouts\LayoutRegistry::for( 'youtube' );
        $grid_definition = new \EasySocialFeed\Layouts\ValueObjects\LayoutDefinition(array(
            'slug'        => 'grid',
            'label'       => __( 'Grid', 'easy-facebook-likebox' ),
            'description' => __( 'Responsive grid of video cards', 'easy-facebook-likebox' ),
            'icon'        => 'grid',
            'class_name'  => ESF_YouTube_Layout_Grid::class,
            'is_pro'      => false,
            'supports'    => array('header', 'metrics', 'load_more'),
            'assets'      => array(
                'layout_css' => array(
                    'handle' => 'esf-youtube-layout-grid',
                    'src'    => ESF_YOUTUBE_URL . 'frontend/assets/css/layouts/grid.css',
                    'deps'   => array(),
                    'ver'    => $version,
                ),
            ),
        ));
        $registry->register( $grid_definition );
        if ( class_exists( 'ESF_YouTube_Layout_Registry' ) ) {
            ESF_YouTube_Layout_Registry::register( 'grid', ESF_YouTube_Layout_Grid::class );
        }
        $this->register_row_layout();
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( method_exists( $this, 'register_carousel_layout__premium_only' ) ) {
                $this->register_carousel_layout__premium_only();
            }
            if ( method_exists( $this, 'register_half_width_layout__premium_only' ) ) {
                $this->register_half_width_layout__premium_only();
            }
            if ( method_exists( $this, 'register_full_width_layout__premium_only' ) ) {
                $this->register_full_width_layout__premium_only();
            }
            if ( method_exists( $this, 'register_masonry_layout__premium_only' ) ) {
                $this->register_masonry_layout__premium_only();
            }
        }
    }

    /**
     * Register the free Row layout (wave style is Pro-gated inside the class).
     *
     * @since 6.17.0
     * @return void
     */
    public function register_row_layout() {
        if ( !class_exists( 'ESF_YouTube_Layout_Row' ) ) {
            return;
        }
        $version = ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );
        $row_js = ESF_YOUTUBE_DIR . 'frontend/assets/js/esf-youtube-row-wave.js';
        $assets = array(
            'layout_css' => array(
                'handle' => ESF_YouTube_Layout_Row::CSS_HANDLE,
                'src'    => ESF_YOUTUBE_URL . 'frontend/assets/css/layouts/row.css',
                'deps'   => array(),
                'ver'    => $version,
            ),
        );
        if ( file_exists( $row_js ) ) {
            $assets['layout_js'] = array(
                'handle'    => ESF_YouTube_Layout_Row::JS_HANDLE,
                'src'       => ESF_YOUTUBE_URL . 'frontend/assets/js/esf-youtube-row-wave.js',
                'deps'      => array(),
                'ver'       => (string) filemtime( $row_js ),
                'in_footer' => true,
            );
        }
        $dim_defaults = ( function_exists( 'esf_layout_dimension_defaults' ) ? esf_layout_dimension_defaults() : array() );
        $registry = \EasySocialFeed\Layouts\LayoutRegistry::for( 'youtube' );
        $registry->register( new \EasySocialFeed\Layouts\ValueObjects\LayoutDefinition(array(
            'slug'             => 'row',
            'label'            => __( 'Row', 'easy-facebook-likebox' ),
            'description'      => __( 'A sleek single-row strip — great for headers and sections', 'easy-facebook-likebox' ),
            'icon'             => 'row',
            'class_name'       => ESF_YouTube_Layout_Row::class,
            'is_pro'           => false,
            'supports'         => array('header', 'metrics', 'load_more'),
            'assets'           => $assets,
            'default_settings' => array(
                'layout' => array(
                    'type' => 'row',
                    'row'  => array_merge( $dim_defaults, array(
                        'row_style'          => 'standard',
                        'wave_offset'        => 16,
                        'media_aspect_ratio' => '1:1',
                        'columns'            => 4,
                        'columns_tablet'     => 3,
                        'columns_mobile'     => 2,
                        'gap'                => 0,
                        'tile_border_radius' => 0,
                        'tile_shadow'        => false,
                        'tile_shadow_color'  => 'rgba(0,0,0,0.12)',
                    ) ),
                ),
            ),
        )) );
        if ( class_exists( 'ESF_YouTube_Layout_Registry' ) ) {
            ESF_YouTube_Layout_Registry::register( 'row', ESF_YouTube_Layout_Row::class );
        }
    }

    /**
     * Enqueue base CSS when a YouTube feed rendered this request.
     *
     * @since 6.9.0
     * @return void
     */
    public function maybe_enqueue_frontend_assets() {
        global $esf_youtube_feed_script_needed;
        if ( empty( $esf_youtube_feed_script_needed ) ) {
            return;
        }
        $version = ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );
        $base_css_path = ESF_YOUTUBE_DIR . 'frontend/assets/css/esf-youtube-feed.css';
        if ( file_exists( $base_css_path ) && !wp_style_is( 'esf-youtube-feed', 'enqueued' ) ) {
            wp_enqueue_style(
                'esf-youtube-feed',
                ESF_YOUTUBE_URL . 'frontend/assets/css/esf-youtube-feed.css',
                array(),
                $version
            );
        }
        if ( class_exists( 'ESF_GDPR_Integrations' ) ) {
            ESF_GDPR_Integrations::maybe_enqueue_consent_script();
        }
        $this->maybe_enqueue_public_script();
    }

    /**
     * Enqueue the public script for frontend interactions.
     *
     * @since 6.9.0
     * @return void
     */
    private function maybe_enqueue_public_script() {
        $script_path = ESF_YOUTUBE_DIR . 'frontend/assets/js/esf-youtube-public__premium_only.js';
        if ( !file_exists( $script_path ) ) {
            return;
        }
        wp_enqueue_script(
            'esf-youtube-public',
            ESF_YOUTUBE_URL . 'frontend/assets/js/esf-youtube-public__premium_only.js',
            array(),
            (string) filemtime( $script_path ),
            true
        );
        wp_localize_script( 'esf-youtube-public', 'esfYouTubePublic', array(
            'restUrl'     => rest_url( 'esf/v1' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'loadingText' => __( esf_get_translated_string( 'loading' ), 'easy-facebook-likebox' ),
        ) );
    }

    /**
     * Shortcode callback: [esf_youtube_feed id="1"].
     *
     * @since 6.7.5
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'id' => 0,
        ), $atts, self::SHORTCODE_TAG );
        $feed_id = absint( $atts['id'] );
        if ( $feed_id <= 0 ) {
            return '';
        }
        // Flag that a feed rendered so loading script can be enqueued
        if ( function_exists( 'esf_youtube_flag_feed_script' ) ) {
            esf_youtube_flag_feed_script();
        }
        return $this->renderer->render( $feed_id, array(), true );
    }

    /**
     * Get renderer instance (for preview endpoint).
     *
     * @since 6.7.5
     * @return ESF_YouTube_Renderer
     */
    public function get_renderer() {
        return $this->renderer;
    }

    /**
     * Enqueue skeleton loading script when a feed rendered this request.
     *
     * @since 6.7.5
     * @return void
     */
    public function maybe_enqueue_loading_script() {
        global $esf_youtube_feed_script_needed;
        if ( empty( $esf_youtube_feed_script_needed ) ) {
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
        if ( !function_exists( 'esf_youtube_user_can_manage' ) || !esf_youtube_user_can_manage() ) {
            return;
        }
        if ( function_exists( 'esf_enqueue_missing_feed_admin_assets' ) ) {
            esf_enqueue_missing_feed_admin_assets( array('[esf_youtube_feed') );
        }
    }

}
