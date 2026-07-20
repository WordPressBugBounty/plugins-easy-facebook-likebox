<?php
/**
 * Shared SEO / AEO helpers — PSR-4 autoloader.
 *
 * Registers an SPL autoloader for `EasySocialFeed\SEO\…` classes used by the
 * modern Instagram, Twitter/X, and YouTube modules. Dependency-free at
 * runtime (no Composer required).
 *
 * @package Easy_Social_Feed
 * @subpackage SEO
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ESF_SEO_AUTOLOAD_LOADED' ) ) {
	return;
}
define( 'ESF_SEO_AUTOLOAD_LOADED', true );

if ( ! defined( 'ESF_SEO_DIR' ) ) {
	define( 'ESF_SEO_DIR', __DIR__ . '/' );
}

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'EasySocialFeed\\SEO\\';
		$len    = strlen( $prefix );

		if ( 0 !== strncmp( $class, $prefix, $len ) ) {
			return;
		}

		$relative = substr( $class, $len );
		$path     = ESF_SEO_DIR . 'php/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

if ( function_exists( 'add_action' ) ) {
	add_action(
		'plugins_loaded',
		static function () {
			if ( class_exists( '\EasySocialFeed\SEO\FeedSeoIntegration' ) ) {
				\EasySocialFeed\SEO\FeedSeoIntegration::init();
			}
		},
		5
	);
}
