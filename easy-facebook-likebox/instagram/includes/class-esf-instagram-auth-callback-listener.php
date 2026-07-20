<?php
/**
 * Instagram OAuth Callback Listener
 *
 * Handles popup callback returns for modern Instagram connect flows.
 * Handles `instagram_login` (token in `access_token`) and `facebook_page` (token in
 * `esf_fb_user_token`). Facebook login stages Page-backed Instagram candidates for the
 * dashboard picker via {@see ESF_Instagram_Facebook_Oauth_Service}.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_Auth_Callback_Listener
 *
 * @since 6.8.0
 */
class ESF_Instagram_Auth_Callback_Listener {

	use ESF_Instagram_Singleton;

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

		if ( ! isset( $_GET['page'], $_GET['esf_ig_connect'] ) || 'esf-instagram' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			status_header( 403 );
			wp_die(
				esc_html__( 'You do not have permission to connect Instagram accounts.', 'easy-facebook-likebox' ),
				esc_html__( '403 Forbidden', 'easy-facebook-likebox' )
			);
		}

		$nonce = isset( $_GET['esf_ig_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['esf_ig_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'esf_instagram_connect' ) ) {
			status_header( 401 );
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'easy-facebook-likebox' ),
				esc_html__( '401 Unauthorized', 'easy-facebook-likebox' )
			);
		}

		$status = $this->process_callback();

		$is_popup = isset( $_GET['esf_ig_popup'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['esf_ig_popup'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
	 * @return string connected|error|limit_reached|pick_accounts
	 */
	private function process_callback() {
		$this->oauth_popup_error_detail = '';

		// Only treat `error` as an OAuth failure when it is a non-empty string (avoid false positives from `error=0`, etc.).
		if ( ! empty( $_GET['error'] ) && is_string( wp_unslash( $_GET['error'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->oauth_popup_error_detail = sanitize_text_field( wp_unslash( $_GET['error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'error';
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved = (string) get_transient( 'esf_ig_oauth_state_' . get_current_user_id() );
		delete_transient( 'esf_ig_oauth_state_' . get_current_user_id() );
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

		$flow = isset( $_GET['esf_ig_type'] ) ? sanitize_key( wp_unslash( $_GET['esf_ig_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$flow = esf_instagram_normalize_auth_flow_type( $flow );

		if ( 'instagram_login' === $flow ) {
			if ( ! $this->maybe_persist_instagram_login_flow() ) {
				$this->oauth_popup_error_detail = 'instagram_login_persist_failed';
				return 'error';
			}
			return $status;
		}

		if ( 'facebook_page' === $flow ) {
			$fb = $this->maybe_stage_facebook_page_pick_flow();
			if ( 'error' === $fb ) {
				return 'error';
			}
			if ( 'pick_accounts' === $fb ) {
				return 'pick_accounts';
			}
		}

		return $status;
	}

	/**
	 * Stage Facebook Page + Instagram candidates after the business bridge returns a user token.
	 *
	 * @return string pick_accounts|noop|error
	 */
	private function maybe_stage_facebook_page_pick_flow() {
		$token = isset( $_GET['esf_fb_user_token'] ) ? sanitize_text_field( wp_unslash( $_GET['esf_fb_user_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $token ) {
			$this->oauth_popup_error_detail = 'missing_esf_fb_user_token';
			return 'error';
		}

		$expires_in = isset( $_GET['esf_fb_expires_in'] ) ? absint( wp_unslash( $_GET['esf_fb_expires_in'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $expires_in <= 0 && isset( $_GET['expires_in'] ) ) {
			$expires_in = absint( wp_unslash( $_GET['expires_in'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$staged = ESF_Instagram_Facebook_Oauth_Service::stage_pick_session(
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

		return 'pick_accounts';
	}

	/**
	 * When the bridge returns `esf_ig_type=instagram_login` + `access_token`, load /me and upsert esf_instagram_accounts.
	 *
	 * @return bool True if skipped or saved OK; false on failure when a token was supplied.
	 */
	private function maybe_persist_instagram_login_flow() {
		$flow = isset( $_GET['esf_ig_type'] ) ? sanitize_key( wp_unslash( $_GET['esf_ig_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'instagram_login' !== esf_instagram_normalize_auth_flow_type( $flow ) ) {
			return true;
		}

		$token = isset( $_GET['access_token'] ) ? sanitize_text_field( wp_unslash( $_GET['access_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $token ) {
			$this->oauth_popup_error_detail = 'missing_access_token';
			return false;
		}

		$expires_in = isset( $_GET['esf_ig_expires_in'] ) ? absint( wp_unslash( $_GET['esf_ig_expires_in'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$body = $this->fetch_instagram_me_for_login( $token );
		if ( ! is_object( $body ) || isset( $body->error ) ) {
			return false;
		}

		$ig_id = isset( $body->id ) ? sanitize_text_field( (string) $body->id ) : '';
		if ( '' === $ig_id ) {
			return false;
		}

		$username = isset( $body->username ) ? sanitize_text_field( (string) $body->username ) : '';
		$name     = isset( $body->name ) ? sanitize_text_field( (string) $body->name ) : '';
		$display  = '' !== $name ? $name : $username;
		$bio      = isset( $body->biography ) ? sanitize_textarea_field( (string) $body->biography ) : '';
		$website  = isset( $body->website ) ? esc_url_raw( (string) $body->website ) : '';
		$pic      = isset( $body->profile_picture_url ) ? esc_url_raw( (string) $body->profile_picture_url ) : '';
		$media    = isset( $body->media_count ) ? (int) $body->media_count : 0;
		$follow   = isset( $body->followers_count ) ? (int) $body->followers_count : 0;

		$account_type = $this->map_instagram_login_account_type(
			isset( $body->account_type ) ? (string) $body->account_type : ''
		);

		$account_json = wp_json_encode( $body );
		if ( ! is_string( $account_json ) ) {
			$account_json = null;
		}

		$data = array(
			'instagram_user_id'  => $ig_id,
			'account_type'       => $account_type,
			'username'           => $username,
			'display_name'       => $display,
			'biography'          => $bio,
			'website'            => $website,
			'profile_image_url'  => $pic,
			'followers_count'    => $follow,
			'media_count'        => $media,
			'access_token'       => $token,
			'page_access_token'  => null,
			'facebook_page_id'   => null,
			'facebook_page_name' => null,
			'auth_source'        => 'instagram_login',
			'status'             => 'active',
			'stats_refreshed_at' => current_time( 'mysql', true ),
			'account_data'       => $account_json,
		);

		if ( $expires_in > 0 ) {
			$data['token_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + $expires_in );
		}

		$id = ESF_Instagram_Account_Repository::get_instance()->upsert( $data );
		if ( false !== $id && function_exists( 'esf_review_request_record_milestone' ) ) {
			esf_review_request_record_milestone( 'account_connected', 'instagram' );
		}
		return false !== $id;
	}

	/**
	 * Load graph.instagram.com/me for an Instagram user access token (Instagram Login).
	 *
	 * @param string $token Long- or short-lived user token.
	 * @return object|null Decoded body object or null on failure.
	 */
	private function fetch_instagram_me_for_login( $token ) {
		$full_fields = implode(
			',',
			array(
				'id',
				'username',
				'account_type',
				'media_count',
				'name',
				'profile_picture_url',
				'biography',
				'website',
				'followers_count',
			)
		);
		$minimal      = 'id,username,account_type,media_count';

		foreach ( array( $full_fields, $minimal ) as $fields ) {
			$url      = add_query_arg(
				array(
					'fields'       => $fields,
					'access_token' => $token,
				),
				'https://graph.instagram.com/me'
			);
			$response = wp_remote_get( $url, array( 'timeout' => 25 ) );
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 400 ) {
				continue;
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), false );
			if ( is_object( $body ) && ! isset( $body->error ) ) {
				return $body;
			}
		}

		return null;
	}

	/**
	 * Map Graph `account_type` to repository enum.
	 *
	 * @param string $raw Raw account_type from graph.instagram.com/me.
	 * @return string personal|business|creator
	 */
	private function map_instagram_login_account_type( $raw ) {
		$key = strtoupper( str_replace( ' ', '_', trim( (string) $raw ) ) );
		if ( in_array( $key, array( 'BUSINESS', 'BUSINESS_ACCOUNT', 'MEDIA_BUSINESS' ), true ) ) {
			return 'business';
		}
		if ( in_array( $key, array( 'MEDIA_CREATOR', 'CREATOR' ), true ) ) {
			return 'creator';
		}
		return 'personal';
	}

	/**
	 * Build redirect URL.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function get_redirect_url( $status ) {
		return add_query_arg(
			array(
				'page'        => 'esf-instagram',
				'esf_ig_done' => '1',
				'status'      => sanitize_key( (string) $status ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build allowed postMessage target origins for the OAuth popup (admin vs home vs www mismatches).
	 *
	 * @since 6.8.0
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
	 * @since 6.8.0
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
	 * Render popup close response page.
	 *
	 * @param string $status Status.
	 * @return void
	 */
	private function render_popup_response( $status ) {
		$redirect_url = esc_url_raw( $this->get_redirect_url( $status ) );
		$origins      = $this->get_oauth_post_message_target_origins();

		$body = array(
			'type'        => 'esf_instagram_oauth_complete',
			'module'      => 'instagram',
			'status'      => sanitize_key( (string) $status ),
			'redirectUrl' => $redirect_url,
		);
		if ( 'error' === sanitize_key( (string) $status ) && '' !== $this->oauth_popup_error_detail ) {
			$body['errorDetail'] = sanitize_text_field( $this->oauth_popup_error_detail );
		}

		$payload = wp_json_encode( $body );
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

		echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Instagram Account Connected', 'easy-facebook-likebox' ) . '</title></head><body>';
		echo '<p>' . esc_html__( 'Finishing authentication. You can close this window if it does not close automatically.', 'easy-facebook-likebox' ) . '</p>';
		echo '<script>(function(){var payload=' . $payload . ';var origins=' . $origins_json . ';';
		echo 'try{if(window.opener&&!window.opener.closed&&origins&&origins.length){for(var i=0;i<origins.length;i++){try{window.opener.postMessage(payload,origins[i]);}catch(e){}}}}catch(e){}';
		echo 'try{window.close();}catch(e){}';
		echo 'setTimeout(function(){window.location.href=' . wp_json_encode( $redirect_url ) . ';},800);';
		echo '}());</script>';
		echo '<p><a href="' . esc_url( $redirect_url ) . '">' . esc_html__( 'Return to Instagram dashboard', 'easy-facebook-likebox' ) . '</a></p>';
		echo '</body></html>';
	}
}
