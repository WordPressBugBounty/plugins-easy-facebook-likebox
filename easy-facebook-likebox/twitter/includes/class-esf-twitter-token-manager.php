<?php
/**
 * Twitter Token Manager
 *
 * Manages automatic OAuth 2.0 token refresh for all connected Twitter accounts.
 * Runs via WP-Cron every 30 minutes to proactively refresh tokens that will
 * expire within the next 10 minutes.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter
 * @since 6.7.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Twitter_Token_Manager
 *
 * @since 6.7.6
 */
class ESF_Twitter_Token_Manager {

	use ESF_Twitter_Singleton;

	/**
	 * WP-Cron hook for auto-refresh.
	 *
	 * @since 6.7.6
	 * @var string
	 */
	const CRON_HOOK = 'esf_twitter_auto_refresh_tokens';

	/**
	 * Initialize the token manager.
	 *
	 * Registers the cron action and schedules it when not already scheduled.
	 *
	 * @since 6.7.6
	 * @return void
	 */
	public function init() {
		add_action( self::CRON_HOOK, array( $this, 'auto_refresh_expiring_tokens' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'thirty_minutes', self::CRON_HOOK );
		}
	}

	/**
	 * Refresh tokens expiring within the next 10 minutes.
	 *
	 * Iterates active connected accounts whose access_token expires soon,
	 * calls the ESF proxy for a new token, and updates the DB record.
	 * Public accounts (no OAuth) are skipped.
	 *
	 * @since 6.7.6
	 * @return void
	 */
	public function auto_refresh_expiring_tokens() {
		global $wpdb;

		$table         = $wpdb->prefix . 'esf_twitter_accounts';
		$table_escaped = esc_sql( $table );
		$buffer_time   = gmdate( 'Y-m-d H:i:s', time() + 600 ); // 10-minute buffer.

		$expiring = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, refresh_token, display_name FROM `{$table_escaped}` WHERE account_type = %s AND status = %s AND token_expires_at <= %s AND refresh_token IS NOT NULL AND refresh_token != '' LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'connected',
				'active',
				$buffer_time
			)
		);

		if ( empty( $expiring ) ) {
			return;
		}

		$api_service = ESF_Twitter_API_Service::get_instance();
		$repository  = ESF_Twitter_Account_Repository::get_instance();

		foreach ( $expiring as $account ) {
			$token_data = $api_service->refresh_access_token( $account->refresh_token );

			if ( is_wp_error( $token_data ) ) {
				$repository->update_status( (int) $account->id, 'expired' );
				$this->maybe_send_expiry_notification( (int) $account->id );
				continue;
			}

			$repository->update_tokens(
				(int) $account->id,
				$token_data['access_token'],
				$token_data['refresh_token'],
				$token_data['expires_in']
			);
		}
	}

	/**
	 * Get a valid access token for an account, refreshing on demand if expiring.
	 *
	 * Use this method whenever making an API call so tokens are always fresh.
	 * Returns null for public accounts (no OAuth token needed).
	 *
	 * @since 6.7.6
	 * @param int $account_id Account ID.
	 * @return string|null Access token, or null on failure/invalid account.
	 */
	public function get_valid_access_token( $account_id ) {
		$repository = ESF_Twitter_Account_Repository::get_instance();
		$account    = $repository->get_by_id( (int) $account_id );

		if ( ! $account ) {
			return null;
		}

		// Public accounts do not use OAuth tokens.
		if ( 'public' === $account->account_type ) {
			return null;
		}

		if ( empty( $account->access_token ) ) {
			return null;
		}

		if ( $repository->needs_token_refresh( (int) $account_id ) && ! empty( $account->refresh_token ) ) {
			$api_service = ESF_Twitter_API_Service::get_instance();
			$token_data  = $api_service->refresh_access_token( $account->refresh_token );

			if ( is_wp_error( $token_data ) ) {
				$repository->update_status( (int) $account_id, 'expired' );
				return null;
			}

			$repository->update_tokens(
				(int) $account_id,
				$token_data['access_token'],
				$token_data['refresh_token'],
				$token_data['expires_in']
			);

			return $token_data['access_token'];
		}

		return $account->access_token;
	}

	/**
	 * Unschedule the auto-refresh cron.
	 *
	 * Called when the module is deactivated.
	 *
	 * @since 6.7.6
	 * @return void
	 */
	public function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Optionally notify the site admin when a token cannot be refreshed.
	 *
	 * @since 6.7.6
	 * @param int $account_id Account ID.
	 * @return void
	 */
	private function maybe_send_expiry_notification( $account_id ) {
		$settings = get_option( 'esf_twitter_settings', array() );
		if ( empty( $settings['notify_token_expiry'] ) ) {
			return;
		}

		$repository = ESF_Twitter_Account_Repository::get_instance();
		$account    = $repository->get_by_id( $account_id );
		if ( ! $account ) {
			return;
		}

		$admin_email = get_option( 'admin_email' );
		$subject     = sprintf(
			/* translators: %s: site name */
			__( '[%s] X Account Token Expired', 'easy-facebook-likebox' ),
			get_bloginfo( 'name' )
		);
		$message = sprintf(
			/* translators: 1: username, 2: admin URL */
			__(
				"The X access token for @%1\$s has expired and could not be refreshed automatically.\n\nPlease reconnect the account at:\n%2\$s\n\nThis is an automated message from Easy Social Feed.",
				'easy-facebook-likebox'
			),
			$account->username,
			admin_url( 'admin.php?page=esf-twitter' )
		);

		wp_mail( $admin_email, $subject, $message );
	}
}
