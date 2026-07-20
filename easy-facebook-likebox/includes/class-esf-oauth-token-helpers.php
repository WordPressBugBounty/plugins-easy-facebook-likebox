<?php
/**
 * Shared OAuth refresh-token error helpers for modern ESF modules.
 *
 * Used by Twitter/X, YouTube, and Instagram so invalid refresh tokens surface a
 * clear reconnect-required error instead of the raw provider message.
 *
 * @package Easy_Social_Feed
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a refresh failure means the stored refresh token can never succeed again.
 *
 * @since 6.9.0
 * @param WP_Error|mixed $error Refresh failure from the module API service.
 * @return bool
 */
function esf_oauth_refresh_error_requires_reconnect( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return false;
	}

	$code = (string) $error->get_error_code();
	if ( in_array( $code, array( 'reconnect_required', 'no_refresh_token', 'invalid_refresh_token' ), true ) ) {
		return true;
	}

	$message = strtolower( (string) $error->get_error_message() );
	if ( '' === $message ) {
		return false;
	}

	$needles = array(
		'value passed for the token was invalid',
		'invalid_grant',
		'invalid refresh token',
		'refresh token is invalid',
		'token has been revoked',
		'token has been expired or revoked',
	);

	foreach ( $needles as $needle ) {
		if ( false !== strpos( $message, $needle ) ) {
			return true;
		}
	}

	return false;
}

/**
 * WP_Error prompting the admin to reconnect after an invalid refresh token.
 *
 * @since 6.9.0
 * @param int $status Optional HTTP status for REST responses.
 * @return WP_Error
 */
function esf_oauth_reconnect_required_error( $status = 400 ) {
	return new WP_Error(
		'reconnect_required',
		__( "This account's refresh token is no longer valid. Please reconnect the account.", 'easy-facebook-likebox' ),
		array( 'status' => (int) $status )
	);
}

/**
 * Map a raw OAuth refresh WP_Error to a reconnect-required error when appropriate.
 *
 * @since 6.9.0
 * @param WP_Error $error Refresh failure from the provider/proxy.
 * @return WP_Error
 */
function esf_oauth_map_refresh_failure( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return esf_oauth_reconnect_required_error();
	}

	if ( esf_oauth_refresh_error_requires_reconnect( $error ) ) {
		return esf_oauth_reconnect_required_error();
	}

	return $error;
}
