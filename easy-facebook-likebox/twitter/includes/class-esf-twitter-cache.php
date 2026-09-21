<?php
/**
 * Twitter module cache.
 *
 * Reads and writes API responses in the Twitter cache table.
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
	 * Default tweets-cache TTL (seconds) for BYOK (manually pasted token) accounts.
	 *
	 * Manual accounts authenticate with a token the site owner generated in
	 * their own X Developer App, so reads are billed against that app, not
	 * ESF's shared OAuth app. They are therefore not subject to the
	 * plan-gated `cache_duration` setting and default to a short TTL.
	 * Filterable via `esf_twitter_manual_account_cache_ttl`.
	 *
	 * @since 6.16.0
	 * @var int
	 */
	const MANUAL_ACCOUNT_CACHE_TTL = 3600;

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

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT cache_data FROM `{$table_escaped}` WHERE cache_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * @param int|null $account_id Optional. Account ID for account-scoped invalidation.
	 * @return bool True on success.
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

		$account_id = $account_id ? (int) $account_id : null;

		$table = self::get_table_name();

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'cache_key'  => $key,
				'cache_data' => $json,
				'cache_type' => $cache_type,
				'account_id' => $account_id,
				'expires_at' => $expires_at,
				'created_at' => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
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
	 * Clear cache for the account attached to a feed.
	 *
	 * @since 6.7.6
	 * @param int $feed_id Feed ID.
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public static function flush_feed_cache( $feed_id ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return false;
		}

		if ( ! class_exists( 'ESF_Twitter_Feed_Repository' ) ) {
			return false;
		}

		$feed = ESF_Twitter_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( ! $feed || empty( $feed->account_id ) ) {
			return 0;
		}

		return self::flush_account_cache( (int) $feed->account_id );
	}

	/**
	 * Delete every row in the Twitter cache table.
	 *
	 * @since 6.14.1
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public static function flush_all_cache() {
		global $wpdb;

		$table_escaped = esc_sql( self::get_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query( "DELETE FROM `{$table_escaped}`" );
	}

	/**
	 * Whether any cache row exists for an account.
	 *
	 * Expired rows still count. Used to decide if customize is a first load.
	 *
	 * @since 6.14.1
	 * @param int $account_id Account ID.
	 * @param int $feed_id    Optional feed ID for legacy tweet cache keys.
	 * @return bool
	 */
	public static function account_has_cache( $account_id, $feed_id = 0 ) {
		global $wpdb;

		$account_id = (int) $account_id;
		if ( $account_id <= 0 ) {
			return false;
		}

		$keys    = array( self::sanitize_key( 'esf_tw_tweets_' . $account_id ) );
		$feed_id = (int) $feed_id;
		if ( $feed_id > 0 ) {
			$keys[] = self::sanitize_key( 'esf_tw_tweets_' . $feed_id . '_' . $account_id );
		}
		$keys = array_values( array_filter( $keys ) );
		if ( empty( $keys ) ) {
			return false;
		}

		$table_escaped = esc_sql( self::get_table_name() );
		$placeholders  = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$args          = array_merge( array( $account_id ), $keys );

		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT 1 FROM `{$table_escaped}` WHERE account_id = %d OR cache_key IN ({$placeholders}) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...$args
			)
		);

		return null !== $found && false !== $found && '' !== (string) $found;
	}

	/**
	 * Delete cache rows for one account.
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

	/**
	 * Cache key for a disconnected account's public profile snapshot.
	 *
	 * @since 6.7.9
	 *
	 * @param int $account_id Internal account id.
	 * @return string
	 */
	public static function account_profile_snapshot_key( $account_id ) {
		return sprintf( 'esf_tw_account_profile_%d', max( 0, (int) $account_id ) );
	}

	/**
	 * Persist a token-free account profile snapshot for disconnected-feed headers.
	 *
	 * Stores the remote CDN avatar URL (plus a compact `account_data` copy),
	 * never a local `uploads/esf-twitter/` URL. Header render localizes on the fly.
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

		$remote_avatar = self::remote_profile_image_url_from_account_row( $account_row );

		$snapshot = array(
			'id'                => $account_id,
			'x_user_id'         => isset( $account_row->x_user_id ) ? (string) $account_row->x_user_id : '',
			'username'          => isset( $account_row->username ) ? (string) $account_row->username : '',
			'display_name'      => isset( $account_row->display_name ) ? (string) $account_row->display_name : '',
			'profile_image_url' => $remote_avatar,
			'description'       => isset( $account_row->description ) ? (string) $account_row->description : '',
			'followers_count'   => isset( $account_row->followers_count ) ? (int) $account_row->followers_count : 0,
			'following_count'   => isset( $account_row->following_count ) ? (int) $account_row->following_count : 0,
			'tweet_count'       => isset( $account_row->tweet_count ) ? (int) $account_row->tweet_count : 0,
			'is_verified'       => ! empty( $account_row->is_verified ),
			'verified_type'     => isset( $account_row->verified_type ) ? (string) $account_row->verified_type : '',
			'account_type'      => isset( $account_row->account_type ) ? (string) $account_row->account_type : 'connected',
		);

		if ( '' !== $remote_avatar ) {
			$snapshot['account_data'] = array(
				'profile_image_url' => $remote_avatar,
			);
		}

		return self::set(
			self::account_profile_snapshot_key( $account_id ),
			$snapshot,
			self::resolve_profile_snapshot_ttl(),
			'account',
			$account_id
		);
	}

	/**
	 * Remote CDN avatar URL from an account row (never a local upload URL).
	 *
	 * Disconnect snapshots must keep the CDN URL so the header can re-download
	 * if `uploads/esf-twitter/{id}/` is missing. The live DB column already
	 * stores remote URLs; do not replace them with `resolve_avatar_display_url()`.
	 *
	 * @since 6.7.9
	 * @param object $account_row Account row.
	 * @return string
	 */
	private static function remote_profile_image_url_from_account_row( $account_row ) {
		$stored = isset( $account_row->profile_image_url ) ? trim( (string) $account_row->profile_image_url ) : '';
		if ( '' !== $stored && ( ! function_exists( 'esf_is_local_media_url' ) || ! esf_is_local_media_url( $stored, 'twitter' ) ) ) {
			return $stored;
		}

		$account_data = isset( $account_row->account_data ) ? $account_row->account_data : null;
		if ( is_string( $account_data ) && '' !== $account_data ) {
			$decoded      = json_decode( $account_data, true );
			$account_data = is_array( $decoded ) ? $decoded : array();
		}
		if ( is_array( $account_data ) && ! empty( $account_data['profile_image_url'] ) ) {
			$candidate = trim( (string) $account_data['profile_image_url'] );
			if ( '' !== $candidate && ( ! function_exists( 'esf_is_local_media_url' ) || ! esf_is_local_media_url( $candidate, 'twitter' ) ) ) {
				return $candidate;
			}
		}

		return '';
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

		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE `{$table_escaped}` SET expires_at = %s WHERE account_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$pinned,
				$account_id
			)
		);
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
		if ( $feed_id <= 0 || ! class_exists( 'ESF_Twitter_Feed_Repository' ) ) {
			return false;
		}

		$feed = ESF_Twitter_Feed_Repository::get_instance()->get_by_id( $feed_id );
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
		if ( $feed_id <= 0 || ! class_exists( 'ESF_Twitter_Feed_Repository' ) ) {
			return false;
		}

		$feed = ESF_Twitter_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( ! $feed || empty( $feed->account_id ) ) {
			return false;
		}

		global $wpdb;

		$account_id    = (int) $feed->account_id;
		$table_escaped = esc_sql( self::get_table_name() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped via esc_sql().
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table_escaped}` WHERE expires_at = %s AND account_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped via esc_sql().
				self::PINNED_EXPIRES_AT,
				$account_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count > 0;
	}
}
