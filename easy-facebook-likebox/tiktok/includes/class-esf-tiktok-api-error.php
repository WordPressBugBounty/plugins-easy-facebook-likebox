<?php
/**
 * TikTok API error classifier.
 *
 * Maps TikTok Display API / OAuth error payloads to WP_Error codes.
 * Do not reuse Meta Graph or LinkedIn error tables here — use shared
 * {@see esf_oauth_*} helpers for refresh failures.
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
 * Class ESF_TikTok_API_Error
 *
 * @since 6.8.0
 */
class ESF_TikTok_API_Error {

	/**
	 * Whether an error means the user must reconnect the TikTok account.
	 *
	 * @since 6.8.0
	 * @param WP_Error|array|mixed $error WP_Error, decoded body, or status context.
	 * @return bool
	 */
	public static function requires_reconnect( $error ) {
		if ( $error instanceof WP_Error ) {
			$code = $error->get_error_code();
			if ( in_array( $code, array( 'token_expired', 'token_invalid', 'unauthorized', 'reconnect_required' ), true ) ) {
				return true;
			}
			$data = $error->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) && in_array( (int) $data['status'], array( 401, 403 ), true ) ) {
				return true;
			}
			return false;
		}

		if ( is_array( $error ) ) {
			$status = isset( $error['status'] ) ? (int) $error['status'] : 0;
			if ( in_array( $status, array( 401, 403 ), true ) ) {
				return true;
			}
			$msg = isset( $error['message'] ) ? strtolower( (string) $error['message'] ) : '';
			if ( '' === $msg && isset( $error['error'] ) && is_string( $error['error'] ) ) {
				$msg = strtolower( (string) $error['error'] );
			}
			if ( '' !== $msg && ( false !== strpos( $msg, 'expired' ) || false !== strpos( $msg, 'revoked' ) || false !== strpos( $msg, 'unauthorized' ) || false !== strpos( $msg, 'invalid_token' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a WP_Error from an HTTP status + TikTok JSON body.
	 *
	 * @since 6.8.0
	 * @param int                 $http_code HTTP status.
	 * @param array<string,mixed> $body      Decoded JSON body.
	 * @return WP_Error
	 */
	public static function from_http_response( $http_code, array $body = array() ) {
		$http_code = (int) $http_code;
		$message   = '';

		if ( isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
			$message = $body['error']['message'];
		} elseif ( isset( $body['error_description'] ) && is_string( $body['error_description'] ) ) {
			$message = $body['error_description'];
		} elseif ( isset( $body['message'] ) && is_string( $body['message'] ) ) {
			$message = $body['message'];
		} elseif ( isset( $body['error'] ) && is_string( $body['error'] ) ) {
			$message = $body['error'];
		}

		if ( '' === $message ) {
			$message = __( 'TikTok API request failed.', 'easy-facebook-likebox' );
		}

		$code = 'tiktok_api_error';
		if ( in_array( $http_code, array( 401, 403 ), true ) ) {
			$code = 'unauthorized';
		}

		return new WP_Error(
			$code,
			$message,
			array(
				'status' => $http_code,
				'body'   => $body,
			)
		);
	}

	/**
	 * User-facing copy when TikTok access must be reconnected (admin / preview).
	 *
	 * @since 6.14.0
	 * @return string
	 */
	public static function reconnect_required_message() {
		return self::reconnect_required_message_admin();
	}

	/**
	 * Admin / capable editor copy with reconnect guidance.
	 *
	 * @since 6.14.0
	 * @return string
	 */
	public static function reconnect_required_message_admin() {
		return __(
			'TikTok access was revoked or expired. Reconnect your TikTok account to restore this feed.',
			'easy-facebook-likebox'
		);
	}

	/**
	 * Public visitor copy — no admin UI paths.
	 *
	 * @since 6.14.0
	 * @return string
	 */
	public static function reconnect_required_message_public() {
		return __(
			'This TikTok feed is temporarily unavailable.',
			'easy-facebook-likebox'
		);
	}

	/**
	 * Generic public copy for non-auth API failures.
	 *
	 * @since 6.14.0
	 * @return string
	 */
	public static function feed_unavailable_message_public() {
		return __(
			'This TikTok feed is temporarily unavailable.',
			'easy-facebook-likebox'
		);
	}
}
