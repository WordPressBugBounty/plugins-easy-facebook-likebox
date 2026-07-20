<?php
/**
 * Instagram Module Cache
 *
 * Reads and writes to the `wp_esf_instagram_cache` table for Graph API
 * responses and other module data. Mirrors {@see ESF_Twitter_Cache} so the
 * three modules (X/IG/YT) all share the same operational shape.
 *
 * The table is created by {@see ESF_Instagram_DB_Installer::create_cache_table()}.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_Cache
 *
 * @since 6.9.0
 */
class ESF_Instagram_Cache {

	/**
	 * Default TTL in seconds when the caller omits it.
	 *
	 * @var int
	 */
	const DEFAULT_TTL = 43200; // 12 hours.

	/**
	 * Allowed cache_type values that map to the enum on the table.
	 *
	 * @var string[]
	 */
	private static $allowed_types = array( 'account', 'feed', 'api' );

	/**
	 * Get the cache table name.
	 *
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'esf_instagram_cache';
	}

	/**
	 * Retrieve cached data by key. Returns null on miss or when the row is expired.
	 *
	 * @param string $key Cache key.
	 * @return array|null
	 */
	public static function get( $key ) {
		global $wpdb;

		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return null;
		}

		$table_escaped = esc_sql( self::get_table_name() );
		$row           = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT cache_data FROM `{$table_escaped}` WHERE cache_key = %s AND expires_at > %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * Store data in the cache.
	 *
	 * @param string   $key        Cache key.
	 * @param array    $data       Data to store (JSON-encoded internally).
	 * @param int|null $ttl_secs   TTL in seconds; falls back to DEFAULT_TTL.
	 * @param string   $cache_type 'account' | 'feed' | 'api' (default 'api').
	 * @param int|null $account_id Optional account scope for selective invalidation.
	 * @return bool
	 */
	public static function set( $key, $data, $ttl_secs = null, $cache_type = 'api', $account_id = null ) {
		global $wpdb;

		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return false;
		}

		$json = wp_json_encode( $data );
		if ( false === $json ) {
			return false;
		}

		$ttl_secs = null === $ttl_secs ? self::DEFAULT_TTL : (int) $ttl_secs;
		if ( $ttl_secs <= 0 ) {
			$ttl_secs = self::DEFAULT_TTL;
		}

		if ( ! in_array( $cache_type, self::$allowed_types, true ) ) {
			$cache_type = 'api';
		}

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			self::get_table_name(),
			array(
				'cache_key'  => $key,
				'cache_data' => $json,
				'cache_type' => $cache_type,
				'account_id' => $account_id ? (int) $account_id : null,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl_secs ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return true;
	}

	/**
	 * Clear account-scoped cache rows for the account linked to a feed.
	 *
	 * Media cache is shared across every feed that uses the same account, so
	 * "clear cache" on one feed refreshes data for all sibling feeds too.
	 *
	 * @param int $feed_id Feed ID.
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public static function flush_feed_cache( $feed_id ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return false;
		}

		if ( ! class_exists( 'ESF_Instagram_Feed_Repository' ) ) {
			return false;
		}

		$feed = ESF_Instagram_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( ! $feed ) {
			return 0;
		}

		if ( function_exists( 'esf_instagram_feed_is_hashtag' ) && esf_instagram_feed_is_hashtag( $feed ) ) {
			if ( function_exists( 'esf_instagram_flush_hashtag_cache' ) ) {
				return esf_instagram_flush_hashtag_cache( isset( $feed->source_id ) ? (string) $feed->source_id : '' );
			}
			return 0;
		}

		if ( empty( $feed->account_id ) ) {
			return 0;
		}

		return self::flush_account_cache( (int) $feed->account_id );
	}

	/**
	 * List non-expired hashtag cache rows for the admin picker.
	 *
	 * @since 6.9.0
	 * @return array<int,array{tag:string,label:string,media_type:string,post_count:int,cached_at:string,expires_at:string}>
	 */
	public static function list_hashtag_caches() {
		global $wpdb;

		$table_escaped = esc_sql( self::get_table_name() );
		$prefix        = 'esf_ig_hashtag_';
		$like          = $wpdb->esc_like( $prefix ) . '%';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT cache_key, cache_data, created_at, expires_at FROM `{$table_escaped}` WHERE cache_key LIKE %s AND expires_at > %s ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$like,
				current_time( 'mysql', true )
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$items = array();
		$seen  = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || empty( $row->cache_key ) ) {
				continue;
			}

			$key = (string) $row->cache_key;
			if ( '_meta' === substr( $key, -5 ) ) {
				continue;
			}

			$suffix = substr( $key, strlen( $prefix ) );
			if ( '' === $suffix || ! function_exists( 'esf_instagram_normalize_hashtag' ) ) {
				continue;
			}

			$media_type = 'top_media';
			if ( '__recent' === substr( $suffix, -8 ) ) {
				$suffix     = substr( $suffix, 0, -8 );
				$media_type = 'recent_media';
			}

			$normalized = esf_instagram_normalize_hashtag( $suffix );
			if ( '' === $normalized ) {
				continue;
			}

			$seen_key = $normalized . '|' . $media_type;
			if ( isset( $seen[ $seen_key ] ) ) {
				continue;
			}
			$seen[ $seen_key ] = true;

			$post_count = 0;
			if ( ! empty( $row->cache_data ) ) {
				$decoded = json_decode( (string) $row->cache_data, true );
				if ( is_array( $decoded ) && isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
					$post_count = count( $decoded['data'] );
				}
			}

			$type_label = 'recent_media' === $media_type
				? __( 'Recent', 'easy-facebook-likebox' )
				: __( 'Top', 'easy-facebook-likebox' );

			$items[] = array(
				'tag'        => $normalized,
				'label'      => '#' . $normalized . ' · ' . $type_label,
				'media_type' => $media_type,
				'post_count' => $post_count,
				'cached_at'  => isset( $row->created_at ) ? (string) $row->created_at : '',
				'expires_at' => isset( $row->expires_at ) ? (string) $row->expires_at : '',
			);
		}

		return $items;
	}

	/**
	 * Delete a single cache row by key.
	 *
	 * @param string $key Cache key.
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public static function delete_by_key( $key ) {
		global $wpdb;

		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return false;
		}

		$table_escaped = esc_sql( self::get_table_name() );

		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM `{$table_escaped}` WHERE cache_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key
			)
		);
	}

	/**
	 * Delete every cached row for an account.
	 *
	 * @param int $account_id Account ID.
	 * @return int|false
	 */
	public static function flush_account_cache( $account_id ) {
		global $wpdb;

		$account_id    = (int) $account_id;
		$table_escaped = esc_sql( self::get_table_name() );

		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM `{$table_escaped}` WHERE account_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$account_id
			)
		);
	}

	/**
	 * Flush every `cache_type = 'api'` row (use when global API-affecting
	 * settings change, e.g. locale).
	 *
	 * @return int|false
	 */
	public static function flush_api_cache() {
		global $wpdb;

		$table_escaped = esc_sql( self::get_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query( "DELETE FROM `{$table_escaped}` WHERE cache_type = 'api'" );
	}

	/**
	 * Flush every row in the module cache table.
	 *
	 * Used by Settings → Clear All Caches. Instagram stores user-timeline media
	 * under `cache_type = account` (X/YouTube use `api` for feed payloads), so
	 * a global clear must remove all types—not only `api`.
	 *
	 * @since 6.9.0
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public static function flush_all_cache() {
		global $wpdb;

		$table_escaped = esc_sql( self::get_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query( "DELETE FROM `{$table_escaped}`" );
	}

	/**
	 * Sanitize a cache key to alphanumerics, underscores and hyphens
	 * (max 255 chars to match the column width).
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	private static function sanitize_key( $key ) {
		$key = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $key );
		return substr( (string) $key, 0, 255 );
	}
}
