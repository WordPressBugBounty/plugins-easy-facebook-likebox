<?php
/**
 * YouTube OAuth REST API.
 *
 * Provides the REST endpoint for generating the OAuth connect URL.
 * The OAuth flow itself runs on the ESF bridge; this class only
 * builds the entry-point URL with the nonce-protected return URL.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/API
 * @since 6.7.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_API_OAuth
 *
 * @since 6.7.5
 */
class ESF_YouTube_API_OAuth {

	/**
	 * ESF bridge URL for YouTube OAuth.
	 *
	 * @since 6.7.5
	 * @var string
	 */
	const BRIDGE_URL = 'https://easysocialfeed.com/apps/youtube/index.php';

	/**
	 * Register REST API routes.
	 *
	 * @since 6.7.5
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/youtube/oauth/url',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_oauth_url' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * Restrict to admins or users who can manage plugin settings.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function permissions_check( $request ) {
		// Ensure this endpoint is only used for GET requests.
		if ( $request instanceof WP_REST_Request && 'GET' !== $request->get_method() ) {
			return false;
		}

		return esf_youtube_user_can_manage();
	}

	/**
	 * Build external OAuth URL for YouTube via the ESF bridge.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_oauth_url( $request ) {
		// Touch request to satisfy coding standards (future extension point).
		if ( $request instanceof WP_REST_Request ) {
			$request->get_params();
		}

		// Enforce account limit on server: free plan = one account per site (cannot be bypassed via frontend).
		$repo = ESF_YouTube_Account_Repository::get_instance();
		if ( ( ! function_exists( 'esf_youtube_has_youtube_plan' ) || ! esf_youtube_has_youtube_plan() ) && $repo->get_total_account_count() >= 1 ) {
			return new WP_Error(
				'account_limit_reached',
				__( 'Your plan allows one YouTube account per site. Upgrade to add more.', 'easy-facebook-likebox' ),
				array( 'status' => 403 )
			);
		}

		/**
		 * Filter the YouTube OAuth bridge URL.
		 *
		 * @since 6.7.5
		 *
		 * @param string $bridge_url Bridge entry URL.
		 */
		$base_url = apply_filters( 'esf_youtube_oauth_base_url', self::BRIDGE_URL );

		$site_url = site_url();

		// Generate a nonce and callback URL for the admin dashboard.
		$nonce = wp_create_nonce( 'esf_youtube_connect' );

		// Build the return URL that the external server will redirect back to.
		$return_url = add_query_arg(
			array(
				'page'           => 'esf-youtube',
				'esf_yt_connect' => '1',
				'esf_yt_nonce'   => $nonce,
			),
			admin_url( 'admin.php' )
		);

		// Build the connect URL (external OAuth app URL with return_url and site_url).
		$connect_url = add_query_arg(
			array(
				'return_url' => rawurlencode( $return_url ),
				'site_url'   => rawurlencode( $site_url ),
			),
			$base_url
		);

		$data = array(
			'url' => esc_url_raw( $connect_url ),
		);

		return rest_ensure_response( $data );
	}
}
