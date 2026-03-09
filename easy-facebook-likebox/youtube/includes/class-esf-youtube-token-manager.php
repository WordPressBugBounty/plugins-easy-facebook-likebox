<?php
/**
 * YouTube Token Manager
 *
 * Handles automatic token refresh and expiry management.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube
 * @since 6.7.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_Token_Manager
 *
 * Manages automatic token refresh for all YouTube accounts.
 *
 * @since 6.7.5
 */
class ESF_YouTube_Token_Manager {

	use ESF_YouTube_Singleton;

	/**
	 * Cron hook name.
	 *
	 * @since 6.7.5
	 * @var string
	 */
	const CRON_HOOK = 'esf_youtube_auto_refresh_tokens';

	/**
	 * Initialize token manager.
	 *
	 * @since 6.7.5
	 */
	public function init() {
		// Register cron job.
		add_action( self::CRON_HOOK, array( $this, 'auto_refresh_expiring_tokens' ) );

		// Schedule cron if not already scheduled.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Run every 30 minutes.
			wp_schedule_event( time(), 'thirty_minutes', self::CRON_HOOK );
		}
	}

	/**
	 * Auto-refresh tokens that are expiring soon.
	 *
	 * Runs via WP-Cron to proactively refresh tokens before they expire.
	 * This prevents API failures and improves user experience.
	 *
	 * @since 6.7.5
	 */
	public function auto_refresh_expiring_tokens() {
		global $wpdb;
		$table         = $wpdb->prefix . 'esf_youtube_accounts';
		$table_escaped = esc_sql( $table );

		// Find accounts that:
		// 1. Are active.
		// 2. Expire within the next 10 minutes.
		// 3. Have a refresh token.
		$buffer_time = gmdate( 'Y-m-d H:i:s', time() + 600 ); // 10 minutes buffer.

		$status     = 'active';
		$status_esc = esc_sql( $status );
		$buffer_esc = esc_sql( $buffer_time );

		$expiring_accounts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and values are derived from trusted sources and escaped.
			"SELECT id, refresh_token, channel_name FROM `{$table_escaped}` WHERE status = '{$status_esc}' AND token_expires_at <= '{$buffer_esc}' AND refresh_token IS NOT NULL AND refresh_token != '' LIMIT 50"
		);

		if ( empty( $expiring_accounts ) ) {
			return;
		}

		$refreshed_count = 0;
		$failed_count    = 0;

		$api_service = ESF_YouTube_API_Service::get_instance();
		$repository  = ESF_YouTube_Account_Repository::get_instance();

		foreach ( $expiring_accounts as $account ) {
			// Attempt to refresh token.
			$token_data = $api_service->refresh_access_token( $account->refresh_token );

			if ( is_wp_error( $token_data ) ) {
				// Mark account as expired.
				$repository->update_status( $account->id, 'expired' );
				++$failed_count;

				// Notify admin (optional).
				$this->maybe_send_expiry_notification( $account->id );

				continue;
			}

			// Update tokens in database.
			$updated = $repository->update_tokens(
				$account->id,
				$token_data['access_token'],
				$token_data['refresh_token'],
				$token_data['expires_in']
			);

			if ( $updated ) {
				++$refreshed_count;
			} else {
				++$failed_count;
			}
		}
	}

	/**
	 * Send notification to admin when token expires.
	 *
	 * @since 6.7.5
	 *
	 * @param int $account_id Account ID.
	 */
	private function maybe_send_expiry_notification( $account_id ) {
		// Check if notifications are enabled.
		$settings = get_option( 'esf_youtube_settings', array() );
		if ( empty( $settings['notify_token_expiry'] ) ) {
			return;
		}

		// Get account details.
		$repository = ESF_YouTube_Account_Repository::get_instance();
		$account    = $repository->get_by_id( $account_id );

		if ( ! $account ) {
			return;
		}

		// Get admin email.
		$admin_email = get_option( 'admin_email' );

		// Send email notification.
		$subject = sprintf(
			/* translators: %s: Site name */
			__( '[%s] YouTube Account Token Expired', 'easy-facebook-likebox' ),
			get_bloginfo( 'name' )
		);

		$message = sprintf(
			/* translators: 1: Channel name, 2: Channel ID, 3: Admin URL */
			__(
				"The YouTube access token for channel '%1\$s' (ID: %2\$s) has expired and could not be automatically refreshed.\n\nPlease reconnect the account at:\n%3\$s\n\nThis is an automated message from Easy Social Feed plugin.",
				'easy-facebook-likebox'
			),
			$account->channel_name,
			$account->channel_id,
			admin_url( 'admin.php?page=esf-youtube' )
		);

		wp_mail( $admin_email, $subject, $message );
	}

	/**
	 * Get a valid access token for an account (refresh if needed).
	 *
	 * Use this when making API calls so the token is refreshed on demand if expiring.
	 *
	 * @since 6.7.5
	 *
	 * @param int $account_id Account ID.
	 * @return string|null Access token on success, null if account missing or refresh failed.
	 */
	public function get_valid_access_token( $account_id ) {
		$repository = ESF_YouTube_Account_Repository::get_instance();
		$account    = $repository->get_by_id( (int) $account_id );

		if ( ! $account || empty( $account->access_token ) ) {
			return null;
		}

		// Refresh if expired or expiring within 5 minutes.
		if ( $repository->needs_token_refresh( $account_id ) && ! empty( $account->refresh_token ) ) {
			$api_service = ESF_YouTube_API_Service::get_instance();
			$token_data  = $api_service->refresh_access_token( $account->refresh_token );

			if ( is_wp_error( $token_data ) ) {
				$repository->update_status( $account_id, 'expired' );
				return null;
			}

			$repository->update_tokens(
				$account_id,
				$token_data['access_token'],
				isset( $token_data['refresh_token'] ) ? $token_data['refresh_token'] : $account->refresh_token,
				isset( $token_data['expires_in'] ) ? $token_data['expires_in'] : 3600
			);

			return $token_data['access_token'];
		}

		return $account->access_token;
	}

	/**
	 * Get token status for an account.
	 *
	 * Returns human-readable token status for display in admin.
	 *
	 * @since 6.7.5
	 *
	 * @param int $account_id Account ID.
	 *
	 * @return array Status information.
	 */
	public function get_token_status( $account_id ) {
		$repository = ESF_YouTube_Account_Repository::get_instance();
		$account    = $repository->get_by_id( $account_id );

		if ( ! $account || empty( $account->token_expires_at ) ) {
			return array(
				'status'  => 'unknown',
				'message' => __( 'Unknown', 'easy-facebook-likebox' ),
			);
		}

		$expires_timestamp = strtotime( $account->token_expires_at );
		$time_left         = $expires_timestamp - time();

		if ( $time_left < 0 ) {
			return array(
				'status'  => 'expired',
				'message' => __( 'Expired - Auto-refresh failed. Please reconnect.', 'easy-facebook-likebox' ),
			);
		} elseif ( $time_left < 600 ) { // Less than 10 minutes.
			return array(
				'status'  => 'expiring_soon',
				'message' => __( 'Expiring soon - Auto-refresh scheduled', 'easy-facebook-likebox' ),
			);
		} else {
			$hours = floor( $time_left / 3600 );
			$days  = floor( $time_left / 86400 );

			if ( $days > 0 ) {
				/* translators: %d: Number of days */
				$message = sprintf( __( 'Active - Auto-refresh in %d days', 'easy-facebook-likebox' ), $days );
			} elseif ( $hours > 0 ) {
				/* translators: %d: Number of hours */
				$message = sprintf( __( 'Active - Auto-refresh in %d hours', 'easy-facebook-likebox' ), $hours );
			} else {
				$minutes = floor( $time_left / 60 );
				/* translators: %d: Number of minutes */
				$message = sprintf( __( 'Active - Auto-refresh in %d minutes', 'easy-facebook-likebox' ), $minutes );
			}

			return array(
				'status'  => 'active',
				'message' => $message,
			);
		}
	}

	/**
	 * Unschedule cron job.
	 *
	 * Called when module is deactivated.
	 *
	 * @since 6.7.5
	 */
	public function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}
}
