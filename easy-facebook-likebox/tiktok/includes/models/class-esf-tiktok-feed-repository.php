<?php
/**
 * TikTok Feed Repository
 *
 * Encapsulates CRUD operations for the esf_tiktok_feeds table.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok/Models
 * @since 6.7.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_TikTok_Feed_Repository
 *
 * @since 6.7.6
 */
class ESF_TikTok_Feed_Repository {

	use ESF_TikTok_Singleton;

	/**
	 * Allowed feed type values.
	 *
	 * @since 6.7.6
	 * @var string[]
	 */
	private static $allowed_feed_types = array( 'user_videos' );

	/**
	 * Allowed status values.
	 *
	 * @since 6.7.6
	 * @var string[]
	 */
	private static $allowed_statuses = array( 'active', 'inactive' );

	/**
	 * Get the feeds table name.
	 *
	 * @since 6.7.6
	 * @return string
	 */
	private function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'esf_tiktok_feeds';
	}

	/**
	 * Get the default settings for a new feed.
	 *
	 * @since 6.7.6
	 * @return array
	 */
	public static function get_default_settings() {
		$layout_dimensions = function_exists( 'esf_layout_dimension_defaults' )
			? esf_layout_dimension_defaults()
			: array();

		return array(
			'feed'   => array(
				'per_page'                     => 9,
				'links_new_tab'                => true,
				'show_media_type_icon'         => false,
				'show_duration'                => true,
				'show_media_collage'           => true,
				'hover_overlay'                => true,
				'show_hover_play'              => true,
				'hover_play_color'             => '',
				'hover_play_icon_color'        => '',
				'hover_play_size'              => 52,
				'show_hover_plus'              => false,
				'hover_plus_color'             => '',
				'hover_plus_size'              => 42,
				'load_more'                    => true,
				'load_more_mode'               => 'button',
				'load_more_bg_color'           => '',
				'load_more_text_color'         => '',
				'load_more_hover_bg_color'     => '',
				'load_more_hover_text_color'   => '',
				'show_popup'                   => true,
				'popup_show_header'            => false,
				'popup_show_caption'           => true,
				'popup_show_meta'              => true,
				'popup_show_view_tiktok'       => true,
				'popup_show_gallery_nav'       => true,
				'popup_show_gallery_thumbs'    => true,
				'popup_gallery_nav_bg_color'   => '',
				'popup_gallery_nav_icon_color' => '',
			),
			'layout' => array(
				'type' => 'grid',
				'grid' => array_merge(
					array(
						'columns'            => 3,
						'columns_tablet'     => 2,
						'columns_mobile'     => 1,
						'gap'                => 8,
						'tile_border_radius' => 8,
						'tile_shadow'        => true,
						'tile_shadow_color'  => 'rgba(0,0,0,0.12)',
						'media_aspect_ratio' => '1:1',
						'display_mode'       => 'detailed',
						'show_caption'       => true,
						'caption_words'      => 20,
					),
					$layout_dimensions
				),
				'row'  => array_merge(
					array(
						'row_style'          => 'standard',
						'wave_offset'        => 16,
						'columns'            => 5,
						'columns_tablet'     => 3,
						'columns_mobile'     => 2,
						'gap'                => 0,
						'tile_border_radius' => 0,
						'tile_shadow'        => false,
						'tile_shadow_color'  => 'rgba(0,0,0,0.12)',
						'media_aspect_ratio' => '1:1',
					),
					$layout_dimensions
				),
			),
			'header' => array(
				'show'               => true,
				'show_avatar'        => true,
				'show_name'          => true,
				'show_handle'        => true,
				'show_bio'           => true,
				'show_followers'     => true,
				'show_following'     => false,
				'show_videos'        => true,
				'show_link'          => true,
				'show_follow_button' => true,
				'avatar_round'       => true,
				'header_rounded'     => true,
				'show_border'        => true,
				'border_color'       => '',
				'padding'            => 16,
				'bg_color'           => '',
				'text_color'         => '',
			),
			'post'   => array(
				'show_caption'  => true,
				'show_time'     => true,
				'show_views'    => true,
				'show_likes'    => true,
				'show_comments' => true,
				'show_shares'   => true,
			),
			'style'  => array(
				'custom_css' => '',
			),
		);
	}

	/**
	 * Merge partial settings with defaults, recursively.
	 *
	 * Ensures future setting additions do not break existing feeds.
	 *
	 * @since 6.7.6
	 * @param array $settings Partial settings array (may be incomplete).
	 * @return array Fully-merged settings safe to JSON-encode for storage.
	 */
	public static function merge_settings_with_defaults( $settings ) {
		$defaults = self::get_default_settings();
		if ( ! is_array( $settings ) ) {
			return $defaults;
		}

		$merged = self::array_merge_recursive_distinct( $defaults, $settings );
		$merged = self::normalize_header_bio_setting( $merged, $settings );
		$merged = self::normalize_hover_play_settings( $merged, $settings );
		$merged = self::normalize_layout_dimension_settings( $merged );
		if ( function_exists( 'esf_tiktok_lock_free_hover_styles' ) ) {
			$merged = esf_tiktok_lock_free_hover_styles( $merged );
		}

		return $merged;
	}

	/**
	 * Map the legacy `header.show_description` key to Instagram's `show_bio`.
	 *
	 * Older TikTok feeds stored the Design toggle as `show_description` while
	 * PHP layouts read `show_bio`. Prefer an explicit `show_bio` when both exist.
	 *
	 * @since 6.9.0
	 * @param array $merged   Settings merged with defaults.
	 * @param array $incoming Original user settings (pre-merge).
	 * @return array
	 */
	private static function normalize_header_bio_setting( $merged, $incoming ) {
		if ( ! isset( $merged['header'] ) || ! is_array( $merged['header'] ) ) {
			return $merged;
		}

		$incoming_header = ( isset( $incoming['header'] ) && is_array( $incoming['header'] ) )
			? $incoming['header']
			: array();

		if ( ! array_key_exists( 'show_bio', $incoming_header ) && array_key_exists( 'show_description', $incoming_header ) ) {
			$merged['header']['show_bio'] = (bool) $incoming_header['show_description'];
		}

		unset( $merged['header']['show_description'] );

		return $merged;
	}

	/**
	 * Map legacy hover-plus keys onto YouTube-style hover-play settings.
	 *
	 * @since 6.16.0
	 * @param array $merged   Settings merged with defaults.
	 * @param array $incoming Original user settings (pre-merge).
	 * @return array
	 */
	private static function normalize_hover_play_settings( $merged, $incoming ) {
		if ( ! isset( $merged['feed'] ) || ! is_array( $merged['feed'] ) ) {
			return $merged;
		}

		$incoming_feed = ( isset( $incoming['feed'] ) && is_array( $incoming['feed'] ) )
			? $incoming['feed']
			: array();

		if ( ! array_key_exists( 'show_hover_play', $incoming_feed ) && array_key_exists( 'show_hover_plus', $incoming_feed ) ) {
			$merged['feed']['show_hover_play'] = ! empty( $incoming_feed['show_hover_plus'] );
		}

		if (
			! array_key_exists( 'hover_play_color', $incoming_feed )
			&& isset( $incoming_feed['hover_plus_color'] )
			&& is_string( $incoming_feed['hover_plus_color'] )
			&& '' !== trim( $incoming_feed['hover_plus_color'] )
		) {
			$merged['feed']['hover_play_color'] = trim( $incoming_feed['hover_plus_color'] );
		}

		if (
			! array_key_exists( 'hover_play_size', $incoming_feed )
			&& isset( $incoming_feed['hover_plus_size'] )
			&& is_numeric( $incoming_feed['hover_plus_size'] )
		) {
			$merged['feed']['hover_play_size'] = (int) $incoming_feed['hover_plus_size'];
		}

		return $merged;
	}

	/**
	 * Clamp optional feed/media size settings (Instagram / shared helper).
	 *
	 * @since 6.9.0
	 * @param array $merged Settings merged with defaults.
	 * @return array
	 */
	private static function normalize_layout_dimension_settings( $merged ) {
		if ( ! function_exists( 'esf_layout_normalize_dimension_scope' ) ) {
			return $merged;
		}

		if ( ! isset( $merged['layout'] ) || ! is_array( $merged['layout'] ) ) {
			return $merged;
		}

		foreach ( array( 'grid', 'carousel', 'full_width' ) as $layout_slug ) {
			if ( isset( $merged['layout'][ $layout_slug ] ) && is_array( $merged['layout'][ $layout_slug ] ) ) {
				$merged['layout'][ $layout_slug ] = esf_layout_normalize_dimension_scope(
					$merged['layout'][ $layout_slug ]
				);
			}
		}

		return $merged;
	}

	/**
	 * Recursive merge: defaults first, user values override.
	 *
	 * @since 6.7.6
	 * @param array $defaults Default values.
	 * @param array $user     User-provided values.
	 * @return array Merged result.
	 */
	private static function array_merge_recursive_distinct( $defaults, $user ) {
		$merged = $defaults;
		foreach ( $user as $key => $value ) {
			if ( is_array( $value ) && isset( $merged[ $key ] ) && is_array( $merged[ $key ] ) ) {
				$merged[ $key ] = self::array_merge_recursive_distinct( $merged[ $key ], $value );
			} else {
				$merged[ $key ] = $value;
			}
		}

		return $merged;
	}

	/**
	 * Get a feed by its internal ID.
	 *
	 * @since 6.7.6
	 * @param int $feed_id Feed ID.
	 * @return object|null Feed object or null.
	 */
	public function get_by_id( $feed_id ) {
		global $wpdb;

		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return null;
		}

		$table = $this->get_table_name();

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $table ) . '` WHERE id = %d LIMIT 1',
				$feed_id
			)
		);
	}

	/**
	 * Get feeds linked to an account.
	 *
	 * @since 6.7.9
	 *
	 * @param int $account_id Internal account id.
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
				'SELECT * FROM `' . esc_sql( $table ) . '` WHERE account_id = %d ORDER BY id ASC',
				$account_id
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get all feeds, with optional filtering.
	 *
	 * @since 6.7.6
	 * @param array $args Optional args: 'status', 'orderby', 'order', 'limit'.
	 * @return array List of feed objects.
	 */
	public function get_all( $args = array() ) {
		global $wpdb;

		$table         = $this->get_table_name();
		$table_escaped = esc_sql( $table );

		$status  = isset( $args['status'] ) ? $args['status'] : '';
		$orderby = isset( $args['orderby'] ) ? $args['orderby'] : 'created_at';
		$order   = isset( $args['order'] ) ? strtoupper( $args['order'] ) : 'DESC';
		$limit   = isset( $args['limit'] ) ? (int) $args['limit'] : 0;

		$allowed_orderby = array( 'id', 'name', 'account_id', 'feed_type', 'status', 'created_at', 'updated_at' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'created_at';
		}
		$orderby_escaped = esc_sql( $orderby );

		if ( 'ASC' !== $order && 'DESC' !== $order ) {
			$order = 'DESC';
		}

		$sql          = "SELECT * FROM `{$table_escaped}`";
		$prepare_args = array();

		if ( in_array( $status, self::$allowed_statuses, true ) ) {
			$sql           .= ' WHERE status = %s';
			$prepare_args[] = $status;
		}

		$sql .= ' ORDER BY `' . $orderby_escaped . '` ' . $order;

		if ( $limit > 0 ) {
			$sql           .= ' LIMIT %d';
			$prepare_args[] = $limit;
		}

		if ( ! empty( $prepare_args ) ) {
			$sql = $wpdb->prepare( $sql, $prepare_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$results = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Create a new feed.
	 *
	 * @since 6.7.6
	 * @param array $data Required: 'name', 'account_id'. Optional: 'feed_type', 'source_id', 'settings', 'status'.
	 * @return int|false Feed ID on success, false on failure.
	 */
	public function create( $data ) {
		global $wpdb;

		$name = isset( $data['name'] ) ? trim( (string) $data['name'] ) : '';
		if ( '' === $name ) {
			return false;
		}

		$account_id = isset( $data['account_id'] ) ? (int) $data['account_id'] : 0;
		if ( $account_id <= 0 ) {
			return false;
		}

		$feed_type = isset( $data['feed_type'] ) ? (string) $data['feed_type'] : 'user_videos';
		if ( ! in_array( $feed_type, self::$allowed_feed_types, true ) ) {
			$feed_type = 'user_videos';
		}

		$source_id = isset( $data['source_id'] ) ? sanitize_text_field( (string) $data['source_id'] ) : '';
		$settings  = isset( $data['settings'] ) && is_array( $data['settings'] )
			? self::merge_settings_with_defaults( $data['settings'] )
			: self::get_default_settings();

		$status = isset( $data['status'] ) ? (string) $data['status'] : 'active';
		if ( ! in_array( $status, self::$allowed_statuses, true ) ) {
			$status = 'active';
		}

		$settings_json = wp_json_encode( $settings );
		if ( false === $settings_json ) {
			return false;
		}

		$now   = current_time( 'mysql', true );
		$table = $this->get_table_name();

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'name'       => $name,
				'account_id' => $account_id,
				'feed_type'  => $feed_type,
				'source_id'  => $source_id,
				'settings'   => $settings_json,
				'status'     => $status,
				'created_at' => $now,
				'updated_at' => null,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing feed.
	 *
	 * Only fields present in $data are updated.
	 *
	 * @since 6.7.6
	 * @param int   $feed_id Feed ID.
	 * @param array $data    Partial data to update.
	 * @return bool
	 */
	public function update( $feed_id, $data ) {
		global $wpdb;

		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return false;
		}

		if ( ! $this->get_by_id( $feed_id ) ) {
			return false;
		}

		$fields             = array();
		$format             = array();
		$account_id_updated = false;
		$new_account_id     = 0;

		if ( array_key_exists( 'name', $data ) ) {
			$name = trim( (string) $data['name'] );
			if ( '' !== $name ) {
				$fields['name'] = $name;
				$format[]       = '%s';
			}
		}

		if ( array_key_exists( 'account_id', $data ) ) {
			$account_id = (int) $data['account_id'];
			if ( $account_id > 0 ) {
				$fields['account_id'] = $account_id;
				$format[]             = '%d';
				$account_id_updated   = true;
				$new_account_id       = $account_id;
			}
		}

		if ( array_key_exists( 'feed_type', $data ) ) {
			$ft = (string) $data['feed_type'];
			if ( in_array( $ft, self::$allowed_feed_types, true ) ) {
				$fields['feed_type'] = $ft;
				$format[]            = '%s';
			}
		}

		if ( array_key_exists( 'source_id', $data ) ) {
			$fields['source_id'] = sanitize_text_field( (string) $data['source_id'] );
			$format[]            = '%s';
		}

		if ( array_key_exists( 'settings', $data ) && is_array( $data['settings'] ) ) {
			$merged_json = wp_json_encode( self::merge_settings_with_defaults( $data['settings'] ) );
			if ( false !== $merged_json ) {
				$fields['settings'] = $merged_json;
				$format[]           = '%s';
			}
		}

		if ( array_key_exists( 'status', $data ) ) {
			$st = (string) $data['status'];
			if ( in_array( $st, self::$allowed_statuses, true ) ) {
				$fields['status'] = $st;
				$format[]         = '%s';
			}
		}

		if ( empty( $fields ) ) {
			return true; // Nothing to update.
		}

		$fields['updated_at'] = current_time( 'mysql', true );
		$format[]             = '%s';

		$table   = $this->get_table_name();
		$updated = $wpdb->update( $table, $fields, array( 'id' => $feed_id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false !== $updated && $account_id_updated && class_exists( 'ESF_Feed_Disconnected_Cache' ) && class_exists( 'ESF_TikTok_Account_Repository' ) ) {
			$account = ESF_TikTok_Account_Repository::get_instance()->get_by_id( $new_account_id );
			if ( $account ) {
				ESF_Feed_Disconnected_Cache::handle_feed_source_reconnected(
					'tiktok',
					$feed_id,
					$new_account_id
				);
			}
		}

		return ( false !== $updated );
	}

	/**
	 * Delete a feed by ID.
	 *
	 * Also flushes the feed's cache.
	 *
	 * @since 6.7.6
	 * @param int $feed_id Feed ID.
	 * @return bool
	 */
	public function delete( $feed_id ) {
		global $wpdb;

		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return false;
		}

		ESF_TikTok_Cache::flush_feed_cache( $feed_id );

		$table   = $this->get_table_name();
		$deleted = $wpdb->delete( $table, array( 'id' => $feed_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return ( false !== $deleted && $deleted > 0 );
	}

	/**
	 * Duplicate a feed (copy with " (Copy)" suffix on the name).
	 *
	 * @since 6.7.6
	 * @param int $feed_id Feed ID to duplicate.
	 * @return int|false New feed ID on success, false on failure.
	 */
	public function duplicate( $feed_id ) {
		$feed = $this->get_by_id( $feed_id );
		if ( ! $feed ) {
			return false;
		}

		$settings = json_decode( $feed->settings, true );
		if ( ! is_array( $settings ) ) {
			$settings = self::get_default_settings();
		}

		$name = trim( (string) $feed->name );
		$name = '' !== $name
			/* translators: appended to feed name when duplicating */
			? $name . ' ' . __( '(Copy)', 'easy-facebook-likebox' )
			: __( 'Feed (Copy)', 'easy-facebook-likebox' );

		return $this->create(
			array(
				'name'       => $name,
				'account_id' => (int) $feed->account_id,
				'feed_type'  => $feed->feed_type,
				'source_id'  => $feed->source_id,
				'settings'   => $settings,
				'status'     => $feed->status,
			)
		);
	}
}
