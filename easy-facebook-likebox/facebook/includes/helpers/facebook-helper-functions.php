<?php

/**
 * Facebook Module Helper Functions
 *
 * Global helpers for the modern Facebook module stack.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/Helpers
 * @since 6.7.9
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Check if the Facebook module is currently active.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_is_facebook_active() {
    $fta_settings = get_option( 'fta_settings', array() );
    $status = ( isset( $fta_settings['plugins']['facebook']['status'] ) ? $fta_settings['plugins']['facebook']['status'] : 'deactivated' );
    return 'activated' === $status;
}

/**
 * Determine if the site should use the modern Facebook system.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_facebook_use_new_system() {
    if ( !class_exists( 'ESF_Module_System' ) ) {
        $mode = get_option( 'esf_facebook_use_new_system', null );
        if ( 'new' === $mode ) {
            return true;
        }
        if ( defined( 'ESF_FACEBOOK_USE_LEGACY' ) && ESF_FACEBOOK_USE_LEGACY ) {
            return false;
        }
        if ( 'legacy' === $mode ) {
            return false;
        }
        if ( esf_facebook_has_legacy_data() ) {
            return false;
        }
        update_option( 'esf_facebook_use_new_system', 'new' );
        return true;
    }
    return ESF_Module_System::resolve_use_new_system( 'facebook', 'esf_facebook_has_legacy_data', array(
        'force_legacy_constant' => 'ESF_FACEBOOK_USE_LEGACY',
    ) );
}

/**
 * Whether legacy Facebook data exists in fta_settings.
 *
 * Checks for a non-empty access_token or approved_pages.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_facebook_has_legacy_data() {
    $fta_settings = get_option( 'fta_settings', array() );
    $fb = ( isset( $fta_settings['plugins']['facebook'] ) && is_array( $fta_settings['plugins']['facebook'] ) ? $fta_settings['plugins']['facebook'] : array() );
    $token = ( isset( $fb['access_token'] ) ? trim( (string) $fb['access_token'] ) : '' );
    if ( '' !== $token ) {
        return true;
    }
    $pages = ( isset( $fb['approved_pages'] ) ? $fb['approved_pages'] : array() );
    return is_array( $pages ) && !empty( $pages );
}

/**
 * Return Facebook legacy/modern module-system status for REST/admin UI.
 *
 * @since 6.7.9
 * @return array<string,mixed>
 */
function esf_facebook_get_module_system_status() {
    if ( !class_exists( 'ESF_Module_System' ) ) {
        return array(
            'mode'                 => ( esf_facebook_has_legacy_data() ? 'legacy' : 'new' ),
            'legacy_present'       => esf_facebook_has_legacy_data(),
            'needs_migration'      => esf_facebook_has_legacy_data() && !esf_facebook_use_new_system(),
            'can_switch_to_legacy' => esf_facebook_has_legacy_data() && 'new' === get_option( 'esf_facebook_use_new_system', null ),
            'using_modern'         => esf_facebook_use_new_system(),
            'legacy_admin_url'     => admin_url( 'admin.php?page=easy-facebook-likebox' ),
            'modern_admin_url'     => admin_url( 'admin.php?page=esf-facebook' ),
        );
    }
    return ESF_Module_System::get_status( 'facebook', 'esf_facebook_has_legacy_data' );
}

/**
 * Resolve the default feed created during legacy → modern Facebook migration.
 *
 * @since 6.7.9
 * @return int Feed id or 0 when unavailable.
 */
function esf_facebook_get_migration_default_feed_id() {
    $stored_id = (int) get_option( 'esf_facebook_migration_default_feed_id', 0 );
    if ( $stored_id > 0 && class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
        $row = ESF_Facebook_Feed_Repository::get_instance()->get_by_id( $stored_id );
        if ( $row ) {
            return $stored_id;
        }
    }
    if ( !class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
        return 0;
    }
    $feeds = ESF_Facebook_Feed_Repository::get_instance()->get_all( array(
        'content_type' => 'feed',
    ) );
    if ( !empty( $feeds ) && is_object( $feeds[0] ) && !empty( $feeds[0]->id ) ) {
        return (int) $feeds[0]->id;
    }
    return 0;
}

/**
 * Whether a legacy shortcode → feed alias map exists.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_facebook_legacy_feed_map_has_entries() {
    if ( class_exists( 'ESF_Facebook_Legacy_Feed_Map', false ) ) {
        return ESF_Facebook_Legacy_Feed_Map::has_entries();
    }
    $map = get_option( 'esf_facebook_legacy_feed_map', array() );
    return is_array( $map ) && !empty( $map );
}

/**
 * Resolve a modern feed id for legacy [efb_feed] / [efb_likebox] attributes.
 *
 * @since 6.7.9
 *
 * @param array<string,mixed> $atts            Shortcode / block attributes.
 * @param int                 $default_feed_id Fallback feed id.
 * @return int
 */
function esf_facebook_resolve_legacy_shortcode_feed_id(  $atts, $default_feed_id = 0  ) {
    $default_feed_id = absint( $default_feed_id );
    if ( $default_feed_id <= 0 ) {
        $default_feed_id = esf_facebook_get_migration_default_feed_id();
    }
    if ( !class_exists( 'ESF_Facebook_Legacy_Feed_Map', false ) ) {
        $dir = ( defined( 'ESF_FACEBOOK_DIR' ) ? ESF_FACEBOOK_DIR : (( defined( 'EFBL_PLUGIN_DIR' ) ? EFBL_PLUGIN_DIR : '' )) );
        if ( '' !== $dir ) {
            $map_file = $dir . 'includes/class-esf-facebook-legacy-feed-map.php';
            if ( file_exists( $map_file ) ) {
                require_once $map_file;
            }
        }
    }
    if ( !class_exists( 'ESF_Facebook_Legacy_Feed_Map', false ) ) {
        return $default_feed_id;
    }
    return ( new ESF_Facebook_Legacy_Feed_Map() )->resolve_shortcode_atts( $atts, $default_feed_id );
}

/**
 * Admin notice prompting migration from the legacy Facebook admin.
 *
 * @since 6.7.9
 * @return void
 */
function esf_facebook_render_legacy_migration_notice() {
    if ( !is_admin() || esf_facebook_use_new_system() ) {
        return;
    }
    if ( !isset( $_GET['page'] ) || 'easy-facebook-likebox' !== sanitize_key( (string) wp_unslash( $_GET['page'] ) ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }
    if ( !current_user_can( 'manage_options' ) ) {
        return;
    }
    static $esf_facebook_migrate_overlay_registered = false;
    if ( !$esf_facebook_migrate_overlay_registered ) {
        $esf_facebook_migrate_overlay_registered = true;
        add_action( 'admin_print_footer_scripts', 'esf_facebook_print_legacy_migrate_overlay_script', 20 );
    }
    $has_legacy_data = esf_facebook_has_legacy_data();
    $migrate_url = wp_nonce_url( add_query_arg( array(
        'page'                 => 'easy-facebook-likebox',
        'esf_facebook_migrate' => '1',
    ), admin_url( 'admin.php' ) ), 'esf_facebook_migrate_legacy', 'esf_facebook_migrate_nonce' );
    ?>
	<div class="notice notice-warning fs-notice fs-slug-easy-facebook-likebox">
		<p><strong><?php 
    esc_html_e( 'A new Facebook experience is here', 'easy-facebook-likebox' );
    ?></strong></p>
		<?php 
    if ( $has_legacy_data ) {
        ?>
			<p>
				<?php 
        esc_html_e( 'We have rebuilt Facebook from the ground up so it is faster, easier to set up, and simpler to manage. Connecting Pages, customizing how feeds look, and showing them on your site has been redesigned to save you time.', 'easy-facebook-likebox' );
        ?>
			</p>
			<p>
				<?php 
        esc_html_e( 'The screens you are using now belong to the older version. They still work for now, but they will be removed in a future release and only the new system will receive updates, fixes, and new features. Switch over to copy your connected Pages into the new system. Existing shortcodes, widgets, and blocks keep working via compatibility adapters. You can switch back temporarily from the new Facebook admin if needed.', 'easy-facebook-likebox' );
        ?>
			</p>
		<?php 
    } else {
        ?>
			<p>
				<?php 
        esc_html_e( 'You are viewing the older Facebook admin. This is usually opened for support or testing. Switch back to the new Facebook dashboard to continue managing Pages and feeds.', 'easy-facebook-likebox' );
        ?>
			</p>
		<?php 
    }
    ?>
		<p>
			<a id="esf-facebook-migrate-btn" class="button button-primary" href="<?php 
    echo esc_url( $migrate_url );
    ?>">
				<?php 
    esc_html_e( 'Switch to the new Facebook', 'easy-facebook-likebox' );
    ?>
			</a>
		</p>
	</div>
	<?php 
}

/**
 * Full-screen loader while legacy Facebook migration request runs.
 *
 * @since 6.7.9
 * @return void
 */
function esf_facebook_print_legacy_migrate_overlay_script() {
    if ( !isset( $_GET['page'] ) || 'easy-facebook-likebox' !== sanitize_key( (string) wp_unslash( $_GET['page'] ) ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }
    if ( !current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
	<style id="esf-facebook-migrate-overlay-style">
		#esf-facebook-migrate-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100000;display:none;align-items:center;justify-content:center;}
		#esf-facebook-migrate-overlay.is-visible{display:flex;}
		#esf-facebook-migrate-overlay .esf-facebook-migrate-overlay-inner{background:#fff;padding:28px 36px;border-radius:4px;box-shadow:0 4px 24px rgba(0,0,0,.2);text-align:center;max-width:360px;}
		#esf-facebook-migrate-overlay .spinner{float:none;margin:0 auto 12px;visibility:visible;}
	</style>
	<div id="esf-facebook-migrate-overlay" aria-hidden="true">
		<div class="esf-facebook-migrate-overlay-inner">
			<span class="spinner is-active"></span>
			<p style="margin:0;font-size:14px;"><?php 
    esc_html_e( 'Switching your Facebook setup…', 'easy-facebook-likebox' );
    ?></p>
			<p style="margin:12px 0 0;font-size:13px;color:#646970;"><?php 
    esc_html_e( 'Please wait. You will be redirected when migration finishes.', 'easy-facebook-likebox' );
    ?></p>
		</div>
	</div>
	<script>
	(function () {
		var btn = document.getElementById('esf-facebook-migrate-btn');
		if (!btn) {
			return;
		}
		btn.addEventListener('click', function (e) {
			var overlay = document.getElementById('esf-facebook-migrate-overlay');
			if (!overlay) {
				return;
			}
			e.preventDefault();
			overlay.classList.add('is-visible');
			overlay.setAttribute('aria-hidden', 'false');
			window.location.href = btn.getAttribute('href');
		});
	}());
	</script>
	<?php 
}

/**
 * Handle legacy-to-modern migration action from admin notice.
 *
 * @since 6.7.9
 * @return void
 */
function esf_facebook_handle_legacy_migration_action() {
    if ( !is_admin() ) {
        return;
    }
    if ( !isset( $_GET['page'], $_GET['esf_facebook_migrate'] ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }
    $page = sanitize_key( (string) wp_unslash( $_GET['page'] ) );
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( 'easy-facebook-likebox' !== $page ) {
        return;
    }
    if ( '1' !== sanitize_text_field( (string) wp_unslash( $_GET['esf_facebook_migrate'] ) ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }
    if ( !current_user_can( 'manage_options' ) ) {
        return;
    }
    $nonce = ( isset( $_GET['esf_facebook_migrate_nonce'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['esf_facebook_migrate_nonce'] ) ) : '' );
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( '' === $nonce || !wp_verify_nonce( $nonce, 'esf_facebook_migrate_legacy' ) ) {
        return;
    }
    $dir = ( defined( 'ESF_FACEBOOK_DIR' ) ? ESF_FACEBOOK_DIR : (( defined( 'EFBL_PLUGIN_DIR' ) ? EFBL_PLUGIN_DIR : '' )) );
    if ( '' === $dir ) {
        return;
    }
    if ( !trait_exists( 'ESF_Facebook_Singleton', false ) ) {
        require_once $dir . 'includes/traits/trait-esf-facebook-singleton.php';
    }
    if ( !class_exists( 'ESF_Facebook_DB_Installer' ) ) {
        require_once $dir . 'includes/database/class-esf-facebook-db-installer.php';
    }
    if ( !class_exists( 'ESF_Facebook_Account_Repository' ) ) {
        require_once $dir . 'includes/models/class-esf-facebook-account-repository.php';
    }
    if ( !class_exists( 'ESF_Facebook_Page_Repository' ) ) {
        require_once $dir . 'includes/models/class-esf-facebook-page-repository.php';
    }
    if ( !class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
        require_once $dir . 'includes/models/class-esf-facebook-feed-repository.php';
    }
    if ( !class_exists( 'ESF_Facebook_Migrator' ) ) {
        require_once $dir . 'includes/class-esf-facebook-migrator.php';
    }
    if ( !class_exists( 'ESF_Facebook_Widget_Migrator' ) ) {
        require_once $dir . 'includes/class-esf-facebook-widget-migrator.php';
    }
    if ( !class_exists( 'ESF_Facebook_Legacy_Feed_Map' ) ) {
        require_once $dir . 'includes/class-esf-facebook-legacy-feed-map.php';
    }
    if ( !class_exists( 'ESF_Facebook_Legacy_Shortcode_Rewriter' ) ) {
        require_once $dir . 'includes/class-esf-facebook-legacy-shortcode-rewriter.php';
    }
    if ( !class_exists( 'ESF_Facebook_Graph_Client' ) ) {
        require_once $dir . 'includes/class-esf-facebook-graph-client.php';
    }
    if ( !class_exists( 'ESF_Facebook_Local_Media' ) ) {
        require_once $dir . 'includes/class-esf-facebook-local-media.php';
    }
    if ( !ESF_Facebook_DB_Installer::tables_exist() ) {
        ESF_Facebook_DB_Installer::create_tables();
    }
    ESF_Facebook_Migrator::get_instance()->migrate();
    wp_safe_redirect( add_query_arg( array(
        'page'            => 'esf-facebook',
        'esf_fb_migrated' => '1',
    ), admin_url( 'admin.php' ) ) );
    exit;
}

/**
 * Default Facebook module settings.
 *
 * @since 6.7.9
 * @return array<string,mixed>
 */
function esf_facebook_get_settings_defaults() {
    return array(
        'cache_duration'                => 43200,
        'notify_token_reconnect'        => true,
        'notify_token_reconnect_emails' => array(),
    );
}

/**
 * Merge saved Facebook settings with module defaults.
 *
 * @since 6.7.9
 * @param array<string, mixed> $saved Raw option values.
 * @return array<string, mixed>
 */
function esf_facebook_normalize_settings(  array $saved  ) {
    $settings = array_merge( esf_facebook_get_settings_defaults(), $saved );
    $allowed = array(
        3600,
        43200,
        86400,
        604800
    );
    if ( !in_array( (int) $settings['cache_duration'], $allowed, true ) ) {
        $settings['cache_duration'] = 43200;
    }
    $emails = ( isset( $settings['notify_token_reconnect_emails'] ) && is_array( $settings['notify_token_reconnect_emails'] ) ? $settings['notify_token_reconnect_emails'] : array() );
    $settings['notify_token_reconnect_emails'] = ( function_exists( 'esf_sanitize_notify_emails' ) ? esf_sanitize_notify_emails( $emails ) : array() );
    if ( !empty( $settings['notify_token_reconnect'] ) && empty( $settings['notify_token_reconnect_emails'] ) && function_exists( 'esf_default_notify_emails' ) ) {
        $settings['notify_token_reconnect_emails'] = esf_default_notify_emails();
    }
    return $settings;
}

/**
 * Get Facebook module settings.
 *
 * Applies defaults; all cache whitelist intervals are available on free and Pro.
 *
 * @since 6.7.9
 * @param string|null $key Optional. Specific setting key.
 * @return mixed
 */
function esf_get_facebook_settings(  $key = null  ) {
    $saved = get_option( 'esf_facebook_settings', array() );
    if ( !is_array( $saved ) ) {
        $saved = array();
    }
    $settings = esf_facebook_normalize_settings( $saved );
    if ( null !== $key ) {
        return ( array_key_exists( $key, $settings ) ? $settings[$key] : null );
    }
    return $settings;
}

/**
 * Resolved Facebook settings for runtime (cron, mailers, cache TTL).
 *
 * Alias of esf_get_facebook_settings() with defaults applied.
 *
 * @since 6.7.9
 * @return array<string, mixed>
 */
function esf_facebook_get_resolved_settings() {
    $settings = esf_get_facebook_settings();
    return ( is_array( $settings ) ? $settings : esf_facebook_get_settings_defaults() );
}

/**
 * Update Facebook module settings.
 *
 * @since 6.7.9
 * @param string|array $key   Setting key or array of settings.
 * @param mixed        $value Setting value (ignored when $key is an array).
 * @return bool
 */
function esf_update_facebook_settings(  $key, $value = null  ) {
    $settings = esf_get_facebook_settings();
    if ( is_array( $key ) ) {
        $settings = array_merge( $settings, $key );
    } else {
        $settings[$key] = $value;
    }
    return update_option( 'esf_facebook_settings', $settings );
}

/**
 * Check if the current user can manage the Facebook module.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_facebook_user_can_manage() {
    /**
     * Filters the capability required to manage the Facebook module.
     *
     * @since 6.7.9
     * @param string $capability Required capability.
     */
    $capability = apply_filters( 'esf_facebook_manage_capability', 'manage_options' );
    return current_user_can( $capability );
}

/**
 * Resolved `auth_source` for a Facebook account row.
 *
 * @since 6.7.9
 * @param object|null $row Account DB row.
 * @return string `oauth` or `manual`.
 */
function esf_facebook_auth_source_for_account_row(  $row  ) {
    if ( is_object( $row ) && isset( $row->auth_source ) ) {
        $src = sanitize_key( (string) $row->auth_source );
        if ( class_exists( 'ESF_Manual_Access_Token' ) ) {
            return ESF_Manual_Access_Token::normalize_auth_source( $src );
        }
        return ( 'manual' === $src ? 'manual' : 'oauth' );
    }
    return ( class_exists( 'ESF_Manual_Access_Token' ) ? ESF_Manual_Access_Token::AUTH_OAUTH : 'oauth' );
}

/**
 * Whether the account uses hosted OAuth token refresh (not manual paste).
 *
 * @since 6.7.9
 * @param object|null $row Account DB row.
 * @return bool
 */
function esf_facebook_account_supports_token_refresh(  $row  ) {
    if ( !class_exists( 'ESF_Manual_Access_Token' ) ) {
        return true;
    }
    return ESF_Manual_Access_Token::supports_hosted_refresh( esf_facebook_auth_source_for_account_row( $row ) );
}

/**
 * Load the account row linked to a page row (when available).
 *
 * @since 6.7.9
 * @param object|null $page_row Page DB row.
 * @return object|null
 */
function esf_facebook_get_account_row_for_page(  $page_row  ) {
    if ( !is_object( $page_row ) || empty( $page_row->account_id ) || !class_exists( 'ESF_Facebook_Account_Repository' ) ) {
        return null;
    }
    return ESF_Facebook_Account_Repository::get_instance()->get_by_id( (int) $page_row->account_id );
}

/**
 * Whether a dedicated Events overlay token is stored on the page row.
 *
 * @since 6.7.9
 * @param object|null $page_row Page DB row.
 * @return bool
 */
function esf_facebook_page_has_dedicated_events_token(  $page_row  ) {
    if ( !is_object( $page_row ) ) {
        return false;
    }
    $events_token = ( isset( $page_row->events_access_token ) ? trim( (string) $page_row->events_access_token ) : '' );
    return '' !== $events_token;
}

/**
 * Whether a Page can fetch Events via Graph (dedicated `events_access_token`).
 *
 * Facebook no longer returns Events for Connect-with-Facebook (OAuth) Page tokens alone.
 * Manual connect `page_access_token` is also not enough — many pasted tokens load the
 * timeline but not `/events`. Events always need a dedicated Page token from the
 * user's Facebook app (Development mode is enough).
 *
 * @since 6.7.9
 * @param object|null $page_row     Page DB row.
 * @param object|null $account_row  Unused; kept for call-site compatibility.
 * @return bool
 */
function esf_facebook_page_has_events_token(  $page_row, $account_row = null  ) {
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
    return esf_facebook_page_has_dedicated_events_token( $page_row );
}

/**
 * Resolve the Graph token used to fetch feed content (not header/profile).
 *
 * Events feeds use only `events_access_token`. Connect / manual `page_access_token`
 * is never used for Events — Facebook requires a Facebook-app Page token. Other
 * feed types use `page_access_token`.
 *
 * @since 6.7.9
 * @param object|null $page_row     Page DB row.
 * @param string      $feed_type    Canonical feed type.
 * @param object|null $account_row  Unused; kept for call-site compatibility.
 * @return string Non-empty token or empty string.
 */
function esf_facebook_resolve_content_graph_token(  $page_row, $feed_type, $account_row = null  ) {
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
    if ( !is_object( $page_row ) ) {
        return '';
    }
    $page_token = ( isset( $page_row->page_access_token ) ? trim( (string) $page_row->page_access_token ) : '' );
    $feed_type = sanitize_key( (string) $feed_type );
    if ( 'events' !== $feed_type ) {
        return $page_token;
    }
    $events_token = ( isset( $page_row->events_access_token ) ? trim( (string) $page_row->events_access_token ) : '' );
    return $events_token;
}

/**
 * Whether a Graph error indicates Events need a dedicated Page token overlay.
 *
 * @since 6.7.9
 * @param mixed $error Graph error (typically WP_Error).
 * @return bool
 */
function esf_facebook_graph_error_suggests_events_token(  $error  ) {
    if ( !$error instanceof WP_Error ) {
        return false;
    }
    $message = strtolower( $error->get_error_message() );
    $hints = array(
        'page public content',
        '(#10)',
        'permission',
        'access token',
        'oauth',
        'invalid token'
    );
    foreach ( $hints as $hint ) {
        if ( str_contains( $message, $hint ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Whether an Events feed still needs a Page token from Facebook developer tools.
 *
 * True when `feed_type` is events and no dedicated `events_access_token` is saved
 * (OAuth and manual connects alike).
 *
 * @since 6.7.9
 * @param object|null $page_row     Page DB row.
 * @param string      $feed_type    Canonical feed type.
 * @param object|null $account_row  Unused; kept for call-site compatibility.
 * @return bool
 */
function esf_facebook_events_token_required_for_feed(  $page_row, $feed_type, $account_row = null  ) {
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
    if ( 'events' !== sanitize_key( (string) $feed_type ) ) {
        return false;
    }
    return !esf_facebook_page_has_dedicated_events_token( $page_row );
}

/**
 * Parse Graph `debug_token` JSON into a public-safe Events token expiry.
 *
 * `expires_at: 0` means Facebook reports the token does not expire.
 *
 * @since 6.7.9
 * @param array<string,mixed>|WP_Error|mixed $debug Graph JSON or error.
 * @return array{expires_at: ?string, never_expires: bool}
 */
function esf_facebook_events_token_expiry_from_debug(  $debug  ) {
    $unknown = array(
        'expires_at'    => null,
        'never_expires' => false,
    );
    if ( is_wp_error( $debug ) || !is_array( $debug ) ) {
        return $unknown;
    }
    $data = ( isset( $debug['data'] ) && is_array( $debug['data'] ) ? $debug['data'] : $debug );
    if ( !isset( $data['expires_at'] ) ) {
        return $unknown;
    }
    $raw = $data['expires_at'];
    if ( !is_numeric( $raw ) ) {
        return $unknown;
    }
    $ts = (int) $raw;
    if ( 0 === $ts ) {
        return array(
            'expires_at'    => null,
            'never_expires' => true,
        );
    }
    if ( $ts > 0 ) {
        return array(
            'expires_at'    => gmdate( 'Y-m-d H:i:s', $ts ),
            'never_expires' => false,
        );
    }
    return $unknown;
}

/**
 * Inspect a pasted Events token and return expiry for storage.
 *
 * Graph inspect failures are non-fatal: the token already passed Events probes.
 *
 * @since 6.7.9
 * @param string $access_token Page access token.
 * @return array{expires_at: ?string, never_expires: bool}
 */
function esf_facebook_inspect_events_token_expiry(  $access_token  ) {
    $unknown = array(
        'expires_at'    => null,
        'never_expires' => false,
    );
    if ( !class_exists( 'ESF_Facebook_Graph_Client' ) ) {
        return $unknown;
    }
    $debug = ESF_Facebook_Graph_Client::inspect_access_token( $access_token );
    return esf_facebook_events_token_expiry_from_debug( $debug );
}

/**
 * Public REST expiry fields for a Page row (never exposes the token).
 *
 * @since 6.7.9
 * @param object|null $page_row Page row.
 * @return array{expires_at: string, never_expires: bool}
 */
function esf_facebook_page_events_token_expiry_public(  $page_row  ) {
    $never = is_object( $page_row ) && !empty( $page_row->events_token_never_expires );
    $at = ( is_object( $page_row ) && isset( $page_row->events_token_expires_at ) ? trim( (string) $page_row->events_token_expires_at ) : '' );
    if ( '0000-00-00 00:00:00' === $at ) {
        $at = '';
    }
    return array(
        'expires_at'    => ( $never ? '' : $at ),
        'never_expires' => (bool) $never,
    );
}

/**
 * Hash fragment for the Connect panel Events token section.
 *
 * Used by frontend admin notices and expiry emails so deep links scroll to
 * Update / paste token UI.
 *
 * @since 6.7.9
 */
if ( !defined( 'ESF_FACEBOOK_EVENTS_TOKEN_PANEL_FRAGMENT' ) ) {
    define( 'ESF_FACEBOOK_EVENTS_TOKEN_PANEL_FRAGMENT', 'esf-fb-events-token' );
}
/**
 * Admin URL for the modern Facebook feed editor.
 *
 * @since 6.7.9
 * @param int    $feed_id  Feed id.
 * @param string $fragment Optional hash without leading # (e.g. events token panel).
 * @return string
 */
function esf_facebook_get_feed_editor_admin_url(  $feed_id, $fragment = ''  ) {
    if ( !function_exists( 'admin_url' ) ) {
        return '';
    }
    $feed_id = (int) $feed_id;
    if ( $feed_id <= 0 ) {
        return (string) admin_url( 'admin.php?page=esf-facebook&screen=feeds' );
    }
    $url = (string) admin_url( 'admin.php?page=esf-facebook&screen=feeds&feed_id=' . $feed_id );
    $fragment = sanitize_key( ltrim( (string) $fragment, '#' ) );
    if ( '' !== $fragment ) {
        $url .= '#' . $fragment;
    }
    return $url;
}

/**
 * Admin URL to update a Page's Events token (prefer an Events feed for that Page).
 *
 * @since 6.7.9
 * @param int $page_row_id Internal pages.id.
 * @return string
 */
function esf_facebook_get_events_token_update_admin_url(  $page_row_id  ) {
    $page_row_id = (int) $page_row_id;
    if ( $page_row_id > 0 && class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
        global $wpdb;
        if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && method_exists( $wpdb, 'prepare' ) ) {
            $feeds = ESF_Facebook_Feed_Repository::get_instance()->get_by_page_id( $page_row_id );
            foreach ( $feeds as $feed ) {
                if ( !is_object( $feed ) || empty( $feed->id ) ) {
                    continue;
                }
                $feed_type = ( isset( $feed->feed_type ) ? sanitize_key( (string) $feed->feed_type ) : '' );
                if ( 'events' === $feed_type ) {
                    return esf_facebook_get_feed_editor_admin_url( (int) $feed->id, ESF_FACEBOOK_EVENTS_TOKEN_PANEL_FRAGMENT );
                }
            }
        }
    }
    if ( !function_exists( 'admin_url' ) ) {
        return '';
    }
    return (string) admin_url( 'admin.php?page=esf-facebook&screen=feeds' );
}

/**
 * Whether a dedicated Events token has a known expiry in the past.
 *
 * Unknown expiry and never-expires tokens return false.
 *
 * @since 6.7.9
 * @param object|null $page_row Page row.
 * @return bool
 */
function esf_facebook_page_events_token_is_expired(  $page_row  ) {
    if ( !esf_facebook_page_has_dedicated_events_token( $page_row ) ) {
        return false;
    }
    if ( !empty( $page_row->events_token_never_expires ) ) {
        return false;
    }
    $at = ( isset( $page_row->events_token_expires_at ) ? trim( (string) $page_row->events_token_expires_at ) : '' );
    if ( '' === $at || '0000-00-00 00:00:00' === $at ) {
        return false;
    }
    $ts = strtotime( $at . ' UTC' );
    return false !== $ts && $ts < time();
}

/**
 * Validate and store a Page-specific events access token.
 *
 * @since 6.7.9
 * @param int    $page_row_id Internal pages row id.
 * @param string $access_token Raw pasted Page token.
 * @return true|WP_Error
 */
function esf_facebook_save_page_events_token(  $page_row_id, $access_token  ) {
    $page_row_id = (int) $page_row_id;
    if ( $page_row_id <= 0 || !class_exists( 'ESF_Facebook_Page_Repository' ) || !class_exists( 'ESF_Manual_Access_Token' ) ) {
        return new WP_Error('esf_fb_events_token_unavailable', __( 'Events token setup is not available.', 'easy-facebook-likebox' ), array(
            'status' => 500,
        ));
    }
    $access_token = ESF_Manual_Access_Token::sanitize( $access_token );
    if ( !ESF_Manual_Access_Token::is_valid_format( $access_token ) ) {
        return new WP_Error('esf_fb_invalid_token', __( 'Enter a valid Facebook Page access token.', 'easy-facebook-likebox' ), array(
            'status' => 400,
        ));
    }
    $page_repo = ESF_Facebook_Page_Repository::get_instance();
    $page_row = $page_repo->get_by_id( $page_row_id );
    if ( !$page_row || empty( $page_row->page_id ) ) {
        return new WP_Error('esf_fb_page_not_found', __( 'Page not found.', 'easy-facebook-likebox' ), array(
            'status' => 404,
        ));
    }
    $fb_page_id = sanitize_text_field( (string) $page_row->page_id );
    if ( !class_exists( 'ESF_Facebook_Graph_Client' ) ) {
        return new WP_Error('esf_fb_graph_unavailable', __( 'Facebook Graph client is not available.', 'easy-facebook-likebox' ), array(
            'status' => 500,
        ));
    }
    $profile = ESF_Facebook_Graph_Client::fetch_page_profile( $fb_page_id, $access_token );
    if ( is_wp_error( $profile ) ) {
        return $profile;
    }
    $probe = ESF_Facebook_Graph_Client::fetch_page_content(
        $fb_page_id,
        $access_token,
        'events',
        array(
            'limit' => 1,
        )
    );
    if ( is_wp_error( $probe ) ) {
        return new WP_Error('esf_fb_events_token_rejected', $probe->get_error_message(), array(
            'status' => 400,
        ));
    }
    $expiry = ( function_exists( 'esf_facebook_inspect_events_token_expiry' ) ? esf_facebook_inspect_events_token_expiry( $access_token ) : array(
        'expires_at'    => null,
        'never_expires' => false,
    ) );
    $row_id = $page_repo->upsert( array(
        'account_id'                 => ( isset( $page_row->account_id ) ? (int) $page_row->account_id : 0 ),
        'page_id'                    => $fb_page_id,
        'events_access_token'        => $access_token,
        'events_token_set_at'        => current_time( 'mysql', true ),
        'events_token_expires_at'    => ( isset( $expiry['expires_at'] ) ? $expiry['expires_at'] : null ),
        'events_token_never_expires' => ( !empty( $expiry['never_expires'] ) ? 1 : 0 ),
    ) );
    if ( false === $row_id || (int) $row_id <= 0 ) {
        return new WP_Error('esf_fb_events_token_save_failed', __( 'Could not save the token. Try again.', 'easy-facebook-likebox' ), array(
            'status' => 500,
        ));
    }
    if ( class_exists( 'ESF_Facebook_Cache' ) ) {
        ESF_Facebook_Cache::flush_page_cache( $page_row_id );
    }
    return true;
}

/**
 * Whether a Facebook login may be staged or imported under the current plan.
 *
 * Free: one Meta login per site (unlimited Pages under it). Re-importing the same
 * `facebook_user_id` or reconnecting when an account is expired/invalid is allowed.
 *
 * @since 6.7.9
 * @param string $facebook_user_id Meta user id or `manual:{page_id}` session key.
 * @return true|WP_Error
 */
function esf_facebook_assert_account_import_allowed(  $facebook_user_id  ) {
    $facebook_user_id = sanitize_text_field( (string) $facebook_user_id );
    if ( '' === $facebook_user_id ) {
        return new WP_Error('esf_fb_invalid_user', __( 'Invalid Facebook account.', 'easy-facebook-likebox' ), array(
            'status' => 400,
        ));
    }
    if ( function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan() ) {
        return true;
    }
    if ( !class_exists( 'ESF_Facebook_Account_Repository' ) ) {
        return true;
    }
    $account_repo = ESF_Facebook_Account_Repository::get_instance();
    $existing = $account_repo->get_by_facebook_user_id( $facebook_user_id );
    if ( $existing ) {
        return true;
    }
    $all_accounts = $account_repo->get_all();
    $other_count = ( is_array( $all_accounts ) ? count( $all_accounts ) : 0 );
    if ( $other_count < 1 ) {
        return true;
    }
    $needs_reconnect = false;
    foreach ( (array) $all_accounts as $account_row ) {
        $status = ( is_object( $account_row ) && isset( $account_row->status ) ? (string) $account_row->status : '' );
        if ( in_array( $status, array('expired', 'invalid'), true ) ) {
            $needs_reconnect = true;
            break;
        }
    }
    if ( $needs_reconnect ) {
        return true;
    }
    return new WP_Error('account_limit_reached', __( 'Your plan allows one connected Facebook account per site. Upgrade to add more.', 'easy-facebook-likebox' ), array(
        'status' => 403,
    ));
}

/**
 * Check whether the site has an active Facebook Pro plan.
 *
 * Returns true when premium code is available and the user has
 * facebook_premium or combo_premium.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_facebook_has_facebook_plan() {
    if ( !function_exists( 'efl_fs' ) ) {
        return false;
    }
    $fs = efl_fs();
    return false;
    return $fs->is_plan( 'facebook_premium', true ) || $fs->is_plan( 'combo_premium', true );
}

/**
 * Whether a feed's customize preview is expected to be a cold (slow) first load.
 *
 * Cold when both the posts cache and local media files are missing for the Page.
 *
 * @since 6.7.9
 *
 * @param object|null $feed Feed row (needs page_id; feed_type/source_id/settings for cache keys).
 * @return bool True when neither posts cache nor local media are present.
 */
function esf_facebook_feed_preview_is_cold(  $feed  ) {
    if ( !is_object( $feed ) ) {
        return true;
    }
    $page_id = ( isset( $feed->page_id ) ? (int) $feed->page_id : 0 );
    if ( $page_id <= 0 ) {
        return true;
    }
    if ( class_exists( 'ESF_Facebook_Renderer' ) && ESF_Facebook_Renderer::feed_has_posts_cache( $feed ) ) {
        return false;
    }
    $has_local_media = false;
    if ( function_exists( 'esf_get_uploads_directory' ) ) {
        $dir = esf_get_uploads_directory( 'facebook', $page_id );
        if ( is_string( $dir ) && '' !== $dir && is_dir( $dir ) ) {
            $entries = @scandir( $dir );
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort existence probe.
            if ( is_array( $entries ) ) {
                foreach ( $entries as $entry ) {
                    if ( '.' === $entry || '..' === $entry ) {
                        continue;
                    }
                    $path = $dir . '/' . $entry;
                    if ( is_file( $path ) || is_dir( $path ) ) {
                        $has_local_media = true;
                        break;
                    }
                }
            }
        }
    }
    return !$has_local_media;
}

/**
 * Format a Facebook post caption as safe HTML with hashtag linking.
 *
 * Converts hashtags to Facebook search links and clickable URLs.
 *
 * @since 6.7.9
 * @param string $caption Raw caption text.
 * @return string
 */
function esf_facebook_format_caption_html(  $caption  ) {
    $caption = trim( (string) $caption );
    if ( '' === $caption ) {
        return '';
    }
    // First escape HTML
    $caption = esc_html( $caption );
    // Convert hashtags to Facebook hashtag links
    $caption = preg_replace_callback( '/(?:^|[\\s\\n\\r])#([a-zA-Z0-9_\\x{00C0}-\\x{00FF}\\x{0100}-\\x{017F}\\x{0180}-\\x{024F}]+)/u', function ( $matches ) {
        $hashtag = $matches[1];
        $url = 'https://www.facebook.com/hashtag/' . rawurlencode( $hashtag );
        return $matches[0][0] . '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" class="esf-facebook-hashtag">#' . esc_html( $hashtag ) . '</a>';
    }, $caption );
    // Convert URLs to clickable links
    $caption = make_clickable( $caption );
    // Convert line breaks to paragraphs
    return wpautop( $caption );
}

/**
 * Whether a Facebook account row needs reconnect.
 *
 * @since 6.7.9
 * @param object|null $row Account row from esf_facebook_accounts.
 * @return bool
 */
function esf_facebook_account_needs_reconnect(  $row  ) {
    if ( !is_object( $row ) ) {
        return false;
    }
    $status = ( isset( $row->status ) ? sanitize_key( (string) $row->status ) : '' );
    if ( in_array( $status, array('expired', 'invalid'), true ) ) {
        return true;
    }
    if ( 'active' !== $status ) {
        return false;
    }
    $exp = ( isset( $row->token_expires_at ) ? trim( (string) $row->token_expires_at ) : '' );
    if ( '' === $exp ) {
        return false;
    }
    $ts = strtotime( $exp . ' UTC' );
    if ( false === $ts ) {
        return false;
    }
    return time() > $ts;
}

/**
 * Log a Facebook module error (no-op in production).
 *
 * @since 6.7.9
 * @param string              $message Log message.
 * @param array<string,mixed> $context Optional context (unused).
 * @return void
 */
function esf_facebook_log_error(  $message, $context = array()  ) {
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Reserved for future logging.
}

/**
 * Admin URL for the Facebook dashboard.
 *
 * @since 6.7.9
 * @param array<string,mixed> $args Optional query args.
 * @return string
 */
function esf_get_facebook_admin_url(  $args = array()  ) {
    $default_args = array(
        'page' => 'esf-facebook',
    );
    $args = wp_parse_args( $args, $default_args );
    return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Allowed Connect → Display live modes.
 *
 * @since 6.7.9
 * @return array<int,string>
 */
function esf_facebook_live_display_modes() {
    return array('posts', 'prefer_live', 'live_only');
}

/**
 * Resolve Connect Display live mode from feed settings.
 *
 * `live_display` is canonical. Legacy `live_stream_only` (bool) maps to
 * `prefer_live` when the enum is absent — matching modern priority behavior
 * (not legacy empty-when-offline).
 *
 * @since 6.7.9
 * @param array<string,mixed> $feed_cfg settings.feed slice.
 * @return string posts|prefer_live|live_only
 */
function esf_facebook_resolve_live_display(  $feed_cfg  ) {
    if ( !is_array( $feed_cfg ) ) {
        return 'posts';
    }
    $allowed = esf_facebook_live_display_modes();
    if ( isset( $feed_cfg['live_display'] ) ) {
        $mode = sanitize_key( (string) $feed_cfg['live_display'] );
        if ( in_array( $mode, $allowed, true ) ) {
            return $mode;
        }
    }
    return ( !empty( $feed_cfg['live_stream_only'] ) ? 'prefer_live' : 'posts' );
}

/**
 * Whether this feed should refresh / read live_videos status.
 *
 * @since 6.7.9
 * @param array<string,mixed> $feed_cfg settings.feed slice.
 * @return bool
 */
function esf_facebook_feed_uses_live_status(  $feed_cfg  ) {
    $mode = esf_facebook_resolve_live_display( $feed_cfg );
    return 'prefer_live' === $mode || 'live_only' === $mode;
}

/**
 * Sync `live_display` + derived `live_stream_only` on a feed settings slice.
 *
 * Prefer explicit incoming `live_display` / `live_stream_only` over defaults
 * injected by array_replace_recursive.
 *
 * @since 6.7.9
 * @param array<string,mixed>      $feed_cfg  Merged feed settings.
 * @param array<string,mixed>|null $incoming  Pre-merge feed settings (optional).
 * @return array<string,mixed>
 */
function esf_facebook_sync_live_display_settings(  $feed_cfg, $incoming = null  ) {
    if ( !is_array( $feed_cfg ) ) {
        $feed_cfg = array();
    }
    $source = ( is_array( $incoming ) ? $incoming : $feed_cfg );
    $mode = esf_facebook_resolve_live_display( $source );
    $feed_cfg['live_display'] = $mode;
    $feed_cfg['live_stream_only'] = 'posts' !== $mode;
    return $feed_cfg;
}

/**
 * Apply Multifeed soft limits on feed settings (Live Display + specific album).
 *
 * When 2+ page_ids are selected and Multifeed is available, Live Display is
 * forced to Page posts and album_id is cleared (All albums only).
 *
 * @since 6.10.0
 * @param array $settings Feed settings.
 * @return array
 */
function esf_facebook_normalize_multifeed_settings(  $settings  ) {
    if ( !is_array( $settings ) ) {
        return array();
    }
    if ( !isset( $settings['feed'] ) || !is_array( $settings['feed'] ) ) {
        return $settings;
    }
    if ( !function_exists( 'esf_facebook_can_use_multifeed' ) || !esf_facebook_can_use_multifeed() ) {
        return $settings;
    }
    $page_ids = array();
    if ( isset( $settings['feed']['page_ids'] ) && is_array( $settings['feed']['page_ids'] ) ) {
        foreach ( $settings['feed']['page_ids'] as $id ) {
            $id = (int) $id;
            if ( $id > 0 && !in_array( $id, $page_ids, true ) ) {
                $page_ids[] = $id;
            }
        }
    }
    if ( count( $page_ids ) <= 1 ) {
        return $settings;
    }
    $settings['feed']['page_ids'] = $page_ids;
    $settings['feed']['album_id'] = '';
    $settings['feed']['multifeed_sort'] = esf_facebook_resolve_multifeed_sort( $settings['feed'] );
    if ( function_exists( 'esf_facebook_sync_live_display_settings' ) ) {
        $settings['feed'] = esf_facebook_sync_live_display_settings( $settings['feed'], array_merge( $settings['feed'], array(
            'live_display' => 'posts',
        ) ) );
    } else {
        $settings['feed']['live_display'] = 'posts';
        $settings['feed']['live_stream_only'] = false;
    }
    return $settings;
}

/**
 * Allowed Multifeed sort modes.
 *
 * @since 6.10.0
 * @return array<int,string>
 */
function esf_facebook_multifeed_sort_modes() {
    return ( class_exists( 'ESF_Multifeed' ) ? ESF_Multifeed::sort_modes() : array('newest', 'balanced') );
}

/**
 * Resolve Multifeed sort mode from feed settings.
 *
 * @since 6.10.0
 * @param array<string,mixed>|string $feed_cfg settings.feed slice or raw sort.
 * @return string newest|balanced
 */
function esf_facebook_resolve_multifeed_sort(  $feed_cfg  ) {
    if ( class_exists( 'ESF_Multifeed' ) ) {
        return ESF_Multifeed::resolve_sort( $feed_cfg );
    }
    if ( is_array( $feed_cfg ) ) {
        $feed_cfg = ( array_key_exists( 'multifeed_sort', $feed_cfg ) ? $feed_cfg['multifeed_sort'] : 'newest' );
    }
    if ( !is_scalar( $feed_cfg ) || (string) $feed_cfg === '' ) {
        return 'newest';
    }
    $sort = sanitize_key( (string) $feed_cfg );
    return ( in_array( $sort, array('newest', 'balanced'), true ) ? $sort : 'newest' );
}

/**
 * Sort Multifeed Graph nodes by mode.
 *
 * @since 6.10.0
 * @param array<int,array<string,mixed>> $nodes Nodes tagged with _esf_page_row_id.
 * @param string                         $sort  newest|balanced.
 * @return array<int,array<string,mixed>>
 */
function esf_facebook_sort_multifeed_nodes(  array $nodes, $sort = 'newest'  ) {
    if ( class_exists( 'ESF_Multifeed' ) ) {
        return ESF_Multifeed::sort_nodes( $nodes, $sort, '_esf_page_row_id' );
    }
    return esf_facebook_sort_nodes_by_created_time( $nodes );
}

/**
 * Sort Graph nodes newest-first by created_time.
 *
 * @since 6.10.0
 * @param array<int,array<string,mixed>> $nodes Nodes.
 * @return array<int,array<string,mixed>>
 */
function esf_facebook_sort_nodes_by_created_time(  array $nodes  ) {
    if ( class_exists( 'ESF_Multifeed' ) ) {
        return ESF_Multifeed::sort_nodes_newest_first( $nodes );
    }
    usort( $nodes, static function ( $a, $b ) {
        $ta = ( isset( $a['created_time'] ) ? strtotime( (string) $a['created_time'] ) : 0 );
        $tb = ( isset( $b['created_time'] ) ? strtotime( (string) $b['created_time'] ) : 0 );
        if ( false === $ta ) {
            $ta = 0;
        }
        if ( false === $tb ) {
            $tb = 0;
        }
        return $tb <=> $ta;
    } );
    return $nodes;
}

/**
 * Round-robin Multifeed mix so quieter Pages still appear.
 *
 * @since 6.10.0
 * @param array<int,array<string,mixed>> $nodes Nodes.
 * @return array<int,array<string,mixed>>
 */
function esf_facebook_interleave_multifeed_nodes(  array $nodes  ) {
    if ( class_exists( 'ESF_Multifeed' ) ) {
        return ESF_Multifeed::interleave_nodes( $nodes, '_esf_page_row_id' );
    }
    return esf_facebook_sort_nodes_by_created_time( $nodes );
}

/**
 * Strip Pro-only Facebook feed settings when Facebook Pro is unavailable.
 *
 * Free layouts stay on half_width / full_width / row; Pro filters, multifeed, live stream,
 * fancybox popup, infinite scroll, and Row wave chrome reset to free defaults.
 *
 * @since 6.7.9
 * @param array $settings Feed settings.
 * @return array
 */
function esf_facebook_strip_pro_settings(  $settings  ) {
    if ( !is_array( $settings ) ) {
        return array();
    }
    if ( function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan() ) {
        /*
         * Multifeed is included in Facebook Pro (6.10.0+) and the Multifeed addon.
         * Clear stale page_ids only when Multifeed is unavailable for this site.
         */
        if ( !function_exists( 'esf_facebook_multifeed_active' ) || !esf_facebook_multifeed_active() ) {
            if ( !isset( $settings['feed'] ) || !is_array( $settings['feed'] ) ) {
                $settings['feed'] = array();
            }
            $settings['feed']['page_ids'] = array();
        }
        return $settings;
    }
    unset($settings['moderation']);
    $defaults = ( class_exists( 'ESF_Facebook_Feed_Repository' ) ? ESF_Facebook_Feed_Repository::get_default_settings() : array() );
    if ( !isset( $settings['layout'] ) || !is_array( $settings['layout'] ) ) {
        $settings['layout'] = array();
    }
    $layout_type = ( isset( $settings['layout']['type'] ) ? sanitize_key( (string) $settings['layout']['type'] ) : 'half_width' );
    if ( function_exists( 'esf_facebook_normalize_layout_type' ) ) {
        $layout_type = esf_facebook_normalize_layout_type( $layout_type );
    } elseif ( 'half' === $layout_type ) {
        $layout_type = 'half_width';
    } elseif ( 'full' === $layout_type ) {
        $layout_type = 'full_width';
    }
    $free_layouts = array(
        'half_width',
        'full_width',
        'thumbnail',
        'row'
    );
    if ( !in_array( $layout_type, $free_layouts, true ) ) {
        $layout_type = 'half_width';
    }
    $settings['layout']['type'] = $layout_type;
    if ( !isset( $settings['feed'] ) || !is_array( $settings['feed'] ) ) {
        $settings['feed'] = array();
    }
    // Preserve Multifeed page_ids when the Multifeed addon is active without full Pro.
    $preserved_page_ids = array();
    if ( function_exists( 'esf_facebook_multifeed_active' ) && esf_facebook_multifeed_active() && isset( $settings['feed']['page_ids'] ) && is_array( $settings['feed']['page_ids'] ) ) {
        foreach ( $settings['feed']['page_ids'] as $page_id ) {
            $page_id = (int) $page_id;
            if ( $page_id > 0 && !in_array( $page_id, $preserved_page_ids, true ) ) {
                $preserved_page_ids[] = $page_id;
            }
        }
    }
    $settings['feed']['load_more'] = false;
    $settings['feed']['load_more_mode'] = 'button';
    $settings['feed']['live_display'] = 'posts';
    $settings['feed']['live_stream_only'] = false;
    $settings['feed']['page_ids'] = $preserved_page_ids;
    $settings['feed']['filter'] = '';
    $settings['feed']['events_filter'] = '';
    $settings['feed']['album_id'] = '';
    // Free simple popup stays enabled by default (Pro FancyBox chrome is stripped above).
    $settings['feed']['show_popup'] = !isset( $settings['feed']['show_popup'] ) || !empty( $settings['feed']['show_popup'] );
    $settings['feed']['popup_show_comments'] = false;
    $settings['feed']['popup_show_comments_list'] = false;
    $settings['feed']['popup_comments_per_page'] = 20;
    $settings['feed']['popup_show_album_load_more'] = false;
    $settings['feed']['popup_show_gallery_nav'] = false;
    $settings['feed']['popup_show_gallery_thumbs'] = false;
    $settings['feed']['popup_gallery_nav_bg_color'] = '';
    $settings['feed']['popup_gallery_nav_icon_color'] = '';
    $settings['feed']['likebox_tabs'] = '';
    $settings['feed']['show_media_collage'] = false;
    $settings['feed']['hover_show_likes'] = false;
    $settings['feed']['hover_show_comments'] = false;
    $settings['feed']['hover_plus_color'] = '';
    $settings['feed']['hover_stats_color'] = '';
    $settings['feed']['hover_plus_size'] = ( isset( $defaults['feed']['hover_plus_size'] ) ? (int) $defaults['feed']['hover_plus_size'] : 42 );
    $settings['feed']['hover_stats_size'] = ( isset( $defaults['feed']['hover_stats_size'] ) ? (int) $defaults['feed']['hover_stats_size'] : 14 );
    // Pro-only header chrome: cover + website link off on Free; locked round chrome stays on.
    if ( !isset( $settings['header'] ) || !is_array( $settings['header'] ) ) {
        $settings['header'] = array();
    }
    $settings['header']['show_link'] = false;
    $settings['header']['show_banner'] = false;
    // Soft-rounded avatar + round header corners stay on Free (toggle remains Pro).
    $settings['header']['avatar_round'] = true;
    $settings['header']['header_rounded'] = true;
    $settings['header']['bg_color'] = '';
    $settings['header']['text_color'] = '';
    $settings['header']['border_color'] = '';
    $settings['header']['show_border'] = true;
    $settings['header']['padding'] = 16;
    // Half Width Pro chrome (aspect, shadow, theme colors, hover metrics).
    if ( !isset( $settings['layout']['half_width'] ) || !is_array( $settings['layout']['half_width'] ) ) {
        $settings['layout']['half_width'] = array();
    }
    $half_defaults = ( isset( $defaults['layout']['half_width'] ) && is_array( $defaults['layout']['half_width'] ) ? $defaults['layout']['half_width'] : array() );
    $settings['layout']['half_width']['media_aspect_ratio'] = ( isset( $half_defaults['media_aspect_ratio'] ) ? (string) $half_defaults['media_aspect_ratio'] : '1:1' );
    // Card shadow is Pro-only: Free locked off; Pro defaults on in repository.
    $settings['layout']['half_width']['card_shadow'] = false;
    $settings['layout']['half_width']['card_shadow_color'] = '';
    $settings['layout']['half_width']['bg_color'] = '';
    $settings['layout']['half_width']['footer_bg_color'] = '';
    $settings['layout']['half_width']['text_color'] = '';
    $settings['layout']['half_width']['accent_color'] = '';
    $settings['layout']['half_width']['hover_show_likes'] = false;
    $settings['layout']['half_width']['hover_show_comments'] = false;
    if ( !isset( $settings['layout']['full_width'] ) || !is_array( $settings['layout']['full_width'] ) ) {
        $settings['layout']['full_width'] = array();
    }
    $fw_defaults = ( isset( $defaults['layout']['full_width'] ) && is_array( $defaults['layout']['full_width'] ) ? $defaults['layout']['full_width'] : array() );
    // Original (auto) is the free Full Width default — fixed ratios + media position are Pro.
    $settings['layout']['full_width']['media_aspect_ratio'] = ( isset( $fw_defaults['media_aspect_ratio'] ) ? (string) $fw_defaults['media_aspect_ratio'] : 'auto' );
    $settings['layout']['full_width']['media_position'] = ( isset( $fw_defaults['media_position'] ) ? (string) $fw_defaults['media_position'] : 'header_first' );
    $settings['layout']['full_width']['card_shadow'] = false;
    $settings['layout']['full_width']['card_shadow_color'] = '';
    $settings['layout']['full_width']['bg_color'] = '';
    $settings['layout']['full_width']['footer_bg_color'] = '';
    $settings['layout']['full_width']['text_color'] = '';
    $settings['layout']['full_width']['accent_color'] = '';
    if ( !isset( $settings['layout']['row'] ) || !is_array( $settings['layout']['row'] ) ) {
        $settings['layout']['row'] = array();
    }
    $row_defaults = ( isset( $defaults['layout']['row'] ) && is_array( $defaults['layout']['row'] ) ? $defaults['layout']['row'] : array() );
    $settings['layout']['row']['row_style'] = 'standard';
    $settings['layout']['row']['wave_offset'] = ( isset( $row_defaults['wave_offset'] ) ? (int) $row_defaults['wave_offset'] : 16 );
    $settings['layout']['row']['tile_border_radius'] = ( isset( $row_defaults['tile_border_radius'] ) ? (int) $row_defaults['tile_border_radius'] : 0 );
    $settings['layout']['row']['tile_shadow'] = false;
    $settings['layout']['row']['tile_shadow_color'] = '';
    $settings['layout']['row']['media_aspect_ratio'] = ( isset( $row_defaults['media_aspect_ratio'] ) ? (string) $row_defaults['media_aspect_ratio'] : '1:1' );
    // Thumbnail Pro chrome (aspect / shadow / theme colors / hover metrics).
    if ( !isset( $settings['layout']['thumbnail'] ) || !is_array( $settings['layout']['thumbnail'] ) ) {
        $settings['layout']['thumbnail'] = array();
    }
    $thumb_defaults = ( isset( $defaults['layout']['thumbnail'] ) && is_array( $defaults['layout']['thumbnail'] ) ? $defaults['layout']['thumbnail'] : array() );
    $settings['layout']['thumbnail']['media_aspect_ratio'] = ( isset( $thumb_defaults['media_aspect_ratio'] ) ? (string) $thumb_defaults['media_aspect_ratio'] : '1:1' );
    $settings['layout']['thumbnail']['card_shadow'] = false;
    $settings['layout']['thumbnail']['card_shadow_color'] = '';
    $settings['layout']['thumbnail']['bg_color'] = '';
    $settings['layout']['thumbnail']['footer_bg_color'] = '';
    $settings['layout']['thumbnail']['text_color'] = '';
    $settings['layout']['thumbnail']['accent_color'] = '';
    $settings['layout']['thumbnail']['hover_show_likes'] = false;
    $settings['layout']['thumbnail']['hover_show_comments'] = false;
    if ( isset( $defaults['feed'] ) && is_array( $defaults['feed'] ) ) {
        foreach ( array('per_page', 'load_more') as $key ) {
            if ( !array_key_exists( $key, $settings['feed'] ) && array_key_exists( $key, $defaults['feed'] ) ) {
                $settings['feed'][$key] = $defaults['feed'][$key];
            }
        }
    }
    return $settings;
}

/**
 * Default feed settings helper (thin wrapper for repository defaults).
 *
 * @since 6.7.9
 * @return array<string,mixed>
 */
function esf_facebook_get_default_feed_settings() {
    if ( class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
        return ESF_Facebook_Feed_Repository::get_default_settings();
    }
    return array(
        'layout'  => array(
            'type' => 'half_width',
        ),
        'feed'    => array(
            'per_page'  => 9,
            'load_more' => true,
            'page_ids'  => array(),
        ),
        'header'  => array(
            'show' => true,
        ),
        'likebox' => array(
            'width'        => 340,
            'height'       => 214,
            'show_faces'   => true,
            'hide_cover'   => false,
            'small_header' => false,
            'hide_cta'     => false,
            'show_posts'   => false,
            'locale'       => 'en_US',
        ),
    );
}

/**
 * Whether Pro filters (images/videos/events/albums/mentioned) may be used.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_facebook_can_use_filters() {
    return function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan();
}

/**
 * Whether Facebook Multifeed support is available.
 *
 * Modern Facebook: Included in Facebook Pro (`facebook_premium` / `combo_premium`)
 * **or** the standalone Multifeed addon (`Esf_Multifeed_Facebook_Modern`).
 * Legacy Facebook: Multifeed addon or parent Pro bundled fallback
 * (`Esf_Multifeed_Facebook_Frontend`).
 *
 * Delegates to shared {@see ESF_Multifeed::is_available()} for cross-module consistency.
 *
 * Users with the standalone addon can deactivate it after upgrading to Pro—
 * their feeds will continue working via native Pro support.
 *
 * @since 6.7.9
 * @since 6.10.0 Multifeed now included in Facebook Pro (native, not addon-only). Uses shared ESF_Multifeed class.
 * @return bool
 */
function esf_facebook_multifeed_active() {
    if ( class_exists( 'ESF_Multifeed' ) ) {
        return ESF_Multifeed::is_available( 'facebook' );
    }
    // Fallback if shared class is not loaded (should not happen).
    // Check addon classes directly.
    if ( class_exists( 'Esf_Multifeed_Facebook_Modern', false ) || class_exists( 'Esf_Multifeed_Facebook_Frontend', false ) ) {
        return true;
    }
    // Check if Pro plan active (requires Facebook plan check helper).
    if ( !function_exists( 'efl_fs' ) || !efl_fs()->can_use_premium_code__premium_only() ) {
        return false;
    }
    return function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan();
}

/**
 * Whether Multifeed (page_ids[]) may be used.
 *
 * Multifeed is now included in Facebook Pro (`facebook_premium` / `combo_premium`)
 * in addition to the standalone Multifeed addon. Existing addon users can
 * deactivate the addon; Pro users gain the feature natively.
 *
 * @since 6.7.9
 * @since 6.10.0 Multifeed now included in Facebook Pro (not addon-only).
 * @return bool
 */
function esf_facebook_can_use_multifeed() {
    return function_exists( 'esf_facebook_multifeed_active' ) && esf_facebook_multifeed_active();
}

/**
 * Whether a modern feed is configured as Multifeed (2+ source Pages).
 *
 * @since 6.7.9
 *
 * @param object|null $feed_obj Feed object.
 * @return bool
 */
function esf_facebook_feed_is_multifeed(  $feed_obj  ) {
    return count( esf_facebook_resolve_page_row_ids( $feed_obj ) ) > 1;
}

/**
 * Resolve effective feed_type with Pro gating (Free always timeline).
 *
 * Thin wrapper around ESF_Facebook_Feed_Query (canonical resolver).
 *
 * @since 6.7.9
 * @param object|array<string,mixed>|null $feed_or_settings Feed row or settings array.
 * @return string
 */
function esf_facebook_resolve_effective_feed_type(  $feed_or_settings  ) {
    if ( class_exists( 'ESF_Facebook_Feed_Query' ) ) {
        if ( is_object( $feed_or_settings ) ) {
            return ESF_Facebook_Feed_Query::from_feed( $feed_or_settings )->get_feed_type();
        }
        if ( is_array( $feed_or_settings ) ) {
            return ESF_Facebook_Feed_Query::from_feed( (object) array(
                'feed_type' => 'timeline',
                'settings'  => $feed_or_settings,
            ) )->get_feed_type();
        }
        return 'timeline';
    }
    // Fallback if Feed_Query is not loaded (should not happen on modern path).
    $allowed = array(
        'timeline',
        'images',
        'videos',
        'events',
        'albums',
        'mentioned'
    );
    $type = 'timeline';
    if ( is_object( $feed_or_settings ) && isset( $feed_or_settings->feed_type ) ) {
        $type = sanitize_key( (string) $feed_or_settings->feed_type );
    }
    if ( !in_array( $type, $allowed, true ) ) {
        $type = 'timeline';
    }
    if ( 'timeline' !== $type && !esf_facebook_can_use_filters() ) {
        return 'timeline';
    }
    return $type;
}

/**
 * Resolve page row IDs for a feed (primary + Pro multifeed page_ids).
 *
 * @since 6.7.9
 * @param object $feed_obj Feed object with page_id + settings.
 * @return array<int,int> Internal esf_facebook_pages.id values.
 */
function esf_facebook_resolve_page_row_ids(  $feed_obj  ) {
    $ids = array();
    if ( !is_object( $feed_obj ) ) {
        return $ids;
    }
    $primary = ( isset( $feed_obj->page_id ) ? (int) $feed_obj->page_id : 0 );
    if ( $primary > 0 ) {
        $ids[] = $primary;
    }
    if ( !esf_facebook_can_use_multifeed() ) {
        return $ids;
    }
    $settings = ( isset( $feed_obj->settings ) && is_array( $feed_obj->settings ) ? $feed_obj->settings : array() );
    $page_ids = ( isset( $settings['feed']['page_ids'] ) && is_array( $settings['feed']['page_ids'] ) ? $settings['feed']['page_ids'] : array() );
    foreach ( $page_ids as $id ) {
        $id = (int) $id;
        if ( $id > 0 && !in_array( $id, $ids, true ) ) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * Live-stream positive cache TTL in seconds (60–120, filterable).
 *
 * Kept for filters / legacy callers. Cron and preview refreshes use
 * esf_facebook_get_live_cron_cache_ttl() so rows outlive the 30-minute
 * token cron interval. Public frontend is cache-only (no Graph).
 *
 * @since 6.7.9
 * @return int
 */
function esf_facebook_get_live_cache_ttl() {
    $ttl = (int) apply_filters( 'esf_facebook_live_cache_ttl', 90 );
    return max( 60, min( 120, $ttl ) );
}

/**
 * Not-live status cache TTL in seconds (15–60, filterable). Default 30.
 *
 * Legacy helper; public frontend no longer Graph-polls on this interval.
 *
 * @since 6.7.9
 * @return int
 */
function esf_facebook_get_live_negative_cache_ttl() {
    $ttl = (int) apply_filters( 'esf_facebook_live_negative_cache_ttl', 30 );
    return max( 15, min( 60, $ttl ) );
}

/**
 * Live status cache TTL written by cron / save / preview (default 45 minutes).
 *
 * Longer than the existing Facebook token cron (30 minutes) so public pages
 * can serve stale-while-fresh until the next piggybacked refresh.
 *
 * @since 6.7.9
 * @return int
 */
function esf_facebook_get_live_cron_cache_ttl() {
    $ttl = (int) apply_filters( 'esf_facebook_live_cron_cache_ttl', 2700 );
    return max( 1800, min( 3600, $ttl ) );
}

/**
 * Interval between chained live-status Graph checks while a Page may be live.
 *
 * @since 6.7.9
 * @return int Seconds (60–600, filterable). Default 300 (5 minutes).
 */
function esf_facebook_get_live_follow_up_interval() {
    $interval = (int) apply_filters( 'esf_facebook_live_follow_up_interval', 300 );
    return max( 60, min( 600, $interval ) );
}

/**
 * Feed posts cache TTL from module settings.
 *
 * @since 6.7.9
 * @return int
 */
function esf_facebook_get_cache_ttl_secs() {
    $ttl = ( function_exists( 'esf_get_facebook_settings' ) ? (int) esf_get_facebook_settings( 'cache_duration' ) : 43200 );
    return ( $ttl > 0 ? $ttl : 43200 );
}

/**
 * Normalize layout type slug for the Facebook LayoutRegistry.
 *
 * Accepts legacy shortcodes (`half` / `full`) and modern slugs (`half_width` /
 * `full_width` / `thumbnail` / …). Always returns a registered layout slug.
 *
 * @since 6.7.9
 * @param string $layout_type Raw layout type.
 * @return string
 */
function esf_facebook_normalize_layout_type(  $layout_type  ) {
    $layout_type = sanitize_key( (string) $layout_type );
    if ( 'half' === $layout_type ) {
        return 'half_width';
    }
    if ( 'full' === $layout_type ) {
        return 'full_width';
    }
    $allowed = array(
        'half_width',
        'full_width',
        'thumbnail',
        'row',
        'grid',
        'masonry',
        'carousel'
    );
    if ( !in_array( $layout_type, $allowed, true ) ) {
        return 'half_width';
    }
    return $layout_type;
}

/**
 * Whether a feed needs the shared stacked post-card CSS bundle.
 *
 * Full Width includes the partial in its own layout CSS. Masonry and
 * Carousel load the standalone bundle only for Full post cards mode.
 *
 * @since 6.7.9
 * @param string              $layout_slug Layout slug.
 * @param array<string,mixed> $settings    Normalized feed settings.
 * @return bool
 */
function esf_facebook_needs_stacked_post_card_css(  $layout_slug, array $settings  ) {
    $layout_slug = esf_facebook_normalize_layout_type( (string) $layout_slug );
    if ( 'full_width' === $layout_slug ) {
        return false;
    }
    if ( 'masonry' !== $layout_slug && 'carousel' !== $layout_slug ) {
        return false;
    }
    $slice = ( isset( $settings['layout'][$layout_slug] ) && is_array( $settings['layout'][$layout_slug] ) ? $settings['layout'][$layout_slug] : array() );
    $mode = ( isset( $slice['display_mode'] ) ? sanitize_key( (string) $slice['display_mode'] ) : 'detailed' );
    return 'post_cards' === $mode;
}

/**
 * Asset descriptor for esf-facebook-stacked-post-card.css.
 *
 * @since 6.7.9
 * @return array{handle:string,src:string,deps:array<int,string>,ver:string}|null
 */
function esf_facebook_get_stacked_post_card_css_asset() {
    $base_url = ( defined( 'ESF_FACEBOOK_URL' ) ? ESF_FACEBOOK_URL : (( defined( 'EFBL_PLUGIN_URL' ) ? EFBL_PLUGIN_URL : '' )) );
    $base_dir = ( defined( 'ESF_FACEBOOK_DIR' ) ? ESF_FACEBOOK_DIR : (( defined( 'EFBL_PLUGIN_DIR' ) ? EFBL_PLUGIN_DIR : '' )) );
    if ( '' === $base_url || '' === $base_dir ) {
        return null;
    }
    $path = $base_dir . 'frontend/assets/css/layouts/stacked-post-card.css';
    if ( !file_exists( $path ) ) {
        return null;
    }
    $version = ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );
    $ver = (string) filemtime( $path );
    if ( '' === $ver ) {
        $ver = $version;
    }
    return array(
        'handle' => 'esf-facebook-stacked-post-card',
        'src'    => $base_url . 'frontend/assets/css/layouts/stacked-post-card.css',
        'deps'   => array('esf-facebook-feed'),
        'ver'    => $ver,
    );
}

/**
 * Enqueue stacked post-card CSS when the active layout needs it.
 *
 * @since 6.7.9
 * @param string              $layout_slug Layout slug.
 * @param array<string,mixed> $settings    Normalized feed settings.
 * @return void
 */
function esf_facebook_enqueue_stacked_post_card_css(  $layout_slug, array $settings  ) {
    if ( !esf_facebook_needs_stacked_post_card_css( $layout_slug, $settings ) ) {
        return;
    }
    $asset = esf_facebook_get_stacked_post_card_css_asset();
    if ( null === $asset ) {
        return;
    }
    wp_enqueue_style(
        $asset['handle'],
        $asset['src'],
        $asset['deps'],
        $asset['ver']
    );
}

/**
 * Flag that the Facebook feed script should be enqueued.
 *
 * @since 6.7.9
 * @return void
 */
function esf_facebook_flag_feed_script() {
    global $esf_facebook_feed_script_needed;
    $esf_facebook_feed_script_needed = true;
}

/**
 * Flag Likebox / XFBML bootstrap script.
 *
 * @since 6.7.9
 * @param string|null $locale Optional Page Plugin locale (e.g. `fr_FR`). Drives SDK URL.
 * @return void
 */
function esf_facebook_flag_likebox_script(  $locale = null  ) {
    global $esf_facebook_likebox_script_needed, $esf_facebook_likebox_sdk_locale;
    $esf_facebook_likebox_script_needed = true;
    if ( is_string( $locale ) && preg_match( '/^[a-z]{2}_[A-Z]{2}$/', $locale ) ) {
        $esf_facebook_likebox_sdk_locale = $locale;
    }
}

/**
 * Locale for the Facebook JS SDK URL used by Like Box embeds.
 *
 * Page Plugin UI language comes from `connect.facebook.net/{locale}/sdk.js`,
 * not from a data attribute on `.fb-page`.
 *
 * @since 6.7.9
 * @return string
 */
function esf_facebook_get_likebox_sdk_locale() {
    global $esf_facebook_likebox_sdk_locale;
    if ( is_string( $esf_facebook_likebox_sdk_locale ) && preg_match( '/^[a-z]{2}_[A-Z]{2}$/', $esf_facebook_likebox_sdk_locale ) ) {
        return $esf_facebook_likebox_sdk_locale;
    }
    if ( function_exists( 'esf_get_effective_api_locale' ) ) {
        return esf_get_effective_api_locale();
    }
    return 'en_US';
}

/**
 * Flag Free popup script.
 *
 * @since 6.7.9
 * @return void
 */
function esf_facebook_flag_free_popup_script() {
    global $esf_facebook_free_popup_script_needed;
    $esf_facebook_free_popup_script_needed = true;
}

/**
 * Localize strings/assets for the shared Free simple popup (legacy branding).
 *
 * @since 6.7.9
 * @return array<string,string>
 */
function esf_facebook_get_simple_popup_localize_data() {
    $plugin_url = ( defined( 'FTA_PLUGIN_URL' ) ? FTA_PLUGIN_URL : '' );
    $icon = ( $plugin_url ? trailingslashit( $plugin_url ) . 'admin/assets/images/plugin_icon.png' : '' );
    $brand_label = ( function_exists( 'esf_translate_registry_string' ) ? (string) esf_translate_registry_string( 'fb_powered_by', 'Powered by Easy Social Feed' ) : __( 'Powered by Easy Social Feed', 'easy-facebook-likebox' ) );
    $read_more = ( function_exists( 'esf_translate_registry_string' ) ? (string) esf_translate_registry_string( 'read_full_story', 'Read full story' ) : __( 'Read full story', 'easy-facebook-likebox' ) );
    $close_label = ( function_exists( 'esf_translate_registry_string' ) ? (string) esf_translate_registry_string( 'close', 'Close' ) : __( 'Close', 'easy-facebook-likebox' ) );
    return array(
        'brandLabel'    => $brand_label,
        'readMoreLabel' => $read_more,
        'closeLabel'    => $close_label,
        'brandUrl'      => 'https://easysocialfeed.com/?utm_campaign=powered-by&utm_medium=link&utm_source=plugin',
        'brandIcon'     => $icon,
    );
}

/**
 * Enqueue shared Free simple-popup CSS/JS (public + admin preview).
 *
 * @since 6.7.9
 * @return void
 */
function esf_facebook_enqueue_free_popup_assets() {
    $plugin_dir = ( defined( 'FTA_PLUGIN_DIR' ) ? FTA_PLUGIN_DIR : '' );
    $plugin_url = ( defined( 'FTA_PLUGIN_URL' ) ? FTA_PLUGIN_URL : '' );
    if ( '' === $plugin_dir || '' === $plugin_url ) {
        return;
    }
    $css = $plugin_dir . 'admin/shared/frontend/css/esf-simple-popup.css';
    $js = $plugin_dir . 'admin/shared/frontend/js/esf-simple-popup.js';
    if ( file_exists( $css ) && !wp_style_is( 'esf-simple-popup', 'enqueued' ) ) {
        wp_enqueue_style(
            'esf-simple-popup',
            $plugin_url . 'admin/shared/frontend/css/esf-simple-popup.css',
            array(),
            (string) filemtime( $css )
        );
    }
    if ( file_exists( $js ) && !wp_script_is( 'esf-simple-popup', 'enqueued' ) ) {
        wp_enqueue_script(
            'esf-simple-popup',
            $plugin_url . 'admin/shared/frontend/js/esf-simple-popup.js',
            array(),
            (string) filemtime( $js ),
            true
        );
        wp_localize_script( 'esf-simple-popup', 'esfSimplePopupData', esf_facebook_get_simple_popup_localize_data() );
    } elseif ( wp_script_is( 'esf-simple-popup', 'enqueued' ) ) {
        wp_localize_script( 'esf-simple-popup', 'esfSimplePopupData', esf_facebook_get_simple_popup_localize_data() );
    }
}

/**
 * Whether the feed script was flagged for this request.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_facebook_needs_feed_script() {
    global $esf_facebook_feed_script_needed;
    return !empty( $esf_facebook_feed_script_needed );
}

/**
 * Whether the Load More script was flagged.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_facebook_needs_load_more_script() {
    global $esf_facebook_load_more_script_needed;
    return !empty( $esf_facebook_load_more_script_needed );
}

/**
 * Whether the infinite scroll script was flagged.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_facebook_needs_infinite_scroll_script() {
    global $esf_facebook_infinite_scroll_script_needed;
    return !empty( $esf_facebook_infinite_scroll_script_needed );
}

/**
 * Whether the lightbox script was flagged.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_facebook_needs_lightbox_script() {
    global $esf_facebook_lightbox_script_needed;
    return !empty( $esf_facebook_lightbox_script_needed );
}

/**
 * Whether the Likebox script was flagged.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_facebook_needs_likebox_script() {
    global $esf_facebook_likebox_script_needed;
    return !empty( $esf_facebook_likebox_script_needed );
}

/**
 * Whether the live stream script was flagged.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_facebook_needs_live_stream_script() {
    global $esf_facebook_live_stream_script_needed;
    return !empty( $esf_facebook_live_stream_script_needed );
}

/**
 * Whether the Free popup script was flagged.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_facebook_needs_free_popup_script() {
    global $esf_facebook_free_popup_script_needed;
    return !empty( $esf_facebook_free_popup_script_needed );
}

/**
 * Whether the Row wave animation script was flagged.
 *
 * @since 6.7.9
 * @return bool
 */
function esf_facebook_needs_row_wave_script() {
    global $esf_facebook_row_wave_script_needed;
    return !empty( $esf_facebook_row_wave_script_needed );
}

/**
 * Enrich feed nodes with Pro event metadata when attachment embeds are restricted.
 *
 * @since 6.7.9
 *
 * @param array<int,array<string,mixed>> $nodes     Graph post nodes.
 * @param object|null                    $page_row  Page DB row.
 * @param string                         $feed_type Canonical feed type.
 * @return array<int,array<string,mixed>>
 */
function esf_facebook_enrich_feed_nodes(  array $nodes, $page_row, string $feed_type = 'timeline'  ) : array {
    if ( !class_exists( 'ESF_Facebook_Embed_Enricher' ) ) {
        return $nodes;
    }
    return ESF_Facebook_Embed_Enricher::enrich_nodes( $nodes, $page_row, $feed_type );
}

/**
 * Localize data for the Facebook Pro FancyBox lightbox.
 *
 * Shared by the public frontend and the admin customize preview.
 *
 * @since 6.7.9
 * @return array<string,mixed>
 */
function esf_facebook_get_lightbox_localize_data() {
    $admin = ( class_exists( 'ESF_GDPR_Integrations' ) && method_exists( 'ESF_GDPR_Integrations', 'get_lightbox_admin_localize_data' ) ? call_user_func( array('ESF_GDPR_Integrations', 'get_lightbox_admin_localize_data') ) : array(
        'adminMediaBlockedText' => '',
        'adminsOnlyText'        => __( 'Admins only', 'easy-facebook-likebox' ),
    ) );
    if ( !is_array( $admin ) ) {
        $admin = array(
            'adminMediaBlockedText' => '',
            'adminsOnlyText'        => __( 'Admins only', 'easy-facebook-likebox' ),
        );
    }
    return array_merge( array(
        'restUrl'                    => rest_url( 'esf/v1' ),
        'nonce'                      => wp_create_nonce( 'wp_rest' ),
        'isAdminPreview'             => false,
        'viewOnFacebookText'         => esf_translate_registry_string( 'view_on_facebook', 'View on Facebook' ),
        'writeCommentOnFacebookText' => esf_translate_registry_string( 'fb_write_comment_on_facebook', 'Add a comment on Facebook…' ),
        'videoText'                  => esf_translate_registry_string( 'fb_video', 'Video' ),
        'galleryText'                => esf_translate_registry_string( 'fb_gallery', 'Gallery' ),
        'previousMediaText'          => esf_translate_registry_string( 'fb_previous', 'Previous' ),
        'nextMediaText'              => esf_translate_registry_string( 'fb_next', 'Next' ),
        'imageText'                  => esf_translate_registry_string( 'fb_image', 'Image' ),
        'likesText'                  => esf_translate_registry_string( 'fb_likes', 'Likes' ),
        'reactionsText'              => esf_translate_registry_string( 'fb_reactions', 'Reactions' ),
        'allReactionsText'           => esf_translate_registry_string( 'fb_all_reactions', 'All' ),
        'closeText'                  => esf_translate_registry_string( 'fb_close', 'Close' ),
        'commentsText'               => esf_translate_registry_string( 'fb_comments', 'Comments' ),
        'sharesText'                 => esf_translate_registry_string( 'fb_shares', 'Shares' ),
        'viewImageText'              => esf_translate_registry_string( 'fb_view_image', 'View image' ),
        'commentsShowingText'        => esf_translate_registry_string( 'fb_comments_showing', 'Showing %1$s of %2$s' ),
        'loadMoreCommentsText'       => esf_translate_registry_string( 'fb_load_more_comments', 'Load more comments' ),
        'loadMoreRepliesText'        => esf_translate_registry_string( 'fb_load_more_replies', 'Load more replies' ),
        'loadingCommentsText'        => esf_translate_registry_string( 'fb_loading_comments', 'Loading comments…' ),
        'noCommentsText'             => esf_translate_registry_string( 'fb_no_comments', 'No comments yet.' ),
        'commentsErrorText'          => esf_translate_registry_string( 'fb_comments_error', 'Could not load comments.' ),
        'loadMorePhotosText'         => esf_translate_registry_string( 'fb_load_more_photos', 'Load more photos' ),
        'loadingPhotosText'          => esf_translate_registry_string( 'fb_loading_photos', 'Loading photos…' ),
        'photosErrorText'            => esf_translate_registry_string( 'fb_photos_error', "Couldn't load more photos." ),
        'albumPhotoCountText'        => esf_translate_registry_string( 'fb_album_photo_count', '%d photos' ),
        'buyTicketsText'             => ( function_exists( 'esf_get_translated_string' ) ? (string) esf_get_translated_string( 'buy_tickets' ) : __( 'Buy Tickets', 'easy-facebook-likebox' ) ),
        'viewMapText'                => ( function_exists( 'esf_get_translated_string' ) ? (string) esf_get_translated_string( 'view_map' ) : __( 'View Map', 'easy-facebook-likebox' ) ),
        'viewEventText'              => esf_translate_registry_string( 'view_event', 'View event' ),
        'embedSharedEventText'       => esf_translate_registry_string( 'fb_embed_shared_event', 'Shared event' ),
        'embedSharedPostText'        => esf_translate_registry_string( 'fb_embed_shared_post', 'Shared post' ),
        'embedSharedVideoText'       => esf_translate_registry_string( 'fb_embed_shared_video', 'Shared video' ),
        'embedLinkPreviewText'       => esf_translate_registry_string( 'fb_embed_link_preview', 'Link preview' ),
        'embedUnavailableBodyText'   => esf_translate_registry_string( 'fb_embed_unavailable_body', 'Full details aren\'t available in the feed preview.' ),
        'embedViewEventText'         => esf_translate_registry_string( 'fb_embed_view_event', 'View event on Facebook' ),
        'viewRepliesText'            => esf_translate_registry_string( 'fb_view_all_replies', 'View all %d replies' ),
        'viewReplyText'              => esf_translate_registry_string( 'fb_view_reply', 'View %d reply' ),
        'hideRepliesText'            => esf_translate_registry_string( 'fb_hide_replies', 'Hide replies' ),
        'anonymousUserText'          => esf_translate_registry_string( 'fb_facebook_user', 'Facebook User' ),
        'numberSuffixes'             => array(
            'k' => __( 'K', 'easy-facebook-likebox' ),
            'm' => __( 'M', 'easy-facebook-likebox' ),
            'b' => __( 'B', 'easy-facebook-likebox' ),
        ),
    ), $admin );
}

/**
 * Clear all Facebook frontend asset flags for the current request.
 *
 * @since 6.7.9
 * @return void
 */
function esf_facebook_clear_asset_flags() {
    global $esf_facebook_feed_script_needed;
    global $esf_facebook_load_more_script_needed;
    global $esf_facebook_infinite_scroll_script_needed;
    global $esf_facebook_lightbox_script_needed;
    global $esf_facebook_likebox_script_needed;
    global $esf_facebook_likebox_sdk_locale;
    global $esf_facebook_live_stream_script_needed;
    global $esf_facebook_free_popup_script_needed;
    $esf_facebook_feed_script_needed = false;
    $esf_facebook_load_more_script_needed = false;
    $esf_facebook_infinite_scroll_script_needed = false;
    $esf_facebook_lightbox_script_needed = false;
    $esf_facebook_likebox_script_needed = false;
    $esf_facebook_likebox_sdk_locale = null;
    $esf_facebook_live_stream_script_needed = false;
    $esf_facebook_free_popup_script_needed = false;
}

/**
 * Mark a Facebook account invalid when a Graph error requires reconnect.
 *
 * @since 6.7.9
 * @param object|null                        $account_row Account row (needs numeric `id`).
 * @param array<string,mixed>|WP_Error|mixed $error       Graph error or WP_Error.
 * @return bool
 */
function esf_facebook_maybe_mark_account_reconnect_from_graph_error(  $account_row, $error  ) {
    if ( !class_exists( 'ESF_Meta_Graph_Error' ) || !is_object( $account_row ) ) {
        return false;
    }
    $account_id = ( isset( $account_row->id ) ? (int) $account_row->id : 0 );
    if ( $account_id <= 0 ) {
        return false;
    }
    return ESF_Meta_Graph_Error::maybe_mark_reconnect( $error, array(
        'account_id'  => $account_id,
        'status'      => 'invalid',
        'mark_status' => static function ( $id, $status ) {
            if ( !class_exists( 'ESF_Facebook_Account_Repository' ) ) {
                return false;
            }
            return ESF_Facebook_Account_Repository::get_instance()->update_status( (int) $id, (string) $status );
        },
        'notify'      => static function ( $id ) {
            unset($id);
        },
    ) );
}

/**
 * Pages for Gutenberg block inspector, in the legacy `approved_pages` shape.
 *
 * Keyed by Facebook Page id; each value has `name` so `esf-fb/*` edit.js
 * (`esfFBBlockData.pages[fanPageId].name`) keeps working after migration.
 * Expired/invalid rows are omitted. Returns false when none are usable
 * (same sentinel the block editor already treats as “no pages”).
 *
 * @since 6.7.9
 * @return array<string,object>|false
 */
function esf_facebook_get_block_editor_pages() {
    if ( !class_exists( 'ESF_Facebook_Page_Repository', false ) ) {
        return false;
    }
    $rows = ESF_Facebook_Page_Repository::get_instance()->get_all();
    $out = array();
    if ( !is_array( $rows ) ) {
        return false;
    }
    foreach ( $rows as $row ) {
        if ( !is_object( $row ) ) {
            continue;
        }
        $status = ( isset( $row->status ) ? sanitize_key( (string) $row->status ) : '' );
        if ( 'invalid' === $status || 'expired' === $status ) {
            continue;
        }
        $page_id = ( isset( $row->page_id ) ? trim( (string) $row->page_id ) : '' );
        if ( '' === $page_id ) {
            continue;
        }
        $name = ( isset( $row->page_name ) ? trim( (string) $row->page_name ) : '' );
        if ( '' === $name ) {
            $name = $page_id;
        }
        $out[$page_id] = (object) array(
            'id'   => $page_id,
            'name' => $name,
        );
    }
    return ( empty( $out ) ? false : $out );
}

/**
 * Enqueue modern Facebook feed CSS in the block editor so SSR preview is styled.
 *
 * Legacy `Easy_Facebook_Likebox::enqueue_styles()` is not loaded on the
 * modern path; Gutenberg still needs the feed + layout styles for the
 * `esf-fb/*` server-side render callback.
 *
 * @since 6.7.9
 * @return void
 */
function esf_facebook_enqueue_block_editor_preview_assets() {
    $version = ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );
    $dir = ( defined( 'ESF_FACEBOOK_DIR' ) ? ESF_FACEBOOK_DIR : (( defined( 'EFBL_PLUGIN_DIR' ) ? EFBL_PLUGIN_DIR : '' )) );
    $url = ( defined( 'ESF_FACEBOOK_URL' ) ? ESF_FACEBOOK_URL : (( defined( 'EFBL_PLUGIN_URL' ) ? EFBL_PLUGIN_URL : '' )) );
    if ( '' === $dir || '' === $url ) {
        return;
    }
    $base_css = $dir . 'frontend/assets/css/esf-facebook-feed.css';
    if ( file_exists( $base_css ) && function_exists( 'wp_style_is' ) && !wp_style_is( 'esf-facebook-feed', 'enqueued' ) ) {
        wp_enqueue_style(
            'esf-facebook-feed',
            $url . 'frontend/assets/css/esf-facebook-feed.css',
            array(),
            $version
        );
    }
    $layouts = array('half-width', 'full-width');
    if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
        if ( function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan() ) {
            $layouts[] = 'grid';
            $layouts[] = 'masonry';
            $layouts[] = 'carousel';
        }
    }
    foreach ( $layouts as $layout ) {
        $handle = 'esf-facebook-layout-' . $layout;
        $file = $dir . 'frontend/assets/css/layouts/' . $layout . '.css';
        $src = $url . 'frontend/assets/css/layouts/' . $layout . '.css';
        if ( function_exists( 'esf_locate_premium_only_asset' ) ) {
            $located = esf_locate_premium_only_asset(
                $dir . 'frontend/assets/css/layouts',
                $url . 'frontend/assets/css/layouts',
                $layout,
                'css'
            );
            if ( '' !== $located['path'] ) {
                $file = $located['path'];
                $src = $located['url'];
            }
        }
        if ( !file_exists( $file ) ) {
            continue;
        }
        if ( function_exists( 'wp_style_is' ) && wp_style_is( $handle, 'enqueued' ) ) {
            continue;
        }
        wp_enqueue_style(
            $handle,
            $src,
            array('esf-facebook-feed'),
            $version
        );
    }
}

/**
 * Apply shared moderation hide/show rules to raw Facebook Graph nodes.
 *
 * Page-scoped cache stays unfiltered; each feed applies its own rules after
 * cache/API fetch (renderer + Load More). Integrators can still filter via
 * `esf_facebook_feed_posts`.
 *
 * @since 6.7.9
 *
 * @param array      $nodes    Raw Graph nodes (must include `id`).
 * @param int        $feed_id  Feed ID.
 * @param array|null $settings Optional feed settings (unused; kept for callers).
 * @return array Filtered nodes (same structure).
 */
function esf_facebook_apply_moderation_filter(  $nodes, $feed_id, $settings = null  ) {
    unset($settings);
    if ( !is_array( $nodes ) ) {
        return array();
    }
    $feed_id = (int) $feed_id;
    $filtered = array_values( $nodes );
    if ( $feed_id > 0 && class_exists( 'ESF_Moderation' ) ) {
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            if ( function_exists( 'esf_facebook_has_facebook_plan' ) && esf_facebook_has_facebook_plan() ) {
                $filtered = ESF_Moderation::filter_raw_nodes( $filtered, 'facebook', $feed_id );
            }
        }
    }
    /**
     * Filter Facebook feed posts after moderation (raw Graph nodes).
     *
     * @since 6.7.9
     *
     * @param array $filtered Filtered nodes.
     * @param int   $feed_id  Feed ID.
     */
    return apply_filters( 'esf_facebook_feed_posts', $filtered, $feed_id );
}

// Register when Freemius Pro callbacks still exist. Free deployments strip
// `__premium_only` functions — `function_exists` avoids hooking missing callables.
// Prefer this over `can_use_premium_code__premium_only()` at include time so Pro
// still registers if Freemius is not ready yet during early helper load.
if ( function_exists( 'add_filter' ) ) {
    if ( function_exists( 'esf_facebook_moderation_get_feed_settings__premium_only' ) ) {
        add_filter(
            'esf_moderation_get_feed_settings',
            'esf_facebook_moderation_get_feed_settings__premium_only',
            10,
            3
        );
    }
    if ( function_exists( 'esf_facebook_moderation_save_feed_settings__premium_only' ) ) {
        add_filter(
            'esf_moderation_save_feed_settings',
            'esf_facebook_moderation_save_feed_settings__premium_only',
            10,
            4
        );
    }
    if ( function_exists( 'esf_facebook_moderation_feed_exists__premium_only' ) ) {
        add_filter(
            'esf_moderation_feed_exists',
            'esf_facebook_moderation_feed_exists__premium_only',
            10,
            3
        );
    }
}