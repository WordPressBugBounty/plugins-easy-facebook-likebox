<?php
/**
 * TikTok OAuth Callback Listener
 *
 * Processes the Login Kit callback from the ESF bridge after the user
 * authorises the TikTok app. Saves tokens and account data to the DB.
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
 * Class ESF_TikTok_Auth_Callback_Listener
 *
 * @since 6.8.0
 */
class ESF_TikTok_Auth_Callback_Listener {

	use ESF_TikTok_Singleton;

	/**
	 * Constructor.
	 *
	 * @since 6.8.0
	 */
	private function __construct() {}

	/**
	 * Handle the OAuth callback on admin_init.
	 *
	 * @since 6.8.0
	 * @return void
	 */
	public function handle() {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! isset( $_GET['page'], $_GET['esf_tt_connect'] ) || 'esf-tiktok' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			status_header( 403 );
			wp_die(
				esc_html__( 'You do not have permission to connect TikTok accounts.', 'easy-facebook-likebox' ),
				esc_html__( '403 Forbidden', 'easy-facebook-likebox' )
			);
		}

		$nonce = isset( $_GET['esf_tt_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['esf_tt_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'esf_tiktok_connect' ) ) {
			status_header( 401 );
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'easy-facebook-likebox' ),
				esc_html__( '401 Unauthorized', 'easy-facebook-likebox' )
			);
		}

		$status = $this->process_oauth_callback();

		$is_popup = isset( $_GET['esf_tt_popup'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['esf_tt_popup'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

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
	 * Primary path: bridge already exchanged the code and returns tokens.
	 * Optional: validate stored state when present.
	 *
	 * @since 6.8.0
	 * @return string Result status for dashboard UI.
	 */
	private function process_oauth_callback() {
		$oauth_error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $oauth_error ) {
			return 'error';
		}

		$user_id = get_current_user_id();
		$context = ESF_TikTok_API_OAuth::consume_oauth_context( $user_id );
		$stored  = isset( $context['state'] ) ? (string) $context['state'] : '';
		$state   = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// When a state was issued, require a matching return state.
		if ( '' !== $stored ) {
			if ( '' === $state || ! hash_equals( $stored, $state ) ) {
				return 'error';
			}
		}

		return $this->process_bridge_tokens_callback();
	}

	/**
	 * Persist account from bridge-returned tokens.
	 *
	 * @since 6.8.0
	 * @return string Status: connected|error|limit_reached.
	 */
	private function process_bridge_tokens_callback() {
		$access_token = '';
		if ( isset( $_GET['access_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$access_token = sanitize_text_field( wp_unslash( $_GET['access_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['tt_access_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$access_token = sanitize_text_field( wp_unslash( $_GET['tt_access_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( '' === $access_token ) {
			return 'error';
		}

		$refresh_token = '';
		if ( isset( $_GET['refresh_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$refresh_token = sanitize_text_field( wp_unslash( $_GET['refresh_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['tt_refresh_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$refresh_token = sanitize_text_field( wp_unslash( $_GET['tt_refresh_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$expires_in = isset( $_GET['expires_in'] ) ? (int) $_GET['expires_in'] : 86400; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $expires_in <= 0 ) {
			$expires_in = 86400;
		}

		$refresh_expires_in = isset( $_GET['refresh_expires_in'] ) ? (int) $_GET['refresh_expires_in'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$scopes = '';
		if ( isset( $_GET['scope'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$scopes = sanitize_text_field( wp_unslash( $_GET['scope'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$open_id_hint = null;
		if ( isset( $_GET['open_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$open_id_hint = sanitize_text_field( wp_unslash( $_GET['open_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$user_id = get_current_user_id();
		$repo    = ESF_TikTok_Account_Repository::get_instance();

		$account_id = $repo->upsert_from_tokens(
			$user_id,
			$access_token,
			$refresh_token,
			$expires_in,
			$refresh_expires_in,
			$scopes,
			$open_id_hint
		);

		if ( false === $account_id ) {
			$has_plan = function_exists( 'esf_tiktok_has_tiktok_plan' ) && esf_tiktok_has_tiktok_plan();
			if ( ! $has_plan && $repo->get_total_connected_account_count() >= 1 ) {
				return 'limit_reached';
			}
			return 'error';
		}

		if ( function_exists( 'esf_review_request_record_milestone' ) ) {
			esf_review_request_record_milestone( 'account_connected', 'tiktok' );
		}

		return 'connected';
	}

	/**
	 * Post-auth dashboard redirect URL.
	 *
	 * @since 6.8.0
	 * @param string $status OAuth status.
	 * @return string
	 */
	private function get_redirect_url( $status ) {
		return add_query_arg(
			array(
				'page'        => 'esf-tiktok',
				'esf_tt_done' => '1',
				'status'      => $status,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Collect allowed postMessage origins (admin / site / home / www variants).
	 *
	 * @since 6.8.0
	 * @return array<int,string>
	 */
	private function get_post_message_origins() {
		$candidates = array(
			admin_url(),
			site_url(),
			home_url(),
		);

		$origins = array();
		foreach ( $candidates as $url ) {
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				continue;
			}
			$origin = $parts['scheme'] . '://' . $parts['host'];
			if ( isset( $parts['port'] ) ) {
				$origin .= ':' . (int) $parts['port'];
			}
			$origins[] = $origin;

			$host = (string) $parts['host'];
			if ( 0 === strpos( $host, 'www.' ) ) {
				$alt       = $parts['scheme'] . '://' . substr( $host, 4 );
				$origins[] = isset( $parts['port'] ) ? $alt . ':' . (int) $parts['port'] : $alt;
			} else {
				$alt       = $parts['scheme'] . '://www.' . $host;
				$origins[] = isset( $parts['port'] ) ? $alt . ':' . (int) $parts['port'] : $alt;
			}
		}

		$origins = array_values( array_unique( array_filter( $origins ) ) );
		return ! empty( $origins ) ? $origins : array( esc_url_raw( site_url() ) );
	}

	/**
	 * Render popup-close HTML with postMessage to the opener.
	 *
	 * @since 6.8.0
	 * @param string $status OAuth status.
	 * @return void
	 */
	private function render_popup_response( $status ) {
		$redirect_url = esc_url_raw( $this->get_redirect_url( $status ) );
		$origins      = $this->get_post_message_origins();

		$payload = wp_json_encode(
			array(
				'type'        => 'esf_tiktok_oauth_complete',
				'module'      => 'tiktok',
				'status'      => sanitize_key( (string) $status ),
				'redirectUrl' => $redirect_url,
			)
		);

		if ( ! is_string( $payload ) ) {
			$payload = '{}';
		}

		$origins_json = wp_json_encode( $origins );
		if ( ! is_string( $origins_json ) ) {
			$origins_json = '[]';
		}

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		}

		echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'TikTok Account Connected', 'easy-facebook-likebox' ) . '</title></head><body>';
		echo '<p>' . esc_html__( 'Finishing authentication. You can close this window if it does not close automatically.', 'easy-facebook-likebox' ) . '</p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from wp_json_encode for popup postMessage bootstrap.
		echo '<script>(function(){var payload=' . $payload . ';var origins=' . $origins_json . ';';
		echo 'try{if(window.opener&&!window.opener.closed){for(var i=0;i<origins.length;i++){try{window.opener.postMessage(payload,origins[i]);}catch(e){}}}}catch(e){}';
		echo 'try{window.close();}catch(e){}';
		echo 'setTimeout(function(){window.location.href=' . wp_json_encode( $redirect_url ) . ';},1000);';
		echo '}());</script>';
		echo '<p><a href="' . esc_url( $redirect_url ) . '">' . esc_html__( 'Return to TikTok dashboard', 'easy-facebook-likebox' ) . '</a></p>';
		echo '</body></html>';
	}
}
