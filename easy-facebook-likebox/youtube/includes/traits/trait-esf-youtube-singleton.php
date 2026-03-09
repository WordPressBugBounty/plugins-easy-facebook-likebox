<?php
/**
 * Singleton Trait
 *
 * Provides singleton pattern implementation for YouTube module classes.
 * This ensures only one instance of each class exists throughout the application.
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
 * Implements the singleton design pattern.
 * Classes using this trait will have a single instance throughout the request lifecycle.
 *
 * Usage:
 * class My_Class {
 *     use ESF_YouTube_Singleton;
 * }
 * $instance = My_Class::get_instance();
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
	 * Creates instance if it doesn't exist, returns existing instance otherwise.
	 *
	 * @since 6.7.5
	 * @return object Instance of the class.
	 */
	final public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prevent cloning of the instance.
	 *
	 * @since 6.7.5
	 * @return void
	 */
	private function __clone() {
		// Singleton pattern - prevent cloning.
	}

	/**
	 * Prevent unserializing of the instance.
	 *
	 * @since 6.7.5
	 * @throws Exception When attempting to unserialize singleton instance.
	 * @return void
	 */
	final public function __wakeup() {
		// Singleton pattern - prevent unserialization.
		throw new Exception( 'Cannot unserialize singleton' );
	}
}
