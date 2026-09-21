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
	 * Maximum comments/replies accepted per YouTube page request.
	 *
	 * @since 6.7.9
	 * @var int
	 */
	const MAX_COMMENTS_PAGE = 50;

	/**
	 * Get YouTube API language (hl) from ESF feed language setting.
	 *
	 * Uses the feed language selected on ESF Settings (General tab) so that
	 * channel/video metadata is returned in that language when supported.
	 * Returns an empty string when the locale has no YouTube-supported `hl`
	 * value — callers must omit the parameter in that case.
	 *
	 * @since 6.7.5
	 * @return string YouTube `hl` parameter value, or empty string.
	 */
	public static function get_hl_for_request() {
		if ( ! function_exists( 'esf_get_effective_api_locale' ) ) {
			return '';
		}

		$locale = esf_get_effective_api_locale();
		if ( ! is_string( $locale ) || '' === $locale ) {
			return '';
		}

		$locale = str_replace( '-', '_', $locale );

		/**
		 * Map WordPress / Facebook-style locales to YouTube Data API `hl` codes.
		 * Only values YouTube accepts should appear here; unknown locales omit `hl`.
		 *
		 * @see https://developers.google.com/youtube/v3/docs/i18nLanguages/list
		 */
		$map = array(
			'af_ZA' => 'af',
			'ar_AR' => 'ar',
			'az_AZ' => 'az',
			'be_BY' => 'be',
			'bg_BG' => 'bg',
			'bn_IN' => 'bn',
			'bs_BA' => 'bs',
			'ca_ES' => 'ca',
			'cs_CZ' => 'cs',
			'da_DK' => 'da',
			'de_DE' => 'de',
			'el_GR' => 'el',
			'en_US' => 'en',
			'en_GB' => 'en-GB',
			'es_ES' => 'es',
			'es_LA' => 'es-419',
			'et_EE' => 'et',
			'eu_ES' => 'eu',
			'fa_IR' => 'fa',
			'fi_FI' => 'fi',
			'fr_FR' => 'fr',
			'fr_CA' => 'fr-CA',
			'gl_ES' => 'gl',
			'hi_IN' => 'hi',
			'hr_HR' => 'hr',
			'hu_HU' => 'hu',
			'hy_AM' => 'hy',
			'id_ID' => 'id',
			'is_IS' => 'is',
			'it_IT' => 'it',
			'ja_JP' => 'ja',
			'ka_GE' => 'ka',
			'km_KH' => 'km',
			'ko_KR' => 'ko',
			'lt_LT' => 'lt',
			'lv_LV' => 'lv',
			'mk_MK' => 'mk',
			'ml_IN' => 'ml',
			'ms_MY' => 'ms',
			'nb_NO' => 'no',
			'ne_NP' => 'ne',
			'nl_NL' => 'nl',
			'nn_NO' => 'no',
			'pa_IN' => 'pa',
			'pl_PL' => 'pl',
			'pt_PT' => 'pt-PT',
			'pt_BR' => 'pt',
			'ro_RO' => 'ro',
			'ru_RU' => 'ru',
			'sk_SK' => 'sk',
			'sl_SI' => 'sl',
			'sq_AL' => 'sq',
			'sr_RS' => 'sr',
			'sv_SE' => 'sv',
			'sw_KE' => 'sw',
			'ta_IN' => 'ta',
			'te_IN' => 'te',
			'th_TH' => 'th',
			'tl_PH' => 'fil',
			'tr_TR' => 'tr',
			'uk_UA' => 'uk',
			'ur_PK' => 'ur',
			'vi_VN' => 'vi',
			'zh_CN' => 'zh-CN',
			'zh_HK' => 'zh-HK',
			'zh_TW' => 'zh-TW',
		);

		/**
		 * Filter the YouTube `hl` locale map.
		 *
		 * @since 6.9.1
		 * @param array<string, string> $map    Locale => YouTube hl.
		 * @param string                $locale Effective ESF API locale.
		 */
		$map = apply_filters( 'esf_youtube_hl_locale_map', $map, $locale );

		if ( isset( $map[ $locale ] ) ) {
			return (string) $map[ $locale ];
		}

		// Fallback: two-letter language only when it is a known YouTube code.
		$lang2     = strtolower( substr( $locale, 0, 2 ) );
		$supported = array_values( array_unique( array_map( 'strval', $map ) ) );
		$supported = array_merge(
			$supported,
			array( 'en', 'es', 'fr', 'de', 'it', 'ja', 'ko', 'pt', 'ru', 'ar', 'hi', 'id', 'tr', 'vi' )
		);

		if ( in_array( $lang2, $supported, true ) ) {
			return $lang2;
		}

		return '';
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

		/**
		 * Filter the full URL used for refreshing YouTube OAuth tokens.
		 *
		 * @since 6.7.5
		 *
		 * @param string $refresh_url Refresh endpoint URL.
		 */
		$refresh_url = apply_filters(
			'esf_youtube_oauth_refresh_url',
			'https://easysocialfeed.com/apps/youtube/refresh.php'
		);

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
	 * @param string   $token         Access token or refresh token to revoke.
	 * @param int|null $timeout_secs  Optional HTTP timeout (1–30 seconds). Default 10.
	 *
	 * @return bool True if revoke succeeded (200) or token was already invalid (400), false on failure.
	 */
	public function revoke_token( $token, $timeout_secs = 10 ) {
		if ( empty( $token ) || ! is_string( $token ) ) {
			return false;
		}

		$timeout_secs = max( 1, min( 30, (int) $timeout_secs ) );

		$url = add_query_arg(
			array( 'token' => $token ),
			'https://oauth2.googleapis.com/revoke'
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout_secs,
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
	 * List playlists owned by the authenticated channel (for Connect picker).
	 *
	 * @since 6.7.6
	 *
	 * @param string $access_token Access token.
	 * @param int    $max_results  Max playlists (1–50). Default 50.
	 * @return array|WP_Error List of { id, title, description, item_count, thumbnail }.
	 */
	public function list_channel_playlists( $access_token, $max_results = 50 ) {
		if ( empty( $access_token ) ) {
			return new WP_Error( 'invalid_token', __( 'Access token is required.', 'easy-facebook-likebox' ) );
		}

		$max_results = max( 1, min( 50, (int) $max_results ) );

		$query_args = array(
			'part'       => 'snippet,contentDetails',
			'mine'       => 'true',
			'maxResults' => $max_results,
		);
		$hl         = self::get_hl_for_request();
		if ( '' !== $hl ) {
			$query_args['hl'] = $hl;
		}
		$url = add_query_arg( $query_args, self::API_BASE_URL . '/playlists' );

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
			$msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Unknown API error', 'easy-facebook-likebox' );
			return new WP_Error( 'youtube_api_error', $msg, array( 'status' => $code ) );
		}

		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$out   = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = isset( $item['id'] ) ? (string) $item['id'] : '';
			if ( '' === $id ) {
				continue;
			}

			$snippet = isset( $item['snippet'] ) && is_array( $item['snippet'] ) ? $item['snippet'] : array();
			$details = isset( $item['contentDetails'] ) && is_array( $item['contentDetails'] ) ? $item['contentDetails'] : array();
			$thumbs  = isset( $snippet['thumbnails'] ) && is_array( $snippet['thumbnails'] ) ? $snippet['thumbnails'] : array();

			$thumbnail = '';
			foreach ( array( 'medium', 'default', 'high' ) as $size ) {
				if ( ! empty( $thumbs[ $size ]['url'] ) ) {
					$thumbnail = (string) $thumbs[ $size ]['url'];
					break;
				}
			}

			$out[] = array(
				'id'          => $id,
				'title'       => isset( $snippet['title'] ) ? (string) $snippet['title'] : '',
				'description' => isset( $snippet['description'] ) ? (string) $snippet['description'] : '',
				'item_count'  => isset( $details['itemCount'] ) ? (int) $details['itemCount'] : 0,
				'thumbnail'   => $thumbnail,
			);
		}

		return $out;
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
		return $this->apply_videos_statistics( $access_token, $videos, $video_ids );
	}

	/**
	 * Fetch videos from search (normalized for feed display).
	 *
	 * When $channel_id is provided, results are limited to that channel.
	 *
	 * @since 6.7.5
	 *
	 * @param string $access_token Access token.
	 * @param string $query        Search query.
	 * @param int    $max_results  Max number of videos (1–50). Default 12.
	 * @param string $channel_id   Optional. Restrict search to this channel ID.
	 * @return array{items:array,total_results:int}|WP_Error Envelope on success, WP_Error on failure.
	 */
	public function fetch_search_videos( $access_token, $query, $max_results = 12, $channel_id = '' ) {
		if ( empty( $access_token ) || '' === trim( $query ) ) {
			return new WP_Error( 'invalid_args', __( 'Access token and search query are required.', 'easy-facebook-likebox' ) );
		}

		$max_results = max( 1, min( 50, (int) $max_results ) );
		$channel_id  = sanitize_text_field( (string) $channel_id );

		$query_args = array(
			'part'       => 'snippet',
			'type'       => 'video',
			'q'          => $query,
			'maxResults' => $max_results,
		);
		if ( '' !== $channel_id ) {
			$query_args['channelId'] = $channel_id;
		}
		$hl = self::get_hl_for_request();
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

		$items         = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$total_results = isset( $data['pageInfo']['totalResults'] )
			? max( 0, (int) $data['pageInfo']['totalResults'] )
			: 0;
		$video_ids     = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['id']['videoId'] ) ) {
				$video_ids[] = $item['id']['videoId'];
			}
		}

		if ( empty( $video_ids ) ) {
			return array(
				'items'         => array(),
				'total_results' => $total_results,
			);
		}

		$videos = $this->fetch_videos_details( $access_token, $video_ids );
		if ( is_wp_error( $videos ) ) {
			return $videos;
		}

		$video_ids = array_column( $videos, 'video_id' );
		$videos    = $this->apply_videos_statistics( $access_token, $videos, $video_ids );
		if ( is_wp_error( $videos ) ) {
			return $videos;
		}

		return array(
			'items'         => is_array( $videos ) ? $videos : array(),
			'total_results' => $total_results > 0 ? $total_results : count( (array) $videos ),
		);
	}

	/**
	 * Attach statistics and duration onto normalized video items.
	 *
	 * Used after playlist/search fetch and to backfill older caches that lack duration.
	 *
	 * @since 6.7.5
	 *
	 * @param string   $access_token Access token.
	 * @param array    $videos       Normalized video items.
	 * @param string[] $video_ids    Optional explicit IDs; derived from $videos when empty.
	 * @return array Videos with stats/duration merged in.
	 */
	public function apply_videos_statistics( $access_token, array $videos, $video_ids = null ) {
		if ( empty( $videos ) ) {
			return $videos;
		}

		if ( ! is_array( $video_ids ) || empty( $video_ids ) ) {
			$video_ids = array();
			foreach ( $videos as $video ) {
				if ( ! is_array( $video ) ) {
					continue;
				}
				$id = isset( $video['video_id'] ) ? (string) $video['video_id'] : ( isset( $video['id'] ) ? (string) $video['id'] : '' );
				if ( '' !== $id ) {
					$video_ids[] = $id;
				}
			}
		}

		$stats_map = $this->fetch_videos_statistics( $access_token, $video_ids );
		if ( empty( $stats_map ) ) {
			return $videos;
		}

		foreach ( $videos as $i => $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}
			$video_id = isset( $v['video_id'] ) ? (string) $v['video_id'] : ( isset( $v['id'] ) ? (string) $v['id'] : '' );
			if ( '' === $video_id || ! isset( $stats_map[ $video_id ] ) || ! is_array( $stats_map[ $video_id ] ) ) {
				continue;
			}

			$stats = $stats_map[ $video_id ];

			$videos[ $i ]['view_count']    = isset( $stats['view_count'] ) ? (int) $stats['view_count'] : 0;
			$videos[ $i ]['like_count']    = isset( $stats['like_count'] ) ? (int) $stats['like_count'] : 0;
			$videos[ $i ]['comment_count'] = isset( $stats['comment_count'] ) ? (int) $stats['comment_count'] : 0;
			if ( ! empty( $stats['duration'] ) ) {
				$videos[ $i ]['duration']           = (string) $stats['duration'];
				$videos[ $i ]['duration_formatted'] = isset( $stats['duration_formatted'] ) ? (string) $stats['duration_formatted'] : '';
			}
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
				'channel_id'    => isset( $snippet['channelId'] ) ? (string) $snippet['channelId'] : '',
				'channel_title' => isset( $snippet['channelTitle'] ) ? (string) $snippet['channelTitle'] : '',
				'view_count'    => 0,
			);
		}
		return $videos;
	}

	/**
	 * Fetch video statistics and duration for given video IDs.
	 *
	 * @since 6.7.5
	 *
	 * @param string   $access_token Access token.
	 * @param string[] $video_ids    Video IDs (max 50).
	 * @return array Map of video_id => stats (view/like/comment counts + duration).
	 */
	private function fetch_videos_statistics( $access_token, $video_ids ) {
		if ( empty( $video_ids ) ) {
			return array();
		}

		$ids        = array_slice( array_unique( array_filter( $video_ids ) ), 0, 50 );
		$query_args = array(
			'part' => 'statistics,contentDetails',
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

			$stats           = isset( $item['statistics'] ) && is_array( $item['statistics'] ) ? $item['statistics'] : array();
			$content_details = isset( $item['contentDetails'] ) && is_array( $item['contentDetails'] ) ? $item['contentDetails'] : array();
			$duration        = isset( $content_details['duration'] ) ? (string) $content_details['duration'] : '';

			$row = array(
				'view_count'    => isset( $stats['viewCount'] ) ? (int) $stats['viewCount'] : 0,
				'like_count'    => isset( $stats['likeCount'] ) ? (int) $stats['likeCount'] : 0,
				'comment_count' => isset( $stats['commentCount'] ) ? (int) $stats['commentCount'] : 0,
			);

			if ( '' !== $duration ) {
				$row['duration']           = $duration;
				$row['duration_formatted'] = self::format_iso_duration( $duration );
			}

			$out[ $vid ] = $row;
		}

		return $out;
	}

	/**
	 * Format an ISO 8601 duration (e.g. PT1H2M10S) for display.
	 *
	 * @since 6.7.5
	 * @param string $duration ISO 8601 duration.
	 * @return string
	 */
	private static function format_iso_duration( $duration ) {
		$duration = (string) $duration;
		if ( '' === $duration ) {
			return '';
		}

		try {
			$interval = new DateInterval( $duration );
		} catch ( Exception $e ) {
			return '';
		}

		$hours   = (int) $interval->h + ( (int) $interval->d * 24 );
		$minutes = (int) $interval->i;
		$seconds = (int) $interval->s;

		if ( $hours > 0 ) {
			return $hours . ':' . str_pad( (string) $minutes, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( (string) $seconds, 2, '0', STR_PAD_LEFT );
		}

		return $minutes . ':' . str_pad( (string) $seconds, 2, '0', STR_PAD_LEFT );
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
				'channel_id'    => isset( $snippet['channelId'] ) ? (string) $snippet['channelId'] : '',
				'channel_title' => isset( $snippet['channelTitle'] ) ? (string) $snippet['channelTitle'] : '',
				'view_count'    => 0,
			);
		}
		return $videos;
	}

	/**
	 * Sanitize a YouTube video ID for URLs and cache keys.
	 *
	 * @since 6.7.9
	 *
	 * @param string $video_id Raw video ID.
	 * @return string
	 */
	public function sanitize_video_id( $video_id ) {
		$video_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $video_id );
		return substr( (string) $video_id, 0, 64 );
	}

	/**
	 * Sanitize a YouTube comment ID for URLs and cache keys.
	 *
	 * @since 6.7.9
	 *
	 * @param string $comment_id Raw comment ID.
	 * @return string
	 */
	public function sanitize_comment_id( $comment_id ) {
		$comment_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $comment_id );
		return substr( (string) $comment_id, 0, 128 );
	}

	/**
	 * Fetch top-level comment threads for a video.
	 *
	 * Uses commentThreads.list. OAuth tokens must include
	 * `https://www.googleapis.com/auth/youtube.force-ssl` (youtube.readonly is
	 * not accepted by Google for this endpoint). Callers cache + paginate via
	 * pageToken; never call the live API from unit tests.
	 *
	 * @since 6.7.9
	 *
	 * @param string $access_token OAuth access token.
	 * @param string $video_id     YouTube video ID.
	 * @param int    $max_results  Page size (1–50).
	 * @param string $page_token   Optional nextPageToken from a prior response.
	 * @return array|WP_Error { data: array<int,array>, pagination: array{cursor:string} }
	 */
	public function fetch_video_comments( $access_token, $video_id, $max_results = 20, $page_token = '' ) {
		$access_token = is_string( $access_token ) ? trim( $access_token ) : '';
		if ( '' === $access_token ) {
			return new WP_Error(
				'missing_access_token',
				__( 'No access token is stored for this YouTube account. Please reconnect.', 'easy-facebook-likebox' )
			);
		}

		$video_id = $this->sanitize_video_id( $video_id );
		if ( '' === $video_id ) {
			return new WP_Error(
				'invalid_video_id',
				__( 'Invalid YouTube video ID.', 'easy-facebook-likebox' )
			);
		}

		$max_results = max( 1, min( self::MAX_COMMENTS_PAGE, (int) $max_results ) );
		$query_args  = array(
			'part'       => 'snippet,replies',
			'videoId'    => $video_id,
			'maxResults' => $max_results,
			'order'      => 'relevance',
			'textFormat' => 'plainText',
		);
		$page_token  = sanitize_text_field( (string) $page_token );
		if ( '' !== $page_token ) {
			$query_args['pageToken'] = $page_token;
		}

		$url = add_query_arg( $query_args, self::API_BASE_URL . '/commentThreads' );

		/**
		 * Filter the YouTube commentThreads request URL.
		 *
		 * @since 6.7.9
		 *
		 * @param string $url          Final URL.
		 * @param string $video_id     Video ID.
		 * @param int    $max_results  Page size.
		 * @param string $page_token   Page token.
		 */
		$url = (string) apply_filters( 'esf_youtube_comments_request_url', $url, $video_id, $max_results, $page_token );

		return $this->dispatch_comments_request( $access_token, $url );
	}

	/**
	 * Fetch replies for a top-level comment.
	 *
	 * Uses comments.list with parentId = top-level comment id.
	 *
	 * @since 6.7.9
	 *
	 * @param string $access_token OAuth access token.
	 * @param string $comment_id   Parent (top-level) comment ID.
	 * @param int    $max_results  Page size (1–50).
	 * @param string $page_token   Optional nextPageToken.
	 * @return array|WP_Error { data: array<int,array>, pagination: array{cursor:string} }
	 */
	public function fetch_comment_replies( $access_token, $comment_id, $max_results = 10, $page_token = '' ) {
		$access_token = is_string( $access_token ) ? trim( $access_token ) : '';
		if ( '' === $access_token ) {
			return new WP_Error(
				'missing_access_token',
				__( 'No access token is stored for this YouTube account. Please reconnect.', 'easy-facebook-likebox' )
			);
		}

		$comment_id = $this->sanitize_comment_id( $comment_id );
		if ( '' === $comment_id ) {
			return new WP_Error(
				'invalid_comment_id',
				__( 'Invalid YouTube comment ID.', 'easy-facebook-likebox' )
			);
		}

		$max_results = max( 1, min( self::MAX_COMMENTS_PAGE, (int) $max_results ) );
		$query_args  = array(
			'part'       => 'snippet',
			'parentId'   => $comment_id,
			'maxResults' => $max_results,
			'textFormat' => 'plainText',
		);
		$page_token  = sanitize_text_field( (string) $page_token );
		if ( '' !== $page_token ) {
			$query_args['pageToken'] = $page_token;
		}

		$url = add_query_arg( $query_args, self::API_BASE_URL . '/comments' );

		/**
		 * Filter the YouTube comments.list (replies) request URL.
		 *
		 * @since 6.7.9
		 *
		 * @param string $url          Final URL.
		 * @param string $comment_id   Parent comment ID.
		 * @param int    $max_results  Page size.
		 * @param string $page_token   Page token.
		 */
		$url = (string) apply_filters( 'esf_youtube_comment_replies_request_url', $url, $comment_id, $max_results, $page_token );

		return $this->dispatch_comments_request( $access_token, $url );
	}

	/**
	 * Issue an authenticated comments/commentThreads GET and normalize the page.
	 *
	 * @since 6.7.9
	 *
	 * @param string $access_token Bearer token.
	 * @param string $url          Final request URL.
	 * @return array|WP_Error
	 */
	private function dispatch_comments_request( $access_token, $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
					'User-Agent'    => 'EasySocialFeed-YouTube/' . ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0' ),
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'invalid_json',
				__( 'YouTube returned a malformed comments response.', 'easy-facebook-likebox' ),
				array( 'status' => $code )
			);
		}

		if ( isset( $data['error'] ) && is_array( $data['error'] ) ) {
			$error_message = isset( $data['error']['message'] )
				? (string) $data['error']['message']
				: __( 'YouTube API error.', 'easy-facebook-likebox' );
			$reason        = '';
			if ( isset( $data['error']['errors'][0]['reason'] ) ) {
				$reason = (string) $data['error']['errors'][0]['reason'];
			}

			// Comments disabled / private — treat as empty page, not a hard failure.
			if ( in_array( $reason, array( 'commentsDisabled', 'videoNotFound' ), true )
				|| false !== stripos( $error_message, 'disabled comments' )
				|| false !== stripos( $error_message, 'comments are turned off' )
			) {
				return array(
					'data'       => array(),
					'pagination' => array( 'cursor' => '' ),
				);
			}

			// Token lacks youtube.force-ssl — bridge must request it and the
			// account must reconnect. Do not soft-empty this as "no comments".
			if (
				'insufficientPermissions' === $reason
				|| false !== stripos( $error_message, 'insufficient authentication scopes' )
				|| false !== stripos( $error_message, 'insufficient permission' )
			) {
				return new WP_Error(
					'youtube_insufficient_scopes',
					__( 'This YouTube account needs to be reconnected with comment access. Disconnect and connect the channel again from the YouTube Accounts tab.', 'easy-facebook-likebox' ),
					array(
						'status'          => 403,
						'detail'          => $data['error'],
						'reason'          => $reason,
						'needs_reconnect' => true,
					)
				);
			}

			return new WP_Error(
				'youtube_api_error',
				sprintf(
					/* translators: %s: Error message from YouTube API. */
					__( 'YouTube API error: %s', 'easy-facebook-likebox' ),
					$error_message
				),
				array(
					'status' => $code,
					'detail' => $data['error'],
					'reason' => $reason,
				)
			);
		}

		if ( $code >= 400 ) {
			return new WP_Error(
				'youtube_http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'YouTube comments request failed with status %d.', 'easy-facebook-likebox' ),
					$code
				),
				array( 'status' => $code )
			);
		}

		$items  = isset( $data['items'] ) && is_array( $data['items'] ) ? array_values( $data['items'] ) : array();
		$cursor = isset( $data['nextPageToken'] ) ? sanitize_text_field( (string) $data['nextPageToken'] ) : '';

		return array(
			'data'       => $items,
			'pagination' => array(
				'cursor' => $cursor,
			),
		);
	}
}
