<?php
/**
 * REST API: stateless Like Box preview (shortcode generator).
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/API
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_API_Likebox
 *
 * @since 6.7.9
 */
class ESF_Facebook_API_Likebox {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1/facebook',
			'/likebox/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'preview' ),
				'permission_callback' => static function () {
					return function_exists( 'esf_facebook_user_can_manage' ) && esf_facebook_user_can_manage();
				},
			)
		);
	}

	/**
	 * Preview Like Box markup from Page URL + settings (no saved feed row).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function preview( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$page_href = isset( $params['page_href'] ) ? trim( (string) $params['page_href'] ) : '';
		$page_id   = isset( $params['page_id'] ) ? (int) $params['page_id'] : 0;

		$likebox = isset( $params['likebox'] ) && is_array( $params['likebox'] )
			? $params['likebox']
			: array();

		if ( '' === $page_href && $page_id > 0 && class_exists( 'ESF_Facebook_Page_Repository', false ) ) {
			$page = ESF_Facebook_Page_Repository::get_instance()->get_by_id( $page_id );
			if ( $page ) {
				$page_href = ESF_Facebook_Likebox_Renderer::page_row_to_href( $page );
			}
		}

		if ( '' === $page_href && ! empty( $likebox['page_url'] ) ) {
			$page_href = ESF_Facebook_Likebox_Renderer::resolve_page_href_from_atts(
				array( 'fanpage_url' => (string) $likebox['page_url'] )
			);
		}

		if ( '' !== $page_href && ! ESF_Facebook_Likebox_Renderer::is_allowed_facebook_page_url( $page_href ) ) {
			$page_href = ESF_Facebook_Likebox_Renderer::resolve_page_href_from_atts(
				array( 'fanpage_url' => $page_href )
			);
		}

		if ( '' === $page_href ) {
			return new WP_Error(
				'likebox_missing_page',
				__( 'Choose a connected Page or enter a public Facebook Page URL.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( 'ESF_Facebook_Likebox_Renderer', false ) ) {
			return new WP_Error(
				'likebox_unavailable',
				__( 'Like Box preview is unavailable.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$html = ( new ESF_Facebook_Likebox_Renderer() )->render_for_page_href( $page_href, $likebox, 0 );
		if ( '' === $html ) {
			return new WP_Error(
				'preview_failed',
				__( 'Could not generate Like Box preview.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'html' => $html,
			)
		);
	}
}
