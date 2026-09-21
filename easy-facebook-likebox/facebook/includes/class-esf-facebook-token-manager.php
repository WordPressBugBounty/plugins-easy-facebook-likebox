<?php
/**
 * Facebook automatic token refresh (WP-Cron).
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Token_Manager
 *
 * @since 6.7.9
 */
class ESF_Facebook_Token_Manager {

	use ESF_Facebook_Singleton;

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'esf_facebook_auto_refresh_tokens';

	/**
	 * Register cron handler and schedule.
	 *
	 * @since 6.7.9
	 * @return void
	 */
	public function init() {
		if ( function_exists( 'esf_cron_attach_thirty_minutes_recurring_job' ) ) {
			esf_cron_attach_thirty_minutes_recurring_job(
				self::CRON_HOOK,
				array( $this, 'auto_refresh_expiring_tokens' )
			);
		}
	}

	/**
	 * Refresh accounts whose token_expires_at is within the buffer window.
	 *
	 * Also emails admins about dedicated Events Page tokens that are expired
	 * or within 7 days of expiry (same Reconnect alerts setting). Refreshes
	 * live_videos status for Pages that have Live Display feeds enabled.
	 * Skips live refresh when no such feeds exist (default). Does not
	 * register a separate cron schedule.
	 *
	 * @since 6.7.9
	 * @return void
	 */
	public function auto_refresh_expiring_tokens() {
		$repo     = ESF_Facebook_Account_Repository::get_instance();
		$accounts = $repo->get_accounts_due_for_token_refresh( 600, 50 );

		if ( ! empty( $accounts ) ) {
			foreach ( $accounts as $account ) {
				$patch = ESF_Facebook_Token_Refresh_Client::refresh_tokens( $account );
				if ( is_wp_error( $patch ) ) {
					$this->handle_refresh_failure( $repo, $account, $patch );
					continue;
				}

				$patch['facebook_user_id'] = (string) $account->facebook_user_id;
				$patch['status']           = 'active';
				$id                        = $repo->upsert( $patch );
				if ( false === $id ) {
					$repo->update_status( (int) $account->id, 'expired' );
					if ( class_exists( 'ESF_Facebook_Token_Notifications' ) ) {
						ESF_Facebook_Token_Notifications::maybe_notify_reconnect_required( (int) $account->id );
					}
				}
			}
		}

		if ( class_exists( 'ESF_Facebook_Token_Notifications' ) ) {
			ESF_Facebook_Token_Notifications::notify_expiring_events_tokens();
		}

		if ( class_exists( 'ESF_Facebook_Renderer' ) && method_exists( 'ESF_Facebook_Renderer', 'refresh_live_status_for_enabled_pages__premium_only' ) ) {
			ESF_Facebook_Renderer::refresh_live_status_for_enabled_pages__premium_only();
		}
	}

	/**
	 * Apply classified status after a failed token refresh.
	 *
	 * @since 6.7.9
	 * @param ESF_Facebook_Account_Repository $repo    Repository.
	 * @param object                          $account Account row.
	 * @param WP_Error                        $error   Refresh failure.
	 * @return void
	 */
	private function handle_refresh_failure( $repo, $account, $error ) {
		$account_id = (int) $account->id;

		if ( ! class_exists( 'ESF_Meta_Graph_Error' ) ) {
			$repo->update_status( $account_id, 'expired' );
			if ( class_exists( 'ESF_Facebook_Token_Notifications' ) ) {
				ESF_Facebook_Token_Notifications::maybe_notify_reconnect_required( $account_id );
			}
			return;
		}

		$action = ESF_Meta_Graph_Error::classify_wp_error( $error );

		if ( ESF_Meta_Graph_Error::is_reconnect_required( $action ) ) {
			$repo->update_status( $account_id, 'invalid' );
			if ( class_exists( 'ESF_Facebook_Token_Notifications' ) ) {
				ESF_Facebook_Token_Notifications::maybe_notify_reconnect_required( $account_id );
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
		if ( class_exists( 'ESF_Facebook_Token_Notifications' ) ) {
			ESF_Facebook_Token_Notifications::maybe_notify_reconnect_required( $account_id );
		}
	}

	/**
	 * Clear scheduled event.
	 *
	 * @since 6.7.9
	 * @return void
	 */
	public function unschedule_cron() {
		if ( function_exists( 'esf_cron_unschedule_recurring_job' ) ) {
			esf_cron_unschedule_recurring_job( self::CRON_HOOK );
		}
	}
}
