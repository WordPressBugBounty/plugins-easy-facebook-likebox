<?php
/**
 * Instagram Feed Repository
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/Models
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_Feed_Repository
 *
 * @since 6.8.0
 */
class ESF_Instagram_Feed_Repository {

	use ESF_Instagram_Singleton;

	/**
	 * Allowed feed types.
	 *
	 * @var string[]
	 */
	private static $allowed_feed_types = array( 'user_timeline', 'hashtag', 'tagged' );

	/**
	 * Allowed feed statuses for the `status` column.
	 *
	 * @var string[]
	 */
	private static $allowed_statuses = array( 'active', 'inactive' );

	/**
	 * Get feeds table name.
	 *
	 * @since 6.8.0
	 * @return string
	 */
	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'esf_instagram_feeds';
	}

	/**
	 * Default feed settings.
	 *
	 * @since 6.8.0
	 * @return array
	 */
	public static function get_default_settings() {
		$layout_dimensions = array(
			'feed_width'              => 0,
			'feed_width_tablet'       => 0,
			'feed_width_mobile'       => 0,
			'feed_height'             => 0,
			'feed_height_tablet'      => 0,
			'feed_height_mobile'      => 0,
			'media_max_height'        => 0,
			'media_max_height_tablet' => 0,
			'media_max_height_mobile' => 0,
		);

		return array(
			'layout' => array(
				'type' => 'grid',
				// Grid layout settings. Defaults match the legacy `mif_skin_*` defaults so feeds
				// upgraded from the customizer keep visual parity: 3-column desktop / 2-column tablet /
				// 1-column mobile, 8px gap (modern compact; legacy default was 30px, max 100).
				'grid' => array_merge(
					array(
					'columns'            => 3,
					'columns_tablet'     => 2,
					'columns_mobile'     => 1,
					'gap'                => 8,
					'tile_border_radius' => 8,
					'tile_shadow'        => true,
					'tile_shadow_color'  => 'rgba(0,0,0,0.12)',
					),
					$layout_dimensions
				),
				// Row: single horizontal band of square tiles; flush (0 gap) by default.
				'row'  => array_merge(
					array(
					'columns'            => 6,
					'columns_tablet'     => 3,
					'columns_mobile'     => 2,
					'gap'                => 0,
					'row_style'          => 'standard',
					'wave_offset'        => 16,
					'tile_border_radius' => 0,
					'tile_shadow'        => false,
					'tile_shadow_color'  => 'rgba(0,0,0,0.12)',
					),
					$layout_dimensions
				),
				// Half Width (Pro): split card — media left, caption/meta right.
				'half_width' => array_merge(
					array(
					'gap'                => 16,
					'card_border_radius' => 12,
					'card_shadow'        => true,
					'card_shadow_color'  => 'rgba(0,0,0,0.08)',
					'bg_color'           => '',
					'footer_bg_color'    => '',
					'text_color'         => '',
					'accent_color'       => '',
					'caption_words'      => 25,
					'show_post_header'   => true,
					'show_post_time'     => true,
					'show_share'         => true,
					'hover_show_likes'   => false,
					'hover_show_comments'=> false,
					),
					$layout_dimensions
				),
				// Full Width (Pro): stacked card — media on top, caption/meta below.
				'full_width' => array_merge(
					array(
					'gap'                => 16,
					'card_border_radius' => 12,
					'card_shadow'        => true,
					'card_shadow_color'  => 'rgba(0,0,0,0.08)',
					'bg_color'           => '',
					'footer_bg_color'    => '',
					'text_color'         => '',
					'accent_color'       => '',
					'caption_words'      => 25,
					'media_position'     => 'header_first',
					'media_aspect_ratio' => '16:9',
					'show_post_header'   => true,
					'show_post_time'     => true,
					'show_share'         => true,
					),
					$layout_dimensions
				),
				// Masonry (Pro): mixed-height tile wall.
				'masonry' => array_merge(
					array(
					'columns'            => 4,
					'columns_tablet'     => 3,
					'columns_mobile'     => 2,
					'gap'                => 8,
					'tile_border_radius' => 8,
					'tile_shadow'        => true,
					'tile_shadow_color'  => 'rgba(0,0,0,0.12)',
					'display_mode'       => 'media_only',
					'show_caption'       => true,
					'show_meta'          => true,
					'show_time'          => true,
					'caption_words'      => 18,
					'details_bg_color'   => '#ffffff',
					'details_text_color' => '#262626',
					'details_accent_color' => '#e1306c',
					'details_meta_color' => '#8e8e8e',
					),
					$layout_dimensions
				),
				'carousel' => array_merge(
					array(
					'columns'            => 4,
					'columns_tablet'     => 3,
					'columns_mobile'     => 1,
					'gap'                => 8,
					'tile_border_radius' => 8,
					'display_mode'       => 'media_only',
					'media_aspect_ratio' => '1:1',
					'show_caption'       => true,
					'show_meta'          => true,
					'show_time'          => true,
					'caption_words'      => 18,
					'details_bg_color'   => '#ffffff',
					'details_text_color' => '#262626',
					'details_accent_color' => '#e1306c',
					'details_meta_color' => '#8e8e8e',
					'autoplay'           => true,
					'loop'               => true,
					'autoplay_speed'     => 3000,
					'show_arrows'        => true,
					'show_dots'          => true,
					'nav_color'          => '#d6d6d6',
					'nav_active_color'   => '#869791',
					),
					$layout_dimensions
				),
			),
			'feed'   => array(
				'per_page'             => 9,
				/**
				 * Extra source accounts for Multifeed (addon / bundled). Primary
				 * `account_id` column remains the header / default account.
				 *
				 * @var int[]
				 */
				'account_ids'          => array(),
				'hashtag_media_type'   => 'top_media',
				'load_more'            => true,
				'load_more_bg_color'   => '',
				'load_more_text_color' => '',
				'load_more_hover_bg_color' => '',
				'load_more_hover_text_color' => '',
				'show_popup'           => true,
				'popup_show_header'    => true,
				'popup_show_caption'   => true,
				'popup_show_meta'      => true,
				'popup_show_view_insta' => true,
				'popup_show_comments_list' => true,
				'popup_comments_per_page'  => 20,
				'popup_show_gallery_nav'    => true,
				'popup_show_gallery_thumbs' => true,
				'popup_gallery_nav_bg_color' => '',
				'popup_gallery_nav_icon_color' => '',
				'links_new_tab'        => true,
				'show_media_type_icon' => true,
				'show_media_collage'   => true,
				'hover_overlay'        => true,
				'show_hover_plus'      => true,
				'hover_show_likes'     => true,
				'hover_show_comments'  => true,
				'hover_plus_color'     => '',
				'hover_plus_size'      => 42,
				'hover_stats_color'    => '',
				'hover_stats_size'     => 14,
			),
			'header' => array(
				'show'               => true,
				'show_avatar'        => true,
				'show_name'          => true,
				'show_handle'        => true,
				'show_bio'           => true,
				'show_followers'     => true,
				'show_media'         => true,
				'show_follow_button' => true,
				'show_link'          => true,
				'avatar_round'       => true,
				'header_rounded'     => true,
				'show_border'        => true,
				'border_color'       => '',
				'padding'            => 16,
				'bg_color'           => '',
				'text_color'         => '',
				'show_stories'       => true,
				'stories_ring'       => true,
				'stories_row'        => true,
			),
			'post'   => array(
				'show_caption'  => true,
				'show_likes'    => true,
				'show_comments' => true,
				'show_share'    => true,
			),
			'style'  => array(
				'custom_css' => '',
			),
		);
	}

	/**
	 * Get feed by ID.
	 *
	 * @since 6.8.0
	 * @param int $feed_id Feed ID.
	 * @return object|null
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
	 * @since 6.9.0
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
	 * @since 6.8.0
	 * @param array $args Optional filters.
	 * @return array
	 */
	public function get_all( $args = array() ) {
		global $wpdb;

		$table         = $this->get_table_name();
		$table_escaped = esc_sql( $table );
		$status        = isset( $args['status'] ) ? sanitize_text_field( (string) $args['status'] ) : '';
		$sql           = "SELECT * FROM `{$table_escaped}`";
		$prepare_args  = array();

		if ( in_array( $status, self::$allowed_statuses, true ) ) {
			$sql           .= ' WHERE status = %s';
			$prepare_args[] = $status;
		}

		$sql .= ' ORDER BY created_at DESC';
		if ( ! empty( $prepare_args ) ) {
			$sql = $wpdb->prepare( $sql, $prepare_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Create feed.
	 *
	 * @since 6.8.0
	 * @param array $data Feed data.
	 * @return int|false
	 */
	public function create( $data ) {
		global $wpdb;

		$name       = isset( $data['name'] ) ? trim( (string) $data['name'] ) : '';
		$account_id = isset( $data['account_id'] ) ? (int) $data['account_id'] : 0;
		if ( '' === $name || $account_id <= 0 ) {
			return false;
		}

		$feed_type = isset( $data['feed_type'] ) ? (string) $data['feed_type'] : 'user_timeline';
		if ( ! in_array( $feed_type, self::$allowed_feed_types, true ) ) {
			$feed_type = 'user_timeline';
		}

		$settings = isset( $data['settings'] ) && is_array( $data['settings'] )
			? array_replace_recursive( self::get_default_settings(), $data['settings'] )
			: self::get_default_settings();
		if ( function_exists( 'esf_instagram_normalize_feed_settings' ) ) {
			$settings = esf_instagram_normalize_feed_settings( $settings );
		}
		$json     = wp_json_encode( $settings );
		if ( false === $json ) {
			return false;
		}

		$table = $this->get_table_name();
		$now   = current_time( 'mysql', true );
		$ok    = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'name'       => $name,
				'account_id' => $account_id,
				'feed_type'  => $feed_type,
				'source_id'  => isset( $data['source_id'] ) ? sanitize_text_field( (string) $data['source_id'] ) : '',
				'settings'   => $json,
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => null,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false === $ok ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Update feed by ID.
	 *
	 * @since 6.8.0
	 * @param int   $feed_id Feed ID.
	 * @param array $data Data to update.
	 * @return bool
	 */
	public function update( $feed_id, $data ) {
		global $wpdb;

		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 || ! $this->get_by_id( $feed_id ) ) {
			return false;
		}

		$fields = array();
		$format = array();

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
			}
		}
		if ( array_key_exists( 'feed_type', $data ) ) {
			$type = sanitize_text_field( (string) $data['feed_type'] );
			if ( in_array( $type, self::$allowed_feed_types, true ) ) {
				$fields['feed_type'] = $type;
				$format[]            = '%s';
			}
		}
		if ( array_key_exists( 'source_id', $data ) ) {
			$fields['source_id'] = sanitize_text_field( (string) $data['source_id'] );
			$format[]            = '%s';
		}
		if ( array_key_exists( 'settings', $data ) && is_array( $data['settings'] ) ) {
			$settings = array_replace_recursive( self::get_default_settings(), $data['settings'] );
			if ( function_exists( 'esf_instagram_normalize_feed_settings' ) ) {
				$settings = esf_instagram_normalize_feed_settings( $settings );
			}
			$json = wp_json_encode( $settings );
			if ( false !== $json ) {
				$fields['settings'] = $json;
				$format[]           = '%s';
			}
		}
		if ( array_key_exists( 'status', $data ) ) {
			$status = sanitize_text_field( (string) $data['status'] );
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
		$updated = $wpdb->update( $table, $fields, array( 'id' => $feed_id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $updated;
	}

	/**
	 * Delete feed.
	 *
	 * @since 6.8.0
	 * @param int $feed_id Feed ID.
	 * @return bool
	 */
	public function delete( $feed_id ) {
		global $wpdb;

		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return false;
		}

		$feed = $this->get_by_id( $feed_id );
		if ( ! $feed ) {
			return false;
		}

		$is_hashtag_feed = function_exists( 'esf_instagram_feed_is_hashtag' ) && esf_instagram_feed_is_hashtag( $feed );
		$hashtag         = '';
		$media_type      = 'top_media';

		if ( $is_hashtag_feed ) {
			$hashtag = function_exists( 'esf_instagram_normalize_hashtag' )
				? esf_instagram_normalize_hashtag( isset( $feed->source_id ) ? (string) $feed->source_id : '' )
				: '';

			if ( function_exists( 'esf_instagram_feed_hashtag_media_type' ) ) {
				$media_type = esf_instagram_feed_hashtag_media_type( $feed );
			}
		}

		$table   = $this->get_table_name();
		$deleted = $wpdb->delete( $table, array( 'id' => $feed_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false !== $deleted && $deleted > 0 ) {
			if ( $is_hashtag_feed && '' !== $hashtag ) {
				$remaining_feeds = function_exists( 'esf_instagram_get_feeds_using_hashtag' )
					? esf_instagram_get_feeds_using_hashtag( $hashtag, $media_type )
					: array();

				if ( empty( $remaining_feeds ) && class_exists( 'ESF_Instagram_Local_Media' ) ) {
					ESF_Instagram_Local_Media::purge_hashtag_media( $hashtag, $media_type );
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * Duplicate feed.
	 *
	 * @since 6.8.0
	 * @param int $feed_id Feed ID.
	 * @return int|false
	 */
	public function duplicate( $feed_id ) {
		$feed = $this->get_by_id( $feed_id );
		if ( ! $feed ) {
			return false;
		}

		$settings = json_decode( (string) $feed->settings, true );
		if ( ! is_array( $settings ) ) {
			$settings = self::get_default_settings();
		}

		return $this->create(
			array(
				'name'       => sanitize_text_field( (string) $feed->name ) . ' ' . __( '(Copy)', 'easy-facebook-likebox' ),
				'account_id' => (int) $feed->account_id,
				'feed_type'  => sanitize_text_field( (string) $feed->feed_type ),
				'source_id'  => sanitize_text_field( (string) $feed->source_id ),
				'settings'   => $settings,
			)
		);
	}

	/**
	 * Count other hashtag feeds using the same tag and media-type edge.
	 *
	 * @since 6.9.0
	 *
	 * @param string $tag             Normalized hashtag without `#`.
	 * @param string $media_type      Hashtag edge ('top_media'|'recent_media').
	 * @param int    $exclude_feed_id Feed ID to exclude (usually the editor feed).
	 * @return int
	 */
	public function count_other_hashtag_feeds( $tag, $media_type = 'top_media', $exclude_feed_id = 0 ) {
		global $wpdb;

		$tag = function_exists( 'esf_instagram_normalize_hashtag' )
			? esf_instagram_normalize_hashtag( (string) $tag )
			: '';
		if ( '' === $tag ) {
			return 0;
		}

		$media_type = function_exists( 'esf_instagram_normalize_hashtag_media_type' )
			? esf_instagram_normalize_hashtag_media_type( $media_type )
			: 'top_media';
		$exclude_feed_id = (int) $exclude_feed_id;

		$table_escaped = esc_sql( $this->get_table_name() );
		$sql           = "SELECT id, settings FROM `{$table_escaped}` WHERE feed_type = %s AND source_id = %s";
		$prepare_args  = array( 'hashtag', $tag );
		if ( $exclude_feed_id > 0 ) {
			$sql            .= ' AND id != %d';
			$prepare_args[] = $exclude_feed_id;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare_args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) ) {
				continue;
			}

			$settings = array();
			if ( ! empty( $row->settings ) ) {
				$decoded = json_decode( (string) $row->settings, true );
				if ( is_array( $decoded ) ) {
					$settings = $decoded;
				}
			}

			$feed_cfg = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();
			$row_type = function_exists( 'esf_instagram_normalize_hashtag_media_type' )
				? esf_instagram_normalize_hashtag_media_type(
					isset( $feed_cfg['hashtag_media_type'] ) ? (string) $feed_cfg['hashtag_media_type'] : 'top_media'
				)
				: 'top_media';

			if ( $row_type === $media_type ) {
				++$count;
			}
		}

		return $count;
	}
}
