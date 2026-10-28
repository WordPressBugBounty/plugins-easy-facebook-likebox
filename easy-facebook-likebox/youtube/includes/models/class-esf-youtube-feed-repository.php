<?php
/**
 * YouTube Feed Repository.
 *
 * Encapsulates CRUD operations for YouTube feeds table.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/Models
 * @since 6.7.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_Feed_Repository
 *
 * @since 6.7.5
 */
class ESF_YouTube_Feed_Repository {

	use ESF_YouTube_Singleton;

	/**
	 * Allowed feed types.
	 *
	 * @since 6.7.5
	 * @var string[]
	 */
	private static $allowed_feed_types = array( 'channel', 'playlist', 'search' );

	/**
	 * Allowed status values.
	 *
	 * @since 6.7.5
	 * @var string[]
	 */
	private static $allowed_statuses = array( 'active', 'inactive' );

	/**
	 * Get table name for feeds.
	 *
	 * @since 6.7.5
	 * @return string
	 */
	private function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'esf_youtube_feeds';
	}

	/**
	 * Get default settings structure for a feed.
	 *
	 * Layout options are namespaced per type (e.g. layout.grid, layout.masonry) so that:
	 * - Each layout type keeps its own independent options.
	 * - Switching layouts preserves the previous layout's settings.
	 * - New layout types can be added by extending this array without touching existing feeds
	 *   (merge_settings_with_defaults() fills in new keys on next load).
	 *
	 * @since 6.7.5
	 * @return array Default settings array (will be JSON-encoded for storage).
	 */
	public static function get_default_settings() {
		return array(
			'feed'   => array(
				'per_page'                   => 12,
				'content_filter'             => 'all',
				/**
				 * Which videos to show by privacy status: public | all | unlisted | private.
				 * Only `public` is available without a YouTube plan.
				 */
				'video_visibility'           => 'public',
				/**
				 * Extra source accounts for Multifeed (Pro / addon). Primary
				 * `account_id` column remains the default / header account.
				 *
				 * @var int[]
				 */
				'account_ids'                => array(),
				'multifeed_sort'             => 'newest',
				'hover_overlay'              => true,
				'show_hover_play'            => true,
				'hover_play_color'           => '',
				'hover_play_icon_color'      => '',
				'hover_play_size'            => 52,
				'load_more'                  => true,
				'load_more_mode'             => 'button',
				'load_more_bg_color'         => '',
				'load_more_text_color'       => '',
				'load_more_hover_bg_color'   => '',
				'load_more_hover_text_color' => '',
			),
			'layout' => array(
				'type'       => 'grid',
				'grid'       => array_merge(
					function_exists( 'esf_layout_dimension_defaults' )
						? esf_layout_dimension_defaults()
						: array(),
					array(
						'columns'            => 3,
						'columns_tablet'     => 2,
						'columns_mobile'     => 1,
						'gap'                => 20,
						'media_aspect_ratio' => '16:9',
						'bg_color'           => '',
						'text_color'         => '',
						'meta_color'         => '',
						'accent_color'       => '',
					)
				),
				'row'        => array_merge(
					function_exists( 'esf_layout_dimension_defaults' )
						? esf_layout_dimension_defaults()
						: array(),
					array(
						'row_style'          => 'standard',
						'wave_offset'        => 16,
						'media_aspect_ratio' => '1:1',
						'columns'            => 4,
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
					'media_aspect_ratio'   => '16:9',
					'columns'              => 3,
					'columns_tablet'       => 2,
					'columns_mobile'       => 1,
					'gap'                  => 16,
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
					'details_text_color'   => '#0f0f0f',
					'details_accent_color' => '#ff0000',
					'details_meta_color'   => '#606060',
					'nav_color'            => '#d6d6d6',
					'nav_active_color'     => '#ff0000',
				),
				'half_width' => array_merge(
					function_exists( 'esf_layout_dimension_defaults' )
						? esf_layout_dimension_defaults()
						: array(),
					array(
						'gap'                 => 16,
						'card_border_radius'  => 12,
						'card_shadow'         => true,
						'card_shadow_color'   => 'rgba(0,0,0,0.08)',
						'media_aspect_ratio'  => '16:9',
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
						'details_text_color'   => '#0f0f0f',
						'details_meta_color'   => '#606060',
						'details_accent_color' => '#ff0000',
						'hover_show_likes'     => false,
						'hover_show_comments'  => false,
					)
				),
				'full_width' => array_merge(
					function_exists( 'esf_layout_dimension_defaults' )
						? esf_layout_dimension_defaults()
						: array(),
					array(
						'gap'                 => 16,
						'card_border_radius'  => 12,
						'card_shadow'         => true,
						'card_shadow_color'   => 'rgba(0,0,0,0.08)',
						'media_aspect_ratio'  => '16:9',
						'media_position'      => 'header_first',
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
				'show'                  => true,
				'show_logo'             => true,
				'show_name'             => true,
				'show_subscribers'      => true,
				'show_video_count'      => true,
				'show_channel_views'    => true,
				'show_description'      => true,
				'show_subscribe_button' => true,
				'show_banner'           => true,
				'show_border'           => true,
				'padding'               => 16,
				'bg_color'              => '',
				'text_color'            => '',
				'border_color'          => '',
			),
			'card'   => array(
				'show_thumbnail'   => true,
				'show_title'       => true,
				'show_description' => true,
				'show_date'        => true,
				'show_views'       => true,
				'show_duration'    => true,
			),
			'popup'    => array(
				'enabled'            => true,
				'show_description'   => true,
				'show_stats'         => true,
				'autoplay'           => true,
				'mute'               => false,
				'show_comments_list' => true,
				'comments_per_page'  => 20,
			),
			/**
			 * Video click behaviour. `auto` keeps legacy popup.enabled + plan
			 * derivation so existing feeds do not change behaviour.
			 *
			 * @since 6.18.0
			 */
			'playback' => array(
				'mode'     => 'auto',
				// Video Showcase player sizing (see esf_youtube_get_showcase_settings()).
				'showcase' => array(
					'aspect'      => 'auto',
					'max_width'   => 0,
					'align'       => 'center',
					'radius'      => -1,
					'now_playing' => true,
				),
			),
			'style'    => array(
				'custom_css' => '',
			),
		);
	}

	/**
	 * Merge user settings with defaults (recursive).
	 *
	 * Ensures new keys added in future do not break old feeds.
	 *
	 * @since 6.7.5
	 *
	 * @param array $settings User-provided settings (may be partial).
	 * @return array Merged settings, safe to encode as JSON.
	 */
	public static function merge_settings_with_defaults( $settings ) {
		$defaults = self::get_default_settings();
		if ( ! is_array( $settings ) ) {
			return $defaults;
		}

		// Migrate legacy header.show_stats before defaults fill the new keys.
		if ( isset( $settings['header'] ) && is_array( $settings['header'] ) ) {
			$header = $settings['header'];
			if ( array_key_exists( 'show_stats', $header ) ) {
				if ( ! array_key_exists( 'show_subscribers', $header ) ) {
					$settings['header']['show_subscribers'] = ! empty( $header['show_stats'] );
				}
				if ( ! array_key_exists( 'show_video_count', $header ) ) {
					$settings['header']['show_video_count'] = ! empty( $header['show_stats'] );
				}
			}
		}

		$merged = self::array_merge_recursive_distinct( $defaults, $settings );
		return self::sanitize_content_filter_setting( $merged );
	}

	/**
	 * Normalize content_filter and video_visibility; force "all" / "public"
	 * without a YouTube plan. Also normalizes multifeed account_ids.
	 *
	 * @since 6.7.6
	 *
	 * @param array $settings Merged settings.
	 * @return array
	 */
	public static function sanitize_content_filter_setting( $settings ) {
		if ( ! is_array( $settings ) ) {
			return self::get_default_settings();
		}

		if ( ! isset( $settings['feed'] ) || ! is_array( $settings['feed'] ) ) {
			$settings['feed'] = array();
		}

		$filter = function_exists( 'esf_youtube_normalize_content_filter' )
			? esf_youtube_normalize_content_filter(
				isset( $settings['feed']['content_filter'] ) ? $settings['feed']['content_filter'] : 'all'
			)
			: 'all';

		if (
			'all' !== $filter
			&& (
				! function_exists( 'esf_youtube_has_youtube_plan' )
				|| ! esf_youtube_has_youtube_plan()
			)
		) {
			$filter = 'all';
		}

		$settings['feed']['content_filter'] = $filter;

		// Video visibility: normalize; force "public" without a YouTube plan.
		$visibility = function_exists( 'esf_youtube_normalize_video_visibility' )
			? esf_youtube_normalize_video_visibility(
				isset( $settings['feed']['video_visibility'] ) ? $settings['feed']['video_visibility'] : 'public'
			)
			: 'public';

		if (
			'public' !== $visibility
			&& (
				! function_exists( 'esf_youtube_has_youtube_plan' )
				|| ! esf_youtube_has_youtube_plan()
			)
		) {
			$visibility = 'public';
		}

		$settings['feed']['video_visibility'] = $visibility;

		if ( isset( $settings['feed']['account_ids'] ) && is_array( $settings['feed']['account_ids'] ) ) {
			$ids = array();
			foreach ( $settings['feed']['account_ids'] as $id ) {
				$id = (int) $id;
				if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
			$settings['feed']['account_ids'] = $ids;
		}

		return $settings;
	}

	/**
	 * Recursive merge: defaults first, then user values (replacing scalar/array per key).
	 *
	 * @since 6.7.5
	 *
	 * @param array $defaults Default array.
	 * @param array $user     User array.
	 * @return array
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
	 * Get feed by ID.
	 *
	 * @since 6.7.5
	 *
	 * @param int $feed_id Feed ID.
	 * @return object|null Feed object or null if not found.
	 */
	public function get_by_id( $feed_id ) {
		global $wpdb;

		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return null;
		}

		$table = $this->get_table_name();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $table ) . '` WHERE id = %d LIMIT 1',
				$feed_id
			)
		);

		return $row;
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
	 * Get all feeds.
	 *
	 * @since 6.7.5
	 *
	 * @param array $args Optional. 'status', 'orderby', 'order', 'limit'.
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
	 * @since 6.7.5
	 *
	 * @param array $data Must include 'name', 'account_id'. Optional: 'feed_type', 'source_id', 'settings', 'status'.
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

		$feed_type = isset( $data['feed_type'] ) ? (string) $data['feed_type'] : 'channel';
		if ( ! in_array( $feed_type, self::$allowed_feed_types, true ) ) {
			$feed_type = 'channel';
		}

		$source_id = isset( $data['source_id'] ) ? sanitize_text_field( (string) $data['source_id'] ) : null;
		$settings  = isset( $data['settings'] ) && is_array( $data['settings'] )
			? self::merge_settings_with_defaults( $data['settings'] )
			: self::get_default_settings();

		// New feeds start on Video Showcase (free and Pro) unless the caller chose a
		// mode. Existing feeds keep `auto` (legacy popup/new-tab behaviour) because
		// this runs on create only, never in merge_settings_with_defaults().
		$explicit_mode = is_array( $data['settings'] ?? null ) && isset( $data['settings']['playback']['mode'] );
		$layout_type   = isset( $settings['layout']['type'] ) ? sanitize_key( (string) $settings['layout']['type'] ) : 'grid';
		if (
			! $explicit_mode
			&& function_exists( 'esf_youtube_layout_supports_showcase' )
			&& esf_youtube_layout_supports_showcase( $layout_type )
		) {
			$settings['playback']['mode'] = 'showcase';
			if ( ! isset( $settings['popup'] ) || ! is_array( $settings['popup'] ) ) {
				$settings['popup'] = array();
			}
			$settings['popup']['enabled'] = false;
		}

		// Seed Multifeed source list with the primary account (Instagram / Facebook parity).
		// $account_id is validated as > 0 above.
		if ( ! isset( $settings['feed'] ) || ! is_array( $settings['feed'] ) ) {
			$settings['feed'] = array();
		}
		$existing_ids = isset( $settings['feed']['account_ids'] ) && is_array( $settings['feed']['account_ids'] )
			? $settings['feed']['account_ids']
			: array();
		$normalized   = array();
		foreach ( $existing_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $normalized, true ) ) {
				$normalized[] = $id;
			}
		}
		if ( empty( $normalized ) ) {
			$settings['feed']['account_ids'] = array( $account_id );
		} elseif ( ! in_array( $account_id, $normalized, true ) ) {
			array_unshift( $normalized, $account_id );
			$settings['feed']['account_ids'] = $normalized;
		} else {
			$settings['feed']['account_ids'] = $normalized;
		}

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
	 * @since 6.7.5
	 *
	 * @param int   $feed_id Feed ID.
	 * @param array $data    Partial data: 'name', 'account_id', 'feed_type', 'source_id', 'settings', 'status'.
	 * @return bool True on success, false on failure.
	 */
	public function update( $feed_id, $data ) {
		global $wpdb;

		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return false;
		}

		$existing = $this->get_by_id( $feed_id );
		if ( ! $existing ) {
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
			$feed_type = (string) $data['feed_type'];
			if ( in_array( $feed_type, self::$allowed_feed_types, true ) ) {
				$fields['feed_type'] = $feed_type;
				$format[]            = '%s';
			}
		}

		if ( array_key_exists( 'source_id', $data ) ) {
			$fields['source_id'] = sanitize_text_field( (string) $data['source_id'] );
			$format[]            = '%s';
		}

		if ( array_key_exists( 'settings', $data ) && is_array( $data['settings'] ) ) {
			$settings      = self::merge_settings_with_defaults( $data['settings'] );
			$settings_json = wp_json_encode( $settings );
			if ( false !== $settings_json ) {
				$fields['settings'] = $settings_json;
				$format[]           = '%s';
			}
		}

		if ( array_key_exists( 'status', $data ) ) {
			$status = (string) $data['status'];
			if ( in_array( $status, self::$allowed_statuses, true ) ) {
				$fields['status'] = $status;
				$format[]         = '%s';
			}
		}

		if ( empty( $fields ) ) {
			return true;
		}

		$fields['updated_at'] = current_time( 'mysql', true );
		$format[]             = '%s';

		$table   = $this->get_table_name();
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			$fields,
			array( 'id' => $feed_id ),
			$format,
			array( '%d' )
		);

		if ( false !== $updated && $account_id_updated && class_exists( 'ESF_Feed_Disconnected_Cache' ) && class_exists( 'ESF_YouTube_Account_Repository' ) ) {
			$account = ESF_YouTube_Account_Repository::get_instance()->get_by_id( $new_account_id );
			if ( $account ) {
				ESF_Feed_Disconnected_Cache::handle_feed_source_reconnected(
					'youtube',
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
	 * @since 6.7.5
	 *
	 * @param int $feed_id Feed ID.
	 * @return bool True if deleted, false otherwise.
	 */
	public function delete( $feed_id ) {
		global $wpdb;

		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return false;
		}

		$table   = $this->get_table_name();
		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array( 'id' => $feed_id ),
			array( '%d' )
		);

		return ( false !== $deleted && $deleted > 0 );
	}

	/**
	 * Duplicate a feed by ID.
	 *
	 * Creates a new feed with the same settings; name is appended with " (Copy)".
	 *
	 * @since 6.7.5
	 *
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
		$name = '' !== $name ? $name . ' ' . __( '(Copy)', 'easy-facebook-likebox' ) : __( 'Feed (Copy)', 'easy-facebook-likebox' );

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
