<?php
/**
 * Facebook Feed Repository
 *
 * Graph feed rows only (`content_type` is always `feed`; Like Box uses settings.likebox for append).
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/Models
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Feed_Repository
 *
 * @since 6.7.9
 */
class ESF_Facebook_Feed_Repository {

	use ESF_Facebook_Singleton;

	/**
	 * Allowed content types.
	 *
	 * @var string[]
	 */
	private static $allowed_content_types = array( 'feed' );

	/**
	 * Allowed feed types (meaningful when content_type=feed).
	 *
	 * @var string[]
	 */
	private static $allowed_feed_types = array( 'timeline', 'images', 'videos', 'events', 'albums', 'mentioned' );

	/**
	 * Allowed statuses.
	 *
	 * @var string[]
	 */
	private static $allowed_statuses = array( 'active', 'inactive', 'invalid' );

	/**
	 * Get feeds table name.
	 *
	 * @since 6.7.9
	 * @return string
	 */
	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'esf_facebook_feeds';
	}

	/**
	 * Default feed / likebox settings.
	 *
	 * @since 6.7.9
	 * @return array<string,mixed>
	 */
	public static function get_default_settings() {
		return array(
			'layout'  => array(
				'type'       => 'half_width',
				'half'       => array(
					'gap'                => 16,
					'card_border_radius' => 12,
					'card_shadow'        => true,
					'caption_words'      => 25,
				),
				'full'       => array(
					'gap'                => 16,
					'card_border_radius' => 12,
					'caption_words'      => 25,
				),
				'half_width' => array(
					'gap'                   => 16,
					'card_border_radius'    => 12,
					'card_shadow'           => true,
					'card_shadow_color'     => 'rgba(0,0,0,0.08)',
					'bg_color'              => '#ffffff',
					'footer_bg_color'       => '#f0f2f5',
					'text_color'            => '#1c1e21',
					'accent_color'          => '#1877f2',
					'caption_words'         => 25,
					'show_post_header'      => true,
					'show_post_time'        => true,
					'show_share'            => true,
					'show_view_on_facebook' => true,
					'media_aspect_ratio'    => '1:1',
					'hover_show_likes'      => false,
					'hover_show_comments'   => false,
				),
				'full_width' => array(
					'gap'                   => 16,
					'card_border_radius'    => 12,
					'card_shadow'           => true,
					'card_shadow_color'     => 'rgba(0,0,0,0.08)',
					'bg_color'              => '#ffffff',
					'footer_bg_color'       => '#f3f6f8',
					'text_color'            => '#1c1e21',
					'accent_color'          => '#1877f2',
					'media_aspect_ratio'    => 'auto',
					'media_position'        => 'header_first',
					'caption_words'         => 25,
					'show_post_header'      => true,
					'show_post_time'        => true,
					'show_share'            => true,
					'show_view_on_facebook' => true,
				),
				'thumbnail'  => array(
					'gap'                   => 16,
					'card_border_radius'    => 12,
					'card_shadow'           => true,
					'card_shadow_color'     => 'rgba(0,0,0,0.08)',
					'bg_color'              => '#ffffff',
					'footer_bg_color'       => '#f0f2f5',
					'text_color'            => '#1c1e21',
					'accent_color'          => '#1877f2',
					'caption_words'         => 25,
					'show_post_header'      => true,
					'show_post_time'        => true,
					'show_share'            => true,
					'show_view_on_facebook' => true,
					'media_aspect_ratio'    => '1:1',
					'hover_show_likes'      => false,
					'hover_show_comments'   => false,
				),
				'row'        => array(
					'columns'            => 6,
					'columns_tablet'     => 3,
					'columns_mobile'     => 2,
					'gap'                => 0,
					'row_style'          => 'standard',
					'wave_offset'        => 16,
					'tile_border_radius' => 0,
					'tile_shadow'        => false,
					'tile_shadow_color'  => 'rgba(0,0,0,0.12)',
					'media_aspect_ratio' => '1:1',
					'caption_words'      => 25,
				),
				'grid'       => array(
					'columns'              => 3,
					'columns_tablet'       => 2,
					'columns_mobile'       => 1,
					'gap'                  => 8,
					'tile_border_radius'   => 8,
					'tile_shadow'          => true,
					'tile_shadow_color'    => 'rgba(0,0,0,0.12)',
					'media_aspect_ratio'   => '1:1',
					'display_mode'         => 'media_only',
					'caption_words'        => 25,
					'show_caption'         => true,
					'show_meta'            => true,
					'show_time'            => true,
					'details_bg_color'     => '#ffffff',
					'details_text_color'   => '#1c1e21',
					'details_accent_color' => '#1877f2',
					'details_meta_color'   => '#65676b',
				),
				'masonry'    => array(
					'columns'              => 3,
					'columns_tablet'       => 3,
					'columns_mobile'       => 2,
					'gap'                  => 8,
					'tile_border_radius'   => 8,
					'tile_shadow'          => true,
					'tile_shadow_color'    => 'rgba(0,0,0,0.12)',
					'display_mode'         => 'detailed',
					'caption_words'        => 25,
					'text_tile_lines'      => 6,
					'show_caption'         => true,
					'show_meta'            => true,
					'show_time'            => true,
					'show_post_header'     => true,
					'show_share'           => true,
					'media_position'       => 'header_first',
					'details_bg_color'     => '#ffffff',
					'details_text_color'   => '#1c1e21',
					'details_accent_color' => '#1877f2',
					'details_meta_color'   => '#65676b',
				),
				'carousel'   => array(
					'columns'              => 3,
					'columns_tablet'       => 3,
					'columns_mobile'       => 1,
					'gap'                  => 8,
					'tile_border_radius'   => 8,
					'tile_shadow'          => true,
					'tile_shadow_color'    => 'rgba(0,0,0,0.12)',
					'display_mode'         => 'detailed',
					'media_aspect_ratio'   => '1:1',
					'show_arrows'          => true,
					'show_dots'            => true,
					'loop'                 => true,
					'autoplay'             => true,
					'autoplay_speed'       => 3000,
					'caption_words'        => 18,
					'show_caption'         => true,
					'show_meta'            => true,
					'show_time'            => true,
					'show_post_header'     => true,
					'show_share'           => true,
					'media_position'       => 'header_first',
					'details_bg_color'     => '#ffffff',
					'details_text_color'   => '#1c1e21',
					'details_accent_color' => '#1877f2',
					'details_meta_color'   => '#65676b',
					'nav_color'            => '#d6d6d6',
					'nav_active_color'     => '#1877f2',
				),
			),
			'feed'    => array(
				'per_page'                         => 9,
				'page_ids'                         => array(),
				'multifeed_sort'                   => 'newest',
				'load_more'                        => true,
				'load_more_mode'                   => 'button',
				'show_popup'                       => true,
				'popup_show_header'                => true,
				'popup_show_caption'               => true,
				'popup_show_meta'                  => true,
				'popup_show_view_facebook'         => true,
				'popup_show_comments'              => true,
				'popup_show_comments_list'         => true,
				'popup_comments_per_page'          => 20,
				'popup_show_album_load_more'       => true,
				'popup_show_gallery_nav'           => true,
				'popup_show_gallery_thumbs'        => true,
				'popup_gallery_nav_bg_color'       => '',
				'popup_gallery_nav_icon_color'     => '',
				'links_new_tab'                    => true,
				'live_display'                     => 'posts',
				'live_stream_only'                 => false,
				'filter'                           => '',
				'events_filter'                    => '',
				'album_id'                         => '',
				'show_like_box'                    => false,
				'show_media_collage'               => true,
				'hover_overlay'                    => true,
				'show_hover_plus'                  => true,
				'hover_show_likes'                 => false,
				'hover_show_comments'              => false,
				'hover_plus_color'                 => '',
				'hover_plus_size'                  => 42,
				'hover_stats_color'                => '',
				'hover_stats_size'                 => 14,
				'show_media_type_icon'             => true,
				'show_view_all_events'             => true,
				'view_all_events_label'            => '',
				'view_all_events_bg_color'         => '',
				'view_all_events_text_color'       => '',
				'view_all_events_hover_bg_color'   => '',
				'view_all_events_hover_text_color' => '',
			),
			'header'  => array(
				'show'               => true,
				'show_avatar'        => true,
				'show_name'          => true,
				'show_handle'        => false,
				'show_followers'     => true,
				'show_category'      => true,
				'show_bio'           => true,
				'show_follow_button' => true,
				// Pro defaults on; Free clamps via esf_facebook_strip_pro_settings().
				'show_link'          => true,
				'show_banner'        => true,
				'avatar_round'       => true,
				'header_rounded'     => true,
				'show_border'        => true,
				'padding'            => 16,
				'bg_color'           => '',
				'text_color'         => '',
				'border_color'       => '',
			),
			'post'    => array(
				'show_caption'  => true,
				'show_likes'    => true,
				'show_comments' => true,
				'show_shares'   => true,
			),
			'likebox' => array(
				'width'        => 340,
				'height'       => 214,
				'show_faces'   => true,
				'hide_cover'   => false,
				'small_header' => false,
				'hide_cta'     => false,
				'show_posts'   => false,
				'locale'       => 'en_US',
				'tabs'         => '',
				'responsive'   => true,
				'page_url'     => '',
				'app_id'       => '',
			),
			'style'   => array(
				'custom_css' => '',
			),
		);
	}

	/**
	 * Normalize settings JSON with defaults and free-plan clamp.
	 *
	 * @since 6.7.9
	 * @param array|string|null $settings Raw settings.
	 * @return array<string,mixed>
	 */
	public static function normalize_settings( $settings ) {
		if ( is_string( $settings ) ) {
			$decoded  = json_decode( $settings, true );
			$settings = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$incoming_feed = isset( $settings['feed'] ) && is_array( $settings['feed'] )
			? $settings['feed']
			: array();

		$settings = array_replace_recursive( self::get_default_settings(), $settings );

		if ( ! isset( $settings['feed'] ) || ! is_array( $settings['feed'] ) ) {
			$settings['feed'] = array();
		}

		if ( function_exists( 'esf_facebook_sync_live_display_settings' ) ) {
			$settings['feed'] = esf_facebook_sync_live_display_settings(
				$settings['feed'],
				$incoming_feed
			);
		}

		if ( function_exists( 'esf_facebook_strip_pro_settings' ) ) {
			$settings = esf_facebook_strip_pro_settings( $settings );
		}

		if (
			function_exists( 'esf_facebook_normalize_layout_type' )
			&& isset( $settings['layout']['type'] )
		) {
			$settings['layout']['type'] = esf_facebook_normalize_layout_type(
				(string) $settings['layout']['type']
			);
		}

		if ( isset( $settings['feed']['page_ids'] ) && is_array( $settings['feed']['page_ids'] ) ) {
			$ids = array();
			foreach ( $settings['feed']['page_ids'] as $id ) {
				$id = (int) $id;
				if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
			$settings['feed']['page_ids'] = $ids;
		}

		if ( function_exists( 'esf_facebook_normalize_multifeed_settings' ) ) {
			$settings = esf_facebook_normalize_multifeed_settings( $settings );
		}

		return $settings;
	}

	/**
	 * Decode settings on a feed row object.
	 *
	 * @since 6.7.9
	 * @param object|null $row Feed row.
	 * @return object|null
	 */
	private function hydrate_row( $row ) {
		if ( ! is_object( $row ) ) {
			return null;
		}
		$raw           = isset( $row->settings ) ? $row->settings : array();
		$row->settings = self::normalize_settings( $raw );
		return $row;
	}

	/**
	 * Get feed by ID.
	 *
	 * @since 6.7.9
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
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $table ) . '` WHERE id = %d LIMIT 1',
				$feed_id
			)
		);

		return $this->hydrate_row( $row );
	}

	/**
	 * Feeds linked to an internal page row id.
	 *
	 * @since 6.7.9
	 * @param int $page_row_id Internal pages.id.
	 * @return array<int,object>
	 */
	public function get_by_page_id( $page_row_id ) {
		global $wpdb;

		$page_row_id = (int) $page_row_id;
		if ( $page_row_id <= 0 ) {
			return array();
		}

		$table = $this->get_table_name();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $table ) . '` WHERE page_id = %d ORDER BY id ASC',
				$page_row_id
			)
		);

		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$hydrated = $this->hydrate_row( $row );
				if ( $hydrated ) {
					$out[] = $hydrated;
				}
			}
		}

		return $out;
	}

	/**
	 * Get all feeds.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $args Optional filters.
	 * @return array<int,object>
	 */
	public function get_all( $args = array() ) {
		global $wpdb;

		$table         = $this->get_table_name();
		$table_escaped = esc_sql( $table );
		$status        = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		$content_type  = isset( $args['content_type'] ) ? sanitize_key( (string) $args['content_type'] ) : '';
		$sql           = "SELECT * FROM `{$table_escaped}` WHERE 1=1";
		$prepare_args  = array();

		if ( in_array( $status, self::$allowed_statuses, true ) ) {
			$sql           .= ' AND status = %s';
			$prepare_args[] = $status;
		}
		if ( in_array( $content_type, self::$allowed_content_types, true ) ) {
			$sql           .= ' AND content_type = %s';
			$prepare_args[] = $content_type;
		}

		$sql .= ' ORDER BY created_at DESC';
		if ( ! empty( $prepare_args ) ) {
			$sql = $wpdb->prepare( $sql, $prepare_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$out  = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$hydrated = $this->hydrate_row( $row );
				if ( $hydrated ) {
					$out[] = $hydrated;
				}
			}
		}

		return $out;
	}

	/**
	 * Internal page row IDs that have at least one active feed with Live Display.
	 *
	 * Used by the token cron piggyback — returns empty when nobody enabled
	 * Prefer live / Live only Display (the default), so no Graph work runs.
	 *
	 * @since 6.7.9
	 * @return array<int,int> Unique page row ids.
	 */
	public function get_page_row_ids_with_live_stream() {
		$feeds = $this->get_all(
			array(
				'status'       => 'active',
				'content_type' => 'feed',
			)
		);
		$ids   = array();
		foreach ( $feeds as $feed ) {
			if ( ! is_object( $feed ) ) {
				continue;
			}
			$page_row_id = isset( $feed->page_id ) ? (int) $feed->page_id : 0;
			if ( $page_row_id <= 0 ) {
				continue;
			}
			$settings  = isset( $feed->settings ) && is_array( $feed->settings ) ? $feed->settings : array();
			$feed_cfg  = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();
			$uses_live = function_exists( 'esf_facebook_feed_uses_live_status' )
				? esf_facebook_feed_uses_live_status( $feed_cfg )
				: ! empty( $feed_cfg['live_stream_only'] );
			if ( ! $uses_live ) {
				continue;
			}
			$ids[ $page_row_id ] = $page_row_id;
		}

		return array_values( $ids );
	}

	/**
	 * Create feed or likebox.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $data Feed data.
	 * @return int|false
	 */
	public function create( $data ) {
		global $wpdb;

		$name = isset( $data['name'] ) ? trim( (string) $data['name'] ) : '';
		if ( '' === $name ) {
			return false;
		}

		$content_type = isset( $data['content_type'] ) ? sanitize_key( (string) $data['content_type'] ) : 'feed';
		if ( ! in_array( $content_type, self::$allowed_content_types, true ) ) {
			$content_type = 'feed';
		}

		$feed_type = isset( $data['feed_type'] ) ? sanitize_key( (string) $data['feed_type'] ) : 'timeline';
		if ( ! in_array( $feed_type, self::$allowed_feed_types, true ) ) {
			$feed_type = 'timeline';
		}

		$page_id = array_key_exists( 'page_id', $data ) ? $data['page_id'] : null;
		if ( null !== $page_id && '' !== $page_id ) {
			$page_id = (int) $page_id;
			if ( $page_id <= 0 ) {
				$page_id = null;
			}
		} else {
			$page_id = null;
		}

		$settings = self::normalize_settings( isset( $data['settings'] ) ? $data['settings'] : array() );
		$json     = wp_json_encode( $settings );
		if ( false === $json ) {
			return false;
		}

		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'active';
		if ( ! in_array( $status, self::$allowed_statuses, true ) ) {
			$status = 'active';
		}

		$table = $this->get_table_name();
		$now   = current_time( 'mysql', true );
		$ok    = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'name'         => $name,
				'page_id'      => $page_id,
				'content_type' => $content_type,
				'feed_type'    => $feed_type,
				'source_id'    => isset( $data['source_id'] ) ? sanitize_text_field( (string) $data['source_id'] ) : '',
				'settings'     => $json,
				'status'       => $status,
				'created_at'   => $now,
				'updated_at'   => null,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false === $ok ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Update feed by ID.
	 *
	 * Attaching a valid `page_id` auto-heals a stale `invalid` status (set by
	 * mark_invalid_for_page() when the feed's Page was disconnected) —
	 * see maybe_heal_invalid_status(). where
	 * connection health lives on the account row and never contaminates the
	 * feed's own active/inactive toggle in the first place.
	 *
	 * @since 6.7.9
	 * @param int                 $feed_id Feed ID.
	 * @param array<string,mixed> $data    Data to update.
	 * @return bool
	 */
	public function update( $feed_id, $data ) {
		global $wpdb;

		$feed_id = (int) $feed_id;
		$current = $this->get_by_id( $feed_id );
		if ( $feed_id <= 0 || ! $current ) {
			return false;
		}

		$fields          = array();
		$format          = array();
		$page_id_updated = false;

		if ( array_key_exists( 'name', $data ) ) {
			$name = trim( (string) $data['name'] );
			if ( '' !== $name ) {
				$fields['name'] = $name;
				$format[]       = '%s';
			}
		}
		if ( array_key_exists( 'page_id', $data ) ) {
			$page_id = $data['page_id'];
			if ( null === $page_id || '' === $page_id ) {
				$fields['page_id'] = null;
				$format[]          = '%d';
			} else {
				$page_id = (int) $page_id;
				if ( $page_id > 0 ) {
					$fields['page_id'] = $page_id;
					$format[]          = '%d';
					$page_id_updated   = true;
				}
			}
		}
		if ( array_key_exists( 'content_type', $data ) ) {
			$type = sanitize_key( (string) $data['content_type'] );
			if ( in_array( $type, self::$allowed_content_types, true ) ) {
				$fields['content_type'] = $type;
				$format[]               = '%s';
			}
		}
		if ( array_key_exists( 'feed_type', $data ) ) {
			$type = sanitize_key( (string) $data['feed_type'] );
			if ( in_array( $type, self::$allowed_feed_types, true ) ) {
				$fields['feed_type'] = $type;
				$format[]            = '%s';
			}
		}
		if ( array_key_exists( 'source_id', $data ) ) {
			$fields['source_id'] = sanitize_text_field( (string) $data['source_id'] );
			$format[]            = '%s';
		}
		if ( array_key_exists( 'settings', $data ) ) {
			$settings = self::normalize_settings( $data['settings'] );
			$json     = wp_json_encode( $settings );
			if ( false !== $json ) {
				$fields['settings'] = $json;
				$format[]           = '%s';
			}
		}
		$status_explicit = false;
		if ( array_key_exists( 'status', $data ) ) {
			$status = sanitize_key( (string) $data['status'] );
			if ( in_array( $status, self::$allowed_statuses, true ) ) {
				$fields['status'] = $status;
				$format[]         = '%s';
				$status_explicit  = true;
			}
		}

		if ( ! $status_explicit && $this->maybe_heal_invalid_status( $current, $page_id_updated ) ) {
			$fields['status'] = 'active';
			$format[]         = '%s';
		}

		if ( empty( $fields ) ) {
			return false;
		}

		$fields['updated_at'] = current_time( 'mysql', true );
		$format[]             = '%s';

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->get_table_name(),
			$fields,
			array( 'id' => $feed_id ),
			$format,
			array( '%d' )
		);

		if ( false !== $updated && $page_id_updated && class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
			$page_id = isset( $fields['page_id'] ) ? (int) $fields['page_id'] : 0;
			if ( $page_id > 0 && class_exists( 'ESF_Facebook_Page_Repository' ) ) {
				$page = ESF_Facebook_Page_Repository::get_instance()->get_by_id( $page_id );
				if ( $page ) {
					ESF_Feed_Disconnected_Cache::handle_feed_source_reconnected(
						'facebook',
						$feed_id,
						$page_id
					);
				}
			}
		}

		return false !== $updated;
	}

	/**
	 * Whether an update should auto-heal a stale `invalid` status.
	 *
	 * `invalid` only ever means "this feed's Page link is broken"
	 * (mark_invalid_for_page()). The moment a valid `page_id` is
	 * (re)attached — the only user-facing way to fix that link, via the feed
	 * editor's Source Account/Page picker — that specific problem is resolved
	 * by definition, so the status should clear automatically instead of
	 * staying stuck forever. An intentional `inactive` toggle is left alone;
	 * only `invalid` is healed.
	 *
	 * @since 6.7.9
	 *
	 * @param object $current         Feed row before this update.
	 * @param bool   $page_id_updated Whether this update set a valid `page_id`.
	 * @return bool
	 */
	private function maybe_heal_invalid_status( $current, $page_id_updated ) {
		if ( ! $page_id_updated ) {
			return false;
		}
		$current_status = isset( $current->status ) ? sanitize_key( (string) $current->status ) : '';
		return 'invalid' === $current_status;
	}

	/**
	 * Soft-mark feeds invalid when their page is disconnected.
	 *
	 * @since 6.7.9
	 * @param int $page_row_id Internal pages.id.
	 * @return int Rows updated.
	 */
	public function mark_invalid_for_page( $page_row_id ) {
		global $wpdb;

		$page_row_id = (int) $page_row_id;
		if ( $page_row_id <= 0 ) {
			return 0;
		}

		$table   = $this->get_table_name();
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'status'     => 'invalid',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'page_id' => $page_row_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false === $updated ? 0 : (int) $updated;
	}

	/**
	 * Delete feed by ID.
	 *
	 * @since 6.7.9
	 * @param int $feed_id Feed ID.
	 * @return bool
	 */
	public function delete( $feed_id ) {
		global $wpdb;

		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return false;
		}

		if ( class_exists( 'ESF_Facebook_Cache' ) ) {
			ESF_Facebook_Cache::delete_by_feed_id( $feed_id );
		}

		$deleted = $wpdb->delete( $this->get_table_name(), array( 'id' => $feed_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return false !== $deleted && $deleted > 0;
	}
}
