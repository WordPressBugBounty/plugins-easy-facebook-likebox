<?php
/**
 * Instagram Graph Media Comments API Service.
 *
 * Fetches comment nodes for a single media post from the Instagram Graph API.
 * Modeled on {@see ESF_Instagram_API_Media} — singleton, WP_Error returns, no
 * internal caching (callers use {@see ESF_Instagram_Cache}).
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/API
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_API_Comments
 *
 * @since 6.9.0
 */
class ESF_Instagram_API_Comments {

	use ESF_Instagram_Singleton;

	/**
	 * Graph version for the business path.
	 *
	 * @var string
	 */
	const FB_API_VERSION = 'v18.0';

	/**
	 * Maximum bytes accepted for a Graph response (4 MB).
	 *
	 * @var int
	 */
	const MAX_RESPONSE_BYTES = 4194304;

	/**
	 * Hard ceiling on the `limit` field.
	 *
	 * @var int
	 */
	const MAX_LIMIT = 50;

	/**
	 * Base comment fields without nested replies.
	 *
	 * @var string
	 */
	const BUSINESS_FIELDS_FALLBACK = 'id,text,timestamp,username,like_count,from{id,username,name}';

	/**
	 * Basic fallback without nested reply fields.
	 *
	 * @var string
	 */
	const BASIC_FIELDS_FALLBACK = 'id,text,timestamp,username,from{id,username,name}';

	/**
	 * Fields returned for a paginated replies request.
	 *
	 * @var string
	 */
	const REPLY_BUSINESS_FIELDS = 'id,text,timestamp,username,like_count,from{id,username,name}';

	/**
	 * Reply fields for Instagram Login accounts.
	 *
	 * @var string
	 */
	const REPLY_BASIC_FIELDS = 'id,text,timestamp,username,from{id,username,name}';

	/**
	 * Default replies fetched per page for on-demand loading.
	 *
	 * @var int
	 */
	const DEFAULT_REPLIES_PER_PAGE = 10;

	/**
	 * Fetch comments for a media node.
	 *
	 * @param object $account_row  Account DB row.
	 * @param string $media_id     Instagram media ID.
	 * @param int    $limit                 Max items (clamped to MAX_LIMIT).
	 * @param string $after_cursor          Optional pagination cursor.
	 * @param int    $replies_preview_limit Reply preview/page size from feed settings.
	 *
	 * @return array|WP_Error { data: array<int,array>, pagination: array{cursor:string} }
	 */
	public function fetch_comments( $account_row, $media_id, $limit = 20, $after_cursor = '', $replies_preview_limit = 1 ) {
		if ( ! is_object( $account_row ) ) {
			return new WP_Error( 'invalid_account', __( 'Invalid Instagram account.', 'easy-facebook-likebox' ) );
		}

		$media_id = $this->sanitize_media_id( $media_id );
		if ( '' === $media_id ) {
			return new WP_Error( 'invalid_media_id', __( 'Invalid Instagram media ID.', 'easy-facebook-likebox' ) );
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
		$replies_preview_limit = max( 1, min( self::MAX_LIMIT, (int) $replies_preview_limit ) );

		$field_attempts = array(
			array(
				'with_summary' => true,
				'limit'        => $replies_preview_limit,
			),
			array(
				'with_summary' => false,
				'limit'        => $replies_preview_limit,
			),
			array(
				'with_summary' => null,
				'limit'        => 0,
			),
		);

		$result  = null;
		$last_url = '';

		foreach ( $field_attempts as $attempt ) {
			if ( null === $attempt['with_summary'] ) {
				$fields = 'instagram_login' === $auth_source ? self::BASIC_FIELDS_FALLBACK : self::BUSINESS_FIELDS_FALLBACK;
			} elseif ( 'instagram_login' === $auth_source ) {
				$fields = $this->build_basic_fields_with_replies( (int) $attempt['limit'], (bool) $attempt['with_summary'] );
			} else {
				$fields = $this->build_business_fields_with_replies( (int) $attempt['limit'], (bool) $attempt['with_summary'] );
			}

			if ( 'instagram_login' === $auth_source ) {
				$last_url = $this->build_basic_url( $media_id, $token, $limit, $after_cursor, $fields );
			} else {
				$last_url = $this->build_business_url( $media_id, $token, $limit, $after_cursor, $fields );
			}

			/**
			 * Filter the Graph API URL before a comments request is dispatched.
			 *
			 * @param string $url         Final URL with query string.
			 * @param object $account_row Account DB row.
			 * @param string $media_id    Media ID.
			 * @param int    $limit       Effective limit.
			 * @param string $auth_source Auth source.
			 */
			$last_url = (string) apply_filters( 'esf_instagram_comments_request_url', $last_url, $account_row, $media_id, $limit, $auth_source );

			$result = $this->dispatch( $last_url, $auth_source, $account_row );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! $this->is_replies_field_error( $result ) ) {
				return $result;
			}
		}

		return $result instanceof WP_Error ? $result : new WP_Error(
			'instagram_api_error',
			__( 'Instagram comments request failed.', 'easy-facebook-likebox' )
		);
	}

	/**
	 * Fetch replies for a single comment node.
	 *
	 * @param object $account_row  Account DB row.
	 * @param string $comment_id   Instagram comment ID.
	 * @param int    $limit        Max items (clamped to MAX_LIMIT).
	 * @param string $after_cursor Optional pagination cursor.
	 *
	 * @return array|WP_Error { data: array<int,array>, pagination: array{cursor:string} }
	 */
	public function fetch_replies( $account_row, $comment_id, $limit = 10, $after_cursor = '' ) {
		if ( ! is_object( $account_row ) ) {
			return new WP_Error( 'invalid_account', __( 'Invalid Instagram account.', 'easy-facebook-likebox' ) );
		}

		$comment_id = $this->sanitize_media_id( $comment_id );
		if ( '' === $comment_id ) {
			return new WP_Error( 'invalid_comment_id', __( 'Invalid Instagram comment ID.', 'easy-facebook-likebox' ) );
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
			$url = $this->build_basic_replies_url( $comment_id, $token, $limit, $after_cursor );
		} else {
			$url = $this->build_business_replies_url( $comment_id, $token, $limit, $after_cursor );
		}

		/**
		 * Filter the Graph API URL before a replies request is dispatched.
		 *
		 * @param string $url         Final URL with query string.
		 * @param object $account_row Account DB row.
		 * @param string $comment_id  Comment ID.
		 * @param int    $limit       Effective limit.
		 * @param string $auth_source Auth source.
		 */
		$url = (string) apply_filters( 'esf_instagram_comment_replies_request_url', $url, $account_row, $comment_id, $limit, $auth_source );

		return $this->dispatch( $url, $auth_source, $account_row );
	}

	/**
	 * Sanitize a Graph media ID for use in URLs and cache keys.
	 *
	 * @param string $media_id Raw media ID.
	 * @return string
	 */
	public function sanitize_media_id( $media_id ) {
		$media_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $media_id );
		return substr( (string) $media_id, 0, 64 );
	}

	/**
	 * Pick the access token for the auth source.
	 *
	 * @param object $row         Account row.
	 * @param string $auth_source Auth source string.
	 * @return string
	 */
	private function resolve_access_token( $row, $auth_source ) {
		if ( 'instagram_login' === $auth_source ) {
			return trim( isset( $row->access_token ) ? (string) $row->access_token : '' );
		}

		$primary  = trim( isset( $row->page_access_token ) ? (string) $row->page_access_token : '' );
		$fallback = trim( isset( $row->access_token ) ? (string) $row->access_token : '' );
		return '' !== $primary ? $primary : $fallback;
	}

	/**
	 * Build the business Graph comments URL.
	 *
	 * @param string $media_id     Media ID.
	 * @param string $token        Access token.
	 * @param int    $limit        Page size.
	 * @param string $after_cursor Pagination cursor.
	 * @param string $fields       Optional Graph fields override.
	 * @return string
	 */
	private function build_business_url( $media_id, $token, $limit, $after_cursor, $fields = null ) {
		$args = array(
			'fields'       => null !== $fields ? (string) $fields : self::BUSINESS_FIELDS_FALLBACK,
			'limit'        => (int) $limit,
			'access_token' => $token,
		);
		if ( '' !== $after_cursor ) {
			$args['after'] = $after_cursor;
		}

		$base = 'https://graph.facebook.com/' . self::FB_API_VERSION . '/' . rawurlencode( $media_id ) . '/comments';
		return add_query_arg( $args, $base );
	}

	/**
	 * Build the Instagram Login comments URL.
	 *
	 * @param string $media_id     Media ID.
	 * @param string $token        Access token.
	 * @param int    $limit        Page size.
	 * @param string $after_cursor Pagination cursor.
	 * @param string $fields       Optional Graph fields override.
	 * @return string
	 */
	private function build_basic_url( $media_id, $token, $limit, $after_cursor, $fields = null ) {
		$args = array(
			'fields'       => null !== $fields ? (string) $fields : self::BASIC_FIELDS_FALLBACK,
			'limit'        => (int) $limit,
			'access_token' => $token,
		);
		if ( '' !== $after_cursor ) {
			$args['after'] = $after_cursor;
		}

		$base = 'https://graph.instagram.com/' . rawurlencode( $media_id ) . '/comments';
		return add_query_arg( $args, $base );
	}

	/**
	 * Build the business Graph replies URL.
	 *
	 * @param string $comment_id   Comment ID.
	 * @param string $token        Access token.
	 * @param int    $limit        Page size.
	 * @param string $after_cursor Pagination cursor.
	 * @return string
	 */
	private function build_business_replies_url( $comment_id, $token, $limit, $after_cursor ) {
		$args = array(
			'fields'       => self::REPLY_BUSINESS_FIELDS,
			'limit'        => (int) $limit,
			'access_token' => $token,
		);
		if ( '' !== $after_cursor ) {
			$args['after'] = $after_cursor;
		}

		$base = 'https://graph.facebook.com/' . self::FB_API_VERSION . '/' . rawurlencode( $comment_id ) . '/replies';
		return add_query_arg( $args, $base );
	}

	/**
	 * Build the Instagram Login replies URL.
	 *
	 * @param string $comment_id   Comment ID.
	 * @param string $token        Access token.
	 * @param int    $limit        Page size.
	 * @param string $after_cursor Pagination cursor.
	 * @return string
	 */
	private function build_basic_replies_url( $comment_id, $token, $limit, $after_cursor ) {
		$args = array(
			'fields'       => self::REPLY_BASIC_FIELDS,
			'limit'        => (int) $limit,
			'access_token' => $token,
		);
		if ( '' !== $after_cursor ) {
			$args['after'] = $after_cursor;
		}

		$base = 'https://graph.instagram.com/' . rawurlencode( $comment_id ) . '/replies';
		return add_query_arg( $args, $base );
	}

	/**
	 * Issue the HTTP request and normalize the response.
	 *
	 * @param string      $url         Final URL.
	 * @param string      $auth_source Auth source for error context.
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
		$cursor = isset( $decoded['paging']['cursors']['after'] ) ? (string) $decoded['paging']['cursors']['after'] : '';

		return array(
			'data'       => $nodes,
			'pagination' => array(
				'cursor' => $cursor,
			),
		);
	}

	/**
	 * Clamp limit to a sensible range.
	 *
	 * @param mixed $limit Raw limit.
	 * @return int
	 */
	private function clamp_limit( $limit ) {
		$limit = (int) $limit;
		if ( $limit < 1 ) {
			return 20;
		}
		if ( $limit > self::MAX_LIMIT ) {
			return self::MAX_LIMIT;
		}
		return $limit;
	}

	/**
	 * Build business comment fields with a nested replies preview edge.
	 *
	 * @param int  $replies_limit Nested replies page size.
	 * @param bool $with_summary  Whether to request summary total_count.
	 * @return string
	 */
	private function build_business_fields_with_replies( $replies_limit, $with_summary ) {
		$limit = max( 1, min( self::MAX_LIMIT, (int) $replies_limit ) );
		$base  = 'id,text,timestamp,username,like_count,from{id,username,name}';
		$reply = 'id,text,timestamp,username,like_count,from{id,username,name}';
		$edge  = $with_summary
			? 'replies.summary(true).limit(' . $limit . '){' . $reply . '}'
			: 'replies.limit(' . $limit . '){' . $reply . '}';

		return $base . ',' . $edge;
	}

	/**
	 * Build Instagram Login comment fields with a nested replies preview edge.
	 *
	 * @param int  $replies_limit Nested replies page size.
	 * @param bool $with_summary  Whether to request summary total_count.
	 * @return string
	 */
	private function build_basic_fields_with_replies( $replies_limit, $with_summary ) {
		$limit = max( 1, min( self::MAX_LIMIT, (int) $replies_limit ) );
		$base  = 'id,text,timestamp,username,from{id,username,name}';
		$reply = 'id,text,timestamp,username,from{id,username,name}';
		$edge  = $with_summary
			? 'replies.summary(true).limit(' . $limit . '){' . $reply . '}'
			: 'replies.limit(' . $limit . '){' . $reply . '}';

		return $base . ',' . $edge;
	}

	/**
	 * Whether a Graph error likely came from unsupported reply summary fields.
	 *
	 * @param WP_Error $error Error from dispatch().
	 * @return bool
	 */
	private function is_replies_field_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$message = strtolower( (string) $error->get_error_message() );
		if ( '' === $message ) {
			return false;
		}

		$needles = array( 'replies', 'summary', 'field', 'fields', 'syntax', 'unknown' );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
