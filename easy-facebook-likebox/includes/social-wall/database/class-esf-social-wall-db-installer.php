<?php
/**
 * Social Wall database installer.
 *
 * Creates custom tables (not CPT) matching modern module feed storage.
 *
 * @package Easy_Social_Feed
 * @since   6.8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Social_Wall_DB_Installer
 *
 * @since 6.8.2
 */
class ESF_Social_Wall_DB_Installer {

	/**
	 * Option key for schema version.
	 *
	 * @since 6.8.2
	 * @var string
	 */
	const VERSION_OPTION = 'esf_social_wall_db_version';

	/**
	 * Current schema version.
	 *
	 * @since 6.8.2
	 * @var string
	 */
	const VERSION = '1.0.0';

	/**
	 * Create or update Social Wall tables.
	 *
	 * @since 6.8.2
	 * @return bool True when the feeds table exists.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$feeds_ok        = self::create_feeds_table( $charset_collate );

		if ( $feeds_ok ) {
			update_option( self::VERSION_OPTION, self::VERSION, false );
		}

		return $feeds_ok;
	}

	/**
	 * Maybe install/upgrade on admin init when version drifts.
	 *
	 * @since 6.8.2
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = (string) get_option( self::VERSION_OPTION, '' );
		if ( self::VERSION === $installed ) {
			return;
		}

		self::create_tables();
	}

	/**
	 * Create the walls (feeds) table.
	 *
	 * @since 6.8.2
	 * @param string $charset_collate DB charset/collation.
	 * @return bool
	 */
	private static function create_feeds_table( $charset_collate ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'esf_social_wall_feeds';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL DEFAULT '' COMMENT 'Wall display name',
			settings longtext NOT NULL COMMENT 'JSON wall settings (sources, layout, design)',
			status enum('active','inactive') DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
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
}
