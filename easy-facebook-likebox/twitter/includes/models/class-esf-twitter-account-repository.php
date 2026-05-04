<?php

/**
 * Twitter Account Repository
 *
 * Encapsulates CRUD operations for the esf_twitter_accounts table.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/Models
 * @since 6.7.6
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Twitter_Account_Repository
 *
 * @since 6.7.6
 */
class ESF_Twitter_Account_Repository {
    use ESF_Twitter_Singleton;
    /**
     * Get the accounts table name.
     *
     * @since 6.7.6
     * @return string
     */
    private function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'esf_twitter_accounts';
    }

    /**
     * Upsert a connected account from OAuth token data.
     *
     * Calls the X API to validate the token and fetch the user profile,
     * then inserts or updates the account row.
     *
     * @since 6.7.6
     * @param int    $wp_user_id    WordPress user ID.
     * @param string $access_token  OAuth 2.0 access token.
     * @param string $refresh_token OAuth 2.0 refresh token.
     * @param int    $expires_in    Seconds until access_token expires.
     * @return int|false Account ID on success, false on failure.
     */
    public function upsert_from_tokens(
        $wp_user_id,
        $access_token,
        $refresh_token = '',
        $expires_in = 7200
    ) {
        global $wpdb;
        $wp_user_id = (int) $wp_user_id;
        $access_token = (string) $access_token;
        $refresh_token = (string) $refresh_token;
        $expires_in = (int) $expires_in;
        if ( $wp_user_id <= 0 || '' === $access_token ) {
            return false;
        }
        $api_service = ESF_Twitter_API_Service::get_instance();
        $user_data = $api_service->validate_token_and_fetch_user( $access_token );
        if ( is_wp_error( $user_data ) ) {
            esf_twitter_log_error( 'Failed to validate X token.', array(
                'error'   => $user_data->get_error_message(),
                'user_id' => $wp_user_id,
            ) );
            return false;
        }
        $table = $this->get_table_name();
        $now = current_time( 'mysql', true );
        $token_expires = gmdate( 'Y-m-d H:i:s', time() + max( 60, $expires_in ) );
        $table_escaped = esc_sql( $table );
        $x_user_id_esc = esc_sql( $user_data['x_user_id'] );
        $existing_id = (int) $wpdb->get_var( 
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT id FROM `{$table_escaped}` WHERE x_user_id = '{$x_user_id_esc}' LIMIT 1"
         );
        // Save avatar locally when media helpers are available.
        $profile_image_url = $user_data['profile_image_url'];
        if ( function_exists( 'esf_twitter_get_best_profile_image_url' ) ) {
            $profile_image_url = esf_twitter_get_best_profile_image_url( $profile_image_url );
        }
        if ( !empty( $user_data['x_user_id'] ) && !empty( $profile_image_url ) ) {
            if ( function_exists( 'esf_delete_media' ) ) {
                esf_delete_media( 'tw_' . $user_data['x_user_id'], 'twitter' );
            }
            if ( function_exists( 'esf_serve_media_locally' ) ) {
                $local = esf_serve_media_locally( 'tw_' . $user_data['x_user_id'], $profile_image_url, 'twitter' );
                if ( is_string( $local ) && '' !== $local ) {
                    $profile_image_url = $local;
                }
            }
        }
        $fields = array(
            'user_id'            => $wp_user_id,
            'account_type'       => 'connected',
            'x_user_id'          => $user_data['x_user_id'],
            'username'           => $user_data['username'],
            'display_name'       => $user_data['display_name'],
            'profile_image_url'  => $profile_image_url,
            'description'        => $user_data['description'],
            'followers_count'    => $user_data['followers_count'],
            'following_count'    => $user_data['following_count'],
            'tweet_count'        => $user_data['tweet_count'],
            'is_verified'        => ( !empty( $user_data['is_verified'] ) ? 1 : 0 ),
            'verified_type'      => ( isset( $user_data['verified_type'] ) ? sanitize_text_field( (string) $user_data['verified_type'] ) : '' ),
            'access_token'       => $access_token,
            'refresh_token'      => $refresh_token,
            'token_expires_at'   => $token_expires,
            'account_data'       => wp_json_encode( $user_data ),
            'status'             => 'active',
            'stats_refreshed_at' => $now,
            'updated_at'         => $now,
        );
        $format = array(
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%d',
            '%d',
            '%d',
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s'
        );
        if ( $existing_id > 0 ) {
            $updated = $wpdb->update(
                $table,
                $fields,
                array(
                    'id' => $existing_id,
                ),
                $format,
                array('%d')
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            return ( false === $updated ? false : $existing_id );
        }
        $fields['created_at'] = $now;
        $format[] = '%s';
        $inserted = $wpdb->insert( $table, $fields, $format );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( false === $inserted ) {
            return false;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Add or update a public account (Pro-only gateway).
     *
     * @since 6.7.6
     * @param int        $wp_user_id WordPress user who added the account.
     * @param string     $username   X username (without @).
     * @param array|null $user_data  Optional pre-fetched normalized user data.
     * @return int|false Account ID on success, false on failure.
     */
    public function upsert_public_account( $wp_user_id, $username, $user_data = null ) {
        if ( !function_exists( 'esf_twitter_has_twitter_plan' ) || !esf_twitter_has_twitter_plan() ) {
            return false;
        }
        if ( !method_exists( $this, 'upsert_public_account__premium_only' ) ) {
            return false;
        }
        return $this->upsert_public_account__premium_only( $wp_user_id, $username, $user_data );
    }

    /**
     * Get an account by its internal ID.
     *
     * @since 6.7.6
     * @param int $account_id Account ID.
     * @return object|null Account object or null.
     */
    public function get_by_id( $account_id ) {
        global $wpdb;
        $account_id = (int) $account_id;
        $table = $this->get_table_name();
        $table_escaped = esc_sql( $table );
        return $wpdb->get_row( 
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM `{$table_escaped}` WHERE id = {$account_id} LIMIT 1"
         );
    }

    /**
     * Get the total number of connected (OAuth) accounts across the site.
     *
     * Used to enforce the one-account-per-site free-plan limit.
     *
     * @since 6.7.6
     * @return int
     */
    public function get_total_connected_account_count() {
        global $wpdb;
        $table = $this->get_table_name();
        $table_escaped = esc_sql( $table );
        $count = $wpdb->get_var( 
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT COUNT(*) FROM `{$table_escaped}` WHERE account_type = 'connected'"
         );
        return (int) $count;
    }

    /**
     * Update access and refresh tokens for a connected account.
     *
     * @since 6.7.6
     * @param int    $account_id    Account ID.
     * @param string $access_token  New access token.
     * @param string $refresh_token New refresh token.
     * @param int    $expires_in    Seconds until new token expires.
     * @return bool
     */
    public function update_tokens(
        $account_id,
        $access_token,
        $refresh_token,
        $expires_in = 7200
    ) {
        global $wpdb;
        $table = $this->get_table_name();
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + $expires_in );
        $updated = $wpdb->update(
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $table,
            array(
                'access_token'     => $access_token,
                'refresh_token'    => $refresh_token,
                'token_expires_at' => $expires_at,
                'status'           => 'active',
                'updated_at'       => current_time( 'mysql', true ),
            ),
            array(
                'id' => $account_id,
            ),
            array(
                '%s',
                '%s',
                '%s',
                '%s',
                '%s'
            ),
            array('%d')
        );
        return false !== $updated;
    }

    /**
     * Update the status of an account.
     *
     * @since 6.7.6
     * @param int    $account_id Account ID.
     * @param string $status     'active', 'expired', or 'invalid'.
     * @return bool
     */
    public function update_status( $account_id, $status ) {
        global $wpdb;
        $valid = array('active', 'expired', 'invalid');
        if ( !in_array( $status, $valid, true ) ) {
            return false;
        }
        $table = $this->get_table_name();
        $updated = $wpdb->update(
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $table,
            array(
                'status'     => $status,
                'updated_at' => current_time( 'mysql', true ),
            ),
            array(
                'id' => $account_id,
            ),
            array('%s', '%s'),
            array('%d')
        );
        return false !== $updated;
    }

    /**
     * Check whether a connected account's token needs refreshing.
     *
     * Returns true when the token expires within the next 5 minutes.
     *
     * @since 6.7.6
     * @param int $account_id Account ID.
     * @return bool
     */
    public function needs_token_refresh( $account_id ) {
        $account = $this->get_by_id( $account_id );
        if ( !$account || empty( $account->token_expires_at ) ) {
            return false;
        }
        return time() >= strtotime( $account->token_expires_at ) - 300;
    }

    /**
     * Delete an account and its associated media/cache.
     *
     * @since 6.7.6
     * @param int $account_id Account ID.
     * @return bool True when deleted.
     */
    public function delete( $account_id ) {
        global $wpdb;
        $account_id = (int) $account_id;
        if ( $account_id <= 0 ) {
            return false;
        }
        $account = $this->get_by_id( $account_id );
        if ( $account && !empty( $account->x_user_id ) && function_exists( 'esf_delete_media' ) ) {
            esf_delete_media( 'tw_' . $account->x_user_id, 'twitter' );
        }
        // Flush per-account cache entries.
        ESF_Twitter_Cache::flush_account_cache( $account_id );
        $table = $this->get_table_name();
        $deleted = $wpdb->delete( $table, array(
            'id' => $account_id,
        ), array('%d') );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return false !== $deleted && $deleted > 0;
    }

    /**
     * Get active accounts due for a stats refresh.
     *
     * @since 6.7.6
     * @param int $cache_duration_secs How old stats must be before refreshing.
     * @return array List of account objects.
     */
    public function get_accounts_due_for_stats_refresh( $cache_duration_secs ) {
        global $wpdb;
        $cache_duration_secs = max( 0, (int) $cache_duration_secs );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $cache_duration_secs );
        $table = $this->get_table_name();
        $table_escaped = esc_sql( $table );
        $rows = $wpdb->get_results( 
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare( 
                "SELECT id, x_user_id, username, account_type FROM `{$table_escaped}` WHERE status = %s AND (stats_refreshed_at IS NULL OR stats_refreshed_at <= %s) ORDER BY stats_refreshed_at ASC",
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                'active',
                $cutoff
             )
         );
        return ( is_array( $rows ) ? $rows : array() );
    }

    /**
     * Update stored profile stats/details for an account.
     *
     * @since 6.7.6
     * @param int   $account_id Account ID.
     * @param array $user_data  Normalized user data from API service.
     * @return bool
     */
    public function update_account_stats( $account_id, $user_data ) {
        global $wpdb;
        $account_id = (int) $account_id;
        if ( $account_id <= 0 || !is_array( $user_data ) || empty( $user_data['x_user_id'] ) ) {
            return false;
        }
        $profile_image_url = ( isset( $user_data['profile_image_url'] ) ? (string) $user_data['profile_image_url'] : '' );
        if ( function_exists( 'esf_twitter_get_best_profile_image_url' ) ) {
            $profile_image_url = esf_twitter_get_best_profile_image_url( $profile_image_url );
        }
        if ( !empty( $user_data['x_user_id'] ) && '' !== $profile_image_url && function_exists( 'esf_serve_media_locally' ) ) {
            $local = esf_serve_media_locally( 'tw_' . $user_data['x_user_id'], $profile_image_url, 'twitter' );
            if ( is_string( $local ) && '' !== $local ) {
                $profile_image_url = $local;
            }
        }
        $table = $this->get_table_name();
        $updated = $wpdb->update(
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $table,
            array(
                'username'           => ( isset( $user_data['username'] ) ? sanitize_text_field( (string) $user_data['username'] ) : '' ),
                'display_name'       => ( isset( $user_data['display_name'] ) ? sanitize_text_field( (string) $user_data['display_name'] ) : '' ),
                'profile_image_url'  => $profile_image_url,
                'description'        => ( isset( $user_data['description'] ) ? sanitize_text_field( (string) $user_data['description'] ) : '' ),
                'followers_count'    => ( isset( $user_data['followers_count'] ) ? (int) $user_data['followers_count'] : 0 ),
                'following_count'    => ( isset( $user_data['following_count'] ) ? (int) $user_data['following_count'] : 0 ),
                'tweet_count'        => ( isset( $user_data['tweet_count'] ) ? (int) $user_data['tweet_count'] : 0 ),
                'is_verified'        => ( !empty( $user_data['is_verified'] ) ? 1 : 0 ),
                'verified_type'      => ( isset( $user_data['verified_type'] ) ? sanitize_text_field( (string) $user_data['verified_type'] ) : '' ),
                'account_data'       => wp_json_encode( $user_data ),
                'stats_refreshed_at' => current_time( 'mysql', true ),
                'updated_at'         => current_time( 'mysql', true ),
            ),
            array(
                'id' => $account_id,
            ),
            array(
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s'
            ),
            array('%d')
        );
        return false !== $updated;
    }

}
