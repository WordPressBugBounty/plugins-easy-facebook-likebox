<?php
/**
 * Instagram Settings REST API
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/API
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_API_Settings
 *
 * @since 6.8.0
 */
class ESF_Instagram_API_Settings {

	/**
	 * Allowed cache duration values (seconds).
	 *
	 * @since 6.9.0
	 * @var int[]
	 */
	const CACHE_DURATION_OPTIONS = array( 3600, 43200, 86400, 604800 );

	/**
	 * Default settings.
	 *
	 * @var array<string, mixed>
	 */
	private static $defaults = array(
		'cache_duration'                => 43200,
		'notify_token_reconnect'        => true,
		'notify_token_reconnect_emails' => array(),
	);

	/**
	 * Register settings routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/instagram/settings',
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
			'/instagram/settings/clear-cache',
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
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function read_permissions_check( $request ) {
		if ( $request instanceof WP_REST_Request && 'GET' !== $request->get_method() ) {
			return false;
		}
		return esf_instagram_user_can_manage();
	}

	/**
	 * Write permission callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function write_permissions_check( $request ) {
		if ( ! esf_instagram_user_can_manage() ) {
			return false;
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Get settings.
	 *
	 * @param WP_REST_Request|null $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_settings( $request = null ) {
		unset( $request );

		$saved    = get_option( 'esf_instagram_settings', array() );
		$settings = self::normalize_saved_settings( is_array( $saved ) ? $saved : array() );

		return rest_ensure_response( self::format_settings_response( $settings ) );
	}

	/**
	 * Update settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_settings( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'invalid_body', __( 'No settings provided.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$current = get_option( 'esf_instagram_settings', array() );
		$current = is_array( $current ) ? $current : array();

		if ( isset( $body['cache_duration'] ) ) {
			$current['cache_duration'] = self::sanitize_cache_duration( (int) $body['cache_duration'] );
		}
		if ( array_key_exists( 'notify_token_reconnect', $body ) ) {
			$current['notify_token_reconnect'] = (bool) $body['notify_token_reconnect'];
		}
		if ( array_key_exists( 'notify_token_reconnect_emails', $body ) ) {
			$current['notify_token_reconnect_emails'] = esf_instagram_sanitize_notify_emails( $body['notify_token_reconnect_emails'] );
		}

		if ( ! empty( $current['notify_token_reconnect'] ) && empty( $current['notify_token_reconnect_emails'] ) ) {
			$current['notify_token_reconnect_emails'] = esf_instagram_default_notify_emails();
		}

		update_option( 'esf_instagram_settings', $current );

		return rest_ensure_response( self::format_settings_response( self::normalize_saved_settings( $current ) ) );
	}

	/**
	 * Clear all Instagram module cache rows (API, account-scoped media, hashtags).
	 *
	 * @param WP_REST_Request|null $request Request object.
	 * @return WP_REST_Response
	 */
	public static function clear_cache( $request = null ) {
		unset( $request );

		$deleted = class_exists( 'ESF_Instagram_Cache' )
			? ESF_Instagram_Cache::flush_all_cache()
			: false;

		return rest_ensure_response(
			array(
				'success' => false !== $deleted,
				'deleted' => false !== $deleted ? (int) $deleted : 0,
			)
		);
	}

	/**
	 * Merge saved values with defaults and normalize cache duration.
	 *
	 * @since 6.9.0
	 * @param array<string, mixed> $saved Saved option values.
	 * @return array<string, mixed>
	 */
	private static function normalize_saved_settings( array $saved ) {
		$settings = esf_instagram_normalize_settings( $saved );
		$settings['cache_duration'] = self::sanitize_cache_duration( (int) $settings['cache_duration'] );

		return $settings;
	}

	/**
	 * Shape the REST response with UI-only administrator email choices.
	 *
	 * @since 6.9.0
	 * @param array<string, mixed> $settings Normalized settings.
	 * @return array<string, mixed>
	 */
	private static function format_settings_response( array $settings ) {
		$settings['admin_email_options'] = function_exists( 'esf_get_site_administrator_email_options' )
			? esf_get_site_administrator_email_options()
			: array();

		return $settings;
	}

	/**
	 * Sanitize cache duration against the module whitelist.
	 *
	 * All whitelist intervals are available on free and Pro.
	 *
	 * @since 6.9.0
	 * @param int $raw_value Requested duration in seconds.
	 * @return int
	 */
	private static function sanitize_cache_duration( $raw_value ) {
		if ( in_array( (int) $raw_value, self::CACHE_DURATION_OPTIONS, true ) ) {
			return (int) $raw_value;
		}

		return (int) self::$defaults['cache_duration'];
	}
}
