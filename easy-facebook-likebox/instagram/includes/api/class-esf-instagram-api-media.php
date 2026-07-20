<?php
/**
 * Instagram Graph Media API Service.
 *
 * Fetches Instagram media nodes (the raw input for {@see PostMapper}) using:
 *  - Instagram Business API (Page-backed) → `graph.facebook.com/{ig_id}/media`
 *  - Instagram Login (Basic Display successor) → `graph.instagram.com/{ig_id}/media`
 *
 * Modeled on {@see ESF_Twitter_API_Service} so all modules share the same
 * service shape:
 *  - Singleton instance.
 *  - WP_Error returns for callers to handle uniformly.
 *  - Bytes-bounded responses, validation of decoded payloads.
 *
 * The service does NOT perform any caching itself; that is the caller's job
 * via {@see ESF_Instagram_Cache}. Callers therefore stay in full control of
 * cache keys (account-scoped media rows and TTLs).
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/API
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_API_Media
 *
 * @since 6.9.0
 */
class ESF_Instagram_API_Media {

	use ESF_Instagram_Singleton;

	/**
	 * Graph version for the business path. Aligned with {@see ESF_Instagram_Facebook_Graph}.
	 *
	 * @var string
	 */
	const FB_API_VERSION = 'v18.0';

	/**
	 * Maximum bytes accepted for a Graph response (8 MB).
	 *
	 * @var int
	 */
	const MAX_RESPONSE_BYTES = 8388608;

	/**
	 * Hard ceiling on the `limit` field accepted by the Graph endpoints.
	 *
	 * @var int
	 */
	const MAX_LIMIT = 100;

	/**
	 * Fields requested when the IG account is owned via a Facebook Page.
	 *
	 * @var string
	 */
	const BUSINESS_FIELDS = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count,children{id,media_type,media_url,thumbnail_url,permalink,timestamp}';

	/**
	 * Fields requested when the IG account is owned via Instagram Login.
	 *
	 * `like_count` and `comments_count` are unavailable on the basic endpoint;
	 * callers should fall back to `0` for those metrics.
	 *
	 * @var string
	 */
	const BASIC_FIELDS = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,username,children{id,media_type,media_url,thumbnail_url,permalink,timestamp}';

	/**
	 * Fetch a user's recent media for an account row from `wp_esf_instagram_accounts`.
	 *
	 * @param object $account_row   Account DB row.
	 * @param int    $limit         Max items to request (clamped to MAX_LIMIT).
	 * @param string $after_cursor  Optional pagination cursor.
	 *
	 * @return array|WP_Error  { data: array<int,array>, pagination: array{cursor:string, next_url:string} }
	 */
	public function fetch_user_media( $account_row, $limit = 25, $after_cursor = '' ) {
		if ( ! is_object( $account_row ) ) {
			return new WP_Error( 'invalid_account', __( 'Invalid Instagram account.', 'easy-facebook-likebox' ) );
		}

		$instagram_user_id = isset( $account_row->instagram_user_id ) ? (string) $account_row->instagram_user_id : '';
		if ( '' === $instagram_user_id ) {
			return new WP_Error( 'missing_ig_id', __( 'Instagram account is missing its user ID.', 'easy-facebook-likebox' ) );
		}

		$auth_source = isset( $account_row->auth_source ) ? (string) $account_row->auth_source : 'facebook_page';
		$token       = $this->resolve_access_token( $account_row, $auth_source );
		if ( '' === $token ) {
			return new WP_Error(
				'missing_access_token',
				__( 'No access token is stored for this Instagram account. Please reconnect.', 'easy-facebook-likebox' )
			);
		}

		$limit = $this->clamp_limit( $limit );

		if ( 'instagram_login' === $auth_source ) {
			$url = $this->build_basic_url( $instagram_user_id, $token, $limit, $after_cursor );
		} else {
			$url = $this->build_business_url( $instagram_user_id, $token, $limit, $after_cursor );
		}

		/**
		 * Filter the Graph API URL just before the request is dispatched.
		 *
		 * Allows tests and integrators to swap the endpoint without monkey-patching wp_remote_get().
		 *
		 * @param string $url         Final URL with query string.
		 * @param object $account_row Account DB row.
		 * @param int    $limit       Effective limit.
		 * @param string $auth_source 'facebook_page' or 'instagram_login'.
		 */
		$url = (string) apply_filters( 'esf_instagram_media_request_url', $url, $account_row, $limit, $auth_source );

		return $this->dispatch( $url, $auth_source, $account_row );
	}

	/**
	 * Pick the right token for the auth source, with a sensible fallback.
	 *
	 * @param object $row         Account row.
	 * @param string $auth_source Auth source string.
	 * @return string
	 */
	private function resolve_access_token( $row, $auth_source ) {
		if ( 'instagram_login' === $auth_source ) {
			$token = isset( $row->access_token ) ? (string) $row->access_token : '';
			return trim( $token );
		}

		$primary  = isset( $row->page_access_token ) ? (string) $row->page_access_token : '';
		$fallback = isset( $row->access_token ) ? (string) $row->access_token : '';
		$primary  = trim( $primary );
		return '' !== $primary ? $primary : trim( $fallback );
	}

	/**
	 * Build the Graph URL for Page-backed (business / creator) accounts.
	 *
	 * @param string $ig_id        Instagram user ID.
	 * @param string $token        Page access token.
	 * @param int    $limit        Number of items.
	 * @param string $after_cursor Optional pagination cursor.
	 * @return string
	 */
	private function build_business_url( $ig_id, $token, $limit, $after_cursor ) {
		$args = array(
			'fields'       => self::BUSINESS_FIELDS,
			'limit'        => (int) $limit,
			'access_token' => $token,
		);
		if ( '' !== $after_cursor ) {
			$args['after'] = $after_cursor;
		}

		$base = 'https://graph.facebook.com/' . self::FB_API_VERSION . '/' . rawurlencode( $ig_id ) . '/media';
		return add_query_arg( $args, $base );
	}

	/**
	 * Build the Graph URL for Instagram Login (basic) accounts.
	 *
	 * @param string $ig_id        Instagram user ID.
	 * @param string $token        IG user access token.
	 * @param int    $limit        Number of items.
	 * @param string $after_cursor Optional pagination cursor.
	 * @return string
	 */
	private function build_basic_url( $ig_id, $token, $limit, $after_cursor ) {
		$args = array(
			'fields'       => self::BASIC_FIELDS,
			'limit'        => (int) $limit,
			'access_token' => $token,
		);
		if ( '' !== $after_cursor ) {
			$args['after'] = $after_cursor;
		}

		$base = 'https://graph.instagram.com/' . rawurlencode( $ig_id ) . '/media';
		return add_query_arg( $args, $base );
	}

	/**
	 * Issue the HTTP request and return a normalized data/pagination array.
	 *
	 * @param string      $url         Final URL.
	 * @param string      $auth_source Used for clearer error messages.
	 * @param object|null $account_row Optional account row for reconnect side effects.
	 * @return array|WP_Error
	 */
	private function dispatch( $url, $auth_source, $account_row = null ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'EasySocialFeed-Instagram/' . ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return new WP_Error(
				'response_too_large',
				__( 'Instagram returned a response larger than the maximum allowed size.', 'easy-facebook-likebox' )
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'invalid_json',
				__( 'Instagram returned a malformed response.', 'easy-facebook-likebox' ),
				array( 'status' => $code )
			);
		}

		if ( isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			if ( function_exists( 'esf_instagram_maybe_mark_account_reconnect_from_graph_error' ) ) {
				esf_instagram_maybe_mark_account_reconnect_from_graph_error( $account_row, $decoded['error'] );
			}

			$message        = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : __( 'Instagram API error.', 'easy-facebook-likebox' );
			$classification = class_exists( 'ESF_Meta_Graph_Error' )
				? ESF_Meta_Graph_Error::classify( $decoded['error'] )
				: '';

			return new WP_Error(
				'instagram_api_error',
				$message,
				array(
					'status'         => $code,
					'auth_source'    => $auth_source,
					'detail'         => $decoded['error'],
					'classification' => $classification,
				)
			);
		}

		if ( $code >= 400 ) {
			return new WP_Error(
				'instagram_http_error',
				/* translators: %d: HTTP response status code returned by Instagram. */
				sprintf( __( 'Instagram API request failed with status %d.', 'easy-facebook-likebox' ), $code ),
				array( 'status' => $code )
			);
		}

		$nodes = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? array_values( $decoded['data'] ) : array();

		$cursor   = isset( $decoded['paging']['cursors']['after'] ) ? (string) $decoded['paging']['cursors']['after'] : '';
		$next_url = isset( $decoded['paging']['next'] ) ? (string) $decoded['paging']['next'] : '';

		return array(
			'data'       => $nodes,
			'pagination' => array(
				'cursor'   => $cursor,
				'next_url' => $next_url,
			),
		);
	}

	/**
	 * Clamp the limit to a sensible range. Graph endpoints accept up to 100.
	 *
	 * @param mixed $limit Raw limit value.
	 * @return int
	 */
	private function clamp_limit( $limit ) {
		$limit = (int) $limit;
		if ( $limit < 1 ) {
			return 25;
		}
		if ( $limit > self::MAX_LIMIT ) {
			return self::MAX_LIMIT;
		}
		return $limit;
	}
}
