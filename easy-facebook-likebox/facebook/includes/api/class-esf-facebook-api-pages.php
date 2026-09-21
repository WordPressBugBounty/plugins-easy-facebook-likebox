<?php
/**
 * Facebook Pages REST API
 *
 * Never exposes page_access_token or facebook_user_token.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/API
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_API_Pages
 *
 * @since 6.7.9
 */
class ESF_Facebook_API_Pages {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/facebook/pages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_pages' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/facebook/pages/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_page' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/facebook/pages/(?P<id>[\d]+)/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'refresh_page' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/facebook/pages/(?P<id>[\d]+)/albums',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_page_albums' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/facebook/pages/(?P<id>[\d]+)/events-token',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_events_token' ),
					'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
					'args'                => array(
						'access_token' => array(
							'description'       => __( 'Facebook Page access token for Events feeds.', 'easy-facebook-likebox' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_events_token' ),
					'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Read permission.
	 *
	 * @return bool
	 */
	public static function permissions_check() {
		return function_exists( 'esf_facebook_user_can_manage' ) && esf_facebook_user_can_manage();
	}

	/**
	 * Write permission with REST nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function write_permissions_check( $request ) {
		if ( ! self::permissions_check() ) {
			return false;
		}
		$nonce = $request instanceof WP_REST_Request ? $request->get_header( 'X-WP-Nonce' ) : '';
		return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * GET /facebook/pages — public fields only.
	 *
	 * @return WP_REST_Response|array
	 */
	public static function get_pages() {
		$pages = ESF_Facebook_Page_Repository::get_instance()->get_all();
		$out   = array();
		foreach ( $pages as $page ) {
			$out[] = ESF_Facebook_Page_Repository::format_public( $page );
		}
		return rest_ensure_response( $out );
	}

	/**
	 * DELETE /facebook/pages/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error|array
	 */
	public static function delete_page( $request ) {
		$id = $request instanceof WP_REST_Request ? (int) $request->get_param( 'id' ) : 0;
		if ( $id <= 0 ) {
			return new WP_Error( 'esf_fb_page_invalid', __( 'Invalid page id.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$page_repo = ESF_Facebook_Page_Repository::get_instance();
		$row       = $page_repo->get_by_id( $id );
		if ( ! $row ) {
			return new WP_Error( 'esf_fb_page_not_found', __( 'Page not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
		}

		$account_id = isset( $row->account_id ) ? (int) $row->account_id : 0;

		$ok = $page_repo->delete_by_id( $id );
		if ( ! $ok ) {
			return new WP_Error( 'esf_fb_page_not_found', __( 'Page not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
		}

		$account_deleted = false;
		if ( $account_id > 0 && class_exists( 'ESF_Facebook_Account_Repository' ) ) {
			// Page delete already attempted empty-account cleanup; confirm for the response.
			$account_deleted = ! ESF_Facebook_Account_Repository::get_instance()->get_by_id( $account_id );
		}

		return rest_ensure_response(
			array(
				'deleted'         => true,
				'id'              => $id,
				'account_id'      => $account_id,
				'account_deleted' => $account_deleted,
			)
		);
	}

	/**
	 * POST /facebook/pages/{id}/refresh — refresh stats/picture (no token in response).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error|array
	 */
	public static function refresh_page( $request ) {
		$id  = $request instanceof WP_REST_Request ? (int) $request->get_param( 'id' ) : 0;
		$row = ESF_Facebook_Page_Repository::get_instance()->get_by_id( $id );
		if ( ! $row ) {
			return new WP_Error( 'esf_fb_page_not_found', __( 'Page not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
		}

		$token = isset( $row->page_access_token ) ? trim( (string) $row->page_access_token ) : '';
		$fb_id = isset( $row->page_id ) ? (string) $row->page_id : '';
		if ( '' === $token || '' === $fb_id ) {
			return new WP_Error( 'esf_fb_page_no_token', __( 'Page token missing. Please reconnect.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$profile = ESF_Facebook_Graph_Client::fetch_page_profile( $fb_id, $token );
		if ( is_wp_error( $profile ) ) {
			if ( function_exists( 'esf_facebook_maybe_mark_account_reconnect_from_graph_error' ) && ! empty( $row->account_id ) ) {
				$account = ESF_Facebook_Account_Repository::get_instance()->get_by_id( (int) $row->account_id );
				esf_facebook_maybe_mark_account_reconnect_from_graph_error( $account, $profile );
			}
			return $profile;
		}

		$patch = array(
			'page_id'            => $fb_id,
			'account_id'         => (int) $row->account_id,
			'stats_refreshed_at' => current_time( 'mysql', true ),
		);
		if ( ! empty( $profile['page_name'] ) ) {
			$patch['page_name'] = $profile['page_name'];
		}
		if ( array_key_exists( 'category', $profile ) ) {
			$patch['category'] = $profile['category'];
		}
		if ( array_key_exists( 'about', $profile ) ) {
			$patch['about'] = $profile['about'];
		}
		if ( array_key_exists( 'fan_count', $profile ) ) {
			$patch['fan_count'] = $profile['fan_count'];
		}
		if ( array_key_exists( 'username', $profile ) ) {
			$patch['username'] = $profile['username'];
		}
		if ( array_key_exists( 'page_link', $profile ) ) {
			$patch['page_link'] = $profile['page_link'];
		}
		if ( array_key_exists( 'website', $profile ) ) {
			$patch['website'] = $profile['website'];
		}
		if ( array_key_exists( 'cover_photo_url', $profile ) ) {
			$patch['cover_photo_url'] = $profile['cover_photo_url'];
		}
		if ( ! empty( $profile['profile_image_url'] ) ) {
			$patch['profile_image_url'] = $profile['profile_image_url'];
		}

		ESF_Facebook_Page_Repository::get_instance()->upsert( $patch );
		$fresh = ESF_Facebook_Page_Repository::get_instance()->get_by_id( $id );

		if ( class_exists( 'ESF_Facebook_Local_Media' ) && $fresh ) {
			ESF_Facebook_Local_Media::localize_page_avatar_from_row( $fresh );
			ESF_Facebook_Local_Media::localize_page_cover_from_row( $fresh );
			$fresh = ESF_Facebook_Page_Repository::get_instance()->get_by_id( $id );
		}

		return rest_ensure_response( ESF_Facebook_Page_Repository::format_public( $fresh ) );
	}

	/**
	 * GET /facebook/pages/{id}/albums — Pro-only album list for a page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error|array
	 */
	public static function get_page_albums( $request ) {
		$id = $request instanceof WP_REST_Request ? (int) $request->get_param( 'id' ) : 0;
		if ( $id <= 0 ) {
			return new WP_Error( 'esf_fb_page_invalid', __( 'Invalid page id.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$row = ESF_Facebook_Page_Repository::get_instance()->get_by_id( $id );
		if ( ! $row ) {
			return new WP_Error( 'esf_fb_page_not_found', __( 'Page not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
		}

		$token = isset( $row->page_access_token ) ? trim( (string) $row->page_access_token ) : '';
		$fb_id = isset( $row->page_id ) ? (string) $row->page_id : '';
		if ( '' === $token || '' === $fb_id ) {
			return new WP_Error( 'esf_fb_page_no_token', __( 'Page token missing. Please reconnect.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$result = ESF_Facebook_Graph_Client::fetch_page_edge(
			$fb_id,
			$token,
			'albums',
			array(
				'fields' => 'id,name,picture.type(small)',
				'limit'  => 200,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$albums = array();
		if ( ! empty( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $item ) {
				$albums[] = array(
					'id'      => isset( $item['id'] ) ? (string) $item['id'] : '',
					'name'    => isset( $item['name'] ) ? (string) $item['name'] : __( 'Album', 'easy-facebook-likebox' ),
					'picture' => isset( $item['picture']['data']['url'] ) ? (string) $item['picture']['data']['url'] : '',
				);
			}
		}

		return rest_ensure_response( $albums );
	}

	/**
	 * POST /facebook/pages/{id}/events-token — store a Page token used only for Events feeds.
	 *
	 * OAuth page tokens continue to power the header and non-events filters.
	 *
	 * @since 6.7.9
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_events_token( $request ) {
		$id = $request instanceof WP_REST_Request ? (int) $request->get_param( 'id' ) : 0;
		if ( $id <= 0 ) {
			return new WP_Error( 'esf_fb_page_invalid', __( 'Invalid page id.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'esf_facebook_save_page_events_token' ) ) {
			return new WP_Error(
				'esf_fb_events_token_unavailable',
				__( 'Events token setup is not available.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$token  = (string) $request->get_param( 'access_token' );
		$result = esf_facebook_save_page_events_token( $id, $token );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$fresh = ESF_Facebook_Page_Repository::get_instance()->get_by_id( $id );
		return rest_ensure_response(
			array(
				'success'          => true,
				'page'             => ESF_Facebook_Page_Repository::format_public( $fresh ),
				'has_events_token' => esf_facebook_page_has_events_token( $fresh ),
			)
		);
	}

	/**
	 * DELETE /facebook/pages/{id}/events-token — remove the dedicated Events token.
	 *
	 * @since 6.7.9
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_events_token( $request ) {
		$id = $request instanceof WP_REST_Request ? (int) $request->get_param( 'id' ) : 0;
		if ( $id <= 0 ) {
			return new WP_Error( 'esf_fb_page_invalid', __( 'Invalid page id.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$row = ESF_Facebook_Page_Repository::get_instance()->get_by_id( $id );
		if ( ! $row ) {
			return new WP_Error( 'esf_fb_page_not_found', __( 'Page not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
		}

		$page_repo = ESF_Facebook_Page_Repository::get_instance();
		$page_repo->upsert(
			array(
				'account_id'                 => (int) $row->account_id,
				'page_id'                    => (string) $row->page_id,
				'events_access_token'        => null,
				'events_token_set_at'        => null,
				'events_token_expires_at'    => null,
				'events_token_never_expires' => 0,
			)
		);

		if ( class_exists( 'ESF_Facebook_Cache' ) ) {
			ESF_Facebook_Cache::flush_page_cache( $id );
		}

		$fresh = $page_repo->get_by_id( $id );
		return rest_ensure_response(
			array(
				'deleted'          => true,
				'page'             => ESF_Facebook_Page_Repository::format_public( $fresh ),
				'has_events_token' => esf_facebook_page_has_events_token( $fresh ),
			)
		);
	}
}
