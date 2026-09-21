<?php
/**
 * TikTok Module Autoloader
 *
 * Defines module constants and bootstraps the TikTok module.
 * Required once from the main plugin file when the module is active.
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
 * Absolute path to the TikTok module directory (with trailing slash).
 *
 * @since 6.8.0
 */
if ( ! defined( 'ESF_TIKTOK_DIR' ) ) {
	define( 'ESF_TIKTOK_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * URL to the TikTok module directory (with trailing slash).
 *
 * @since 6.8.0
 */
if ( ! defined( 'ESF_TIKTOK_URL' ) ) {
	define( 'ESF_TIKTOK_URL', plugin_dir_url( __FILE__ ) );
}

require_once ESF_TIKTOK_DIR . 'includes/traits/trait-esf-tiktok-singleton.php';
require_once ESF_TIKTOK_DIR . 'includes/helpers/tiktok-helper-functions.php';
require_once ESF_TIKTOK_DIR . 'class-esf-tiktok-main.php';
ESF_TikTok_Main::get_instance();
