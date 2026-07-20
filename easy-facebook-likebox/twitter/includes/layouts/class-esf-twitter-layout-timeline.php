<?php
/**
 * Twitter Timeline Layout
 *
 * Renders a vertical list of tweet cards. Each card shows the tweet image
 * (if any) on the left and tweet info (author, text, metrics) on the right.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/Layouts
 * @since 6.7.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Twitter_Layout_Timeline
 *
 * @since 6.7.6
 */
class ESF_Twitter_Layout_Timeline extends ESF_Twitter_Layout_Base {

	/**
	 * WordPress style handle for this layout's CSS.
	 *
	 * @since 6.7.6
	 * @var string
	 */
	const CSS_HANDLE = 'esf-twitter-layout-timeline';

	/**
	 * Render the timeline layout.
	 *
	 * @since 6.7.6
	 * @return string HTML output.
	 */
	public function render() {
		$header_html = $this->render_header();
		$header_html = apply_filters( 'esf_twitter_layout_timeline_header_html', $header_html, $this->feed, $this->account, $this->tweets, $this );

		$shell_attrs = $this->get_timeline_shell_attributes();

		if ( empty( $this->tweets ) ) {
			$html = '<div class="' . esc_attr( $shell_attrs['class'] ) . '"' . $shell_attrs['style_attr'] . '>'
				. $header_html
				. $this->render_empty_state()
				. '</div>';

			return apply_filters( 'esf_twitter_layout_timeline_html', $html, $this->feed, $this->account, array(), $this );
		}

		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings )
			? $this->feed->settings
			: array();
		$feed     = isset( $settings['feed'] ) && is_array( $settings['feed'] )
			? $settings['feed']
			: array();
		$per_page = isset( $feed['per_page'] ) ? max( 1, min( 100, (int) $feed['per_page'] ) ) : 10;

		$display_state = $this->get_display_state();
		$tweets        = isset( $display_state['tweets'] ) && is_array( $display_state['tweets'] )
			? $display_state['tweets']
			: array_slice( $this->tweets, 0, $per_page );
		$tweets        = apply_filters( 'esf_twitter_layout_timeline_tweets', $tweets, $this->feed, $this->account, $this );
		$load_more_html = isset( $display_state['load_more_html'] ) ? (string) $display_state['load_more_html'] : '';
		$cards_html = '';
		foreach ( $tweets as $tweet ) {
			$cards_html .= $this->render_tweet_card( $tweet );
		}

		do_action( 'esf_twitter_layout_timeline_before_cards', $tweets, $this->feed, $this->account, $this );

		$html = '<div class="' . esc_attr( $shell_attrs['class'] ) . '"' . $shell_attrs['style_attr'] . '>'
			. $header_html
			. '<div class="esf-tw-feed__timeline-list">'
			. $cards_html
			. '</div>'
			. $load_more_html
			. '</div>';

		do_action( 'esf_twitter_layout_timeline_after_cards', $tweets, $this->feed, $this->account, $this, $cards_html );

		return apply_filters( 'esf_twitter_layout_timeline_html', $html, $this->feed, $this->account, $tweets, $this );
	}

	/**
	 * Build timeline shell class + inline CSS vars (feed size / aspect ratio).
	 *
	 * @since 6.9.5
	 * @return array{class:string,style_attr:string}
	 */
	private function get_timeline_shell_attributes() {
		$settings  = isset( $this->feed->settings ) && is_array( $this->feed->settings ) ? $this->feed->settings : array();
		$layout    = isset( $settings['layout'] ) && is_array( $settings['layout'] ) ? $settings['layout'] : array();
		$timeline  = isset( $layout['timeline'] ) && is_array( $layout['timeline'] ) ? $layout['timeline'] : array();
		if ( function_exists( 'esf_layout_normalize_dimension_scope' ) ) {
			$timeline = esf_layout_normalize_dimension_scope( $timeline );
		}

		$class = 'esf-tw-feed__timeline';
		$parts = array();

		if ( function_exists( 'esf_layout_dimension_style_vars' ) ) {
			foreach ( esf_layout_dimension_style_vars( $timeline, '--esf-tw' ) as $var => $value ) {
				$parts[] = $var . ':' . $value;
			}
		}
		if ( function_exists( 'esf_layout_has_feed_height_limit' ) && esf_layout_has_feed_height_limit( $timeline ) ) {
			$class .= ' esf-tw-feed__timeline--has-feed-height';
		}

		$has_plan = function_exists( 'esf_twitter_has_twitter_plan' ) && esf_twitter_has_twitter_plan();
		$ratio    = '16:9';
		if ( $has_plan && function_exists( 'esf_layout_sanitize_aspect_ratio' ) ) {
			$ratio = esf_layout_sanitize_aspect_ratio(
				isset( $timeline['media_aspect_ratio'] ) ? (string) $timeline['media_aspect_ratio'] : '16:9',
				array( '16:9', '1:1', '3:4' ),
				'16:9'
			);
		}
		if ( function_exists( 'esf_layout_aspect_ratio_to_css' ) ) {
			$parts[] = '--esf-tw-media-ratio:' . esf_layout_aspect_ratio_to_css( $ratio, '16 / 9' );
		}

		$style_attr = '';
		if ( ! empty( $parts ) ) {
			$style_attr = ' style="' . esc_attr( implode( ';', $parts ) ) . '"';
		}

		return array(
			'class'      => $class,
			'style_attr' => $style_attr,
		);
	}

	/**
	 * Get the CSS handle for this layout's stylesheet.
	 *
	 * @since 6.7.6
	 * @return string
	 */
	public function get_css_handle() {
		return self::CSS_HANDLE;
	}
}
