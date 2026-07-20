<?php
/**
 * REST API for the shared review-request notice.
 *
 * @package Easy_Social_Feed
 * @subpackage Admin/API
 * @since      6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_API_Review_Request' ) ) {

	/**
	 * Class ESF_API_Review_Request
	 *
	 * @since 6.9.0
	 */
	class ESF_API_Review_Request {

		const NAMESPACE_V1 = 'esf/v1';

		/**
		 * Register routes.
		 *
		 * @since 6.9.0
		 * @return void
		 */
		public static function register_routes() {
			register_rest_route(
				self::NAMESPACE_V1,
				'/review-request/state',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_state' ),
					'permission_callback' => array( __CLASS__, 'permissions_check' ),
					'args'                => array(
						'preview' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				'/review-request/action',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'post_action' ),
					'permission_callback' => array( __CLASS__, 'permissions_check' ),
					'args'                => array(
						'action' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				)
			);
		}

		/**
		 * @since 6.9.0
		 * @return bool|WP_Error
		 */
		public static function permissions_check() {
			if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'install_plugins' ) ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'You are not allowed to manage review notices.', 'easy-facebook-likebox' ),
					array( 'status' => 403 )
				);
			}

			return true;
		}

		/**
		 * GET /esf/v1/review-request/state
		 *
		 * @since 6.9.0
		 * @return WP_REST_Response
		 */
		public static function get_state( $request = null ) {
			if ( $request instanceof WP_REST_Request && '1' === (string) $request->get_param( 'preview' ) ) {
				ESF_Review_Request::arm_dev_preview();
			}

			return rest_ensure_response( ESF_Review_Request::get_state_for_user() );
		}

		/**
		 * POST /esf/v1/review-request/action
		 *
		 * @since 6.9.0
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function post_action( $request ) {
			$result = ESF_Review_Request::handle_action( (string) $request->get_param( 'action' ) );
			return rest_ensure_response( $result );
		}
	}
}
