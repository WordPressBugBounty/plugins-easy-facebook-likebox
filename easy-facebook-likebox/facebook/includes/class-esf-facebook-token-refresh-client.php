<?php
/**
 * Facebook token refresh via external Meta bridge (no app secret in WordPress).
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Token_Refresh_Client
 *
 * @since 6.7.9
 */
class ESF_Facebook_Token_Refresh_Client {

	/**
	 * Default refresh URL (same Meta business folder as OAuth).
	 *
	 * @since 6.7.9
	 * @return string
	 */
	private static function default_refresh_url() {
		if ( class_exists( 'ESF_Facebook_API_OAuth' ) ) {
			return ESF_Facebook_API_OAuth::BRIDGE_REFRESH_URL;
		}
		return 'https://easysocialfeed.com/apps/meta/business/refresh.php';
	}

	/**
	 * Build a patch array for account upsert from stored row + bridge response.
	 *
	 * @since 6.7.9
	 * @param object $row Account row.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function refresh_tokens( $row ) {
		if ( function_exists( 'esf_facebook_account_supports_token_refresh' )
			&& ! esf_facebook_account_supports_token_refresh( $row ) ) {
			return new WP_Error(
				'esf_fb_refresh_manual',
				__( 'Manual tokens cannot be renewed automatically. Paste a new access token under Accounts → Set up manually.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$fb_tok = isset( $row->facebook_user_token ) ? trim( (string) $row->facebook_user_token ) : '';
		if ( '' === $fb_tok ) {
			return new WP_Error(
				'esf_fb_refresh_no_token',
				__( 'No Facebook user token to refresh.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$remote = self::request_remote_refresh( $row );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		return self::normalize_bridge_response( $remote, $row );
	}

	/**
	 * POST credentials to the hosted refresh script.
	 *
	 * @since 6.7.9
	 * @param object $row Account row.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function request_remote_refresh( $row ) {
		$refresh_url = self::default_refresh_url();

		/**
		 * Filter the URL used to refresh Facebook user tokens.
		 *
		 * @since 6.7.9
		 * @param string $refresh_url Full HTTPS URL.
		 */
		$refresh_url = apply_filters( 'esf_facebook_oauth_refresh_url', $refresh_url );
		$refresh_url = trim( (string) $refresh_url );
		if ( '' === $refresh_url || ! wp_http_validate_url( $refresh_url ) ) {
			return new WP_Error(
				'esf_fb_refresh_bad_url',
				__( 'Facebook token refresh URL is not configured.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$body = array(
			'site_url'            => home_url(),
			'auth_source'         => 'facebook_page',
			'facebook_user_token' => trim( (string) $row->facebook_user_token ),
			'facebook_user_id'    => isset( $row->facebook_user_id ) ? trim( (string) $row->facebook_user_id ) : '',
		);

		$response = wp_remote_post(
			$refresh_url,
			array(
				'body'    => $body,
				'timeout' => 25,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'esf_fb_refresh_http',
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

		if ( 200 !== (int) $code || ! is_array( $data ) ) {
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
			}

			$error = new WP_Error( 'esf_fb_refresh_failed', $msg, $error_data );
			if ( class_exists( 'ESF_Meta_Graph_Error' ) && isset( $error_data['detail'] ) ) {
				$action                         = ESF_Meta_Graph_Error::classify( $error_data['detail'] );
				$error_data['esf_graph_action'] = $action;
				return new WP_Error( 'esf_fb_refresh_failed', $msg, $error_data );
			}

			return $error;
		}

		return $data;
	}

	/**
	 * Normalize bridge JSON into account upsert patch.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $data Bridge response.
	 * @param object              $row  Existing account row.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function normalize_bridge_response( array $data, $row ) {
		$token = '';
		if ( ! empty( $data['facebook_user_token'] ) ) {
			$token = sanitize_text_field( (string) $data['facebook_user_token'] );
		} elseif ( ! empty( $data['access_token'] ) ) {
			$token = sanitize_text_field( (string) $data['access_token'] );
		}

		if ( '' === $token ) {
			return new WP_Error(
				'esf_fb_refresh_invalid',
				__( 'Invalid response from token refresh server.', 'easy-facebook-likebox' ),
				array( 'status' => 502 )
			);
		}

		$expires_at = null;
		if ( ! empty( $data['expires_in'] ) ) {
			$expires_at = gmdate( 'Y-m-d H:i:s', time() + (int) $data['expires_in'] );
		} elseif ( ! empty( $data['token_expires_at'] ) ) {
			$expires_at = sanitize_text_field( (string) $data['token_expires_at'] );
		}

		$patch = array(
			'facebook_user_id'    => isset( $row->facebook_user_id ) ? (string) $row->facebook_user_id : '',
			'facebook_user_token' => $token,
			'token_expires_at'    => $expires_at,
			'status'              => 'active',
		);

		// Optionally refresh page tokens from bridge payload.
		if ( ! empty( $data['pages'] ) && is_array( $data['pages'] ) && class_exists( 'ESF_Facebook_Page_Repository' ) ) {
			$page_repo = ESF_Facebook_Page_Repository::get_instance();
			foreach ( $data['pages'] as $page ) {
				if ( ! is_array( $page ) || empty( $page['page_id'] ) || empty( $page['page_access_token'] ) ) {
					continue;
				}
				$existing = $page_repo->get_by_page_id( (string) $page['page_id'] );
				if ( ! $existing ) {
					continue;
				}
				$page_repo->upsert(
					array(
						'page_id'           => (string) $page['page_id'],
						'account_id'        => (int) $existing->account_id,
						'page_access_token' => sanitize_text_field( (string) $page['page_access_token'] ),
						'status'            => 'active',
					)
				);
			}
		}

		return $patch;
	}
}
