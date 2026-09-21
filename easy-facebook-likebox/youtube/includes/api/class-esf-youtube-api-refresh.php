<?php
/**
 * YouTube account token refresh REST endpoint.
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

		// Check if refresh token exists.
		if ( empty( $account->refresh_token ) ) {
			return function_exists( 'esf_oauth_reconnect_required_error' )
				? esf_oauth_reconnect_required_error()
				: new WP_Error(
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

			return function_exists( 'esf_oauth_map_refresh_failure' )
				? esf_oauth_map_refresh_failure( $token_data )
				: $token_data;
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

		// Refresh channel stats + local images after a successful token refresh.
		$api_service  = ESF_YouTube_API_Service::get_instance();
		$channel_data = $api_service->validate_token_and_fetch_channel( $token_data['access_token'] );
		if ( ! is_wp_error( $channel_data ) ) {
			$repository->update_channel_stats( $account_id, $channel_data, true );
			// Clear shared video cache so feeds reload with fresh data.
			if ( class_exists( 'ESF_YouTube_Cache' ) ) {
				ESF_YouTube_Cache::flush_account_cache( $account_id );
			}
		}

		$fresh           = $repository->get_by_id( $account_id );
		$account_payload = null;
		if ( $fresh ) {
			$account_payload = array(
				'id'                => (int) $fresh->id,
				'channel_name'      => isset( $fresh->channel_name ) ? (string) $fresh->channel_name : '',
				'channel_id'        => isset( $fresh->channel_id ) ? (string) $fresh->channel_id : '',
				'handle'            => isset( $fresh->handle ) ? (string) $fresh->handle : '',
				'channel_thumbnail' => isset( $fresh->channel_thumbnail ) ? (string) $fresh->channel_thumbnail : '',
				'subscriber_count'  => isset( $fresh->subscriber_count ) ? (int) $fresh->subscriber_count : 0,
				'video_count'       => isset( $fresh->video_count ) ? (int) $fresh->video_count : 0,
				'status'            => isset( $fresh->status ) ? (string) $fresh->status : 'active',
				'token_expires_at'  => isset( $fresh->token_expires_at ) ? (string) $fresh->token_expires_at : gmdate( 'Y-m-d H:i:s', time() + (int) $token_data['expires_in'] ),
			);
			if ( class_exists( 'ESF_YouTube_Local_Media' ) ) {
				$account_payload = ESF_YouTube_Local_Media::localize_account_row_images( $account_payload );
			}
		}

		return rest_ensure_response(
			array(
				'success'    => true,
				'message'    => __( 'Account refreshed successfully.', 'easy-facebook-likebox' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + (int) $token_data['expires_in'] ),
				'account'    => $account_payload,
			)
		);
	}
}
