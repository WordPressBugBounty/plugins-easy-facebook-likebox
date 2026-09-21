<?php
/**
 * YouTube token reconnect notifications (email).
 *
 * Delegates to {@see ESF_Token_Reconnect_Mailer}.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube
 * @since 6.9.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_Token_Notifications
 *
 * @since 6.9.0
 */
class ESF_YouTube_Token_Notifications {

	/**
	 * Transient prefix to throttle one mail per account per day.
	 *
	 * @var string
	 */
	const THROTTLE_PREFIX = 'esf_yt_token_mail_';

	/**
	 * Send email if settings allow and throttle permits.
	 *
	 * @since 6.9.0
	 * @param int $account_id Account id.
	 * @return void
	 */
	public static function maybe_notify_reconnect_required( $account_id ) {
		if ( ! class_exists( 'ESF_Token_Reconnect_Mailer' ) ) {
			return;
		}

		$settings = function_exists( 'esf_get_youtube_settings' ) ? esf_get_youtube_settings() : array();
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		ESF_Token_Reconnect_Mailer::maybe_notify(
			array(
				'account_id'                   => (int) $account_id,
				'settings'                     => $settings,
				'notify_setting_key'           => 'notify_token_reconnect',
				'transient_prefix'             => self::THROTTLE_PREFIX,
				'throttle_ttl'                 => (int) DAY_IN_SECONDS,
				'load_row'                     => static function ( $id ) {
					return ESF_YouTube_Account_Repository::get_instance()->get_by_id( (int) $id );
				},
				'needs_reconnect'              => 'esf_youtube_account_needs_reconnect',
				'recipient_emails_setting_key' => 'notify_token_reconnect_emails',
				'recipient_filter'             => 'esf_youtube_token_reconnect_mail_recipient',
				'build_subject'                => array( __CLASS__, 'build_mail_subject' ),
				'build_body'                   => array( __CLASS__, 'build_mail_body' ),
				'connect_admin_url'            => admin_url( 'admin.php?page=esf-youtube' ),
			)
		);
	}

	/**
	 * @param object $row Account row.
	 * @return string
	 */
	public static function build_mail_subject( $row ) {
		unset( $row );
		return sprintf(
			/* translators: %s: Site title */
			__( '[%s] YouTube channel needs reconnecting', 'easy-facebook-likebox' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}

	/**
	 * @param object $row Account row.
	 * @param string $connect_admin_url Admin URL for reconnect.
	 * @return string
	 */
	public static function build_mail_body( $row, $connect_admin_url ) {
		$label = isset( $row->channel_name ) ? trim( (string) $row->channel_name ) : '';
		if ( '' === $label && isset( $row->channel_id ) ) {
			$label = (string) $row->channel_id;
		}

		return sprintf(
			/* translators: 1: Channel label, 2: Admin reconnect URL */
			__(
				"The YouTube channel connection \"%1\$s\" needs to be reconnected. The access token could not be renewed automatically.\n\nPlease open the YouTube screen in WordPress and connect again, or remove the account if you no longer need it.\n\n%2\$s\n\nThis message was sent by Easy Social Feed.",
				'easy-facebook-likebox'
			),
			$label,
			$connect_admin_url
		);
	}
}
