<?php
/**
 * Facebook Graph post comments API service.
 *
 * Fetches comment nodes for a Page post. Returns WP_Error on failure.
 * Callers own caching via ESF_Facebook_Cache.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/API
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_API_Comments
 *
 * @since 6.7.9
 */
class ESF_Facebook_API_Comments {

	use ESF_Facebook_Singleton;

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
	 * Comment fields without nested replies (fallback).
	 *
	 * @var string
	 */
	const FIELDS_FALLBACK = 'id,message,created_time,from{id,name,picture},like_count,permalink_url,attachment';

	/**
	 * Minimal comment fields (last-resort fallback when Graph rejects nested from/attachment).
	 *
	 * @var string
	 */
	const FIELDS_MINIMAL = 'id,message,created_time,like_count,permalink_url';

	/**
	 * Reply fields for `/{comment-id}/comments`.
	 *
	 * @var string
	 */
	const REPLY_FIELDS = 'id,message,created_time,from{id,name,picture},like_count,permalink_url,attachment';

	/**
	 * Minimal reply fields.
	 *
	 * @var string
	 */
	const REPLY_FIELDS_MINIMAL = 'id,message,created_time,like_count,permalink_url';

	/**
	 * Default replies fetched per page for on-demand loading.
	 *
	 * @var int
	 */
	const DEFAULT_REPLIES_PER_PAGE = 10;

	/**
	 * Fetch comments for a post / object node.
	 *
	 * @param string $page_access_token     Page access token.
	 * @param string $object_id             Facebook post / object ID.
	 * @param int    $limit                 Max items (clamped to MAX_LIMIT).
	 * @param string $after_cursor          Optional pagination cursor.
	 * @param int    $replies_preview_limit Nested replies preview size.
	 *
	 * @return array|WP_Error { data: array<int,array>, pagination: array{cursor:string} }
	 */
	public function fetch_comments( $page_access_token, $object_id, $limit = 20, $after_cursor = '', $replies_preview_limit = 3 ) {
		$page_access_token = trim( (string) $page_access_token );
		if ( '' === $page_access_token ) {
			return new WP_Error(
				'missing_access_token',
				__( 'No page access token is available for this feed. Please reconnect the Page.', 'easy-facebook-likebox' )
			);
		}

		$object_id = $this->sanitize_object_id( $object_id );
		if ( '' === $object_id ) {
			return new WP_Error( 'invalid_object_id', __( 'Invalid Facebook post ID.', 'easy-facebook-likebox' ) );
		}

		$limit                 = $this->clamp_limit( $limit );
		$replies_preview_limit = max( 1, min( self::MAX_LIMIT, (int) $replies_preview_limit ) );

		$field_attempts = array(
			$this->build_fields_with_replies( $replies_preview_limit ),
			self::FIELDS_FALLBACK,
			'id,message,created_time,from{id,name},like_count,permalink_url',
			self::FIELDS_MINIMAL,
		);

		$last_error = new WP_Error(
			'facebook_api_error',
			__( 'Facebook comments request failed.', 'easy-facebook-likebox' )
		);

		foreach ( $field_attempts as $fields ) {
			$url = $this->build_comments_url( $object_id, $page_access_token, $limit, $after_cursor, $fields );

			/**
			 * Filter the Graph API URL before a Facebook comments request is dispatched.
			 *
			 * @param string $url                Final URL with query string.
			 * @param string $object_id          Sanitized object ID.
			 * @param int    $limit              Effective limit.
			 * @param string $page_access_token  Page access token.
			 */
			$url = (string) apply_filters( 'esf_facebook_comments_request_url', $url, $object_id, $limit, $page_access_token );

			$result = $this->dispatch( $url );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			$last_error = $result;
			// Keep trying simpler field sets for field/syntax errors; stop early on auth/token failures.
			if ( ! $this->should_retry_with_simpler_fields( $result ) ) {
				return $result;
			}
		}

		return $last_error;
	}

	/**
	 * Fetch replies for a single comment node.
	 *
	 * Facebook nests replies on the `comments` edge: `GET /{comment-id}/comments`.
	 *
	 * @param string $page_access_token Page access token.
	 * @param string $comment_id        Facebook comment ID.
	 * @param int    $limit             Max items (clamped to MAX_LIMIT).
	 * @param string $after_cursor      Optional pagination cursor.
	 *
	 * @return array|WP_Error { data: array<int,array>, pagination: array{cursor:string} }
	 */
	public function fetch_replies( $page_access_token, $comment_id, $limit = 10, $after_cursor = '' ) {
		$page_access_token = trim( (string) $page_access_token );
		if ( '' === $page_access_token ) {
			return new WP_Error(
				'missing_access_token',
				__( 'No page access token is available for this feed. Please reconnect the Page.', 'easy-facebook-likebox' )
			);
		}

		$comment_id = $this->sanitize_object_id( $comment_id );
		if ( '' === $comment_id ) {
			return new WP_Error( 'invalid_comment_id', __( 'Invalid Facebook comment ID.', 'easy-facebook-likebox' ) );
		}

		$limit = $this->clamp_limit( $limit );

		$field_attempts = array(
			self::REPLY_FIELDS,
			'id,message,created_time,from{id,name},like_count,permalink_url',
			self::REPLY_FIELDS_MINIMAL,
		);

		$last_error = new WP_Error(
			'facebook_api_error',
			__( 'Facebook comment replies request failed.', 'easy-facebook-likebox' )
		);

		foreach ( $field_attempts as $fields ) {
			$url = $this->build_replies_url( $comment_id, $page_access_token, $limit, $after_cursor, $fields );

			/**
			 * Filter the Graph API URL before a Facebook replies request is dispatched.
			 *
			 * @param string $url                Final URL with query string.
			 * @param string $comment_id         Sanitized comment ID.
			 * @param int    $limit              Effective limit.
			 * @param string $page_access_token  Page access token.
			 */
			$url = (string) apply_filters( 'esf_facebook_comment_replies_request_url', $url, $comment_id, $limit, $page_access_token );

			$result = $this->dispatch( $url );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			$last_error = $result;
			if ( ! $this->should_retry_with_simpler_fields( $result ) ) {
				return $result;
			}
		}

		return $last_error;
	}

	/**
	 * Sanitize a Graph object / comment ID (digits and underscore only).
	 *
	 * @param string $id Raw ID.
	 * @return string
	 */
	public function sanitize_object_id( $id ) {
		$id = preg_replace( '/[^0-9_]/', '', (string) $id );
		return substr( (string) $id, 0, 128 );
	}

	/**
	 * Build the Graph comments URL for a post.
	 *
	 * @param string $object_id        Object ID.
	 * @param string $token            Page access token.
	 * @param int    $limit            Page size.
	 * @param string $after_cursor     Pagination cursor.
	 * @param string $fields           Graph fields.
	 * @return string
	 */
	private function build_comments_url( $object_id, $token, $limit, $after_cursor, $fields ) {
		$args = array(
			'fields'       => (string) $fields,
			'limit'        => (int) $limit,
			'order'        => 'reverse_chronological',
			'access_token' => $token,
		);
		if ( '' !== $after_cursor ) {
			$args['after'] = $after_cursor;
		}

		$base = ESF_Facebook_Graph_Client::graph_url( $object_id . '/comments' );
		return add_query_arg( $args, $base );
	}

	/**
	 * Build the Graph replies URL for a comment.
	 *
	 * @param string $comment_id   Comment ID.
	 * @param string $token        Page access token.
	 * @param int    $limit        Page size.
	 * @param string $after_cursor Pagination cursor.
	 * @param string $fields       Graph fields.
	 * @return string
	 */
	private function build_replies_url( $comment_id, $token, $limit, $after_cursor, $fields = '' ) {
		$args = array(
			'fields'       => '' !== (string) $fields ? (string) $fields : self::REPLY_FIELDS,
			'limit'        => (int) $limit,
			'order'        => 'reverse_chronological',
			'access_token' => $token,
		);
		if ( '' !== $after_cursor ) {
			$args['after'] = $after_cursor;
		}

		$base = ESF_Facebook_Graph_Client::graph_url( $comment_id . '/comments' );
		return add_query_arg( $args, $base );
	}

	/**
	 * Comment fields with a nested comments preview edge.
	 *
	 * @param int $replies_limit Nested replies page size.
	 * @return string
	 */
	private function build_fields_with_replies( $replies_limit ) {
		$limit = max( 1, min( self::MAX_LIMIT, (int) $replies_limit ) );
		$reply = 'id,message,created_time,from{id,name,picture},like_count,permalink_url,attachment';

		return 'id,message,created_time,from{id,name,picture},like_count,comment_count,permalink_url,attachment,comments.limit(' . $limit . ').summary(true){' . $reply . '}';
	}

	/**
	 * Issue the HTTP request and normalize the response.
	 *
	 * @param string $url Final URL.
	 * @return array|WP_Error
	 */
	private function dispatch( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'EasySocialFeed-Facebook/' . ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( class_exists( 'ESF_Meta_Graph_Error' ) ) {
				$action = ESF_Meta_Graph_Error::classify_wp_error( $response );
				$data   = $response->get_error_data();
				if ( ! is_array( $data ) ) {
					$data = array();
				}
				$data['esf_graph_action'] = $action;
				return new WP_Error( $response->get_error_code(), $response->get_error_message(), $data );
			}
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return new WP_Error(
				'response_too_large',
				__( 'Facebook returned a response larger than the maximum allowed size.', 'easy-facebook-likebox' )
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'invalid_json',
				__( 'Facebook returned a malformed response.', 'easy-facebook-likebox' ),
				array( 'status' => $code )
			);
		}

		if ( isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			$message = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : __( 'Facebook API error.', 'easy-facebook-likebox' );
			$data    = array(
				'status' => $code,
				'detail' => $decoded['error'],
			);
			if ( class_exists( 'ESF_Meta_Graph_Error' ) ) {
				$data['esf_graph_action'] = ESF_Meta_Graph_Error::classify( $decoded['error'] );
			}

			return new WP_Error( 'facebook_api_error', $message, $data );
		}

		if ( $code >= 400 ) {
			return new WP_Error(
				'facebook_http_error',
				/* translators: %d: HTTP response status code returned by Facebook. */
				sprintf( __( 'Facebook API request failed with status %d.', 'easy-facebook-likebox' ), $code ),
				array( 'status' => $code )
			);
		}

		$nodes    = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? array_values( $decoded['data'] ) : array();
		$has_next = isset( $decoded['paging']['next'] ) && is_string( $decoded['paging']['next'] ) && '' !== trim( (string) $decoded['paging']['next'] );
		// Only expose a cursor when Graph advertises another page. `cursors.after`
		// alone is often present on the final page and must not imply has_more.
		$cursor = '';
		if ( $has_next && isset( $decoded['paging']['cursors']['after'] ) ) {
			$cursor = (string) $decoded['paging']['cursors']['after'];
		}

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
	 * Whether we should retry the Graph request with a simpler fields list.
	 *
	 * Retries on field/syntax/unknown-field style errors. Does not retry
	 * auth/token failures that simpler fields cannot fix.
	 *
	 * @param mixed $error Error from dispatch().
	 * @return bool
	 */
	private function should_retry_with_simpler_fields( $error ): bool {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$code = (string) $error->get_error_code();
		if ( in_array( $code, array( 'missing_access_token', 'invalid_object_id', 'invalid_comment_id' ), true ) ) {
			return false;
		}

		$message = strtolower( (string) $error->get_error_message() );
		if ( '' === $message ) {
			return false;
		}

		// Hard OAuth / token failures — do not burn more Graph calls.
		$auth_needles = array(
			'access token',
			'oauthexception',
			'(#190)',
			'error validating access token',
			'invalid oauth',
			'session has expired',
			'token is invalid',
		);
		foreach ( $auth_needles as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return false;
			}
		}

		$needles = array(
			'comments',
			'summary',
			'field',
			'fields',
			'syntax',
			'unknown',
			'nonexisting',
			'attachment',
			'picture',
			'from',
			'unsupported',
			'invalid parameter',
		);
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		// Default: retry once through simpler field sets for ambiguous Graph errors.
		return true;
	}
}
