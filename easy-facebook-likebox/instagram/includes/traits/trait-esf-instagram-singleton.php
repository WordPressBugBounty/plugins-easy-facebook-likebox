<?php
/**
 * Instagram Module Singleton Trait
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait ESF_Instagram_Singleton
 *
 * @since 6.8.0
 */
trait ESF_Instagram_Singleton {

	/**
	 * Singleton instance.
	 *
	 * @var object|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
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
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws Exception When attempting to unserialize.
	 * @return void
	 */
	final public function __wakeup() {
		throw new Exception( 'Cannot unserialize singleton' );
	}
}
