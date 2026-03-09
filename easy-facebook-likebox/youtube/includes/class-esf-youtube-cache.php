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
	 * Flush cache for a specific feed (uses feed_id column for indexed delete).
	 *
	 * @since 6.7.5
	 * @param int $feed_id Feed ID.
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public static function flush_feed_cache( $feed_id ) {
		global $wpdb;

		$feed_id       = (int) $feed_id;
		$table         = self::get_table_name();
		$table_escaped = esc_sql( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table_escaped}` WHERE feed_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped.
				$feed_id
			)
		);
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
}
