<?php
/**
 * TikTok Module Cache
 *
 * Account-scoped cache (mirrors ESF_Instagram_Cache). Posts for an org are keyed
 * by account_id so every feed on that account shares one cache row.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok
 * @since 6.9.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_TikTok_Cache
 *
 * @since 6.9.0
 */
class ESF_TikTok_Cache {

	/**
	 * Default TTL in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TTL = 43200;

	/**
	 * Far-future expiry for permanently pinned disconnected-feed cache rows.
	 *
	 * @var string
	 */
	const PINNED_EXPIRES_AT = '2099-12-31 23:59:59';

	/**
	 * TTL (seconds) for disconnected account profile snapshots.
	 *
	 * @var int
	 */
	const ACCOUNT_PROFILE_SNAPSHOT_TTL = 315360000;

	/**
	 * @var string[]
	 */
	private static $allowed_types = array( 'account', 'feed', 'api' );

	/**
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'esf_tiktok_cache';
	}

	/**
	 * @param string $key Cache key.
	 * @return string
	 */
	private static function sanitize_key( $key ) {
		$key = sanitize_text_field( (string) $key );
		return strlen( $key ) > 191 ? substr( $key, 0, 191 ) : $key;
	}

	/**
	 * Posts cache key for an account.
	 *
	 * @param int $account_id Account ID.
	 * @return string
	 */
	public static function posts_cache_key( $account_id ) {
		return sprintf( 'esf_tt_videos_%d', (int) $account_id );
	}

	/**
	 * Pagination / fetch meta cache key for an account (Instagram `_meta` parity).
	 *
	 * Stored separately so Load More (Pro) can append videos without rewriting the
	 * whole videos blob's bookkeeping. Also written on every cache warm in MVP.
	 *
	 * @param int $account_id Account ID.
	 * @return string
	 */
	public static function posts_meta_cache_key( $account_id ) {
		return sprintf( 'esf_tt_videos_%d_meta', (int) $account_id );
	}

	/**
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
	 * Read cache even when expired (stale-while-revalidate).
	 *
	 * @since 6.9.5
	 *
	 * @param string $key Cache key.
	 * @return array|null
	 */
	public static function get_stale( $key ) {
		global $wpdb;

		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return null;
		}

		$table_escaped = esc_sql( self::get_table_name() );
		$row           = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 * @since 6.9.5
	 *
	 * @param string $key Cache key.
	 * @return string|null MySQL datetime string, or null when no row exists.
	 */
	public static function get_expires_at( $key ) {
		global $wpdb;

		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return null;
		}

		$table_escaped = esc_sql( self::get_table_name() );
		$row           = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT expires_at FROM `{$table_escaped}` WHERE cache_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key
			)
		);

		return ( $row && ! empty( $row->expires_at ) ) ? $row->expires_at : null;
	}

	/**
	 * @param string   $key        Cache key.
	 * @param array    $data       Data.
	 * @param int|null $ttl_secs   TTL.
	 * @param string   $cache_type Type.
	 * @param int|null $account_id Account scope.
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
	 * Flush account-scoped cache for the feed's account.
	 *
	 * @param int $feed_id Feed ID.
	 * @return int|false
	 */
	public static function flush_feed_cache( $feed_id ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 || ! class_exists( 'ESF_TikTok_Feed_Repository' ) ) {
			return false;
		}

		$feed = ESF_TikTok_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( ! $feed || empty( $feed->account_id ) ) {
			return 0;
		}

		return self::flush_account_cache( (int) $feed->account_id );
	}

	/**
	 * @param int $account_id Account ID.
	 * @return int|false
	 */
	public static function flush_account_cache( $account_id ) {
		global $wpdb;

		$account_id = (int) $account_id;
		if ( $account_id <= 0 ) {
			return false;
		}

		$table_escaped = esc_sql( self::get_table_name() );
		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM `{$table_escaped}` WHERE account_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$account_id
			)
		);
	}

	/**
	 * @return int|false
	 */
	public static function flush_api_cache() {
		global $wpdb;

		$table_escaped = esc_sql( self::get_table_name() );
		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"DELETE FROM `{$table_escaped}` WHERE cache_type = 'api'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * @return int|false
	 */
	public static function flush_all_cache() {
		global $wpdb;

		$table_escaped = esc_sql( self::get_table_name() );
		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"DELETE FROM `{$table_escaped}`" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
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
		return sprintf( 'esf_tt_account_profile_%d', max( 0, (int) $account_id ) );
	}

	/**
	 * Persist a token-free account profile snapshot for disconnected-feed headers.
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
			'open_id'           => isset( $account_row->open_id ) ? (string) $account_row->open_id : '',
			'username'          => isset( $account_row->username ) ? (string) $account_row->username : '',
			'display_name'      => isset( $account_row->display_name ) ? (string) $account_row->display_name : '',
			'avatar_url'        => isset( $account_row->avatar_url ) ? (string) $account_row->avatar_url : '',
			'bio_description'   => isset( $account_row->bio_description ) ? (string) $account_row->bio_description : '',
			'profile_deep_link' => isset( $account_row->profile_deep_link ) ? (string) $account_row->profile_deep_link : '',
			'follower_count'    => isset( $account_row->follower_count ) ? (int) $account_row->follower_count : 0,
			'following_count'   => isset( $account_row->following_count ) ? (int) $account_row->following_count : 0,
			'likes_count'       => isset( $account_row->likes_count ) ? (int) $account_row->likes_count : 0,
			'video_count'       => isset( $account_row->video_count ) ? (int) $account_row->video_count : 0,
			'is_verified'       => ! empty( $account_row->is_verified ),
		);

		if ( class_exists( 'ESF_TikTok_Local_Media' ) && '' !== $snapshot['avatar_url'] ) {
			$resolved = ESF_TikTok_Local_Media::resolve_avatar_display_url( $account_row );
			if ( '' !== $resolved ) {
				$snapshot['avatar_url'] = $resolved;
			}
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
	 * TTL for disconnect profile snapshots (matches normal feed cache duration).
	 *
	 * @return int
	 */
	private static function resolve_profile_snapshot_ttl() {
		return self::DEFAULT_TTL;
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table_escaped}` SET expires_at = %s WHERE account_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped via esc_sql().
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
		if ( $feed_id <= 0 || ! class_exists( 'ESF_TikTok_Feed_Repository' ) ) {
			return false;
		}

		$feed = ESF_TikTok_Feed_Repository::get_instance()->get_by_id( $feed_id );
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
		if ( $feed_id <= 0 || ! class_exists( 'ESF_TikTok_Feed_Repository' ) ) {
			return false;
		}

		$feed = ESF_TikTok_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( ! $feed || empty( $feed->account_id ) ) {
			return false;
		}

		global $wpdb;

		$account_id    = (int) $feed->account_id;
		$table_escaped = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table_escaped}` WHERE expires_at = %s AND account_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped via esc_sql().
				self::PINNED_EXPIRES_AT,
				$account_id
			)
		);

		return $count > 0;
	}
}
