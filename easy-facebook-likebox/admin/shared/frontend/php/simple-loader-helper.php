<?php
/**
 * Simple Loading Overlay Helper (Frontend Only)
 *
 * Generates a lightweight loading spinner overlay for modern modules (Twitter,
 * YouTube, Facebook, Instagram, Social Wall). Prevents layout shift with minimal
 * code (~500 bytes CSS + 1KB JS). Staggered post reveal lives in
 * admin/shared/frontend/js/esf-feed-loading.js + esf-feed-reveal.css (loaded only
 * when General → entrance animation is on).
 *
 * @package Easy_Social_Feed
 * @subpackage Shared/Frontend
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate simple loading overlay HTML for frontend feeds.
 *
 * @param string $module Module slug ('twitter', 'youtube', 'facebook', 'instagram', 'social_wall').
 * @return string Loading overlay HTML.
 */
function esf_get_simple_loader_html( $module ) {
	$module = sanitize_key( $module );

	if ( empty( $module ) || ! in_array( $module, array( 'twitter', 'youtube', 'facebook', 'instagram', 'social_wall' ), true ) ) {
		return '';
	}

	// Simple overlay with spinner - prevents layout shift with minimal code
	return '<div class="esf-loading-overlay" data-esf-loader="1">
		<div class="esf-loading-spinner"></div>
	</div>';
}

/**
 * Whether staggered feed entrance animation is enabled (default on).
 *
 * @since 6.7.9
 * @return bool
 */
function esf_is_feed_entrance_animation_enabled() {
	if ( class_exists( 'ESF_Settings' ) && method_exists( 'ESF_Settings', 'is_feed_entrance_animation_enabled' ) ) {
		return ESF_Settings::is_feed_entrance_animation_enabled();
	}

	return true;
}

/**
 * Absolute URL (with ver) for the shared reveal stylesheet.
 *
 * @since 6.7.9
 * @return string Empty when unavailable.
 */
function esf_get_feed_reveal_css_url() {
	$plugin_dir = defined( 'FTA_PLUGIN_DIR' ) ? FTA_PLUGIN_DIR : '';
	$plugin_url = defined( 'FTA_PLUGIN_URL' ) ? FTA_PLUGIN_URL : '';
	if ( '' === $plugin_dir || '' === $plugin_url ) {
		return '';
	}

	$css = $plugin_dir . 'admin/shared/frontend/css/esf-feed-reveal.css';
	if ( ! file_exists( $css ) ) {
		return '';
	}

	$url = $plugin_url . 'admin/shared/frontend/css/esf-feed-reveal.css';
	if ( function_exists( 'add_query_arg' ) ) {
		$url = (string) add_query_arg( 'ver', (string) filemtime( $css ), $url );
	}

	return $url;
}

/**
 * Enqueue shared feed loading script (+ reveal CSS only when animation is on).
 *
 * Replaces per-module `*-feed-loading.js` copies.
 *
 * @since 6.7.9
 * @return bool True when enqueued or already registered.
 */
function esf_enqueue_feed_loading_script() {
	$entrance_enabled = esf_is_feed_entrance_animation_enabled();

	if ( $entrance_enabled && ! wp_style_is( 'esf-feed-reveal', 'enqueued' ) ) {
		$plugin_dir = defined( 'FTA_PLUGIN_DIR' ) ? FTA_PLUGIN_DIR : '';
		$plugin_url = defined( 'FTA_PLUGIN_URL' ) ? FTA_PLUGIN_URL : '';
		$css        = '' !== $plugin_dir ? $plugin_dir . 'admin/shared/frontend/css/esf-feed-reveal.css' : '';
		if ( '' !== $plugin_url && '' !== $css && file_exists( $css ) ) {
			wp_enqueue_style(
				'esf-feed-reveal',
				$plugin_url . 'admin/shared/frontend/css/esf-feed-reveal.css',
				array(),
				(string) filemtime( $css )
			);
		}
	}

	if ( wp_script_is( 'esf-feed-loading', 'enqueued' ) ) {
		return true;
	}

	$plugin_dir = defined( 'FTA_PLUGIN_DIR' ) ? FTA_PLUGIN_DIR : '';
	$plugin_url = defined( 'FTA_PLUGIN_URL' ) ? FTA_PLUGIN_URL : '';
	if ( '' === $plugin_dir || '' === $plugin_url ) {
		return false;
	}

	$js = $plugin_dir . 'admin/shared/frontend/js/esf-feed-loading.js';
	if ( ! file_exists( $js ) ) {
		return false;
	}

	wp_enqueue_script(
		'esf-feed-loading',
		$plugin_url . 'admin/shared/frontend/js/esf-feed-loading.js',
		array(),
		(string) filemtime( $js ),
		true
	);

	// wp_localize_script stringifies booleans — use real JSON so `false` works.
	wp_add_inline_script(
		'esf-feed-loading',
		'window.esfFeedLoading=' . wp_json_encode(
			array(
				'entranceAnimation' => (bool) $entrance_enabled,
			)
		) . ';',
		'before'
	);

	return true;
}

/**
 * Register shared row-wise masonry packer (once per request).
 *
 * Layout scripts should list `esf-masonry-pack` as a dependency so WordPress
 * loads the packer before per-module ratio sync helpers.
 *
 * @since 6.7.9
 * @return bool True when registered or already registered.
 */
function esf_register_masonry_pack_script() {
	if ( wp_script_is( 'esf-masonry-pack', 'registered' ) ) {
		return true;
	}

	$plugin_dir = defined( 'FTA_PLUGIN_DIR' ) ? FTA_PLUGIN_DIR : '';
	$plugin_url = defined( 'FTA_PLUGIN_URL' ) ? FTA_PLUGIN_URL : '';
	if ( '' === $plugin_dir || '' === $plugin_url ) {
		return false;
	}

	$js = $plugin_dir . 'admin/shared/frontend/js/esf-masonry-pack__premium_only.js';
	if ( ! file_exists( $js ) ) {
		return false;
	}

	wp_register_script(
		'esf-masonry-pack',
		$plugin_url . 'admin/shared/frontend/js/esf-masonry-pack__premium_only.js',
		array(),
		(string) filemtime( $js ),
		true
	);

	return true;
}

/**
 * Register and enqueue the shared masonry packer.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_enqueue_masonry_pack_script() {
	if ( ! esf_register_masonry_pack_script() ) {
		return false;
	}

	wp_enqueue_script( 'esf-masonry-pack' );
	return true;
}

/**
 * Absolute URL (with ver) for the shared masonry packer — dashboard preview inject.
 *
 * @since 6.7.9
 * @return string Empty when unavailable.
 */
function esf_get_masonry_pack_script_url() {
	$plugin_dir = defined( 'FTA_PLUGIN_DIR' ) ? FTA_PLUGIN_DIR : '';
	$plugin_url = defined( 'FTA_PLUGIN_URL' ) ? FTA_PLUGIN_URL : '';
	if ( '' === $plugin_dir || '' === $plugin_url ) {
		return '';
	}

	$js = $plugin_dir . 'admin/shared/frontend/js/esf-masonry-pack__premium_only.js';
	if ( ! file_exists( $js ) ) {
		return '';
	}

	$url = $plugin_url . 'admin/shared/frontend/js/esf-masonry-pack__premium_only.js';
	if ( function_exists( 'add_query_arg' ) ) {
		$url = (string) add_query_arg( 'ver', (string) filemtime( $js ), $url );
	}

	return $url;
}
