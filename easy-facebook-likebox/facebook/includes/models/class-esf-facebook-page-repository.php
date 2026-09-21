<?php
/**
 * Facebook Page Repository
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/Models
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Page_Repository
 *
 * @since 6.7.9
 */
class ESF_Facebook_Page_Repository {

	use ESF_Facebook_Singleton;

	/**
	 * Allowed statuses.
	 *
	 * @var string[]
	 */
	private static $allowed_statuses = array( 'active', 'expired', 'invalid' );

	/**
	 * When true, per-Page disconnect emails are skipped (account-level email sent instead).
	 *
	 * @var bool
	 */
	private static $suppress_disconnect_notify = false;

	/**
	 * When true, do not auto-delete empty parent accounts (account cascade in progress).
	 *
	 * @var bool
	 */
	private static $suppress_empty_account_cleanup = false;

	/**
	 * @param bool $suppress Whether to suppress per-Page disconnect emails.
	 * @return void
	 */
	public static function set_suppress_disconnect_notify( $suppress ) {
		self::$suppress_disconnect_notify = (bool) $suppress;
	}

	/**
	 * @return bool
	 */
	public static function is_disconnect_notify_suppressed() {
		return self::$suppress_disconnect_notify;
	}

	/**
	 * @param bool $suppress Whether to skip empty-account cleanup after page delete.
	 * @return void
	 */
	public static function set_suppress_empty_account_cleanup( $suppress ) {
		self::$suppress_empty_account_cleanup = (bool) $suppress;
	}

	/**
	 * @return bool
	 */
	public static function is_empty_account_cleanup_suppressed() {
		return self::$suppress_empty_account_cleanup;
	}

	/**
	 * Get table name.
	 *
	 * @since 6.7.9
	 * @return string
	 */
	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'esf_facebook_pages';
	}

	/**
	 * Get page by internal ID.
	 *
	 * @since 6.7.9
	 * @param int $id Internal page row id.
	 * @return object|null
	 */
	public function get_by_id( $id ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}

		$table = $this->get_table_name();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $table ) . '` WHERE id = %d LIMIT 1',
				$id
			)
		);
	}

	/**
	 * Get page by Facebook Page id.
	 *
	 * @since 6.7.9
	 * @param string $page_id Facebook Page id.
	 * @return object|null
	 */
	public function get_by_page_id( $page_id ) {
		global $wpdb;

		$page_id = trim( (string) $page_id );
		if ( '' === $page_id ) {
			return null;
		}

		$table = $this->get_table_name();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $table ) . '` WHERE page_id = %s LIMIT 1',
				$page_id
			)
		);
	}

	/**
	 * Resolve a page row by Facebook Page id, vanity username, or display name.
	 *
	 * Legacy shortcodes/widgets often store the Page username in fanpage_id
	 * instead of the numeric Graph id.
	 *
	 * @since 6.7.9
	 * @param string $page_key Raw identifier from legacy embed attributes.
	 * @return object|null
	 */
	public function find_by_page_key( $page_key ) {
		$page_key = trim( (string) $page_key );
		if ( '' === $page_key ) {
			return null;
		}

		$page = $this->get_by_page_id( $page_key );
		if ( $page ) {
			return $page;
		}

		foreach ( $this->get_all() as $candidate ) {
			if ( ! is_object( $candidate ) ) {
				continue;
			}

			$pid      = isset( $candidate->page_id ) ? (string) $candidate->page_id : '';
			$username = isset( $candidate->username ) ? (string) $candidate->username : '';
			$name     = isset( $candidate->page_name ) ? (string) $candidate->page_name : '';

			if ( '' !== $pid && strcasecmp( $pid, $page_key ) === 0 ) {
				return $candidate;
			}
			if ( '' !== $username && strcasecmp( $username, $page_key ) === 0 ) {
				return $candidate;
			}
			if ( '' !== $name && strcasecmp( $name, $page_key ) === 0 ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Pages belonging to an account.
	 *
	 * @since 6.7.9
	 * @param int $account_id Account id.
	 * @return array<int,object>
	 */
	public function get_by_account_id( $account_id ) {
		global $wpdb;

		$account_id = (int) $account_id;
		if ( $account_id <= 0 ) {
			return array();
		}

		$table = $this->get_table_name();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $table ) . '` WHERE account_id = %d ORDER BY page_name ASC',
				$account_id
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * All pages site-wide.
	 *
	 * @since 6.7.9
	 * @return array<int,object>
	 */
	public function get_all() {
		global $wpdb;

		$table = $this->get_table_name();
		$rows  = $wpdb->get_results( 'SELECT * FROM `' . esc_sql( $table ) . '` ORDER BY page_name ASC' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Pages whose dedicated Events token expires within the lead window (or already expired).
	 *
	 * Skips never-expires tokens and rows without a known `events_token_expires_at`.
	 *
	 * @since 6.7.9
	 * @param int $lead_seconds Seconds ahead of UTC now (default 7 days).
	 * @param int $limit        Max rows.
	 * @return array<int,object>
	 */
	public function get_pages_with_events_token_expiring( $lead_seconds = 604800, $limit = 50 ) {
		global $wpdb;

		$lead_seconds = max( 0, (int) $lead_seconds );
		$limit        = max( 1, min( 100, (int) $limit ) );
		$threshold    = gmdate( 'Y-m-d H:i:s', time() + $lead_seconds );
		$table        = $this->get_table_name();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $table ) . '`
				WHERE events_access_token IS NOT NULL
					AND events_access_token != \'\'
					AND ( events_token_never_expires IS NULL OR events_token_never_expires = 0 )
					AND events_token_expires_at IS NOT NULL
					AND events_token_expires_at != \'0000-00-00 00:00:00\'
					AND events_token_expires_at <= %s
				ORDER BY events_token_expires_at ASC
				LIMIT %d',
				$threshold,
				$limit
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Upsert by Facebook page_id.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $data Page data.
	 * @return int|false
	 */
	public function upsert( $data ) {
		global $wpdb;

		$page_id = isset( $data['page_id'] ) ? trim( (string) $data['page_id'] ) : '';
		if ( '' === $page_id ) {
			return false;
		}

		$existing = $this->get_by_page_id( $page_id );
		$table    = $this->get_table_name();
		$now      = current_time( 'mysql', true );

		$defaults = array(
			'account_id'                 => 0,
			'page_id'                    => '',
			'page_name'                  => '',
			'username'                   => null,
			'page_link'                  => null,
			'category'                   => null,
			'about'                      => null,
			'fan_count'                  => 0,
			'page_access_token'          => null,
			'events_access_token'        => null,
			'events_token_set_at'        => null,
			'events_token_expires_at'    => null,
			'events_token_never_expires' => 0,
			'profile_image_url'          => null,
			'cover_photo_url'            => null,
			'website'                    => null,
			'status'                     => 'active',
			'stats_refreshed_at'         => null,
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

		$fields['page_id']                    = $page_id;
		$fields['account_id']                 = (int) $fields['account_id'];
		$fields['fan_count']                  = max( 0, (int) $fields['fan_count'] );
		$fields['events_token_never_expires'] = ! empty( $fields['events_token_never_expires'] ) ? 1 : 0;
		$fields['updated_at']                 = $now;

		if ( function_exists( 'esf_db_safe_text' ) ) {
			foreach ( array( 'page_name', 'username', 'page_link', 'category', 'about', 'website', 'cover_photo_url' ) as $text_key ) {
				if ( isset( $fields[ $text_key ] ) && is_string( $fields[ $text_key ] ) ) {
					$fields[ $text_key ] = esf_db_safe_text( $fields[ $text_key ] );
				}
			}
		}

		$status           = isset( $fields['status'] ) ? sanitize_key( (string) $fields['status'] ) : 'active';
		$fields['status'] = in_array( $status, self::$allowed_statuses, true ) ? $status : 'active';

		$format = array(
			'%d', // account_id.
			'%s', // page_id.
			'%s', // page_name.
			'%s', // username.
			'%s', // page_link.
			'%s', // category.
			'%s', // about.
			'%d', // fan_count.
			'%s', // page_access_token.
			'%s', // events_access_token.
			'%s', // events_token_set_at.
			'%s', // events_token_expires_at.
			'%d', // events_token_never_expires.
			'%s', // profile_image_url.
			'%s', // cover_photo_url.
			'%s', // website.
			'%s', // status.
			'%s', // stats_refreshed_at.
			'%s', // updated_at.
		);

		if ( $existing ) {
			$updated = $wpdb->update( $table, $fields, array( 'id' => (int) $existing->id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row_id  = false === $updated ? false : (int) $existing->id;
		} else {
			$fields['created_at'] = $now;
			$format[]             = '%s';
			$inserted             = $wpdb->insert( $table, $fields, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row_id               = false === $inserted ? false : (int) $wpdb->insert_id;
		}

		return $row_id;
	}

	/**
	 * Update page status.
	 *
	 * @since 6.7.9
	 * @param int    $id     Internal id.
	 * @param string $status Status.
	 * @return bool
	 */
	public function update_status( $id, $status ) {
		global $wpdb;

		$id     = (int) $id;
		$status = sanitize_key( (string) $status );
		if ( $id <= 0 || ! in_array( $status, self::$allowed_statuses, true ) ) {
			return false;
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->get_table_name(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Delete page row and soft-mark feeds (cache rows are preserved).
	 *
	 * Does not hard-delete feeds (shortcodes/widgets must not fatal).
	 *
	 * @since 6.7.9
	 * @param int $id Internal page row id.
	 * @return bool
	 */
	public function delete_by_id( $id ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}

		$row = $this->get_by_id( $id );
		if ( ! $row ) {
			return false;
		}

		if ( class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
			ESF_Facebook_Feed_Repository::get_instance()->mark_invalid_for_page( $id );
		}

		if ( class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
			ESF_Feed_Disconnected_Cache::maybe_notify_facebook_page_disconnected( $row );
		}

		if ( class_exists( 'ESF_Facebook_Cache' ) ) {
			ESF_Facebook_Cache::save_page_profile_snapshot( $row );
		}

		$deleted = $wpdb->delete( $this->get_table_name(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$ok = false !== $deleted && $deleted > 0;
		if ( $ok ) {
			$account_id = isset( $row->account_id ) ? (int) $row->account_id : 0;
			self::maybe_delete_empty_parent_account( $account_id );
		}

		return $ok;
	}

	/**
	 * Remove the parent account shell when it has no Pages left.
	 *
	 * @since 6.7.9
	 * @param int $account_id Internal accounts.id.
	 * @return bool True when the account was deleted.
	 */
	public static function maybe_delete_empty_parent_account( $account_id ) {
		$account_id = (int) $account_id;
		if ( $account_id <= 0 || self::$suppress_empty_account_cleanup ) {
			return false;
		}
		if ( ! class_exists( 'ESF_Facebook_Account_Repository' ) ) {
			return false;
		}

		$remaining = self::get_instance()->get_by_account_id( $account_id );
		if ( ! empty( $remaining ) ) {
			return false;
		}

		return ESF_Facebook_Account_Repository::get_instance()->delete_empty_account( $account_id );
	}

	/**
	 * Public-safe page shape (no tokens).
	 *
	 * @since 6.7.9
	 * @param object $page Page row.
	 * @return array<string,mixed>
	 */
	public static function format_public( $page ) {
		if ( ! is_object( $page ) ) {
			return array();
		}

		$profile_image_url = isset( $page->profile_image_url ) ? (string) $page->profile_image_url : '';
		$cover_photo_url   = isset( $page->cover_photo_url ) ? (string) $page->cover_photo_url : '';

		if ( class_exists( 'ESF_Facebook_Local_Media' ) ) {
			if ( '' !== $profile_image_url ) {
				$resolved = ESF_Facebook_Local_Media::resolve_page_avatar_display_url( $page );
				if ( '' !== $resolved ) {
					$profile_image_url = $resolved;
				}
			}
			if ( '' !== $cover_photo_url ) {
				$resolved_cover = ESF_Facebook_Local_Media::resolve_page_cover_display_url( $page );
				if ( '' !== $resolved_cover ) {
					$cover_photo_url = $resolved_cover;
				}
			}
		}

		$events_expiry = function_exists( 'esf_facebook_page_events_token_expiry_public' )
			? esf_facebook_page_events_token_expiry_public( $page )
			: array(
				'expires_at'    => '',
				'never_expires' => false,
			);

		return array(
			'id'                         => isset( $page->id ) ? (int) $page->id : 0,
			'account_id'                 => isset( $page->account_id ) ? (int) $page->account_id : 0,
			'page_id'                    => isset( $page->page_id ) ? (string) $page->page_id : '',
			'page_name'                  => isset( $page->page_name ) ? (string) $page->page_name : '',
			'username'                   => isset( $page->username ) ? (string) $page->username : '',
			'page_link'                  => isset( $page->page_link ) ? (string) $page->page_link : '',
			'category'                   => isset( $page->category ) ? (string) $page->category : '',
			'about'                      => isset( $page->about ) ? (string) $page->about : '',
			'fan_count'                  => isset( $page->fan_count ) ? (int) $page->fan_count : 0,
			'profile_image_url'          => $profile_image_url,
			'cover_photo_url'            => $cover_photo_url,
			'website'                    => isset( $page->website ) ? (string) $page->website : '',
			'status'                     => isset( $page->status ) ? (string) $page->status : '',
			'stats_refreshed_at'         => isset( $page->stats_refreshed_at ) ? (string) $page->stats_refreshed_at : '',
			'created_at'                 => isset( $page->created_at ) ? (string) $page->created_at : '',
			'has_events_token'           => esf_facebook_page_has_events_token( $page ),
			'has_dedicated_events_token' => esf_facebook_page_has_dedicated_events_token( $page ),
			'events_token_expires_at'    => isset( $events_expiry['expires_at'] ) ? (string) $events_expiry['expires_at'] : '',
			'events_token_never_expires' => ! empty( $events_expiry['never_expires'] ),
		);
	}
}
