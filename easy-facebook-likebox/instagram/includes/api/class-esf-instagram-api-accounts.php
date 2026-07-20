<?php
/**
 * Instagram Accounts REST API
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/API
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_API_Accounts
 *
 * @since 6.8.0
 */
class ESF_Instagram_API_Accounts {

	/**
	 * Register account routes.
	 *
	 * @since 6.8.0
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/instagram/accounts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_accounts' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => array(
					'fields' => array(
						'description'       => __( 'Comma-separated list of account fields to return.', 'easy-facebook-likebox' ),
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'esf/v1',
			'/instagram/accounts/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_account' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/instagram/accounts/(?P<id>[\d]+)/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'refresh_account' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);
	}

	/**
	 * Permission check callback.
	 *
	 * @since 6.8.0
	 * @return bool
	 */
	public static function permissions_check() {
		return esf_instagram_user_can_manage();
	}

	/**
	 * Map DB row to REST shape (no secrets).
	 *
	 * @param object $account Row from repository.
	 * @return array<string,mixed>
	 */
	private static function format_account( $account ) {
		$profile_image_url = (string) $account->profile_image_url;
		if ( class_exists( 'ESF_Instagram_Local_Media' ) ) {
			$resolved = ESF_Instagram_Local_Media::resolve_avatar_display_url( $account );
			if ( '' !== $resolved ) {
				$profile_image_url = $resolved;
			}
		}

		return array(
			'id'                  => (int) $account->id,
			'account_type'        => (string) $account->account_type,
			'auth_source'         => esf_instagram_auth_source_for_account_row( $account ),
			'instagram_user_id'   => (string) $account->instagram_user_id,
			'username'            => (string) $account->username,
			'display_name'        => (string) $account->display_name,
			'profile_image_url'   => $profile_image_url,
			'biography'           => (string) $account->biography,
			'website'             => (string) $account->website,
			'followers_count'     => (int) $account->followers_count,
			'media_count'         => (int) $account->media_count,
			'facebook_page_id'    => isset( $account->facebook_page_id ) ? (string) $account->facebook_page_id : '',
			'facebook_page_name'  => isset( $account->facebook_page_name ) ? (string) $account->facebook_page_name : '',
			'status'              => (string) $account->status,
			'token_expires_at'    => isset( $account->token_expires_at ) ? (string) $account->token_expires_at : '',
			'needs_reconnect'     => esf_instagram_account_needs_reconnect( $account ),
			'stats_refreshed_at'  => isset( $account->stats_refreshed_at ) ? (string) $account->stats_refreshed_at : '',
			'created_at'          => (string) $account->created_at,
		);
	}

	/**
	 * Keys allowed on GET /instagram/accounts responses (no tokens or secrets).
	 *
	 * @since 6.8.0
	 * @return array<int,string>
	 */
	private static function get_allowed_account_response_keys() {
		return array(
			'id',
			'account_type',
			'auth_source',
			'instagram_user_id',
			'username',
			'display_name',
			'profile_image_url',
			'biography',
			'website',
			'followers_count',
			'media_count',
			'facebook_page_id',
			'facebook_page_name',
			'status',
			'token_expires_at',
			'needs_reconnect',
			'stats_refreshed_at',
			'created_at',
		);
	}

	/**
	 * Default field list when `fields` is omitted (Instagram React accounts table only).
	 *
	 * @since 6.8.0
	 * @return array<int,string>
	 */
	private static function get_default_account_response_keys() {
		return array(
			'id',
			'account_type',
			'auth_source',
			'instagram_user_id',
			'username',
			'display_name',
			'profile_image_url',
			'biography',
			'website',
			'followers_count',
			'media_count',
			'status',
			'token_expires_at',
			'needs_reconnect',
		);
	}

	/**
	 * Filter formatted account to requested keys (matches X/Twitter `fields` behavior).
	 *
	 * @since 6.8.0
	 * @param array<string,mixed> $formatted Full row from format_account().
	 * @param array<int,string>   $keys      Allowed keys to keep.
	 * @return array<string,mixed>
	 */
	private static function filter_account_keys( array $formatted, array $keys ) {
		$flip = array_flip( $keys );
		return array_intersect_key( $formatted, $flip );
	}

	/**
	 * Return all Instagram accounts (site-wide for users who can manage the module).
	 *
	 * @since 6.8.0
	 * @param WP_REST_Request $request Request (optional `fields` query arg).
	 * @return WP_REST_Response
	 */
	public static function get_accounts( WP_REST_Request $request ) {
		$allowed = self::get_allowed_account_response_keys();
		$default = self::get_default_account_response_keys();

		$requested = $request->get_param( 'fields' );
		if ( ! empty( $requested ) ) {
			$keys = array_intersect(
				array_map( 'trim', explode( ',', (string) $requested ) ),
				$allowed
			);
		} else {
			$keys = $default;
		}

		if ( ! in_array( 'id', $keys, true ) ) {
			array_unshift( $keys, 'id' );
		}

		$accounts = ESF_Instagram_Account_Repository::get_instance()->get_all();
		$data     = array();

		foreach ( $accounts as $account ) {
			$formatted = self::format_account( $account );
			$data[]    = self::filter_account_keys( $formatted, $keys );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * DELETE /esf/v1/instagram/accounts/:id
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_account( WP_REST_Request $request ) {
		$account_id = (int) $request['id'];
		$repo       = ESF_Instagram_Account_Repository::get_instance();
		$row        = $repo->get_by_id( $account_id );

		if ( ! $row ) {
			return new WP_Error(
				'not_found',
				__( 'Account not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $repo->delete_by_id( $account_id ) ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete account.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /esf/v1/instagram/accounts/:id/refresh — refetch profile/stats from Instagram APIs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function refresh_account( WP_REST_Request $request ) {
		$account_id = (int) $request['id'];

		$result = ESF_Instagram_Migrator::get_instance()->refresh_account_by_id( $account_id, null );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$repo  = ESF_Instagram_Account_Repository::get_instance();
		$fresh = $repo->get_by_id( $account_id );
		if ( ! $fresh ) {
			return new WP_Error(
				'not_found',
				__( 'Account not found after refresh.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success'    => true,
				'expires_at' => (string) $fresh->token_expires_at,
				'account'    => self::format_account( $fresh ),
			)
		);
	}
}
