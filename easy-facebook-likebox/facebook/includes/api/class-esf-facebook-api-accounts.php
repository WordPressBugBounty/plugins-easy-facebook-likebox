<?php
/**
 * Facebook Accounts REST API
 *
 * Manages Meta business accounts (esf_facebook_accounts).
 * Never exposes facebook_user_token.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/API
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_API_Accounts
 *
 * @since 6.7.9
 */
class ESF_Facebook_API_Accounts {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/facebook/accounts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_accounts' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/facebook/accounts/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_account' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/facebook/accounts/(?P<id>[\d]+)/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'refresh_account' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
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
	 * GET /facebook/accounts — public fields only.
	 *
	 * @return WP_REST_Response|array
	 */
	public static function get_accounts() {
		$accounts = ESF_Facebook_Account_Repository::get_instance()->get_all();
		$out      = array();

		foreach ( $accounts as $account ) {
			$out[] = self::format_public_account( $account );
		}

		return rest_ensure_response( $out );
	}

	/**
	 * DELETE /facebook/accounts/{id}
	 *
	 * Cascade deletes all pages belonging to this account.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error|array
	 */
	public static function delete_account( $request ) {
		$id = $request instanceof WP_REST_Request ? (int) $request->get_param( 'id' ) : 0;
		if ( $id <= 0 ) {
			return new WP_Error( 'esf_fb_account_invalid', __( 'Invalid account id.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$account_repo = ESF_Facebook_Account_Repository::get_instance();

		$account = $account_repo->get_by_id( $id );
		if ( ! $account ) {
			return new WP_Error( 'esf_fb_account_not_found', __( 'Account not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
		}

		// Cascade Pages + soft-invalidate feeds; suppresses per-Page emails / empty-account cleanup.
		$ok = $account_repo->delete_by_id( $id );
		if ( ! $ok ) {
			return new WP_Error( 'esf_fb_account_delete_failed', __( 'Failed to delete account.', 'easy-facebook-likebox' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * POST /facebook/accounts/{id}/refresh — refresh token and linked pages.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error|array
	 */
	public static function refresh_account( $request ) {
		$id      = $request instanceof WP_REST_Request ? (int) $request->get_param( 'id' ) : 0;
		$account = ESF_Facebook_Account_Repository::get_instance()->get_by_id( $id );
		if ( ! $account ) {
			return new WP_Error( 'esf_fb_account_not_found', __( 'Account not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
		}

		$account_repo = ESF_Facebook_Account_Repository::get_instance();

		if ( class_exists( 'ESF_Facebook_Token_Refresh_Client' ) && function_exists( 'esf_facebook_account_supports_token_refresh' )
			&& esf_facebook_account_supports_token_refresh( $account ) ) {
			$patch = ESF_Facebook_Token_Refresh_Client::refresh_tokens( $account );
			if ( is_wp_error( $patch ) ) {
				return $patch;
			}
			if ( is_array( $patch ) ) {
				$patch['facebook_user_id'] = isset( $account->facebook_user_id )
					? (string) $account->facebook_user_id
					: '';
				$patch['status']           = 'active';
				$account_repo->upsert( $patch );
			}
		}

		$user_token = isset( $account->facebook_user_token ) ? trim( (string) $account->facebook_user_token ) : '';
		if ( '' !== $user_token && class_exists( 'ESF_Facebook_Graph_Client' ) ) {
			$user_profile = ESF_Facebook_Graph_Client::fetch_facebook_user( $user_token );
			if ( ! is_wp_error( $user_profile ) && ! empty( $user_profile['name'] ) ) {
				$account_repo->upsert(
					array(
						'facebook_user_id'   => isset( $account->facebook_user_id )
							? (string) $account->facebook_user_id
							: $user_profile['id'],
						'facebook_user_name' => $user_profile['name'],
					)
				);
			}
		}

		$page_repo = ESF_Facebook_Page_Repository::get_instance();
		$pages     = $page_repo->get_by_account_id( $id );
		foreach ( $pages as $page ) {
			if ( ! empty( $page->page_access_token ) && ! empty( $page->page_id ) ) {
				$profile = ESF_Facebook_Graph_Client::fetch_page_profile( $page->page_id, $page->page_access_token );
				if ( ! is_wp_error( $profile ) ) {
					$patch = array(
						'page_id'            => $page->page_id,
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
					$page_repo->upsert( $patch );

					if ( class_exists( 'ESF_Facebook_Local_Media' ) ) {
						$fresh_page = $page_repo->get_by_page_id( $page->page_id );
						if ( $fresh_page ) {
							ESF_Facebook_Local_Media::localize_page_avatar_from_row( $fresh_page );
							ESF_Facebook_Local_Media::localize_page_cover_from_row( $fresh_page );
						}
					}
				}
			}
		}

		$fresh = $account_repo->get_by_id( $id );
		return rest_ensure_response( self::format_public_account( $fresh ) );
	}

	/**
	 * Format account for public API response (no tokens).
	 *
	 * @param object $account Account row.
	 * @return array<string,mixed>
	 */
	private static function format_public_account( $account ) {
		if ( ! is_object( $account ) ) {
			return array();
		}

		$page_repo  = ESF_Facebook_Page_Repository::get_instance();
		$pages      = $page_repo->get_by_account_id( (int) $account->id );
		$page_count = count( $pages );

		$status           = isset( $account->status ) ? (string) $account->status : 'active';
		$token_expires_at = isset( $account->token_expires_at ) ? (string) $account->token_expires_at : '';
		$needs_reconnect  = in_array( $status, array( 'expired', 'invalid' ), true );

		if ( ! $needs_reconnect && '' !== $token_expires_at
			&& ( ! function_exists( 'esf_facebook_account_supports_token_refresh' )
				|| esf_facebook_account_supports_token_refresh( $account ) ) ) {
			$expiry_ts = strtotime( $token_expires_at );
			if ( false !== $expiry_ts && $expiry_ts < time() ) {
				$needs_reconnect = true;
			}
		}

		return array(
			'id'                 => isset( $account->id ) ? (int) $account->id : 0,
			'user_id'            => isset( $account->user_id ) ? (int) $account->user_id : 0,
			'facebook_user_id'   => isset( $account->facebook_user_id ) ? (string) $account->facebook_user_id : '',
			'facebook_user_name' => isset( $account->facebook_user_name ) ? (string) $account->facebook_user_name : '',
			'auth_source'        => function_exists( 'esf_facebook_auth_source_for_account_row' )
				? esf_facebook_auth_source_for_account_row( $account )
				: 'oauth',
			'status'             => $status,
			'token_expires_at'   => $token_expires_at,
			'needs_reconnect'    => $needs_reconnect,
			'page_count'         => $page_count,
			'created_at'         => isset( $account->created_at ) ? (string) $account->created_at : '',
		);
	}
}
