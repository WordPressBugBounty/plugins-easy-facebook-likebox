<?php
/**
 * TikTok Accounts REST API
 *
 * - GET    /esf/v1/tiktok/accounts
 * - DELETE /esf/v1/tiktok/accounts/:id
 * - POST   /esf/v1/tiktok/accounts/:id/refresh
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok/API
 * @since 6.8.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_TikTok_API_Accounts
 *
 * @since 6.8.0
 */
class ESF_TikTok_API_Accounts {

	/**
	 * Register REST routes.
	 *
	 * @since 6.8.0
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/tiktok/accounts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_accounts' ),
				'permission_callback' => array( __CLASS__, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/tiktok/accounts/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_account' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
				'args'                => array(
					'id' => array(
						'description'       => __( 'Account ID.', 'easy-facebook-likebox' ),
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0;
						},
					),
				),
			)
		);

		register_rest_route(
			'esf/v1',
			'/tiktok/accounts/(?P<id>[\d]+)/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'refresh_account' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
				'args'                => array(
					'id' => array(
						'description'       => __( 'Account ID.', 'easy-facebook-likebox' ),
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0;
						},
					),
				),
			)
		);
	}

	/**
	 * Read permission.
	 *
	 * @since 6.8.0
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function read_permissions_check( $request ) {
		if ( $request instanceof WP_REST_Request && 'GET' !== $request->get_method() ) {
			return false;
		}

		return esf_tiktok_user_can_manage();
	}

	/**
	 * Write permission (capability + REST nonce).
	 *
	 * @since 6.8.0
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function write_permissions_check( $request ) {
		if ( ! esf_tiktok_user_can_manage() ) {
			return false;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_cookie_invalid_nonce',
				__( 'Cookie check failed.', 'easy-facebook-likebox' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Map DB row to REST shape (no secrets).
	 *
	 * @since 6.8.0
	 * @param object $account Row.
	 * @return array<string,mixed>
	 */
	private static function format_account( $account ) {
		return array(
			'id'                 => (int) $account->id,
			'account_type'       => isset( $account->account_type ) ? (string) $account->account_type : 'user',
			'open_id'            => isset( $account->open_id ) ? (string) $account->open_id : '',
			'username'           => isset( $account->username ) ? (string) $account->username : '',
			'display_name'       => isset( $account->display_name ) ? (string) $account->display_name : '',
			'avatar_url'         => isset( $account->avatar_url ) ? (string) $account->avatar_url : '',
			'bio_description'    => isset( $account->bio_description ) ? (string) $account->bio_description : '',
			'is_verified'        => ! empty( $account->is_verified ),
			'profile_deep_link'  => isset( $account->profile_deep_link ) ? (string) $account->profile_deep_link : '',
			'follower_count'     => isset( $account->follower_count ) ? (int) $account->follower_count : 0,
			'following_count'    => isset( $account->following_count ) ? (int) $account->following_count : 0,
			'likes_count'        => isset( $account->likes_count ) ? (int) $account->likes_count : 0,
			'video_count'        => isset( $account->video_count ) ? (int) $account->video_count : 0,
			'status'             => isset( $account->status ) ? (string) $account->status : 'active',
			'token_expires_at'   => isset( $account->token_expires_at ) ? (string) $account->token_expires_at : '',
			'needs_reconnect'    => function_exists( 'esf_tiktok_account_needs_reconnect' )
				? esf_tiktok_account_needs_reconnect( $account )
				: false,
			'scopes_granted'     => isset( $account->scopes_granted ) ? (string) $account->scopes_granted : '',
			'stats_refreshed_at' => isset( $account->stats_refreshed_at ) ? (string) $account->stats_refreshed_at : '',
			'created_at'         => isset( $account->created_at ) ? (string) $account->created_at : '',
		);
	}

	/**
	 * GET /tiktok/accounts
	 *
	 * @since 6.8.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_accounts( $request ) {
		unset( $request );

		$repo     = ESF_TikTok_Account_Repository::get_instance();
		$accounts = $repo->get_all();
		$out      = array();

		foreach ( $accounts as $account ) {
			$out[] = self::format_account( $account );
		}

		return rest_ensure_response( $out );
	}

	/**
	 * DELETE /tiktok/accounts/:id
	 *
	 * @since 6.8.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_account( $request ) {
		$account_id = (int) $request['id'];
		$repository = ESF_TikTok_Account_Repository::get_instance();
		$account    = $repository->get_by_id( $account_id );

		if ( ! $account ) {
			return new WP_Error(
				'account_not_found',
				__( 'Account not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$deleted = $repository->delete( $account_id );
		if ( ! $deleted ) {
			return new WP_Error(
				'delete_failed',
				__( 'Could not disconnect the TikTok account.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Account disconnected successfully.', 'easy-facebook-likebox' ),
			)
		);
	}

	/**
	 * POST /tiktok/accounts/:id/refresh
	 *
	 * @since 6.8.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function refresh_account( $request ) {
		$account_id  = (int) $request['id'];
		$repository  = ESF_TikTok_Account_Repository::get_instance();
		$api_service = ESF_TikTok_API_Service::get_instance();
		$account     = $repository->get_by_id( $account_id );

		if ( ! $account ) {
			return new WP_Error(
				'account_not_found',
				__( 'Account not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		if ( empty( $account->refresh_token ) ) {
			return function_exists( 'esf_oauth_reconnect_required_error' )
				? esf_oauth_reconnect_required_error()
				: new WP_Error(
					'no_refresh_token',
					__( 'No refresh token available for this account. Please reconnect.', 'easy-facebook-likebox' ),
					array( 'status' => 400 )
				);
		}

		$token_data = $api_service->refresh_access_token( (string) $account->refresh_token );
		if ( is_wp_error( $token_data ) ) {
			$repository->update_status( $account_id, 'expired' );
			if ( class_exists( 'ESF_TikTok_Token_Notifications' ) ) {
				ESF_TikTok_Token_Notifications::maybe_notify_reconnect_required( $account_id );
			}
			return function_exists( 'esf_oauth_map_refresh_failure' )
				? esf_oauth_map_refresh_failure( $token_data )
				: $token_data;
		}

		$updated = $repository->update_tokens(
			$account_id,
			(string) $token_data['access_token'],
			(string) $token_data['refresh_token'],
			isset( $token_data['expires_in'] ) ? (int) $token_data['expires_in'] : 86400,
			isset( $token_data['refresh_expires_in'] ) ? (int) $token_data['refresh_expires_in'] : 0
		);

		if ( ! $updated ) {
			return new WP_Error(
				'update_tokens_failed',
				__( 'Failed to update account tokens.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		// Best-effort profile refresh with the new access token.
		$user_data = $api_service->validate_token_and_fetch_user( (string) $token_data['access_token'] );
		if ( ! is_wp_error( $user_data ) && ! empty( $user_data['open_id'] ) ) {
			$repository->upsert(
				array(
					'open_id'            => (string) $user_data['open_id'],
					'union_id'           => isset( $user_data['union_id'] ) ? (string) $user_data['union_id'] : null,
					'username'           => isset( $user_data['username'] ) ? (string) $user_data['username'] : '',
					'display_name'       => isset( $user_data['display_name'] ) ? (string) $user_data['display_name'] : '',
					'avatar_url'         => isset( $user_data['avatar_url'] ) ? (string) $user_data['avatar_url'] : null,
					'bio_description'    => isset( $user_data['bio_description'] ) ? (string) $user_data['bio_description'] : null,
					'is_verified'        => ! empty( $user_data['is_verified'] ) ? 1 : 0,
					'profile_deep_link'  => isset( $user_data['profile_deep_link'] ) ? (string) $user_data['profile_deep_link'] : null,
					'follower_count'     => isset( $user_data['follower_count'] ) ? (int) $user_data['follower_count'] : 0,
					'following_count'    => isset( $user_data['following_count'] ) ? (int) $user_data['following_count'] : 0,
					'likes_count'        => isset( $user_data['likes_count'] ) ? (int) $user_data['likes_count'] : 0,
					'video_count'        => isset( $user_data['video_count'] ) ? (int) $user_data['video_count'] : 0,
					'account_data'       => wp_json_encode( isset( $user_data['raw'] ) ? $user_data['raw'] : $user_data ),
					'stats_refreshed_at' => current_time( 'mysql', true ),
					'status'             => 'active',
				)
			);
		}

		$updated_account = $repository->get_by_id( $account_id );

		return rest_ensure_response(
			array(
				'success'    => true,
				'account_id' => $account_id,
				'expires_at' => $updated_account && isset( $updated_account->token_expires_at )
					? (string) $updated_account->token_expires_at
					: '',
				'account'    => $updated_account ? self::format_account( $updated_account ) : null,
			)
		);
	}
}
