<?php
/**
 * Twitter Feed Repository
 *
 * Encapsulates CRUD operations for the esf_twitter_feeds table.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/Models
 * @since 6.7.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Twitter_Feed_Repository
 *
 * @since 6.7.6
 */
class ESF_Twitter_Feed_Repository {

	use ESF_Twitter_Singleton;

	/**
	 * Allowed feed type values.
	 *
	 * @since 6.7.6
	 * @var string[]
	 */
	private static $allowed_feed_types = array( 'user_timeline' );

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

		return $wpdb->prefix . 'esf_twitter_feeds';
	}

	/**
	 * Get the default settings for a new feed.
	 *
	 * @since 6.7.6
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			'feed'   => array(
				'per_page'                    => 10,
				'tweet_count'                 => 20,
				'account_ids'                 => array(),
				'multifeed_sort'              => 'newest',
				'exclude_retweets'            => true,
				'exclude_replies'             => true,
				'enable_popup'                => true,
				'popup_show_author'           => true,
				'popup_show_date'             => true,
				'popup_show_follow'           => true,
				'popup_show_text'             => true,
				'popup_show_metrics'          => true,
				'popup_show_view_on_x'        => true,
				'popup_show_comments_list'    => true,
				'popup_comments_per_page'     => 20,
				'popup_comment_show_avatar'   => true,
				'popup_comment_show_username' => true,
				'popup_comment_show_date'     => true,
				'popup_comment_show_likes'    => true,
				'load_more'                   => true,
				'load_more_mode'              => 'button',
				'load_more_bg_color'          => '',
				'load_more_text_color'        => '',
				'load_more_hover_bg_color'    => '',
				'load_more_hover_text_color'  => '',
				'show_media_collage'          => false,
				'show_media_type_icon'        => true,
				'hover_overlay'               => true,
				'show_hover_plus'             => true,
				'hover_plus_size'             => 34,
				'hover_plus_color'            => '',
			),
			'layout' => array(
				'type'       => 'timeline',
				'timeline'   => array_merge(
					function_exists( 'esf_layout_dimension_defaults' )
						? esf_layout_dimension_defaults()
						: array(),
					array(
						'media_aspect_ratio' => '16:9',
						'bg_color'           => '',
						'text_color'         => '',
						'meta_color'         => '',
						'accent_color'       => '',
					)
				),
				'grid'       => array_merge(
					function_exists( 'esf_layout_dimension_defaults' )
						? esf_layout_dimension_defaults()
						: array(),
					array(
						'display_mode'         => 'media_only',
						'media_aspect_ratio'   => '1:1',
						'columns'              => 3,
						'columns_tablet'       => 2,
						'columns_mobile'       => 1,
						'gap'                  => 8,
						'tile_border_radius'   => 8,
						'tile_shadow'          => true,
						'tile_shadow_color'    => 'rgba(0,0,0,0.12)',
						'caption_words'        => 18,
						'show_caption'         => true,
						'show_meta'            => true,
						'show_time'            => true,
						'details_bg_color'     => '#ffffff',
						'details_text_color'   => '#0f1419',
						'details_accent_color' => '#1d9bf0',
						'details_meta_color'   => '#536471',
					)
				),
				// Row: single horizontal band; flush (0 gap) by default. Wave is Pro-only.
				'row'        => array_merge(
					function_exists( 'esf_layout_dimension_defaults' )
						? esf_layout_dimension_defaults()
						: array(),
					array(
						'row_style'          => 'standard',
						'wave_offset'        => 16,
						'media_aspect_ratio' => '1:1',
						'columns'            => 6,
						'columns_tablet'     => 3,
						'columns_mobile'     => 2,
						'gap'                => 0,
						'tile_border_radius' => 0,
						'tile_shadow'        => false,
						'tile_shadow_color'  => 'rgba(0,0,0,0.12)',
					)
				),
				'carousel'   => array(
					'display_mode'         => 'detailed',
					'media_aspect_ratio'   => '1:1',
					'columns'              => 3,
					'columns_tablet'       => 2,
					'columns_mobile'       => 1,
					'gap'                  => 8,
					'tile_border_radius'   => 8,
					'show_arrows'          => true,
					'show_dots'            => true,
					'loop'                 => true,
					'autoplay'             => true,
					'autoplay_speed'       => 3000,
					'caption_words'        => 18,
					'show_caption'         => true,
					'show_meta'            => true,
					'show_time'            => true,
					'details_bg_color'     => '#ffffff',
					'details_text_color'   => '#0f1419',
					'details_accent_color' => '#1d9bf0',
					'details_meta_color'   => '#536471',
					'nav_color'            => '#d6d6d6',
					'nav_active_color'     => '#1d9bf0',
				),
				'masonry'    => array_merge(
					function_exists( 'esf_layout_dimension_defaults' )
						? esf_layout_dimension_defaults()
						: array(),
					array(
						'display_mode'         => 'media_only',
						'columns'              => 4,
						'columns_tablet'       => 3,
						'columns_mobile'       => 2,
						'gap'                  => 8,
						'tile_border_radius'   => 8,
						'tile_shadow'          => true,
						'tile_shadow_color'    => 'rgba(0,0,0,0.12)',
						'caption_words'        => 18,
						'show_caption'         => true,
						'show_meta'            => true,
						'show_time'            => true,
						'details_bg_color'     => '#ffffff',
						'details_text_color'   => '#0f1419',
						'details_accent_color' => '#1d9bf0',
						'details_meta_color'   => '#536471',
					)
				),
				'half_width' => array_merge(
					function_exists( 'esf_layout_dimension_defaults' )
						? esf_layout_dimension_defaults()
						: array(),
					array(
						'media_aspect_ratio'  => '1:1',
						'gap'                 => 16,
						'card_border_radius'  => 12,
						'card_shadow'         => true,
						'card_shadow_color'   => 'rgba(0,0,0,0.08)',
						'bg_color'            => '',
						'footer_bg_color'     => '',
						'text_color'          => '',
						'accent_color'        => '',
						'caption_words'       => 25,
						'show_post_header'    => true,
						'show_post_time'      => true,
						'show_share'          => true,
						'hover_show_likes'    => false,
						'hover_show_comments' => false,
					)
				),
			),
			'header' => array(
				'show'               => true,
				'show_avatar'        => true,
				'show_name'          => true,
				'show_username'      => true,
				'show_followers'     => true,
				'show_following'     => true,
				'show_tweets'        => true,
				'show_bio'           => true,
				'show_follow_button' => true,
				'bg_color'           => '',
				'text_color'         => '',
				'border_color'       => '',
				'show_border'        => true,
				'padding'            => 20,
			),
			'card'   => array(
				'show_image'    => true,
				'show_text'     => true,
				'show_date'     => true,
				'show_likes'    => true,
				'show_retweets' => true,
				'show_replies'  => true,
				'show_quotes'   => true,
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

		// Normalize account_ids for multifeed.
		if ( isset( $merged['feed']['account_ids'] ) && is_array( $merged['feed']['account_ids'] ) ) {
			$ids = array();
			foreach ( $merged['feed']['account_ids'] as $id ) {
				$id = (int) $id;
				if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
			$merged['feed']['account_ids'] = $ids;
		}

		if ( function_exists( 'esf_twitter_normalize_multifeed_settings__premium_only' ) && function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
			$merged = esf_twitter_normalize_multifeed_settings__premium_only( $merged );
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
	 * Get feeds owned by an account.
	 *
	 * @since 6.14.1
	 * @param int $account_id Account ID.
	 * @return array<int, object>
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

		$feed_type = isset( $data['feed_type'] ) ? (string) $data['feed_type'] : 'user_timeline';
		if ( ! in_array( $feed_type, self::$allowed_feed_types, true ) ) {
			$feed_type = 'user_timeline';
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

		if ( false !== $updated && $account_id_updated && class_exists( 'ESF_Feed_Disconnected_Cache' ) && class_exists( 'ESF_Twitter_Account_Repository' ) ) {
			$account = ESF_Twitter_Account_Repository::get_instance()->get_by_id( $new_account_id );
			if ( $account ) {
				ESF_Feed_Disconnected_Cache::handle_feed_source_reconnected(
					'twitter',
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

		ESF_Twitter_Cache::flush_feed_cache( $feed_id );

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
