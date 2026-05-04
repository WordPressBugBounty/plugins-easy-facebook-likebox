<?php
/**
 * Twitter Module Cache
 *
 * Reads and writes to the Twitter cache table for API responses.
 * Follows the same pattern as ESF_YouTube_Cache.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter
 * @since 6.7.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Twitter_Cache
 *
 * @since 6.7.6
 */
class ESF_Twitter_Cache {

	/**
	 * Get the cache table name.
	 *
	 * @since 6.7.6
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'esf_twitter_cache';
	}

	/**
	 * Retrieve cached data by key.
	 *
	 * Returns null on a miss or when the row is expired.
	 *
	 * @since 6.7.6
	 * @param string $key Cache key.
	 * @return array|null Decoded array on a cache hit, null on miss/expiry.
	 */
	public static function get( $key ) {
		global $wpdb;

		$key           = self::sanitize_key( $key );
		$table         = self::get_table_name();
		$table_escaped = esc_sql( $table );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT cache_data, expires_at FROM `{$table_escaped}` WHERE cache_key = %s AND expires_at > %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * Get the raw expires_at datetime for a cache key (no expiry check).
	 *
	 * Used by the cron job to decide whether a feed cache needs refreshing.
	 *
	 * @since 6.7.6
	 * @param string $key Cache key.
	 * @return string|null MySQL datetime string, or null when no row exists.
	 */
	public static function get_expires_at( $key ) {
		global $wpdb;

		$key           = self::sanitize_key( $key );
		$table         = self::get_table_name();
		$table_escaped = esc_sql( $table );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT expires_at FROM `{$table_escaped}` WHERE cache_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key
			)
		);

		return ( $row && ! empty( $row->expires_at ) ) ? $row->expires_at : null;
	}

	/**
	 * Store data in the cache.
	 *
	 * @since 6.7.6
	 * @param string   $key        Cache key.
	 * @param array    $data       Data to store (JSON-encoded internally).
	 * @param int|null $ttl_secs   TTL in seconds. Defaults to module setting.
	 * @param string   $cache_type Optional. 'account', 'feed', or 'api'. Default 'api'.
	 * @param int|null $feed_id    Optional. Feed ID for feed-scoped invalidation.
	 * @param int|null $account_id Optional. Account ID for account-scoped invalidation.
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
			$ttl_secs = (int) esf_get_twitter_settings( 'cache_duration' );
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
	 * Flush all API-type cache rows.
	 *
	 * Use when global settings that affect API output change (e.g. locale).
	 *
	 * @since 6.7.6
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
	 * Flush all cached data for a specific feed.
	 *
	 * @since 6.7.6
	 * @param int $feed_id Feed ID.
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public static function flush_feed_cache( $feed_id ) {
		global $wpdb;

		$feed_id       = (int) $feed_id;
		$table         = self::get_table_name();
		$table_escaped = esc_sql( $table );

		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM `{$table_escaped}` WHERE feed_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$feed_id
			)
		);
	}

	/**
	 * Flush all cached data for a specific account.
	 *
	 * @since 6.7.6
	 * @param int $account_id Account ID.
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public static function flush_account_cache( $account_id ) {
		global $wpdb;

		$account_id    = (int) $account_id;
		$table         = self::get_table_name();
		$table_escaped = esc_sql( $table );

		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM `{$table_escaped}` WHERE account_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$account_id
			)
		);
	}

	/**
	 * Sanitize a cache key to alphanumerics, underscores, and hyphens only (max 255 chars).
	 *
	 * @since 6.7.6
	 * @param string $key Raw key.
	 * @return string Sanitized key.
	 */
	private static function sanitize_key( $key ) {
		$key = (string) $key;
		$key = preg_replace( '/[^a-zA-Z0-9_-]/', '', $key );

		return substr( $key, 0, 255 );
	}

}
