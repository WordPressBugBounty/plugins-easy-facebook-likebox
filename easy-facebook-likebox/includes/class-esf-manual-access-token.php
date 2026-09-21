<?php
/**
 * Shared helpers for manual (pasted) OAuth access tokens across ESF modules.
 *
 * Modules validate tokens against their provider API, persist rows with an
 * `auth_source` of `manual`, and skip hosted refresh bridges for those rows.
 *
 * @package Easy_Social_Feed
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Manual_Access_Token
 *
 * @since 6.9.0
 */
class ESF_Manual_Access_Token {

	/**
	 * Default auth source for hosted OAuth connections.
	 *
	 * @var string
	 */
	const AUTH_OAUTH = 'oauth';

	/**
	 * Auth source when the admin pasted a provider access token.
	 *
	 * @var string
	 */
	const AUTH_MANUAL = 'manual';

	/**
	 * Sanitize a pasted access token for storage and Graph calls.
	 *
	 * @since 6.9.0
	 * @param mixed $raw Raw token from request/UI.
	 * @return string
	 */
	public static function sanitize( $raw ) {
		return sanitize_text_field( trim( (string) $raw ) );
	}

	/**
	 * Whether a sanitized token is non-empty and within a reasonable length.
	 *
	 * @since 6.9.0
	 * @param string $token Sanitized token.
	 * @return bool
	 */
	public static function is_valid_format( $token ) {
		$token = self::sanitize( $token );
		if ( '' === $token ) {
			return false;
		}

		$len = strlen( $token );
		return $len >= 16 && $len <= 4096;
	}

	/**
	 * Normalize a stored auth source value.
	 *
	 * @since 6.9.0
	 * @param mixed $source Raw source string.
	 * @return string `oauth` or `manual`.
	 */
	public static function normalize_auth_source( $source ) {
		$source = sanitize_key( (string) $source );
		return self::AUTH_MANUAL === $source ? self::AUTH_MANUAL : self::AUTH_OAUTH;
	}

	/**
	 * Whether the auth source uses the module's hosted OAuth refresh path.
	 *
	 * @since 6.9.0
	 * @param mixed $source Stored auth source.
	 * @return bool
	 */
	public static function supports_hosted_refresh( $source ) {
		return self::AUTH_MANUAL !== self::normalize_auth_source( $source );
	}

	/**
	 * Default documentation URL for generating a Facebook/Meta Page access token.
	 *
	 * Filterable per module via `esf_manual_access_token_help_url`.
	 *
	 * @since 6.9.0
	 * @param string $module_slug Module slug (`facebook`, `instagram`, …).
	 * @return string
	 */
	public static function default_help_url( $module_slug = 'facebook' ) {
		$module_slug = (string) $module_slug;
		$url         = 'https://easysocialfeed.com/custom-facebook-feed/page-token/';

		if ( 'twitter' === $module_slug ) {
			// Official X Developer Console: create an app and generate tokens.
			$url = 'https://console.x.com/';
		}

		/**
		 * Filter the help URL shown beside manual token fields.
		 *
		 * @since 6.9.0
		 * @param string $url         Default help URL.
		 * @param string $module_slug Module requesting the link.
		 */
		return (string) apply_filters( 'esf_manual_access_token_help_url', $url, $module_slug );
	}
}
