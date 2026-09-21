<?php
/**
 * TikTok Feed Preview REST API.
 *
 * Returns rendered HTML (and asset URLs) for dashboard live preview.
 *
 * Settings overrides are accepted in the POST body (not the query string) so
 * large feed configs do not hit nginx/Apache "414 Request-URI Too Large".
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok/API
 * @since 6.7.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_TikTok_API_Preview
 *
 * @since 6.7.6
 */
class ESF_TikTok_API_Preview {

	/**
	 * Register REST route for feed preview.
	 *
	 * @since 6.7.6
	 *
	 * @return void
	 */
	public static function register_routes() {
		$args = array(
			'id'         => array(
				/* translators: %s: feed ID. */
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
		);

		register_rest_route(
			'esf/v1',
			'/tiktok/feeds/(?P<id>[\d]+)/preview',
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
	 * Permission callback: only users who can manage TikTok module.
	 *
	 * @since 6.7.6
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function permissions_check( $request ) {
		unset( $request );
		return function_exists( 'esf_tiktok_user_can_manage' ) ? esf_tiktok_user_can_manage() : current_user_can( 'manage_options' );
	}

	/**
	 * Get preview HTML and asset URLs for a feed.
	 *
	 * @since 6.7.6
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

		if ( $feed_id <= 0 ) {
			return new WP_Error(
				'invalid_feed_id',
				__( 'Invalid feed ID.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$renderer = new ESF_TikTok_Renderer();
		$payload  = $renderer->build_preview_payload( $feed_id, $settings_override, $account_id );

		if ( empty( $payload['html'] ) ) {
			return new WP_Error(
				'preview_failed',
				__( 'Could not generate preview.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $payload );
	}
}
