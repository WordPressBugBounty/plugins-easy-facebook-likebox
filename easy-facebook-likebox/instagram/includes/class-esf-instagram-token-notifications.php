<?php
/**
 * Instagram token expiry notifications (email).
 *
 * Delegates to {@see ESF_Token_Reconnect_Mailer} so other modules can reuse the same flow.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional admin email when an account is marked expired after failed auto-refresh.
 *
 * @since 6.8.0
 */
class ESF_Instagram_Token_Notifications {

	/**
	 * Transient prefix to throttle one mail per account per day.
	 *
	 * @var string
	 */
	const THROTTLE_PREFIX = 'esf_ig_token_mail_';

	/**
	 * Send email if settings allow and throttle permits.
	 *
	 * @since 6.8.0
	 * @param int $account_id Account id.
	 * @return void
	 */
	public static function maybe_notify_reconnect_required( $account_id ) {
		if ( ! class_exists( 'ESF_Token_Reconnect_Mailer' ) ) {
			return;
		}

		ESF_Token_Reconnect_Mailer::maybe_notify(
			array(
				'account_id'           => (int) $account_id,
				'settings'                     => esf_instagram_get_resolved_settings(),
				'notify_setting_key'   => 'notify_token_reconnect',
				'transient_prefix'     => self::THROTTLE_PREFIX,
				'throttle_ttl'         => (int) DAY_IN_SECONDS,
				'load_row'             => function ( $id ) {
					return ESF_Instagram_Account_Repository::get_instance()->get_by_id( (int) $id );
				},
				'needs_reconnect'              => 'esf_instagram_account_needs_reconnect',
				'recipient_emails_setting_key' => 'notify_token_reconnect_emails',
				'recipient_filter'             => 'esf_instagram_token_reconnect_mail_recipient',
				'build_subject'        => array( __CLASS__, 'build_mail_subject' ),
				'build_body'           => array( __CLASS__, 'build_mail_body' ),
				'connect_admin_url'    => admin_url( 'admin.php?page=esf-instagram' ),
			)
		);
	}

	/**
	 * @param object $row Account row.
	 * @return string
	 */
	public static function build_mail_subject( $row ) {
		return sprintf(
			/* translators: %s: Site title */
			__( '[%s] Instagram account needs reconnecting', 'easy-facebook-likebox' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}

	/**
	 * @param object $row Account row.
	 * @param string $connect_admin_url Admin URL for reconnect.
	 * @return string
	 */
	public static function build_mail_body( $row, $connect_admin_url ) {
		$label = trim( (string) $row->display_name );
		if ( '' === $label ) {
			$label = trim( (string) $row->username );
		}
		if ( '' === $label ) {
			$label = (string) $row->instagram_user_id;
		}

		return sprintf(
			/* translators: 1: Account label, 2: Admin reconnect URL */
			__(
				"The Instagram connection \"%1\$s\" needs to be reconnected. The access token could not be renewed automatically, or Meta revoked it (for example after a password or security change).\n\nPlease open the Instagram screen in WordPress and connect again using the same method you used before (Instagram Login or Facebook Page), or remove the account if you no longer need it.\n\n%2\$s\n\nThis message was sent by Easy Social Feed.",
				'easy-facebook-likebox'
			),
			$label,
			$connect_admin_url
		);
	}
}
