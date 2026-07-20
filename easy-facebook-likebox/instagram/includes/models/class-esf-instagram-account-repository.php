<?php
/**
 * Instagram Account Repository
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/Models
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_Account_Repository
 *
 * @since 6.8.0
 */
class ESF_Instagram_Account_Repository {

	use ESF_Instagram_Singleton;

	/**
	 * Get table name.
	 *
	 * @since 6.8.0
	 * @return string
	 */
	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'esf_instagram_accounts';
	}

	/**
	 * Get account by internal ID.
	 *
	 * @since 6.8.0
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
	 * Find account by instagram user id.
	 *
	 * @since 6.8.0
	 * @param string $instagram_user_id Instagram user ID.
	 * @return object|null
	 */
	public function get_by_instagram_user_id( $instagram_user_id ) {
		global $wpdb;

		$instagram_user_id = trim( (string) $instagram_user_id );
		if ( '' === $instagram_user_id ) {
			return null;
		}

		$table = $this->get_table_name();

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $table ) . '` WHERE instagram_user_id = %s LIMIT 1',
				$instagram_user_id
			)
		);
	}

	/**
	 * Upsert account by instagram user ID.
	 *
	 * @since 6.8.0
	 * @param array $data Sanitized account data.
	 * @return int|false
	 */
	public function upsert( $data ) {
		global $wpdb;

		$instagram_user_id = isset( $data['instagram_user_id'] ) ? trim( (string) $data['instagram_user_id'] ) : '';
		if ( '' === $instagram_user_id ) {
			return false;
		}

		$existing = $this->get_by_instagram_user_id( $instagram_user_id );
		$table    = $this->get_table_name();
		$now      = current_time( 'mysql', true );

		$defaults = array(
			'user_id'             => get_current_user_id(),
			'account_type'        => 'business',
			'auth_source'         => 'facebook_page',
			'instagram_user_id'   => '',
			'username'            => '',
			'display_name'        => '',
			'profile_image_url'   => '',
			'biography'           => '',
			'website'             => '',
			'followers_count'     => 0,
			'media_count'         => 0,
			'facebook_page_id'    => null,
			'facebook_page_name'  => null,
			'facebook_user_id'    => null,
			'facebook_user_token' => null,
			'page_access_token'   => null,
			'access_token'        => null,
			'token_expires_at'    => null,
			'account_data'        => null,
			'status'              => 'active',
			'stats_refreshed_at'  => null,
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

		$fields['instagram_user_id'] = $instagram_user_id;
		$fields['updated_at']        = $now;

		// Order must match $fields (wpdb maps formats to columns in array order).
		$format = array(
			'%d', // user_id.
			'%s', // account_type.
			'%s', // auth_source.
			'%s', // instagram_user_id.
			'%s', // username.
			'%s', // display_name.
			'%s', // profile_image_url.
			'%s', // biography.
			'%s', // website.
			'%d', // followers_count.
			'%d', // media_count.
			'%s', // facebook_page_id.
			'%s', // facebook_page_name.
			'%s', // facebook_user_id.
			'%s', // facebook_user_token.
			'%s', // page_access_token.
			'%s', // access_token.
			'%s', // token_expires_at.
			'%s', // account_data.
			'%s', // status.
			'%s', // stats_refreshed_at.
			'%s', // updated_at.
		);

		if ( $existing ) {
			$updated = $wpdb->update( $table, $fields, array( 'id' => (int) $existing->id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$account_id = false === $updated ? false : (int) $existing->id;
		} else {
			$fields['created_at'] = $now;
			$format[]             = '%s';
			$inserted             = $wpdb->insert( $table, $fields, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( false === $inserted ) {
				return false;
			}

			$account_id = (int) $wpdb->insert_id;
		}

		if ( false !== $account_id && $account_id > 0 ) {
			$this->maybe_localize_profile_image( $account_id, $fields );
		}

		return $account_id;
	}

	/**
	 * Automatically save the profile image locally when an account is created or updated.
	 *
	 * @since 6.9.0
	 *
	 * @param int                 $account_id Internal account id.
	 * @param array<string,mixed> $fields     Account fields data.
	 * @return void
	 */
	private function maybe_localize_profile_image( $account_id, $fields ) {
		if ( ! isset( $fields['profile_image_url'] ) || ! isset( $fields['instagram_user_id'] ) ) {
			return;
		}

		$profile_url       = trim( (string) $fields['profile_image_url'] );
		$instagram_user_id = trim( (string) $fields['instagram_user_id'] );

		if ( '' === $profile_url || '' === $instagram_user_id ) {
			return;
		}

		if ( ! class_exists( 'ESF_Instagram_Local_Media' ) ) {
			return;
		}

		$account_row = (object) array(
			'id'                 => $account_id,
			'instagram_user_id'  => $instagram_user_id,
			'profile_image_url'  => $profile_url,
		);

		ESF_Instagram_Local_Media::localize_avatar_from_row( $account_row );

		$localized_url = ESF_Instagram_Local_Media::resolve_avatar_display_url( $account_row, false );
		if ( '' === $localized_url || $localized_url === $profile_url ) {
			return;
		}

		global $wpdb;
		$table = $this->get_table_name();
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'profile_image_url' => esc_url_raw( $localized_url ),
			),
			array( 'id' => (int) $account_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get all accounts for a WP user.
	 *
	 * @since 6.8.0
	 * @param int $user_id Optional user id.
	 * @return array
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
	 * Get all Instagram accounts (site-wide, for users who can manage the module).
	 *
	 * @since 6.8.0
	 * @return array<int,object>
	 */
	public function get_all() {
		global $wpdb;

		$table = $this->get_table_name();
		$rows  = $wpdb->get_results( 'SELECT * FROM `' . esc_sql( $table ) . '` ORDER BY created_at DESC' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete account row by internal id (caller must enforce capability).
	 *
	 * @since 6.8.0
	 * @param int $account_id Internal account id.
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
	 * Delete account row if it belongs to the given WordPress user.
	 *
	 * @param int $account_id Internal account id.
	 * @param int $user_id    WordPress user id (owner).
	 * @return bool
	 */
	public function delete( $account_id, $user_id = 0 ) {
		global $wpdb;

		$account_id = (int) $account_id;
		$user_id    = (int) $user_id;
		if ( $account_id <= 0 ) {
			return false;
		}
		if ( $user_id <= 0 ) {
			$user_id = (int) get_current_user_id();
		}

		$row = $this->get_by_id( $account_id );
		if ( ! $row || (int) $row->user_id !== $user_id ) {
			return false;
		}

		$this->delete_associated_data( $row );

		$table = $this->get_table_name();
		$deleted = $wpdb->delete( $table, array( 'id' => $account_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Remove feeds, local media, and cache rows tied to an account.
	 *
	 * @since 6.9.0
	 *
	 * @param object $row Account row.
	 * @return void
	 */
	private function delete_associated_data( $row ) {
		if ( ! is_object( $row ) || empty( $row->id ) ) {
			return;
		}

		$account_id = (int) $row->id;

		$this->purge_legacy_cached_post_files( $account_id );

		if ( class_exists( 'ESF_Instagram_Feed_Repository' ) ) {
			$feed_repo = ESF_Instagram_Feed_Repository::get_instance();
			foreach ( $feed_repo->get_by_account_id( $account_id ) as $feed ) {
				if ( ! is_object( $feed ) || empty( $feed->id ) ) {
					continue;
				}

				$feed_id = (int) $feed->id;
				if ( class_exists( 'ESF_Instagram_Local_Media' ) ) {
					ESF_Instagram_Local_Media::clear_feed_warm_transient( $feed_id, $account_id );
				}
				$feed_repo->delete( $feed_id );
			}
		}

		if ( class_exists( 'ESF_Instagram_Local_Media' ) ) {
			ESF_Instagram_Local_Media::purge_account_media( $account_id );
		}

		if ( ! empty( $row->instagram_user_id ) && function_exists( 'esf_delete_media' ) ) {
			$avatar_key = 'ig_avatar_' . (string) $row->instagram_user_id;
			esf_delete_media( $avatar_key, 'instagram', $account_id );
			esf_delete_media( $avatar_key, 'instagram', 0 );
		}

		if ( class_exists( 'ESF_Instagram_Cache' ) ) {
			ESF_Instagram_Cache::flush_account_cache( $account_id );
		}
	}

	/**
	 * Delete timeline files saved before per-account upload folders existed.
	 *
	 * @since 6.9.0
	 *
	 * @param int $account_id Account ID.
	 * @return void
	 */
	private function purge_legacy_cached_post_files( int $account_id ): void {
		if ( $account_id <= 0 || ! class_exists( 'ESF_Instagram_Cache' ) || ! class_exists( 'ESF_Instagram_Renderer' ) || ! function_exists( 'esf_delete_media' ) ) {
			return;
		}

		$cached = ESF_Instagram_Cache::get( ESF_Instagram_Renderer::media_cache_key( $account_id ) );
		if ( ! is_array( $cached ) || empty( $cached['data'] ) || ! is_array( $cached['data'] ) ) {
			return;
		}

		foreach ( $cached['data'] as $node ) {
			if ( is_array( $node ) ) {
				$this->delete_legacy_node_media_files( $node );
			}
		}
	}

	/**
	 * @since 6.9.0
	 *
	 * @param array<string,mixed> $node Graph node.
	 * @return void
	 */
	private function delete_legacy_node_media_files( array $node ): void {
		if ( empty( $node['id'] ) ) {
			return;
		}

		$id = (string) $node['id'];
		esf_delete_media( 'ig_media_' . $id, 'instagram', 0 );
		esf_delete_media( 'ig_thumb_' . $id, 'instagram', 0 );

		if ( ! empty( $node['children']['data'] ) && is_array( $node['children']['data'] ) ) {
			foreach ( $node['children']['data'] as $child ) {
				if ( is_array( $child ) ) {
					$this->delete_legacy_node_media_files( $child );
				}
			}
		}
	}

	/**
	 * Set account status (active, expired, invalid).
	 *
	 * @since 6.8.0
	 * @param int    $account_id Account id.
	 * @param string $status     Status enum value.
	 * @return bool
	 */
	public function update_status( $account_id, $status ) {
		global $wpdb;

		$account_id = (int) $account_id;
		if ( $account_id <= 0 ) {
			return false;
		}

		$valid = array( 'active', 'expired', 'invalid' );
		if ( ! in_array( $status, $valid, true ) ) {
			return false;
		}

		$table = $this->get_table_name();
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
	 * Accounts due for token refresh (expiry within buffer, active, required tokens present).
	 *
	 * @since 6.8.0
	 * @param int $buffer_seconds Seconds from now; rows with token_expires_at at or before this threshold qualify.
	 * @param int $limit          Max rows.
	 * @return array<int,object>
	 */
	public function get_accounts_due_for_token_refresh( $buffer_seconds = 600, $limit = 50 ) {
		global $wpdb;

		$buffer_seconds = max( 0, (int) $buffer_seconds );
		$limit          = max( 1, min( 200, (int) $limit ) );
		$threshold      = gmdate( 'Y-m-d H:i:s', time() + $buffer_seconds );
		$table          = $this->get_table_name();
		$table_sql      = esc_sql( $table );

		$sql = "SELECT * FROM `{$table_sql}` WHERE status = %s
			AND token_expires_at IS NOT NULL
			AND token_expires_at <= %s
			AND (
				(auth_source = %s AND access_token IS NOT NULL AND access_token != '')
				OR (auth_source = %s AND facebook_user_token IS NOT NULL AND facebook_user_token != '')
			)
			ORDER BY token_expires_at ASC
			LIMIT %d";

		$prepared = $wpdb->prepare(
			$sql,
			'active',
			$threshold,
			'instagram_login',
			'facebook_page',
			$limit
		);

		$rows = $wpdb->get_results( $prepared ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}
}
