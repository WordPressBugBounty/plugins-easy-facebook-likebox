<?php
/**
 * Facebook OAuth staging and page import.
 *
 * After the Meta bridge returns a user access token, candidates are stored in a
 * short-lived transient so the dashboard can pick which Pages to import.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Oauth_Service
 *
 * @since 6.7.9
 */
class ESF_Facebook_Oauth_Service {

	/**
	 * Transient key prefix (per WordPress user id).
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'esf_fb_pick_v1_';

	/**
	 * Pick session TTL (seconds).
	 *
	 * @var int
	 */
	const PICK_SESSION_TTL = 900;

	/**
	 * Transient option key for a user.
	 *
	 * @since 6.7.9
	 * @param int $user_id WordPress user id.
	 * @return string
	 */
	public static function transient_key( $user_id ) {
		return self::TRANSIENT_PREFIX . (int) $user_id;
	}

	/**
	 * Discover Pages then persist a pick session.
	 *
	 * @since 6.7.9
	 * @param int    $user_id           WordPress user id.
	 * @param string $user_access_token Facebook user token.
	 * @param int    $token_expires_in  Seconds until expiry.
	 * @param string $auth_source       `oauth` or `manual`.
	 * @return true|WP_Error
	 */
	public static function stage_pick_session( $user_id, $user_access_token, $token_expires_in = 0, $auth_source = 'oauth' ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return new WP_Error( 'esf_fb_invalid_user', __( 'Invalid user.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$token_expires_in = (int) $token_expires_in;
		$auth_source_norm = class_exists( 'ESF_Manual_Access_Token' )
			? ESF_Manual_Access_Token::normalize_auth_source( $auth_source )
			: 'oauth';

		if ( class_exists( 'ESF_Manual_Access_Token' ) && ESF_Manual_Access_Token::AUTH_MANUAL === $auth_source_norm ) {
			$token_expires_in = 0;
		} elseif ( $token_expires_in <= 0 ) {
			$fallback = (int) apply_filters( 'esf_facebook_user_token_default_expires_in', 5184000, $user_id, $user_access_token );
			if ( $fallback > 0 ) {
				$token_expires_in = $fallback;
			}
		}

		$fb_user = ESF_Facebook_Graph_Client::fetch_facebook_user( $user_access_token );
		if ( is_wp_error( $fb_user ) ) {
			return $fb_user;
		}
		$fb_user_id   = $fb_user['id'];
		$fb_user_name = isset( $fb_user['name'] ) ? (string) $fb_user['name'] : '';

		if ( function_exists( 'esf_facebook_assert_account_import_allowed' ) ) {
			$allowed = esf_facebook_assert_account_import_allowed( $fb_user_id );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		$candidates = ESF_Facebook_Graph_Client::fetch_all_page_candidates( $user_access_token );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}
		if ( array() === $candidates ) {
			return new WP_Error(
				'esf_fb_no_pages',
				__( 'No Facebook Pages were found for this login.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$expires_at = null;
		$ttl        = self::PICK_SESSION_TTL;
		if ( $token_expires_in > 0 ) {
			$expires_at = gmdate( 'Y-m-d H:i:s', time() + (int) $token_expires_in );
			$ttl        = min( self::PICK_SESSION_TTL, max( 60, (int) $token_expires_in ) );
		}

		$session = array(
			'facebook_user_id'    => $fb_user_id,
			'facebook_user_name'  => $fb_user_name,
			'facebook_user_token' => sanitize_text_field( (string) $user_access_token ),
			'user_token_expires'  => $expires_at,
			'auth_source'         => $auth_source_norm,
			'candidates'          => function_exists( 'esf_db_safe_text' ) ? esf_db_safe_text( $candidates ) : $candidates,
			'staged_at'           => time(),
		);

		set_transient( self::transient_key( $user_id ), $session, $ttl );

		return true;
	}

	/**
	 * Validate a pasted access token and stage a pick session (manual connect).
	 *
	 * Accepts a Facebook user token (`me/accounts`) or a single Page access token.
	 *
	 * @since 6.7.9
	 * @param int    $user_id      WordPress user id.
	 * @param string $access_token Raw pasted token.
	 * @return true|WP_Error
	 */
	public static function stage_manual_access_token( $user_id, $access_token ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return new WP_Error( 'esf_fb_invalid_user', __( 'Invalid user.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		if ( ! class_exists( 'ESF_Manual_Access_Token' ) ) {
			return new WP_Error(
				'esf_fb_manual_unavailable',
				__( 'Manual token setup is not available.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$access_token = ESF_Manual_Access_Token::sanitize( $access_token );
		if ( ! ESF_Manual_Access_Token::is_valid_format( $access_token ) ) {
			return new WP_Error(
				'esf_fb_invalid_token',
				__( 'Enter a valid Facebook access token.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$candidates = ESF_Facebook_Graph_Client::fetch_all_page_candidates( $access_token );
		if ( ! is_wp_error( $candidates ) && ! empty( $candidates ) ) {
			return self::stage_pick_session(
				$user_id,
				$access_token,
				0,
				ESF_Manual_Access_Token::AUTH_MANUAL
			);
		}

		$me = ESF_Facebook_Graph_Client::fetch_facebook_user( $access_token );
		if ( is_wp_error( $me ) ) {
			if ( is_wp_error( $candidates ) ) {
				return $candidates;
			}
			return new WP_Error(
				'esf_fb_invalid_token',
				__( 'This access token could not be validated with Facebook.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$page_id = isset( $me['id'] ) ? sanitize_text_field( (string) $me['id'] ) : '';
		if ( '' === $page_id ) {
			return new WP_Error(
				'esf_fb_invalid_token',
				__( 'Facebook did not return an id for this token.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$profile = ESF_Facebook_Graph_Client::fetch_page_profile( $page_id, $access_token );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$page_name = isset( $profile['page_name'] ) && '' !== (string) $profile['page_name']
			? (string) $profile['page_name']
			: ( isset( $me['name'] ) ? (string) $me['name'] : '' );

		$candidate = array(
			'page_id'           => $page_id,
			'page_name'         => $page_name,
			'page_access_token' => $access_token,
			'username'          => isset( $profile['username'] ) ? (string) $profile['username'] : '',
			'page_link'         => isset( $profile['page_link'] ) ? (string) $profile['page_link'] : '',
			'category'          => isset( $profile['category'] ) ? (string) $profile['category'] : '',
			'about'             => isset( $profile['about'] ) ? (string) $profile['about'] : '',
			'fan_count'         => isset( $profile['fan_count'] ) ? (int) $profile['fan_count'] : 0,
			'website'           => isset( $profile['website'] ) ? (string) $profile['website'] : '',
			'cover_photo_url'   => isset( $profile['cover_photo_url'] ) ? (string) $profile['cover_photo_url'] : '',
			'profile_image_url' => isset( $profile['profile_image_url'] ) ? (string) $profile['profile_image_url'] : '',
		);

		$manual_fb_uid = 'manual:' . $page_id;
		if ( function_exists( 'esf_facebook_assert_account_import_allowed' ) ) {
			$allowed = esf_facebook_assert_account_import_allowed( $manual_fb_uid );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		$session = array(
			'facebook_user_id'    => $manual_fb_uid,
			'facebook_user_name'  => $page_name,
			'facebook_user_token' => $access_token,
			'user_token_expires'  => null,
			'auth_source'         => ESF_Manual_Access_Token::AUTH_MANUAL,
			'candidates'          => function_exists( 'esf_db_safe_text' )
				? esf_db_safe_text( array( $candidate ) )
				: array( $candidate ),
			'staged_at'           => time(),
		);

		set_transient( self::transient_key( $user_id ), $session, self::PICK_SESSION_TTL );

		return true;
	}

	/**
	 * Public pick payload (no tokens).
	 *
	 * @since 6.7.9
	 * @param int $user_id WordPress user id.
	 * @return array<string,mixed>|null
	 */
	public static function get_public_pick_payload( $user_id ) {
		$session = get_transient( self::transient_key( (int) $user_id ) );
		if ( ! is_array( $session ) || empty( $session['candidates'] ) || ! is_array( $session['candidates'] ) ) {
			return null;
		}

		$public = array();
		foreach ( $session['candidates'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$public[] = array(
				'page_id'           => isset( $row['page_id'] ) ? (string) $row['page_id'] : '',
				'page_name'         => isset( $row['page_name'] ) ? (string) $row['page_name'] : '',
				'username'          => isset( $row['username'] ) ? (string) $row['username'] : '',
				'page_link'         => isset( $row['page_link'] ) ? (string) $row['page_link'] : '',
				'category'          => isset( $row['category'] ) ? (string) $row['category'] : '',
				'about'             => isset( $row['about'] ) ? (string) $row['about'] : '',
				'fan_count'         => isset( $row['fan_count'] ) ? (int) $row['fan_count'] : 0,
				'website'           => isset( $row['website'] ) ? (string) $row['website'] : '',
				'cover_photo_url'   => isset( $row['cover_photo_url'] ) ? (string) $row['cover_photo_url'] : '',
				'profile_image_url' => isset( $row['profile_image_url'] ) ? (string) $row['profile_image_url'] : '',
			);
		}

		$auth_source = 'oauth';
		if ( isset( $session['auth_source'] ) && class_exists( 'ESF_Manual_Access_Token' ) ) {
			$auth_source = ESF_Manual_Access_Token::normalize_auth_source( $session['auth_source'] );
		}

		return array(
			'auth_source' => $auth_source,
			'candidates'  => $public,
		);
	}

	/**
	 * Clear staged session.
	 *
	 * @since 6.7.9
	 * @param int $user_id WordPress user id.
	 * @return void
	 */
	public static function clear_pick_session( $user_id ) {
		delete_transient( self::transient_key( (int) $user_id ) );
	}

	/**
	 * Import selected Pages and clear the session.
	 *
	 * @since 6.7.9
	 * @param int               $user_id           WordPress user id.
	 * @param array<int,string> $selected_page_ids Facebook Page ids.
	 * @return array{imported:int,account_id:int,page_ids:int[]}|WP_Error
	 */
	public static function import_selected_pages( $user_id, array $selected_page_ids ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return new WP_Error( 'esf_fb_invalid_user', __( 'Invalid user.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$session = get_transient( self::transient_key( $user_id ) );
		if ( ! is_array( $session ) || empty( $session['candidates'] ) || ! is_array( $session['candidates'] ) ) {
			return new WP_Error(
				'esf_fb_no_session',
				__( 'No pending Facebook connection. Please start connect again.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$user_token = isset( $session['facebook_user_token'] ) ? trim( (string) $session['facebook_user_token'] ) : '';
		$fb_uid     = isset( $session['facebook_user_id'] ) ? sanitize_text_field( (string) $session['facebook_user_id'] ) : '';
		$fb_uname   = isset( $session['facebook_user_name'] ) ? sanitize_text_field( (string) $session['facebook_user_name'] ) : '';
		if ( '' === $user_token || '' === $fb_uid ) {
			return new WP_Error(
				'esf_fb_session_corrupt',
				__( 'Pending session is invalid. Please connect again.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		if ( function_exists( 'esf_facebook_assert_account_import_allowed' ) ) {
			$allowed = esf_facebook_assert_account_import_allowed( $fb_uid );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		$account_repo = ESF_Facebook_Account_Repository::get_instance();

		$want = array();
		foreach ( $selected_page_ids as $pid ) {
			$pid = sanitize_text_field( (string) $pid );
			if ( '' !== $pid ) {
				$want[ $pid ] = true;
			}
		}
		if ( empty( $want ) ) {
			return new WP_Error(
				'esf_fb_no_selection',
				__( 'Select at least one Page to import.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$by_id = array();
		foreach ( $session['candidates'] as $row ) {
			if ( ! is_array( $row ) || empty( $row['page_id'] ) ) {
				continue;
			}
			$by_id[ (string) $row['page_id'] ] = $row;
		}

		$account_id = $account_repo->upsert(
			array(
				'user_id'             => $user_id,
				'facebook_user_id'    => $fb_uid,
				'facebook_user_name'  => $fb_uname,
				'facebook_user_token' => $user_token,
				'token_expires_at'    => isset( $session['user_token_expires'] ) ? $session['user_token_expires'] : null,
				'auth_source'         => isset( $session['auth_source'] ) ? $session['auth_source'] : 'oauth',
				'status'              => 'active',
			)
		);

		if ( false === $account_id || $account_id <= 0 ) {
			return new WP_Error(
				'esf_fb_account_save_failed',
				__( 'Could not save the Facebook account.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$imported  = 0;
		$page_ids  = array();
		$page_repo = ESF_Facebook_Page_Repository::get_instance();

		foreach ( array_keys( $want ) as $pid ) {
			if ( ! isset( $by_id[ $pid ] ) ) {
				continue;
			}
			$row = $by_id[ $pid ];
			$id  = $page_repo->upsert(
				array(
					'account_id'        => (int) $account_id,
					'page_id'           => $pid,
					'page_name'         => isset( $row['page_name'] ) ? (string) $row['page_name'] : '',
					'username'          => isset( $row['username'] ) ? (string) $row['username'] : '',
					'page_link'         => isset( $row['page_link'] ) ? (string) $row['page_link'] : '',
					'category'          => isset( $row['category'] ) ? (string) $row['category'] : '',
					'about'             => isset( $row['about'] ) ? (string) $row['about'] : '',
					'fan_count'         => isset( $row['fan_count'] ) ? (int) $row['fan_count'] : 0,
					'website'           => isset( $row['website'] ) ? (string) $row['website'] : '',
					'cover_photo_url'   => isset( $row['cover_photo_url'] ) ? (string) $row['cover_photo_url'] : '',
					'page_access_token' => isset( $row['page_access_token'] ) ? (string) $row['page_access_token'] : '',
					'profile_image_url' => isset( $row['profile_image_url'] ) ? (string) $row['profile_image_url'] : '',
					'status'            => 'active',
				)
			);
			if ( false !== $id && $id > 0 ) {
				++$imported;
				$page_ids[] = (int) $id;
			}
		}

		self::clear_pick_session( $user_id );

		if ( 0 === $imported ) {
			return new WP_Error(
				'esf_fb_import_empty',
				__( 'None of the selected Pages could be imported.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'imported'   => $imported,
			'account_id' => (int) $account_id,
			'page_ids'   => $page_ids,
		);
	}
}
