<?php
/**
 * Instagram OAuth REST API
 *
 * Supports connect URL generation for:
 * - Instagram API with Instagram Login (`type=instagram_login`, Business Login on graph.instagram.com)
 * - Instagram Business/Creator via Facebook (`type=facebook_page`, Page-backed Graph)
 *
 * Legacy query aliases `basic` and `business` are normalized to the canonical values above.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/API
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_API_OAuth
 *
 * @since 6.8.0
 */
class ESF_Instagram_API_OAuth {

	/**
	 * OAuth bridge for Instagram API with Instagram Login (Business Login).
	 *
	 * @var string
	 */
	const BRIDGE_URL_BASIC = 'https://easysocialfeed.com/apps/meta/basic/index.php';

	/**
	 * OAuth bridge for Instagram via Facebook Login / Page-backed accounts.
	 *
	 * @var string
	 */
	const BRIDGE_URL_BUSINESS = 'https://easysocialfeed.com/apps/meta/business/index.php';

	/**
	 * Token refresh bridge for Instagram Login (same Meta folder as {@see self::BRIDGE_URL_BASIC}).
	 *
	 * @var string
	 */
	const BRIDGE_REFRESH_URL_BASIC = 'https://easysocialfeed.com/apps/meta/basic/refresh.php';

	/**
	 * Token refresh bridge for Facebook Page–backed accounts (same folder as {@see self::BRIDGE_URL_BUSINESS}).
	 *
	 * @var string
	 */
	const BRIDGE_REFRESH_URL_BUSINESS = 'https://easysocialfeed.com/apps/meta/business/refresh.php';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/instagram/oauth/url',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_oauth_url' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => array(
					'type' => array(
						'description'       => __( 'Auth flow: instagram_login (Instagram Login) or facebook_page (Facebook Page). Legacy: basic, business.', 'easy-facebook-likebox' ),
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'esf/v1',
			'/instagram/oauth/facebook-pending',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_facebook_pending' ),
					'permission_callback' => array( __CLASS__, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_facebook_pending' ),
					'permission_callback' => array( __CLASS__, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			'esf/v1',
			'/instagram/oauth/facebook-import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'import_facebook_pending' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => array(
					'selected_page_ids' => array(
						'description' => __( 'Facebook Page ids to import from the pending session.', 'easy-facebook-likebox' ),
						'type'        => 'array',
						'required'    => true,
						'items'       => array(
							'type' => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			'esf/v1',
			'/instagram/oauth/manual',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'stage_manual_token' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
				'args'                => array(
					'access_token' => array(
						'description' => __( 'Instagram user or Facebook user access token.', 'easy-facebook-likebox' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Permission callback for write routes (nonce required).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function write_permissions_check( $request ) {
		if ( ! self::permissions_check( $request ) ) {
			return false;
		}
		$nonce = $request instanceof WP_REST_Request ? $request->get_header( 'X-WP-Nonce' ) : '';
		return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * POST /esf/v1/instagram/oauth/manual — validate pasted token and connect or stage pick session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function stage_manual_token( WP_REST_Request $request ) {
		$token = $request->get_param( 'access_token' );
		if ( ! is_string( $token ) || '' === trim( $token ) ) {
			return new WP_Error(
				'esf_ig_invalid_token',
				__( 'Enter a valid Instagram or Facebook access token.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( 'ESF_Instagram_Manual_Oauth_Service' ) ) {
			return new WP_Error(
				'esf_ig_manual_unavailable',
				__( 'Manual token setup is not available.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$result = ESF_Instagram_Manual_Oauth_Service::submit_manual_access_token(
			get_current_user_id(),
			$token
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Permission callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function permissions_check( $request ) {
		unset( $request );
		return esf_instagram_user_can_manage();
	}

	/**
	 * Build connect URL for selected auth flow.
	 *
	 * Uses {@see self::BRIDGE_URL_BASIC} when the flow is `instagram_login`, otherwise
	 * {@see self::BRIDGE_URL_BUSINESS}. Override the base URL with the
	 * `esf_instagram_oauth_bridge_url` filter (second argument: canonical flow string).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_oauth_url( $request ) {
		$raw  = $request instanceof WP_REST_Request
			? (string) $request->get_param( 'type' )
			: '';
		$type = esf_instagram_normalize_auth_flow_type( $raw );

		$nonce      = wp_create_nonce( 'esf_instagram_connect' );
		$return_url = add_query_arg(
			array(
				'page'           => 'esf-instagram',
				'esf_ig_connect' => '1',
				'esf_ig_nonce'   => $nonce,
				'esf_ig_popup'   => '1',
				'esf_ig_type'    => $type,
			),
			admin_url( 'admin.php' )
		);

		$state = wp_generate_password( 24, false, false );
		set_transient( 'esf_ig_oauth_state_' . get_current_user_id(), $state, 900 );

		$bridge_base = ( 'instagram_login' === $type )
			? self::BRIDGE_URL_BASIC
			: self::BRIDGE_URL_BUSINESS;

		$url = add_query_arg(
			array(
				'redirect' => rawurlencode( $return_url ),
				'type'     => $type,
				'state'    => $state,
			),
			apply_filters( 'esf_instagram_oauth_bridge_url', $bridge_base, $type )
		);

		return rest_ensure_response(
			array(
				'url'  => esc_url_raw( $url ),
				'type' => $type,
			)
		);
	}

	/**
	 * GET /esf/v1/instagram/oauth/facebook-pending — public fields for staged Facebook login (no tokens).
	 *
	 * @return WP_REST_Response
	 */
	public static function get_facebook_pending() {
		$payload = ESF_Instagram_Facebook_Oauth_Service::get_public_pick_payload( get_current_user_id() );
		if ( null === $payload ) {
			return rest_ensure_response(
				array(
					'candidates' => array(),
				)
			);
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * DELETE /esf/v1/instagram/oauth/facebook-pending — discard staged session.
	 *
	 * @return WP_REST_Response
	 */
	public static function delete_facebook_pending() {
		ESF_Instagram_Facebook_Oauth_Service::clear_pick_session( get_current_user_id() );
		return rest_ensure_response( array( 'cleared' => true ) );
	}

	/**
	 * POST /esf/v1/instagram/oauth/facebook-import — persist selected Page-backed Instagram accounts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function import_facebook_pending( WP_REST_Request $request ) {
		$raw = $request->get_param( 'selected_page_ids' );
		if ( ! is_array( $raw ) ) {
			return new WP_Error(
				'esf_ig_fb_bad_request',
				__( 'Invalid selection payload.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$page_ids = array();
		foreach ( $raw as $id ) {
			$page_ids[] = sanitize_text_field( (string) $id );
		}

		$result = ESF_Instagram_Facebook_Oauth_Service::import_selected_pages(
			get_current_user_id(),
			$page_ids
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['imported'] ) && function_exists( 'esf_review_request_record_milestone' ) ) {
			esf_review_request_record_milestone( 'account_connected', 'instagram' );
		}

		return rest_ensure_response( $result );
	}
}
