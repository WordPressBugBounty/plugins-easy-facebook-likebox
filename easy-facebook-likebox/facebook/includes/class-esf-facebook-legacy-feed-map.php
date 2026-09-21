<?php
/**
 * Persist legacy shortcode/widget/block source → modern feed id aliases.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Legacy_Feed_Map
 *
 * Built during migrate. Used by [efb_feed], [efb_likebox], [efb_pageplugin]
 * and Gutenberg block render_callback so existing embeds keep working.
 *
 * Map keys:
 * - `p:{page_id}` → content_type=feed (username aliases use the same `p:` prefix)
 *
 * Like Box / Page Plugin embeds are **not** mapped to feed rows. Modern Like Box
 * is a shortcode/widget generator (`render_from_legacy_atts`); legacy
 * `[efb_likebox]` / page-plugin widgets keep working with inline attributes.
 *
 * @since 6.7.9
 */
class ESF_Facebook_Legacy_Feed_Map {

	/**
	 * Option storing map keys → feed ids.
	 *
	 * @since 6.7.9
	 * @var string
	 */
	const OPTION_KEY = 'esf_facebook_legacy_feed_map';

	/**
	 * Flag once the map has been built on a modern install.
	 *
	 * @since 6.7.9
	 * @var string
	 */
	const DONE_OPTION = 'esf_facebook_legacy_feed_map_built';

	/**
	 * Bump when map indexing rules change so already-modern sites rebuild once.
	 *
	 * v3: stop creating/indexing content_type=likebox feed rows (Like Box tab
	 * is shortcode-only; legacy likebox shortcodes/widgets use inline atts).
	 *
	 * @since 6.7.9
	 * @var int
	 */
	const MAP_VERSION = 3;

	/**
	 * Stored MAP_VERSION after the last successful build.
	 *
	 * @since 6.7.9
	 * @var string
	 */
	const VERSION_OPTION = 'esf_facebook_legacy_feed_map_version';

	/**
	 * Whether a non-empty map is stored.
	 *
	 * @since 6.7.9
	 * @return bool
	 */
	public static function has_entries() {
		$map = get_option( self::OPTION_KEY, array() );
		return is_array( $map ) && ! empty( $map );
	}

	/**
	 * Read the stored map.
	 *
	 * @since 6.7.9
	 * @return array<string,int>
	 */
	public function get_map() {
		$map = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $map ) ) {
			return array();
		}

		$out = array();
		foreach ( $map as $key => $feed_id ) {
			$key     = sanitize_text_field( (string) $key );
			$feed_id = absint( $feed_id );
			if ( '' === $key || $feed_id <= 0 ) {
				continue;
			}
			$out[ $key ] = $feed_id;
		}

		return $out;
	}

	/**
	 * Persist map.
	 *
	 * @since 6.7.9
	 * @param array<string,int> $map Map.
	 * @return void
	 */
	public function save_map( array $map ) {
		update_option( self::OPTION_KEY, $map, false );
	}

	/**
	 * Stable map key for a page + content type.
	 *
	 * @since 6.7.9
	 * @param string $page_key     Facebook Page id or username.
	 * @param string $content_type `feed` or `likebox`.
	 * @return string
	 */
	public function map_key( $page_key, $content_type = 'feed' ) {
		$page_key = $this->normalize_page_key( $page_key );
		if ( '' === $page_key ) {
			return '';
		}
		$content_type = sanitize_key( (string) $content_type );
		if ( 'likebox' === $content_type ) {
			return 'p:' . $page_key . '|c:likebox';
		}
		return 'p:' . $page_key;
	}

	/**
	 * Normalize fanpage_id / fanpage_url / page slug to a map key fragment.
	 *
	 * @since 6.7.9
	 * @param mixed $raw Raw page identifier.
	 * @return string
	 */
	public function normalize_page_key( $raw ) {
		if ( is_array( $raw ) ) {
			$raw = reset( $raw );
		}
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		// Full Facebook URL → last path segment.
		if ( preg_match( '#facebook\.com/#i', $raw ) ) {
			$path = wp_parse_url( $raw, PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $path ) {
				$raw   = trim( $path, '/' );
				$parts = explode( '/', $raw );
				$raw   = end( $parts );
			}
		}

		$raw = sanitize_text_field( $raw );
		return $raw;
	}

	/**
	 * Extract page key from legacy shortcode / widget / block attributes.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $atts Attributes.
	 * @return string
	 */
	public function extract_page_key( $atts ) {
		$keys = $this->extract_page_keys( $atts );
		return ! empty( $keys ) ? (string) $keys[0] : '';
	}

	/**
	 * Extract one or more page keys from legacy shortcode / widget / block attributes.
	 *
	 * Multifeed legacy embeds use comma-separated fanpage_id values; the first
	 * key is the primary page and the rest map to Pro multifeed page_ids[].
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $atts Attributes.
	 * @return string[] Non-empty normalized keys in source order.
	 */
	public function extract_page_keys( $atts ) {
		$atts = is_array( $atts ) ? $atts : array();
		$raw  = '';

		if ( ! empty( $atts['other_page_id'] ) ) {
			$raw = $atts['other_page_id'];
		} else {
			foreach ( array( 'fanpage_id', 'fanpage_url', 'page_id', 'fb_id' ) as $key ) {
				if ( ! empty( $atts[ $key ] ) ) {
					$raw = $atts[ $key ];
					break;
				}
			}
		}

		if ( is_array( $raw ) ) {
			$raw = implode( ',', $raw );
		}

		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return array();
		}

		$parts = preg_split( '/\s*,\s*/', $raw );
		if ( ! is_array( $parts ) ) {
			$parts = array( $raw );
		}

		$keys = array();
		foreach ( $parts as $part ) {
			$normalized = $this->normalize_page_key( $part );
			if ( '' !== $normalized ) {
				$keys[] = $normalized;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Detect whether attributes target a likebox / page plugin.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $atts Attributes.
	 * @return bool
	 */
	public function is_likebox_atts( $atts ) {
		$atts = is_array( $atts ) ? $atts : array();
		if ( isset( $atts['content_type'] ) && 'likebox' === sanitize_key( (string) $atts['content_type'] ) ) {
			return true;
		}
		if ( ! empty( $atts['_esf_fb_likebox'] ) ) {
			return true;
		}
		// Page plugin widget fields.
		if ( isset( $atts['box_width'] ) || isset( $atts['show_stream'] ) || isset( $atts['hide_cover'] ) ) {
			if ( empty( $atts['fanpage_id'] ) && ! empty( $atts['fanpage_url'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remember one source → feed mapping.
	 *
	 * @since 6.7.9
	 * @param string $page_key     Page id or username.
	 * @param int    $feed_id      Feed id.
	 * @param string $content_type feed|likebox.
	 * @return void
	 */
	public function remember( $page_key, $feed_id, $content_type = 'feed' ) {
		$feed_id = absint( $feed_id );
		$key     = $this->map_key( $page_key, $content_type );
		if ( '' === $key || $feed_id <= 0 ) {
			return;
		}

		$map         = $this->get_map();
		$map[ $key ] = $feed_id;
		$this->save_map( $map );
	}

	/**
	 * Look up a feed id for legacy **feed** shortcode / block attributes.
	 *
	 * Like Box embeds are not resolved here — they render from inline attributes
	 * via ESF_Facebook_Likebox_Renderer::render_from_legacy_atts().
	 *
	 * Does not create feeds on the public front end.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed>               $atts            Shortcode/block atts.
	 * @param int                               $default_feed_id Fallback.
	 * @param ESF_Facebook_Page_Repository|null $page_repo       Optional.
	 * @param ESF_Facebook_Feed_Repository|null $feed_repo       Optional.
	 * @return int
	 */
	public function resolve_shortcode_atts( $atts, $default_feed_id = 0, $page_repo = null, $feed_repo = null ) {
		$atts = is_array( $atts ) ? $atts : array();

		// Modern Like Box never uses saved feed rows.
		if ( $this->is_likebox_atts( $atts ) ) {
			return 0;
		}

		$page_key = $this->extract_page_key( $atts );
		$key      = $this->map_key( $page_key, 'feed' );

		$map = $this->get_map();
		if ( '' !== $key && isset( $map[ $key ] ) ) {
			$mapped = absint( $map[ $key ] );
			if ( $mapped > 0 ) {
				return $mapped;
			}
		}

		if ( null === $feed_repo || null === $page_repo ) {
			if ( ! class_exists( 'ESF_Facebook_Feed_Repository', false )
				|| ! class_exists( 'ESF_Facebook_Page_Repository', false )
			) {
				return absint( $default_feed_id );
			}
			$feed_repo = null === $feed_repo ? ESF_Facebook_Feed_Repository::get_instance() : $feed_repo;
			$page_repo = null === $page_repo ? ESF_Facebook_Page_Repository::get_instance() : $page_repo;
		}

		if ( '' !== $page_key ) {
			$page = $page_repo->find_by_page_key( $page_key );
			if ( $page && is_object( $page ) && ! empty( $page->id ) ) {
				$feeds = $feed_repo->get_by_page_id( (int) $page->id );
				if ( is_array( $feeds ) ) {
					foreach ( $feeds as $feed ) {
						if ( ! is_object( $feed ) || empty( $feed->id ) ) {
							continue;
						}
						$ctype = isset( $feed->content_type ) ? (string) $feed->content_type : 'feed';
						if ( 'feed' === $ctype ) {
							return (int) $feed->id;
						}
					}
				}
			}
		}

		return absint( $default_feed_id );
	}

	/**
	 * Build / refresh the map from pages, feeds, and widgets.
	 *
	 * @since 6.7.9
	 * @param int                               $default_feed_id Fallback feed.
	 * @param ESF_Facebook_Page_Repository|null $page_repo       Optional.
	 * @param ESF_Facebook_Feed_Repository|null $feed_repo       Optional.
	 * @return array{mapped:int,created_feeds:int}
	 */
	public function build( $default_feed_id = 0, $page_repo = null, $feed_repo = null ) {
		$stats = array(
			'mapped'        => 0,
			'created_feeds' => 0,
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

		// Drop stale likebox map keys / orphaned likebox feed rows from older migrates.
		$this->purge_legacy_likebox_artifacts( $feed_repo );

		$map = array();

		foreach ( $page_repo->get_all() as $page ) {
			if ( ! is_object( $page ) || empty( $page->id ) || empty( $page->page_id ) ) {
				continue;
			}

			$row_id = (int) $page->id;

			$feed_id = $this->ensure_feed_for_page( $feed_repo, $row_id, 'feed', $page, $stats );
			if ( $feed_id > 0 ) {
				$this->remember_page_aliases( $map, $page, $feed_id, 'feed', $stats );
			}
		}

		// Index existing Graph feeds only (Like Box never uses saved feed rows).
		foreach ( $feed_repo->get_all( array( 'content_type' => 'feed' ) ) as $feed ) {
			if ( ! is_object( $feed ) || empty( $feed->id ) || empty( $feed->page_id ) ) {
				continue;
			}
			$page = $page_repo->get_by_id( (int) $feed->page_id );
			if ( ! $page || empty( $page->page_id ) ) {
				continue;
			}
			$this->remember_page_aliases( $map, $page, (int) $feed->id, 'feed', $stats );
		}

		// Feed widgets only — page-plugin widgets keep inline fanpage_url settings.
		$this->index_widgets( 'widget_easy_facebook_feed', 'feed', $map, $stats, $page_repo, $feed_repo, $default_feed_id );

		$this->save_map( $map );
		update_option( self::DONE_OPTION, '1', false );
		update_option( self::VERSION_OPTION, self::MAP_VERSION, false );

		return $stats;
	}

	/**
	 * Remove migration-era likebox feed rows (modern Like Box is shortcode-only).
	 *
	 * @since 6.7.9
	 * @param ESF_Facebook_Feed_Repository $feed_repo Feed repository.
	 * @return void
	 */
	private function purge_legacy_likebox_artifacts( $feed_repo ) {
		if ( ! is_object( $feed_repo ) || ! method_exists( $feed_repo, 'get_all' ) || ! method_exists( $feed_repo, 'delete' ) ) {
			return;
		}

		$likebox_feeds = $feed_repo->get_all( array( 'content_type' => 'likebox' ) );
		if ( ! is_array( $likebox_feeds ) ) {
			return;
		}

		foreach ( $likebox_feeds as $feed ) {
			if ( ! is_object( $feed ) || empty( $feed->id ) ) {
				continue;
			}
			$ctype = isset( $feed->content_type ) ? sanitize_key( (string) $feed->content_type ) : 'feed';
			if ( 'likebox' !== $ctype ) {
				continue;
			}
			$feed_repo->delete( (int) $feed->id );
		}
	}

	/**
	 * Whether the stored map should be rebuilt (schema/index rule bump).
	 *
	 * @since 6.7.9
	 * @return bool
	 */
	public static function needs_rebuild() {
		return (int) get_option( self::VERSION_OPTION, 0 ) < self::MAP_VERSION;
	}

	/**
	 * Index numeric Page id and vanity username aliases for one feed.
	 *
	 * @since 6.7.9
	 * @param array<string,int> $map          Map (by ref).
	 * @param object            $page         Page row.
	 * @param int               $feed_id      Feed id.
	 * @param string            $content_type feed|likebox.
	 * @param array<string,int> $stats        Stats (by ref).
	 * @return void
	 */
	private function remember_page_aliases( array &$map, $page, $feed_id, $content_type, array &$stats ) {
		$feed_id = absint( $feed_id );
		if ( $feed_id <= 0 || ! is_object( $page ) ) {
			return;
		}

		$aliases = array();
		if ( ! empty( $page->page_id ) ) {
			$aliases[] = (string) $page->page_id;
		}
		if ( ! empty( $page->username ) ) {
			$username = sanitize_text_field( (string) $page->username );
			if ( '' !== $username ) {
				$aliases[] = $username;
			}
		}

		foreach ( array_unique( $aliases ) as $alias ) {
			$key = $this->map_key( $alias, $content_type );
			if ( '' === $key ) {
				continue;
			}
			$map[ $key ] = $feed_id;
			++$stats['mapped'];
		}
	}

	/**
	 * Ensure a Graph feed row exists for a page (never creates likebox rows).
	 *
	 * @since 6.7.9
	 * @param ESF_Facebook_Feed_Repository $feed_repo Feed repo.
	 * @param int                          $row_id    Internal page row id.
	 * @param string                       $content_type Must be `feed` (likebox ignored).
	 * @param object                       $page      Page row.
	 * @param array<string,int>            $stats     Stats (by ref for created_feeds).
	 * @return int
	 */
	private function ensure_feed_for_page( $feed_repo, $row_id, $content_type, $page, array &$stats ) {
		$content_type = sanitize_key( (string) $content_type );
		if ( 'feed' !== $content_type ) {
			return 0;
		}

		$existing = $feed_repo->get_by_page_id( $row_id );
		if ( is_array( $existing ) ) {
			foreach ( $existing as $feed ) {
				if ( ! is_object( $feed ) || empty( $feed->id ) ) {
					continue;
				}
				$ctype = isset( $feed->content_type ) ? (string) $feed->content_type : 'feed';
				if ( 'feed' === $ctype ) {
					return (int) $feed->id;
				}
			}
		}

		$page_name = ! empty( $page->page_name ) ? (string) $page->page_name : (string) $page->page_id;
		$name      = sprintf(
			/* translators: %s: Facebook Page name */
			__( '%s Feed', 'easy-facebook-likebox' ),
			$page_name
		);

		$created = $feed_repo->create(
			array(
				'name'         => $name,
				'page_id'      => $row_id,
				'content_type' => 'feed',
				'feed_type'    => 'timeline',
				'settings'     => ESF_Facebook_Feed_Repository::get_default_settings(),
			)
		);

		if ( is_int( $created ) && $created > 0 ) {
			++$stats['created_feeds'];
			return $created;
		}

		return 0;
	}

	/**
	 * Index widget option instances into the map.
	 *
	 * @since 6.7.9
	 * @param string                       $option_key       Widget option.
	 * @param string                       $content_type     feed|likebox.
	 * @param array<string,int>            $map              Map (by ref).
	 * @param array<string,int>            $stats            Stats (by ref).
	 * @param ESF_Facebook_Page_Repository $page_repo        Pages.
	 * @param ESF_Facebook_Feed_Repository $feed_repo        Feeds.
	 * @param int                          $default_feed_id  Fallback.
	 * @return void
	 */
	private function index_widgets( $option_key, $content_type, array &$map, array &$stats, $page_repo, $feed_repo, $default_feed_id ) {
		$widgets = get_option( $option_key, array() );
		if ( ! is_array( $widgets ) ) {
			return;
		}

		foreach ( $widgets as $number => $instance ) {
			if ( ! is_numeric( $number ) || ! is_array( $instance ) ) {
				continue;
			}

			$feed_id  = isset( $instance['feed_id'] ) ? absint( $instance['feed_id'] ) : 0;
			$page_key = $this->extract_page_key( $instance );

			if ( $feed_id <= 0 && '' !== $page_key ) {
				$atts                    = $instance;
				$atts['_esf_fb_likebox'] = ( 'likebox' === $content_type );
				$feed_id                 = $this->resolve_shortcode_atts( $atts, $default_feed_id, $page_repo, $feed_repo );
			}

			$key = $this->map_key( $page_key, $content_type );
			if ( '' !== $key && $feed_id > 0 ) {
				$map[ $key ] = $feed_id;
				++$stats['mapped'];
			}
		}
	}
}
