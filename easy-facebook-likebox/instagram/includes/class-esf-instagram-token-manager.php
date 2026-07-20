<?php
/**
 * Instagram automatic token refresh (WP-Cron).
 *
 * Uses {@see ESF_Instagram_Token_Refresh_Client} so Meta app secrets stay on easysocialfeed.com,
 * same security model as YouTube. Auth failures are classified via {@see ESF_Meta_Graph_Error}
 * so password/session revokes mark accounts `invalid` without waiting for natural expiry alone.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_Token_Manager
 *
 * @since 6.8.0
 */
class ESF_Instagram_Token_Manager {

	use ESF_Instagram_Singleton;

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'esf_instagram_auto_refresh_tokens';

	/**
	 * Register cron handler and schedule.
	 *
	 * @since 6.8.0
	 * @return void
	 */
	public function init() {
		esf_cron_attach_thirty_minutes_recurring_job(
			self::CRON_HOOK,
			array( $this, 'auto_refresh_expiring_tokens' )
		);
	}

	/**
	 * Refresh accounts whose `token_expires_at` is within the buffer window.
	 *
	 * @since 6.8.0
	 * @return void
	 */
	public function auto_refresh_expiring_tokens() {
		$repo     = ESF_Instagram_Account_Repository::get_instance();
		$accounts = $repo->get_accounts_due_for_token_refresh( 600, 50 );

		if ( empty( $accounts ) ) {
			return;
		}

		foreach ( $accounts as $account ) {
			$patch = ESF_Instagram_Token_Refresh_Client::refresh_tokens( $account );
			if ( is_wp_error( $patch ) ) {
				$this->handle_refresh_failure( $repo, $account, $patch );
				continue;
			}

			$patch['instagram_user_id'] = (string) $account->instagram_user_id;
			$patch['status']            = 'active';
			$id                         = $repo->upsert( $patch );
			if ( false === $id ) {
				$repo->update_status( (int) $account->id, 'expired' );
				if ( class_exists( 'ESF_Instagram_Token_Notifications' ) ) {
					ESF_Instagram_Token_Notifications::maybe_notify_reconnect_required( (int) $account->id );
				}
			}
		}
	}

	/**
	 * Apply classified status after a failed token refresh.
	 *
	 * Auth/revoke → `invalid`. Transport/rate-limit → leave `active` for retry.
	 * Other near-expiry refresh misses → `expired`.
	 *
	 * @since 6.9.4
	 * @param ESF_Instagram_Account_Repository $repo    Repository.
	 * @param object                           $account Account row.
	 * @param WP_Error                         $error   Refresh failure.
	 * @return void
	 */
	private function handle_refresh_failure( $repo, $account, $error ) {
		$account_id = (int) $account->id;

		if ( ! class_exists( 'ESF_Meta_Graph_Error' ) ) {
			$repo->update_status( $account_id, 'expired' );
			if ( class_exists( 'ESF_Instagram_Token_Notifications' ) ) {
				ESF_Instagram_Token_Notifications::maybe_notify_reconnect_required( $account_id );
			}
			return;
		}

		$action = ESF_Meta_Graph_Error::classify_wp_error( $error );

		if ( ESF_Meta_Graph_Error::is_reconnect_required( $action ) ) {
			$repo->update_status( $account_id, 'invalid' );
			if ( class_exists( 'ESF_Instagram_Token_Notifications' ) ) {
				ESF_Instagram_Token_Notifications::maybe_notify_reconnect_required( $account_id );
			}
			return;
		}

		if (
			in_array(
				$action,
				array( ESF_Meta_Graph_Error::ACTION_TRANSIENT, ESF_Meta_Graph_Error::ACTION_RATE_LIMITED ),
				true
			)
		) {
			return;
		}

		$repo->update_status( $account_id, 'expired' );
		if ( class_exists( 'ESF_Instagram_Token_Notifications' ) ) {
			ESF_Instagram_Token_Notifications::maybe_notify_reconnect_required( $account_id );
		}
	}

	/**
	 * Clear scheduled event (e.g. on module deactivation if wired later).
	 *
	 * @since 6.8.0
	 * @return void
	 */
	public function unschedule_cron() {
		esf_cron_unschedule_recurring_job( self::CRON_HOOK );
	}
}
