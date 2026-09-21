<?php

/**
 * Twitter Accounts REST API
 *
 * Exposes endpoints for managing X accounts:
 * - GET  /esf/v1/twitter/accounts         – list accounts
 * - DELETE /esf/v1/twitter/accounts/:id   – delete account
 * - POST /esf/v1/twitter/accounts/:id/refresh – refresh account token
 * - POST /esf/v1/twitter/accounts/:id/refresh-cache – force-refresh cached tweets
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
        register_rest_route( 'esf/v1', '/twitter/accounts/(?P<id>[\\d]+)/refresh-cache', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'refresh_cache'),
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
            if ( function_exists( 'esf_twitter_has_twitter_plan' ) && esf_twitter_has_twitter_plan() ) {
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
     * Returns X accounts for users who can manage the module.
     * Tokens are not included in the response.
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
            'auth_source',
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
            'updated_at',
            // Virtual fields computed below; not real DB columns.
            'cache_expires_at',
            'cache_cadence_seconds',
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
        $select_fields = array_values( $fields );
        $injected_x_user_id = false;
        $injected_account_data = false;
        $injected_auth_source = false;
        $wants_avatar = in_array( 'profile_image_url', $fields, true );
        $wants_cache_meta = in_array( 'cache_expires_at', $fields, true ) || in_array( 'cache_cadence_seconds', $fields, true );
        if ( $wants_avatar && !in_array( 'x_user_id', $select_fields, true ) ) {
            $select_fields[] = 'x_user_id';
            $injected_x_user_id = true;
        }
        if ( $wants_avatar && !in_array( 'account_data', $select_fields, true ) ) {
            $select_fields[] = 'account_data';
            $injected_account_data = true;
        }
        if ( $wants_cache_meta && !in_array( 'auth_source', $select_fields, true ) ) {
            $select_fields[] = 'auth_source';
            $injected_auth_source = true;
        }
        // Cache fields are computed after the query; they are not real columns.
        $select_fields = array_values( array_diff( $select_fields, array('cache_expires_at', 'cache_cadence_seconds') ) );
        $select = implode( ', ', array_map( 'esc_sql', $select_fields ) );
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
            if ( $wants_avatar && class_exists( 'ESF_Twitter_Local_Media' ) ) {
                $row = ESF_Twitter_Local_Media::localize_account_row_images( $row );
            }
            if ( $wants_cache_meta ) {
                $row = self::attach_cache_meta( $row );
            }
            if ( $injected_x_user_id ) {
                unset($row['x_user_id']);
            }
            if ( $injected_account_data ) {
                unset($row['account_data']);
            }
            if ( $injected_auth_source ) {
                unset($row['auth_source']);
            }
        }
        unset($row);
        return rest_ensure_response( $rows );
    }

    /**
     * Attach virtual `cache_expires_at` / `cache_cadence_seconds` fields to an account row.
     *
     * Used by {@see self::get_accounts()} to power the dashboard's feed
     * freshness indicator. `cache_cadence_seconds` reflects
     * {@see esf_twitter_resolve_tweets_cache_ttl()}, so BYOK accounts report
     * their short, plan-independent cadence instead of the managed setting.
     *
     * @since 6.16.0
     * @param array<string,mixed> $row Account row (expects `id`, `auth_source`).
     * @return array<string,mixed> Row with cache fields attached.
     */
    private static function attach_cache_meta( array $row ) {
        $account_id = ( isset( $row['id'] ) ? (int) $row['id'] : 0 );
        $cache_key = ( $account_id > 0 && function_exists( 'esf_twitter_tweets_cache_key' ) ? esf_twitter_tweets_cache_key( $account_id ) : '' );
        $row['cache_expires_at'] = ( '' !== $cache_key ? ESF_Twitter_Cache::get_expires_at( $cache_key ) : null );
        $row['cache_cadence_seconds'] = ( function_exists( 'esf_twitter_resolve_tweets_cache_ttl' ) ? (int) esf_twitter_resolve_tweets_cache_ttl( (object) $row ) : null );
        return $row;
    }

    /**
     * POST /esf/v1/twitter/accounts/:id/refresh-cache
     *
     * Forces a fresh timeline fetch for one account, bypassing the tweets
     * cache. BYOK (manually pasted token) accounts may call this on any
     * plan because reads are billed to the site owner's own X app, not
     * ESF's shared OAuth app. OAuth-connected and public (proxy) accounts
     * require the Pro plan and are rate-limited to protect ESF's shared X
     * API quota. See `twitter/PLAN.md`.
     *
     * @since 6.16.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function refresh_cache( $request ) {
        $account_id = (int) $request['id'];
        $repository = ESF_Twitter_Account_Repository::get_instance();
        $account = $repository->get_by_id( $account_id );
        if ( !$account ) {
            return new WP_Error('account_not_found', __( 'Account not found.', 'easy-facebook-likebox' ), array(
                'status' => 404,
            ));
        }
        $is_byok = function_exists( 'esf_twitter_account_is_byok' ) && esf_twitter_account_is_byok( $account );
        if ( !$is_byok && (!function_exists( 'esf_twitter_has_twitter_plan' ) || !esf_twitter_has_twitter_plan()) ) {
            return new WP_Error('pro_required', __( 'On-demand refresh needs Pro. X charges for API access, so free feeds update less often. Connect using your own X API access to refresh without Pro.', 'easy-facebook-likebox' ), array(
                'status' => 403,
            ));
        }
        /**
         * Filter the rate-limit window (seconds) between manual refreshes for BYOK accounts.
         *
         * @since 6.16.0
         * @param int $seconds Default 300 (5 minutes).
         */
        $byok_window = (int) apply_filters( 'esf_twitter_manual_refresh_rate_limit_byok', 5 * MINUTE_IN_SECONDS );
        /**
         * Filter the rate-limit window (seconds) between manual refreshes for managed (OAuth/public) accounts.
         *
         * @since 6.16.0
         * @param int $seconds Default 3600 (1 hour).
         */
        $managed_window = (int) apply_filters( 'esf_twitter_manual_refresh_rate_limit_managed', HOUR_IN_SECONDS );
        $rate_limit_secs = max( 0, ( $is_byok ? $byok_window : $managed_window ) );
        $rate_limit_key = 'esf_tw_refresh_cache_' . $account_id;
        if ( $rate_limit_secs > 0 ) {
            $blocked_until = get_transient( $rate_limit_key );
            if ( false !== $blocked_until ) {
                return new WP_Error('rate_limited', __( 'This feed was refreshed recently. Please try again in a few minutes.', 'easy-facebook-likebox' ), array(
                    'status'      => 429,
                    'retry_after' => max( 0, (int) $blocked_until - time() ),
                ));
            }
        }
        if ( !class_exists( 'ESF_Twitter_Renderer' ) ) {
            return new WP_Error('renderer_unavailable', __( 'Refresh is unavailable right now.', 'easy-facebook-likebox' ), array(
                'status' => 500,
            ));
        }
        $renderer = new ESF_Twitter_Renderer();
        $result = $renderer->force_refresh_account_tweets( $account );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( $rate_limit_secs > 0 ) {
            set_transient( $rate_limit_key, time() + $rate_limit_secs, $rate_limit_secs );
        }
        return rest_ensure_response( array(
            'success'    => true,
            'account_id' => $account_id,
            'updated_at' => ( isset( $result['updated_at'] ) ? (string) $result['updated_at'] : '' ),
            'expires_at' => ( isset( $result['expires_at'] ) ? (string) $result['expires_at'] : '' ),
        ) );
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
