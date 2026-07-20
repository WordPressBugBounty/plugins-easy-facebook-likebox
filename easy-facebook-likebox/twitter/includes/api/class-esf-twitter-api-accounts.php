<?php

/**
 * Twitter Accounts REST API
 *
 * Exposes endpoints for managing X accounts:
 * - GET  /esf/v1/twitter/accounts         – list accounts
 * - DELETE /esf/v1/twitter/accounts/:id   – delete account
 * - POST /esf/v1/twitter/accounts/:id/refresh – refresh account token
 * - POST /esf/v1/twitter/accounts/public  – add a public account (Pro)
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/API
 * @since 6.7.6
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Twitter_API_Accounts
 *
 * @since 6.7.6
 */
class ESF_Twitter_API_Accounts {
    /**
     * Register REST API routes.
     *
     * @since 6.7.6
     * @return void
     */
    public static function register_routes() {
        register_rest_route( 'esf/v1', '/twitter/accounts', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array(__CLASS__, 'get_accounts'),
            'permission_callback' => array(__CLASS__, 'read_permissions_check'),
            'args'                => array(
                'fields' => array(
                    'description'       => __( 'Comma-separated list of fields to return.', 'easy-facebook-likebox' ),
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ) );
        register_rest_route( 'esf/v1', '/twitter/accounts/(?P<id>[\\d]+)', array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => array(__CLASS__, 'delete_account'),
            'permission_callback' => array(__CLASS__, 'write_permissions_check'),
            'args'                => array(
                'id' => array(
                    'description'       => __( 'Account ID.', 'easy-facebook-likebox' ),
                    'type'              => 'integer',
                    'required'          => true,
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param ) && (int) $param > 0;
                    },
                ),
            ),
        ) );
        register_rest_route( 'esf/v1', '/twitter/accounts/(?P<id>[\\d]+)/refresh', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'refresh_account'),
            'permission_callback' => array(__CLASS__, 'write_permissions_check'),
            'args'                => array(
                'id' => array(
                    'description'       => __( 'Account ID.', 'easy-facebook-likebox' ),
                    'type'              => 'integer',
                    'required'          => true,
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param ) && (int) $param > 0;
                    },
                ),
            ),
        ) );
        // Pro: add a public account by username.
        if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
            register_rest_route( 'esf/v1', '/twitter/accounts/public', array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array(__CLASS__, 'add_public_account'),
                'permission_callback' => array(__CLASS__, 'write_permissions_check'),
                'args'                => array(
                    'username' => array(
                        'description'       => __( 'X username (without @).', 'easy-facebook-likebox' ),
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            ) );
        }
    }

    /**
     * Read permission callback.
     *
     * @since 6.7.6
     * @param WP_REST_Request $request Request object.
     * @return bool
     */
    public static function read_permissions_check( $request ) {
        if ( $request instanceof WP_REST_Request && 'GET' !== $request->get_method() ) {
            return false;
        }
        return esf_twitter_user_can_manage();
    }

    /**
     * Write permission callback (validates nonce + capability).
     *
     * @since 6.7.6
     * @param WP_REST_Request $request Request object.
     * @return bool
     */
    public static function write_permissions_check( $request ) {
        if ( !esf_twitter_user_can_manage() ) {
            return false;
        }
        $nonce = $request->get_header( 'X-WP-Nonce' );
        return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
    }

    /**
     * GET /esf/v1/twitter/accounts
     *
     * Returns all X accounts for the site (users who can manage the module).
     * Never exposes access_token or refresh_token.
     *
     * @since 6.7.6
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function get_accounts( $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'esf_twitter_accounts';
        $table_escaped = esc_sql( $table );
        $available_fields = array(
            'id',
            'user_id',
            'account_type',
            'x_user_id',
            'username',
            'display_name',
            'profile_image_url',
            'is_verified',
            'verified_type',
            'description',
            'followers_count',
            'following_count',
            'tweet_count',
            'status',
            'token_expires_at',
            'stats_refreshed_at',
            'created_at',
            'updated_at'
        );
        $default_fields = array(
            'id',
            'account_type',
            'x_user_id',
            'username',
            'display_name',
            'profile_image_url',
            'is_verified',
            'verified_type',
            'status',
            'token_expires_at'
        );
        $requested = $request->get_param( 'fields' );
        if ( !empty( $requested ) ) {
            $fields = array_intersect( array_map( 'trim', explode( ',', $requested ) ), $available_fields );
        } else {
            $fields = $default_fields;
        }
        if ( !in_array( 'id', $fields, true ) ) {
            array_unshift( $fields, 'id' );
        }
        $select = implode( ', ', array_map( 'esc_sql', $fields ) );
        $rows = $wpdb->get_results( 
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT {$select} FROM `{$table_escaped}` ORDER BY created_at DESC",
            ARRAY_A
         );
        if ( !$rows ) {
            return rest_ensure_response( array() );
        }
        $has_plan = function_exists( 'esf_twitter_has_twitter_plan' ) && esf_twitter_has_twitter_plan();
        foreach ( $rows as &$row ) {
            foreach ( array('followers_count', 'following_count', 'tweet_count') as $col ) {
                if ( isset( $row[$col] ) ) {
                    $row[$col] = (int) $row[$col];
                }
            }
            if ( isset( $row['is_verified'] ) ) {
                $row['is_verified'] = (int) $row['is_verified'];
            }
            if ( isset( $row['verified_type'] ) ) {
                $row['verified_type'] = (string) $row['verified_type'];
            }
            // Flag whether account_type = 'public' is accessible (Pro gate).
            if ( isset( $row['account_type'] ) && 'public' === $row['account_type'] && !$has_plan ) {
                $row['pro_required'] = true;
            }
        }
        return rest_ensure_response( $rows );
    }

    /**
     * DELETE /esf/v1/twitter/accounts/:id
     *
     * Deletes an account and revokes cached data.
     *
     * @since 6.7.6
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function delete_account( $request ) {
        $account_id = (int) $request['id'];
        $repository = ESF_Twitter_Account_Repository::get_instance();
        $account = $repository->get_by_id( $account_id );
        if ( !$account ) {
            return new WP_Error('account_not_found', __( 'Account not found.', 'easy-facebook-likebox' ), array(
                'status' => 404,
            ));
        }
        $deleted = $repository->delete( $account_id );
        if ( !$deleted ) {
            return new WP_Error('delete_failed', __( 'Failed to delete account.', 'easy-facebook-likebox' ), array(
                'status' => 500,
            ));
        }
        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Account removed successfully.', 'easy-facebook-likebox' ),
        ) );
    }

    /**
     * POST /esf/v1/twitter/accounts/:id/refresh
     *
     * Refreshes an account access token using its refresh token.
     *
     * @since 6.7.6
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function refresh_account( $request ) {
        $account_id = (int) $request['id'];
        $repository = ESF_Twitter_Account_Repository::get_instance();
        $api_service = new ESF_Twitter_API_Service();
        $account = $repository->get_by_id( $account_id );
        if ( !$account ) {
            return new WP_Error('account_not_found', __( 'Account not found.', 'easy-facebook-likebox' ), array(
                'status' => 404,
            ));
        }
        if ( 'connected' !== (string) $account->account_type ) {
            return new WP_Error('invalid_account_type', __( 'Only connected accounts can refresh tokens.', 'easy-facebook-likebox' ), array(
                'status' => 400,
            ));
        }
        if ( empty( $account->refresh_token ) ) {
            return ( function_exists( 'esf_oauth_reconnect_required_error' ) ? esf_oauth_reconnect_required_error() : new WP_Error('no_refresh_token', __( 'No refresh token available for this account. Please reconnect.', 'easy-facebook-likebox' ), array(
                'status' => 400,
            )) );
        }
        $token_data = $api_service->refresh_access_token( (string) $account->refresh_token );
        if ( is_wp_error( $token_data ) ) {
            $repository->update_status( $account_id, 'expired' );
            return ( function_exists( 'esf_oauth_map_refresh_failure' ) ? esf_oauth_map_refresh_failure( $token_data ) : $token_data );
        }
        $updated = $repository->update_tokens(
            $account_id,
            (string) $token_data['access_token'],
            (string) $token_data['refresh_token'],
            (int) $token_data['expires_in']
        );
        if ( !$updated ) {
            return new WP_Error('update_tokens_failed', __( 'Failed to update account tokens.', 'easy-facebook-likebox' ), array(
                'status' => 500,
            ));
        }
        $updated_account = $repository->get_by_id( $account_id );
        return rest_ensure_response( array(
            'success'    => true,
            'account_id' => $account_id,
            'expires_at' => ( $updated_account ? (string) $updated_account->token_expires_at : '' ),
        ) );
    }

    /**
     * POST /esf/v1/twitter/accounts/public
     *
     * Add a public X account by username (Pro feature).
     *
     * @since 6.7.6
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function add_public_account( $request ) {
        if ( !method_exists( __CLASS__, 'add_public_account__premium_only' ) ) {
            return new WP_Error('pro_unavailable', __( 'Public account support is unavailable in this build.', 'easy-facebook-likebox' ), array(
                'status' => 403,
            ));
        }
        return self::add_public_account__premium_only( $request );
    }

}
