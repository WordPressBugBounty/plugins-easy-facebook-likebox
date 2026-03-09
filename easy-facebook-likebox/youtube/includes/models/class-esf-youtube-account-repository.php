<?php
/**
 * YouTube Account Repository.
 *
 * Encapsulates CRUD operations for YouTube accounts table.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/Models
 * @since 6.7.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_Account_Repository
 *
 * @since 6.7.5
 */
class ESF_YouTube_Account_Repository {

	use ESF_YouTube_Singleton;

	/**
	 * Get table name for accounts.
	 *
	 * @since 6.7.5
	 * @return string
	 */
	private function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'esf_youtube_accounts';
	}

	/**
	 * Upsert account for a user using token data.
	 *
	 * Validates the access token with YouTube API, fetches channel information,
	 * and saves/updates the account record.
	 *
	 * @since 6.7.5
	 *
	 * @param int    $user_id       WordPress user ID.
	 * @param string $access_token  Access token.
	 * @param string $refresh_token Refresh token.
	 * @param int    $expires_in    Seconds until expiry.
	 *
	 * @return int|false Account ID on success, false on failure.
	 */
	public function upsert_from_tokens( $user_id, $access_token, $refresh_token = '', $expires_in = 3600 ) {
		global $wpdb;

		$user_id       = (int) $user_id;
		$access_token  = (string) $access_token;
		$refresh_token = (string) $refresh_token;
		$expires_in    = (int) $expires_in;

		if ( $user_id <= 0 || '' === $access_token ) {
			return false;
		}

		// Validate token and fetch channel information from YouTube API.
		$api_service  = ESF_YouTube_API_Service::get_instance();
		$channel_data = $api_service->validate_token_and_fetch_channel( $access_token );

		if ( is_wp_error( $channel_data ) ) {
			esf_youtube_log_error(
				'Failed to validate YouTube token.',
				array(
					'error'   => $channel_data->get_error_message(),
					'user_id' => $user_id,
				)
			);
			return false;
		}

		$table = $this->get_table_name();

		$now              = current_time( 'mysql', true );
		$token_expires_at = gmdate( 'Y-m-d H:i:s', time() + max( 60, $expires_in ) );

		$table_escaped  = esc_sql( $table );
		$channel_id_esc = esc_sql( $channel_data['id'] );
		$existing_id    = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and channel ID are derived from trusted sources and escaped.
			"SELECT id FROM `{$table_escaped}` WHERE channel_id = '{$channel_id_esc}' LIMIT 1"
		);

		// Save channel thumbnail locally for fast, first-party serving (reuses core esf_serve_media_locally).
		// When connecting (or reconnecting), always refresh the local thumbnail so it matches YouTube.
		$thumbnail_url = isset( $channel_data['thumbnail'] ) ? $channel_data['thumbnail'] : '';
		if ( ! empty( $channel_data['id'] ) && ! empty( $thumbnail_url ) ) {
			// Delete any existing local copy so a fresh version is downloaded.
			if ( function_exists( 'esf_delete_media' ) ) {
				esf_delete_media( $channel_data['id'], 'youtube' );
			}

			if ( function_exists( 'esf_serve_media_locally' ) ) {
				$local_url = esf_serve_media_locally( $channel_data['id'], $thumbnail_url, 'youtube' );
				if ( is_string( $local_url ) && '' !== $local_url ) {
					$thumbnail_url = $local_url;
				}
			}
		}

		// Save channel banner locally when available (Pro feature; uses distinct id so it does not overwrite thumbnail).
		$banner_url = isset( $channel_data['banner'] ) ? $channel_data['banner'] : '';
		if ( ! empty( $channel_data['id'] ) && ! empty( $banner_url ) && is_string( $banner_url ) ) {
			$banner_media_id = $channel_data['id'] . '_banner';
			if ( function_exists( 'esf_delete_media' ) ) {
				esf_delete_media( $banner_media_id, 'youtube' );
			}
			if ( function_exists( 'esf_serve_media_locally' ) ) {
				$local_banner = esf_serve_media_locally( $banner_media_id, $banner_url, 'youtube' );
				if ( is_string( $local_banner ) && '' !== $local_banner ) {
					$banner_url = $local_banner;
				}
			}
		}

		// Prepare channel data fields.
		// Store frequently-accessed fields as columns for fast queries.
		$fields = array(
			'user_id'            => $user_id,
			'channel_name'       => $channel_data['title'],
			'channel_id'         => $channel_data['id'],
			'handle'             => ! empty( $channel_data['custom_url'] ) ? $channel_data['custom_url'] : null,
			'description'        => ! empty( $channel_data['description'] ) ? $channel_data['description'] : null,
			'channel_thumbnail'  => $thumbnail_url,
			'channel_banner'     => $banner_url ? $banner_url : null,
			'subscriber_count'   => isset( $channel_data['statistics']['subscribers'] ) ? (int) $channel_data['statistics']['subscribers'] : 0,
			'video_count'        => isset( $channel_data['statistics']['videos'] ) ? (int) $channel_data['statistics']['videos'] : 0,
			'view_count'         => isset( $channel_data['statistics']['views'] ) ? (int) $channel_data['statistics']['views'] : 0,
			'access_token'       => $access_token,
			'refresh_token'      => $refresh_token,
			'token_expires_at'   => $token_expires_at,
			'channel_data'       => wp_json_encode( $channel_data ), // Keep full JSON as backup.
			'status'             => 'active',
			'stats_refreshed_at' => $now,
			'updated_at'         => $now,
		);

		$format = array(
			'%d', // user_id.
			'%s', // channel_name.
			'%s', // channel_id.
			'%s', // handle.
			'%s', // description.
			'%s', // channel_thumbnail.
			'%s', // channel_banner.
			'%d', // subscriber_count.
			'%d', // video_count.
			'%d', // view_count.
			'%s', // access_token.
			'%s', // refresh_token.
			'%s', // token_expires_at.
			'%s', // channel_data.
			'%s', // status.
			'%s', // stats_refreshed_at.
			'%s', // updated_at.
		);

		if ( $existing_id > 0 ) {
			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$table,
				$fields,
				array( 'id' => $existing_id ),
				$format,
				array( '%d' )
			);

			return ( false === $updated ) ? false : $existing_id;
		}

		// New account.
		$fields['created_at'] = $now;
		$format[]             = '%s';

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			$fields,
			$format
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get account by ID.
	 *
	 * @since 6.7.5
	 *
	 * @param int $account_id Account ID.
	 *
	 * @return object|null Account object or null if not found.
	 */
	public function get_by_id( $account_id ) {
		global $wpdb;

		$table         = $wpdb->prefix . 'esf_youtube_accounts';
		$table_escaped = esc_sql( $table );
		$account_id    = (int) $account_id;

		$result = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from $wpdb->prefix and ID is cast to int.
			"SELECT * FROM `{$table_escaped}` WHERE id = {$account_id} LIMIT 1"
		);

		return $result;
	}

	/**
	 * Get the total number of YouTube accounts for the site.
	 *
	 * Used for free-plan limit: one account per site (not per user).
	 *
	 * @since 6.7.5
	 *
	 * @return int Account count (0 or more).
	 */
	public function get_total_account_count() {
		global $wpdb;

		$table         = $this->get_table_name();
		$table_escaped = esc_sql( $table );

		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from prefix.
			"SELECT COUNT(*) FROM `{$table_escaped}`"
		);

		return (int) $count;
	}

	/**
	 * Update access and refresh tokens for an account.
	 *
	 * @since 6.7.5
	 *
	 * @param int    $account_id    Account ID.
	 * @param string $access_token  New access token.
	 * @param string $refresh_token New refresh token.
	 * @param int    $expires_in    Token expiration time in seconds.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function update_tokens( $account_id, $access_token, $refresh_token, $expires_in = 3600 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'esf_youtube_accounts';

		// Calculate expiration timestamp.
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $expires_in );

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'access_token'     => $access_token,
				'refresh_token'    => $refresh_token,
				'token_expires_at' => $expires_at,
				'status'           => 'active',
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( 'id' => $account_id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $updated ) {
			return false;
		}

		return true;
	}

	/**
	 * Update channel stats (description, thumbnail, banner, subscriber_count, etc.) from API response.
	 *
	 * Used after fetching channel data via validate_token_and_fetch_channel.
	 * Does not update tokens. Sets stats_refreshed_at to now.
	 * Banner is stored and optionally served locally (Pro feature).
	 *
	 * @since 6.7.5
	 *
	 * @param int   $account_id   Account ID.
	 * @param array $channel_data Channel data from API (title, id, thumbnail, statistics, etc.).
	 * @return bool True on success, false on failure.
	 */
	public function update_channel_stats( $account_id, $channel_data ) {
		global $wpdb;

		$account_id = (int) $account_id;
		if ( $account_id <= 0 || ! is_array( $channel_data ) ) {
			return false;
		}

		$thumbnail_url = isset( $channel_data['thumbnail'] ) ? $channel_data['thumbnail'] : '';
		if ( ! empty( $channel_data['id'] ) && ! empty( $thumbnail_url ) ) {
			// Delete any existing local copy so a fresh version is downloaded on stats refresh.
			if ( function_exists( 'esf_delete_media' ) ) {
				esf_delete_media( $channel_data['id'], 'youtube' );
			}

			if ( function_exists( 'esf_serve_media_locally' ) ) {
				$local_url = esf_serve_media_locally( $channel_data['id'], $thumbnail_url, 'youtube' );
				if ( is_string( $local_url ) && '' !== $local_url ) {
					$thumbnail_url = $local_url;
				}
			}
		}

		// Refresh channel banner locally when available (Pro feature).
		$banner_url = isset( $channel_data['banner'] ) ? $channel_data['banner'] : '';
		if ( ! empty( $channel_data['id'] ) && ! empty( $banner_url ) && is_string( $banner_url ) ) {
			$banner_media_id = $channel_data['id'] . '_banner';
			if ( function_exists( 'esf_delete_media' ) ) {
				esf_delete_media( $banner_media_id, 'youtube' );
			}
			if ( function_exists( 'esf_serve_media_locally' ) ) {
				$local_banner = esf_serve_media_locally( $banner_media_id, $banner_url, 'youtube' );
				if ( is_string( $local_banner ) && '' !== $local_banner ) {
					$banner_url = $local_banner;
				}
			}
		}

		$now = current_time( 'mysql', true );

		$fields = array(
			'channel_name'       => isset( $channel_data['title'] ) ? $channel_data['title'] : '',
			'handle'             => ! empty( $channel_data['custom_url'] ) ? $channel_data['custom_url'] : null,
			'description'        => ! empty( $channel_data['description'] ) ? $channel_data['description'] : null,
			'channel_thumbnail'  => $thumbnail_url,
			'channel_banner'     => $banner_url ? $banner_url : null,
			'subscriber_count'   => isset( $channel_data['statistics']['subscribers'] ) ? (int) $channel_data['statistics']['subscribers'] : 0,
			'video_count'        => isset( $channel_data['statistics']['videos'] ) ? (int) $channel_data['statistics']['videos'] : 0,
			'view_count'         => isset( $channel_data['statistics']['views'] ) ? (int) $channel_data['statistics']['views'] : 0,
			'channel_data'       => wp_json_encode( $channel_data ),
			'stats_refreshed_at' => $now,
			'updated_at'         => $now,
		);

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->get_table_name(),
			$fields,
			array( 'id' => $account_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return ( false !== $updated );
	}

	/**
	 * Get active accounts that are due for stats refresh (stats_refreshed_at is null or older than cache_duration).
	 *
	 * @since 6.7.5
	 *
	 * @param int $cache_duration_secs Cache duration in seconds; stats older than this are due.
	 * @return array List of account objects (id, channel_id, etc.).
	 */
	public function get_accounts_due_for_stats_refresh( $cache_duration_secs ) {
		global $wpdb;

		$cache_duration_secs = max( 0, (int) $cache_duration_secs );
		$cutoff              = gmdate( 'Y-m-d H:i:s', time() - $cache_duration_secs );
		$table               = $this->get_table_name();
		$table_escaped       = esc_sql( $table );

		// Table name cannot be parameterized; escaped via esc_sql.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, channel_id, channel_name FROM `{$table_escaped}` WHERE status = %s AND (stats_refreshed_at IS NULL OR stats_refreshed_at <= %s) ORDER BY stats_refreshed_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped.
				'active',
				$cutoff
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Update account status.
	 *
	 * @since 6.7.5
	 *
	 * @param int    $account_id Account ID.
	 * @param string $status     New status (active, expired, revoked).
	 *
	 * @return bool True on success, false on failure.
	 */
	public function update_status( $account_id, $status ) {
		global $wpdb;

		$table = $wpdb->prefix . 'esf_youtube_accounts';

		// Validate status.
		$valid_statuses = array( 'active', 'expired', 'revoked' );
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			return false;
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $account_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if an account's token needs refresh.
	 *
	 * Returns true if token expires within the next 5 minutes.
	 *
	 * @since 6.7.5
	 *
	 * @param int $account_id Account ID.
	 *
	 * @return bool True if token needs refresh, false otherwise.
	 */
	public function needs_token_refresh( $account_id ) {
		$account = $this->get_by_id( $account_id );

		if ( ! $account || empty( $account->token_expires_at ) ) {
			return false;
		}

		// Refresh if token expires in less than 5 minutes.
		$expires_timestamp = strtotime( $account->token_expires_at );
		$buffer_time       = 300; // 5 minutes.

		return ( time() >= ( $expires_timestamp - $buffer_time ) );
	}

	/**
	 * Delete data associated with an account (files, cache, etc.).
	 *
	 * Called before removing the account row so all related data is cleaned up.
	 * Extend this method when new associated data is introduced (e.g. cached feeds).
	 *
	 * @since 6.7.5
	 *
	 * @param int $account_id Account ID.
	 * @return void
	 */
	private function delete_associated_data( $account_id ) {
		$account = $this->get_by_id( $account_id );
		if ( ! $account ) {
			return;
		}

		// Remove locally served channel thumbnail and banner (uploads/esf-youtube/).
		if ( ! empty( $account->channel_id ) && function_exists( 'esf_delete_media' ) ) {
			esf_delete_media( $account->channel_id, 'youtube' );
			esf_delete_media( $account->channel_id . '_banner', 'youtube' );
		}

		/**
		 * Fires after YouTube account associated data is deleted.
		 * Use to remove custom data tied to this account (e.g. cache, transients).
		 *
		 * @since 6.7.5
		 * @param object $account Account object (id, channel_id, etc.).
		 */
		do_action( 'esf_youtube_account_deleted_associated_data', $account );
	}

	/**
	 * Delete an account by ID.
	 *
	 * Removes associated data (thumbnails, etc.) then deletes the row.
	 * Caller is responsible for ensuring the current user has permission
	 * (e.g. via capability check in REST API).
	 *
	 * @since 6.7.5
	 *
	 * @param int $account_id Account ID.
	 *
	 * @return bool True if deleted, false otherwise.
	 */
	public function delete( $account_id ) {
		global $wpdb;

		$account_id = (int) $account_id;
		if ( $account_id <= 0 ) {
			return false;
		}

		$this->delete_associated_data( $account_id );

		$table   = $this->get_table_name();
		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array( 'id' => $account_id ),
			array( '%d' )
		);

		return ( false !== $deleted && $deleted > 0 );
	}
}
