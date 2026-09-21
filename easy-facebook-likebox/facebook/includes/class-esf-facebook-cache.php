<?php
/**
 * Facebook Module Cache
 *
 * Reads/writes `wp_esf_facebook_cache`.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Cache
 *
 * @since 6.7.9
 */
class ESF_Facebook_Cache {

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
	 * Allowed cache_type values.
	 *
	 * @var string[]
	 */
	private static $allowed_types = array( 'page', 'feed', 'api', 'live' );

	/**
	 * Get the cache table name.
	 *
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'esf_facebook_cache';
	}

	/**
	 * Sanitize cache key.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	private static function sanitize_key( $key ) {
		$key = sanitize_text_field( (string) $key );
		return substr( $key, 0, 255 );
	}

	/**
	 * Page-scoped posts cache key.
	 *
	 * @param int    $page_row_id Internal page id.
	 * @param string $feed_type   Feed type.
	 * @return string
	 */
	public static function posts_cache_key( $page_row_id, $feed_type = 'timeline' ) {
		$page_row_id = max( 0, (int) $page_row_id );
		$feed_type   = sanitize_key( (string) $feed_type );
		if ( '' === $feed_type ) {
			$feed_type = 'timeline';
		}
		return sprintf( 'esf_fb_posts_%d_%s', $page_row_id, $feed_type );
	}

	/**
	 * Page-scoped posts meta cache key (pagination cursor, etc.).
	 *
	 * @param int    $page_row_id Internal page id.
	 * @param string $feed_type   Feed type.
	 * @return string
	 */
	public static function posts_meta_cache_key( $page_row_id, $feed_type = 'timeline' ) {
		return self::posts_cache_key( $page_row_id, $feed_type ) . '_meta';
	}

	/**
	 * Live-stream status cache key.
	 *
	 * @param int $page_row_id Internal page id.
	 * @return string
	 */
	public static function live_cache_key( $page_row_id ) {
		return sprintf( 'esf_fb_live_%d', max( 0, (int) $page_row_id ) );
	}

	/**
	 * Retrieve cached data by key (null on miss/expired).
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
	 * Read cache even when expired (stale-while-revalidate).
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
	 * Store data in the cache.
	 *
	 * @param string   $key        Cache key.
	 * @param array    $data       Data to store.
	 * @param int|null $ttl_secs   TTL in seconds.
	 * @param string   $cache_type page|feed|api|live.
	 * @param int|null $page_id    Optional page scope.
	 * @param int|null $feed_id    Optional feed scope.
	 * @return bool
	 */
	public static function set( $key, $data, $ttl_secs = null, $cache_type = 'api', $page_id = null, $feed_id = null ) {
		global $wpdb;

		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return false;
		}

		if ( function_exists( 'esf_db_safe_text' ) ) {
			$data = esf_db_safe_text( $data );
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
				'page_id'    => $page_id ? (int) $page_id : null,
				'feed_id'    => $feed_id ? (int) $feed_id : null,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl_secs ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return true;
	}

	/**
	 * Delete by cache key.
	 *
	 * @param string $key Cache key.
	 * @return int|false
	 */
	public static function delete_by_key( $key ) {
		global $wpdb;

		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return false;
		}

		return $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			self::get_table_name(),
			array( 'cache_key' => $key ),
			array( '%s' )
		);
	}

	/**
	 * Delete cache rows for a page.
	 *
	 * @param int $page_id Internal page id.
	 * @return int|false
	 */
	public static function delete_by_page_id( $page_id ) {
		global $wpdb;

		$page_id = (int) $page_id;
		if ( $page_id <= 0 ) {
			return false;
		}

		return $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			self::get_table_name(),
			array( 'page_id' => $page_id ),
			array( '%d' )
		);
	}

	/**
	 * Alias for delete_by_page_id() used by REST clear-cache.
	 *
	 * @param int $page_id Internal page id.
	 * @return int|false
	 */
	public static function flush_page_cache( $page_id ) {
		return self::delete_by_page_id( $page_id );
	}

	/**
	 * Delete cache rows for a feed.
	 *
	 * @param int $feed_id Feed id.
	 * @return int|false
	 */
	public static function delete_by_feed_id( $feed_id ) {
		global $wpdb;

		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return false;
		}

		return $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			self::get_table_name(),
			array( 'feed_id' => $feed_id ),
			array( '%d' )
		);
	}

	/**
	 * Flush all Facebook cache rows.
	 *
	 * @return int|false
	 */
	public static function flush_all_cache() {
		global $wpdb;

		$table_escaped = esc_sql( self::get_table_name() );
		return $wpdb->query( "DELETE FROM `{$table_escaped}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Cache key for a disconnected page's public profile snapshot.
	 *
	 * @since 6.7.9
	 *
	 * @param int $page_row_id Internal pages.id.
	 * @return string
	 */
	public static function page_profile_snapshot_key( $page_row_id ) {
		return sprintf( 'esf_fb_page_profile_%d', max( 0, (int) $page_row_id ) );
	}

	/**
	 * Persist a token-free page profile snapshot for disconnected-feed headers.
	 *
	 * @since 6.7.9
	 *
	 * @param object $page_row Page row (must include internal id).
	 * @return bool
	 */
	public static function save_page_profile_snapshot( $page_row ) {
		if ( ! is_object( $page_row ) || empty( $page_row->id ) ) {
			return false;
		}

		$page_row_id = (int) $page_row->id;
		if ( $page_row_id <= 0 || ! class_exists( 'ESF_Facebook_Page_Repository' ) ) {
			return false;
		}

		$snapshot = ESF_Facebook_Page_Repository::format_public( $page_row );
		if ( empty( $snapshot ) ) {
			return false;
		}

		return self::set(
			self::page_profile_snapshot_key( $page_row_id ),
			$snapshot,
			self::resolve_profile_snapshot_ttl(),
			'page',
			$page_row_id
		);
	}

	/**
	 * TTL for disconnect profile snapshots (same as configured feed cache duration).
	 *
	 * @return int
	 */
	private static function resolve_profile_snapshot_ttl() {
		if ( function_exists( 'esf_facebook_get_cache_ttl_secs' ) ) {
			$ttl = (int) esf_facebook_get_cache_ttl_secs();
			if ( $ttl > 0 ) {
				return $ttl;
			}
		}

		return self::DEFAULT_TTL;
	}

	/**
	 * Read a stored page profile snapshot (includes expired rows).
	 *
	 * @since 6.7.9
	 *
	 * @param int $page_row_id Internal pages.id.
	 * @return array<string,mixed>|null
	 */
	public static function get_page_profile_snapshot( $page_row_id ) {
		$page_row_id = (int) $page_row_id;
		if ( $page_row_id <= 0 ) {
			return null;
		}

		$data = self::get_stale( self::page_profile_snapshot_key( $page_row_id ) );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Extend cache expiry for all rows tied to a page (posts, meta, live, profile snapshot).
	 *
	 * @since 6.7.9
	 *
	 * @param int $page_row_id Internal pages.id.
	 * @return int Rows updated.
	 */
	public static function pin_page_cache( $page_row_id ) {
		global $wpdb;

		$page_row_id = (int) $page_row_id;
		if ( $page_row_id <= 0 ) {
			return 0;
		}

		$table_escaped = esc_sql( self::get_table_name() );
		$pinned        = self::PINNED_EXPIRES_AT;
		$updated       = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated += (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table_escaped}` SET expires_at = %s WHERE page_id = %d",
				$pinned,
				$page_row_id
			)
		);

		$posts_prefix = $wpdb->esc_like( 'esf_fb_posts_' . $page_row_id . '_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated += (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table_escaped}` SET expires_at = %s WHERE cache_key LIKE %s",
				$pinned,
				$posts_prefix
			)
		);

		$snapshot_key = self::page_profile_snapshot_key( $page_row_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated += (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table_escaped}` SET expires_at = %s WHERE cache_key = %s",
				$pinned,
				$snapshot_key
			)
		);

		return $updated;
	}

	/**
	 * Pin cache rows for a feed's linked page.
	 *
	 * @since 6.7.9
	 *
	 * @param int $feed_id Feed id.
	 * @return int|false Rows updated, or false when feed/page is missing.
	 */
	public static function pin_feed_cache( $feed_id ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 || ! class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
			return false;
		}

		$feed = ESF_Facebook_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( ! $feed || empty( $feed->page_id ) ) {
			return false;
		}

		return self::pin_page_cache( (int) $feed->page_id );
	}

	/**
	 * Whether any cache rows for this feed's page use the pinned far-future expiry.
	 *
	 * @since 6.7.9
	 *
	 * @param int $feed_id Feed id.
	 * @return bool
	 */
	public static function feed_has_pinned_cache_rows( $feed_id ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 || ! class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
			return false;
		}

		$feed = ESF_Facebook_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( ! $feed || empty( $feed->page_id ) ) {
			return false;
		}

		global $wpdb;

		$page_row_id   = (int) $feed->page_id;
		$table_escaped = esc_sql( self::get_table_name() );
		$posts_prefix  = $wpdb->esc_like( 'esf_fb_posts_' . $page_row_id . '_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table_escaped}` WHERE expires_at = %s AND (page_id = %d OR cache_key LIKE %s)",
				self::PINNED_EXPIRES_AT,
				$page_row_id,
				$posts_prefix
			)
		);

		return $count > 0;
	}
}
