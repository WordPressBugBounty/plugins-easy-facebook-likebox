<?php
/**
 * YouTube OAuth REST API.
 *
 * Provides endpoints for generating OAuth URLs and, later, handling tokens.
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
	 * Default external app ID used for YouTube OAuth.
	 *
	 * @since 6.7.5
	 *
	 * @var string
	 */
	const DEFAULT_APP_ID = '245898365669';

	/**
	 * Get the external app ID for the YouTube OAuth app.
	 *
	 * Centralizes the app ID and passes it through a filter so
	 * other apps/IDs can be used without touching core code.
	 *
	 * @since 6.7.5
	 *
	 * @return string
	 */
	public static function get_app_id() {
		$app_id = apply_filters( 'esf_youtube_oauth_app_id', self::DEFAULT_APP_ID );
		if ( ! is_string( $app_id ) || '' === trim( $app_id ) ) {
			$app_id = self::DEFAULT_APP_ID;
		}

		return trim( (string) $app_id );
	}

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
	 * Build external OAuth URL for YouTube app.
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

		$app_id = self::get_app_id();

		$base_url = sprintf(
			'https://easysocialfeed.com/apps/youtube/%s/index.php',
			rawurlencode( trim( (string) $app_id ) )
		);

		/**
		 * Filter the base URL used for the YouTube OAuth app.
		 *
		 * @since 6.7.5
		 *
		 * @param string $base_url Base URL.
		 * @param string $app_id   App ID used in the URL.
		 */
		$base_url = apply_filters( 'esf_youtube_oauth_base_url', $base_url, $app_id );

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
