<?php
/**
 * Shared Layouts Framework — PSR-4 autoloader.
 *
 * Registers an SPL autoloader for the `EasySocialFeed\` namespace prefix,
 * mapping `EasySocialFeed\Layouts\…` to `admin/shared/dashboard-core/layouts/php/src/…`
 * and each module's `EasySocialFeed\{Module}\Layouts\…` to its own `includes/layouts/src/…`.
 *
 * This is intentionally dependency-free so the runtime never requires
 * `composer install`. A matching `autoload` block in `composer.json` keeps
 * IDE/tooling in sync.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ESF_LAYOUTS_AUTOLOAD_LOADED' ) ) {
	return;
}
define( 'ESF_LAYOUTS_AUTOLOAD_LOADED', true );

if ( ! defined( 'ESF_LAYOUTS_DIR' ) ) {
	define( 'ESF_LAYOUTS_DIR', __DIR__ . '/' );
}

if ( ! defined( 'ESF_LAYOUTS_URL' ) && function_exists( 'plugin_dir_url' ) ) {
	define( 'ESF_LAYOUTS_URL', plugin_dir_url( __FILE__ ) );
}

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'EasySocialFeed\\' ) ) {
			return;
		}

		static $prefix_map = null;
		if ( null === $prefix_map ) {
			$plugin_dir = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';

			$prefix_map = array(
				'EasySocialFeed\\Layouts\\'           => ESF_LAYOUTS_DIR . 'php/src/',
				'EasySocialFeed\\Instagram\\Layouts\\' => $plugin_dir . 'instagram/includes/layouts/src/',
				'EasySocialFeed\\Twitter\\Layouts\\'   => $plugin_dir . 'twitter/includes/layouts/src/',
				'EasySocialFeed\\YouTube\\Layouts\\'   => $plugin_dir . 'youtube/includes/layouts/src/',
			);
		}

		foreach ( $prefix_map as $prefix => $base_dir ) {
			$len = strlen( $prefix );
			if ( 0 !== strncmp( $class, $prefix, $len ) ) {
				continue;
			}

			$relative = substr( $class, $len );
			$path     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $path ) ) {
				require_once $path;
			}
			return;
		}
	}
);
