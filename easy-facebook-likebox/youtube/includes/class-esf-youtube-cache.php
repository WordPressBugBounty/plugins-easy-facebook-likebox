<?php
/**
 * YouTube Module Cache.
 *
 * Reads and writes to the YouTube cache table for API responses.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube
 * @since 6.7.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_Cache
 *
 * @since 6.7.5
 */
class ESF_YouTube_Cache {

	/**
	 * Far-future expiry for permanently pinned disconnected-feed cache rows.
	 *
	 * @since 6.7.9
	 * @var string
	 */
	const PINNED_EXPIRES_AT = '2099-12-31 23:59:59';

	/**
	 * TTL (seconds) for disconnected account profile snapshots.
	 *
	 * @since 6.7.9
	 * @var int
	 */
	const ACCOUNT_PROFILE_SNAPSHOT_TTL = 315360000;

	/**
	 * Get table name for cache.
	 *
	 * @since 6.7.5
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'esf_youtube_cache';
	}

	/**
	 * Get cached data by key.
	 *
	 * @since 6.7.5
	 *
	 * @param string $key Cache key.
	 * @return array|null Decoded array on hit, null on miss or expired.
	 */
	public static function get( $key ) {
		global $wpdb;

		$key           = self::sanitize_key( $key );
		$table         = self::get_table_name();
		$table_escaped = esc_sql( $table );

		// Table name cannot be parameterized in MySQL; escaped via esc_sql. Custom cache table, not using wp_cache.
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT cache_data, expires_at FROM `{$table_escaped}` WHERE cache_key = %s AND expires_at > %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from get_table_name().
				$key,
				current_time( 'mysql', true )
			)
		);

		if ( ! $row || empty( $row->cache_data ) ) {
			return null;
		}

		$data = json_decode( $row->cache_data, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Read cache even when expired (stale-while-revalidate).
	 *
	 * @since 6.9.5
	 *
	 * @param string $key Cache key.
	 * @return array|null
	 */
	public static function get_stale( $key ) {
		global $wpdb;

		$key           = self::sanitize_key( $key );
		$table         = self::get_table_name();
		$table_escaped = esc_sql( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT cache_data FROM `{$table_escaped}` WHERE cache_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped.
				$key
			)
		);

		if ( ! $row || empty( $row->cache_data ) ) {
			return null;
		}

		$data = json_decode( $row->cache_data, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Get expiration datetime for a cache key (raw value, no expiry check).
	 * Used by cron to decide whether to refresh.
	 *
	 * @since 6.7.5
	 *
	 * @param string $key Cache key.
	 * @return string|null Expires_at datetime string, or null if no row.
	 */
	public static function get_expires_at( $key ) {
		global $wpdb;

		$key           = self::sanitize_key( $key );
		$table         = self::get_table_name();
		$table_escaped = esc_sql( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT expires_at FROM `{$table_escaped}` WHERE cache_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped.
				$key
			)
		);

		return ( $row && ! empty( $row->expires_at ) ) ? $row->expires_at : null;
	}

	/**
	 * Set cache by key.
	 *
	 * @since 6.7.5
	 *
	 * @param string   $key        Cache key.
	 * @param array    $data       Data to store (will be JSON-encoded).
	 * @param int|null $ttl_secs   TTL in seconds. Default from module settings.
	 * @param string   $cache_type Optional. 'account', 'feed', or 'api'. Default 'api'.
	 * @param int|null $feed_id    Optional. Feed ID for feed-scoped cache (enables efficient flush by feed).
	 * @param int|null $account_id  Optional. Account ID for account-scoped cache (enables efficient flush by account).
	 * @return bool True on success.
	 */
	public static function set( $key, $data, $ttl_secs = null, $cache_type = 'api', $feed_id = null, $account_id = null ) {
		global $wpdb;

		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return false;
		}

		$json = wp_json_encode( $data );
		if ( false === $json ) {
			return false;
		}

		if ( null === $ttl_secs ) {
			$ttl_secs = (int) esf_get_youtube_settings( 'cache_duration' );
			if ( $ttl_secs <= 0 ) {
				$ttl_secs = 43200; // 12 hours.
			}
		}

		$now        = current_time( 'mysql', true );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl_secs );

		$allowed_types = array( 'account', 'feed', 'api' );
		if ( ! in_array( $cache_type, $allowed_types, true ) ) {
			$cache_type = 'api';
		}

		$feed_id    = $feed_id ? (int) $feed_id : null;
		$account_id = $account_id ? (int) $account_id : null;

		$table = self::get_table_name();

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'cache_key'  => $key,
				'cache_data' => $json,
				'cache_type' => $cache_type,
				'feed_id'    => $feed_id,
				'account_id' => $account_id,
				'expires_at' => $expires_at,
				'created_at' => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return true;
	}

	/**
	 * Delete a single cache row by key.
	 *
	 * @since 6.7.6
	 *
	 * @param string $key Cache key.
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public static function delete( $key ) {
		global $wpdb;

		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return false;
		}

		$table         = self::get_table_name();
		$table_escaped = esc_sql( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table_escaped}` WHERE cache_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped.
				$key
			)
		);
	}

	/**
	 * Flush the shared videos cache for an account + feed source.
	 *
	 * Also removes legacy feed-scoped keys when $feed_id is provided.
	 *
	 * @since 6.7.6
	 *
	 * @param int    $account_id Account ID.
	 * @param string $feed_type  Feed type (channel|playlist|search).
	 * @param string $source_id  Playlist ID or search query.
	 * @param int    $feed_id    Optional feed ID for legacy key cleanup.
	 * @return int Number of rows deleted.
	 */
	public static function flush_videos_cache_for_source( $account_id, $feed_type = 'channel', $source_id = '', $feed_id = 0 ) {
		$deleted    = 0;
		$account_id = (int) $account_id;
		$feed_id    = (int) $feed_id;

		if ( $account_id > 0 && function_exists( 'esf_youtube_videos_cache_key' ) ) {
			$key = esf_youtube_videos_cache_key( $account_id, $feed_type, $source_id );
			if ( '' !== $key ) {
				$result   = self::delete( $key );
				$deleted += false !== $result ? (int) $result : 0;
			}
		}

		if ( $feed_id > 0 && function_exists( 'esf_youtube_videos_cache_legacy_keys' ) ) {
			foreach ( esf_youtube_videos_cache_legacy_keys( $feed_id, $account_id, $feed_type, $source_id ) as $legacy_key ) {
				$result   = self::delete( $legacy_key );
				$deleted += false !== $result ? (int) $result : 0;
			}
		}

		return $deleted;
	}

	/**
	 * Flush API cache (video list caches). Use when feed language or other API-affecting settings change.
	 *
	 * @since 6.7.5
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public static function flush_api_cache() {
		global $wpdb;

		$table         = self::get_table_name();
		$table_escaped = esc_sql( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query( "DELETE FROM `{$table_escaped}` WHERE cache_type = 'api'" );
	}

	/**
	 * Clear shared videos cache for the account/source linked to a feed.
	 *
	 * Sibling feeds that share the same account + source lose that row too.
	 *
	 * @since 6.7.5
	 * @param int $feed_id Feed ID.
	 * @return int|false Rows deleted, or false on failure.
	 */
	public static function flush_feed_cache( $feed_id ) {
		global $wpdb;

		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return false;
		}

		$deleted = 0;

		if ( class_exists( 'ESF_YouTube_Feed_Repository' ) ) {
			$feed = ESF_YouTube_Feed_Repository::get_instance()->get_by_id( $feed_id );
			if ( $feed ) {
				$account_id = isset( $feed->account_id ) ? (int) $feed->account_id : 0;
				$feed_type  = isset( $feed->feed_type ) ? (string) $feed->feed_type : 'channel';
				$source_id  = isset( $feed->source_id ) ? (string) $feed->source_id : '';

				$settings = array();
				if ( isset( $feed->settings ) ) {
					$decoded  = is_string( $feed->settings )
						? json_decode( $feed->settings, true )
						: $feed->settings;
					$settings = is_array( $decoded ) ? $decoded : array();
				}
				$content_filter = function_exists( 'esf_youtube_content_filter_from_settings' )
					? esf_youtube_content_filter_from_settings(
						class_exists( 'ESF_YouTube_Feed_Repository' )
							? ESF_YouTube_Feed_Repository::merge_settings_with_defaults( $settings )
							: $settings
					)
					: 'all';

				$channel_id = '';
				if ( $account_id > 0 && class_exists( 'ESF_YouTube_Account_Repository' ) ) {
					$account    = ESF_YouTube_Account_Repository::get_instance()->get_by_id( $account_id );
					$channel_id = ( $account && isset( $account->channel_id ) )
						? (string) $account->channel_id
						: '';
				}

				if ( function_exists( 'esf_youtube_resolve_videos_source' ) ) {
					$resolved  = esf_youtube_resolve_videos_source(
						$feed_type,
						$source_id,
						$content_filter,
						$channel_id
					);
					$feed_type = $resolved['feed_type'];
					$source_id = $resolved['source_id'];
				}

				$deleted += self::flush_videos_cache_for_source(
					$account_id,
					$feed_type,
					$source_id,
					$feed_id
				);

				if ( function_exists( 'esf_youtube_shorts_ids_cache_key' ) ) {
					$shorts_key = esf_youtube_shorts_ids_cache_key( $account_id );
					if ( '' !== $shorts_key ) {
						$deleted += (int) self::delete( $shorts_key );
					}
				}
			}
		}

		// Remove any leftover rows still tagged with this feed_id column.
		$table         = self::get_table_name();
		$table_escaped = esc_sql( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_column = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table_escaped}` WHERE feed_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped.
				$feed_id
			)
		);
		if ( false !== $by_column ) {
			$deleted += (int) $by_column;
		}

		return $deleted;
	}

	/**
	 * Flush cache for a specific account (video caches and account metadata for that account).
	 *
	 * @since 6.7.5
	 * @param int $account_id Account ID.
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public static function flush_account_cache( $account_id ) {
		global $wpdb;

		$account_id    = (int) $account_id;
		$table         = self::get_table_name();
		$table_escaped = esc_sql( $table );

		// Table name cannot be parameterized in MySQL; escaped via esc_sql.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table_escaped}` WHERE account_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped.
				$account_id
			)
		);
	}

	/**
	 * Sanitize cache key (alphanumeric, underscore, hyphen only; max 255 chars).
	 *
	 * @since 6.7.5
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	private static function sanitize_key( $key ) {
		$key = (string) $key;
		$key = preg_replace( '/[^a-zA-Z0-9_-]/', '', $key );

		return substr( $key, 0, 255 );
	}

	/**
	 * Cache key for a disconnected account's public profile snapshot.
	 *
	 * @since 6.7.9
	 *
	 * @param int $account_id Internal account id.
	 * @return string
	 */
	public static function account_profile_snapshot_key( $account_id ) {
		return sprintf( 'esf_yt_account_profile_%d', max( 0, (int) $account_id ) );
	}

	/**
	 * Persist a token-free channel profile snapshot for disconnected-feed headers.
	 *
	 * @since 6.7.9
	 *
	 * @param object $account_row Account row.
	 * @return bool
	 */
	public static function save_account_profile_snapshot( $account_row ) {
		if ( ! is_object( $account_row ) || empty( $account_row->id ) ) {
			return false;
		}

		$account_id = (int) $account_row->id;
		if ( $account_id <= 0 ) {
			return false;
		}

		$snapshot = array(
			'id'                => $account_id,
			'channel_name'      => isset( $account_row->channel_name ) ? (string) $account_row->channel_name : '',
			'channel_id'        => isset( $account_row->channel_id ) ? (string) $account_row->channel_id : '',
			'handle'            => isset( $account_row->handle ) ? (string) $account_row->handle : '',
			'channel_thumbnail' => isset( $account_row->channel_thumbnail ) ? (string) $account_row->channel_thumbnail : '',
			'channel_banner'    => isset( $account_row->channel_banner ) ? (string) $account_row->channel_banner : '',
			'subscriber_count'  => isset( $account_row->subscriber_count ) ? (int) $account_row->subscriber_count : 0,
			'video_count'       => isset( $account_row->video_count ) ? (int) $account_row->video_count : 0,
			'view_count'        => isset( $account_row->view_count ) ? (int) $account_row->view_count : 0,
		);

		if ( class_exists( 'ESF_YouTube_Local_Media' ) ) {
			if ( '' !== $snapshot['channel_thumbnail'] ) {
				$thumb = ESF_YouTube_Local_Media::resolve_avatar_display_url( $account_row );
				if ( '' !== $thumb ) {
					$snapshot['channel_thumbnail'] = $thumb;
				}
			}
			if ( '' !== $snapshot['channel_banner'] ) {
				$banner = ESF_YouTube_Local_Media::resolve_banner_display_url( $account_row );
				if ( '' !== $banner ) {
					$snapshot['channel_banner'] = $banner;
				}
			}
		}

		return self::set(
			self::account_profile_snapshot_key( $account_id ),
			$snapshot,
			self::resolve_profile_snapshot_ttl(),
			'account',
			null,
			$account_id
		);
	}

	/**
	 * TTL for disconnect profile snapshots (matches normal feed cache duration).
	 *
	 * @return int
	 */
	private static function resolve_profile_snapshot_ttl() {
		return self::ACCOUNT_PROFILE_SNAPSHOT_TTL;
	}

	/**
	 * Read a stored account profile snapshot (includes expired rows).
	 *
	 * @since 6.7.9
	 *
	 * @param int $account_id Internal account id.
	 * @return array<string,mixed>|null
	 */
	public static function get_account_profile_snapshot( $account_id ) {
		$account_id = (int) $account_id;
		if ( $account_id <= 0 ) {
			return null;
		}

		$data = self::get_stale( self::account_profile_snapshot_key( $account_id ) );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Extend cache expiry for all rows tied to an account.
	 *
	 * @since 6.7.9
	 *
	 * @param int $account_id Internal account id.
	 * @return int Rows updated.
	 */
	public static function pin_account_cache( $account_id ) {
		global $wpdb;

		$account_id = (int) $account_id;
		if ( $account_id <= 0 ) {
			return 0;
		}

		$table_escaped = esc_sql( self::get_table_name() );
		$pinned        = self::PINNED_EXPIRES_AT;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped via esc_sql().
		$result = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table_escaped}` SET expires_at = %s WHERE account_id = %d",
				$pinned,
				$account_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $result;
	}

	/**
	 * Pin cache rows for a feed's linked account.
	 *
	 * @since 6.7.9
	 *
	 * @param int $feed_id Feed id.
	 * @return int|false Rows updated, or false when feed/account is missing.
	 */
	public static function pin_feed_cache( $feed_id ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 || ! class_exists( 'ESF_YouTube_Feed_Repository' ) ) {
			return false;
		}

		$feed = ESF_YouTube_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( ! $feed || empty( $feed->account_id ) ) {
			return false;
		}

		return self::pin_account_cache( (int) $feed->account_id );
	}

	/**
	 * Whether any cache rows for this feed's account use the pinned far-future expiry.
	 *
	 * @since 6.7.9
	 *
	 * @param int $feed_id Feed id.
	 * @return bool
	 */
	public static function feed_has_pinned_cache_rows( $feed_id ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 || ! class_exists( 'ESF_YouTube_Feed_Repository' ) ) {
			return false;
		}

		$feed = ESF_YouTube_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( ! $feed || empty( $feed->account_id ) ) {
			return false;
		}

		global $wpdb;

		$account_id    = (int) $feed->account_id;
		$table_escaped = esc_sql( self::get_table_name() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped via esc_sql().
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table_escaped}` WHERE expires_at = %s AND account_id = %d",
				self::PINNED_EXPIRES_AT,
				$account_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count > 0;
	}
}
