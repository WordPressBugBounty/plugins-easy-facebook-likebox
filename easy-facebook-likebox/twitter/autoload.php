<?php
/**
 * Twitter Module Autoloader
 *
 * Defines module constants and bootstraps the Twitter module.
 * Required once from the main plugin file when the module is active.
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
 * Absolute path to the Twitter module directory (with trailing slash).
 *
 * @since 6.7.6
 */
if ( ! defined( 'ESF_TWITTER_DIR' ) ) {
	define( 'ESF_TWITTER_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * URL to the Twitter module directory (with trailing slash).
 *
 * @since 6.7.6
 */
if ( ! defined( 'ESF_TWITTER_URL' ) ) {
	define( 'ESF_TWITTER_URL', plugin_dir_url( __FILE__ ) );
}

require_once ESF_TWITTER_DIR . 'includes/traits/trait-esf-twitter-singleton.php';
require_once ESF_TWITTER_DIR . 'includes/helpers/twitter-helper-functions.php';
require_once ESF_TWITTER_DIR . 'class-esf-twitter-main.php';
ESF_Twitter_Main::get_instance();
