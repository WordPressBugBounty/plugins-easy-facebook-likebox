<?php
/**
 * Responsive feed width/height and media max-height CSS vars for Instagram layouts.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram\Layouts
 * @since 6.9.0
 */

namespace EasySocialFeed\Instagram\Layouts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared dimension helpers for layout shells.
 *
 * @since 6.9.0
 */
trait InstagramLayoutDimensionsSupport {

	/**
	 * Layout dimension setting keys mapped to CSS custom properties.
	 *
	 * @since 6.9.0
	 * @var array<string,string>
	 */
	private static $layout_dimension_var_map = array(
		'feed_width'              => '--esf-ig-feed-width',
		'feed_width_tablet'       => '--esf-ig-feed-width-tablet',
		'feed_width_mobile'       => '--esf-ig-feed-width-mobile',
		'feed_height'             => '--esf-ig-feed-height',
		'feed_height_tablet'      => '--esf-ig-feed-height-tablet',
		'feed_height_mobile'      => '--esf-ig-feed-height-mobile',
		'media_max_height'        => '--esf-ig-media-max-height',
		'media_max_height_tablet' => '--esf-ig-media-max-height-tablet',
		'media_max_height_mobile' => '--esf-ig-media-max-height-mobile',
	);

	/**
	 * Build CSS custom properties for optional feed/media dimensions.
	 *
	 * Values of `0` are omitted so layouts stay fully responsive by default.
	 *
	 * @since 6.9.0
	 *
	 * @param string $settings_path Dotted settings path (e.g. `layout.grid`).
	 * @return array<string,string>
	 */
	protected function get_layout_dimension_style_vars( string $settings_path ): array {
		$vars   = array();
		$scroll = false;

		foreach ( self::$layout_dimension_var_map as $setting_key => $css_var ) {
			$value = (int) $this->setting( $settings_path . '.' . $setting_key, 0 );
			$value = max( 0, min( 2000, $value ) );

			if ( $value <= 0 ) {
				continue;
			}

			$vars[ $css_var ] = $value . 'px';

			if ( in_array( $setting_key, array( 'feed_height', 'feed_height_tablet', 'feed_height_mobile' ), true ) ) {
				$scroll = true;
			}
		}

		if ( $scroll ) {
			$vars['--esf-ig-feed-height-scroll'] = 'auto';
		}

		return $vars;
	}

	/**
	 * Whether any feed max-height limit is configured.
	 *
	 * @since 6.9.0
	 *
	 * @param string $settings_path Dotted settings path (e.g. `layout.grid`).
	 * @return bool
	 */
	protected function layout_has_feed_height_limit( string $settings_path ): bool {
		foreach ( array( 'feed_height', 'feed_height_tablet', 'feed_height_mobile' ) as $key ) {
			if ( (int) $this->setting( $settings_path . '.' . $key, 0 ) > 0 ) {
				return true;
			}
		}

		return false;
	}
}
