<?php
/**
 * Instagram Feed Media Warm REST API.
 *
 * Schedules batched background downloads for cached feed media after preview.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/API
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_API_Warm_Media
 *
 * @since 6.9.0
 */
class ESF_Instagram_API_Warm_Media {

	/**
	 * Register REST route for background media warm.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/instagram/feeds/(?P<id>[\d]+)/warm-media',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'warm_media' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => array(
					'id'         => array(
						'description'       => __( 'Feed ID.', 'easy-facebook-likebox' ),
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0;
						},
					),
					'account_id' => array(
						'description'       => __( 'Optional account ID override for customize preview.', 'easy-facebook-likebox' ),
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
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
	 * Schedule batched local media downloads for a feed.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function warm_media( $request ) {
		$feed_id = (int) $request['id'];
		if ( $feed_id <= 0 ) {
			return new WP_Error(
				'invalid_feed_id',
				__( 'Invalid feed ID.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( 'ESF_Instagram_Feed_Repository' ) || ! class_exists( 'ESF_Instagram_Local_Media' ) ) {
			return new WP_Error(
				'warm_unavailable',
				__( 'Media warm is not available.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$feed_row = ESF_Instagram_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return new WP_Error(
				'feed_not_found',
				__( 'Feed not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$account_id = isset( $feed_row->account_id ) ? (int) $feed_row->account_id : 0;
		$override   = (int) $request->get_param( 'account_id' );
		if ( $override > 0 ) {
			$account_id = $override;
		}

		if ( $account_id <= 0 ) {
			return new WP_Error(
				'account_not_found',
				__( 'Account not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$result = ESF_Instagram_Local_Media::schedule_warm_for_feed( $feed_id, $account_id );

		return rest_ensure_response( $result );
	}
}
