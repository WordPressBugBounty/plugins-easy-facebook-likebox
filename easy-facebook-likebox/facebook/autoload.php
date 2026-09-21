<?php
/**
 * Facebook module autoload — branches modern vs legacy via ESF_Module_System.
 *
 * Root bootstrap registers mode-aware widgets early (widgets_init); this file
 * is the single full module gate loaded from Feed_Them_All::includes().
 *
 * @package Easy_Social_Feed
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Defining Paths
 */

// Plugin Folder Path
if ( ! defined( 'EFBL_PLUGIN_DIR' ) ) {
	define( 'EFBL_PLUGIN_DIR', FTA_PLUGIN_DIR . 'facebook/' );
}

// Plugin Folder URL
if ( ! defined( 'EFBL_PLUGIN_URL' ) ) {
	define( 'EFBL_PLUGIN_URL', FTA_PLUGIN_URL . 'facebook/' );
}

// Plugin Root File
if ( ! defined( 'EFBL_PLUGIN_FILE' ) ) {
	define( 'EFBL_PLUGIN_FILE', FTA_PLUGIN_URL . 'facebook/' );
}

/*
 * Modern Facebook system constants ( ESF_INSTAGRAM_DIR/URL).
 */
if ( ! defined( 'ESF_FACEBOOK_DIR' ) ) {
	define( 'ESF_FACEBOOK_DIR', EFBL_PLUGIN_DIR );
}
if ( ! defined( 'ESF_FACEBOOK_URL' ) ) {
	define( 'ESF_FACEBOOK_URL', EFBL_PLUGIN_URL );
}

/*
 * Optional: force legacy Facebook (define true in wp-config.php).
 */
if ( ! defined( 'ESF_FACEBOOK_USE_LEGACY' ) ) {
	define( 'ESF_FACEBOOK_USE_LEGACY', false );
}

require_once EFBL_PLUGIN_DIR . 'includes/helpers/facebook-helper-functions.php';

/**
 * Load the legacy Facebook frontend / admin / skins / blocks stack.
 *
 * Frontend shortcodes previously loaded early from the root bootstrap; they
 * now load here so legacy mode stays intact after Phase 14 cleanup.
 *
 * @return void
 */
function esf_load_legacy_facebook_module() {
	require_once EFBL_PLUGIN_DIR . 'frontend/includes/core-functions.php';
	require_once EFBL_PLUGIN_DIR . 'frontend/easy-facebook-likebox.php';

	require_once EFBL_PLUGIN_DIR . 'admin/includes/efbl-skins.php';
	require_once EFBL_PLUGIN_DIR . 'admin/includes/efbl-customizer.php';
	require_once EFBL_PLUGIN_DIR . 'admin/includes/efbl-customizer-extend.php';
	require_once EFBL_PLUGIN_DIR . 'admin/class-easy-facebook-likebox-admin.php';

	if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
		$esf_facebook_multifeed = EFBL_PLUGIN_DIR . 'multifeed/autoload.php';
		if ( file_exists( $esf_facebook_multifeed ) ) {
			require_once $esf_facebook_multifeed;
		}
	}

	if ( function_exists( 'register_block_type' ) ) {
		require_once EFBL_PLUGIN_DIR . 'includes/blocks/esf-fb-blocks.php';
	}
}

if ( esf_facebook_use_new_system() ) {
	if ( ! trait_exists( 'ESF_Facebook_Singleton', false ) ) {
		require_once ESF_FACEBOOK_DIR . 'includes/traits/trait-esf-facebook-singleton.php';
	}
	require_once EFBL_PLUGIN_DIR . 'class-esf-facebook-main.php';
	ESF_Facebook_Main::get_instance();

	// Blocks keep the same attribute contract; render_callback adapts in modern mode.
	if ( function_exists( 'register_block_type' ) ) {
		require_once EFBL_PLUGIN_DIR . 'includes/blocks/esf-fb-blocks.php';
	}
} else {
	add_action( 'admin_init', 'esf_facebook_handle_legacy_migration_action' );
	add_action( 'admin_notices', 'esf_facebook_render_legacy_migration_notice' );
	esf_load_legacy_facebook_module();
}
