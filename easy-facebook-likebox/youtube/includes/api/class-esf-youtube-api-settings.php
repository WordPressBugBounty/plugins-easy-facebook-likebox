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
		$cache    = isset( $settings['cache_duration'] ) ? (int) $settings['cache_duration'] : 43200;

		// Ensure stored value is allowed; otherwise return default.
		if ( ! in_array( $cache, self::CACHE_DURATION_OPTIONS, true ) ) {
			$cache = 43200;
		}

		return rest_ensure_response(
			array(
				'cache_duration' => $cache,
			)
		);
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

		return self::get_settings( $request );
	}

	/**
	 * Clear YouTube feed cache (API video caches).
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object (unused; required by REST API signature).
	 * @return WP_REST_Response
	 */
	public static function clear_cache( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$deleted = ESF_YouTube_Cache::flush_api_cache();
		return rest_ensure_response(
			array(
				'success' => ( false !== $deleted ),
				'deleted' => false !== $deleted ? (int) $deleted : 0,
			)
		);
	}
}
