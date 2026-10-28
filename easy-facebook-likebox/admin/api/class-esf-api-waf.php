<?php
/**
 * Central WAF-compatibility layer for the `esf/*` REST namespace.
 *
 * Web application firewalls (Sucuri, Cloudflare, Wordfence, ModSecurity, managed-host
 * WAFs) can reject authenticated write requests whose JSON body contains HTML, custom
 * CSS, SQL-like text or long access tokens. The shared JS middleware
 * (`admin/shared/dashboard-core/services/apiFetchMiddleware.js`) therefore wraps the
 * whole body in Base64 when such a pattern is present. This class undoes that wrapping
 * once, in a single `rest_pre_dispatch` hook, so no route callback needs to know about it.
 *
 * Wire contract (client -> server):
 *
 * - Header `X-ESF-Body-Encoded: 1`
 * - Content-Type `application/json`
 * - Body `{ "__esf_encoded": "<base64( original JSON body )>" }`
 *
 * The wrapper itself is valid JSON so body-inspecting firewalls never see a malformed
 * payload. Requests without the header are left completely untouched.
 *
 * @package Easy_Social_Feed
 * @subpackage Admin/API
 * @since      6.8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_API_WAF' ) ) {

	/**
	 * Class ESF_API_WAF
	 *
	 * @since 6.8.2
	 */
	class ESF_API_WAF {

		/**
		 * Request header flagging a Base64-wrapped body.
		 *
		 * @var string
		 */
		const HEADER = 'X-ESF-Body-Encoded';

		/**
		 * JSON key carrying the Base64 payload inside the wrapper body.
		 *
		 * @var string
		 */
		const BODY_KEY = '__esf_encoded';

		/**
		 * Route prefix (all `esf/*` namespaces) the decoder applies to.
		 *
		 * @var string
		 */
		const ROUTE_PREFIX = '/esf/';

		/**
		 * Whether the hook has been registered in this request.
		 *
		 * @var bool
		 */
		private static $initialized = false;

		/**
		 * Script handles that bundle the shared WAF middleware.
		 *
		 * @since 6.8.2
		 * @var string[]
		 */
		private static $admin_script_handles = array(
			'esf-welcome-wizard',
			'esf-module-hub',
			'esf-settings',
			'esf-social-wall',
			'esf-facebook-dashboard',
			'esf-instagram-dashboard',
			'esf-youtube-dashboard',
			'esf-twitter-dashboard',
			'esf-tiktok-dashboard',
		);

		/**
		 * Register the central decode hook (idempotent).
		 *
		 * @since 6.8.2
		 * @return void
		 */
		public static function init() {
			if ( self::$initialized ) {
				return;
			}
			self::$initialized = true;

			// Priority 5: run before other plugins inspect the request body.
			add_filter( 'rest_pre_dispatch', array( __CLASS__, 'maybe_decode_request' ), 5, 3 );
			// After module dashboards enqueue so we can attach to their handles.
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'localize_admin_i18n' ), 100 );
		}

		/**
		 * Translated strings + docs URL for the shared admin JS notice/middleware.
		 *
		 * Kept in PHP so Loco / gettext extract them and so runtime translations use
		 * the existing .mo catalogs (no separate JS script-translation JSON required).
		 *
		 * @since 6.8.2
		 * @return array<string,string>
		 */
		public static function get_admin_i18n() {
			return array(
				'docsUrl'           => 'https://easysocialfeed.com/documentation/',
				'allowlistPath'     => '/wp-json/esf/',
				'noticeTitle'       => __( 'A firewall or security plugin is blocking Easy Social Feed.', 'easy-facebook-likebox' ),
				/* translators: %d: HTTP status code returned by the firewall, e.g. 403. */
				'noticeBody'        => __( 'A request to the Easy Social Feed REST API was rejected with a %d response before it reached WordPress. Please allow the following path in your firewall or security plugin, using "begins with" matching, then try again:', 'easy-facebook-likebox' ),
				'noticeSucuri'      => __( 'Sucuri: Firewall (WAF) > Allowlist & Blocklist > Allow a URL Path.', 'easy-facebook-likebox' ),
				'noticeCloudflare'  => __( 'Cloudflare: Security > WAF > Custom rules, add a Skip rule when the URI Path starts with this path.', 'easy-facebook-likebox' ),
				'noticeWordfence'   => __( 'Wordfence: Firewall > All Firewall Options > Allowlisted URLs.', 'easy-facebook-likebox' ),
				'noticeModsecurity' => __( 'ModSecurity or host firewall: ask your host to allow this path.', 'easy-facebook-likebox' ),
				'noticeDocsLink'    => __( 'Read the firewall compatibility guide', 'easy-facebook-likebox' ),
				'noticeDismiss'     => __( 'Dismiss this notice.', 'easy-facebook-likebox' ),
				'blockedMessage'    => __( 'The request was blocked by a firewall or security plugin. Allow /wp-json/esf/ in your firewall and try again.', 'easy-facebook-likebox' ),
				'invalidJson'       => __( 'The response is not a valid JSON response.', 'easy-facebook-likebox' ),
			);
		}

		/**
		 * Expose {@see get_admin_i18n()} to the active ESF admin React bundle.
		 *
		 * @since 6.8.2
		 * @return void
		 */
		public static function localize_admin_i18n() {
			if ( ! is_admin() ) {
				return;
			}

			foreach ( self::$admin_script_handles as $handle ) {
				if ( ! wp_script_is( $handle, 'enqueued' ) ) {
					continue;
				}

				$payload = 'window.esfWafI18n = ' . wp_json_encode( self::get_admin_i18n() ) . ';';
				wp_add_inline_script( $handle, $payload, 'before' );
				return;
			}
		}

		/**
		 * `rest_pre_dispatch` callback: unwrap an encoded body on `esf/*` routes.
		 *
		 * @since 6.8.2
		 *
		 * @param mixed                 $result  Short-circuit result from earlier filters.
		 * @param WP_REST_Server|object $server  REST server (unused).
		 * @param WP_REST_Request       $request Current request.
		 * @return mixed Unchanged `$result`, or a `WP_Error` when the encoded body is invalid.
		 */
		public static function maybe_decode_request( $result, $server = null, $request = null ) {
			unset( $server );

			if ( null !== $result || ! $request instanceof WP_REST_Request ) {
				return $result;
			}

			if ( 0 !== strpos( (string) $request->get_route(), self::ROUTE_PREFIX ) ) {
				return $result;
			}

			/**
			 * Filters whether `esf/*` REST requests may carry a Base64-wrapped body.
			 *
			 * @since 6.8.2
			 *
			 * @param bool            $enabled Whether decoding is enabled. Default true.
			 * @param WP_REST_Request $request Current request.
			 */
			if ( ! apply_filters( 'esf_rest_decode_waf_body', true, $request ) ) {
				return $result;
			}

			$decoded = self::decode_body( $request );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}

			if ( null !== $decoded ) {
				// Replaces the body and resets WP_REST_Request's parsed JSON/body caches.
				$request->set_body( $decoded );
				$request->set_header( 'Content-Type', 'application/json' );
				// Prevents a second decode if the same request object is re-dispatched.
				$request->remove_header( self::HEADER );
			}

			return $result;
		}

		/**
		 * Decode a wrapped body into an array of request parameters.
		 *
		 * Convenience wrapper around {@see self::decode_body()} for callers that want the
		 * parameters instead of the raw JSON string.
		 *
		 * @since 6.8.2
		 *
		 * @param WP_REST_Request $request REST request.
		 * @return array<mixed>|WP_Error|null Decoded params, null when not encoded, WP_Error when invalid.
		 */
		public static function maybe_decode_body( WP_REST_Request $request ) {
			$json = self::decode_body( $request );
			if ( null === $json || is_wp_error( $json ) ) {
				return $json;
			}

			$params = json_decode( $json, true );

			return is_array( $params ) ? $params : array();
		}

		/**
		 * Extract and validate the original JSON string from a wrapped body.
		 *
		 * @since 6.8.2
		 *
		 * @param WP_REST_Request $request REST request.
		 * @return string|WP_Error|null Original JSON string, null when the request is not
		 *                              encoded, WP_Error when it claims to be but is invalid.
		 */
		public static function decode_body( WP_REST_Request $request ) {
			if ( '1' !== (string) $request->get_header( self::HEADER ) ) {
				return null;
			}

			$method = strtoupper( (string) $request->get_method() );
			if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
				return null;
			}

			$wrapper = json_decode( (string) $request->get_body(), true );
			if ( ! is_array( $wrapper ) || ! isset( $wrapper[ self::BODY_KEY ] ) || ! is_string( $wrapper[ self::BODY_KEY ] ) ) {
				return self::invalid_body_error();
			}

			$json = base64_decode( $wrapper[ self::BODY_KEY ], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport decoding of our own request body, not obfuscation.
			if ( false === $json || '' === $json ) {
				return self::invalid_body_error();
			}

			// The original payload is always a JSON object or array.
			$params = json_decode( $json, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $params ) ) {
				return self::invalid_body_error();
			}

			return $json;
		}

		/**
		 * Error returned for a malformed encoded body.
		 *
		 * @since 6.8.2
		 * @return WP_Error
		 */
		private static function invalid_body_error() {
			return new WP_Error(
				'esf_invalid_encoded_body',
				__( 'The encoded request body is invalid.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}
	}
}
