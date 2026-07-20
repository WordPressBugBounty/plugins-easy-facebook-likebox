<?php
/**
 * Shared layout feed/media dimension helpers.
 *
 * Used by Instagram, X/Twitter, and YouTube to emit optional CSS custom
 * properties for feed width/height and media max-height (0 = automatic).
 *
 * @package Easy_Social_Feed
 * @since 6.9.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dimension setting keys (desktop + tablet + mobile variants).
 *
 * @since 6.9.5
 * @return array<int,string>
 */
function esf_layout_dimension_keys() {
	return array(
		'feed_width',
		'feed_width_tablet',
		'feed_width_mobile',
		'feed_height',
		'feed_height_tablet',
		'feed_height_mobile',
		'media_max_height',
		'media_max_height_tablet',
		'media_max_height_mobile',
	);
}

/**
 * Default dimension settings (all automatic).
 *
 * @since 6.9.5
 * @return array<string,int>
 */
function esf_layout_dimension_defaults() {
	$defaults = array();
	foreach ( esf_layout_dimension_keys() as $key ) {
		$defaults[ $key ] = 0;
	}
	return $defaults;
}

/**
 * Clamp dimension values on a layout settings scope.
 *
 * @since 6.9.5
 * @param array<string,mixed> $scope Layout settings.
 * @return array<string,mixed>
 */
function esf_layout_normalize_dimension_scope( array $scope ) {
	foreach ( esf_layout_dimension_keys() as $key ) {
		if ( array_key_exists( $key, $scope ) ) {
			$scope[ $key ] = max( 0, min( 2000, (int) $scope[ $key ] ) );
		}
	}
	return $scope;
}

/**
 * Build CSS custom properties for optional feed/media dimensions.
 *
 * @since 6.9.5
 * @param array<string,mixed> $scope  Layout settings scope.
 * @param string              $prefix CSS var prefix without trailing dash (e.g. `--esf-tw`).
 * @return array<string,string> Map of CSS var => value (e.g. `600px`).
 */
function esf_layout_dimension_style_vars( array $scope, $prefix ) {
	$prefix = rtrim( (string) $prefix, '-' );
	$map    = array(
		'feed_width'              => $prefix . '-feed-width',
		'feed_width_tablet'       => $prefix . '-feed-width-tablet',
		'feed_width_mobile'       => $prefix . '-feed-width-mobile',
		'feed_height'             => $prefix . '-feed-height',
		'feed_height_tablet'      => $prefix . '-feed-height-tablet',
		'feed_height_mobile'      => $prefix . '-feed-height-mobile',
		'media_max_height'        => $prefix . '-media-max-height',
		'media_max_height_tablet' => $prefix . '-media-max-height-tablet',
		'media_max_height_mobile' => $prefix . '-media-max-height-mobile',
	);

	$vars   = array();
	$scroll = false;

	foreach ( $map as $setting_key => $css_var ) {
		$value = isset( $scope[ $setting_key ] ) ? (int) $scope[ $setting_key ] : 0;
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
		$vars[ $prefix . '-feed-height-scroll' ] = 'auto';
	}

	return $vars;
}

/**
 * Serialize dimension CSS vars into an inline style fragment.
 *
 * @since 6.9.5
 * @param array<string,mixed> $scope  Layout settings scope.
 * @param string              $prefix CSS var prefix.
 * @return string e.g. `--esf-tw-feed-width:600px;`
 */
function esf_layout_dimension_style_string( array $scope, $prefix ) {
	$parts = array();
	foreach ( esf_layout_dimension_style_vars( $scope, $prefix ) as $var => $value ) {
		$parts[] = $var . ':' . $value;
	}
	return implode( ';', $parts );
}

/**
 * Whether any feed max-height is set.
 *
 * @since 6.9.5
 * @param array<string,mixed> $scope Layout settings scope.
 * @return bool
 */
function esf_layout_has_feed_height_limit( array $scope ) {
	foreach ( array( 'feed_height', 'feed_height_tablet', 'feed_height_mobile' ) as $key ) {
		if ( isset( $scope[ $key ] ) && (int) $scope[ $key ] > 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Convert a media aspect ratio token to a CSS aspect-ratio value.
 *
 * @since 6.9.5
 * @param string $ratio Ratio token (e.g. `16:9`).
 * @param string $fallback CSS fallback (e.g. `16 / 9`).
 * @return string
 */
function esf_layout_aspect_ratio_to_css( $ratio, $fallback = '16 / 9' ) {
	switch ( trim( (string) $ratio ) ) {
		case '1:1':
			return '1 / 1';
		case '4:5':
			return '4 / 5';
		case '3:4':
			return '3 / 4';
		case '16:9':
			return '16 / 9';
		case '16:10':
			return '16 / 10';
		default:
			return $fallback;
	}
}

/**
 * Sanitize a media aspect ratio against an allow-list.
 *
 * @since 6.9.5
 * @param string        $ratio    Raw ratio.
 * @param array<string> $allowed  Allowed tokens.
 * @param string        $fallback Fallback when invalid.
 * @return string
 */
function esf_layout_sanitize_aspect_ratio( $ratio, array $allowed, $fallback ) {
	$ratio = trim( (string) $ratio );
	if ( in_array( $ratio, $allowed, true ) ) {
		return $ratio;
	}
	return $fallback;
}
