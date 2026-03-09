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

		$columns_desktop = isset( $grid['columns'] ) ? max( 1, min( 6, (int) $grid['columns'] ) ) : 3;
		$columns_tablet  = isset( $grid['columns_tablet'] ) ? max( 1, min( 6, (int) $grid['columns_tablet'] ) ) : 2;
		$columns_mobile  = isset( $grid['columns_mobile'] ) ? max( 1, min( 6, (int) $grid['columns_mobile'] ) ) : 1;
		$gap             = isset( $grid['gap'] ) ? max( 0, min( 48, (int) $grid['gap'] ) ) : 16;

		$inline_style = sprintf(
			'--esf-yt-col-desktop:%d;--esf-yt-col-tablet:%d;--esf-yt-col-mobile:%d;--esf-yt-gap:%dpx',
			$columns_desktop,
			$columns_tablet,
			$columns_mobile,
			$gap
		);

		$header_html = $this->render_header();
		$state       = $this->get_display_state();

		if ( empty( $state['videos'] ) ) {
			$empty_html = $this->render_empty_state();
			return '<div class="esf-yt-feed__grid" style="' . esc_attr( $inline_style ) . '">' . $header_html . $empty_html . '</div>';
		}

		$cards_html = $this->render_video_cards( $state['videos'] );

		return '<div class="esf-yt-feed__grid" style="' . esc_attr( $inline_style ) . '">' . $header_html . '<div class="esf-yt-feed__grid-inner">' . $cards_html . '</div>' . $state['load_more_html'] . '</div>';
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
