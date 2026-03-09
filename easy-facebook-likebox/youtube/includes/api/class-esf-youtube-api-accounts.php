<?php
/**
 * YouTube Accounts REST API.
 *
 * Exposes read-only endpoints for YouTube accounts.
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
 * Class ESF_YouTube_API_Accounts
 *
 * @since 6.7.5
 */
class ESF_YouTube_API_Accounts {

	/**
	 * Register REST API routes.
	 *
	 * @since 6.7.5
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/youtube/accounts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_accounts' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => array(
					'fields' => array(
						'description'       => __( 'Comma-separated list of fields to return.', 'easy-facebook-likebox' ),
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'esf/v1',
			'/youtube/accounts/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_account' ),
				'permission_callback' => array( __CLASS__, 'delete_permissions_check' ),
				'args'                => array(
					'id' => array(
						'description'       => __( 'Account ID to delete.', 'easy-facebook-likebox' ),
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0;
						},
					),
				),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function permissions_check( $request ) {
		// Use request parameter to satisfy coding standards.
		if ( $request instanceof WP_REST_Request && 'GET' !== $request->get_method() ) {
			return false;
		}

		return esf_youtube_user_can_manage();
	}

	/**
	 * Permission callback for delete account.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function delete_permissions_check( $request ) {
		if ( ! esf_youtube_user_can_manage() ) {
			return false;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Get YouTube accounts for current user.
	 *
	 * Returns account data including channel name, thumbnail, banner (Pro), and statistics.
	 * Supports field filtering via 'fields' query parameter.
	 * channel_banner is only included when the user has a YouTube Pro plan.
	 * NEVER exposes sensitive data like access_token or refresh_token.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_accounts( $request ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return rest_ensure_response( array() );
		}

		global $wpdb;

		$table         = $wpdb->prefix . 'esf_youtube_accounts';
		$table_escaped = esc_sql( $table );

		// Get requested fields from query parameter.
		$requested_fields = $request->get_param( 'fields' );

		// Define all available fields (EXCLUDING sensitive data).
		// channel_banner is Pro-only; include in list but strip from response for free users below.
		$available_fields = array(
			'id',
			'user_id',
			'channel_name',
			'channel_id',
			'handle',
			'description',
			'channel_thumbnail',
			'channel_banner',
			'subscriber_count',
			'video_count',
			'view_count',
			'status',
			'token_expires_at',
			'created_at',
			'updated_at',
		);

		// Default fields (if no fields parameter provided).
		$default_fields = array(
			'id',
			'channel_name',
			'channel_id',
			'handle',
			'channel_thumbnail',
			'status',
			'token_expires_at',
		);

		// Parse requested fields.
		if ( ! empty( $requested_fields ) ) {
			$fields = array_map( 'trim', explode( ',', $requested_fields ) );
			// Filter to only allowed fields for security.
			$fields = array_intersect( $fields, $available_fields );
		} else {
			$fields = $default_fields;
		}

		// Always include 'id' for key identification.
		if ( ! in_array( 'id', $fields, true ) ) {
			array_unshift( $fields, 'id' );
		}

		// Build SELECT query dynamically.
		$select_fields = implode( ', ', array_map( 'esc_sql', $fields ) );

		// Fetch channel data.
		$user_id = (int) $user_id;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and field list are whitelisted; user ID is cast to int.
			'SELECT ' . $select_fields . ' FROM `' . $table_escaped . '` WHERE user_id = ' . $user_id . ' ORDER BY created_at DESC',
			ARRAY_A
		);

		if ( ! $rows ) {
			return rest_ensure_response( array() );
		}

		// Convert numeric strings to integers for cleaner JSON response (if those fields exist).
		// Strip Pro-only field channel_banner for users without YouTube plan.
		$has_youtube_plan = function_exists( 'esf_youtube_has_youtube_plan' ) && esf_youtube_has_youtube_plan();
		foreach ( $rows as &$row ) {
			if ( isset( $row['subscriber_count'] ) ) {
				$row['subscriber_count'] = (int) $row['subscriber_count'];
			}
			if ( isset( $row['video_count'] ) ) {
				$row['video_count'] = (int) $row['video_count'];
			}
			if ( isset( $row['view_count'] ) ) {
				$row['view_count'] = (int) $row['view_count'];
			}
			if ( ! $has_youtube_plan && array_key_exists( 'channel_banner', $row ) ) {
				unset( $row['channel_banner'] );
			}
		}

		return rest_ensure_response( $rows );
	}

	/**
	 * Delete a YouTube account and revoke Google access.
	 *
	 * Revokes the token with Google OAuth revoke endpoint, then deletes
	 * the account row from the database. Deletion proceeds even if revoke fails.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_account( $request ) {
		$account_id = (int) $request['id'];

		$repository = ESF_YouTube_Account_Repository::get_instance();
		$account    = $repository->get_by_id( $account_id );

		if ( ! $account ) {
			return new WP_Error(
				'account_not_found',
				__( 'Account not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$api_service     = ESF_YouTube_API_Service::get_instance();
		$token_to_revoke = ! empty( $account->refresh_token ) ? $account->refresh_token : $account->access_token;
		if ( ! empty( $token_to_revoke ) ) {
			$api_service->revoke_token( $token_to_revoke );
		}

		$deleted = $repository->delete( $account_id );
		if ( ! $deleted ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete account.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Account deleted successfully. Access has been revoked from Google.', 'easy-facebook-likebox' ),
			)
		);
	}
}
