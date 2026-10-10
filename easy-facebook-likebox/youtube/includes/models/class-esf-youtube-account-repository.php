<?php
/**
 * YouTube accounts table repository.
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
	 * @param string $auth_source   `oauth` or `manual`.
	 *
	 * @return int|false Account ID on success, false on failure.
	 */
	public function upsert_from_tokens( $user_id, $access_token, $refresh_token = '', $expires_in = 3600, $auth_source = 'oauth' ) {
		global $wpdb;

		$user_id       = (int) $user_id;
		$access_token  = (string) $access_token;
		$refresh_token = (string) $refresh_token;
		$expires_in    = (int) $expires_in;
		$auth_source   = class_exists( 'ESF_Manual_Access_Token' )
			? ESF_Manual_Access_Token::normalize_auth_source( $auth_source )
			: 'oauth';
		$is_manual     = class_exists( 'ESF_Manual_Access_Token' )
			&& ESF_Manual_Access_Token::AUTH_MANUAL === $auth_source;

		if ( $user_id <= 0 || '' === $access_token ) {
			return false;
		}

		if ( $is_manual ) {
			$refresh_token = '';
			$expires_in    = 0;
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
		$token_expires_at = $is_manual || $expires_in <= 0
			? null
			: gmdate( 'Y-m-d H:i:s', time() + max( 60, $expires_in ) );

		$table_escaped  = esc_sql( $table );
		$channel_id_esc = esc_sql( $channel_data['id'] );
		$existing_id    = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and channel ID are derived from trusted sources and escaped.
			"SELECT id FROM `{$table_escaped}` WHERE channel_id = '{$channel_id_esc}' LIMIT 1"
		);

		$thumbnail_url = isset( $channel_data['thumbnail'] ) ? (string) $channel_data['thumbnail'] : '';
		$banner_url    = isset( $channel_data['banner'] ) ? (string) $channel_data['banner'] : '';

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
			'auth_source'        => $auth_source,
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
			'%s', // auth_source.
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

			$account_id = ( false === $updated ) ? false : $existing_id;
		} else {
			// New account.
			$fields['created_at'] = $now;
			$format[]             = '%s';

			$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$table,
				$fields,
				$format
			);

			$account_id = ( false === $inserted ) ? false : (int) $wpdb->insert_id;
		}

		if ( $account_id && ! empty( $channel_data['id'] ) ) {
			$localized = self::persist_local_channel_images(
				(int) $account_id,
				(string) $channel_data['id'],
				$thumbnail_url,
				$banner_url
			);
			self::update_stored_channel_images( (int) $account_id, $localized['thumbnail'], $localized['banner'] );
		}

		return $account_id;
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
	 * Get account by YouTube channel ID.
	 *
	 * @since 6.14.0
	 *
	 * @param string $channel_id YouTube channel ID.
	 * @return object|null Account row or null.
	 */
	public function get_by_channel_id( $channel_id ) {
		global $wpdb;

		$channel_id = sanitize_text_field( (string) $channel_id );
		if ( '' === $channel_id ) {
			return null;
		}

		$table         = $wpdb->prefix . 'esf_youtube_accounts';
		$table_escaped = esc_sql( $table );

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name escaped above.
				"SELECT * FROM `{$table_escaped}` WHERE channel_id = %s LIMIT 1",
				$channel_id
			)
		);
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
	 * Update channel stats and images from a YouTube API channel payload.
	 *
	 * Does not touch tokens. Pass $force_images to re-download avatar/banner.
	 *
	 * @since 6.7.5
	 *
	 * @param int   $account_id   Account ID.
	 * @param array $channel_data Channel data from validate_token_and_fetch_channel.
	 * @param bool  $force_images When true, delete local files first so images re-download.
	 * @return bool
	 */
	public function update_channel_stats( $account_id, $channel_data, $force_images = false ) {
		global $wpdb;

		$account_id = (int) $account_id;
		if ( $account_id <= 0 || ! is_array( $channel_data ) ) {
			return false;
		}

		$thumbnail_url = isset( $channel_data['thumbnail'] ) ? (string) $channel_data['thumbnail'] : '';
		$banner_url    = isset( $channel_data['banner'] ) ? (string) $channel_data['banner'] : '';
		if ( ! empty( $channel_data['id'] ) ) {
			$localized     = self::persist_local_channel_images(
				$account_id,
				(string) $channel_data['id'],
				$thumbnail_url,
				$banner_url,
				(bool) $force_images
			);
			$thumbnail_url = $localized['thumbnail'];
			$banner_url    = $localized['banner'];
		}

		$now = current_time( 'mysql', true );

		$fields  = array(
			'channel_name'       => isset( $channel_data['title'] ) ? $channel_data['title'] : '',
			'handle'             => ! empty( $channel_data['custom_url'] ) ? $channel_data['custom_url'] : null,
			'description'        => ! empty( $channel_data['description'] ) ? $channel_data['description'] : null,
			'channel_thumbnail'  => $thumbnail_url,
			'subscriber_count'   => isset( $channel_data['statistics']['subscribers'] ) ? (int) $channel_data['statistics']['subscribers'] : 0,
			'video_count'        => isset( $channel_data['statistics']['videos'] ) ? (int) $channel_data['statistics']['videos'] : 0,
			'view_count'         => isset( $channel_data['statistics']['views'] ) ? (int) $channel_data['statistics']['views'] : 0,
			'channel_data'       => wp_json_encode( $channel_data ),
			'stats_refreshed_at' => $now,
			'updated_at'         => $now,
		);
		$formats = array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' );

		// brandingSettings.image is deprecated and often empty — never wipe a known banner.
		if ( '' !== $banner_url ) {
			$fields['channel_banner'] = $banner_url;
			$formats[]                = '%s';
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->get_table_name(),
			$fields,
			array( 'id' => $account_id ),
			$formats,
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
	 * Preserve feeds, API video cache, and local media on disconnect.
	 *
	 * Feeds keep their rows (orphaned `account_id`) so the public frontend can
	 * keep serving cached videos after disconnect. Local thumbnails under
	 * `uploads/esf-youtube/{id}/` stay on disk — same as Instagram / X.
	 * Do not call `purge_account_media()` here: wiping local media causes the
	 * renderer to flush video cache on the next view (localized URL detection),
	 * which empties the frontend when no token remains to re-fetch.
	 *
	 * @since 6.7.9
	 *
	 * @param object $account Account object (id, channel_id, etc.).
	 * @return void
	 */
	private function delete_associated_data( $account ) {
		if ( ! is_object( $account ) || empty( $account->id ) ) {
			return;
		}

		try {
			if ( class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
				ESF_Feed_Disconnected_Cache::maybe_notify_youtube_account_disconnected( $account );
			}

			if ( class_exists( 'ESF_YouTube_Cache' ) ) {
				ESF_YouTube_Cache::save_account_profile_snapshot( $account );
			}
		} catch ( \Throwable $e ) {
			// Best-effort cleanup; account row deletion must still proceed.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only disconnect cleanup trail.
				error_log( 'ESF YouTube account disconnect cleanup failed: ' . $e->getMessage() );
			}
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

		$account = $this->get_by_id( $account_id );
		if ( ! $account ) {
			return false;
		}

		$this->delete_associated_data( $account );

		$table   = $this->get_table_name();
		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array( 'id' => $account_id ),
			array( '%d' )
		);

		return ( false !== $deleted && $deleted > 0 );
	}

	/**
	 * Repair channel thumbnail/banner when DB still points at deleted local uploads.
	 *
	 * Recovers remote CDN URLs from the stored `channel_data` JSON backup (no API
	 * call). Falls back to a live channel fetch when the backup is missing. Writes
	 * remote URLs back to the account row and re-warms local files.
	 *
	 * @since 6.9.5
	 *
	 * @param object|null $account Account row.
	 * @return object|null Account row with usable image URLs.
	 */
	public function repair_channel_images_if_needed( $account ) {
		if ( ! is_object( $account ) ) {
			return $account;
		}

		$account_id = isset( $account->id ) ? (int) $account->id : 0;
		$channel_id = isset( $account->channel_id ) ? (string) $account->channel_id : '';
		if ( $account_id <= 0 || '' === $channel_id ) {
			return $account;
		}

		$thumb  = isset( $account->channel_thumbnail ) ? (string) $account->channel_thumbnail : '';
		$banner = isset( $account->channel_banner ) ? (string) $account->channel_banner : '';

		$thumb_needs_repair  = $this->is_dead_local_youtube_url( $thumb, $channel_id, $account_id );
		$banner_needs_repair = ( '' !== $banner ) && $this->is_dead_local_youtube_url( $banner, $channel_id . '_banner', $account_id );

		if ( ! $thumb_needs_repair && ! $banner_needs_repair ) {
			return $account;
		}

		$remote_thumb  = '';
		$remote_banner = '';

		$backup = $this->extract_remote_images_from_channel_data(
			isset( $account->channel_data ) ? (string) $account->channel_data : ''
		);
		if ( $thumb_needs_repair ) {
			$remote_thumb = $backup['thumbnail'];
		}
		if ( $banner_needs_repair ) {
			$remote_banner = $backup['banner'];
		}

		// Last resort: refresh from YouTube API when backup has no remote URL.
		$needs_api = ( $thumb_needs_repair && '' === $remote_thumb )
			|| ( $banner_needs_repair && '' === $remote_banner );
		if ( $needs_api && class_exists( 'ESF_YouTube_Token_Manager', false ) && class_exists( 'ESF_YouTube_API_Service', false ) ) {
			$token = ESF_YouTube_Token_Manager::get_instance()->get_valid_access_token( $account_id );
			if ( $token ) {
				$channel_data = ESF_YouTube_API_Service::get_instance()->validate_token_and_fetch_channel( $token );
				if ( ! is_wp_error( $channel_data ) && is_array( $channel_data ) ) {
					if ( $thumb_needs_repair && '' === $remote_thumb && ! empty( $channel_data['thumbnail'] ) ) {
						$remote_thumb = (string) $channel_data['thumbnail'];
					}
					if ( $banner_needs_repair && '' === $remote_banner && ! empty( $channel_data['banner'] ) ) {
						$remote_banner = (string) $channel_data['banner'];
					}
				}
			}
		}

		$new_thumb  = $thumb;
		$new_banner = $banner;
		$changed    = false;

		if ( $thumb_needs_repair && '' !== $remote_thumb ) {
			$persisted = self::persist_local_channel_images( $account_id, $channel_id, $remote_thumb, '' );
			$new_thumb = $persisted['thumbnail'];
			$changed   = true;
		}

		if ( $banner_needs_repair && '' !== $remote_banner ) {
			$persisted  = self::persist_local_channel_images( $account_id, $channel_id, '', $remote_banner );
			$new_banner = $persisted['banner'];
			$changed    = true;
		}

		if ( $changed ) {
			self::update_stored_channel_images( $account_id, $new_thumb, $new_banner );
			$account->channel_thumbnail = $new_thumb;
			$account->channel_banner    = $new_banner;
		}

		return $account;
	}

	/**
	 * Whether a URL is a local YouTube upload path whose file is missing.
	 *
	 * @since 6.9.5
	 *
	 * @param string $url        Candidate URL.
	 * @param string $media_key  Local media key (channel id or channel id + _banner).
	 * @param int    $account_id Internal account id.
	 * @return bool
	 */
	private function is_dead_local_youtube_url( $url, $media_key, $account_id ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return false;
		}
		if ( ! function_exists( 'esf_is_local_media_url' ) || ! esf_is_local_media_url( $url, 'youtube' ) ) {
			return false;
		}
		if ( function_exists( 'esf_local_media_url' ) ) {
			$existing = esf_local_media_url( (string) $media_key, 'youtube', 0, (int) $account_id );
			return '' === $existing;
		}
		return true;
	}

	/**
	 * Pull remote thumbnail/banner URLs from the channel_data JSON backup column.
	 *
	 * @since 6.9.5
	 *
	 * @param string $channel_data_json JSON from accounts.channel_data.
	 * @return array{thumbnail:string,banner:string}
	 */
	private function extract_remote_images_from_channel_data( $channel_data_json ) {
		$empty             = array(
			'thumbnail' => '',
			'banner'    => '',
		);
		$channel_data_json = (string) $channel_data_json;
		if ( '' === $channel_data_json ) {
			return $empty;
		}

		$data = json_decode( $channel_data_json, true );
		if ( ! is_array( $data ) ) {
			return $empty;
		}

		$thumb = isset( $data['thumbnail'] ) ? (string) $data['thumbnail'] : '';
		if ( '' === $thumb && isset( $data['snippet']['thumbnails']['default']['url'] ) ) {
			$thumb = (string) $data['snippet']['thumbnails']['default']['url'];
		} elseif ( '' === $thumb && isset( $data['snippet']['thumbnails']['high']['url'] ) ) {
			$thumb = (string) $data['snippet']['thumbnails']['high']['url'];
		}

		$banner = isset( $data['banner'] ) ? (string) $data['banner'] : '';

		if ( function_exists( 'esf_is_local_media_url' ) ) {
			if ( '' !== $thumb && esf_is_local_media_url( $thumb, 'youtube' ) ) {
				$thumb = '';
			}
			if ( '' !== $banner && esf_is_local_media_url( $banner, 'youtube' ) ) {
				$banner = '';
			}
		}

		return array(
			'thumbnail' => $thumb,
			'banner'    => $banner,
		);
	}

	/**
	 * Localize channel thumbnail and banner into uploads/esf-youtube/{account_id}/.
	 *
	 * @since 6.9.0
	 *
	 * @param int    $account_id    Internal account id.
	 * @param string $channel_id    YouTube channel id.
	 * @param string $thumbnail_url Remote thumbnail URL.
	 * @param string $banner_url    Remote banner URL.
	 * @param bool   $force         When true, delete existing local files before re-download.
	 * @return array{thumbnail:string,banner:string}
	 */
	private static function persist_local_channel_images( int $account_id, string $channel_id, string $thumbnail_url, string $banner_url, bool $force = false ): array {
		if ( $account_id <= 0 || '' === $channel_id || ! function_exists( 'esf_serve_media_locally' ) ) {
			return array(
				'thumbnail' => $thumbnail_url,
				'banner'    => $banner_url,
			);
		}

		if ( $force && function_exists( 'esf_delete_media' ) ) {
			if ( '' !== $thumbnail_url ) {
				esf_delete_media( $channel_id, 'youtube', $account_id );
			}
			if ( '' !== $banner_url ) {
				esf_delete_media( $channel_id . '_banner', 'youtube', $account_id );
			}
		}

		// Warm local copies for faster first paint, but keep remote CDN URLs in
		// the DB so deleting uploads/esf-youtube can re-download from source.
		if ( '' !== $thumbnail_url && ( ! function_exists( 'esf_is_local_media_url' ) || ! esf_is_local_media_url( $thumbnail_url, 'youtube' ) ) ) {
			esf_serve_media_locally( $channel_id, $thumbnail_url, 'youtube', $account_id );
		}

		if ( '' !== $banner_url && ( ! function_exists( 'esf_is_local_media_url' ) || ! esf_is_local_media_url( $banner_url, 'youtube' ) ) ) {
			esf_serve_media_locally( $channel_id . '_banner', $banner_url, 'youtube', $account_id );
		}

		return array(
			'thumbnail' => $thumbnail_url,
			'banner'    => $banner_url,
		);
	}

	/**
	 * Update stored channel image URLs after localizing assets.
	 *
	 * @since 6.9.0
	 *
	 * @param int         $account_id    Internal account id.
	 * @param string      $thumbnail_url Thumbnail URL to store.
	 * @param string|null $banner_url    Banner URL to store (null leaves unchanged).
	 * @return void
	 */
	private static function update_stored_channel_images( int $account_id, string $thumbnail_url, $banner_url = null ): void {
		global $wpdb;

		$fields = array(
			'channel_thumbnail' => $thumbnail_url,
			'updated_at'        => current_time( 'mysql', true ),
		);
		$format = array( '%s', '%s' );

		if ( null !== $banner_url ) {
			$fields['channel_banner'] = $banner_url ? $banner_url : null;
			$format[]                 = '%s';
		}

		$table = self::get_instance()->get_table_name();
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			$fields,
			array( 'id' => $account_id ),
			$format,
			array( '%d' )
		);
	}
}
