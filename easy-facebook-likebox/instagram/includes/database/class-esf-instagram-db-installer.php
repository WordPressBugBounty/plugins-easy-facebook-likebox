<?php
/**
 * Instagram Database Installer
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/Database
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_DB_Installer
 *
 * @since 6.8.0
 */
class ESF_Instagram_DB_Installer {

	/**
	 * Create required Instagram tables.
	 *
	 * @since 6.8.0
	 * @return bool
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$accounts = self::create_accounts_table( $charset_collate );
		$feeds    = self::create_feeds_table( $charset_collate );
		$cache    = self::create_cache_table( $charset_collate );

		return $accounts && $feeds && $cache;
	}

	/**
	 * Create accounts table.
	 *
	 * @since 6.8.0
	 * @param string $charset_collate Charset/collate clause.
	 * @return bool
	 */
	private static function create_accounts_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_instagram_accounts';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED NOT NULL,
			account_type enum('personal','business','creator') NOT NULL DEFAULT 'business',
			auth_source enum('instagram_login','facebook_page') NOT NULL DEFAULT 'facebook_page',
			instagram_user_id varchar(64) NOT NULL DEFAULT '',
			username varchar(100) NOT NULL DEFAULT '',
			display_name varchar(255) NOT NULL DEFAULT '',
			profile_image_url text DEFAULT NULL,
			biography text DEFAULT NULL,
			website varchar(500) DEFAULT NULL,
			followers_count bigint(20) UNSIGNED DEFAULT 0,
			media_count bigint(20) UNSIGNED DEFAULT 0,
			facebook_page_id varchar(64) DEFAULT NULL,
			facebook_page_name varchar(255) DEFAULT NULL,
			facebook_user_id varchar(64) DEFAULT NULL,
			facebook_user_token text DEFAULT NULL,
			page_access_token text DEFAULT NULL,
			access_token text DEFAULT NULL,
			token_expires_at datetime DEFAULT NULL,
			account_data longtext DEFAULT NULL,
			status enum('active','expired','invalid') DEFAULT 'active',
			stats_refreshed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY instagram_user_id (instagram_user_id),
			KEY username (username),
			KEY account_type (account_type),
			KEY auth_source (auth_source),
			KEY status (status)
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
	 * Create feeds table.
	 *
	 * @since 6.8.0
	 * @param string $charset_collate Charset/collate clause.
	 * @return bool
	 */
	private static function create_feeds_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_instagram_feeds';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL DEFAULT '',
			account_id bigint(20) UNSIGNED NOT NULL,
			feed_type varchar(50) NOT NULL DEFAULT 'user_timeline',
			source_id varchar(255) DEFAULT NULL,
			settings longtext NOT NULL,
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
	 * Create cache table.
	 *
	 * @since 6.8.0
	 * @param string $charset_collate Charset/collate clause.
	 * @return bool
	 */
	private static function create_cache_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_instagram_cache';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			cache_key varchar(255) NOT NULL,
			cache_data longtext NOT NULL,
			cache_type enum('account','feed','api') DEFAULT 'api',
			account_id bigint(20) UNSIGNED DEFAULT NULL,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY cache_key (cache_key),
			KEY expires_at (expires_at),
			KEY cache_type (cache_type),
			KEY account_id (account_id)
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
	 * Check if all required tables exist.
	 *
	 * @since 6.8.0
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'esf_instagram_accounts',
			$wpdb->prefix . 'esf_instagram_feeds',
			$wpdb->prefix . 'esf_instagram_cache',
		);

		foreach ( $tables as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				return false;
			}
		}

		return true;
	}

	/**
	 * Remove all rows from Instagram plugin tables (tables remain).
	 *
	 * Order: cache and feeds before accounts in case foreign keys are added later.
	 *
	 * @since 6.8.0
	 * @return void
	 */
	public static function truncate_all_tables() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'esf_instagram_cache',
			$wpdb->prefix . 'esf_instagram_feeds',
			$wpdb->prefix . 'esf_instagram_accounts',
		);

		foreach ( $tables as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $exists !== $table ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated above.
			$wpdb->query( 'TRUNCATE TABLE `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}
}
