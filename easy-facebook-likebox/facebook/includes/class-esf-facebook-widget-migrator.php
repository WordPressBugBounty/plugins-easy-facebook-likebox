<?php
/**
 * Remap legacy Facebook widget instances to modern feed ids.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Widget_Migrator
 *
 * Scans `widget_easy_facebook_feed` and `widget_easy_facebook_page_plugin`
 * and assigns a modern `feed_id` per instance. Preserves id_base options so
 * existing sidebars survive. Safe to run multiple times.
 *
 * @since 6.7.9
 */
class ESF_Facebook_Widget_Migrator {

	/**
	 * Feed widget option key (matches legacy / modern id_base).
	 *
	 * @since 6.7.9
	 * @var string
	 */
	const FEED_OPTION_KEY = 'widget_easy_facebook_feed';

	/**
	 * Page plugin / likebox widget option key.
	 *
	 * @since 6.7.9
	 * @var string
	 */
	const LIKEBOX_OPTION_KEY = 'widget_easy_facebook_page_plugin';

	/**
	 * Flag once widgets have been remapped.
	 *
	 * @since 6.7.9
	 * @var string
	 */
	const DONE_OPTION = 'esf_facebook_widgets_migrated';

	/**
	 * @var ESF_Facebook_Legacy_Feed_Map
	 */
	private $map;

	/**
	 * Constructor.
	 *
	 * @since 6.7.9
	 * @param ESF_Facebook_Legacy_Feed_Map|null $map Optional shared map helper.
	 */
	public function __construct( $map = null ) {
		$dir = defined( 'ESF_FACEBOOK_DIR' ) ? ESF_FACEBOOK_DIR : ( defined( 'EFBL_PLUGIN_DIR' ) ? EFBL_PLUGIN_DIR : '' );
		if ( ! class_exists( 'ESF_Facebook_Legacy_Feed_Map', false ) && '' !== $dir ) {
			require_once $dir . 'includes/class-esf-facebook-legacy-feed-map.php';
		}
		$this->map = $map instanceof ESF_Facebook_Legacy_Feed_Map
			? $map
			: new ESF_Facebook_Legacy_Feed_Map();
	}

	/**
	 * Remap all legacy widget instances to modern feed ids.
	 *
	 * @since 6.7.9
	 * @param int                               $default_feed_id Fallback feed.
	 * @param ESF_Facebook_Page_Repository|null $page_repo       Optional.
	 * @param ESF_Facebook_Feed_Repository|null $feed_repo       Optional.
	 * @return array{updated:int,created_feeds:int,skipped:int}
	 */
	public function migrate_widgets( $default_feed_id = 0, $page_repo = null, $feed_repo = null ) {
		$stats = array(
			'updated'       => 0,
			'created_feeds' => 0,
			'skipped'       => 0,
		);

		if ( null === $feed_repo ) {
			if ( ! class_exists( 'ESF_Facebook_Feed_Repository', false ) ) {
				return $stats;
			}
			$feed_repo = ESF_Facebook_Feed_Repository::get_instance();
		}
		if ( null === $page_repo ) {
			if ( ! class_exists( 'ESF_Facebook_Page_Repository', false ) ) {
				return $stats;
			}
			$page_repo = ESF_Facebook_Page_Repository::get_instance();
		}

		$default_feed_id = absint( $default_feed_id );
		if ( $default_feed_id <= 0 && function_exists( 'esf_facebook_get_migration_default_feed_id' ) ) {
			$default_feed_id = absint( esf_facebook_get_migration_default_feed_id() );
		}

		$feed_stats = $this->migrate_option(
			self::FEED_OPTION_KEY,
			'feed',
			$default_feed_id,
			$page_repo,
			$feed_repo
		);

		// Page Plugin / Like Box widgets keep inline fanpage_url settings.
		// Modern ESF_Facebook_Modern_Likebox_Widget renders via
		// ESF_Facebook_Likebox_Renderer::render_from_legacy_atts() — no feed_id.
		$like_stats = $this->preserve_likebox_widgets();

		$stats['updated']       = $feed_stats['updated'] + $like_stats['updated'];
		$stats['created_feeds'] = $feed_stats['created_feeds'] + $like_stats['created_feeds'];
		$stats['skipped']       = $feed_stats['skipped'] + $like_stats['skipped'];

		update_option( self::DONE_OPTION, '1', false );

		return $stats;
	}

	/**
	 * Leave page-plugin widget instances intact (no feed rows).
	 *
	 * Strips any migration-era `feed_id` so the modern widget form stays on
	 * inline Page Plugin settings.
	 *
	 * @since 6.7.9
	 * @return array{updated:int,created_feeds:int,skipped:int}
	 */
	private function preserve_likebox_widgets() {
		$stats = array(
			'updated'       => 0,
			'created_feeds' => 0,
			'skipped'       => 0,
		);

		$widgets = get_option( self::LIKEBOX_OPTION_KEY, array() );
		if ( ! is_array( $widgets ) || empty( $widgets ) ) {
			return $stats;
		}

		$changed = false;
		foreach ( $widgets as $number => $instance ) {
			if ( ! is_numeric( $number ) || ! is_array( $instance ) ) {
				continue;
			}

			if ( ! array_key_exists( 'feed_id', $instance ) ) {
				++$stats['skipped'];
				continue;
			}

			unset( $widgets[ $number ]['feed_id'] );
			++$stats['updated'];
			$changed = true;
		}

		if ( $changed ) {
			update_option( self::LIKEBOX_OPTION_KEY, $widgets, false );
		}

		return $stats;
	}

	/**
	 * Remap one widget option array.
	 *
	 * @since 6.7.9
	 * @param string                       $option_key       Option name.
	 * @param string                       $content_type     feed|likebox.
	 * @param int                          $default_feed_id  Fallback.
	 * @param ESF_Facebook_Page_Repository $page_repo        Pages.
	 * @param ESF_Facebook_Feed_Repository $feed_repo        Feeds.
	 * @return array{updated:int,created_feeds:int,skipped:int}
	 */
	private function migrate_option( $option_key, $content_type, $default_feed_id, $page_repo, $feed_repo ) {
		$stats = array(
			'updated'       => 0,
			'created_feeds' => 0,
			'skipped'       => 0,
		);

		$widgets = get_option( $option_key, array() );
		if ( ! is_array( $widgets ) || empty( $widgets ) ) {
			return $stats;
		}

		$changed = false;

		foreach ( $widgets as $number => $instance ) {
			if ( ! is_numeric( $number ) || ! is_array( $instance ) ) {
				continue;
			}

			$existing_feed_id = isset( $instance['feed_id'] ) ? absint( $instance['feed_id'] ) : 0;
			if ( $existing_feed_id > 0 && $feed_repo->get_by_id( $existing_feed_id ) ) {
				++$stats['skipped'];
				continue;
			}

			$result  = $this->resolve_feed_for_instance( $instance, $content_type, $page_repo, $feed_repo, $default_feed_id );
			$feed_id = isset( $result['feed_id'] ) ? absint( $result['feed_id'] ) : 0;

			if ( $feed_id <= 0 ) {
				++$stats['skipped'];
				continue;
			}

			if ( ! empty( $result['created'] ) ) {
				++$stats['created_feeds'];
			}

			// Carry legacy “Show like box” onto the modern feed settings when present.
			if (
				'feed' === $content_type
				&& ! empty( $instance['show_like_box'] )
				&& $feed_id > 0
			) {
				$feed = $feed_repo->get_by_id( $feed_id );
				if ( $feed && is_object( $feed ) ) {
					$settings = isset( $feed->settings ) && is_array( $feed->settings )
						? $feed->settings
						: ESF_Facebook_Feed_Repository::get_default_settings();
					if ( empty( $settings['feed']['show_like_box'] ) ) {
						$settings['feed']['show_like_box'] = true;
						$feed_repo->update(
							$feed_id,
							array(
								'settings' => $settings,
							)
						);
					}
				}
			}

			$widgets[ $number ]['feed_id'] = $feed_id;
			++$stats['updated'];
			$changed = true;
		}

		if ( $changed ) {
			update_option( $option_key, $widgets, false );
		}

		return $stats;
	}

	/**
	 * Resolve or create a modern feed for one legacy **feed** widget instance.
	 *
	 * Likebox / page-plugin widgets are not remapped to feed rows — see
	 * preserve_likebox_widgets().
	 *
	 * @since 6.7.9
	 * @param array<string,mixed>          $instance         Widget instance.
	 * @param string                       $content_type     Must be `feed`.
	 * @param ESF_Facebook_Page_Repository $page_repo        Pages.
	 * @param ESF_Facebook_Feed_Repository $feed_repo        Feeds.
	 * @param int                          $default_feed_id  Fallback.
	 * @return array{feed_id:int,created:bool}
	 */
	public function resolve_feed_for_instance( $instance, $content_type, $page_repo, $feed_repo, $default_feed_id = 0 ) {
		if ( 'likebox' === $content_type ) {
			return array(
				'feed_id' => 0,
				'created' => false,
			);
		}

		$atts = is_array( $instance ) ? $instance : array();

		$feed_id = $this->map->resolve_shortcode_atts( $atts, 0, $page_repo, $feed_repo );
		if ( $feed_id > 0 ) {
			return array(
				'feed_id' => $feed_id,
				'created' => false,
			);
		}

		$page_key = $this->map->extract_page_key( $atts );
		$page     = null;
		if ( '' !== $page_key ) {
			$page = $page_repo->find_by_page_key( $page_key );
		}

		if ( $page && is_object( $page ) && ! empty( $page->id ) ) {
			$page_fb_id = isset( $page->page_id ) ? (string) $page->page_id : '';
			$page_name  = ! empty( $page->page_name ) ? (string) $page->page_name : $page_fb_id;
			$name       = sprintf(
					/* translators: %s: Facebook Page name */
				__( '%s Feed', 'easy-facebook-likebox' ),
				$page_name
			);

			$settings    = $this->build_legacy_feed_settings_from_instance( $instance, 'feed', $page_repo );
			$feed_type   = $this->resolve_legacy_feed_type( $instance );
			$page_row_id = (int) $page->id;

			$created = $feed_repo->create(
				array(
					'name'         => $name,
					'page_id'      => $page_row_id,
					'content_type' => 'feed',
					'feed_type'    => $feed_type,
					'settings'     => $settings,
				)
			);

			if ( is_int( $created ) && $created > 0 ) {
				$this->map->remember( $page_fb_id, $created, 'feed' );
				if ( ! empty( $page->username ) ) {
					$this->map->remember( (string) $page->username, $created, 'feed' );
				}
				return array(
					'feed_id' => $created,
					'created' => true,
				);
			}
		}

		return array(
			'feed_id' => absint( $default_feed_id ),
			'created' => false,
		);
	}

	/**
	 * Extract page key from a legacy widget instance.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $instance Widget instance.
	 * @return string
	 */
	public function extract_page_key( $instance ) {
		return $this->map->extract_page_key( $instance );
	}

	/**
	 * Map legacy widget instance fields onto modern feed settings.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed>          $instance     Widget instance.
	 * @param string                       $content_type feed|likebox.
	 * @param ESF_Facebook_Page_Repository $page_repo    Pages.
	 * @return array<string,mixed>
	 */
	private function build_legacy_feed_settings_from_instance( $instance, $content_type, $page_repo ) {
		$settings = ESF_Facebook_Feed_Repository::get_default_settings();
		if ( 'feed' !== $content_type ) {
			return $settings;
		}

		if ( ! empty( $instance['show_like_box'] ) ) {
			$settings['feed']['show_like_box'] = true;
		}

		if ( isset( $instance['post_limit'] ) && is_numeric( $instance['post_limit'] ) ) {
			$settings['feed']['per_page'] = max( 1, min( 50, (int) $instance['post_limit'] ) );
		}

		if ( ! empty( $instance['load_more'] ) ) {
			$settings['feed']['load_more'] = ! empty( $instance['load_more'] );
		}

		if ( isset( $instance['links_new_tab'] ) ) {
			$settings['feed']['links_new_tab'] = ! empty( $instance['links_new_tab'] );
		}

		if ( ! empty( $instance['filter'] ) ) {
			$settings['feed']['filter'] = sanitize_key( (string) $instance['filter'] );
		}

		if ( ! empty( $instance['events_filter'] ) ) {
			$settings['feed']['events_filter'] = sanitize_key( (string) $instance['events_filter'] );
		}

		if ( ! empty( $instance['live_stream_only'] ) ) {
			$settings['feed']['live_stream_only'] = true;
			$settings['feed']['live_display']     = 'live_only';
		}

		$page_keys = $this->map->extract_page_keys( $instance );
		if ( count( $page_keys ) > 1 && function_exists( 'esf_facebook_can_use_multifeed' ) && esf_facebook_can_use_multifeed() ) {
			$row_ids = array();
			foreach ( $page_keys as $key ) {
				$row = $page_repo->find_by_page_key( $key );
				if ( $row && is_object( $row ) && ! empty( $row->id ) ) {
					$row_ids[] = (int) $row->id;
				}
			}
			$row_ids = array_values( array_unique( array_filter( $row_ids ) ) );
			if ( count( $row_ids ) > 1 ) {
				$settings['feed']['page_ids'] = array_slice( $row_ids, 1 );
			}
		}

		return $settings;
	}

	/**
	 * Resolve modern feed_type column from a legacy widget instance.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $instance Widget instance.
	 * @return string
	 */
	private function resolve_legacy_feed_type( $instance ) {
		$filter = isset( $instance['filter'] ) ? sanitize_key( (string) $instance['filter'] ) : '';
		if ( class_exists( 'ESF_Facebook_Feed_Query', false ) ) {
			$allowed = ESF_Facebook_Feed_Query::TYPES;
		} else {
			$allowed = array( 'timeline', 'images', 'videos', 'events', 'albums', 'mentioned' );
		}
		if ( in_array( $filter, $allowed, true ) && 'timeline' !== $filter ) {
			return $filter;
		}
		return 'timeline';
	}

	/**
	 * Stable id_base values preserved across migration.
	 *
	 * @since 6.7.9
	 * @return string[]
	 */
	public static function preserved_id_bases() {
		return array( 'easy_facebook_feed', 'easy_facebook_page_plugin' );
	}
}
