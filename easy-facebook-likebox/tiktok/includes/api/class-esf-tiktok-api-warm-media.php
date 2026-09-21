<?php
/**
 * TikTok feed media warm REST API.
 *
 * Schedules batched background downloads for cached post media after preview
 * (mirrors {@see ESF_Instagram_API_Warm_Media}).
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok/API
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_TikTok_API_Warm_Media
 *
 * @since 6.9.0
 */
class ESF_TikTok_API_Warm_Media {

	/**
	 * Register REST route.
	 *
	 * @since 6.9.0
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/tiktok/feeds/(?P<id>[\d]+)/warm-media',
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
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function permissions_check( $request ) {
		unset( $request );
		return function_exists( 'esf_tiktok_user_can_manage' )
			? esf_tiktok_user_can_manage()
			: current_user_can( 'manage_options' );
	}

	/**
	 * @param WP_REST_Request $request Request.
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

		$feed = ESF_TikTok_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( ! $feed ) {
			return new WP_Error(
				'not_found',
				__( 'Feed not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$account_id = (int) $request->get_param( 'account_id' );
		if ( $account_id <= 0 ) {
			$account_id = isset( $feed->account_id ) ? (int) $feed->account_id : 0;
		}

		if ( $account_id <= 0 || ! class_exists( 'ESF_TikTok_Local_Media' ) ) {
			return rest_ensure_response(
				array(
					'scheduled' => false,
					'total'     => 0,
					'pending'   => 0,
					'offset'    => 0,
				)
			);
		}

		$result = ESF_TikTok_Local_Media::schedule_warm_for_feed( $feed_id, $account_id );
		return rest_ensure_response( is_array( $result ) ? $result : array( 'scheduled' => false ) );
	}
}
