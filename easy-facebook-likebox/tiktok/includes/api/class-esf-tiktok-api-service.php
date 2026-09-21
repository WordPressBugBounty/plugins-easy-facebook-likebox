<?php
/**
 * TikTok API Service
 *
 * Thin client for TikTok Login Kit token refresh and Display API user.info.
 * Client secrets stay on the ESF bridge — refresh goes through the bridge proxy.
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
 * Class ESF_TikTok_API_Service
 *
 * @since 6.8.0
 */
class ESF_TikTok_API_Service {

	use ESF_TikTok_Singleton;

	/**
	 * TikTok Display / Open API base URL.
	 *
	 * @since 6.8.0
	 * @var string
	 */
	const API_BASE = 'https://open.tiktokapis.com';

	/**
	 * Default user.info fields (basic + profile + stats).
	 *
	 * Request all intended fields; API omits those outside granted scopes.
	 *
	 * @since 6.8.0
	 * @var string
	 */
	const USER_INFO_FIELDS = 'open_id,union_id,avatar_url,avatar_url_100,display_name,username,bio_description,is_verified,profile_deep_link,follower_count,following_count,likes_count,video_count';

	/**
	 * Constructor.
	 *
	 * @since 6.8.0
	 */
	private function __construct() {}

	/**
	 * Refresh an OAuth access token via the ESF bridge (client_secret server-side).
	 *
	 * Always persist a replacement `refresh_token` when the response includes one.
	 *
	 * @since 6.8.0
	 * @param string $refresh_token Refresh token.
	 * @return array|WP_Error Keys: access_token, refresh_token, expires_in, refresh_expires_in?, open_id?, scope?
	 */
	public function refresh_access_token( $refresh_token ) {
		$refresh_token = trim( (string) $refresh_token );
		if ( '' === $refresh_token ) {
			return new WP_Error( 'invalid_refresh_token', __( 'Refresh token is required.', 'easy-facebook-likebox' ) );
		}

		$refresh_url = apply_filters(
			'esf_tiktok_oauth_refresh_url',
			'https://easysocialfeed.com/apps/tiktok/refresh.php'
		);

		$response = wp_remote_post(
			$refresh_url,
			array(
				'body'    => array(
					'refresh_token' => $refresh_token,
					'site_url'      => home_url(),
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'refresh_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to refresh TikTok token: %s', 'easy-facebook-likebox' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$data = $body;
		if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
			$data = $body['data'];
		} elseif ( isset( $body['result'] ) && is_array( $body['result'] ) ) {
			$data = $body['result'];
		}

		if ( ! in_array( $code, array( 200, 202 ), true ) ) {
			$msg = isset( $body['error'] ) && is_string( $body['error'] )
				? $body['error']
				: ( isset( $body['message'] ) ? (string) $body['message'] : sprintf( 'HTTP %d', $code ) );
			return new WP_Error( 'refresh_error', $msg, array( 'status' => $code ) );
		}

		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'refresh_invalid_response', __( 'Invalid token refresh response from server.', 'easy-facebook-likebox' ) );
		}

		return array(
			'access_token'       => (string) $data['access_token'],
			'refresh_token'      => isset( $data['refresh_token'] ) ? (string) $data['refresh_token'] : $refresh_token,
			'expires_in'         => isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 86400,
			'refresh_expires_in' => isset( $data['refresh_expires_in'] ) ? (int) $data['refresh_expires_in'] : 0,
			'open_id'            => isset( $data['open_id'] ) ? (string) $data['open_id'] : '',
			'scope'              => isset( $data['scope'] ) ? (string) $data['scope'] : '',
		);
	}

	/**
	 * Validate an access token and fetch the authenticated user's profile.
	 *
	 * @since 6.8.0
	 * @param string $access_token OAuth access token.
	 * @param string $scopes_hint  Optional comma-separated scopes (unused for field list; reserved).
	 * @return array|WP_Error Normalized user array.
	 */
	public function validate_token_and_fetch_user( $access_token, $scopes_hint = '' ) {
		unset( $scopes_hint );

		$access_token = trim( (string) $access_token );
		if ( '' === $access_token ) {
			return new WP_Error( 'invalid_access_token', __( 'Access token is required.', 'easy-facebook-likebox' ) );
		}

		$fields = (string) apply_filters( 'esf_tiktok_user_info_fields', self::USER_INFO_FIELDS );
		$url    = add_query_arg(
			array(
				'fields' => $fields,
			),
			self::API_BASE . '/v2/user/info/'
		);

		$response = $this->api_request( $url, $access_token );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user = array();
		if ( isset( $response['data']['user'] ) && is_array( $response['data']['user'] ) ) {
			$user = $response['data']['user'];
		} elseif ( isset( $response['data'] ) && is_array( $response['data'] ) && isset( $response['data']['open_id'] ) ) {
			$user = $response['data'];
		}

		if ( empty( $user['open_id'] ) ) {
			return new WP_Error( 'invalid_user_response', __( 'Could not retrieve TikTok user data.', 'easy-facebook-likebox' ) );
		}

		return $this->normalize_user( $user );
	}

	/**
	 * Normalize a TikTok user.info payload into DB-friendly keys.
	 *
	 * Every nested read uses isset() / null coalescing — empty/partial API
	 * responses must never raise notices.
	 *
	 * @since 6.8.0
	 * @param array<string,mixed> $user Raw user node.
	 * @return array<string,mixed>
	 */
	public function normalize_user( $user ) {
		if ( ! is_array( $user ) ) {
			$user = array();
		}

		$avatar = '';
		if ( isset( $user['avatar_url'] ) && is_string( $user['avatar_url'] ) && '' !== $user['avatar_url'] ) {
			$avatar = $user['avatar_url'];
		} elseif ( isset( $user['avatar_url_100'] ) && is_string( $user['avatar_url_100'] ) ) {
			$avatar = $user['avatar_url_100'];
		}

		return array(
			'open_id'           => isset( $user['open_id'] ) ? (string) $user['open_id'] : '',
			'union_id'          => isset( $user['union_id'] ) ? (string) $user['union_id'] : '',
			'username'          => isset( $user['username'] ) ? (string) $user['username'] : '',
			'display_name'      => isset( $user['display_name'] ) ? (string) $user['display_name'] : '',
			'avatar_url'        => $avatar,
			'bio_description'   => isset( $user['bio_description'] ) ? (string) $user['bio_description'] : '',
			'is_verified'       => ! empty( $user['is_verified'] ),
			'profile_deep_link' => isset( $user['profile_deep_link'] ) ? (string) $user['profile_deep_link'] : '',
			'follower_count'    => isset( $user['follower_count'] ) ? (int) $user['follower_count'] : 0,
			'following_count'   => isset( $user['following_count'] ) ? (int) $user['following_count'] : 0,
			'likes_count'       => isset( $user['likes_count'] ) ? (int) $user['likes_count'] : 0,
			'video_count'       => isset( $user['video_count'] ) ? (int) $user['video_count'] : 0,
			'raw'               => $user,
		);
	}

	/**
	 * Default video.list / video.query fields.
	 *
	 * @since 6.8.0
	 * @var string
	 */
	const VIDEO_LIST_FIELDS = 'id,title,video_description,duration,cover_image_url,share_url,embed_link,create_time,height,width,like_count,comment_count,share_count,view_count';

	/**
	 * Fetch the authenticated user's videos via Display API video.list.
	 *
	 * @since 6.8.0
	 * @param string     $access_token Bearer token.
	 * @param int        $max_count    Max videos (1–20 per TikTok docs).
	 * @param int|string $cursor       Optional pagination cursor.
	 * @return array|WP_Error Keys: videos, cursor, has_more, posts (alias of videos).
	 */
	public function fetch_user_videos( $access_token, $max_count = 20, $cursor = 0 ) {
		$access_token = trim( (string) $access_token );
		if ( '' === $access_token ) {
			return new WP_Error( 'invalid_access_token', __( 'Access token is required.', 'easy-facebook-likebox' ) );
		}

		$max_count = max( 1, min( 20, (int) $max_count ) );
		$fields    = (string) apply_filters( 'esf_tiktok_video_list_fields', self::VIDEO_LIST_FIELDS );
		$url       = add_query_arg(
			array(
				'fields' => $fields,
			),
			self::API_BASE . '/v2/video/list/'
		);

		$body = array(
			'max_count' => $max_count,
		);
		if ( is_numeric( $cursor ) && (int) $cursor > 0 ) {
			$body['cursor'] = (int) $cursor;
		} elseif ( is_string( $cursor ) && '' !== $cursor && '0' !== $cursor ) {
			$body['cursor'] = $cursor;
		}

		$response = $this->api_request( $url, $access_token, 'POST', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data   = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$videos = array();
		if ( isset( $data['videos'] ) && is_array( $data['videos'] ) ) {
			$videos = array_values( $data['videos'] );
		}

		$out_cursor = isset( $data['cursor'] ) ? $data['cursor'] : 0;
		$has_more   = ! empty( $data['has_more'] );

		return array(
			'videos'   => $videos,
			'posts'    => $videos, // Renderer / cache parity with other modules.
			'cursor'   => $out_cursor,
			'has_more' => (bool) $has_more,
			'meta'     => array(
				'cursor'   => $out_cursor,
				'has_more' => (bool) $has_more,
			),
		);
	}

	/**
	 * Refresh metadata for specific video IDs (cover URL recovery).
	 *
	 * @since 6.8.0
	 * @param string        $access_token Bearer token.
	 * @param array<string> $video_ids    TikTok video ids.
	 * @return array|WP_Error Keys: videos (list of nodes).
	 */
	public function fetch_videos_by_ids( $access_token, array $video_ids ) {
		$access_token = trim( (string) $access_token );
		if ( '' === $access_token ) {
			return new WP_Error( 'invalid_access_token', __( 'Access token is required.', 'easy-facebook-likebox' ) );
		}

		$ids = array();
		foreach ( $video_ids as $id ) {
			$id = sanitize_text_field( (string) $id );
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( empty( $ids ) ) {
			return array( 'videos' => array() );
		}

		// TikTok caps filters.video_ids; keep batches modest.
		$ids = array_slice( $ids, 0, 20 );

		$fields = (string) apply_filters( 'esf_tiktok_video_list_fields', self::VIDEO_LIST_FIELDS );
		$url    = add_query_arg(
			array(
				'fields' => $fields,
			),
			self::API_BASE . '/v2/video/query/'
		);

		$body = array(
			'filters' => array(
				'video_ids' => $ids,
			),
		);

		$response = $this->api_request( $url, $access_token, 'POST', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data   = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$videos = array();
		if ( isset( $data['videos'] ) && is_array( $data['videos'] ) ) {
			$videos = array_values( $data['videos'] );
		}

		return array( 'videos' => $videos );
	}

	/**
	 * Perform an authenticated request against the TikTok Open API.
	 *
	 * @since 6.8.0
	 * @param string              $url          Absolute URL.
	 * @param string              $access_token Bearer token.
	 * @param string              $method       HTTP method GET|POST.
	 * @param array<string,mixed> $body         JSON body for POST.
	 * @return array|WP_Error Decoded JSON body or error.
	 */
	private function api_request( $url, $access_token, $method = 'GET', $body = array() ) {
		$method  = strtoupper( (string) $method );
		$headers = array(
			'Authorization' => 'Bearer ' . $access_token,
		);
		$args    = array(
			'headers' => $headers,
			'timeout' => 20,
		);

		if ( 'POST' === $method ) {
			$headers['Content-Type'] = 'application/json';
			$args['headers']         = $headers;
			$args['body']            = wp_json_encode( is_array( $body ) ? $body : array() );
			$response                = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'tiktok_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'TikTok API request failed: %s', 'easy-facebook-likebox' ),
					$response->get_error_message()
				)
			);
		}

		$code          = (int) wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $response_body ) ) {
			$response_body = array();
		}

		if ( $code < 200 || $code >= 300 ) {
			return ESF_TikTok_API_Error::from_http_response( $code, $response_body );
		}

		// TikTok wraps business errors in error.code even on HTTP 200.
		if ( isset( $response_body['error']['code'] ) && 'ok' !== strtolower( (string) $response_body['error']['code'] ) ) {
			return ESF_TikTok_API_Error::from_http_response( 400, $response_body );
		}

		return $response_body;
	}
}
