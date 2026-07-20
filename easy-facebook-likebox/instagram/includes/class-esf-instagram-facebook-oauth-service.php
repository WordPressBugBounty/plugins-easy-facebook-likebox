<?php
/**
 * Instagram Facebook-login OAuth staging and import.
 *
 * After the bridge returns a user access token, WordPress stores candidate Pages
 * (with Instagram) in a short-lived transient so the dashboard can let the user
 * choose which accounts to keep before writing rows to {@see ESF_Instagram_Account_Repository}.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient-backed pick session and import for Page-backed Instagram accounts.
 *
 * @since 6.8.0
 */
class ESF_Instagram_Facebook_Oauth_Service {

	/**
	 * Transient key prefix (per WordPress user id).
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'esf_ig_fb_pick_v1_';

	/**
	 * Time-to-live for a staged pick session (seconds).
	 *
	 * @var int
	 */
	const PICK_SESSION_TTL = 900;

	/**
	 * Transient option key for a user.
	 *
	 * @since 6.8.0
	 * @param int $user_id WordPress user id.
	 * @return string
	 */
	public static function transient_key( $user_id ) {
		return self::TRANSIENT_PREFIX . (int) $user_id;
	}

	/**
	 * Discover Pages + Instagram, then persist a pick session for the user.
	 *
	 * @since 6.8.0
	 * @param int    $user_id           WordPress user id (owner).
	 * @param string $user_access_token Long-lived (or short-lived) Facebook user token.
	 * @param int    $token_expires_in  Seconds until user token expiry from bridge (`esf_fb_expires_in`). If zero, a filterable default (~60 days) applies so `token_expires_at` is stored on import.
	 * @return true|WP_Error True when staged (including zero candidates — caller may treat as error), WP_Error on transport/Graph failure.
	 */
	public static function stage_pick_session( $user_id, $user_access_token, $token_expires_in = 0 ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return new WP_Error( 'esf_ig_fb_invalid_user', __( 'Invalid user.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$token_expires_in = (int) $token_expires_in;
		if ( $token_expires_in <= 0 ) {
			/**
			 * Seconds until Facebook user access token expiry when the OAuth bridge omits `esf_fb_expires_in`.
			 * Default matches Meta’s typical ~60-day long-lived user token lifetime so admin UI and cron
			 * have a sensible `token_expires_at`. Return 0 to leave expiry unknown (previous behavior).
			 *
			 * @since 6.8.0
			 * @param int    $default           Default 5184000 (60 days).
			 * @param int    $user_id           WordPress user id.
			 * @param string $user_access_token User access token (do not log).
			 */
			$fallback = (int) apply_filters( 'esf_instagram_facebook_user_token_default_expires_in', 5184000, $user_id, $user_access_token );
			if ( $fallback > 0 ) {
				$token_expires_in = $fallback;
			}
		}

		$fb_user_id = ESF_Instagram_Facebook_Graph::fetch_facebook_user_id( $user_access_token );
		if ( is_wp_error( $fb_user_id ) ) {
			return $fb_user_id;
		}

		$candidates = ESF_Instagram_Facebook_Graph::fetch_all_instagram_page_candidates( $user_access_token );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}
		if ( array() === $candidates ) {
			return new WP_Error(
				'esf_ig_fb_no_ig',
				__( 'No Instagram Business or Creator accounts were found on the Facebook Pages returned for this login.', 'easy-facebook-likebox' ),
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
			'facebook_user_token' => sanitize_text_field( (string) $user_access_token ),
			'user_token_expires'  => $expires_at,
			'candidates'          => $candidates,
			'staged_at'           => time(),
		);

		set_transient( self::transient_key( $user_id ), $session, $ttl );

		return true;
	}

	/**
	 * Read staged session for REST (no tokens).
	 *
	 * @since 6.8.0
	 * @param int $user_id WordPress user id.
	 * @return array<string,mixed>|null Null if none.
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
				'instagram_user_id' => isset( $row['instagram_user_id'] ) ? (string) $row['instagram_user_id'] : '',
				'username'          => isset( $row['username'] ) ? (string) $row['username'] : '',
				'display_name'      => isset( $row['display_name'] ) ? (string) $row['display_name'] : '',
				'profile_image_url' => isset( $row['profile_image_url'] ) ? (string) $row['profile_image_url'] : '',
			);
		}

		return array(
			'candidates' => $public,
		);
	}

	/**
	 * Remove staged session without importing.
	 *
	 * @since 6.8.0
	 * @param int $user_id WordPress user id.
	 * @return void
	 */
	public static function clear_pick_session( $user_id ) {
		delete_transient( self::transient_key( (int) $user_id ) );
	}

	/**
	 * Import selected Page-backed Instagram accounts and clear the session.
	 *
	 * @since 6.8.0
	 * @param int              $user_id         WordPress user id.
	 * @param array<int,string> $selected_page_ids Facebook Page ids to import (must match staged candidates).
	 * @return array{imported:int,account_ids:int[]}|WP_Error
	 */
	public static function import_selected_pages( $user_id, array $selected_page_ids ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return new WP_Error( 'esf_ig_fb_invalid_user', __( 'Invalid user.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$session = get_transient( self::transient_key( $user_id ) );
		if ( ! is_array( $session ) || empty( $session['candidates'] ) || ! is_array( $session['candidates'] ) ) {
			return new WP_Error(
				'esf_ig_fb_no_session',
				__( 'No pending Facebook connection. Please start connect again.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$user_token = isset( $session['facebook_user_token'] ) ? trim( (string) $session['facebook_user_token'] ) : '';
		$fb_uid     = isset( $session['facebook_user_id'] ) ? sanitize_text_field( (string) $session['facebook_user_id'] ) : '';
		if ( '' === $user_token || '' === $fb_uid ) {
			return new WP_Error(
				'esf_ig_fb_session_corrupt',
				__( 'Pending session is invalid. Please connect again.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$want = array();
		foreach ( $selected_page_ids as $pid ) {
			$pid = sanitize_text_field( (string) $pid );
			if ( '' !== $pid ) {
				$want[ $pid ] = true;
			}
		}
		if ( array() === $want ) {
			self::clear_pick_session( $user_id );
			return array(
				'imported'    => 0,
				'account_ids' => array(),
			);
		}

		$repo    = ESF_Instagram_Account_Repository::get_instance();
		$migrator = ESF_Instagram_Migrator::get_instance();
		$stats    = current_time( 'mysql', true );

		$imported_ids = array();

		foreach ( $session['candidates'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$page_id = isset( $row['page_id'] ) ? (string) $row['page_id'] : '';
			if ( '' === $page_id || empty( $want[ $page_id ] ) ) {
				continue;
			}

			$ig = isset( $row['instagram_account'] ) && is_array( $row['instagram_account'] ) ? $row['instagram_account'] : array();
			$ig_json = wp_json_encode( $ig );
			if ( ! is_string( $ig_json ) ) {
				$ig_json = null;
			}

			$ig_user_id = isset( $row['instagram_user_id'] ) ? sanitize_text_field( (string) $row['instagram_user_id'] ) : '';
			if ( '' === $ig_user_id ) {
				continue;
			}

			$page_token = isset( $row['page_access_token'] ) ? sanitize_text_field( (string) $row['page_access_token'] ) : '';
			if ( '' === $page_token ) {
				continue;
			}

			$account_type = self::map_graph_account_type(
				isset( $ig['account_type'] ) ? (string) $ig['account_type'] : ''
			);

			$upsert = array(
				'user_id'              => $user_id,
				'account_type'         => $account_type,
				'auth_source'          => 'facebook_page',
				'instagram_user_id'    => $ig_user_id,
				'username'             => isset( $row['username'] ) ? sanitize_text_field( (string) $row['username'] ) : '',
				'display_name'         => isset( $row['display_name'] ) ? sanitize_text_field( (string) $row['display_name'] ) : '',
				'profile_image_url'    => isset( $row['profile_image_url'] ) ? esc_url_raw( (string) $row['profile_image_url'] ) : '',
				'facebook_page_id'     => $page_id,
				'facebook_page_name'   => isset( $row['page_name'] ) ? sanitize_text_field( (string) $row['page_name'] ) : '',
				'facebook_user_id'     => $fb_uid,
				'facebook_user_token'  => $user_token,
				'page_access_token'    => $page_token,
				'access_token'         => null,
				'account_data'         => $ig_json,
				'status'               => 'active',
				'stats_refreshed_at'   => $stats,
			);

			if ( ! empty( $session['user_token_expires'] ) ) {
				$upsert['token_expires_at'] = sanitize_text_field( (string) $session['user_token_expires'] );
			}

			$followers = isset( $ig['followers_count'] ) ? (int) $ig['followers_count'] : 0;
			$media     = isset( $ig['media_count'] ) ? (int) $ig['media_count'] : 0;
			$bio       = isset( $ig['biography'] ) ? sanitize_textarea_field( (string) $ig['biography'] ) : '';
			$website   = isset( $ig['website'] ) ? esc_url_raw( (string) $ig['website'] ) : '';

			$upsert['followers_count'] = $followers;
			$upsert['media_count']     = $media;
			$upsert['biography']       = $bio;
			$upsert['website']        = $website;

			$new_id = $repo->upsert( $upsert );
			if ( false !== $new_id ) {
				$imported_ids[] = (int) $new_id;
				$migrator->refresh_account_by_id( (int) $new_id, $user_id );
			}
		}

		self::clear_pick_session( $user_id );

		return array(
			'imported'    => count( $imported_ids ),
			'account_ids' => $imported_ids,
		);
	}

	/**
	 * Map Instagram Graph `account_type` string to repository enum.
	 *
	 * @since 6.8.0
	 * @param string $raw Raw value from Graph.
	 * @return string personal|business|creator
	 */
	private static function map_graph_account_type( $raw ) {
		$key = strtoupper( str_replace( ' ', '_', trim( (string) $raw ) ) );
		if ( in_array( $key, array( 'MEDIA_CREATOR', 'CREATOR' ), true ) ) {
			return 'creator';
		}
		if ( in_array( $key, array( 'BUSINESS', 'MEDIA_BUSINESS' ), true ) ) {
			return 'business';
		}
		return 'business';
	}
}
