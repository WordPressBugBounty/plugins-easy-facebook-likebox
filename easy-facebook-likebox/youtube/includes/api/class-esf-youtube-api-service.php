<?php
/**
 * YouTube API Service.
 *
 * Handles communication with YouTube Data API v3.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/API
 * @since 6.7.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_API_Service
 *
 * @since 6.7.5
 */
class ESF_YouTube_API_Service {

	use ESF_YouTube_Singleton;

	/**
	 * YouTube Data API base URL.
	 *
	 * @since 6.7.5
	 * @var string
	 */
	const API_BASE_URL = 'https://www.googleapis.com/youtube/v3';

	/**
	 * Get YouTube API language (hl) from ESF feed language setting.
	 *
	 * Uses the feed language selected on ESF Settings (General tab) so that
	 * channel/video metadata is returned in that language when supported.
	 *
	 * @since 6.7.5
	 * @return string Two-letter language code for YouTube hl parameter, or empty string.
	 */
	public static function get_hl_for_request() {
		if ( ! function_exists( 'esf_get_effective_api_locale' ) ) {
			return '';
		}
		$locale = esf_get_effective_api_locale();
		if ( '' === $locale || strlen( $locale ) < 2 ) {
			return '';
		}
		return strtolower( substr( $locale, 0, 2 ) );
	}

	/**
	 * Validate access token and fetch channel information.
	 *
	 * Makes a request to YouTube API to verify the token works and
	 * retrieves the authenticated user's channel details.
	 *
	 * @since 6.7.5
	 *
	 * @param string $access_token The access token to validate.
	 *
	 * @return array|WP_Error Channel data on success, WP_Error on failure.
	 *                        Success array contains: id, title, thumbnail, banner, customUrl, description, statistics
	 */
	public function validate_token_and_fetch_channel( $access_token ) {
		if ( empty( $access_token ) ) {
			return new WP_Error( 'invalid_token', __( 'Access token is required.', 'easy-facebook-likebox' ) );
		}

		// Request user's channel information.
		// part=snippet,contentDetails gets channel name, thumbnail, etc.
		// mine=true gets the authenticated user's channel.
		$endpoint = self::API_BASE_URL . '/channels';

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Accept'        => 'application/json',
			),
			'timeout' => 15,
		);

		$query_args = array(
			'part' => 'snippet,contentDetails,statistics,brandingSettings',
			'mine' => 'true',
		);
		$hl         = self::get_hl_for_request();
		if ( '' !== $hl ) {
			$query_args['hl'] = $hl;
		}
		$url = add_query_arg( $query_args, $endpoint );

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'api_request_failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Failed to connect to YouTube API: %s', 'easy-facebook-likebox' ),
					$response->get_error_message()
				)
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );
		$data          = json_decode( $body, true );

		// Handle API errors.
		if ( 200 !== $response_code ) {
			$error_message = isset( $data['error']['message'] )
				? $data['error']['message']
				: __( 'Unknown API error', 'easy-facebook-likebox' );

			return new WP_Error(
				'youtube_api_error',
				sprintf(
					/* translators: %s: Error message */
					__( 'YouTube API error: %s', 'easy-facebook-likebox' ),
					$error_message
				),
				array( 'status' => $response_code )
			);
		}

		// Check if we got channel data.
		if ( empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return new WP_Error(
				'no_channel',
				__( 'No YouTube channel found for this account. Please create a channel first.', 'easy-facebook-likebox' )
			);
		}

		// Extract channel information.
		$channel = $data['items'][0];

		// Banner: YouTube returns it under brandingSettings.image (may be deprecated but still returned).
		$banner_url = '';
		if ( ! empty( $channel['brandingSettings']['image'] ) && is_array( $channel['brandingSettings']['image'] ) ) {
			$img = $channel['brandingSettings']['image'];
			if ( ! empty( $img['bannerImageUrl'] ) ) {
				$banner_url = esc_url_raw( $img['bannerImageUrl'] );
			} elseif ( ! empty( $img['bannerExternalUrl'] ) ) {
				$banner_url = esc_url_raw( $img['bannerExternalUrl'] );
			} elseif ( ! empty( $img['bannerTabletImageUrl'] ) ) {
				$banner_url = esc_url_raw( $img['bannerTabletImageUrl'] );
			}
		}

		$channel_data = array(
			'id'          => isset( $channel['id'] ) ? sanitize_text_field( $channel['id'] ) : '',
			'title'       => isset( $channel['snippet']['title'] ) ? sanitize_text_field( $channel['snippet']['title'] ) : '',
			'description' => isset( $channel['snippet']['description'] ) ? sanitize_textarea_field( $channel['snippet']['description'] ) : '',
			'custom_url'  => isset( $channel['snippet']['customUrl'] ) ? sanitize_text_field( $channel['snippet']['customUrl'] ) : '',
			'thumbnail'   => isset( $channel['snippet']['thumbnails']['default']['url'] )
				? esc_url_raw( $channel['snippet']['thumbnails']['default']['url'] )
				: '',
			'banner'      => $banner_url,
			'country'     => isset( $channel['snippet']['country'] ) ? sanitize_text_field( $channel['snippet']['country'] ) : '',
			'statistics'  => array(
				'subscribers' => isset( $channel['statistics']['subscriberCount'] ) ? (int) $channel['statistics']['subscriberCount'] : 0,
				'videos'      => isset( $channel['statistics']['videoCount'] ) ? (int) $channel['statistics']['videoCount'] : 0,
				'views'       => isset( $channel['statistics']['viewCount'] ) ? (int) $channel['statistics']['viewCount'] : 0,
			),
		);

		return $channel_data;
	}

	/**
	 * Refresh an access token using a refresh token.
	 *
	 * Calls the external OAuth server to exchange the refresh token
	 * for a new access token. This is more secure than exposing
	 * client credentials in the WordPress installation.
	 *
	 * @since 6.7.5
	 *
	 * @param string $refresh_token The refresh token.
	 *
	 * @return array|WP_Error Token data on success, WP_Error on failure.
	 *                        Success array contains: access_token, expires_in, refresh_token (optional)
	 */
	public function refresh_access_token( $refresh_token ) {
		if ( empty( $refresh_token ) ) {
			return new WP_Error( 'invalid_refresh_token', __( 'Refresh token is required.', 'easy-facebook-likebox' ) );
		}

		// External OAuth server endpoint for token refresh.
		$app_id = ESF_YouTube_API_OAuth::get_app_id();

		$refresh_url = sprintf(
			'https://easysocialfeed.com/apps/youtube/%s/refresh.php',
			rawurlencode( trim( (string) $app_id ) )
		);

		/**
		 * Filter the full URL used for refreshing YouTube OAuth tokens.
		 *
		 * @since 6.7.5
		 *
		 * @param string $refresh_url Refresh endpoint URL.
		 * @param string $app_id      App ID used in the URL.
		 */
		$refresh_url = apply_filters( 'esf_youtube_oauth_refresh_url', $refresh_url, $app_id );

		// Make request to external server to refresh the token.
		$response = wp_remote_post(
			$refresh_url,
			array(
				'body'    => array(
					'refresh_token' => $refresh_token,
					'site_url'      => home_url(),
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'refresh_request_failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Failed to refresh token: %s', 'easy-facebook-likebox' ),
					$response->get_error_message()
				)
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );
		$data          = json_decode( $body, true );

		// Handle error responses.
		if ( 200 !== $response_code ) {
			$error_message = isset( $data['error'] ) ? $data['error'] : __( 'Unknown error during token refresh', 'easy-facebook-likebox' );

			return new WP_Error(
				'refresh_failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Token refresh failed: %s', 'easy-facebook-likebox' ),
					$error_message
				),
				array( 'status' => $response_code )
			);
		}

		// Validate required fields.
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error(
				'invalid_refresh_response',
				__( 'Invalid response from token refresh server.', 'easy-facebook-likebox' )
			);
		}

		$token_data = array(
			'access_token'  => sanitize_text_field( $data['access_token'] ),
			'expires_in'    => isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600,
			'refresh_token' => isset( $data['refresh_token'] ) ? sanitize_text_field( $data['refresh_token'] ) : $refresh_token,
		);

		return $token_data;
	}

	/**
	 * Revoke an OAuth token with Google.
	 *
	 * Calls https://oauth2.googleapis.com/revoke to revoke access.
	 * Use access_token or refresh_token; revoking refresh_token fully revokes consent.
	 *
	 * @since 6.7.5
	 *
	 * @param string $token Access token or refresh token to revoke.
	 *
	 * @return bool True if revoke succeeded (200) or token was already invalid (400), false on failure.
	 */
	public function revoke_token( $token ) {
		if ( empty( $token ) || ! is_string( $token ) ) {
			return false;
		}

		$url = add_query_arg(
			array( 'token' => $token ),
			'https://oauth2.googleapis.com/revoke'
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		// 200 = revoked; 400 = invalid/expired token (already revoked or bad token).
		return ( 200 === $code || 400 === $code );
	}

	/**
	 * Get the uploads playlist ID for the authenticated user's channel.
	 *
	 * @since 6.7.5
	 *
	 * @param string $access_token Access token.
	 * @return string|WP_Error Uploads playlist ID on success, WP_Error on failure.
	 */
	public function get_uploads_playlist_id( $access_token ) {
		if ( empty( $access_token ) ) {
			return new WP_Error( 'invalid_token', __( 'Access token is required.', 'easy-facebook-likebox' ) );
		}

		$query_args = array(
			'part' => 'contentDetails',
			'mine' => 'true',
		);
		$hl         = self::get_hl_for_request();
		if ( '' !== $hl ) {
			$query_args['hl'] = $hl;
		}
		$url = add_query_arg( $query_args, self::API_BASE_URL . '/channels' );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'api_request_failed',
				$response->get_error_message()
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code || empty( $data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ) ) {
			return new WP_Error(
				'no_uploads_playlist',
				__( 'Could not get channel uploads playlist.', 'easy-facebook-likebox' )
			);
		}

		return sanitize_text_field( $data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] );
	}

	/**
	 * Fetch videos from a playlist (normalized for feed display).
	 *
	 * @since 6.7.5
	 *
	 * @param string $access_token Access token.
	 * @param string $playlist_id  Playlist ID.
	 * @param int    $max_results Max number of videos (1–50). Default 12.
	 * @return array|WP_Error Array of normalized video items on success, WP_Error on failure.
	 */
	public function fetch_playlist_videos( $access_token, $playlist_id, $max_results = 12 ) {
		if ( empty( $access_token ) || empty( $playlist_id ) ) {
			return new WP_Error( 'invalid_args', __( 'Access token and playlist ID are required.', 'easy-facebook-likebox' ) );
		}

		$max_results = max( 1, min( 50, (int) $max_results ) );

		$query_args = array(
			'part'       => 'snippet,contentDetails',
			'playlistId' => $playlist_id,
			'maxResults' => $max_results,
		);
		$hl         = self::get_hl_for_request();
		if ( '' !== $hl ) {
			$query_args['hl'] = $hl;
		}
		$url = add_query_arg( $query_args, self::API_BASE_URL . '/playlistItems' );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_request_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unknown API error', 'easy-facebook-likebox' );
			return new WP_Error( 'youtube_api_error', $msg, array( 'status' => $code ) );
		}

		$items  = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$videos = $this->normalize_playlist_items( $items );

		if ( empty( $videos ) ) {
			return $videos;
		}

		$video_ids = array_column( $videos, 'video_id' );
		$stats_map = $this->fetch_videos_statistics( $access_token, $video_ids );

		foreach ( $videos as $i => $v ) {
			$video_id = isset( $v['video_id'] ) ? $v['video_id'] : '';
			if ( '' === $video_id || ! isset( $stats_map[ $video_id ] ) || ! is_array( $stats_map[ $video_id ] ) ) {
				continue;
			}

			$stats = $stats_map[ $video_id ];

			$videos[ $i ]['view_count']    = isset( $stats['view_count'] ) ? (int) $stats['view_count'] : 0;
			$videos[ $i ]['like_count']    = isset( $stats['like_count'] ) ? (int) $stats['like_count'] : 0;
			$videos[ $i ]['comment_count'] = isset( $stats['comment_count'] ) ? (int) $stats['comment_count'] : 0;
		}

		return $videos;
	}

	/**
	 * Fetch videos from search (normalized for feed display).
	 *
	 * @since 6.7.5
	 *
	 * @param string $access_token Access token.
	 * @param string $query       Search query.
	 * @param int    $max_results Max number of videos (1–50). Default 12.
	 * @return array|WP_Error Array of normalized video items on success, WP_Error on failure.
	 */
	public function fetch_search_videos( $access_token, $query, $max_results = 12 ) {
		if ( empty( $access_token ) || '' === trim( $query ) ) {
			return new WP_Error( 'invalid_args', __( 'Access token and search query are required.', 'easy-facebook-likebox' ) );
		}

		$max_results = max( 1, min( 50, (int) $max_results ) );

		$query_args = array(
			'part'       => 'snippet',
			'type'       => 'video',
			'q'          => $query,
			'maxResults' => $max_results,
		);
		$hl         = self::get_hl_for_request();
		if ( '' !== $hl ) {
			$query_args['hl'] = $hl;
		}
		$url = add_query_arg( $query_args, self::API_BASE_URL . '/search' );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_request_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unknown API error', 'easy-facebook-likebox' );
			return new WP_Error( 'youtube_api_error', $msg, array( 'status' => $code ) );
		}

		$items     = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$video_ids = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['id']['videoId'] ) ) {
				$video_ids[] = $item['id']['videoId'];
			}
		}

		if ( empty( $video_ids ) ) {
			return array();
		}

		$videos = $this->fetch_videos_details( $access_token, $video_ids );
		if ( is_wp_error( $videos ) ) {
			return $videos;
		}

		$stats_map = $this->fetch_videos_statistics( $access_token, $video_ids );
		foreach ( $videos as $i => $v ) {
			$video_id = isset( $v['video_id'] ) ? $v['video_id'] : '';
			if ( '' === $video_id || ! isset( $stats_map[ $video_id ] ) || ! is_array( $stats_map[ $video_id ] ) ) {
				continue;
			}

			$stats = $stats_map[ $video_id ];

			$videos[ $i ]['view_count']    = isset( $stats['view_count'] ) ? (int) $stats['view_count'] : 0;
			$videos[ $i ]['like_count']    = isset( $stats['like_count'] ) ? (int) $stats['like_count'] : 0;
			$videos[ $i ]['comment_count'] = isset( $stats['comment_count'] ) ? (int) $stats['comment_count'] : 0;
		}

		return $videos;
	}

	/**
	 * Normalize playlistItems response to feed video format.
	 *
	 * @since 6.7.5
	 *
	 * @param array $items Raw playlistItems items.
	 * @return array Normalized videos (id, video_id, title, description, thumbnail_url, published_at).
	 */
	private function normalize_playlist_items( $items ) {
		$videos = array();
		foreach ( $items as $item ) {
			$video_id = isset( $item['contentDetails']['videoId'] ) ? $item['contentDetails']['videoId'] : ( isset( $item['snippet']['resourceId']['videoId'] ) ? $item['snippet']['resourceId']['videoId'] : '' );
			if ( '' === $video_id ) {
				continue;
			}
			$snippet  = isset( $item['snippet'] ) ? $item['snippet'] : array();
			$thumb    = isset( $snippet['thumbnails']['maxres']['url'] ) ? $snippet['thumbnails']['maxres']['url'] : ( isset( $snippet['thumbnails']['high']['url'] ) ? $snippet['thumbnails']['high']['url'] : ( isset( $snippet['thumbnails']['medium']['url'] ) ? $snippet['thumbnails']['medium']['url'] : ( isset( $snippet['thumbnails']['default']['url'] ) ? $snippet['thumbnails']['default']['url'] : '' ) ) );
			$videos[] = array(
				'id'            => $video_id,
				'video_id'      => $video_id,
				'title'         => isset( $snippet['title'] ) ? $snippet['title'] : '',
				'description'   => isset( $snippet['description'] ) ? $snippet['description'] : '',
				'thumbnail_url' => $thumb,
				'published_at'  => isset( $snippet['publishedAt'] ) ? $snippet['publishedAt'] : '',
				'view_count'    => 0,
			);
		}
		return $videos;
	}

	/**
	 * Fetch video statistics (view count) for given video IDs.
	 *
	 * @since 6.7.5
	 *
	 * @param string   $access_token Access token.
	 * @param string[] $video_ids    Video IDs (max 50).
	 * @return array Map of video_id => view_count.
	 */
	private function fetch_videos_statistics( $access_token, $video_ids ) {
		if ( empty( $video_ids ) ) {
			return array();
		}

		$ids        = array_slice( array_unique( array_filter( $video_ids ) ), 0, 50 );
		$query_args = array(
			'part' => 'statistics',
			'id'   => implode( ',', $ids ),
		);
		$hl         = self::get_hl_for_request();
		if ( '' !== $hl ) {
			$query_args['hl'] = $hl;
		}
		$url = add_query_arg( $query_args, self::API_BASE_URL . '/videos' );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array();
		}

		$body  = wp_remote_retrieve_body( $response );
		$data  = json_decode( $body, true );
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$out   = array();

		foreach ( $items as $item ) {
			$vid = isset( $item['id'] ) ? $item['id'] : '';
			if ( '' === $vid ) {
				continue;
			}

			$stats = isset( $item['statistics'] ) && is_array( $item['statistics'] ) ? $item['statistics'] : array();

			$out[ $vid ] = array(
				'view_count'    => isset( $stats['viewCount'] ) ? (int) $stats['viewCount'] : 0,
				'like_count'    => isset( $stats['likeCount'] ) ? (int) $stats['likeCount'] : 0,
				'comment_count' => isset( $stats['commentCount'] ) ? (int) $stats['commentCount'] : 0,
			);
		}

		return $out;
	}

	/**
	 * Fetch video details (snippet) for given video IDs (used by search).
	 *
	 * @since 6.7.5
	 *
	 * @param string   $access_token Access token.
	 * @param string[] $video_ids    Video IDs (max 50).
	 * @return array|WP_Error Normalized video array or WP_Error.
	 */
	private function fetch_videos_details( $access_token, $video_ids ) {
		if ( empty( $video_ids ) ) {
			return array();
		}

		$ids        = array_slice( array_unique( array_filter( $video_ids ) ), 0, 50 );
		$query_args = array(
			'part' => 'snippet',
			'id'   => implode( ',', $ids ),
		);
		$hl         = self::get_hl_for_request();
		if ( '' !== $hl ) {
			$query_args['hl'] = $hl;
		}
		$url = add_query_arg( $query_args, self::API_BASE_URL . '/videos' );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return new WP_Error( 'api_request_failed', __( 'Failed to fetch video details.', 'easy-facebook-likebox' ) );
		}

		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );
		$items  = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$videos = array();
		foreach ( $items as $item ) {
			$video_id = isset( $item['id'] ) ? $item['id'] : '';
			$snippet  = isset( $item['snippet'] ) ? $item['snippet'] : array();
			$thumb    = isset( $snippet['thumbnails']['maxres']['url'] ) ? $snippet['thumbnails']['maxres']['url'] : ( isset( $snippet['thumbnails']['high']['url'] ) ? $snippet['thumbnails']['high']['url'] : ( isset( $snippet['thumbnails']['medium']['url'] ) ? $snippet['thumbnails']['medium']['url'] : ( isset( $snippet['thumbnails']['default']['url'] ) ? $snippet['thumbnails']['default']['url'] : '' ) ) );
			$videos[] = array(
				'id'            => $video_id,
				'video_id'      => $video_id,
				'title'         => isset( $snippet['title'] ) ? $snippet['title'] : '',
				'description'   => isset( $snippet['description'] ) ? $snippet['description'] : '',
				'thumbnail_url' => $thumb,
				'published_at'  => isset( $snippet['publishedAt'] ) ? $snippet['publishedAt'] : '',
				'view_count'    => 0,
			);
		}
		return $videos;
	}
}
