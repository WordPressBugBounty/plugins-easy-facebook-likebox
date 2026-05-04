<?php
/**
 * Twitter Database Installer
 *
 * Handles database table creation and removal for the Twitter module.
 * Implements WordPress dbDelta best practices for schema management.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/Database
 * @since 6.7.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Twitter_DB_Installer
 *
 * Manages the database schema for the Twitter module.
 *
 * @since 6.7.6
 */
class ESF_Twitter_DB_Installer {

	/**
	 * Create all required database tables.
	 *
	 * @since 6.7.6
	 * @return bool True when all tables are verified to exist.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$accounts_created = self::create_accounts_table( $charset_collate );
		$feeds_created    = self::create_feeds_table( $charset_collate );
		$cache_created    = self::create_cache_table( $charset_collate );

		return ( $accounts_created && $feeds_created && $cache_created );
	}

	/**
	 * Create the accounts table.
	 *
	 * Stores X account connection data. Both OAuth-connected accounts
	 * (account_type = 'connected') and public accounts added by username
	 * (account_type = 'public', Pro) share this table.
	 *
	 * @since 6.7.6
	 * @param string $charset_collate DB charset/collation string.
	 * @return bool True when table exists.
	 */
	private static function create_accounts_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_twitter_accounts';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED NOT NULL COMMENT 'WordPress user who connected the account',
			account_type enum('connected','public') NOT NULL DEFAULT 'connected',
			x_user_id varchar(32) NOT NULL DEFAULT '' COMMENT 'X platform user ID',
			username varchar(100) NOT NULL DEFAULT '' COMMENT 'X username without @',
			display_name varchar(255) NOT NULL DEFAULT '' COMMENT 'X display name',
			profile_image_url text DEFAULT NULL COMMENT 'Profile picture URL',
			description text DEFAULT NULL COMMENT 'Bio / description',
			followers_count bigint(20) UNSIGNED DEFAULT 0,
			following_count bigint(20) UNSIGNED DEFAULT 0,
			tweet_count bigint(20) UNSIGNED DEFAULT 0,
			is_verified tinyint(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Whether account is verified on X',
			verified_type varchar(50) NOT NULL DEFAULT '' COMMENT 'X verified_type (blue, business, government, etc.)',
			access_token text DEFAULT NULL COMMENT 'OAuth 2.0 access token (connected accounts only)',
			refresh_token text DEFAULT NULL COMMENT 'OAuth 2.0 refresh token (connected accounts only)',
			token_expires_at datetime DEFAULT NULL COMMENT 'Access token expiry (connected accounts only)',
			account_data longtext DEFAULT NULL COMMENT 'Full JSON user object from X API',
			status enum('active','expired','invalid') DEFAULT 'active',
			stats_refreshed_at datetime DEFAULT NULL COMMENT 'Last time profile stats were fetched',
			created_at datetime NOT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY x_user_id (x_user_id),
			KEY username (username),
			KEY account_type (account_type),
			KEY status (status),
			KEY stats_refreshed_at (stats_refreshed_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql ); // phpcs:ignore WordPress.DB.SchemaChange.SchemaChange

		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Create the feeds table.
	 *
	 * Stores feed configurations and their JSON settings.
	 *
	 * @since 6.7.6
	 * @param string $charset_collate DB charset/collation string.
	 * @return bool True when table exists.
	 */
	private static function create_feeds_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_twitter_feeds';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL DEFAULT '' COMMENT 'Feed display name',
			account_id bigint(20) UNSIGNED NOT NULL COMMENT 'Linked esf_twitter_accounts.id',
			feed_type varchar(50) NOT NULL DEFAULT 'user_timeline' COMMENT 'Feed source type',
			source_id varchar(255) DEFAULT NULL COMMENT 'x_user_id or search query',
			settings longtext NOT NULL COMMENT 'JSON feed settings',
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

		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Create the cache table.
	 *
	 * Stores API response cache with expiration support,
	 * using the same structure as the YouTube cache table.
	 *
	 * @since 6.7.6
	 * @param string $charset_collate DB charset/collation string.
	 * @return bool True when table exists.
	 */
	private static function create_cache_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_twitter_cache';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			cache_key varchar(255) NOT NULL,
			cache_data longtext NOT NULL COMMENT 'JSON-encoded API response',
			cache_type enum('account','feed','api') DEFAULT 'api',
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

		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Drop all Twitter module tables.
	 *
	 * WARNING: This permanently deletes all Twitter module data.
	 * Call only during uninstall when the user has not opted to preserve data.
	 *
	 * @since 6.7.6
	 * @return bool True on success.
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'esf_twitter_accounts',
			$wpdb->prefix . 'esf_twitter_feeds',
			$wpdb->prefix . 'esf_twitter_cache',
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
	 * @since 6.7.6
	 * @return bool True when all three tables are present.
	 */
	public static function tables_exist() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'esf_twitter_accounts',
			$wpdb->prefix . 'esf_twitter_feeds',
			$wpdb->prefix . 'esf_twitter_cache',
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
	 * @since 6.7.6
	 * @return int Number of rows deleted.
	 */
	public static function clean_expired_cache() {
		global $wpdb;

		$table         = $wpdb->prefix . 'esf_twitter_cache';
		$table_escaped = esc_sql( $table );

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"DELETE FROM `{$table_escaped}` WHERE expires_at < NOW()" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return (int) $deleted;
	}
}
