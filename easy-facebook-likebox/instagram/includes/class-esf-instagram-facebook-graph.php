<?php
/**
 * Facebook Graph helpers for Instagram Business (Page-backed) OAuth.
 *
 * Used after a user access token is obtained from the external bridge to list
 * Facebook Pages that expose an Instagram Business/Creator account.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Low-level Graph API calls for Page + Instagram discovery.
 *
 * @since 6.8.0
 */
class ESF_Instagram_Facebook_Graph {

	/**
	 * Graph version aligned with {@see ESF_Instagram_Migrator} Instagram node requests.
	 *
	 * @var string
	 */
	const API_VERSION = 'v18.0';

	/**
	 * Graph API version for Facebook requests (filterable).
	 *
	 * @since 6.8.0
	 * @return string Version segment, e.g. `v18.0`.
	 */
	public static function get_api_version() {
		$v = apply_filters( 'esf_instagram_facebook_graph_api_version', self::API_VERSION );
		$v = is_string( $v ) ? trim( $v ) : self::API_VERSION;
		if ( '' === $v || ! preg_match( '/^v\d+(\.\d+)*$/', $v ) ) {
			return self::API_VERSION;
		}
		return $v;
	}

	/**
	 * Ordered `fields` values for `GET me/accounts` (try safest first).
	 *
	 * Some nested Instagram field names (notably `followers_count` on the Page edge) can
	 * make the whole `me/accounts` request fail with HTTP 400 on certain app/token setups.
	 *
	 * @since 6.8.0
	 * @return array<int,string>
	 */
	private static function get_me_accounts_field_candidates() {
		$defaults = array(
			'id,name,access_token,instagram_business_account{id,username,name,profile_picture_url}',
			'id,name,access_token,instagram_business_account',
			'id,name,access_token,instagram_business_account{id,username,ig_id,name,profile_picture_url,biography,website,media_count,account_type}',
		);

		$filtered = apply_filters( 'esf_instagram_facebook_me_accounts_fields', $defaults );
		if ( ! is_array( $filtered ) || array() === $filtered ) {
			return $defaults;
		}

		$out = array();
		foreach ( $filtered as $row ) {
			if ( is_string( $row ) && '' !== trim( $row ) ) {
				$out[] = trim( $row );
			}
		}

		return array() !== $out ? $out : $defaults;
	}

	/**
	 * Parse one `me/accounts` JSON payload into normalized candidate rows (secrets retained).
	 *
	 * @since 6.8.0
	 * @param array<string,mixed> $decoded Top-level JSON array (must include `data` list).
	 * @return array<int,array<string,mixed>> Candidate rows; empty if none with Instagram.
	 */
	public static function parse_me_accounts_response( array $decoded ) {
		$rows   = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();
		$out    = array();
		foreach ( $rows as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$page_id    = isset( $page['id'] ) ? sanitize_text_field( (string) $page['id'] ) : '';
			$page_name  = isset( $page['name'] ) ? sanitize_text_field( (string) $page['name'] ) : '';
			$page_token = isset( $page['access_token'] ) ? sanitize_text_field( (string) $page['access_token'] ) : '';
			$ig_raw     = isset( $page['instagram_business_account'] ) ? $page['instagram_business_account'] : null;
			if ( '' === $page_id || '' === $page_token || null === $ig_raw ) {
				continue;
			}
			$ig = is_array( $ig_raw ) ? $ig_raw : json_decode( wp_json_encode( $ig_raw ), true );
			if ( ! is_array( $ig ) ) {
				continue;
			}
			$ig_id = isset( $ig['id'] ) ? sanitize_text_field( (string) $ig['id'] ) : '';
			if ( '' === $ig_id ) {
				continue;
			}
			$username = isset( $ig['username'] ) ? sanitize_text_field( (string) $ig['username'] ) : '';
			$name     = isset( $ig['name'] ) ? sanitize_text_field( (string) $ig['name'] ) : '';
			$display  = '' !== $name ? $name : ( '' !== $username ? $username : $ig_id );
			$pic      = isset( $ig['profile_picture_url'] ) ? esc_url_raw( (string) $ig['profile_picture_url'] ) : '';

			$out[] = array(
				'page_id'             => $page_id,
				'page_name'           => $page_name,
				'page_access_token'   => $page_token,
				'instagram_user_id'   => $ig_id,
				'username'            => $username,
				'display_name'        => $display,
				'profile_image_url'   => $pic,
				'instagram_account'   => $ig,
			);
		}

		return $out;
	}

	/**
	 * GET `me` id for the user represented by the token.
	 *
	 * @since 6.8.0
	 * @param string $user_access_token User access token.
	 * @return string|WP_Error Facebook user id or error.
	 */
	public static function fetch_facebook_user_id( $user_access_token ) {
		$user_access_token = trim( (string) $user_access_token );
		if ( '' === $user_access_token ) {
			return new WP_Error( 'esf_ig_fb_empty_token', __( 'Missing Facebook user token.', 'easy-facebook-likebox' ) );
		}

		$url = add_query_arg(
			array(
				'fields'       => 'id',
				'access_token' => $user_access_token,
			),
			self::graph_url( 'me' )
		);

		$response = wp_remote_get( $url, array( 'timeout' => 25 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return new WP_Error(
				'esf_ig_fb_me_failed',
				__( 'Could not read Facebook profile for this token.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || isset( $body['error'] ) || empty( $body['id'] ) ) {
			return new WP_Error(
				'esf_ig_fb_me_invalid',
				__( 'Facebook returned an unexpected response for this token.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		return sanitize_text_field( (string) $body['id'] );
	}

	/**
	 * Paginate `me/accounts` and merge candidates that include `instagram_business_account`.
	 *
	 * @since 6.8.0
	 * @param string $user_access_token User access token with pages permissions.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function fetch_all_instagram_page_candidates( $user_access_token ) {
		$user_access_token = trim( (string) $user_access_token );
		if ( '' === $user_access_token ) {
			return new WP_Error( 'esf_ig_fb_empty_token', __( 'Missing Facebook user token.', 'easy-facebook-likebox' ) );
		}

		$candidates         = self::get_me_accounts_field_candidates();
		$field_index        = 0;
		$allow_field_retry  = true;
		$merged             = array();
		$url                = self::build_me_accounts_url( $user_access_token, $candidates[0] );

		while ( '' !== $url ) {
			$response = wp_remote_get( $url, array( 'timeout' => 25 ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			$decoded  = json_decode( $body, true );
			$has_err  = is_array( $decoded ) && isset( $decoded['error'] );
			$bad_http = ( $code >= 400 );

			if ( $bad_http || $has_err ) {
				if ( $allow_field_retry && $field_index + 1 < count( $candidates ) ) {
					++$field_index;
					$url = self::build_me_accounts_url( $user_access_token, $candidates[ $field_index ] );
					continue;
				}

				$detail = self::graph_error_message_from_json( $body );
				$base   = $has_err && ! $bad_http
					? __( 'Facebook returned an unexpected response when listing Pages.', 'easy-facebook-likebox' )
					: __( 'Could not list Facebook Pages for this account.', 'easy-facebook-likebox' );

				return new WP_Error(
					$has_err && ! $bad_http ? 'esf_ig_fb_accounts_invalid' : 'esf_ig_fb_accounts_failed',
					'' !== $detail ? $base . ' ' . $detail : $base,
					array( 'status' => 400 )
				);
			}

			if ( ! is_array( $decoded ) ) {
				return new WP_Error(
					'esf_ig_fb_accounts_invalid',
					__( 'Facebook returned an unexpected response when listing Pages.', 'easy-facebook-likebox' ),
					array( 'status' => 400 )
				);
			}

			$allow_field_retry = false;

			foreach ( self::parse_me_accounts_response( $decoded ) as $c ) {
				$merged[] = $c;
			}

			$url = '';
			if ( ! empty( $decoded['paging']['next'] ) && is_string( $decoded['paging']['next'] ) ) {
				$next = esc_url_raw( $decoded['paging']['next'] );
				if ( '' !== $next && self::url_host_allowed_for_paging( $next ) ) {
					$url = $next;
				}
			}
		}

		return $merged;
	}

	/**
	 * Build first-page `me/accounts` URL.
	 *
	 * @since 6.8.0
	 * @param string $user_access_token User access token.
	 * @param string $fields            Graph `fields` parameter.
	 * @return string
	 */
	private static function build_me_accounts_url( $user_access_token, $fields ) {
		return add_query_arg(
			array(
				'fields'       => $fields,
				'access_token' => $user_access_token,
				'limit'        => 100,
			),
			self::graph_url( 'me/accounts' )
		);
	}

	/**
	 * Build absolute Graph URL for a path (no leading slash on path segments).
	 *
	 * @since 6.8.0
	 * @param string $path Relative path after version, e.g. `me` or `me/accounts`.
	 * @return string
	 */
	public static function graph_url( $path ) {
		$path = trim( (string) $path, '/' );
		return sprintf( 'https://graph.facebook.com/%s/%s', self::get_api_version(), $path );
	}

	/**
	 * Extract a short error message from a Graph JSON error body.
	 *
	 * @since 6.8.0
	 * @param string $raw_json Response body.
	 * @return string
	 */
	private static function graph_error_message_from_json( $raw_json ) {
		$decoded = json_decode( (string) $raw_json, true );
		if ( ! is_array( $decoded ) || empty( $decoded['error'] ) || ! is_array( $decoded['error'] ) ) {
			return '';
		}
		$e   = $decoded['error'];
		$msg = isset( $e['message'] ) ? sanitize_text_field( (string) $e['message'] ) : '';
		$code = isset( $e['code'] ) ? (string) $e['code'] : '';
		if ( '' !== $msg && '' !== $code ) {
			return $msg . ' (Graph ' . $code . ')';
		}
		return '' !== $msg ? $msg : ( '' !== $code ? 'Graph error ' . $code : '' );
	}

	/**
	 * Only follow pagination URLs on Facebook graph hosts.
	 *
	 * @since 6.8.0
	 * @param string $url Next URL from Graph.
	 * @return bool
	 */
	private static function url_host_allowed_for_paging( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		return ( 'graph.facebook.com' === $host || 'graph.fb.com' === $host );
	}
}
