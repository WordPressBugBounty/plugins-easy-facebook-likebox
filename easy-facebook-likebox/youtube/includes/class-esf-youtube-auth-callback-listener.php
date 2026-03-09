<?php
/**
 * YouTube OAuth Callback Listener
 *
 * @package Easy_Social_Feed
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth Callback Listener Class
 *
 * Processes OAuth callbacks from external authorization server.
 *
 * @since 6.7.5
 */
class ESF_YouTube_Auth_Callback_Listener {

	use ESF_YouTube_Singleton;

	/**
	 * Handle OAuth callback
	 *
	 * @since 6.7.5
	 */
	public function handle() {
		// Only proceed in admin area.
		if ( ! is_admin() ) {
			return;
		}

		// Only handle our specific callback.
		if ( ! isset( $_GET['page'], $_GET['esf_yt_connect'] ) || 'esf-youtube' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Capability check - only admins can connect accounts.
		if ( ! current_user_can( 'manage_options' ) ) {
			status_header( 403 );
			wp_die(
				esc_html__( 'You do not have permission to connect YouTube accounts.', 'easy-facebook-likebox' ),
				esc_html__( '403 Forbidden', 'easy-facebook-likebox' )
			);
		}

		// Nonce verification (required for security).
		// Note: WordPress automatically decodes $_GET values, so no need for rawurldecode().
		$nonce = isset( $_GET['esf_yt_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['esf_yt_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'esf_youtube_connect' ) ) {
			status_header( 401 );
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'easy-facebook-likebox' ),
				esc_html__( '401 Unauthorized', 'easy-facebook-likebox' )
			);
		}

		// At this point, the request is trusted as coming from a valid auth flow.
		// Read access token data from the query string if provided. Tokens are opaque
		// values that we store as-is and never output directly.
		// Note: WordPress automatically URL-decodes $_GET values.
		$access_token  = isset( $_GET['ysf_access_token'] ) ? sanitize_text_field( wp_unslash( $_GET['ysf_access_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$refresh_token = isset( $_GET['ysf_refresh_token'] ) ? sanitize_text_field( wp_unslash( $_GET['ysf_refresh_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$expires_in    = isset( $_GET['expires_in'] ) ? (int) $_GET['expires_in'] : 3600; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$status = 'error';
		if ( ! empty( $access_token ) ) {
			$user_id = get_current_user_id();
			$repo    = ESF_YouTube_Account_Repository::get_instance();

			// Free plan: one account per site only. youtube_premium or combo_premium: multiple.
			if ( ( ! function_exists( 'esf_youtube_has_youtube_plan' ) || ! esf_youtube_has_youtube_plan() ) && $repo->get_total_account_count() >= 1 ) {
				$status = 'limit_reached';
			} else {
				$account_id = $repo->upsert_from_tokens(
					$user_id,
					$access_token,
					$refresh_token,
					$expires_in
				);

				if ( false !== $account_id ) {
					$status = 'connected';
					esf_youtube_log_error(
						'Saved YouTube account from callback.',
						array(
							'user_id'    => get_current_user_id(),
							'account_id' => $account_id,
						)
					);
				} else {
					esf_youtube_log_error(
						'Failed to save YouTube account from callback.',
						array(
							'user_id' => get_current_user_id(),
						)
					);
				}
			}
		} else {
			esf_youtube_log_error(
				'YouTube auth callback missing access token.',
				array(
					'get' => $_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				)
			);
		}

		// Redirect to OAuth bridge page so popup can postMessage to opener and close.
		$redirect_url = add_query_arg(
			array(
				'page'        => 'esf-youtube',
				'esf_yt_done' => '1',
				'status'      => $status,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
