<?php
/**
 * YouTube Module Autoloader
 *
 * Handles the initialization of the YouTube module only when it's activated.
 * Implements conditional loading to prevent unnecessary code execution.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube
 * @since 6.7.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if YouTube module is activated before loading.
 * This prevents loading module code when it's disabled from the modules page.
 */
$fta_settings   = get_option( 'fta_settings', array() );
$youtube_status = isset( $fta_settings['plugins']['youtube']['status'] )
	? $fta_settings['plugins']['youtube']['status']
	: 'activated';

// When status is missing or empty, treat YouTube as activated by default.
if ( '' === $youtube_status ) {
	$youtube_status = 'activated';
}

// Early exit only when explicitly deactivated.
if ( 'deactivated' === $youtube_status ) {
	return;
}

/**
 * Define YouTube module constants.
 * These constants are used throughout the module for paths.
 */
if ( ! defined( 'ESF_YOUTUBE_DIR' ) ) {
	define( 'ESF_YOUTUBE_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'ESF_YOUTUBE_URL' ) ) {
	define( 'ESF_YOUTUBE_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'ESF_YOUTUBE_ASSETS_URL' ) ) {
	define( 'ESF_YOUTUBE_ASSETS_URL', ESF_YOUTUBE_URL . 'admin/assets/' );
}

/**
 * Load core files.
 * Order matters - load dependencies first.
 */
require_once ESF_YOUTUBE_DIR . 'includes/traits/trait-esf-youtube-singleton.php';
require_once ESF_YOUTUBE_DIR . 'includes/helpers/youtube-helper-functions.php';
require_once ESF_YOUTUBE_DIR . 'includes/database/class-esf-youtube-db-installer.php';
require_once ESF_YOUTUBE_DIR . 'class-esf-youtube-main.php';

/**
 * Initialize the YouTube module.
 * Uses singleton pattern to ensure single instance.
 */
ESF_YouTube_Main::get_instance();
