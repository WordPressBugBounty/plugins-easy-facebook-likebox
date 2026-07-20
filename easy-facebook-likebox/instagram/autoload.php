<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/*
* Define Instagram module directory
*/
if ( !defined( 'ESF_INSTA_PLUGIN_DIR' ) ) {
    define( 'ESF_INSTA_PLUGIN_DIR', FTA_PLUGIN_DIR . '/instagram/' );
}
/*
* Define Instagram module URL
*/
if ( !defined( 'ESF_INSTA_PLUGIN_URL' ) ) {
    define( 'ESF_INSTA_PLUGIN_URL', FTA_PLUGIN_URL . '/instagram/' );
}
/*
* Define Instagram module file
*/
if ( !defined( 'ESF_INSTA_PLUGIN_FILE' ) ) {
    define( 'ESF_INSTA_PLUGIN_FILE', FTA_PLUGIN_FILE . '/instagram/' );
}
/*
* New Instagram system constants
*/
if ( !defined( 'ESF_INSTAGRAM_DIR' ) ) {
    define( 'ESF_INSTAGRAM_DIR', ESF_INSTA_PLUGIN_DIR );
}
if ( !defined( 'ESF_INSTAGRAM_URL' ) ) {
    define( 'ESF_INSTAGRAM_URL', ESF_INSTA_PLUGIN_URL );
}
/*
 * Optional: force legacy Instagram (define true in wp-config.php). Defaults to off so migrated sites load the new admin.
 */
if ( !defined( 'ESF_INSTAGRAM_USE_LEGACY' ) ) {
    define( 'ESF_INSTAGRAM_USE_LEGACY', false );
}
require_once ESF_INSTA_PLUGIN_DIR . 'includes/helpers/instagram-helper-functions.php';
/**
 * Load the legacy Instagram module stack.
 *
 * @return void
 */
function esf_load_legacy_instagram_module() {
    require_once ESF_INSTA_PLUGIN_DIR . 'includes/esf-insta-helper-functions.php';
    if ( !class_exists( 'ESF_Insta_Skins' ) ) {
        include ESF_INSTA_PLUGIN_DIR . 'admin/includes/class-esf-insta-skins.php';
    }
    require_once ESF_INSTA_PLUGIN_DIR . 'admin/includes/class-esf-insta-customizer-extend.php';
    if ( !class_exists( 'ESF_Insta_Customizer' ) ) {
        include ESF_INSTA_PLUGIN_DIR . 'admin/includes/class-esf-insta-customizer.php';
    }
    if ( !class_exists( 'ESF_Instagram_Admin' ) ) {
        include ESF_INSTA_PLUGIN_DIR . 'admin/class-easy-facebook-likebox-instagram-admin.php';
    }
    require_once ESF_INSTA_PLUGIN_DIR . 'frontend/class-easy-facebook-likebox-instagram-frontend.php';
}

if ( esf_instagram_use_new_system() ) {
    // Trait must load before the main class file is parsed (trait is used at compile time).
    if ( !trait_exists( 'ESF_Instagram_Singleton', false ) ) {
        require_once ESF_INSTAGRAM_DIR . 'includes/traits/trait-esf-instagram-singleton.php';
    }
    require_once ESF_INSTA_PLUGIN_DIR . 'class-esf-instagram-main.php';
    ESF_Instagram_Main::get_instance();
    /*
     * Modern Multifeed is provided by the Multifeed addon only
     * (`esf-multifeed` / Esf_Multifeed_Instagram_Modern). Do not load the
     * bundled Multifeed fallback here — otherwise deactivating the addon
     * still leaves multi-select / merge enabled on Pro parent plans.
     */
} else {
    add_action( 'admin_init', 'esf_instagram_handle_legacy_migration_action' );
    add_action( 'admin_notices', 'esf_instagram_render_legacy_migration_notice' );
    esf_load_legacy_instagram_module();
}