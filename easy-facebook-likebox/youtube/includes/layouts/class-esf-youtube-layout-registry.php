<?php
/**
 * YouTube Layout Registry.
 *
 * Registers and instantiates layout classes by type.
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
 * Class ESF_YouTube_Layout_Registry
 *
 * @since 6.7.5
 */
class ESF_YouTube_Layout_Registry {

	/**
	 * Registered layout types and class names.
	 *
	 * @since 6.7.5
	 * @var array<string, string>
	 */
	private static $layouts = array();

	/**
	 * Default layout type when requested type is not registered.
	 *
	 * @since 6.7.5
	 * @var string
	 */
	const DEFAULT_LAYOUT = 'grid';

	/**
	 * Register a layout type.
	 *
	 * @since 6.7.5
	 *
	 * @param string $type      Layout type key (e.g. 'grid', 'masonry').
	 * @param string $class_name Full class name implementing ESF_YouTube_Layout_Base.
	 * @return void
	 */
	public static function register( $type, $class_name ) {
		$type = is_string( $type ) ? trim( $type ) : '';
		if ( '' === $type || ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
			return;
		}
		self::$layouts[ $type ] = $class_name;
	}

	/**
	 * Create a layout instance for the given type.
	 *
	 * Falls back to default layout if type is not registered.
	 *
	 * @since 6.7.5
	 *
	 * @param string      $type    Layout type.
	 * @param object      $feed    Feed object with settings.
	 * @param array       $videos  Normalized video items.
	 * @param object|null $account Account object for header. Optional.
	 * @return ESF_YouTube_Layout_Base
	 */
	public static function make( $type, $feed, $videos, $account = null ) {
		$type = is_string( $type ) ? trim( $type ) : '';
		if ( '' === $type || ! isset( self::$layouts[ $type ] ) ) {
			$type = self::DEFAULT_LAYOUT;
		}
		$class_name = isset( self::$layouts[ $type ] ) ? self::$layouts[ $type ] : 'ESF_YouTube_Layout_Grid';

		return new $class_name( $feed, $videos, $account );
	}

	/**
	 * Get all registered layout types.
	 *
	 * @since 6.7.5
	 * @return array<string> Layout type keys.
	 */
	public static function get_registered_types() {
		return array_keys( self::$layouts );
	}
}
