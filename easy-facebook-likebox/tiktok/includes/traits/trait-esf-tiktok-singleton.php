<?php
/**
 * TikTok Module Singleton Trait
 *
 * Provides a reusable singleton pattern for TikTok module classes.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok
 * @since 6.8.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait ESF_TikTok_Singleton
 *
 * Implements the singleton pattern. Classes using this trait must declare
 * a private constructor.
 *
 * @since 6.8.0
 */
trait ESF_TikTok_Singleton {

	/**
	 * Single class instance.
	 *
	 * @since 6.8.0
	 * @var object|null
	 */
	private static $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @since 6.8.0
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
	 * @since 6.8.0
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @since 6.8.0
	 * @throws Exception When attempting to unserialize.
	 * @return void
	 */
	final public function __wakeup() {
		throw new Exception( 'Cannot unserialize singleton' );
	}
}
