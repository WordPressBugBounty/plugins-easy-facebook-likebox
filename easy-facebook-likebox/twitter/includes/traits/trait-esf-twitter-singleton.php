<?php
/**
 * Twitter Module Singleton Trait
 *
 * Provides a reusable singleton pattern for Twitter module classes.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter
 * @since 6.7.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait ESF_Twitter_Singleton
 *
 * Implements the singleton pattern. Classes using this trait must declare
 * a private constructor.
 *
 * @since 6.7.6
 */
trait ESF_Twitter_Singleton {

	/**
	 * Single class instance.
	 *
	 * @since 6.7.6
	 * @var object|null
	 */
	private static $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @since 6.7.6
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
	 * @since 6.7.6
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @since 6.7.6
	 * @throws Exception When attempting to unserialize.
	 * @return void
	 */
	final public function __wakeup() {
		throw new Exception( 'Cannot unserialize singleton' );
	}
}
