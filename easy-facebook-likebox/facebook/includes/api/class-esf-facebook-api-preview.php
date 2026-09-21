<?php
/**
 * Facebook Feed Preview REST API.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/API
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_API_Preview
 *
 * @since 6.7.9
 */
class ESF_Facebook_API_Preview {

	/**
	 * Register REST route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$args = array(
			'id'            => array(
				'description'       => __( 'Feed ID.', 'easy-facebook-likebox' ),
				'type'              => 'integer',
				'required'          => true,
				'validate_callback' => static function ( $param ) {
					return is_numeric( $param ) && (int) $param > 0;
				},
			),
			'settings'      => array(
				'description'       => __( 'Optional settings override for live preview.', 'easy-facebook-likebox' ),
				'required'          => false,
				'validate_callback' => array( __CLASS__, 'validate_settings_param' ),
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings_param' ),
			),
			'page_id'       => array(
				'description'       => __( 'Optional internal page row override.', 'easy-facebook-likebox' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'feed_type'     => array(
				'description'       => __( 'Optional feed type override.', 'easy-facebook-likebox' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			),
			'album_id'      => array(
				'description'       => __( 'Optional album id override (albums filter).', 'easy-facebook-likebox' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'events_filter' => array(
				'description'       => __( 'Optional events filter override.', 'easy-facebook-likebox' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			),
		);

		register_rest_route(
			'esf/v1',
			'/facebook/feeds/(?P<id>[\d]+)/preview',
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
	 * @param mixed $value Raw.
	 * @return bool
	 */
	public static function validate_settings_param( $value ) {
		return null === $value || is_array( $value ) || is_string( $value );
	}

	/**
	 * @param mixed $value Raw.
	 * @return array
	 */
	public static function sanitize_settings_param( $value ) {
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
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function permissions_check( $request ) {
		unset( $request );
		return function_exists( 'esf_facebook_user_can_manage' )
			? esf_facebook_user_can_manage()
			: current_user_can( 'manage_options' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_preview( $request ) {
		$feed_id           = (int) $request['id'];
		$page_id           = (int) $request->get_param( 'page_id' );
		$settings_override = $request->get_param( 'settings' );
		if ( ! is_array( $settings_override ) ) {
			$settings_override = array();
		}

		$feed_overrides = array();
		$allowed_types  = class_exists( 'ESF_Facebook_Feed_Query' )
			? ESF_Facebook_Feed_Query::TYPES
			: array( 'timeline', 'images', 'videos', 'events', 'albums', 'mentioned' );

		$feed_type = sanitize_key( (string) $request->get_param( 'feed_type' ) );
		if ( ! in_array( $feed_type, $allowed_types, true ) ) {
			$feed_type = '';
		}
		if ( '' !== $feed_type ) {
			$feed_overrides['feed_type'] = $feed_type;
		}

		// Sub-options may arrive via settings (Connect panel live preview).
		if ( isset( $settings_override['feed'] ) && is_array( $settings_override['feed'] ) ) {
			$feed_cfg = $settings_override['feed'];
			if ( ! isset( $feed_overrides['feed_type'] ) && ! empty( $feed_cfg['feed_type'] ) ) {
				// Soft preview state before save — prefer explicit request param when present.
				$soft = sanitize_key( (string) $feed_cfg['feed_type'] );
				if ( in_array( $soft, $allowed_types, true ) ) {
					$feed_overrides['feed_type'] = $soft;
				}
			}
			if ( array_key_exists( 'album_id', $feed_cfg ) ) {
				$feed_overrides['album_id'] = sanitize_text_field( (string) $feed_cfg['album_id'] );
			}
			if ( array_key_exists( 'events_filter', $feed_cfg ) ) {
				$feed_overrides['events_filter'] = sanitize_key( (string) $feed_cfg['events_filter'] );
			}
		}

		// Top-level overrides win over settings.
		if ( null !== $request->get_param( 'album_id' ) ) {
			$feed_overrides['album_id'] = sanitize_text_field( (string) $request->get_param( 'album_id' ) );
		}
		if ( null !== $request->get_param( 'events_filter' ) ) {
			$feed_overrides['events_filter'] = sanitize_key( (string) $request->get_param( 'events_filter' ) );
		}

		if ( $feed_id <= 0 ) {
			return new WP_Error(
				'invalid_feed_id',
				__( 'Invalid feed ID.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$feed_row = ESF_Facebook_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( $feed_row && isset( $feed_row->content_type ) && 'likebox' === sanitize_key( (string) $feed_row->content_type ) ) {
			return new WP_Error(
				'unsupported_content_type',
				__( 'Like Box preview is available from the Like Box tab.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$renderer = new ESF_Facebook_Renderer();
		$payload  = $renderer->build_preview_payload( $feed_id, $settings_override, $page_id, $feed_overrides );

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
