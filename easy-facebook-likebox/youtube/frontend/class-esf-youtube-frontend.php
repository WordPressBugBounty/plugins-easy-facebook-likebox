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
    }

    /**
     * Register default layout (grid).
     *
     * @since 6.7.5
     * @return void
     */
    public function register_default_layouts() {
        ESF_YouTube_Layout_Registry::register( 'grid', 'ESF_YouTube_Layout_Grid' );
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

}
