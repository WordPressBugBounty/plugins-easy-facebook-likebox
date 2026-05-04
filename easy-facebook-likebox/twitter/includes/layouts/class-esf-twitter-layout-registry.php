<?php
/**
 * Twitter Layout Registry
 *
 * Stores registered layout class names and creates layout instances.
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
 * Class ESF_Twitter_Layout_Registry
 *
 * @since 6.7.6
 */
class ESF_Twitter_Layout_Registry {

	/**
	 * Registered layout class names keyed by layout type slug.
	 *
	 * @since 6.7.6
	 * @var array<string, string>
	 */
	private static $layouts = array();

	/**
	 * Register a layout class for a given slug.
	 *
	 * @since 6.7.6
	 * @param string $type       Layout type slug (e.g. 'timeline').
	 * @param string $class_name Fully-qualified class name.
	 * @return void
	 */
	public static function register( $type, $class_name ) {
		self::$layouts[ (string) $type ] = (string) $class_name;
	}

	/**
	 * Create a layout instance for the given type.
	 *
	 * Falls back to 'timeline' when the requested type is not registered.
	 *
	 * @since 6.7.6
	 * @param string      $type    Layout type slug.
	 * @param object      $feed    Feed object (id, name, account_id, settings).
	 * @param array       $tweets  Normalized tweet items.
	 * @param object|null $account Account object for the header.
	 * @return ESF_Twitter_Layout_Base
	 */
	public static function make( $type, $feed, $tweets, $account = null ) {
		$class = isset( self::$layouts[ $type ] ) ? self::$layouts[ $type ] : '';

		if ( '' === $class || ! class_exists( $class ) ) {
			// Fallback to the first registered layout.
			$class = reset( self::$layouts );
		}

		if ( ! $class || ! class_exists( $class ) ) {
			$class = 'ESF_Twitter_Layout_Timeline';
		}

		return new $class( $feed, $tweets, $account );
	}

	/**
	 * Get all registered layout slugs.
	 *
	 * @since 6.7.6
	 * @return string[]
	 */
	public static function get_registered_types() {
		return array_keys( self::$layouts );
	}
}
