<?php
/**
 * Twitter OAuth REST API
 *
 * Provides the REST endpoint for generating the OAuth connect URL.
 * The OAuth flow itself runs on the ESF server; this class only
 * builds the entry-point URL with the nonce-protected return URL.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/API
 * @since 6.7.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Twitter_API_OAuth
 *
 * @since 6.7.6
 */
class ESF_Twitter_API_OAuth {

	/**
	 * ESF bridge URL for X OAuth.
	 *
	 * @since 6.7.6
	 * @var string
	 */
	const BRIDGE_URL = 'https://easysocialfeed.com/apps/x/index.php';
	/**
	 * PKCE context transient TTL in seconds.
	 *
	 * @since 6.7.6
	 * @var int
	 */
	const PKCE_CONTEXT_TTL = 900;
	/**
	 * Register REST API routes.
	 *
	 * @since 6.7.6
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/twitter/oauth/url',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_oauth_url' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);
	}

	/**
	 * Permission callback — admin users only.
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function permissions_check( $request ) {
		if ( $request instanceof WP_REST_Request && 'GET' !== $request->get_method() ) {
			return false;
		}

		return esf_twitter_user_can_manage();
	}

	/**
	 * Build the ESF OAuth bridge URL and return it.
	 *
	 * Enforces a one-account-per-site limit for non-Pro users.
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_oauth_url( $request ) {
		unset( $request );

		// Enforce account limit on the server side so it cannot be bypassed via the frontend.
		$repo = ESF_Twitter_Account_Repository::get_instance();
		if ( ( ! function_exists( 'esf_twitter_has_twitter_plan' ) || ! esf_twitter_has_twitter_plan() )
			&& $repo->get_total_connected_account_count() >= 1
		) {
			return new WP_Error(
				'account_limit_reached',
				__( 'Your plan allows one connected X account per site. Upgrade to add more.', 'easy-facebook-likebox' ),
				array( 'status' => 403 )
			);
		}

		$bridge_url = apply_filters( 'esf_twitter_oauth_bridge_url', self::BRIDGE_URL );
		$user_id    = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'invalid_user',
				__( 'Could not determine current user for OAuth request.', 'easy-facebook-likebox' ),
				array( 'status' => 401 )
			);
		}

		// Build the nonce-protected return URL the bridge will redirect back to.
		$nonce      = wp_create_nonce( 'esf_twitter_connect' );
		$return_url = add_query_arg(
			array(
				'page'           => 'esf-twitter',
				'esf_tw_connect' => '1',
				'esf_tw_nonce'   => $nonce,
				'esf_tw_popup'   => '1',
			),
			admin_url( 'admin.php' )
		);
		try {
			$pkce  = self::generate_pkce_pair();
			$nonce = self::generate_random_hex( 32 );
		} catch ( Exception $e ) {
			return new WP_Error(
				'oauth_entropy_error',
				__( 'Could not initialize OAuth request. Please try again.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}
		$state = $nonce;

		set_transient( self::get_pkce_state_key( $user_id ), $nonce, self::PKCE_CONTEXT_TTL );
		set_transient( self::get_pkce_verifier_key( $user_id ), $pkce['code_verifier'], self::PKCE_CONTEXT_TTL );
		$scope = (string) apply_filters( 'esf_twitter_oauth_scope', 'tweet.read users.read offline.access' );

		$connect_url = add_query_arg(
			array(
				'redirect'       => rawurlencode( $return_url ),
				'state'          => $state,
				'code_challenge' => $pkce['code_challenge'],
				'code_verifier'  => rawurlencode( $pkce['code_verifier'] ),
				'scope'          => $scope,
			),
			$bridge_url
		);

		return rest_ensure_response( array( 'url' => esc_url_raw( $connect_url ) ) );
	}

	/**
	 * Get and clear stored PKCE/state context for a user.
	 *
	 * @since 6.7.6
	 * @param int $user_id WordPress user ID.
	 * @return array{state:string,code_verifier:string}
	 */
	public static function consume_pkce_context( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return array(
				'state'         => '',
				'code_verifier' => '',
			);
		}

		$state         = (string) get_transient( self::get_pkce_state_key( $user_id ) );
		$code_verifier = (string) get_transient( self::get_pkce_verifier_key( $user_id ) );

		delete_transient( self::get_pkce_state_key( $user_id ) );
		delete_transient( self::get_pkce_verifier_key( $user_id ) );

		return array(
			'state'         => $state,
			'code_verifier' => $code_verifier,
		);
	}

	/**
	 * Extract nonce from packed OAuth state payload.
	 *
	 * @since 6.7.6
	 * @param string $state Packed state value from callback.
	 * @return string
	 */
	public static function extract_state_nonce( $state ) {
		$state   = (string) $state;
		$decoded = self::base64url_decode( (string) $state );
		if ( '' === $decoded ) {
			return $state;
		}

		$data = json_decode( $decoded, true );
		if ( ! is_array( $data ) || empty( $data['n'] ) || ! is_string( $data['n'] ) ) {
			return $state;
		}

		return $data['n'];
	}

	/**
	 * Build PKCE state transient key.
	 *
	 * @since 6.7.6
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	private static function get_pkce_state_key( $user_id ) {
		return 'esf_tw_oauth_state_' . (int) $user_id;
	}

	/**
	 * Build PKCE verifier transient key.
	 *
	 * @since 6.7.6
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	private static function get_pkce_verifier_key( $user_id ) {
		return 'esf_tw_oauth_verifier_' . (int) $user_id;
	}

	/**
	 * Generate PKCE code verifier + challenge pair.
	 *
	 * @since 6.7.6
	 * @return array{code_verifier:string,code_challenge:string}
	 */
	private static function generate_pkce_pair() {
		$verifier_bytes = random_bytes( 64 );
		$code_verifier  = self::base64url_encode( $verifier_bytes );
		$code_challenge = self::base64url_encode( hash( 'sha256', $code_verifier, true ) );

		return array(
			'code_verifier'  => $code_verifier,
			'code_challenge' => $code_challenge,
		);
	}

	/**
	 * Generate random hex string.
	 *
	 * @since 6.7.6
	 * @param int $bytes Number of random bytes before hex-encoding.
	 * @return string
	 */
	private static function generate_random_hex( $bytes = 32 ) {
		return bin2hex( random_bytes( max( 16, (int) $bytes ) ) );
	}

	/**
	 * Base64 URL-safe encode.
	 *
	 * @since 6.7.6
	 * @param string $binary Binary input.
	 * @return string
	 */
	private static function base64url_encode( $binary ) {
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Base64 URL-safe decode.
	 *
	 * @since 6.7.6
	 * @param string $input Encoded input.
	 * @return string
	 */
	private static function base64url_decode( $input ) {
		$input = strtr( (string) $input, '-_', '+/' );
		$pad   = strlen( $input ) % 4;
		if ( 0 !== $pad ) {
			$input .= str_repeat( '=', 4 - $pad );
		}

		$decoded = base64_decode( $input, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		return is_string( $decoded ) ? $decoded : '';
	}
}
