<?php
/**
 * Optional admin email when a module account needs manual reconnect after failed token renewal.
 *
 * Modules pass settings, row loaders, and copy builders so Facebook/Instagram/etc. share one path.
 *
 * @package Easy_Social_Feed
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Token_Reconnect_Mailer
 *
 * @since 6.8.0
 */
class ESF_Token_Reconnect_Mailer {

	/**
	 * Send a throttled reconnect email when enabled in module settings.
	 *
	 * @since 6.8.0
	 * @param array<string,mixed> $args {
	 *     @type int          $account_id          Internal account id.
	 *     @type array        $settings            Module settings option (array).
	 *     @type string       $notify_setting_key  Key in `$settings` for the on/off flag.
	 *     @type string       $transient_prefix    Prefix; full key is prefix + account id.
	 *     @type int          $throttle_ttl        Transient TTL in seconds. Default `DAY_IN_SECONDS`.
	 *     @type callable     $load_row            `function ( int $id ): object|null`.
	 *     @type callable     $needs_reconnect     `function ( object $row ): bool`.
	 *     @type string       $recipient_filter    Optional `apply_filters` tag; receives `( $email, $row )`.
	 *     @type callable     $build_subject       `function ( object $row ): string`.
	 *     @type callable     $build_body          `function ( object $row, string $connect_admin_url ): string`.
	 *     @type string       $connect_admin_url   URL shown in the email body.
	 * }
	 * @return void
	 */
	public static function maybe_notify( array $args ) {
		$account_id = isset( $args['account_id'] ) ? (int) $args['account_id'] : 0;
		if ( $account_id <= 0 ) {
			return;
		}

		$settings = isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : array();
		$key      = isset( $args['notify_setting_key'] ) ? (string) $args['notify_setting_key'] : 'notify_token_reconnect';
		if ( empty( $settings[ $key ] ) ) {
			return;
		}

		$prefix = isset( $args['transient_prefix'] ) ? (string) $args['transient_prefix'] : 'esf_token_reconnect_mail_';
		$ttl    = isset( $args['throttle_ttl'] ) ? max( 60, (int) $args['throttle_ttl'] ) : (int) DAY_IN_SECONDS;

		if ( get_transient( $prefix . $account_id ) ) {
			return;
		}

		$load = isset( $args['load_row'] ) ? $args['load_row'] : null;
		if ( ! is_callable( $load ) ) {
			return;
		}

		$row = call_user_func( $load, $account_id );
		if ( ! is_object( $row ) ) {
			return;
		}

		$needs = isset( $args['needs_reconnect'] ) ? $args['needs_reconnect'] : null;
		if ( ! is_callable( $needs ) || ! call_user_func( $needs, $row ) ) {
			return;
		}

		$recipients = self::resolve_recipients( $args, $settings, $row );
		if ( empty( $recipients ) ) {
			return;
		}

		set_transient( $prefix . $account_id, 1, $ttl );

		$build_subject = isset( $args['build_subject'] ) ? $args['build_subject'] : null;
		$build_body    = isset( $args['build_body'] ) ? $args['build_body'] : null;
		if ( ! is_callable( $build_subject ) || ! is_callable( $build_body ) ) {
			return;
		}

		$url = isset( $args['connect_admin_url'] ) ? (string) $args['connect_admin_url'] : '';
		$url = $url ? esc_url_raw( $url ) : '';

		$subject = call_user_func( $build_subject, $row );
		$subject = is_string( $subject ) ? $subject : '';
		if ( '' === $subject ) {
			return;
		}

		$message = call_user_func( $build_body, $row, $url );
		$message = is_string( $message ) ? $message : '';
		if ( '' === $message ) {
			return;
		}

		wp_mail( $recipients, $subject, $message );
	}

	/**
	 * Resolve one or more valid recipient addresses for a reconnect email.
	 *
	 * @since 6.9.0
	 * @param array<string,mixed> $args     Mailer arguments.
	 * @param array<string,mixed> $settings Module settings.
	 * @param object              $row      Account row.
	 * @return string[]
	 */
	private static function resolve_recipients( array $args, array $settings, $row ) {
		$recipients       = array();
		$emails_key       = isset( $args['recipient_emails_setting_key'] ) ? (string) $args['recipient_emails_setting_key'] : '';
		$recipient_filter = isset( $args['recipient_filter'] ) ? (string) $args['recipient_filter'] : '';

		if ( '' !== $emails_key && ! empty( $settings[ $emails_key ] ) && is_array( $settings[ $emails_key ] ) ) {
			foreach ( $settings[ $emails_key ] as $email ) {
				$email = sanitize_email( (string) $email );
				if ( '' !== $email && is_email( $email ) ) {
					$recipients[] = strtolower( $email );
				}
			}
			$recipients = array_values( array_unique( $recipients ) );
		}

		if ( empty( $recipients ) ) {
			$to = (string) get_option( 'admin_email' );
			if ( '' !== $recipient_filter ) {
				/** This filter is documented per module (e.g. Instagram). */
				$to = (string) apply_filters( $recipient_filter, $to, $row );
			}
			$to = sanitize_email( $to );
			if ( '' !== $to && is_email( $to ) ) {
				$recipients = array( strtolower( $to ) );
			}
		}

		return $recipients;
	}
}
