<?php
/**
 * Shared Meta Graph API error classification (Facebook + Instagram).
 *
 * Maps Graph `error` payloads (and probe HTTP responses) to a stable action so
 * modern modules can mark accounts for reconnect, back off on rate limits, or
 * ignore transient transport failures — without duplicating Meta code lists.
 *
 * @package Easy_Social_Feed
 * @since 6.9.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Meta_Graph_Error
 *
 * @since 6.9.4
 */
class ESF_Meta_Graph_Error {

	/**
	 * Token is revoked/expired/invalid — user must reconnect via OAuth.
	 *
	 * @var string
	 */
	const ACTION_RECONNECT = 'reconnect_required';

	/**
	 * Temporary Meta rate limiting — retry later; do not mark account invalid.
	 *
	 * @var string
	 */
	const ACTION_RATE_LIMITED = 'rate_limited';

	/**
	 * Transport / temporary failure — retry later; do not mark account invalid.
	 *
	 * @var string
	 */
	const ACTION_TRANSIENT = 'transient';

	/**
	 * Unclassified Graph or application error.
	 *
	 * @var string
	 */
	const ACTION_UNKNOWN = 'unknown';

	/**
	 * Token probe: Graph accepted the token.
	 *
	 * @var string
	 */
	const TOKEN_VALID = 'valid';

	/**
	 * Token probe: Graph rejected the token as permanently unusable.
	 *
	 * @var string
	 */
	const TOKEN_INVALID = 'invalid';

	/**
	 * Token probe: could not determine health (network / unexpected body).
	 *
	 * @var string
	 */
	const TOKEN_UNKNOWN = 'unknown';

	/**
	 * Graph / OAuth codes that require reconnect.
	 *
	 * @var int[]
	 */
	const RECONNECT_CODES = array( 102, 190, 458, 463, 467 );

	/**
	 * Common `error_subcode` values for password change / session revoke under code 190.
	 *
	 * @var int[]
	 */
	const RECONNECT_SUBCODES = array( 458, 460, 463, 464, 467, 490, 492 );

	/**
	 * Meta rate-limit style codes.
	 *
	 * @var int[]
	 */
	const RATE_LIMIT_CODES = array( 4, 17, 32, 613, 80001, 80002, 80004 );

	/**
	 * Classify a decoded Meta Graph `error` object.
	 *
	 * @since 6.9.4
	 * @param array<string,mixed>|mixed $error Graph error array (`message`, `type`, `code`, `error_subcode`).
	 * @return string One of {@see self::ACTION_RECONNECT}, {@see self::ACTION_RATE_LIMITED},
	 *                {@see self::ACTION_TRANSIENT}, {@see self::ACTION_UNKNOWN}.
	 */
	public static function classify( $error ) {
		if ( ! is_array( $error ) ) {
			return self::ACTION_UNKNOWN;
		}

		$code     = isset( $error['code'] ) ? (int) $error['code'] : 0;
		$subcode  = isset( $error['error_subcode'] ) ? (int) $error['error_subcode'] : 0;
		$err_type = isset( $error['type'] ) ? (string) $error['type'] : '';
		$message  = isset( $error['message'] ) ? (string) $error['message'] : '';

		if ( in_array( $code, self::RATE_LIMIT_CODES, true ) ) {
			return self::ACTION_RATE_LIMITED;
		}

		if ( in_array( $code, self::RECONNECT_CODES, true ) ) {
			return self::ACTION_RECONNECT;
		}

		if ( $subcode > 0 && in_array( $subcode, self::RECONNECT_SUBCODES, true ) ) {
			return self::ACTION_RECONNECT;
		}

		if ( 'OAuthException' === $err_type && $code >= 100 ) {
			return self::ACTION_RECONNECT;
		}

		$from_message = self::classify_message( $message );
		if ( self::ACTION_RECONNECT === $from_message || self::ACTION_RATE_LIMITED === $from_message ) {
			return $from_message;
		}

		return self::ACTION_UNKNOWN;
	}

	/**
	 * Classify from a free-form error string (bridge responses, wrapped messages).
	 *
	 * @since 6.9.4
	 * @param string $message Error text.
	 * @return string One of the ACTION_* constants.
	 */
	public static function classify_message( $message ) {
		$message = strtolower( trim( (string) $message ) );
		if ( '' === $message ) {
			return self::ACTION_UNKNOWN;
		}

		$rate_needles = array(
			'rate limit',
			'request limit',
			'too many calls',
			'user request limit',
			'application request limit',
		);
		foreach ( $rate_needles as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return self::ACTION_RATE_LIMITED;
			}
		}

		$reconnect_needles = array(
			'session has been invalidated',
			'changed their password',
			'error validating access token',
			'invalid oauth',
			'invalid oauth access token',
			'access token has expired',
			'access token has been revoked',
			'the user must be an administrator',
			'user has not authorized',
			'not authorized application',
			'permissions error',
			'oauthexception',
		);
		foreach ( $reconnect_needles as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return self::ACTION_RECONNECT;
			}
		}

		return self::ACTION_UNKNOWN;
	}

	/**
	 * Classify a WP_Error that may carry Graph `detail` in error data.
	 *
	 * @since 6.9.4
	 * @param WP_Error $error WordPress error.
	 * @return string One of the ACTION_* constants.
	 */
	public static function classify_wp_error( $error ) {
		if ( ! ( $error instanceof WP_Error ) ) {
			return self::ACTION_UNKNOWN;
		}

		$code = (string) $error->get_error_code();
		if ( in_array( $code, array( 'http_request_failed', 'esf_ig_refresh_http', 'esf_yt_refresh_http' ), true ) ) {
			return self::ACTION_TRANSIENT;
		}

		$data = $error->get_error_data();
		if ( is_array( $data ) ) {
			if ( isset( $data['detail'] ) && is_array( $data['detail'] ) ) {
				$classified = self::classify( $data['detail'] );
				if ( self::ACTION_UNKNOWN !== $classified ) {
					return $classified;
				}
			}
			if ( isset( $data['graph_error'] ) && is_array( $data['graph_error'] ) ) {
				$classified = self::classify( $data['graph_error'] );
				if ( self::ACTION_UNKNOWN !== $classified ) {
					return $classified;
				}
			}
		}

		if ( in_array(
			$code,
			array(
				'esf_ig_refresh_no_token',
				'esf_ig_refresh_no_fb_token',
				'esf_ig_refresh_ig_invalid',
				'esf_ig_refresh_ig_http',
				'missing_access_token',
			),
			true
		) ) {
			return self::ACTION_RECONNECT;
		}

		return self::classify_message( $error->get_error_message() );
	}

	/**
	 * Classify a `wp_remote_*` response used as a token health probe.
	 *
	 * @since 6.9.4
	 * @param array<string,mixed>|WP_Error $response Remote response or transport error.
	 * @return string {@see self::TOKEN_VALID}, {@see self::TOKEN_INVALID}, or {@see self::TOKEN_UNKNOWN}.
	 */
	public static function classify_token_probe_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return self::TOKEN_UNKNOWN;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && is_array( $body ) && ! isset( $body['error'] ) ) {
			return self::TOKEN_VALID;
		}

		if ( ! is_array( $body ) || ! isset( $body['error'] ) || ! is_array( $body['error'] ) ) {
			return self::TOKEN_UNKNOWN;
		}

		$action = self::classify( $body['error'] );
		if ( self::ACTION_RECONNECT === $action ) {
			return self::TOKEN_INVALID;
		}

		return self::TOKEN_UNKNOWN;
	}

	/**
	 * Whether the classified action requires manual OAuth reconnect.
	 *
	 * @since 6.9.4
	 * @param string $action Action from {@see classify()} / {@see classify_wp_error()}.
	 * @return bool
	 */
	public static function is_reconnect_required( $action ) {
		return self::ACTION_RECONNECT === (string) $action;
	}

	/**
	 * Mark an account for reconnect when the error classifies as auth failure.
	 *
	 * Modules pass callables so Facebook/Instagram keep their own repositories
	 * and notification classes without sharing DB schemas.
	 *
	 * @since 6.9.4
	 * @param array<string,mixed>|WP_Error|mixed $error Graph error array or WP_Error.
	 * @param array<string,mixed>                $args  {
	 *     @type int      $account_id  Internal account id.
	 *     @type callable $mark_status `function ( int $account_id, string $status ): bool`.
	 *     @type callable $notify      Optional `function ( int $account_id ): void`.
	 *     @type string   $status      DB status to set. Default `invalid`.
	 * }
	 * @return bool True when the account was marked for reconnect.
	 */
	public static function maybe_mark_reconnect( $error, array $args ) {
		$account_id = isset( $args['account_id'] ) ? (int) $args['account_id'] : 0;
		if ( $account_id <= 0 ) {
			return false;
		}

		if ( $error instanceof WP_Error ) {
			$action = self::classify_wp_error( $error );
		} else {
			$action = self::classify( $error );
		}

		if ( ! self::is_reconnect_required( $action ) ) {
			return false;
		}

		$mark = isset( $args['mark_status'] ) ? $args['mark_status'] : null;
		if ( ! is_callable( $mark ) ) {
			return false;
		}

		$status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : 'invalid';
		if ( '' === $status ) {
			$status = 'invalid';
		}

		$updated = (bool) call_user_func( $mark, $account_id, $status );
		if ( ! $updated ) {
			return false;
		}

		$notify = isset( $args['notify'] ) ? $args['notify'] : null;
		if ( is_callable( $notify ) ) {
			call_user_func( $notify, $account_id );
		}

		return true;
	}
}
