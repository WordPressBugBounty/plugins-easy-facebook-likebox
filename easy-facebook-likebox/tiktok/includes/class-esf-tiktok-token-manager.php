<?php
/**
 * TikTok Token Manager
 *
 * Proactively refreshes Login Kit access tokens (~24h) via WP-Cron every
 * 30 minutes. Always persists replacement refresh tokens.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok
 * @since 6.8.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_TikTok_Token_Manager
 *
 * @since 6.8.0
 */
class ESF_TikTok_Token_Manager {

	use ESF_TikTok_Singleton;

	/**
	 * WP-Cron hook for auto-refresh.
	 *
	 * @since 6.8.0
	 * @var string
	 */
	const CRON_HOOK = 'esf_tiktok_auto_refresh_tokens';

	/**
	 * Constructor.
	 *
	 * @since 6.8.0
	 */
	private function __construct() {}

	/**
	 * Attach the thirty-minute recurring refresh job.
	 *
	 * @since 6.8.0
	 * @return void
	 */
	public function init() {
		if ( ! function_exists( 'esf_cron_attach_thirty_minutes_recurring_job' ) ) {
			return;
		}

		esf_cron_attach_thirty_minutes_recurring_job(
			self::CRON_HOOK,
			array( $this, 'auto_refresh_expiring_tokens' )
		);
	}

	/**
	 * Refresh tokens expiring within the next 10 minutes.
	 *
	 * @since 6.8.0
	 * @return void
	 */
	public function auto_refresh_expiring_tokens() {
		global $wpdb;

		if ( class_exists( 'ESF_TikTok_DB_Installer' ) ) {
			ESF_TikTok_DB_Installer::maybe_add_auth_source_column();
		}

		$table         = $wpdb->prefix . 'esf_tiktok_accounts';
		$table_escaped = esc_sql( $table );
		$buffer_time   = gmdate( 'Y-m-d H:i:s', time() + 600 );

		$expiring = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, refresh_token, display_name FROM `{$table_escaped}` WHERE status = %s AND auth_source = %s AND token_expires_at <= %s AND refresh_token IS NOT NULL AND refresh_token != '' LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'active',
				'oauth',
				$buffer_time
			)
		);

		if ( empty( $expiring ) ) {
			return;
		}

		$api_service = ESF_TikTok_API_Service::get_instance();
		$repository  = ESF_TikTok_Account_Repository::get_instance();

		foreach ( $expiring as $account ) {
			$token_data = $api_service->refresh_access_token( (string) $account->refresh_token );

			if ( is_wp_error( $token_data ) ) {
				$repository->update_status( (int) $account->id, 'expired' );
				if ( class_exists( 'ESF_TikTok_Token_Notifications' ) ) {
					ESF_TikTok_Token_Notifications::maybe_notify_reconnect_required( (int) $account->id );
				}
				continue;
			}

			$repository->update_tokens(
				(int) $account->id,
				(string) $token_data['access_token'],
				(string) $token_data['refresh_token'],
				isset( $token_data['expires_in'] ) ? (int) $token_data['expires_in'] : 86400,
				isset( $token_data['refresh_expires_in'] ) ? (int) $token_data['refresh_expires_in'] : 0
			);
		}
	}

	/**
	 * Get a valid access token, refreshing on demand when near expiry.
	 *
	 * @since 6.8.0
	 * @param int $account_id Account ID.
	 * @return string|null
	 */
	public function get_valid_access_token( $account_id ) {
		$repository = ESF_TikTok_Account_Repository::get_instance();
		$account    = $repository->get_by_id( (int) $account_id );

		if ( ! $account || empty( $account->access_token ) ) {
			return null;
		}

		if ( $repository->needs_token_refresh( (int) $account_id ) && ! empty( $account->refresh_token ) ) {
			$api_service = ESF_TikTok_API_Service::get_instance();
			$token_data  = $api_service->refresh_access_token( (string) $account->refresh_token );

			if ( is_wp_error( $token_data ) ) {
				$repository->update_status( (int) $account_id, 'expired' );
				if ( class_exists( 'ESF_TikTok_Token_Notifications' ) ) {
					ESF_TikTok_Token_Notifications::maybe_notify_reconnect_required( (int) $account_id );
				}
				return null;
			}

			$repository->update_tokens(
				(int) $account_id,
				(string) $token_data['access_token'],
				(string) $token_data['refresh_token'],
				isset( $token_data['expires_in'] ) ? (int) $token_data['expires_in'] : 86400,
				isset( $token_data['refresh_expires_in'] ) ? (int) $token_data['refresh_expires_in'] : 0
			);

			return (string) $token_data['access_token'];
		}

		return (string) $account->access_token;
	}

	/**
	 * Unschedule the auto-refresh cron.
	 *
	 * @since 6.8.0
	 * @return void
	 */
	public function unschedule_cron() {
		if ( function_exists( 'esf_cron_unschedule_recurring_job' ) ) {
			esf_cron_unschedule_recurring_job( self::CRON_HOOK );
		}
	}
}
