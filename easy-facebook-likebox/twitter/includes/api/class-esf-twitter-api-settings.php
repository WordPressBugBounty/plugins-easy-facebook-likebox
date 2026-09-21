<?php
/**
 * Twitter Settings REST API
 *
 * GET/PUT endpoints for Twitter module settings.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/API
 * @since 6.7.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Twitter_API_Settings
 *
 * @since 6.7.6
 */
class ESF_Twitter_API_Settings {
	/**
	 * Allowed cache duration values (seconds).
	 *
	 * @since 6.7.6
	 * @var int[]
	 */
	const CACHE_DURATION_OPTIONS = array( 43200, 86400, 604800 );

	/**
	 * Default module settings.
	 *
	 * @since 6.7.6
	 * @var array
	 */
	private static $defaults = array(
		'cache_duration'                => 43200, // 12 hours.
		'notify_token_reconnect'        => true,
		'notify_token_reconnect_emails' => array(),
	);

	/**
	 * Register REST API routes.
	 *
	 * @since 6.7.6
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/twitter/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => array( __CLASS__, 'permissions_check' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_settings' ),
					'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
				),
			)
		);

		register_rest_route(
			'esf/v1',
			'/twitter/settings/clear-cache',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'clear_cache' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
			)
		);
	}

	/**
	 * Read permission callback.
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function permissions_check( $request ) {
		if ( $request instanceof WP_REST_Request && 'GET' !== $request->get_method() ) {
			return false;
		}

		return esf_twitter_user_can_manage();
	}

	/**
	 * Write permission callback.
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function write_permissions_check( $request ) {
		if ( ! esf_twitter_user_can_manage() ) {
			return false;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );

		return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * GET /esf/v1/twitter/settings
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_settings( $request ) {
		unset( $request );

		$saved    = get_option( 'esf_twitter_settings', array() );
		$settings = function_exists( 'esf_get_twitter_settings' )
			? esf_get_twitter_settings()
			: array_merge( self::$defaults, is_array( $saved ) ? $saved : array() );

		return rest_ensure_response( self::format_settings_response( $settings ) );
	}

	/**
	 * PUT /esf/v1/twitter/settings
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_settings( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || empty( $body ) ) {
			return new WP_Error( 'invalid_body', __( 'No settings provided.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$saved    = get_option( 'esf_twitter_settings', array() );
		$existing = is_array( $saved ) ? $saved : array();

		if ( isset( $body['cache_duration'] ) ) {
			$raw_value   = (int) $body['cache_duration'];
			$has_license = function_exists( 'esf_twitter_has_twitter_plan' ) && esf_twitter_has_twitter_plan();

			// Free uses a fixed 7-day cache duration.
			if ( $has_license ) {
				$existing['cache_duration'] = in_array( $raw_value, self::CACHE_DURATION_OPTIONS, true )
					? max( 43200, $raw_value )
					: 43200;
			} else {
				$existing['cache_duration'] = 604800;
			}
		}
		if ( array_key_exists( 'notify_token_reconnect', $body ) ) {
			$existing['notify_token_reconnect'] = (bool) $body['notify_token_reconnect'];
		}
		if ( array_key_exists( 'notify_token_reconnect_emails', $body ) && function_exists( 'esf_sanitize_notify_emails' ) ) {
			$existing['notify_token_reconnect_emails'] = esf_sanitize_notify_emails( $body['notify_token_reconnect_emails'] );
		}

		if ( ! empty( $existing['notify_token_reconnect'] ) && empty( $existing['notify_token_reconnect_emails'] ) && function_exists( 'esf_default_notify_emails' ) ) {
			$existing['notify_token_reconnect_emails'] = esf_default_notify_emails();
		}

		update_option( 'esf_twitter_settings', $existing );
		if ( class_exists( 'ESF_Twitter_Main' ) ) {
			ESF_Twitter_Main::get_instance()->reschedule_feed_cache_refresh();
		}

		$result = function_exists( 'esf_get_twitter_settings' )
			? esf_get_twitter_settings()
			: array_merge( self::$defaults, $existing );

		return rest_ensure_response( self::format_settings_response( $result ) );
	}

	/**
	 * Clear Twitter API cache.
	 *
	 * Pro-gated: this bulk action wipes every account's cached timeline at
	 * once, which would let free users bypass the 7‑day cache throttle on
	 * demand and drive up ESF's shared X API spend. Free users who need a
	 * lighter-weight, targeted refresh should use the per-account
	 * "Refresh now" action instead ({@see ESF_Twitter_API_Accounts::refresh_cache()}),
	 * which stays available for BYOK accounts on any plan. See `twitter/PLAN.md`.
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function clear_cache( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! function_exists( 'esf_twitter_has_twitter_plan' ) || ! esf_twitter_has_twitter_plan() ) {
			return new WP_Error(
				'pro_required',
				__( 'Clearing the cache on demand needs the X Pro plan. X charges for API access, so free sites refresh less often. Use Refresh now on the Accounts tab to update a single feed instead.', 'easy-facebook-likebox' ),
				array( 'status' => 403 )
			);
		}

		$deleted = ESF_Twitter_Cache::flush_all_cache();

		return rest_ensure_response(
			array(
				'success' => false !== $deleted,
				'deleted' => false !== $deleted ? (int) $deleted : 0,
			)
		);
	}

	/**
	 * Shape the REST response with UI-only administrator email choices.
	 *
	 * @since 6.9.0
	 * @param array<string,mixed> $settings Normalized settings.
	 * @return array<string,mixed>
	 */
	private static function format_settings_response( array $settings ) {
		return array(
			'cache_duration'                => isset( $settings['cache_duration'] ) ? (int) $settings['cache_duration'] : 43200,
			'notify_token_reconnect'        => ! empty( $settings['notify_token_reconnect'] ),
			'notify_token_reconnect_emails' => isset( $settings['notify_token_reconnect_emails'] ) && is_array( $settings['notify_token_reconnect_emails'] )
				? $settings['notify_token_reconnect_emails']
				: array(),
			'admin_email_options'           => function_exists( 'esf_get_site_administrator_email_options' )
				? esf_get_site_administrator_email_options()
				: array(),
		);
	}
}
