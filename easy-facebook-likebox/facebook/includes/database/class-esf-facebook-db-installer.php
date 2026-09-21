<?php
/**
 * Facebook Database Installer
 *
 * Creates esf_facebook_accounts, esf_facebook_pages, esf_facebook_feeds,
 * and esf_facebook_cache tables. Schema version option:
 * `esf_facebook_db_schema_version` = '1.6'
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/Database
 * @since 6.7.9
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_DB_Installer
 *
 * @since 6.7.9
 */
class ESF_Facebook_DB_Installer {

	/**
	 * Current schema version.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '1.6';

	/**
	 * Option key for schema version.
	 *
	 * @var string
	 */
	const SCHEMA_OPTION = 'esf_facebook_db_schema_version';

	/**
	 * Create all required database tables and stamp schema version.
	 *
	 * @since 6.7.9
	 * @return bool True when all tables exist.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$accounts = self::create_accounts_table( $charset_collate );
		$pages    = self::create_pages_table( $charset_collate );
		$feeds    = self::create_feeds_table( $charset_collate );
		$cache    = self::create_cache_table( $charset_collate );

		$ok = ( $accounts && $pages && $feeds && $cache );
		if ( $ok ) {
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
		}

		return $ok;
	}

	/**
	 * Create accounts table.
	 *
	 * @since 6.7.9
	 * @param string $charset_collate Charset/collate clause.
	 * @return bool
	 */
	private static function create_accounts_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_facebook_accounts';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED NOT NULL,
			facebook_user_id varchar(64) NOT NULL DEFAULT '',
			facebook_user_name varchar(255) DEFAULT NULL,
			facebook_user_token text DEFAULT NULL,
			auth_source enum('oauth','manual') NOT NULL DEFAULT 'oauth',
			token_expires_at datetime DEFAULT NULL,
			status enum('active','expired','invalid') DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY facebook_user_id (facebook_user_id),
			KEY status (status),
			KEY auth_source (auth_source),
			KEY token_expires_at (token_expires_at)
		) {$charset_collate};";

		self::run_dbdelta( $sql );

		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Create pages table.
	 *
	 * @since 6.7.9
	 * @param string $charset_collate Charset/collate clause.
	 * @return bool
	 */
	private static function create_pages_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_facebook_pages';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id bigint(20) UNSIGNED NOT NULL,
			page_id varchar(64) NOT NULL DEFAULT '',
			page_name varchar(255) NOT NULL DEFAULT '',
			username varchar(255) DEFAULT NULL,
			page_link text DEFAULT NULL,
			category varchar(255) DEFAULT NULL,
			about text DEFAULT NULL,
			fan_count bigint(20) UNSIGNED DEFAULT 0,
			page_access_token text DEFAULT NULL,
			events_access_token text DEFAULT NULL,
			events_token_set_at datetime DEFAULT NULL,
			events_token_expires_at datetime DEFAULT NULL,
			events_token_never_expires tinyint(1) NOT NULL DEFAULT 0,
			profile_image_url text DEFAULT NULL,
			cover_photo_url text DEFAULT NULL,
			website text DEFAULT NULL,
			status enum('active','expired','invalid') DEFAULT 'active',
			stats_refreshed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY page_id (page_id),
			KEY account_id (account_id),
			KEY status (status)
		) {$charset_collate};";

		self::run_dbdelta( $sql );

		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Create feeds table (feed + likebox content types).
	 *
	 * @since 6.7.9
	 * @param string $charset_collate Charset/collate clause.
	 * @return bool
	 */
	private static function create_feeds_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_facebook_feeds';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL DEFAULT '',
			page_id bigint(20) UNSIGNED DEFAULT NULL,
			content_type enum('feed','likebox') NOT NULL DEFAULT 'feed',
			feed_type varchar(50) NOT NULL DEFAULT 'timeline',
			source_id varchar(255) DEFAULT NULL,
			settings longtext NOT NULL,
			status enum('active','inactive','invalid') DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY page_id (page_id),
			KEY content_type (content_type),
			KEY feed_type (feed_type),
			KEY status (status)
		) {$charset_collate};";

		self::run_dbdelta( $sql );

		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Create cache table.
	 *
	 * @since 6.7.9
	 * @param string $charset_collate Charset/collate clause.
	 * @return bool
	 */
	private static function create_cache_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_facebook_cache';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			cache_key varchar(255) NOT NULL,
			cache_data longtext NOT NULL,
			cache_type enum('page','feed','api','live') DEFAULT 'api',
			page_id bigint(20) UNSIGNED DEFAULT NULL,
			feed_id bigint(20) UNSIGNED DEFAULT NULL,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY cache_key (cache_key),
			KEY expires_at (expires_at),
			KEY cache_type (cache_type),
			KEY page_id (page_id),
			KEY feed_id (feed_id)
		) {$charset_collate};";

		self::run_dbdelta( $sql );

		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Run dbDelta via shared helper when available.
	 *
	 * @since 6.7.9
	 * @param string $sql CREATE TABLE SQL.
	 * @return void
	 */
	private static function run_dbdelta( $sql ) {
		if ( function_exists( 'esf_dbdelta' ) ) {
			esf_dbdelta( $sql );
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql ); // phpcs:ignore WordPress.DB.SchemaChange.SchemaChange
	}

	/**
	 * Verify all tables exist.
	 *
	 * @since 6.7.9
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;

		foreach ( self::get_table_names() as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				return false;
			}
		}

		return true;
	}

	/**
	 * Drop all Facebook module tables.
	 *
	 * @since 6.7.9
	 * @return bool
	 */
	public static function drop_tables() {
		global $wpdb;

		foreach ( self::get_table_names() as $table ) {
			$table_escaped = esc_sql( $table );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table_escaped}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		delete_option( self::SCHEMA_OPTION );

		return true;
	}

	/**
	 * Truncate all Facebook plugin tables (tables remain).
	 *
	 * @since 6.7.9
	 * @return void
	 */
	public static function truncate_all_tables() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'esf_facebook_cache',
			$wpdb->prefix . 'esf_facebook_feeds',
			$wpdb->prefix . 'esf_facebook_pages',
			$wpdb->prefix . 'esf_facebook_accounts',
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

	/**
	 * Delete expired cache entries.
	 *
	 * @since 6.7.9
	 * @return int
	 */
	public static function clean_expired_cache() {
		global $wpdb;

		$table         = $wpdb->prefix . 'esf_facebook_cache';
		$table_escaped = esc_sql( $table );

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"DELETE FROM `{$table_escaped}` WHERE expires_at < NOW()" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return (int) $deleted;
	}

	/**
	 * Return module table names (with prefix).
	 *
	 * @since 6.7.9
	 * @return array<int,string>
	 */
	public static function get_table_names() {
		global $wpdb;

		return array(
			$wpdb->prefix . 'esf_facebook_accounts',
			$wpdb->prefix . 'esf_facebook_pages',
			$wpdb->prefix . 'esf_facebook_feeds',
			$wpdb->prefix . 'esf_facebook_cache',
		);
	}
}
