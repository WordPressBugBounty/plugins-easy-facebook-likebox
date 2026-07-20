<?php
/**
 * Instagram token refresh via external bridge (no Meta app secret in WordPress).
 *
 * Mirrors {@see ESF_YouTube_API_Service::refresh_access_token}: POST to easysocialfeed.com
 * `apps/meta/{basic|business}/refresh.php`, which holds or proxies Meta calls. WordPress never stores
 * the Facebook app secret (business script); basic refresh uses graph.instagram.com only.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_Token_Refresh_Client
 *
 * @since 6.8.0
 */
class ESF_Instagram_Token_Refresh_Client {

	/**
	 * Default refresh URL for an auth flow (same `apps/meta/{basic|business}/` layout as OAuth).
	 *
	 * @since 6.8.0
	 * @param string $auth `instagram_login` or `facebook_page`.
	 * @return string
	 */
	private static function default_refresh_url_for_auth( $auth ) {
		if ( class_exists( 'ESF_Instagram_API_OAuth' ) ) {
			return 'facebook_page' === $auth
				? ESF_Instagram_API_OAuth::BRIDGE_REFRESH_URL_BUSINESS
				: ESF_Instagram_API_OAuth::BRIDGE_REFRESH_URL_BASIC;
		}
		return 'facebook_page' === $auth
			? 'https://easysocialfeed.com/apps/meta/business/refresh.php'
			: 'https://easysocialfeed.com/apps/meta/basic/refresh.php';
	}

	/**
	 * Build a patch array for {@see ESF_Instagram_Account_Repository::upsert()} from stored row + Meta responses.
	 *
	 * Tries the remote bridge first. For `instagram_login` only, falls back to
	 * `graph.instagram.com/refresh_access_token` (no app secret) if the bridge is unreachable.
	 *
	 * @since 6.8.0
	 * @param object $row Account row from repository (`auth_source`, tokens, `instagram_user_id`).
	 * @return array<string,mixed>|WP_Error Patch keys for upsert (always include `instagram_user_id` when calling upsert).
	 */
	public static function refresh_tokens( $row ) {
		$auth = esf_instagram_auth_source_for_account_row( $row );
		if ( 'instagram_login' === $auth ) {
			$token = isset( $row->access_token ) ? trim( (string) $row->access_token ) : '';
			if ( '' === $token ) {
				return new WP_Error(
					'esf_ig_refresh_no_token',
					__( 'No Instagram user token to refresh.', 'easy-facebook-likebox' ),
					array( 'status' => 400 )
				);
			}
		} elseif ( 'facebook_page' === $auth ) {
			$fb_tok = isset( $row->facebook_user_token ) ? trim( (string) $row->facebook_user_token ) : '';
			if ( '' === $fb_tok ) {
				return new WP_Error(
					'esf_ig_refresh_no_fb_token',
					__( 'No Facebook user token to refresh.', 'easy-facebook-likebox' ),
					array( 'status' => 400 )
				);
			}
		} else {
			return new WP_Error(
				'esf_ig_refresh_bad_auth',
				__( 'Unknown auth source for token refresh.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$remote = self::request_remote_refresh( $row, $auth );
		if ( ! is_wp_error( $remote ) ) {
			$patch = self::normalize_bridge_response( $remote, $auth );
			if ( ! is_wp_error( $patch ) ) {
				return $patch;
			}
			if ( 'facebook_page' === $auth ) {
				return $patch;
			}
		}

		if ( 'instagram_login' === $auth ) {
			$fallback = self::instagram_login_refresh_direct( trim( (string) $row->access_token ) );
			if ( ! is_wp_error( $fallback ) ) {
				return $fallback;
			}
			return is_wp_error( $remote ) ? $remote : $fallback;
		}

		return is_wp_error( $remote )
			? $remote
			: new WP_Error(
				'esf_ig_refresh_invalid',
				__( 'Invalid response from token refresh server.', 'easy-facebook-likebox' ),
				array( 'status' => 502 )
			);
	}

	/**
	 * POST current credentials to the hosted refresh script.
	 *
	 * @since 6.8.0
	 * @param object $row  Account row.
	 * @param string $auth Canonical `instagram_login` or `facebook_page`.
	 * @return array<string,mixed>|WP_Error Decoded JSON on success.
	 */
	private static function request_remote_refresh( $row, $auth ) {
		$refresh_url = self::default_refresh_url_for_auth( $auth );

		/**
		 * Filter the URL used to refresh Instagram tokens.
		 *
		 * Defaults mirror OAuth: `apps/meta/basic/refresh.php` (Instagram Login) and
		 * `apps/meta/business/refresh.php` (Facebook Page). Host Meta app secrets on the
		 * business endpoint; the WordPress site never stores them.
		 *
		 * @since 6.8.0
		 * @param string $refresh_url Full HTTPS URL to refresh.php (or compatible).
		 * @param string $auth        `instagram_login` or `facebook_page`.
		 */
		$refresh_url = apply_filters( 'esf_instagram_oauth_refresh_url', $refresh_url, $auth );
		$refresh_url = trim( (string) $refresh_url );
		if ( '' === $refresh_url || ! wp_http_validate_url( $refresh_url ) ) {
			return new WP_Error(
				'esf_ig_refresh_bad_url',
				__( 'Instagram token refresh URL is not configured.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$ig_id = isset( $row->instagram_user_id ) ? trim( (string) $row->instagram_user_id ) : '';
		$body  = array(
			'site_url'            => home_url(),
			'auth_source'         => $auth,
			'instagram_user_id'   => $ig_id,
		);

		if ( 'instagram_login' === $auth ) {
			$body['access_token'] = trim( (string) $row->access_token );
		} else {
			$body['facebook_user_token'] = trim( (string) $row->facebook_user_token );
			$body['facebook_page_id']    = isset( $row->facebook_page_id ) ? trim( (string) $row->facebook_page_id ) : '';
		}

		$response = wp_remote_post(
			$refresh_url,
			array(
				'body'    => $body,
				'timeout' => 25,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'esf_ig_refresh_http',
				sprintf(
					/* translators: %s: transport error message */
					__( 'Token refresh request failed: %s', 'easy-facebook-likebox' ),
					$response->get_error_message()
				),
				array( 'status' => 503 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== $code || ! is_array( $data ) ) {
			$msg        = __( 'Unknown error during token refresh', 'easy-facebook-likebox' );
			$error_data = array( 'status' => $code );

			if ( is_array( $data ) && isset( $data['error'] ) ) {
				if ( is_array( $data['error'] ) ) {
					$error_data['detail'] = $data['error'];
					$msg                  = isset( $data['error']['message'] )
						? sanitize_text_field( (string) $data['error']['message'] )
						: $msg;
				} else {
					$msg = sanitize_text_field( (string) $data['error'] );
				}
			} elseif ( is_array( $data ) && isset( $data['graph_error'] ) && is_array( $data['graph_error'] ) ) {
				$error_data['detail'] = $data['graph_error'];
			}

			return new WP_Error(
				'esf_ig_refresh_failed',
				sprintf(
					/* translators: %s: error detail */
					__( 'Token refresh failed: %s', 'easy-facebook-likebox' ),
					$msg
				),
				$error_data
			);
		}

		if ( ! empty( $data['error'] ) ) {
			$error_data = array( 'status' => 400 );
			if ( is_array( $data['error'] ) ) {
				$error_data['detail'] = $data['error'];
				$msg                  = isset( $data['error']['message'] )
					? sanitize_text_field( (string) $data['error']['message'] )
					: __( 'Unknown error during token refresh', 'easy-facebook-likebox' );
			} else {
				$msg = sanitize_text_field( (string) $data['error'] );
			}

			return new WP_Error(
				'esf_ig_refresh_failed',
				sprintf(
					/* translators: %s: error from JSON body */
					__( 'Token refresh failed: %s', 'easy-facebook-likebox' ),
					$msg
				),
				$error_data
			);
		}

		return $data;
	}

	/**
	 * Map JSON from refresh.php into DB columns for upsert.
	 *
	 * @since 6.8.0
	 * @param array<string,mixed> $data Decoded JSON.
	 * @param string               $auth instagram_login|facebook_page.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function normalize_bridge_response( array $data, $auth ) {
		$patch = array();

		if ( 'instagram_login' === $auth ) {
			if ( ! empty( $data['access_token'] ) ) {
				$patch['access_token'] = sanitize_text_field( (string) $data['access_token'] );
			}
			if ( ! empty( $data['expires_in'] ) ) {
				$patch['token_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + max( 0, absint( $data['expires_in'] ) ) );
			}
		} else {
			if ( ! empty( $data['facebook_user_token'] ) ) {
				$patch['facebook_user_token'] = sanitize_text_field( (string) $data['facebook_user_token'] );
			}
			if ( ! empty( $data['page_access_token'] ) ) {
				$patch['page_access_token'] = sanitize_text_field( (string) $data['page_access_token'] );
			}
			if ( ! empty( $data['facebook_user_expires_in'] ) ) {
				$patch['token_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + max( 0, absint( $data['facebook_user_expires_in'] ) ) );
			} elseif ( ! empty( $data['expires_in'] ) ) {
				$patch['token_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + max( 0, absint( $data['expires_in'] ) ) );
			}
		}

		if ( array() === $patch ) {
			return new WP_Error(
				'esf_ig_refresh_empty',
				__( 'Token refresh server returned no tokens.', 'easy-facebook-likebox' ),
				array( 'status' => 502 )
			);
		}

		return $patch;
	}

	/**
	 * Instagram Login long-lived rotation (no app secret on WordPress).
	 *
	 * @link https://developers.facebook.com/docs/instagram-platform/reference/refresh_access_token/
	 *
	 * @since 6.8.0
	 * @param string $token Current long-lived user token.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function instagram_login_refresh_direct( $token ) {
		$url = add_query_arg(
			array(
				'grant_type'   => 'ig_refresh_token',
				'access_token' => $token,
			),
			'https://graph.instagram.com/refresh_access_token'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$data = array( 'status' => 400 );
			if ( is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ) {
				$data['detail'] = $body['error'];
			}

			return new WP_Error(
				'esf_ig_refresh_ig_http',
				__( 'Instagram token refresh failed.', 'easy-facebook-likebox' ),
				$data
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || isset( $body['error'] ) || empty( $body['access_token'] ) ) {
			$data = array( 'status' => 400 );
			if ( is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ) {
				$data['detail'] = $body['error'];
			}

			return new WP_Error(
				'esf_ig_refresh_ig_invalid',
				__( 'Instagram did not return a valid refreshed token.', 'easy-facebook-likebox' ),
				$data
			);
		}

		$patch = array(
			'access_token' => sanitize_text_field( (string) $body['access_token'] ),
		);
		if ( ! empty( $body['expires_in'] ) ) {
			$patch['token_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + max( 0, absint( $body['expires_in'] ) ) );
		}

		return $patch;
	}
}
