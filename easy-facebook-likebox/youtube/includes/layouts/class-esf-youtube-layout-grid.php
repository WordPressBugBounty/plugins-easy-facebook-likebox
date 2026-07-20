<?php
/**
 * YouTube Grid Layout.
 *
 * Renders feed as a CSS Grid of video cards.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/Layouts
 * @since 6.7.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_Layout_Grid
 *
 * @since 6.7.5
 */
class ESF_YouTube_Layout_Grid extends ESF_YouTube_Layout_Base {

	/**
	 * CSS handle for this layout's stylesheet.
	 *
	 * @since 6.7.5
	 * @var string
	 */
	const CSS_HANDLE = 'esf-youtube-layout-grid';

	/**
	 * Render the grid layout.
	 *
	 * @since 6.7.5
	 * @return string HTML output.
	 */
	public function render() {
		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings ) ? $this->feed->settings : array();
		$layout   = isset( $settings['layout'] ) && is_array( $settings['layout'] ) ? $settings['layout'] : array();
		$grid     = isset( $layout['grid'] ) && is_array( $layout['grid'] ) ? $layout['grid'] : array();
		if ( function_exists( 'esf_layout_normalize_dimension_scope' ) ) {
			$grid = esf_layout_normalize_dimension_scope( $grid );
		}

		$columns_desktop = isset( $grid['columns'] ) ? max( 1, min( 6, (int) $grid['columns'] ) ) : 3;
		$columns_tablet  = isset( $grid['columns_tablet'] ) ? max( 1, min( 6, (int) $grid['columns_tablet'] ) ) : 2;
		$columns_mobile  = isset( $grid['columns_mobile'] ) ? max( 1, min( 6, (int) $grid['columns_mobile'] ) ) : 1;
		$gap             = isset( $grid['gap'] ) ? max( 0, min( 48, (int) $grid['gap'] ) ) : 16;

		$parts = array(
			sprintf(
				'--esf-yt-col-desktop:%d;--esf-yt-col-tablet:%d;--esf-yt-col-mobile:%d;--esf-yt-gap:%dpx',
				$columns_desktop,
				$columns_tablet,
				$columns_mobile,
				$gap
			),
		);

		if ( function_exists( 'esf_layout_dimension_style_string' ) ) {
			$dim = esf_layout_dimension_style_string( $grid, '--esf-yt' );
			if ( '' !== $dim ) {
				$parts[] = $dim;
			}
		}

		$has_plan = function_exists( 'esf_youtube_has_youtube_plan' ) && esf_youtube_has_youtube_plan();
		$ratio    = '16:9';
		if ( $has_plan && function_exists( 'esf_layout_sanitize_aspect_ratio' ) ) {
			$ratio = esf_layout_sanitize_aspect_ratio(
				isset( $grid['media_aspect_ratio'] ) ? (string) $grid['media_aspect_ratio'] : '16:9',
				array( '16:9', '1:1', '3:4' ),
				'16:9'
			);
		}
		if ( function_exists( 'esf_layout_aspect_ratio_to_css' ) ) {
			$parts[] = '--esf-yt-media-ratio:' . esf_layout_aspect_ratio_to_css( $ratio, '16 / 9' );
		}

		$inline_style = implode( ';', array_filter( $parts ) );
		$class        = 'esf-yt-feed__grid';
		if ( function_exists( 'esf_layout_has_feed_height_limit' ) && esf_layout_has_feed_height_limit( $grid ) ) {
			$class .= ' esf-yt-feed__grid--has-feed-height';
		}

		$header_html = $this->render_header();
		$state       = $this->get_display_state();

		if ( empty( $state['videos'] ) ) {
			$empty_html = $this->render_empty_state();
			return '<div class="' . esc_attr( $class ) . '" style="' . esc_attr( $inline_style ) . '">' . $header_html . $empty_html . '</div>';
		}

		$cards_html = $this->render_video_cards( $state['videos'] );

		return '<div class="' . esc_attr( $class ) . '" style="' . esc_attr( $inline_style ) . '">' . $header_html . '<div class="esf-yt-feed__grid-inner">' . $cards_html . '</div>' . $state['load_more_html'] . '</div>';
	}

	/**
	 * Get the CSS handle for this layout's stylesheet.
	 *
	 * @since 6.7.5
	 * @return string
	 */
	public function get_css_handle() {
		return self::CSS_HANDLE;
	}
}
