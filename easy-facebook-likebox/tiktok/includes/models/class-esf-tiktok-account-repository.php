<?php
/**
 * TikTok Account Repository
 *
 * CRUD for connected TikTok user accounts (Login Kit OAuth).
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok/Models
 * @since 6.8.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_TikTok_Account_Repository
 *
 * @since 6.8.0
 */
class ESF_TikTok_Account_Repository {

	use ESF_TikTok_Singleton;

	/**
	 * Constructor.
	 *
	 * @since 6.8.0
	 */
	private function __construct() {}

	/**
	 * Accounts table name.
	 *
	 * @since 6.8.0
	 * @return string
	 */
	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'esf_tiktok_accounts';
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
	 * Find account by TikTok open_id.
	 *
	 * @since 6.8.0
	 * @param string $open_id TikTok open_id.
	 * @return object|null
	 */
	public function get_by_open_id( $open_id ) {
		global $wpdb;

		$open_id = trim( (string) $open_id );
		if ( '' === $open_id ) {
			return null;
		}

		$table = $this->get_table_name();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $table ) . '` WHERE open_id = %s LIMIT 1',
				$open_id
			)
		);
	}

	/**
	 * List all accounts (newest first).
	 *
	 * @since 6.8.0
	 * @param array<string,mixed> $args Optional filters (`status`).
	 * @return array<int,object>
	 */
	public function get_all( $args = array() ) {
		global $wpdb;

		$table         = $this->get_table_name();
		$table_escaped = esc_sql( $table );
		$status        = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';

		if ( '' !== $status ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM `{$table_escaped}` WHERE status = %s ORDER BY id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$status
				)
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT * FROM `{$table_escaped}` ORDER BY id DESC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count connected TikTok accounts.
	 *
	 * @since 6.8.0
	 * @return int
	 */
	public function get_total_connected_account_count() {
		global $wpdb;

		$table         = $this->get_table_name();
		$table_escaped = esc_sql( $table );

		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM `{$table_escaped}`" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return (int) $count;
	}

	/**
	 * Whether any account needs reconnect (free-tier OAuth allowlist).
	 *
	 * @since 6.8.0
	 * @return bool
	 */
	public function has_account_needing_reconnect() {
		$accounts = $this->get_all();
		foreach ( $accounts as $row ) {
			if ( function_exists( 'esf_tiktok_account_needs_reconnect' ) && esf_tiktok_account_needs_reconnect( $row ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Upsert from OAuth tokens: fetch user.info then upsert by open_id.
	 *
	 * Free tier: allows reconnect of an existing open_id; blocks a brand-new
	 * open_id when another account already exists.
	 *
	 * @since 6.8.0
	 * @param int         $wp_user_id          WordPress user ID.
	 * @param string      $access_token        Access token.
	 * @param string      $refresh_token       Refresh token.
	 * @param int         $expires_in          Access token TTL seconds.
	 * @param int         $refresh_expires_in  Refresh token TTL seconds.
	 * @param string      $scopes_granted      Comma-separated scopes from token response.
	 * @param string|null $open_id_hint        Optional open_id from bridge (validated against user.info).
	 * @param string      $auth_source        `oauth` or `manual`.
	 * @return int|false Account ID, or false on failure.
	 */
	public function upsert_from_tokens( $wp_user_id, $access_token, $refresh_token = '', $expires_in = 86400, $refresh_expires_in = 0, $scopes_granted = '', $open_id_hint = null, $auth_source = 'oauth' ) {
		$wp_user_id         = (int) $wp_user_id;
		$access_token       = (string) $access_token;
		$refresh_token      = (string) $refresh_token;
		$expires_in         = (int) $expires_in;
		$refresh_expires_in = (int) $refresh_expires_in;
		$scopes_granted     = (string) $scopes_granted;
		$auth_source        = class_exists( 'ESF_Manual_Access_Token' )
			? ESF_Manual_Access_Token::normalize_auth_source( $auth_source )
			: 'oauth';
		$is_manual          = class_exists( 'ESF_Manual_Access_Token' )
			&& ESF_Manual_Access_Token::AUTH_MANUAL === $auth_source;

		if ( $wp_user_id <= 0 || '' === $access_token ) {
			return false;
		}

		if ( $is_manual ) {
			$refresh_token      = '';
			$expires_in         = 0;
			$refresh_expires_in = 0;
		}

		$api_service = ESF_TikTok_API_Service::get_instance();
		$user_data   = $api_service->validate_token_and_fetch_user( $access_token, $scopes_granted );

		if ( is_wp_error( $user_data ) ) {
			esf_tiktok_log_error(
				'Failed to validate TikTok token.',
				array(
					'error'   => $user_data->get_error_message(),
					'user_id' => $wp_user_id,
				)
			);
			return false;
		}

		$open_id = isset( $user_data['open_id'] ) ? (string) $user_data['open_id'] : '';
		if ( '' === $open_id ) {
			return false;
		}

		if ( is_string( $open_id_hint ) && '' !== $open_id_hint && ! hash_equals( $open_id, $open_id_hint ) ) {
			esf_tiktok_log_error(
				'TikTok open_id mismatch between bridge and user.info.',
				array( 'user_id' => $wp_user_id )
			);
			return false;
		}

		$existing = $this->get_by_open_id( $open_id );
		$has_plan = function_exists( 'esf_tiktok_has_tiktok_plan' ) && esf_tiktok_has_tiktok_plan();
		if ( ! $has_plan && ! $existing && $this->get_total_connected_account_count() >= 1 ) {
			return false;
		}

		$token_expires = $is_manual || $expires_in <= 0
			? null
			: gmdate( 'Y-m-d H:i:s', time() + max( 60, $expires_in ) );
		$refresh_exp   = null;
		if ( ! $is_manual && $refresh_expires_in > 0 ) {
			$refresh_exp = gmdate( 'Y-m-d H:i:s', time() + $refresh_expires_in );
		}

		$avatar_url = isset( $user_data['avatar_url'] ) ? (string) $user_data['avatar_url'] : '';
		if ( '' !== $open_id && '' !== $avatar_url && function_exists( 'esf_serve_media_locally' ) ) {
			if ( function_exists( 'esf_delete_media' ) ) {
				esf_delete_media( 'tt_' . $open_id, 'tiktok' );
			}
			$local = esf_serve_media_locally( 'tt_' . $open_id, $avatar_url, 'tiktok' );
			if ( is_string( $local ) && '' !== $local ) {
				$avatar_url = $local;
			}
		}

		$now = current_time( 'mysql', true );

		$data = array(
			'user_id'                  => $wp_user_id,
			'account_type'             => 'user',
			'open_id'                  => $open_id,
			'union_id'                 => isset( $user_data['union_id'] ) ? (string) $user_data['union_id'] : null,
			'username'                 => isset( $user_data['username'] ) ? (string) $user_data['username'] : '',
			'display_name'             => isset( $user_data['display_name'] ) ? (string) $user_data['display_name'] : '',
			'avatar_url'               => '' !== $avatar_url ? $avatar_url : null,
			'bio_description'          => isset( $user_data['bio_description'] ) ? (string) $user_data['bio_description'] : null,
			'is_verified'              => ! empty( $user_data['is_verified'] ) ? 1 : 0,
			'profile_deep_link'        => isset( $user_data['profile_deep_link'] ) && '' !== $user_data['profile_deep_link']
				? (string) $user_data['profile_deep_link']
				: null,
			'follower_count'           => isset( $user_data['follower_count'] ) ? (int) $user_data['follower_count'] : 0,
			'following_count'          => isset( $user_data['following_count'] ) ? (int) $user_data['following_count'] : 0,
			'likes_count'              => isset( $user_data['likes_count'] ) ? (int) $user_data['likes_count'] : 0,
			'video_count'              => isset( $user_data['video_count'] ) ? (int) $user_data['video_count'] : 0,
			'access_token'             => $access_token,
			'auth_source'              => $auth_source,
			'token_expires_at'         => $token_expires,
			'refresh_token'            => '' !== $refresh_token ? $refresh_token : null,
			'refresh_token_expires_at' => $refresh_exp,
			'scopes_granted'           => '' !== $scopes_granted ? $scopes_granted : null,
			'account_data'             => wp_json_encode( isset( $user_data['raw'] ) ? $user_data['raw'] : $user_data ),
			'status'                   => 'active',
			'stats_refreshed_at'       => $now,
		);

		return $this->upsert( $data );
	}

	/**
	 * Upsert by open_id.
	 *
	 * @since 6.8.0
	 * @param array<string,mixed> $data Account fields.
	 * @return int|false Account id or false.
	 */
	public function upsert( $data ) {
		global $wpdb;

		$open_id = isset( $data['open_id'] ) ? trim( (string) $data['open_id'] ) : '';
		if ( '' === $open_id ) {
			return false;
		}

		$existing = $this->get_by_open_id( $open_id );
		$table    = $this->get_table_name();
		$now      = current_time( 'mysql', true );

		$defaults = array(
			'user_id'                  => get_current_user_id(),
			'account_type'             => 'user',
			'open_id'                  => '',
			'union_id'                 => null,
			'username'                 => '',
			'display_name'             => '',
			'avatar_url'               => null,
			'bio_description'          => null,
			'is_verified'              => 0,
			'profile_deep_link'        => null,
			'follower_count'           => 0,
			'following_count'          => 0,
			'likes_count'              => 0,
			'video_count'              => 0,
			'access_token'             => null,
			'auth_source'              => 'oauth',
			'token_expires_at'         => null,
			'refresh_token'            => null,
			'refresh_token_expires_at' => null,
			'scopes_granted'           => null,
			'account_data'             => null,
			'status'                   => 'active',
			'stats_refreshed_at'       => null,
		);

		// Existing rows: overlay only provided keys so a profile-only upsert
		// (token refresh) does not null access_token / token_expires_at.
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

		$fields['open_id']    = $open_id;
		$fields['updated_at'] = $now;

		$format = array(
			'%d', // user_id
			'%s', // account_type
			'%s', // open_id
			'%s', // union_id
			'%s', // username
			'%s', // display_name
			'%s', // avatar_url
			'%s', // bio_description
			'%d', // is_verified
			'%s', // profile_deep_link
			'%d', // follower_count
			'%d', // following_count
			'%d', // likes_count
			'%d', // video_count
			'%s', // access_token
			'%s', // token_expires_at
			'%s', // refresh_token
			'%s', // refresh_token_expires_at
			'%s', // scopes_granted
			'%s', // account_data
			'%s', // status
			'%s', // stats_refreshed_at
			'%s', // updated_at
		);

		$row = array(
			'user_id'                  => (int) $fields['user_id'],
			'account_type'             => (string) $fields['account_type'],
			'open_id'                  => (string) $fields['open_id'],
			'union_id'                 => $fields['union_id'],
			'username'                 => (string) $fields['username'],
			'display_name'             => (string) $fields['display_name'],
			'avatar_url'               => $fields['avatar_url'],
			'bio_description'          => $fields['bio_description'],
			'is_verified'              => (int) $fields['is_verified'],
			'profile_deep_link'        => $fields['profile_deep_link'],
			'follower_count'           => (int) $fields['follower_count'],
			'following_count'          => (int) $fields['following_count'],
			'likes_count'              => (int) $fields['likes_count'],
			'video_count'              => (int) $fields['video_count'],
			'access_token'             => $fields['access_token'],
			'token_expires_at'         => $fields['token_expires_at'],
			'refresh_token'            => $fields['refresh_token'],
			'refresh_token_expires_at' => $fields['refresh_token_expires_at'],
			'scopes_granted'           => $fields['scopes_granted'],
			'account_data'             => $fields['account_data'],
			'status'                   => (string) $fields['status'],
			'stats_refreshed_at'       => $fields['stats_refreshed_at'],
			'updated_at'               => $now,
		);

		if ( $existing ) {
			$updated    = $wpdb->update( $table, $row, array( 'id' => (int) $existing->id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$account_id = ( false === $updated ) ? false : (int) $existing->id;
		} else {
			$row['created_at'] = $now;
			$format[]          = '%s';

			$inserted = $wpdb->insert( $table, $row, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $inserted ) {
				return false;
			}

			$account_id = (int) $wpdb->insert_id;
		}

		return $account_id;
	}

	/**
	 * Update access/refresh tokens for an account.
	 *
	 * @since 6.8.0
	 * @param int    $account_id          Account ID.
	 * @param string $access_token        New access token.
	 * @param string $refresh_token       New refresh token (replacement when returned).
	 * @param int    $expires_in          Access TTL seconds.
	 * @param int    $refresh_expires_in  Refresh TTL seconds (0 = leave unchanged).
	 * @return bool
	 */
	public function update_tokens( $account_id, $access_token, $refresh_token, $expires_in = 86400, $refresh_expires_in = 0 ) {
		global $wpdb;

		$account_id = (int) $account_id;
		if ( $account_id <= 0 ) {
			return false;
		}

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + max( 60, (int) $expires_in ) );
		$fields     = array(
			'access_token'     => (string) $access_token,
			'refresh_token'    => (string) $refresh_token,
			'token_expires_at' => $expires_at,
			'status'           => 'active',
			'updated_at'       => current_time( 'mysql', true ),
		);
		$format     = array( '%s', '%s', '%s', '%s', '%s' );

		if ( (int) $refresh_expires_in > 0 ) {
			$fields['refresh_token_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + (int) $refresh_expires_in );
			$format[]                           = '%s';
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->get_table_name(),
			$fields,
			array( 'id' => $account_id ),
			$format,
			array( '%d' )
		);

		return ( false !== $updated );
	}

	/**
	 * Update account status.
	 *
	 * @since 6.8.0
	 * @param int    $account_id Account ID.
	 * @param string $status     active|expired|invalid.
	 * @return bool
	 */
	public function update_status( $account_id, $status ) {
		global $wpdb;

		$valid = array( 'active', 'expired', 'invalid' );
		if ( ! in_array( $status, $valid, true ) ) {
			return false;
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->get_table_name(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $account_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return ( false !== $updated );
	}

	/**
	 * Whether the access token needs refresh (expires within 5 minutes).
	 *
	 * @since 6.8.0
	 * @param int $account_id Account ID.
	 * @return bool
	 */
	public function needs_token_refresh( $account_id ) {
		$account = $this->get_by_id( $account_id );
		if ( ! $account || empty( $account->token_expires_at ) ) {
			return false;
		}

		$ts = strtotime( (string) $account->token_expires_at . ' UTC' );
		if ( false === $ts ) {
			return false;
		}

		return ( time() >= ( $ts - 300 ) );
	}

	/**
	 * Delete an account row (caller must enforce capability).
	 *
	 * Preserves cached feed posts and local media; see delete_associated_data().
	 *
	 * @since 6.8.0
	 * @param int $account_id Account ID.
	 * @return bool
	 */
	public function delete( $account_id ) {
		global $wpdb;

		$account_id = (int) $account_id;
		if ( $account_id <= 0 ) {
			return false;
		}

		$account = $this->get_by_id( $account_id );
		if ( $account ) {
			$this->delete_associated_data( $account );
		}

		$deleted = $wpdb->delete( $this->get_table_name(), array( 'id' => $account_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return ( false !== $deleted && $deleted > 0 );
	}

	/**
	 * Remove warm-cache transients tied to an account; preserve feeds and API cache.
	 *
	 * Feeds keep their rows (with the orphaned account_id) so the public frontend
	 * can continue serving cached posts until TTL expiry after disconnect.
	 *
	 * @since 6.7.9
	 *
	 * @param object $row Account row.
	 * @return void
	 */
	private function delete_associated_data( $row ) {
		if ( ! is_object( $row ) || empty( $row->id ) ) {
			return;
		}

		$account_id = (int) $row->id;

		try {
			if ( class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
				ESF_Feed_Disconnected_Cache::maybe_notify_tiktok_account_disconnected( $row );
			}

			if ( class_exists( 'ESF_TikTok_Cache' ) ) {
				ESF_TikTok_Cache::save_account_profile_snapshot( $row );
			}

			if ( class_exists( 'ESF_TikTok_Feed_Repository' ) ) {
				$feed_repo = ESF_TikTok_Feed_Repository::get_instance();
				foreach ( $feed_repo->get_by_account_id( $account_id ) as $feed ) {
					if ( ! is_object( $feed ) || empty( $feed->id ) ) {
						continue;
					}
					if ( class_exists( 'ESF_TikTok_Local_Media' ) ) {
						ESF_TikTok_Local_Media::clear_feed_warm_transient( (int) $feed->id, $account_id );
					}
				}
			}

			if ( class_exists( 'ESF_TikTok_Local_Media' ) ) {
				ESF_TikTok_Local_Media::purge_account_media( $account_id );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only disconnect cleanup trail.
				error_log( 'ESF TikTok account disconnect cleanup failed: ' . $e->getMessage() );
			}
		}
	}
}
