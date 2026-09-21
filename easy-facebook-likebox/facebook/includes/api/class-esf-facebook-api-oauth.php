<?php
/**
 * Facebook OAuth REST API
 *
 * Connect URL + pick/import for Facebook Pages via easysocialfeed.com Meta bridge.
 * Scopes: pages_show_list,pages_read_engagement,pages_read_user_content,business_management
 * (no instagram_basic).
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/API
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_API_OAuth
 *
 * @since 6.7.9
 */
class ESF_Facebook_API_OAuth {

	/**
	 * OAuth bridge for Facebook Page login (Meta business folder).
	 *
	 * @var string
	 */
	const BRIDGE_URL = 'https://easysocialfeed.com/apps/meta/business/index.php';

	/**
	 * Token refresh bridge.
	 *
	 * @var string
	 */
	const BRIDGE_REFRESH_URL = 'https://easysocialfeed.com/apps/meta/business/refresh.php';

	/**
	 * Default OAuth scopes (no Instagram scopes).
	 *
	 * @var string
	 */
	const DEFAULT_SCOPES = 'pages_show_list,pages_read_engagement,pages_read_user_content,business_management';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/facebook/oauth/url',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_oauth_url' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/facebook/oauth/pending',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_pending' ),
					'permission_callback' => array( __CLASS__, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_pending' ),
					'permission_callback' => array( __CLASS__, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			'esf/v1',
			'/facebook/oauth/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'import_pending' ),
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
			'/facebook/oauth/manual',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'stage_manual_token' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
				'args'                => array(
					'access_token' => array(
						'description' => __( 'Facebook user or Page access token.', 'easy-facebook-likebox' ),
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
	 * Permission callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function permissions_check( $request ) {
		unset( $request );
		return function_exists( 'esf_facebook_user_can_manage' ) && esf_facebook_user_can_manage();
	}

	/**
	 * Build connect URL.
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return WP_REST_Response|array
	 */
	public static function get_oauth_url( $request = null ) {
		unset( $request );

		$nonce      = wp_create_nonce( 'esf_facebook_connect' );
		$return_url = add_query_arg(
			array(
				'page'           => 'esf-facebook',
				'esf_fb_connect' => '1',
				'esf_fb_nonce'   => $nonce,
				'esf_fb_popup'   => '1',
			),
			admin_url( 'admin.php' )
		);

		$state = wp_generate_password( 24, false, false );
		set_transient( 'esf_fb_oauth_state_' . get_current_user_id(), $state, 900 );

		$scopes = apply_filters( 'esf_facebook_oauth_scopes', self::DEFAULT_SCOPES );
		$scopes = is_string( $scopes ) ? $scopes : self::DEFAULT_SCOPES;

		$url = add_query_arg(
			array(
				'redirect' => rawurlencode( $return_url ),
				'type'     => 'facebook',
				'state'    => $state,
				'scope'    => $scopes,
			),
			apply_filters( 'esf_facebook_oauth_bridge_url', self::BRIDGE_URL )
		);

		return rest_ensure_response(
			array(
				'url' => esc_url_raw( $url ),
			)
		);
	}

	/**
	 * GET pending pick session (no tokens).
	 *
	 * @return WP_REST_Response|array
	 */
	public static function get_pending() {
		$payload = ESF_Facebook_Oauth_Service::get_public_pick_payload( get_current_user_id() );
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
	 * DELETE pending pick session.
	 *
	 * @return WP_REST_Response|array
	 */
	public static function delete_pending() {
		ESF_Facebook_Oauth_Service::clear_pick_session( get_current_user_id() );
		return rest_ensure_response( array( 'cleared' => true ) );
	}

	/**
	 * POST import selected pages.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error|array
	 */
	public static function import_pending( $request ) {
		$raw = $request instanceof WP_REST_Request
			? $request->get_param( 'selected_page_ids' )
			: array();
		if ( ! is_array( $raw ) ) {
			return new WP_Error(
				'esf_fb_bad_request',
				__( 'Invalid selection payload.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$page_ids = array();
		foreach ( $raw as $id ) {
			$page_ids[] = sanitize_text_field( (string) $id );
		}

		$result = ESF_Facebook_Oauth_Service::import_selected_pages(
			get_current_user_id(),
			$page_ids
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['imported'] ) && function_exists( 'esf_review_request_record_milestone' ) ) {
			esf_review_request_record_milestone( 'account_connected', 'facebook' );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST pasted access token — validate and stage Page picker session.
	 *
	 * @since 6.7.9
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error|array
	 */
	public static function stage_manual_token( $request ) {
		$token = $request instanceof WP_REST_Request
			? $request->get_param( 'access_token' )
			: '';
		$token = is_string( $token ) ? $token : '';

		$result = ESF_Facebook_Oauth_Service::stage_manual_access_token(
			get_current_user_id(),
			$token
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'staged' => true,
			)
		);
	}
}
