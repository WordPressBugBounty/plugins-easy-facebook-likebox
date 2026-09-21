<?php
/**
 * TikTok Database Installer
 *
 * Handles database table creation and removal for the TikTok module.
 * Implements WordPress dbDelta best practices for schema management.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok/Database
 * @since 6.8.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_TikTok_DB_Installer
 *
 * Manages the database schema for the TikTok module.
 *
 * @since 6.8.0
 */
class ESF_TikTok_DB_Installer {

	/**
	 * Create all required database tables.
	 *
	 * @since 6.8.0
	 * @return bool True when all tables are verified to exist.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$accounts_created = self::create_accounts_table( $charset_collate );
		$feeds_created    = self::create_feeds_table( $charset_collate );
		$cache_created    = self::create_cache_table( $charset_collate );

		self::maybe_add_auth_source_column();

		return ( $accounts_created && $feeds_created && $cache_created );
	}

	/**
	 * Create the accounts table.
	 *
	 * Stores connected TikTok user account data from Login Kit OAuth.
	 * account_type is reserved as enum('user') for future types.
	 *
	 * @since 6.8.0
	 * @param string $charset_collate DB charset/collation string.
	 * @return bool True when table exists.
	 */
	private static function create_accounts_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_tiktok_accounts';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED NOT NULL COMMENT 'WordPress user who connected the account',
			account_type enum('user') NOT NULL DEFAULT 'user',
			open_id varchar(128) NOT NULL DEFAULT '' COMMENT 'TikTok open_id (upsert key)',
			union_id varchar(128) DEFAULT NULL COMMENT 'TikTok union_id when available',
			username varchar(255) NOT NULL DEFAULT '' COMMENT 'From user.info.profile when granted',
			display_name varchar(255) NOT NULL DEFAULT '' COMMENT 'Display name from user.info.basic',
			avatar_url text DEFAULT NULL COMMENT 'Profile avatar URL',
			bio_description text DEFAULT NULL COMMENT 'Bio from user.info.profile',
			is_verified tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Verified badge when profile scope granted',
			profile_deep_link varchar(500) DEFAULT NULL COMMENT 'Official profile deep link',
			follower_count bigint(20) UNSIGNED DEFAULT 0,
			following_count bigint(20) UNSIGNED DEFAULT 0,
			likes_count bigint(20) UNSIGNED DEFAULT 0,
			video_count bigint(20) UNSIGNED DEFAULT 0,
			access_token text DEFAULT NULL COMMENT 'OAuth access token (~24h)',
			auth_source enum('oauth','manual') NOT NULL DEFAULT 'oauth',
			token_expires_at datetime DEFAULT NULL COMMENT 'Access token expiry',
			refresh_token text DEFAULT NULL COMMENT 'OAuth refresh token (~365d)',
			refresh_token_expires_at datetime DEFAULT NULL COMMENT 'Refresh token expiry',
			scopes_granted text DEFAULT NULL COMMENT 'Comma-separated scopes from token response',
			account_data longtext DEFAULT NULL COMMENT 'Full JSON snapshot of last user.info response',
			status enum('active','expired','invalid') DEFAULT 'active',
			stats_refreshed_at datetime DEFAULT NULL COMMENT 'Last time profile stats were fetched',
			created_at datetime NOT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY open_id (open_id),
			KEY status (status),
			KEY auth_source (auth_source),
			KEY token_expires_at (token_expires_at),
			KEY stats_refreshed_at (stats_refreshed_at)
		) {$charset_collate};";

		if ( function_exists( 'esf_dbdelta' ) ) {
			esf_dbdelta( $sql );
		} else {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql ); // phpcs:ignore WordPress.DB.SchemaChange.SchemaChange
		}

		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Create the feeds table.
	 *
	 * Stores feed configurations and their JSON settings.
	 *
	 * @since 6.8.0
	 * @param string $charset_collate DB charset/collation string.
	 * @return bool True when table exists.
	 */
	private static function create_feeds_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_tiktok_feeds';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL DEFAULT '' COMMENT 'Feed display name',
			account_id bigint(20) UNSIGNED NOT NULL COMMENT 'Linked esf_tiktok_accounts.id',
			feed_type varchar(50) NOT NULL DEFAULT 'user_videos' COMMENT 'Feed source type',
			source_id varchar(255) DEFAULT NULL COMMENT 'TikTok open_id',
			settings longtext NOT NULL COMMENT 'JSON feed settings',
			status enum('active','inactive') DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY account_id (account_id),
			KEY status (status),
			KEY feed_type (feed_type)
		) {$charset_collate};";

		if ( function_exists( 'esf_dbdelta' ) ) {
			esf_dbdelta( $sql );
		} else {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql ); // phpcs:ignore WordPress.DB.SchemaChange.SchemaChange
		}

		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Create the cache table.
	 *
	 * Stores API response cache with expiration support.
	 * Runtime cache keys are account-scoped (see ESF_TikTok_Cache in Phase 3).
	 *
	 * @since 6.8.0
	 * @param string $charset_collate DB charset/collation string.
	 * @return bool True when table exists.
	 */
	private static function create_cache_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_tiktok_cache';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			cache_key varchar(255) NOT NULL,
			cache_data longtext NOT NULL COMMENT 'JSON-encoded API response',
			cache_type enum('account','feed','api') DEFAULT 'api',
			feed_id bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Feed ID when applicable',
			account_id bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Account ID for account-scoped cache',
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY cache_key (cache_key),
			KEY expires_at (expires_at),
			KEY cache_type (cache_type),
			KEY feed_id (feed_id),
			KEY account_id (account_id),
			KEY cache_key_expires (cache_key, expires_at)
		) {$charset_collate};";

		if ( function_exists( 'esf_dbdelta' ) ) {
			esf_dbdelta( $sql );
		} else {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql ); // phpcs:ignore WordPress.DB.SchemaChange.SchemaChange
		}

		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Ensure accounts.auth_source exists on upgrades that predate the column.
	 *
	 * @since 6.7.9
	 * @return void
	 */
	public static function maybe_add_auth_source_column() {
		global $wpdb;

		$table = $wpdb->prefix . 'esf_tiktok_accounts';
		if ( ! function_exists( 'esf_db_maybe_add_column' ) ) {
			return;
		}

		esf_db_maybe_add_column(
			$table,
			'auth_source',
			"enum('oauth','manual') NOT NULL DEFAULT 'oauth'",
			'access_token'
		);
	}

	/**
	 * Drop all TikTok module tables.
	 *
	 * WARNING: This permanently deletes all TikTok module data.
	 * Call only during uninstall when the user has not opted to preserve data.
	 *
	 * @since 6.8.0
	 * @return bool True on success.
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'esf_tiktok_accounts',
			$wpdb->prefix . 'esf_tiktok_feeds',
			$wpdb->prefix . 'esf_tiktok_cache',
		);

		foreach ( $tables as $table ) {
			$table_escaped = esc_sql( $table );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table_escaped}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		return true;
	}

	/**
	 * Verify all tables exist.
	 *
	 * @since 6.8.0
	 * @return bool True when all three tables are present.
	 */
	public static function tables_exist() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'esf_tiktok_accounts',
			$wpdb->prefix . 'esf_tiktok_feeds',
			$wpdb->prefix . 'esf_tiktok_cache',
		);

		foreach ( $tables as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete expired cache entries.
	 *
	 * Removes rows whose expires_at is in the past.
	 * Can be called via WP-Cron for periodic cleanup.
	 *
	 * @since 6.8.0
	 * @return int Number of rows deleted.
	 */
	public static function clean_expired_cache() {
		global $wpdb;

		$table         = $wpdb->prefix . 'esf_tiktok_cache';
		$table_escaped = esc_sql( $table );

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"DELETE FROM `{$table_escaped}` WHERE expires_at < NOW()" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return (int) $deleted;
	}

	/**
	 * Return the three module table names (with prefix).
	 *
	 * Exposed for unit tests without hitting create_tables().
	 *
	 * @since 6.8.0
	 * @return array<int,string>
	 */
	public static function get_table_names() {
		global $wpdb;

		return array(
			$wpdb->prefix . 'esf_tiktok_accounts',
			$wpdb->prefix . 'esf_tiktok_feeds',
			$wpdb->prefix . 'esf_tiktok_cache',
		);
	}
}
