<?php
/**
 * GDPR Integrations Class
 * 
 * Shared class for all modules (Facebook, Instagram, future modules)
 * Handles consent plugin detection and GDPR mode determination
 * 
 * @package EasySocialFeed
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ESF_GDPR_Integrations {

	/**
	 * Cache for detected plugin (avoid multiple checks)
	 * 
	 * @var string|false
	 */
	private static $detected_plugin = null;

	/**
	 * Whether or not consent plugins that Easy Social Feed
	 * is compatible with are active.
	 *
	 * @return bool|string Returns plugin name if active, false otherwise
	 * @since 6.8.0
	 */
	public static function gdpr_plugins_active() {
		// Return cached result if available
		if ( self::$detected_plugin !== null ) {
			return self::$detected_plugin;
		}

		// WPConsent by the WPConsent team
		if ( function_exists( 'WPConsent' ) ) {
			self::$detected_plugin = 'WPConsent by the WPConsent team';
			return self::$detected_plugin;
		}
		
		// Real Cookie Banner by devowl.io
		if ( defined( 'RCB_ROOT_SLUG' ) ) {
			self::$detected_plugin = 'Real Cookie Banner by devowl.io';
			return self::$detected_plugin;
		}
		
		// GDPR Cookie Compliance by Moove Agency
		if ( function_exists( 'gdpr_cookie_is_accepted' ) ) {
			self::$detected_plugin = 'GDPR Cookie Compliance by Moove Agency';
			return self::$detected_plugin;
		}
		
		// Cookie Notice by dFactory
		if ( class_exists( 'Cookie_Notice' ) ) {
			self::$detected_plugin = 'Cookie Notice by dFactory';
			return self::$detected_plugin;
		}
		
		// GDPR Cookie Consent by WebToffee
		if ( function_exists( 'run_cookie_law_info' ) || class_exists( 'Cookie_Law_Info' ) ) {
			self::$detected_plugin = 'GDPR Cookie Consent by WebToffee';
			return self::$detected_plugin;
		}
		
		// CookieYes | GDPR Cookie Consent by CookieYes
		if ( defined( 'CKY_APP_ASSETS_URL' ) ) {
			self::$detected_plugin = 'CookieYes | GDPR Cookie Consent by CookieYes';
			return self::$detected_plugin;
		}
		
		// Cookiebot by Cybot A/S
		if ( class_exists( 'Cookiebot_WP' ) ) {
			self::$detected_plugin = 'Cookiebot by Cybot A/S';
			return self::$detected_plugin;
		}
		
		// Complianz by Really Simple Plugins
		if ( class_exists( 'COMPLIANZ' ) ) {
			self::$detected_plugin = 'Complianz by Really Simple Plugins';
			return self::$detected_plugin;
		}
		
		// Borlabs Cookie by Borlabs
		if ( function_exists( 'BorlabsCookieHelper' ) || 
		     ( defined( 'BORLABS_COOKIE_VERSION' ) && version_compare( BORLABS_COOKIE_VERSION, '3.0', '>=' ) ) ) {
			self::$detected_plugin = 'Borlabs Cookie by Borlabs';
			return self::$detected_plugin;
		}
		
		self::$detected_plugin = false;
		return false;
	}

	/**
	 * Determine if GDPR mode should be active (applies to all modules).
	 *
	 * GDPR can be automatic, forced enabled, or forced disabled globally.
	 *
	 * @param array|null $settings Full FTA settings array, or null to load automatically
	 * @return bool Whether GDPR mode should be active
	 * @since 6.8.0
	 */
	public static function is_gdpr_active( $settings = null ) {
		if ( $settings === null ) {
			$FTA      = new Feed_Them_All();
			$settings = $FTA->fta_get_settings();
		}

		$gdpr_setting = isset( $settings['gdpr'] ) ? $settings['gdpr'] : 'auto';

		if ( $gdpr_setting === 'no' ) {
			return false;
		}
		if ( $gdpr_setting === 'yes' ) {
			return true;
		}
		return ( self::gdpr_plugins_active() !== false );
	}

	/**
	 * Get placeholder image URL
	 * 
	 * @return string Placeholder image URL
	 * @since 6.8.0
	 */
	public static function get_placeholder_image() {
		// Check if placeholder exists in Facebook module (shared asset)
		$placeholder_path = FTA_PLUGIN_DIR . 'facebook/frontend/assets/images/feed-placeholder-img.png';
		
		if ( file_exists( $placeholder_path ) ) {
			return FTA_PLUGIN_URL . 'facebook/frontend/assets/images/feed-placeholder-img.png';
		}
		
		// Fallback: 1x1 transparent PNG (data URI)
		return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
	}

	/**
	 * Get GDPR admin notice HTML
	 * 
	 * @param string $module Module name
	 * @param string $element_name Name of the element (e.g., 'Feed', 'Like Box')
	 * @return string HTML notice (empty if user is not admin)
	 * @since 6.8.0
	 */
	public static function get_gdpr_notice_html( $module, $element_name = '' ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		$element_name = ! empty( $element_name ) ? $element_name : ucfirst( $module ) . ' Feed';

		ob_start();
		?>
		<div class="esf-gdpr-notice">
			<i class="fa fa-lock" aria-hidden="true"></i>
			<?php echo esc_html__( 'This notice is visible to admins only.', 'easy-facebook-likebox' ); ?><br/>
			<?php echo esc_html( $element_name ) . ' ' . esc_html__( 'disabled due to GDPR setting.', 'easy-facebook-likebox' ); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get information about detected consent plugin
	 * 
	 * @return array Plugin info or empty array
	 * @since 6.8.0
	 */
	public static function get_consent_plugin_info() {
		$plugin_name = self::gdpr_plugins_active();
		
		if ( ! $plugin_name ) {
			return array(
				'name' => '',
				'detected' => false,
				'message' => __( 'No GDPR consent plugin detected.', 'easy-facebook-likebox' ),
			);
		}

		return array(
			'name' => $plugin_name,
			'detected' => true,
			'message' => sprintf( __( 'Detected: %s', 'easy-facebook-likebox' ), $plugin_name ),
		);
	}

	/**
	 * Get list of supported GDPR / cookie consent plugin names for display in admin.
	 * WPConsent by the WPConsent team is always at the end of the list.
	 *
	 * @return array List of translatable plugin name strings in display order.
	 * @since 6.8.0
	 */
	public static function get_supported_plugins_display_list() {
		return array(
			__( 'Real Cookie Banner by devowl.io', 'easy-facebook-likebox' ),
			__( 'GDPR Cookie Compliance by Moove Agency', 'easy-facebook-likebox' ),
			__( 'Cookie Notice by dFactory', 'easy-facebook-likebox' ),
			__( 'GDPR Cookie Consent by WebToffee', 'easy-facebook-likebox' ),
			__( 'CookieYes | GDPR Cookie Consent by CookieYes', 'easy-facebook-likebox' ),
			__( 'Cookiebot by Cybot A/S', 'easy-facebook-likebox' ),
			__( 'Complianz by Really Simple Plugins', 'easy-facebook-likebox' ),
			__( 'Borlabs Cookie by Borlabs', 'easy-facebook-likebox' ),
			__( 'WPConsent by the WPConsent team', 'easy-facebook-likebox' ),
		);
	}

	/**
	 * Attempt to determine, on the server side, whether the visitor has
	 * already given consent for marketing / third-party cookies.
	 *
	 * This mirrors (in a simplified way) the checks used in the frontend
	 * JavaScript, but using PHP-accessible cookies. It is primarily used
	 * to avoid a visible "flash" of placeholder images when the page is
	 * reloaded after consent has already been granted.
	 *
	 * Currently supported (server-side) plugins:
	 * - GDPR Cookie Compliance by Moove Agency
	 * - WPConsent / WP Consent API (wp_has_consent or wp_consent_level cookie)
	 *
	 * For unsupported plugins this will safely return false and normal
	 * placeholder behaviour will apply.
	 *
	 * @return bool True if consent appears to have been granted, false otherwise.
	 * @since 6.8.0
	 */
	public static function has_marketing_consent_server() {
		// Moove GDPR Cookie Compliance: cookie "moove_gdpr_popup" (JSON).
		if ( isset( $_COOKIE['moove_gdpr_popup'] ) ) {
			$raw = wp_unslash( $_COOKIE['moove_gdpr_popup'] );

			// The cookie value is URL-encoded JSON.
			$decoded = json_decode( urldecode( $raw ), true );

			if ( is_array( $decoded ) && isset( $decoded['thirdparty'] ) ) {
				// "1" means third-party cookies (including marketing) are allowed.
				if ( (string) $decoded['thirdparty'] === '1' ) {
					return true;
				}
			}
		}

		// WPConsent / WP Consent API: PHP function or wp_consent_level cookie.
		if ( function_exists( 'wp_has_consent' ) && wp_has_consent( 'marketing' ) ) {
			return true;
		}
		if ( isset( $_COOKIE['wp_consent_level'] ) ) {
			$raw = wp_unslash( $_COOKIE['wp_consent_level'] );
			$decoded = json_decode( stripslashes( $raw ), true );
			if ( is_array( $decoded ) && isset( $decoded['marketing'] ) && (string) $decoded['marketing'] === 'allow' ) {
				return true;
			}
			// Some implementations use a simple string per category.
			if ( is_string( $raw ) && strtolower( $raw ) === 'allow' ) {
				return true;
			}
		}

		// Fallback: no positive consent detected on the server.
		return false;
	}
}
