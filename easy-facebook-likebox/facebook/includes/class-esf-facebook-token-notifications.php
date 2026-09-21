<?php
/**
 * Facebook token reconnect / Events token expiry notifications (email).
 *
 * Delegates to ESF_Token_Reconnect_Mailer.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Token_Notifications
 *
 * @since 6.7.9
 */
class ESF_Facebook_Token_Notifications {

	/**
	 * Transient prefix to throttle one mail per account per day.
	 *
	 * @var string
	 */
	const THROTTLE_PREFIX = 'esf_fb_token_mail_';

	/**
	 * Transient prefix for Events Page token expiry mails (per page row).
	 *
	 * @var string
	 */
	const EVENTS_TOKEN_THROTTLE_PREFIX = 'esf_fb_events_token_mail_';

	/**
	 * Lead time before Events token expiry to start emailing (7 days).
	 *
	 * @var int
	 */
	const EVENTS_TOKEN_LEAD_SECONDS = 604800;

	/**
	 * Send email if settings allow and throttle permits.
	 *
	 * @since 6.7.9
	 * @param int $account_id Account id.
	 * @return void
	 */
	public static function maybe_notify_reconnect_required( $account_id ) {
		if ( ! class_exists( 'ESF_Token_Reconnect_Mailer' ) ) {
			return;
		}

		$settings = function_exists( 'esf_facebook_get_resolved_settings' )
			? esf_facebook_get_resolved_settings()
			: array();
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
					return ESF_Facebook_Account_Repository::get_instance()->get_by_id( (int) $id );
				},
				'needs_reconnect'              => 'esf_facebook_account_needs_reconnect',
				'recipient_emails_setting_key' => 'notify_token_reconnect_emails',
				'recipient_filter'             => 'esf_facebook_token_reconnect_mail_recipient',
				'build_subject'                => array( __CLASS__, 'build_mail_subject' ),
				'build_body'                   => array( __CLASS__, 'build_mail_body' ),
				'connect_admin_url'            => admin_url( 'admin.php?page=esf-facebook' ),
			)
		);
	}

	/**
	 * Scan Pages with dedicated Events tokens nearing or past expiry and email admins.
	 *
	 * Uses the same Reconnect alerts toggle and recipient list as login-token emails.
	 * At most one email per Page per 24 hours. Never-expires / unknown expiry are skipped.
	 *
	 * @since 6.7.9
	 * @return void
	 */
	public static function notify_expiring_events_tokens() {
		if ( ! class_exists( 'ESF_Facebook_Page_Repository' ) ) {
			return;
		}

		$pages = ESF_Facebook_Page_Repository::get_instance()->get_pages_with_events_token_expiring(
			self::EVENTS_TOKEN_LEAD_SECONDS,
			50
		);

		foreach ( $pages as $page ) {
			self::maybe_notify_events_token_expiring( $page );
		}
	}

	/**
	 * Send a throttled Events token expiry email for one Page when alerts are enabled.
	 *
	 * @since 6.7.9
	 * @param object $page_row Page DB row.
	 * @return bool True when mail was sent.
	 */
	public static function maybe_notify_events_token_expiring( $page_row ) {
		if ( ! is_object( $page_row ) || ! class_exists( 'ESF_Token_Reconnect_Mailer' ) ) {
			return false;
		}

		$page_id = isset( $page_row->id ) ? (int) $page_row->id : 0;
		if ( $page_id <= 0 ) {
			return false;
		}

		if ( ! function_exists( 'esf_facebook_page_has_dedicated_events_token' )
			|| ! esf_facebook_page_has_dedicated_events_token( $page_row )
		) {
			return false;
		}

		if ( ! empty( $page_row->events_token_never_expires ) ) {
			return false;
		}

		$expires_at = isset( $page_row->events_token_expires_at )
			? trim( (string) $page_row->events_token_expires_at )
			: '';
		if ( '' === $expires_at || '0000-00-00 00:00:00' === $expires_at ) {
			return false;
		}

		$expires_ts = strtotime( $expires_at . ' UTC' );
		if ( false === $expires_ts ) {
			return false;
		}

		$lead_cutoff = time() + self::EVENTS_TOKEN_LEAD_SECONDS;
		if ( $expires_ts > $lead_cutoff ) {
			return false;
		}

		$settings = function_exists( 'esf_facebook_get_resolved_settings' )
			? esf_facebook_get_resolved_settings()
			: array();
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$admin_url       = function_exists( 'esf_facebook_get_events_token_update_admin_url' )
			? esf_facebook_get_events_token_update_admin_url( $page_id )
			: admin_url( 'admin.php?page=esf-facebook&screen=feeds' );
		$already_expired = $expires_ts < time();

		return ESF_Token_Reconnect_Mailer::send_admin_notification(
			array(
				'settings'                     => $settings,
				'notify_setting_key'           => 'notify_token_reconnect',
				'recipient_emails_setting_key' => 'notify_token_reconnect_emails',
				'recipient_filter'             => 'esf_facebook_events_token_mail_recipient',
				'transient_key'                => self::EVENTS_TOKEN_THROTTLE_PREFIX . $page_id,
				'throttle_ttl'                 => (int) DAY_IN_SECONDS,
				'filter_row'                   => $page_row,
				'subject'                      => self::build_events_token_mail_subject( $page_row, $already_expired ),
				'message'                      => self::build_events_token_mail_body(
					$page_row,
					$admin_url,
					$expires_ts,
					$already_expired
				),
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
			__( '[%s] Facebook account needs reconnecting', 'easy-facebook-likebox' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}

	/**
	 * @param object $row Account row.
	 * @param string $connect_admin_url Admin URL for reconnect.
	 * @return string
	 */
	public static function build_mail_body( $row, $connect_admin_url ) {
		$label = isset( $row->facebook_user_name ) ? trim( (string) $row->facebook_user_name ) : '';
		if ( '' === $label && isset( $row->facebook_user_id ) ) {
			$label = (string) $row->facebook_user_id;
		}

		return sprintf(
			/* translators: 1: Account label, 2: Admin reconnect URL */
			__(
				"The Facebook connection \"%1\$s\" needs to be reconnected. The access token could not be renewed automatically, or Meta revoked it (for example after a password or security change).\n\nPlease open the Facebook screen in WordPress and connect again using Connect Facebook Pages, or remove the account if you no longer need it.\n\n%2\$s\n\nThis message was sent by Easy Social Feed.",
				'easy-facebook-likebox'
			),
			$label,
			$connect_admin_url
		);
	}

	/**
	 * @param object $page_row        Page row.
	 * @param bool   $already_expired Whether the token is already past expiry.
	 * @return string
	 */
	public static function build_events_token_mail_subject( $page_row, $already_expired ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( $already_expired ) {
			return sprintf(
				/* translators: %s: Site title */
				__( '[%s] Facebook Events token has expired', 'easy-facebook-likebox' ),
				$site
			);
		}

		return sprintf(
			/* translators: %s: Site title */
			__( '[%s] Facebook Events token expires soon', 'easy-facebook-likebox' ),
			$site
		);
	}

	/**
	 * @param object $page_row         Page row.
	 * @param string $connect_admin_url Admin URL.
	 * @param int    $expires_ts       Unix timestamp (UTC).
	 * @param bool   $already_expired  Whether already past expiry.
	 * @return string
	 */
	public static function build_events_token_mail_body( $page_row, $connect_admin_url, $expires_ts, $already_expired ) {
		$label = isset( $page_row->page_name ) ? trim( (string) $page_row->page_name ) : '';
		if ( '' === $label && isset( $page_row->page_id ) ) {
			$label = (string) $page_row->page_id;
		}
		if ( '' === $label ) {
			$label = __( 'Page', 'easy-facebook-likebox' );
		}

		$date = self::format_events_token_expiry_for_mail( (int) $expires_ts );

		$cache_note = __(
			'Events may still show from cache until that cache expires. To keep cached Events permanently, open a page with the feed while logged in as an admin and choose "Keep cache permanently" in the notice.',
			'easy-facebook-likebox'
		);

		$update_note = __(
			'Open the Facebook feed editor in WordPress, select Events, then use Update token (or paste the token in the preview).',
			'easy-facebook-likebox'
		);

		if ( $already_expired ) {
			return sprintf(
				/* translators: 1: Page name, 2: Formatted expiry date, 3: Cache note, 4: Update instructions, 5: Admin URL */
				__(
					"The Events Page token for \"%1\$s\" expired on %2\$s. Fresh Events cannot be fetched until you paste a new Page access token from your Facebook app. Development mode is enough. We cannot renew it for you.\n\n%3\$s\n\n%4\$s\n\n%5\$s\n\nThis message was sent by Easy Social Feed.",
					'easy-facebook-likebox'
				),
				$label,
				$date,
				$cache_note,
				$update_note,
				$connect_admin_url
			);
		}

		return sprintf(
			/* translators: 1: Page name, 2: Formatted expiry date, 3: Cache note, 4: Update instructions, 5: Admin URL */
			__(
				"The Events Page token for \"%1\$s\" expires on %2\$s. After that, fresh Events cannot be fetched until you paste a new Page access token from your Facebook app. Development mode is enough. We cannot renew it for you.\n\n%3\$s\n\n%4\$s\n\n%5\$s\n\nThis message was sent by Easy Social Feed.",
				'easy-facebook-likebox'
			),
			$label,
			$date,
			$cache_note,
			$update_note,
			$connect_admin_url
		);
	}

	/**
	 * Format a UTC expiry timestamp for email using the site date format.
	 *
	 * @since 6.7.9
	 * @param int $expires_ts Unix timestamp.
	 * @return string
	 */
	public static function format_events_token_expiry_for_mail( $expires_ts ) {
		$expires_ts = (int) $expires_ts;
		if ( $expires_ts <= 0 ) {
			return '';
		}

		$format = (string) get_option( 'date_format', 'F j, Y' );
		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date( $format, $expires_ts );
		}

		return (string) date_i18n( $format, $expires_ts );
	}
}
