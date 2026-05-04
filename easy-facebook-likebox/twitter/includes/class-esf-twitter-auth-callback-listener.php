<?php
/**
 * Twitter OAuth Callback Listener
 *
 * Processes the OAuth callback from the ESF bridge after the user
 * authorises the X app. Saves tokens and account data to the DB.
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
 * Class ESF_Twitter_Auth_Callback_Listener
 *
 * @since 6.7.6
 */
class ESF_Twitter_Auth_Callback_Listener {

	use ESF_Twitter_Singleton;

	/**
	 * Handle the OAuth callback.
	 *
	 * Runs on admin_init. Validates the nonce, reads tokens from the
	 * query string, saves the account, then redirects back to the dashboard.
	 *
	 * @since 6.7.6
	 * @return void
	 */
	public function handle() {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! isset( $_GET['page'], $_GET['esf_tw_connect'] ) || 'esf-twitter' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			status_header( 403 );
			wp_die(
				esc_html__( 'You do not have permission to connect X accounts.', 'easy-facebook-likebox' ),
				esc_html__( '403 Forbidden', 'easy-facebook-likebox' )
			);
		}

		// Nonce verification.
		$nonce = isset( $_GET['esf_tw_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['esf_tw_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'esf_twitter_connect' ) ) {
			status_header( 401 );
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'easy-facebook-likebox' ),
				esc_html__( '401 Unauthorized', 'easy-facebook-likebox' )
			);
		}

		$status = $this->process_oauth_callback();

		$is_popup = isset( $_GET['esf_tw_popup'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['esf_tw_popup'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $is_popup ) {
			$this->render_popup_response( $status );
			exit;
		}

		wp_safe_redirect( $this->get_redirect_url( $status ) );
		exit;
	}

	/**
	 * Process OAuth callback query params and connect account.
	 *
	 * @since 6.7.6
	 * @return string Result status for dashboard UI.
	 */
	private function process_oauth_callback() {
		$oauth_error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $oauth_error ) {
			return 'error';
		}

		$token_status = $this->process_bridge_tokens_callback();
		if ( '' !== $token_status ) {
			return $token_status;
		}

		$code          = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state         = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id       = get_current_user_id();
		$pkce_context  = ESF_Twitter_API_OAuth::consume_pkce_context( $user_id );
		$stored_state  = isset( $pkce_context['state'] ) ? (string) $pkce_context['state'] : '';
		$state_nonce   = ESF_Twitter_API_OAuth::extract_state_nonce( $state );
		$code_verifier = isset( $pkce_context['code_verifier'] ) ? (string) $pkce_context['code_verifier'] : '';

		if ( '' === $code || '' === $state || '' === $stored_state || '' === $state_nonce || '' === $code_verifier || ! hash_equals( $stored_state, $state_nonce ) ) {
			return 'error';
		}

		$repo = ESF_Twitter_Account_Repository::get_instance();
		// Free plan: one connected account per site.
		if ( ( ! function_exists( 'esf_twitter_has_twitter_plan' ) || ! esf_twitter_has_twitter_plan() )
			&& $repo->get_total_connected_account_count() >= 1
		) {
			return 'limit_reached';
		}

		$api_service = ESF_Twitter_API_Service::get_instance();
		$token_data  = $api_service->exchange_authorization_code( $code, $code_verifier );
		if ( is_wp_error( $token_data ) || empty( $token_data['access_token'] ) ) {
			return 'error';
		}

		$account_id = $repo->upsert_from_tokens(
			$user_id,
			(string) $token_data['access_token'],
			isset( $token_data['refresh_token'] ) ? (string) $token_data['refresh_token'] : '',
			isset( $token_data['expires_in'] ) ? (int) $token_data['expires_in'] : 7200
		);

		return false !== $account_id ? 'connected' : 'error';
	}

	/**
	 * Process callback where bridge already exchanged code for tokens.
	 *
	 * @since 6.7.6
	 * @return string Empty string when token params are not present, otherwise status.
	 */
	private function process_bridge_tokens_callback() {
		$access_token = '';
		if ( isset( $_GET['tw_access_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$access_token = sanitize_text_field( wp_unslash( $_GET['tw_access_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['access_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$access_token = sanitize_text_field( wp_unslash( $_GET['access_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( '' === $access_token ) {
			return '';
		}

		$refresh_token = '';
		if ( isset( $_GET['tw_refresh_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$refresh_token = sanitize_text_field( wp_unslash( $_GET['tw_refresh_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['refresh_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$refresh_token = sanitize_text_field( wp_unslash( $_GET['refresh_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$expires_in = isset( $_GET['expires_in'] ) ? (int) $_GET['expires_in'] : 7200; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id    = get_current_user_id();
		$repo       = ESF_Twitter_Account_Repository::get_instance();

		if ( ( ! function_exists( 'esf_twitter_has_twitter_plan' ) || ! esf_twitter_has_twitter_plan() )
			&& $repo->get_total_connected_account_count() >= 1
		) {
			return 'limit_reached';
		}

		$account_id = $repo->upsert_from_tokens( $user_id, $access_token, $refresh_token, $expires_in );

		return false !== $account_id ? 'connected' : 'error';
	}

	/**
	 * Get the post-auth dashboard redirect URL.
	 *
	 * @since 6.7.6
	 *
	 * @param string $status OAuth completion status.
	 * @return string
	 */
	private function get_redirect_url( $status ) {
		return add_query_arg(
			array(
				'page'        => 'esf-twitter',
				'esf_tw_done' => '1',
				'status'      => $status,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render a popup-safe HTML response.
	 *
	 * Sends a postMessage event to the opener window (same origin expected)
	 * and attempts to close the popup. Includes a fallback link when blocked.
	 *
	 * @since 6.7.6
	 *
	 * @param string $status OAuth completion status.
	 * @return void
	 */
	private function render_popup_response( $status ) {
		$redirect_url = esc_url_raw( $this->get_redirect_url( $status ) );
		$admin_url    = admin_url();
		$origin_parts = wp_parse_url( $admin_url );
		$origin       = '';
		if ( is_array( $origin_parts ) && isset( $origin_parts['scheme'], $origin_parts['host'] ) ) {
			$origin = $origin_parts['scheme'] . '://' . $origin_parts['host'];
			if ( isset( $origin_parts['port'] ) ) {
				$origin .= ':' . (int) $origin_parts['port'];
			}
		}
		if ( '' === $origin ) {
			$origin = esc_url_raw( site_url() );
		}

		$payload = wp_json_encode(
			array(
				'type'        => 'esf_twitter_oauth_complete',
				'module'      => 'twitter',
				'status'      => sanitize_key( (string) $status ),
				'redirectUrl' => $redirect_url,
			)
		);

		if ( ! is_string( $payload ) ) {
			$payload = '{}';
		}

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		}

		echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'X Account Connected', 'easy-facebook-likebox' ) . '</title></head><body>';
		echo '<p>' . esc_html__( 'Finishing authentication. You can close this window if it does not close automatically.', 'easy-facebook-likebox' ) . '</p>';
		echo '<script>(function(){var payload=' . $payload . ';';
		echo 'try{if(window.opener&&!window.opener.closed){window.opener.postMessage(payload,' . wp_json_encode( $origin ) . ');}}catch(e){}';
		echo 'try{window.close();}catch(e){}';
		echo 'setTimeout(function(){window.location.href=' . wp_json_encode( $redirect_url ) . ';},1000);';
		echo '}());</script>';
		echo '<p><a href="' . esc_url( $redirect_url ) . '">' . esc_html__( 'Return to Twitter dashboard', 'easy-facebook-likebox' ) . '</a></p>';
		echo '</body></html>';
	}
}
