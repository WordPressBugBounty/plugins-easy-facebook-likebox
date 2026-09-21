<?php
/**
 * Facebook Settings REST API
 *
 * Basic GET/PUT for module settings + clear-cache.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/API
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_API_Settings
 *
 * @since 6.7.9
 */
class ESF_Facebook_API_Settings {

	/**
	 * Allowed cache duration values (seconds).
	 *
	 * @var int[]
	 */
	const CACHE_DURATION_OPTIONS = array( 3600, 43200, 86400, 604800 );

	/**
	 * Register settings routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/facebook/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => array( __CLASS__, 'read_permissions_check' ),
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
			'/facebook/settings/clear-cache',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'clear_cache' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
			)
		);
	}

	/**
	 * Read permission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function read_permissions_check( $request ) {
		if ( $request instanceof WP_REST_Request && 'GET' !== $request->get_method() ) {
			return false;
		}
		return function_exists( 'esf_facebook_user_can_manage' ) && esf_facebook_user_can_manage();
	}

	/**
	 * Write permission with REST nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function write_permissions_check( $request ) {
		if ( ! function_exists( 'esf_facebook_user_can_manage' ) || ! esf_facebook_user_can_manage() ) {
			return false;
		}
		$nonce = $request instanceof WP_REST_Request ? $request->get_header( 'X-WP-Nonce' ) : '';
		return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * GET settings.
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return WP_REST_Response|array
	 */
	public static function get_settings( $request = null ) {
		unset( $request );

		$saved    = get_option( 'esf_facebook_settings', array() );
		$settings = function_exists( 'esf_facebook_normalize_settings' )
			? esf_facebook_normalize_settings( is_array( $saved ) ? $saved : array() )
			: array();

		return rest_ensure_response( self::format_settings_response( $settings ) );
	}

	/**
	 * PUT settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error|array
	 */
	public static function update_settings( $request ) {
		$body = $request instanceof WP_REST_Request ? $request->get_json_params() : null;
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'invalid_body', __( 'No settings provided.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$current = get_option( 'esf_facebook_settings', array() );
		$current = is_array( $current ) ? $current : array();

		if ( isset( $body['cache_duration'] ) ) {
			$current['cache_duration'] = self::sanitize_cache_duration( (int) $body['cache_duration'] );
		}
		if ( array_key_exists( 'notify_token_reconnect', $body ) ) {
			$current['notify_token_reconnect'] = (bool) $body['notify_token_reconnect'];
		}
		if ( array_key_exists( 'notify_token_reconnect_emails', $body ) && function_exists( 'esf_sanitize_notify_emails' ) ) {
			$current['notify_token_reconnect_emails'] = esf_sanitize_notify_emails( $body['notify_token_reconnect_emails'] );
		}

		if ( ! empty( $current['notify_token_reconnect'] ) && empty( $current['notify_token_reconnect_emails'] ) && function_exists( 'esf_default_notify_emails' ) ) {
			$current['notify_token_reconnect_emails'] = esf_default_notify_emails();
		}

		update_option( 'esf_facebook_settings', $current );

		$settings = function_exists( 'esf_facebook_normalize_settings' )
			? esf_facebook_normalize_settings( $current )
			: $current;

		return rest_ensure_response( self::format_settings_response( $settings ) );
	}

	/**
	 * Clear all Facebook cache rows.
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return WP_REST_Response|array
	 */
	public static function clear_cache( $request = null ) {
		unset( $request );

		$deleted = class_exists( 'ESF_Facebook_Cache' )
			? ESF_Facebook_Cache::flush_all_cache()
			: false;

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
	 * @since 6.7.9
	 * @param array<string,mixed> $settings Normalized settings.
	 * @return array<string,mixed>
	 */
	private static function format_settings_response( array $settings ) {
		$response = array(
			'cache_duration'                => isset( $settings['cache_duration'] ) ? (int) $settings['cache_duration'] : 43200,
			'notify_token_reconnect'        => ! empty( $settings['notify_token_reconnect'] ),
			'notify_token_reconnect_emails' => isset( $settings['notify_token_reconnect_emails'] ) && is_array( $settings['notify_token_reconnect_emails'] )
				? $settings['notify_token_reconnect_emails']
				: array(),
			'cache_duration_options'        => self::CACHE_DURATION_OPTIONS,
		);

		$response['admin_email_options'] = function_exists( 'esf_get_site_administrator_email_options' )
			? esf_get_site_administrator_email_options()
			: array();

		return $response;
	}

	/**
	 * Sanitize cache duration to allowed whitelist.
	 *
	 * All whitelist intervals are available on free and Pro.
	 *
	 * @param int $duration Seconds.
	 * @return int
	 */
	private static function sanitize_cache_duration( $duration ) {
		$duration = (int) $duration;
		if ( ! in_array( $duration, self::CACHE_DURATION_OPTIONS, true ) ) {
			$duration = 43200;
		}
		return $duration;
	}
}
