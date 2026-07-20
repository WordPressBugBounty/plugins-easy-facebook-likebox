<?php
/**
 * Instagram Feed Preview REST API.
 *
 * Returns rendered HTML (and asset URLs) for the admin dashboard live preview.
 * Shape mirrors {@see ESF_Twitter_API_Preview::get_preview()} so the shared
 * `useFeedPreview` hook can consume it without per-module branching.
 *
 * Settings overrides are accepted in the POST body (not the query string) so
 * large feed configs do not hit nginx/Apache "414 Request-URI Too Large".
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/API
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_API_Preview
 *
 * @since 6.9.0
 */
class ESF_Instagram_API_Preview {

	/**
	 * Register REST route for feed preview.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$args = array(
			'id'         => array(
				'description'       => __( 'Feed ID.', 'easy-facebook-likebox' ),
				'type'              => 'integer',
				'required'          => true,
				'validate_callback' => static function ( $param ) {
					return is_numeric( $param ) && (int) $param > 0;
				},
			),
			'settings'   => array(
				'description'       => __( 'Optional settings override for live preview (prefer POST body).', 'easy-facebook-likebox' ),
				'required'          => false,
				'validate_callback' => array( __CLASS__, 'validate_settings_param' ),
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings_param' ),
			),
			'account_id' => array(
				'description'       => __( 'Optional account ID override for unsaved source changes.', 'easy-facebook-likebox' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'feed_type'  => array(
				'description'       => __( 'Optional feed type override for live preview.', 'easy-facebook-likebox' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			),
			'source_id'  => array(
				'description'       => __( 'Optional hashtag source override for live preview.', 'easy-facebook-likebox' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => static function ( $value ) {
					return function_exists( 'esf_instagram_normalize_hashtag' )
						? esf_instagram_normalize_hashtag( (string) $value )
						: sanitize_text_field( (string) $value );
				},
			),
		);

		register_rest_route(
			'esf/v1',
			'/instagram/feeds/(?P<id>[\d]+)/preview',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'get_preview' ),
					'permission_callback' => array( __CLASS__, 'permissions_check' ),
					'args'                => $args,
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_preview' ),
					'permission_callback' => array( __CLASS__, 'permissions_check' ),
					'args'                => $args,
				),
			)
		);
	}

	/**
	 * Allow object (POST JSON) or legacy JSON string (GET query).
	 *
	 * @param mixed           $value   Raw param.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Param name.
	 * @return bool
	 */
	public static function validate_settings_param( $value, $request = null, $param = '' ) {
		unset( $request, $param );
		return null === $value || is_array( $value ) || is_string( $value );
	}

	/**
	 * Normalize settings override to an array.
	 *
	 * @param mixed           $value   Raw param.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Param name.
	 * @return array
	 */
	public static function sanitize_settings_param( $value, $request = null, $param = '' ) {
		unset( $request, $param );
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Permission callback: only users who can manage the Instagram module.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function permissions_check( $request ) {
		unset( $request );
		return function_exists( 'esf_instagram_user_can_manage' )
			? esf_instagram_user_can_manage()
			: current_user_can( 'manage_options' );
	}

	/**
	 * Get preview payload for a feed.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_preview( $request ) {
		$feed_id           = (int) $request['id'];
		$account_id        = (int) $request->get_param( 'account_id' );
		$settings_override = $request->get_param( 'settings' );
		if ( ! is_array( $settings_override ) ) {
			$settings_override = array();
		}

		$feed_overrides = array();
		$feed_type      = sanitize_key( (string) $request->get_param( 'feed_type' ) );
		if ( in_array( $feed_type, array( 'user_timeline', 'hashtag' ), true ) ) {
			$feed_overrides['feed_type'] = $feed_type;
		}
		if ( null !== $request->get_param( 'source_id' ) ) {
			$feed_overrides['source_id'] = (string) $request->get_param( 'source_id' );
		}

		if ( $feed_id <= 0 ) {
			return new WP_Error(
				'invalid_feed_id',
				__( 'Invalid feed ID.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$renderer = new ESF_Instagram_Renderer();
		$payload  = $renderer->build_preview_payload( $feed_id, $settings_override, $account_id, $feed_overrides );

		if ( '' === $payload['html'] ) {
			return new WP_Error(
				'preview_failed',
				__( 'Could not generate preview.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $payload );
	}
}
