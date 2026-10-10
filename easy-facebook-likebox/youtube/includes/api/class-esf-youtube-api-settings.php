<?php
/**
 * YouTube Settings REST API.
 *
 * Exposes GET and PATCH endpoints for YouTube module settings (e.g. cache duration).
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/API
 * @since 6.7.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_API_Settings
 *
 * @since 6.7.5
 */
class ESF_YouTube_API_Settings {

	/**
	 * Allowed cache duration values (seconds). Used for validation and options.
	 *
	 * @since 6.7.5
	 * @var int[]
	 */
	const CACHE_DURATION_OPTIONS = array(
		3600,    // One hour.
		10800,   // Three hours.
		21600,   // Six hours.
		43200,   // Twelve hours, default.
		86400,   // Twenty-four hours.
		604800,  // Seven days.
	);

	/**
	 * Register REST API routes.
	 *
	 * @since 6.7.5
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/youtube/settings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_settings' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/youtube/settings',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_settings' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => array(
					'cache_duration' => array(
						'description'       => __( 'Cache duration in seconds. Must be one of the allowed values.', 'easy-facebook-likebox' ),
						'type'              => 'integer',
						'required'          => false,
						'validate_callback' => array( __CLASS__, 'validate_cache_duration' ),
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'esf/v1',
			'/youtube/settings/clear-cache',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'clear_cache' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object (unused; required by REST API signature).
	 * @return bool
	 */
	public static function permissions_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return esf_youtube_user_can_manage();
	}

	/**
	 * Validate cache_duration is one of the allowed values.
	 *
	 * @since 6.7.5
	 *
	 * @param int $value Submitted value.
	 * @return bool
	 */
	public static function validate_cache_duration( $value ) {
		return in_array( (int) $value, self::CACHE_DURATION_OPTIONS, true );
	}

	/**
	 * Get settings (safe subset for dashboard).
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object (unused; required by REST API signature).
	 * @return WP_REST_Response
	 */
	public static function get_settings( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$settings = esf_get_youtube_settings();

		return rest_ensure_response( self::format_settings_response( $settings ) );
	}

	/**
	 * Update settings.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_settings( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		if ( isset( $params['cache_duration'] ) ) {
			$value = (int) $params['cache_duration'];
			if ( ! in_array( $value, self::CACHE_DURATION_OPTIONS, true ) ) {
				return new WP_Error(
					'invalid_cache_duration',
					__( 'Invalid cache duration. Choose one of the allowed values.', 'easy-facebook-likebox' ),
					array( 'status' => 400 )
				);
			}
			esf_update_youtube_settings( 'cache_duration', $value );

			// Reschedule feed cache refresh cron so recurrence matches new cache duration.
			if ( class_exists( 'ESF_YouTube_Main' ) ) {
				ESF_YouTube_Main::get_instance()->reschedule_feed_cache_refresh();
			}
		}
		if ( array_key_exists( 'notify_token_reconnect', $params ) ) {
			esf_update_youtube_settings( 'notify_token_reconnect', (bool) $params['notify_token_reconnect'] );
		}
		if ( array_key_exists( 'notify_token_reconnect_emails', $params ) && function_exists( 'esf_sanitize_notify_emails' ) ) {
			esf_update_youtube_settings( 'notify_token_reconnect_emails', esf_sanitize_notify_emails( $params['notify_token_reconnect_emails'] ) );
		}

		$current = esf_get_youtube_settings();
		if ( ! empty( $current['notify_token_reconnect'] ) && empty( $current['notify_token_reconnect_emails'] ) && function_exists( 'esf_default_notify_emails' ) ) {
			esf_update_youtube_settings( 'notify_token_reconnect_emails', esf_default_notify_emails() );
		}

		return self::get_settings( $request );
	}

	/**
	 * Safely refresh YouTube feed caches from the YouTube API.
	 *
	 * Fetches first; existing cache is kept when the API fails or returns empty.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object (unused; required by REST API signature).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function clear_cache( $request ) {
		if ( class_exists( 'ESF_Safe_Cache_Refresh' ) ) {
			return ESF_Safe_Cache_Refresh::handle_clear_cache_request( 'youtube', $request );
		}

		$deleted = ESF_YouTube_Cache::flush_api_cache();
		return rest_ensure_response(
			array(
				'success' => ( false !== $deleted ),
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
		$cache = isset( $settings['cache_duration'] ) ? (int) $settings['cache_duration'] : 43200;
		if ( ! in_array( $cache, self::CACHE_DURATION_OPTIONS, true ) ) {
			$cache = 43200;
		}

		return array(
			'cache_duration'                => $cache,
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
