<?php
/**
 * Manual (pasted) access-token connect for Instagram.
 *
 * Tries Instagram Login user tokens first (graph.instagram.com/me), then Facebook
 * user tokens that expose Page-linked Instagram accounts (pick session).
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_Manual_Oauth_Service
 *
 * @since 6.9.0
 */
class ESF_Instagram_Manual_Oauth_Service {

	/**
	 * Validate a pasted token and connect or stage a pick session.
	 *
	 * @since 6.9.0
	 * @param int    $user_id      WordPress user id.
	 * @param string $access_token Raw pasted token.
	 * @return array{mode:string,account_id?:int}|WP_Error `mode` is `connected` or `staged`.
	 */
	public static function submit_manual_access_token( $user_id, $access_token ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return new WP_Error( 'esf_ig_invalid_user', __( 'Invalid user.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		if ( ! class_exists( 'ESF_Manual_Access_Token' ) ) {
			return new WP_Error(
				'esf_ig_manual_unavailable',
				__( 'Manual token setup is not available.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$access_token = ESF_Manual_Access_Token::sanitize( $access_token );
		if ( ! ESF_Manual_Access_Token::is_valid_format( $access_token ) ) {
			return new WP_Error(
				'esf_ig_invalid_token',
				__( 'Enter a valid Instagram or Facebook access token.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$me = self::fetch_instagram_me( $access_token );
		if ( is_object( $me ) && ! isset( $me->error ) ) {
			$account_id = self::persist_instagram_login_manual( $user_id, $access_token, $me );
			if ( is_wp_error( $account_id ) ) {
				return $account_id;
			}

			return array(
				'mode'       => 'connected',
				'account_id' => (int) $account_id,
			);
		}

		$staged = ESF_Instagram_Facebook_Oauth_Service::stage_manual_access_token( $user_id, $access_token );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}

		return array(
			'mode' => 'staged',
		);
	}

	/**
	 * Load graph.instagram.com/me for an Instagram user access token.
	 *
	 * @since 6.9.0
	 * @param string $token Access token.
	 * @return object|null
	 */
	private static function fetch_instagram_me( $token ) {
		$full_fields = implode(
			',',
			array(
				'id',
				'username',
				'account_type',
				'media_count',
				'name',
				'profile_picture_url',
				'biography',
				'website',
				'followers_count',
			)
		);
		$minimal     = 'id,username,account_type,media_count';

		foreach ( array( $full_fields, $minimal ) as $fields ) {
			$url      = add_query_arg(
				array(
					'fields'       => $fields,
					'access_token' => $token,
				),
				'https://graph.instagram.com/me'
			);
			$response = wp_remote_get( $url, array( 'timeout' => 25 ) );
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 400 ) {
				continue;
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), false );
			if ( is_object( $body ) && ! isset( $body->error ) ) {
				return $body;
			}
		}

		return null;
	}

	/**
	 * Upsert a manual Instagram Login account row.
	 *
	 * @since 6.9.0
	 * @param int    $user_id WordPress user id.
	 * @param string $token   Instagram user token.
	 * @param object $body    graph.instagram.com/me body.
	 * @return int|WP_Error Account id.
	 */
	private static function persist_instagram_login_manual( $user_id, $token, $body ) {
		$ig_id = isset( $body->id ) ? sanitize_text_field( (string) $body->id ) : '';
		if ( '' === $ig_id ) {
			return new WP_Error(
				'esf_ig_invalid_token',
				__( 'Instagram did not return an account id for this token.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$username = isset( $body->username ) ? sanitize_text_field( (string) $body->username ) : '';
		$name     = isset( $body->name ) ? sanitize_text_field( (string) $body->name ) : '';
		$display  = '' !== $name ? $name : $username;
		$bio      = isset( $body->biography ) ? sanitize_textarea_field( (string) $body->biography ) : '';
		$website  = isset( $body->website ) ? esc_url_raw( (string) $body->website ) : '';
		$pic      = isset( $body->profile_picture_url ) ? esc_url_raw( (string) $body->profile_picture_url ) : '';
		$media    = isset( $body->media_count ) ? (int) $body->media_count : 0;
		$follow   = isset( $body->followers_count ) ? (int) $body->followers_count : 0;

		$account_json = wp_json_encode( $body );
		if ( ! is_string( $account_json ) ) {
			$account_json = null;
		}

		$data = array(
			'user_id'             => $user_id,
			'instagram_user_id'   => $ig_id,
			'account_type'        => self::map_instagram_login_account_type(
				isset( $body->account_type ) ? (string) $body->account_type : ''
			),
			'username'            => $username,
			'display_name'        => $display,
			'biography'           => $bio,
			'website'             => $website,
			'profile_image_url'   => $pic,
			'followers_count'     => $follow,
			'media_count'         => $media,
			'access_token'        => $token,
			'page_access_token'   => null,
			'facebook_page_id'    => null,
			'facebook_page_name'  => null,
			'facebook_user_id'    => null,
			'facebook_user_token' => null,
			'auth_source'         => ESF_Manual_Access_Token::AUTH_MANUAL,
			'token_expires_at'    => null,
			'status'              => 'active',
			'stats_refreshed_at'  => current_time( 'mysql', true ),
			'account_data'        => $account_json,
		);

		$id = ESF_Instagram_Account_Repository::get_instance()->upsert( $data );
		if ( false === $id || $id <= 0 ) {
			return new WP_Error(
				'esf_ig_account_save_failed',
				__( 'Could not save the Instagram account.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		if ( class_exists( 'ESF_Instagram_Migrator' ) ) {
			ESF_Instagram_Migrator::get_instance()->refresh_account_by_id( (int) $id, $user_id );
		}

		if ( function_exists( 'esf_review_request_record_milestone' ) ) {
			esf_review_request_record_milestone( 'account_connected', 'instagram' );
		}

		return (int) $id;
	}

	/**
	 * Map Graph `account_type` to repository enum.
	 *
	 * @since 6.9.0
	 * @param string $raw Raw account_type from graph.instagram.com/me.
	 * @return string personal|business|creator
	 */
	private static function map_instagram_login_account_type( $raw ) {
		$key = strtoupper( str_replace( ' ', '_', trim( (string) $raw ) ) );
		if ( in_array( $key, array( 'BUSINESS', 'BUSINESS_ACCOUNT', 'MEDIA_BUSINESS' ), true ) ) {
			return 'business';
		}
		if ( in_array( $key, array( 'MEDIA_CREATOR', 'CREATOR' ), true ) ) {
			return 'creator';
		}
		return 'personal';
	}
}
