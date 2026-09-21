<?php
/**
 * Facebook OAuth Callback Listener
 *
 * Handles popup callback returns for modern Facebook Page connect.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Auth_Callback_Listener
 *
 * @since 6.7.9
 */
class ESF_Facebook_Auth_Callback_Listener {

	use ESF_Facebook_Singleton;

	/**
	 * Optional detail for the OAuth popup postMessage when status is `error`.
	 *
	 * @var string
	 */
	private $oauth_popup_error_detail = '';

	/**
	 * Handle callback.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! isset( $_GET['page'], $_GET['esf_fb_connect'] ) || 'esf-facebook' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			status_header( 403 );
			wp_die(
				esc_html__( 'You do not have permission to connect Facebook Pages.', 'easy-facebook-likebox' ),
				esc_html__( '403 Forbidden', 'easy-facebook-likebox' )
			);
		}

		$nonce = isset( $_GET['esf_fb_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['esf_fb_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'esf_facebook_connect' ) ) {
			status_header( 401 );
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'easy-facebook-likebox' ),
				esc_html__( '401 Unauthorized', 'easy-facebook-likebox' )
			);
		}

		$status = $this->process_callback();

		$is_popup = isset( $_GET['esf_fb_popup'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['esf_fb_popup'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $is_popup ) {
			$this->render_popup_response( $status );
			exit;
		}

		wp_safe_redirect( $this->get_redirect_url( $status ) );
		exit;
	}

	/**
	 * Process callback query args.
	 *
	 * @return string connected|error|pick_pages|limit_reached
	 */
	private function process_callback() {
		$this->oauth_popup_error_detail = '';

		if ( ! empty( $_GET['error'] ) && is_string( wp_unslash( $_GET['error'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->oauth_popup_error_detail = sanitize_text_field( wp_unslash( $_GET['error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'error';
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved = (string) get_transient( 'esf_fb_oauth_state_' . get_current_user_id() );
		delete_transient( 'esf_fb_oauth_state_' . get_current_user_id() );
		if ( '' !== $saved && '' !== $state && ! hash_equals( $saved, $state ) ) {
			$this->oauth_popup_error_detail = 'state_mismatch';
			return 'error';
		}

		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'connected'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed = array( 'connected', 'error', 'limit_reached' );
		if ( ! in_array( $status, $allowed, true ) ) {
			$status = 'connected';
		}

		if ( 'connected' !== $status ) {
			return $status;
		}

		$fb = $this->maybe_stage_page_pick_flow();
		if ( 'error' === $fb ) {
			return 'error';
		}
		if ( 'pick_pages' === $fb ) {
			return 'pick_pages';
		}

		return $status;
	}

	/**
	 * Stage Page candidates after the bridge returns a user token.
	 *
	 * @return string pick_pages|error
	 */
	private function maybe_stage_page_pick_flow() {
		$token = isset( $_GET['esf_fb_user_token'] ) ? sanitize_text_field( wp_unslash( $_GET['esf_fb_user_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $token && isset( $_GET['access_token'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_GET['access_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( '' === $token ) {
			$this->oauth_popup_error_detail = 'missing_esf_fb_user_token';
			return 'error';
		}

		$expires_in = isset( $_GET['esf_fb_expires_in'] ) ? absint( wp_unslash( $_GET['esf_fb_expires_in'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $expires_in <= 0 && isset( $_GET['expires_in'] ) ) {
			$expires_in = absint( wp_unslash( $_GET['expires_in'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$staged = ESF_Facebook_Oauth_Service::stage_pick_session(
			get_current_user_id(),
			$token,
			$expires_in
		);

		if ( is_wp_error( $staged ) ) {
			$msg = wp_strip_all_tags( (string) $staged->get_error_message() );
			if ( '' === $msg ) {
				$msg = (string) $staged->get_error_code();
			}
			$this->oauth_popup_error_detail = strlen( $msg ) > 240 ? substr( $msg, 0, 237 ) . '...' : $msg;
			return 'error';
		}

		return 'pick_pages';
	}

	/**
	 * Redirect URL after non-popup callback (and popup fallback navigation).
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function get_redirect_url( $status ) {
		$status = sanitize_key( (string) $status );

		if ( 'pick_pages' === $status ) {
			return add_query_arg(
				array(
					'page'        => 'esf-facebook',
					'esf_fb_pick' => '1',
				),
				admin_url( 'admin.php' )
			);
		}

		$args = array(
			'page'        => 'esf-facebook',
			'esf_fb_done' => '1',
			'status'      => $status,
		);
		if ( 'error' === $status && '' !== $this->oauth_popup_error_detail ) {
			$args['esf_fb_error'] = rawurlencode( $this->oauth_popup_error_detail );
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Allowed postMessage target origins (admin vs home vs www mismatches).
	 *
	 * @return array<int,string>
	 */
	private function get_oauth_post_message_target_origins() {
		$candidates = array(
			admin_url(),
			site_url(),
			home_url(),
		);
		if ( function_exists( 'network_home_url' ) && is_multisite() ) {
			$candidates[] = network_home_url();
		}

		$origins = array();
		foreach ( $candidates as $url ) {
			$origin = $this->origin_from_url( (string) $url );
			if ( '' !== $origin ) {
				$origins[ $origin ] = true;
			}
		}

		return array_keys( $origins );
	}

	/**
	 * Parse scheme://host[:port] from a full URL.
	 *
	 * @param string $url Full URL.
	 * @return string
	 */
	private function origin_from_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}

	/**
	 * Minimal popup HTML that postMessages status to opener (Instagram/LinkedIn shape).
	 *
	 * @param string $status Status.
	 * @return void
	 */
	private function render_popup_response( $status ) {
		$redirect_url = esc_url_raw( $this->get_redirect_url( $status ) );
		$origins      = $this->get_oauth_post_message_target_origins();

		$body = array(
			'type'        => 'esf_facebook_oauth_complete',
			'module'      => 'facebook',
			'status'      => sanitize_key( (string) $status ),
			'redirectUrl' => $redirect_url,
		);
		if ( 'error' === sanitize_key( (string) $status ) && '' !== $this->oauth_popup_error_detail ) {
			$body['errorDetail'] = sanitize_text_field( $this->oauth_popup_error_detail );
		}

		$payload = wp_json_encode( $body );
		if ( ! is_string( $payload ) ) {
			$payload = '{"type":"esf_facebook_oauth_complete","module":"facebook","status":"error","errorDetail":"encode_failed"}';
		}

		$origins_json = wp_json_encode( $origins );
		if ( ! is_string( $origins_json ) ) {
			$origins_json = '[]';
		}

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		}

		echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Facebook Account Connected', 'easy-facebook-likebox' ) . '</title></head><body>';
		echo '<p>' . esc_html__( 'Finishing authentication. You can close this window if it does not close automatically.', 'easy-facebook-likebox' ) . '</p>';
		echo '<script>(function(){var payload=' . $payload . ';var origins=' . $origins_json . ';'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON payload for postMessage.
		echo 'try{if(window.opener&&!window.opener.closed&&origins&&origins.length){for(var i=0;i<origins.length;i++){try{window.opener.postMessage(payload,origins[i]);}catch(e){}}}}catch(e){}';
		echo 'try{window.close();}catch(e){}';
		echo 'setTimeout(function(){window.location.href=' . wp_json_encode( $redirect_url ) . ';},800);';
		echo '}());</script>';
		echo '<p><a href="' . esc_url( $redirect_url ) . '">' . esc_html__( 'Return to Facebook dashboard', 'easy-facebook-likebox' ) . '</a></p>';
		echo '</body></html>';
	}
}
