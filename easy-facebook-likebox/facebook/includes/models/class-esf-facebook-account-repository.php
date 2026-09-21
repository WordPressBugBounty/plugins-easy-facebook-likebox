<?php
/**
 * Facebook Account Repository
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/Models
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Account_Repository
 *
 * @since 6.7.9
 */
class ESF_Facebook_Account_Repository {

	use ESF_Facebook_Singleton;

	/**
	 * Allowed statuses.
	 *
	 * @var string[]
	 */
	private static $allowed_statuses = array( 'active', 'expired', 'invalid' );

	/**
	 * Get table name.
	 *
	 * @since 6.7.9
	 * @return string
	 */
	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'esf_facebook_accounts';
	}

	/**
	 * Get account by internal ID.
	 *
	 * @since 6.7.9
	 * @param int $account_id Account ID.
	 * @return object|null
	 */
	public function get_by_id( $account_id ) {
		global $wpdb;

		$account_id = (int) $account_id;
		if ( $account_id <= 0 ) {
			return null;
		}

		$table = $this->get_table_name();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $table ) . '` WHERE id = %d LIMIT 1',
				$account_id
			)
		);
	}

	/**
	 * Find account by Facebook user id.
	 *
	 * @since 6.7.9
	 * @param string $facebook_user_id Facebook user ID.
	 * @return object|null
	 */
	public function get_by_facebook_user_id( $facebook_user_id ) {
		global $wpdb;

		$facebook_user_id = trim( (string) $facebook_user_id );
		if ( '' === $facebook_user_id ) {
			return null;
		}

		$table = $this->get_table_name();

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $table ) . '` WHERE facebook_user_id = %s LIMIT 1',
				$facebook_user_id
			)
		);
	}

	/**
	 * Upsert account by facebook_user_id.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $data Sanitized account data.
	 * @return int|false
	 */
	public function upsert( $data ) {
		global $wpdb;

		$facebook_user_id = isset( $data['facebook_user_id'] ) ? trim( (string) $data['facebook_user_id'] ) : '';
		if ( '' === $facebook_user_id ) {
			return false;
		}

		$existing = $this->get_by_facebook_user_id( $facebook_user_id );
		$table    = $this->get_table_name();
		$now      = current_time( 'mysql', true );

		$defaults = array(
			'user_id'             => get_current_user_id(),
			'facebook_user_id'    => '',
			'facebook_user_name'  => null,
			'facebook_user_token' => null,
			'auth_source'         => class_exists( 'ESF_Manual_Access_Token' )
				? ESF_Manual_Access_Token::AUTH_OAUTH
				: 'oauth',
			'token_expires_at'    => null,
			'status'              => 'active',
		);

		if ( $existing ) {
			$existing_data = get_object_vars( $existing );
			$fields        = $defaults;
			foreach ( array_keys( $defaults ) as $key ) {
				if ( array_key_exists( $key, $existing_data ) ) {
					$fields[ $key ] = $existing_data[ $key ];
				}
			}
			foreach ( $data as $key => $value ) {
				if ( array_key_exists( $key, $defaults ) ) {
					$fields[ $key ] = $value;
				}
			}
		} else {
			$fields = array_merge( $defaults, $data );
		}

		$fields['facebook_user_id'] = $facebook_user_id;
		$fields['updated_at']       = $now;

		if ( function_exists( 'esf_db_safe_text' ) && isset( $fields['facebook_user_name'] ) && is_string( $fields['facebook_user_name'] ) ) {
			$fields['facebook_user_name'] = esf_db_safe_text( $fields['facebook_user_name'] );
		}

		$status           = isset( $fields['status'] ) ? sanitize_key( (string) $fields['status'] ) : 'active';
		$fields['status'] = in_array( $status, self::$allowed_statuses, true ) ? $status : 'active';

		if ( class_exists( 'ESF_Manual_Access_Token' ) ) {
			$fields['auth_source'] = ESF_Manual_Access_Token::normalize_auth_source(
				isset( $fields['auth_source'] ) ? $fields['auth_source'] : ESF_Manual_Access_Token::AUTH_OAUTH
			);
		} else {
			$fields['auth_source'] = 'oauth';
		}

		$format = array(
			'%d', // user_id.
			'%s', // facebook_user_id.
			'%s', // facebook_user_name.
			'%s', // facebook_user_token.
			'%s', // auth_source.
			'%s', // token_expires_at.
			'%s', // status.
			'%s', // updated_at.
		);

		if ( $existing ) {
			$updated    = $wpdb->update( $table, $fields, array( 'id' => (int) $existing->id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$account_id = false === $updated ? false : (int) $existing->id;
		} else {
			$fields['created_at'] = $now;
			$format[]             = '%s';
			$inserted             = $wpdb->insert( $table, $fields, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$account_id           = false === $inserted ? false : (int) $wpdb->insert_id;
		}

		return $account_id;
	}

	/**
	 * Get all accounts for a WP user.
	 *
	 * @since 6.7.9
	 * @param int $user_id Optional user id.
	 * @return array<int,object>
	 */
	public function get_all_by_user( $user_id = 0 ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			$user_id = (int) get_current_user_id();
		}

		$table = $this->get_table_name();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $table ) . '` WHERE user_id = %d ORDER BY created_at DESC',
				$user_id
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get all accounts site-wide.
	 *
	 * @since 6.7.9
	 * @return array<int,object>
	 */
	public function get_all() {
		global $wpdb;

		$table = $this->get_table_name();
		$rows  = $wpdb->get_results( 'SELECT * FROM `' . esc_sql( $table ) . '` ORDER BY created_at DESC' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Accounts due for token refresh within the buffer window.
	 *
	 * @since 6.7.9
	 * @param int $buffer_secs Seconds before expiry to treat as due.
	 * @param int $limit       Max rows.
	 * @return array<int,object>
	 */
	public function get_accounts_due_for_token_refresh( $buffer_secs = 600, $limit = 50 ) {
		global $wpdb;

		$buffer_secs = max( 0, (int) $buffer_secs );
		$limit       = max( 1, min( 100, (int) $limit ) );
		$cutoff      = gmdate( 'Y-m-d H:i:s', time() + $buffer_secs );
		$table       = $this->get_table_name();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $table ) . '` WHERE status = %s AND auth_source = %s AND token_expires_at IS NOT NULL AND token_expires_at <= %s ORDER BY token_expires_at ASC LIMIT %d',
				'active',
				class_exists( 'ESF_Manual_Access_Token' ) ? ESF_Manual_Access_Token::AUTH_OAUTH : 'oauth',
				$cutoff,
				$limit
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Update account status.
	 *
	 * @since 6.7.9
	 * @param int    $account_id Account ID.
	 * @param string $status     Status.
	 * @return bool
	 */
	public function update_status( $account_id, $status ) {
		global $wpdb;

		$account_id = (int) $account_id;
		$status     = sanitize_key( (string) $status );
		if ( $account_id <= 0 || ! in_array( $status, self::$allowed_statuses, true ) ) {
			return false;
		}

		$table   = $this->get_table_name();
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $account_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Delete account and cascade pages; soft-mark feeds invalid.
	 *
	 * @since 6.7.9
	 * @param int $account_id Account ID.
	 * @return bool
	 */
	public function delete_by_id( $account_id ) {
		global $wpdb;

		$account_id = (int) $account_id;
		if ( $account_id <= 0 ) {
			return false;
		}

		$row = $this->get_by_id( $account_id );
		if ( $row ) {
			$this->delete_associated_data( $row );
		}

		$table   = $this->get_table_name();
		$deleted = $wpdb->delete( $table, array( 'id' => $account_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Cascade delete pages/cache; soft-invalidate feeds.
	 *
	 * @since 6.7.9
	 * @param object $row Account row.
	 * @return void
	 */
	private function delete_associated_data( $row ) {
		if ( ! is_object( $row ) || empty( $row->id ) ) {
			return;
		}

		$account_id = (int) $row->id;

		if ( class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
			ESF_Feed_Disconnected_Cache::maybe_notify_facebook_account_disconnected( $row );
		}

		if ( class_exists( 'ESF_Facebook_Page_Repository' ) ) {
			ESF_Facebook_Page_Repository::set_suppress_disconnect_notify( true );
			ESF_Facebook_Page_Repository::set_suppress_empty_account_cleanup( true );
			$page_repo = ESF_Facebook_Page_Repository::get_instance();
			$pages     = $page_repo->get_by_account_id( $account_id );
			foreach ( $pages as $page ) {
				if ( is_object( $page ) && ! empty( $page->id ) ) {
					$page_repo->delete_by_id( (int) $page->id );
				}
			}
			ESF_Facebook_Page_Repository::set_suppress_empty_account_cleanup( false );
			ESF_Facebook_Page_Repository::set_suppress_disconnect_notify( false );
		}
	}

	/**
	 * Delete an account that no longer has any Pages (no page cascade / disconnect email).
	 *
	 * Used after the last Page under a connection is removed so Accounts does not
	 * keep an empty "0 Pages" shell.
	 *
	 * @since 6.7.9
	 * @param int $account_id Account ID.
	 * @return bool
	 */
	public function delete_empty_account( $account_id ) {
		global $wpdb;

		$account_id = (int) $account_id;
		if ( $account_id <= 0 ) {
			return false;
		}

		if ( class_exists( 'ESF_Facebook_Page_Repository' ) ) {
			$pages = ESF_Facebook_Page_Repository::get_instance()->get_by_account_id( $account_id );
			if ( ! empty( $pages ) ) {
				return false;
			}
		}

		$table   = $this->get_table_name();
		$deleted = $wpdb->delete( $table, array( 'id' => $account_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return false !== $deleted && $deleted > 0;
	}
}
