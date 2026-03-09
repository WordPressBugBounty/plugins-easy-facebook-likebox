<?php
/**
 * Database Installer
 *
 * Handles database table creation, updates, and removal for the YouTube module.
 * Implements WordPress dbDelta best practices for schema management.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/Database
 * @since 6.7.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_DB_Installer
 *
 * Manages database schema for YouTube module.
 * Handles table creation, versioning, and cleanup.
 *
 * @since 6.7.5
 */
class ESF_YouTube_DB_Installer {

	/**
	 * Create database tables.
	 *
	 * Creates all required tables for the YouTube module.
	 * Uses dbDelta for safe schema updates.
	 *
	 * @since 6.7.5
	 * @return bool True on success, false on failure.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Create accounts table.
		$accounts_created = self::create_accounts_table( $charset_collate );

		// Create feeds table.
		$feeds_created = self::create_feeds_table( $charset_collate );

		// Create cache table.
		$cache_created = self::create_cache_table( $charset_collate );

		return ( $accounts_created && $feeds_created && $cache_created );
	}

	/**
	 * Create accounts table.
	 *
	 * Stores YouTube account connection data including OAuth tokens.
	 *
	 * @since 6.7.5
	 * @param string $charset_collate Database charset and collation.
	 * @return bool True on success.
	 */
	private static function create_accounts_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_youtube_accounts';

		// Always run dbDelta to ensure schema is up-to-date.
		// dbDelta is smart: it creates table if missing, or updates schema if table exists.
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED NOT NULL,
			channel_name varchar(255) NOT NULL DEFAULT '' COMMENT 'YouTube channel name',
			channel_id varchar(255) NOT NULL DEFAULT '' COMMENT 'YouTube channel ID',
			handle varchar(255) DEFAULT NULL COMMENT 'YouTube handle (@handle)',
			description text DEFAULT NULL COMMENT 'Channel description/bio',
			channel_thumbnail text DEFAULT NULL COMMENT 'Channel thumbnail URL',
			channel_banner text DEFAULT NULL COMMENT 'Channel banner image URL (Pro)',
			subscriber_count bigint(20) UNSIGNED DEFAULT 0 COMMENT 'Total subscribers',
			video_count bigint(20) UNSIGNED DEFAULT 0 COMMENT 'Total videos',
			view_count bigint(20) UNSIGNED DEFAULT 0 COMMENT 'Total channel views',
			access_token text NOT NULL,
			refresh_token text NOT NULL,
			token_expires_at datetime DEFAULT NULL,
			channel_data longtext DEFAULT NULL COMMENT 'JSON data backup (full API response)',
			status enum('active','expired','revoked') DEFAULT 'active',
			stats_refreshed_at datetime DEFAULT NULL COMMENT 'Last time channel stats were refreshed from API',
			created_at datetime NOT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY status (status),
			KEY token_expires_at (token_expires_at),
			KEY channel_id (channel_id),
			KEY subscriber_count (subscriber_count),
			KEY stats_refreshed_at (stats_refreshed_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql ); // phpcs:ignore WordPress.DB.SchemaChange.SchemaChange

		// Verify table exists (after creation or update).
		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Create feeds table.
	 *
	 * Stores YouTube feed configurations and settings.
	 *
	 * @since 6.7.5
	 * @param string $charset_collate Database charset and collation.
	 * @return bool True on success.
	 */
	private static function create_feeds_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_youtube_feeds';

		// Always run dbDelta to ensure schema is up-to-date.
		// dbDelta is smart: it creates table if missing, or updates schema if table exists.
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL DEFAULT '' COMMENT 'Feed name',
			account_id bigint(20) UNSIGNED NOT NULL COMMENT 'Linked YouTube account ID',
			feed_type varchar(50) NOT NULL DEFAULT 'channel' COMMENT 'Feed source type (channel, playlist, search, etc.)',
			source_id varchar(255) DEFAULT NULL COMMENT 'Playlist ID, search query, or other source identifier',
			settings longtext NOT NULL COMMENT 'JSON settings (layout, moderation, styles, etc.)',
			status enum('active','inactive') DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY account_id (account_id),
			KEY status (status),
			KEY feed_type (feed_type)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql ); // phpcs:ignore WordPress.DB.SchemaChange.SchemaChange

		// Verify table exists (after creation or update).
		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Create cache table.
	 *
	 * Stores API response cache with expiration support.
	 *
	 * @since 6.7.5
	 * @param string $charset_collate Database charset and collation.
	 * @return bool True on success.
	 */
	private static function create_cache_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_youtube_cache';

		// Always run dbDelta to ensure schema is up-to-date.
		// dbDelta is smart: it creates table if missing, or updates schema if table exists.
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			cache_key varchar(255) NOT NULL,
			cache_data longtext NOT NULL COMMENT 'Compressed JSON data',
			cache_type enum('account','feed','api') DEFAULT 'feed',
			feed_id bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Feed ID for feed-scoped cache',
			account_id bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Account ID for account-scoped cache',
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY cache_key (cache_key),
			KEY expires_at (expires_at),
			KEY cache_type (cache_type),
			KEY feed_id (feed_id),
			KEY account_id (account_id),
			KEY cache_key_expires (cache_key, expires_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql ); // phpcs:ignore WordPress.DB.SchemaChange.SchemaChange

		// Verify table exists (after creation or update).
		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Drop all YouTube module tables.
	 *
	 * WARNING: This will delete all YouTube data.
	 * Only used on uninstall if user hasn't chosen to preserve data.
	 *
	 * @since 6.7.5
	 * @return bool True on success.
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'esf_youtube_accounts',
			$wpdb->prefix . 'esf_youtube_feeds',
			$wpdb->prefix . 'esf_youtube_cache',
		);

		foreach ( $tables as $table ) {
			// Escape table name for security (even though $wpdb->prefix is trusted).
			$table_escaped = esc_sql( $table );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table_escaped}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		return true;
	}

	/**
	 * Check if tables exist.
	 *
	 * Verifies all required tables are present.
	 *
	 * @since 6.7.5
	 * @return bool True if all tables exist.
	 */
	public static function tables_exist() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'esf_youtube_accounts',
			$wpdb->prefix . 'esf_youtube_feeds',
			$wpdb->prefix . 'esf_youtube_cache',
		);

		foreach ( $tables as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				return false;
			}
		}

		return true;
	}

	/**
	 * Clean expired cache entries.
	 *
	 * Removes cache entries that have expired.
	 * Can be called via WP-Cron.
	 *
	 * @since 6.7.5
	 * @return int Number of rows deleted.
	 */
	public static function clean_expired_cache() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_youtube_cache';

		// Escape table name for security (even though $wpdb->prefix is trusted).
		$table_escaped = esc_sql( $table_name );

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"DELETE FROM `{$table_escaped}` WHERE expires_at < NOW()" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return (int) $deleted;
	}

	/**
	 * Get table statistics.
	 *
	 * Returns row counts for all YouTube tables.
	 * Useful for admin dashboard statistics.
	 *
	 * @since 6.7.5
	 * @return array Table statistics.
	 */
	public static function get_table_stats() {
		global $wpdb;

		$accounts_table = $wpdb->prefix . 'esf_youtube_accounts';
		$feeds_table    = $wpdb->prefix . 'esf_youtube_feeds';
		$cache_table    = $wpdb->prefix . 'esf_youtube_cache';

		// Escape table names for security (even though $wpdb->prefix is trusted).
		$accounts_escaped = esc_sql( $accounts_table );
		$feeds_escaped    = esc_sql( $feeds_table );
		$cache_escaped    = esc_sql( $cache_table );

		// Optimized: Single query instead of 4 separate queries for better performance.
		$query = "SELECT 
			(SELECT COUNT(*) FROM `{$accounts_escaped}`) as accounts,
			(SELECT COUNT(*) FROM `{$accounts_escaped}` WHERE status = 'active') as active_accounts,
			(SELECT COUNT(*) FROM `{$feeds_escaped}`) as feeds,
			(SELECT COUNT(*) FROM `{$feeds_escaped}` WHERE status = 'active') as active_feeds,
			(SELECT COUNT(*) FROM `{$cache_escaped}`) as cache_entries,
			(SELECT COUNT(*) FROM `{$cache_escaped}` WHERE expires_at < NOW()) as expired_cache";

		$result = $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Convert to integers and return.
		return array(
			'accounts'        => (int) $result['accounts'],
			'active_accounts' => (int) $result['active_accounts'],
			'feeds'           => (int) $result['feeds'],
			'active_feeds'    => (int) $result['active_feeds'],
			'cache_entries'   => (int) $result['cache_entries'],
			'expired_cache'   => (int) $result['expired_cache'],
		);
	}
}
