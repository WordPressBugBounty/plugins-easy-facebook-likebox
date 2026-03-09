<?php
/**
 * REST API Controller for Token Refresh.
 *
 * Handles refresh token endpoint for renewing expired access tokens.
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
 * Class ESF_YouTube_API_Refresh
 *
 * @since 6.7.5
 */
class ESF_YouTube_API_Refresh extends WP_REST_Controller {

	use ESF_YouTube_Singleton;

	/**
	 * REST API namespace.
	 *
	 * @since 6.7.5
	 * @var string
	 */
	protected $namespace = 'esf/v1';

	/**
	 * REST API route base.
	 *
	 * @since 6.7.5
	 * @var string
	 */
	protected $rest_base = 'youtube/accounts/(?P<id>[\d]+)/refresh';

	/**
	 * Register REST API routes.
	 *
	 * @since 6.7.5
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refresh_token' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'id' => array(
							'description'       => __( 'Account ID to refresh.', 'easy-facebook-likebox' ),
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && $param > 0;
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Check permissions for REST API request.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error True if allowed, WP_Error otherwise.
	 */
	public function check_permissions( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to refresh YouTube tokens.', 'easy-facebook-likebox' ),
				array( 'status' => 403 )
			);
		}

		// Verify nonce for additional security.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_invalid_nonce',
				__( 'Invalid security token.', 'easy-facebook-likebox' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Refresh access token for an account.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function refresh_token( $request ) {
		$account_id = (int) $request['id'];
		$user_id    = get_current_user_id();

		// Get account from database.
		$repository = ESF_YouTube_Account_Repository::get_instance();
		$account    = $repository->get_by_id( $account_id );

		if ( ! $account ) {
			return new WP_Error(
				'account_not_found',
				__( 'Account not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		// Verify account belongs to current user.
		if ( (int) $account->user_id !== $user_id ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to refresh this account.', 'easy-facebook-likebox' ),
				array( 'status' => 403 )
			);
		}

		// Check if refresh token exists.
		if ( empty( $account->refresh_token ) ) {
			return new WP_Error(
				'no_refresh_token',
				__( 'No refresh token available. Please reconnect your account.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		// Call API service to refresh token.
		$api_service = ESF_YouTube_API_Service::get_instance();
		$token_data  = $api_service->refresh_access_token( $account->refresh_token );

		if ( is_wp_error( $token_data ) ) {
			// Mark account as expired if refresh failed.
			$repository->update_status( $account_id, 'expired' );

			return $token_data;
		}

		// Update account with new token data.
		$updated = $repository->update_tokens(
			$account_id,
			$token_data['access_token'],
			$token_data['refresh_token'],
			$token_data['expires_in']
		);

		if ( ! $updated ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update account tokens.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		// Refresh channel stats (subscriber_count, description, etc.) so header shows up-to-date data.
		$api_service  = ESF_YouTube_API_Service::get_instance();
		$channel_data = $api_service->validate_token_and_fetch_channel( $token_data['access_token'] );
		if ( ! is_wp_error( $channel_data ) ) {
			$repository->update_channel_stats( $account_id, $channel_data );
		}

		// Return success response.
		return rest_ensure_response(
			array(
				'success'    => true,
				'message'    => __( 'Token refreshed successfully.', 'easy-facebook-likebox' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $token_data['expires_in'] ),
			)
		);
	}
}
