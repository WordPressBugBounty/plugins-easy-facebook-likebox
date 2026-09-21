<?php
/**
 * Singleton for YouTube module classes.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/Traits
 * @since 6.7.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait ESF_YouTube_Singleton
 *
 * @since 6.7.5
 */
trait ESF_YouTube_Singleton {

	/**
	 * Single instance of the class.
	 *
	 * @var object|null
	 */
	private static $instance = null;

	/**
	 * Get class instance.
	 *
	 * @since 6.7.5
	 * @return object
	 */
	final public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prevent cloning.
	 *
	 * @since 6.7.5
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing.
	 *
	 * @since 6.7.5
	 * @throws Exception When attempting to unserialize.
	 * @return void
	 */
	final public function __wakeup() {
		throw new Exception( 'Cannot unserialize singleton' );
	}
}
