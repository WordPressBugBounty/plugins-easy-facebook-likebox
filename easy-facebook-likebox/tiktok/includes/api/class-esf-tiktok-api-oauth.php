<?php
/**
 * TikTok OAuth REST API
 *
 * Builds the Login Kit bridge URL with nonce-protected return URL, state, and
 * optional PKCE (HEX SHA256 challenge — Desktop Login Kit encoding).
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok/API
 * @since 6.8.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_TikTok_API_OAuth
 *
 * @since 6.8.0
 */
class ESF_TikTok_API_OAuth {

	/**
	 * ESF bridge URL for TikTok Login Kit.
	 *
	 * @since 6.8.0
	 * @var string
	 */
	const BRIDGE_URL = 'https://easysocialfeed.com/apps/tiktok/index.php';

	/**
	 * OAuth state / PKCE transient TTL (seconds).
	 *
	 * @since 6.8.0
	 * @var int
	 */
	const STATE_TTL = 900;

	/**
	 * Default scopes (basic + video list + optional profile/stats).
	 *
	 * @since 6.8.0
	 * @var string
	 */
	const DEFAULT_SCOPES = 'user.info.basic,video.list,user.info.profile,user.info.stats';

	/**
	 * Register REST routes.
	 *
	 * @since 6.8.0
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/tiktok/oauth/url',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_oauth_url' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/tiktok/oauth/manual',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'stage_manual_token' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
				'args'                => array(
					'access_token' => array(
						'description' => __( 'TikTok access token.', 'easy-facebook-likebox' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Write permission callback (capability + REST nonce).
	 *
	 * @since 6.9.0
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function write_permissions_check( $request ) {
		if ( ! esf_tiktok_user_can_manage() ) {
			return false;
		}
		$nonce = $request instanceof WP_REST_Request ? $request->get_header( 'X-WP-Nonce' ) : '';
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_cookie_invalid_nonce',
				__( 'Cookie check failed.', 'easy-facebook-likebox' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Permission callback.
	 *
	 * @since 6.8.0
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function permissions_check( $request ) {
		if ( $request instanceof WP_REST_Request && 'GET' !== $request->get_method() ) {
			return false;
		}

		return esf_tiktok_user_can_manage();
	}

	/**
	 * Build the ESF OAuth bridge URL.
	 *
	 * Free tier: one connected account; reconnect allowlisted when an existing
	 * account needs reconnect.
	 *
	 * @since 6.8.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_oauth_url( $request ) {
		unset( $request );

		$repo     = ESF_TikTok_Account_Repository::get_instance();
		$has_plan = function_exists( 'esf_tiktok_has_tiktok_plan' ) && esf_tiktok_has_tiktok_plan();
		if ( ! $has_plan
			&& $repo->get_total_connected_account_count() >= 1
			&& ! $repo->has_account_needing_reconnect()
		) {
			return new WP_Error(
				'account_limit_reached',
				__( 'Your plan allows one connected TikTok account per site. Disconnect or reconnect the existing account, or upgrade to add more.', 'easy-facebook-likebox' ),
				array( 'status' => 403 )
			);
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'invalid_user',
				__( 'Could not determine current user for OAuth request.', 'easy-facebook-likebox' ),
				array( 'status' => 401 )
			);
		}

		$bridge_url = apply_filters( 'esf_tiktok_oauth_bridge_url', self::BRIDGE_URL );
		$nonce      = wp_create_nonce( 'esf_tiktok_connect' );
		$return_url = add_query_arg(
			array(
				'page'           => 'esf-tiktok',
				'esf_tt_connect' => '1',
				'esf_tt_nonce'   => $nonce,
				'esf_tt_popup'   => '1',
			),
			admin_url( 'admin.php' )
		);

		try {
			$pkce  = self::generate_pkce_pair();
			$state = self::generate_random_hex( 32 );
		} catch ( Exception $e ) {
			return new WP_Error(
				'oauth_entropy_error',
				__( 'Could not initialize OAuth request. Please try again.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		set_transient( self::get_state_key( $user_id ), $state, self::STATE_TTL );
		set_transient( self::get_verifier_key( $user_id ), $pkce['code_verifier'], self::STATE_TTL );

		$scope = (string) apply_filters( 'esf_tiktok_oauth_scope', self::DEFAULT_SCOPES );

		$connect_url = add_query_arg(
			array(
				'redirect'              => rawurlencode( $return_url ),
				'state'                 => $state,
				'code_challenge'        => $pkce['code_challenge'],
				'code_challenge_method' => 'S256',
				'code_verifier'         => rawurlencode( $pkce['code_verifier'] ),
				'scope'                 => $scope,
			),
			$bridge_url
		);

		return rest_ensure_response( array( 'url' => esc_url_raw( $connect_url ) ) );
	}

	/**
	 * Consume and clear stored state + PKCE verifier for a user.
	 *
	 * @since 6.8.0
	 * @param int $user_id WordPress user ID.
	 * @return array{state:string,code_verifier:string}
	 */
	public static function consume_oauth_context( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return array(
				'state'         => '',
				'code_verifier' => '',
			);
		}

		$state         = (string) get_transient( self::get_state_key( $user_id ) );
		$code_verifier = (string) get_transient( self::get_verifier_key( $user_id ) );

		delete_transient( self::get_state_key( $user_id ) );
		delete_transient( self::get_verifier_key( $user_id ) );

		return array(
			'state'         => $state,
			'code_verifier' => $code_verifier,
		);
	}

	/**
	 * State transient key.
	 *
	 * @since 6.8.0
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function get_state_key( $user_id ) {
		return 'esf_tt_oauth_state_' . (int) $user_id;
	}

	/**
	 * PKCE verifier transient key.
	 *
	 * @since 6.8.0
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function get_verifier_key( $user_id ) {
		return 'esf_tt_oauth_verifier_' . (int) $user_id;
	}

	/**
	 * Generate PKCE verifier + challenge.
	 *
	 * Challenge uses HEX(SHA256(verifier)) per TikTok Desktop Login Kit docs
	 * (not X's base64url encoding). Verify against current Login Kit Web docs
	 * before changing.
	 *
	 * @since 6.8.0
	 * @return array{code_verifier:string,code_challenge:string}
	 */
	private static function generate_pkce_pair() {
		// RFC 7636 unreserved charset; length within TikTok's accepted range.
		$code_verifier  = rtrim( strtr( base64_encode( random_bytes( 64 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$code_challenge = hash( 'sha256', $code_verifier ); // HEX, not base64url.

		return array(
			'code_verifier'  => $code_verifier,
			'code_challenge' => $code_challenge,
		);
	}

	/**
	 * Random hex string for OAuth state.
	 *
	 * @since 6.8.0
	 * @param int $bytes Bytes before hex encoding.
	 * @return string
	 */
	private static function generate_random_hex( $bytes = 32 ) {
		return bin2hex( random_bytes( max( 16, (int) $bytes ) ) );
	}

	/**
	 * POST pasted access token — validate and connect the account.
	 *
	 * @since 6.9.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function stage_manual_token( $request ) {
		$token = $request instanceof WP_REST_Request
			? $request->get_param( 'access_token' )
			: '';
		$token = is_string( $token ) ? $token : '';

		$result = ESF_TikTok_Manual_Oauth_Service::submit_manual_access_token(
			get_current_user_id(),
			$token
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
