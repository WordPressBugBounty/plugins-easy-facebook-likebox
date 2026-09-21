<?php
/**
 * Facebook Feed Query Context
 *
 * Single source of truth for what Graph content a feed loads:
 * feed_type (DB column), events_filter / album_id (settings), page scope.
 *
 * Used by renderer, preview, load-more, and cache key generation so filters
 * stay consistent across admin live preview and frontend shortcodes.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Feed_Query
 *
 * @since 6.7.9
 */
class ESF_Facebook_Feed_Query {

	/**
	 * Allowed feed_type values.
	 *
	 * @var array<int,string>
	 */
	public const TYPES = array( 'timeline', 'images', 'videos', 'events', 'albums', 'mentioned' );

	/**
	 * Allowed event time filters.
	 *
	 * @var array<int,string>
	 */
	public const EVENT_FILTERS = array( 'upcoming', 'past', 'all' );

	/**
	 * @var string
	 */
	private $feed_type = 'timeline';

	/**
	 * @var string Empty, upcoming, past, or all.
	 */
	private $events_filter = '';

	/**
	 * @var string Facebook album id or empty.
	 */
	private $album_id = '';

	/**
	 * Internal esf_facebook_pages.id values.
	 *
	 * @var array<int,int>
	 */
	private $page_row_ids = array();

	/**
	 * @var int
	 */
	private $feed_id = 0;

	/**
	 * @var int Graph page size (prefetch buffer; display still uses feed.per_page).
	 */
	private $limit = 100;

	/**
	 * Multifeed sort mode: newest|balanced.
	 *
	 * @var string
	 */
	private $multifeed_sort = 'newest';

	/**
	 * Instagram-parity Graph prefetch size so raising Posts per page does not
	 * stick at the first cached page (historically equal to per_page, often 9).
	 */
	const GRAPH_PREFETCH_LIMIT = 100;

	/**
	 * Build from a feed row/object + optional overrides (preview / render).
	 *
	 * Override keys: feed_type, events_filter, album_id, page_id, page_ids, limit, settings.
	 *
	 * Canonical type source: `$overrides['feed_type']` → row `feed_type` →
	 * legacy `settings.feed.filter` (migration only). Pro gate applied last.
	 *
	 * @since 6.7.9
	 * @param object|null         $feed      Feed row/object.
	 * @param array<string,mixed> $overrides Preview or render overrides.
	 * @return self
	 */
	public static function from_feed( $feed, array $overrides = array() ): self {
		$query = new self();

		$settings = array();
		if ( is_object( $feed ) && isset( $feed->settings ) && is_array( $feed->settings ) ) {
			$settings = $feed->settings;
		}
		if ( isset( $overrides['settings'] ) && is_array( $overrides['settings'] ) ) {
			$settings = array_replace_recursive( $settings, $overrides['settings'] );
		}
		$feed_cfg = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();

		$query->feed_id = is_object( $feed ) && isset( $feed->id ) ? (int) $feed->id : 0;

		// --- feed_type ---
		$type = '';
		if ( isset( $overrides['feed_type'] ) && is_string( $overrides['feed_type'] ) && '' !== $overrides['feed_type'] ) {
			$type = sanitize_key( $overrides['feed_type'] );
		} elseif ( is_object( $feed ) && isset( $feed->feed_type ) && '' !== (string) $feed->feed_type ) {
			$type = sanitize_key( (string) $feed->feed_type );
		}

		// Legacy migration key only (never written by modern Connect panel).
		if ( ( '' === $type || 'timeline' === $type ) && ! empty( $feed_cfg['filter'] ) ) {
			$legacy = sanitize_key( (string) $feed_cfg['filter'] );
			if ( in_array( $legacy, self::TYPES, true ) ) {
				$type = $legacy;
			}
		}

		if ( ! in_array( $type, self::TYPES, true ) ) {
			$type = 'timeline';
		}

		if ( 'timeline' !== $type && function_exists( 'esf_facebook_can_use_filters' ) && ! esf_facebook_can_use_filters() ) {
			$type = 'timeline';
		}
		$query->feed_type = $type;

		// --- events_filter ---
		$events = '';
		if ( array_key_exists( 'events_filter', $overrides ) ) {
			$events = sanitize_key( (string) $overrides['events_filter'] );
		} elseif ( isset( $feed_cfg['events_filter'] ) ) {
			$events = sanitize_key( (string) $feed_cfg['events_filter'] );
		}
		if ( 'all' === $events ) {
			$events = '';
		}
		if ( '' !== $events && ! in_array( $events, array( 'upcoming', 'past' ), true ) ) {
			$events = '';
		}
		$query->events_filter = ( 'events' === $query->feed_type ) ? $events : '';

		// --- album_id ---
		$album = '';
		if ( array_key_exists( 'album_id', $overrides ) ) {
			$album = sanitize_text_field( (string) $overrides['album_id'] );
		} elseif ( isset( $feed_cfg['album_id'] ) ) {
			$album = sanitize_text_field( (string) $feed_cfg['album_id'] );
		}
		$query->album_id = ( 'albums' === $query->feed_type ) ? $album : '';

		// --- multifeed_sort ---
		$sort = '';
		if ( array_key_exists( 'multifeed_sort', $overrides ) ) {
			$sort = sanitize_key( (string) $overrides['multifeed_sort'] );
		} elseif ( isset( $feed_cfg['multifeed_sort'] ) ) {
			$sort = sanitize_key( (string) $feed_cfg['multifeed_sort'] );
		}
		$query->multifeed_sort = function_exists( 'esf_facebook_resolve_multifeed_sort' )
			? esf_facebook_resolve_multifeed_sort( array( 'multifeed_sort' => $sort ) )
			: ( 'balanced' === $sort ? 'balanced' : 'newest' );

		// --- page scope ---
		if ( isset( $overrides['page_ids'] ) && is_array( $overrides['page_ids'] ) ) {
			$query->page_row_ids = self::sanitize_page_ids( $overrides['page_ids'] );
		} elseif ( function_exists( 'esf_facebook_resolve_page_row_ids' ) && is_object( $feed ) ) {
			// Prefer Multifeed-aware resolve. Optional page_id override only
			// swaps the primary Page — it must not drop settings.feed.page_ids.
			$scoped           = clone $feed;
			$scoped->settings = $settings;
			if ( isset( $overrides['page_id'] ) && (int) $overrides['page_id'] > 0 ) {
				$scoped->page_id = (int) $overrides['page_id'];
			}
			$query->page_row_ids = esf_facebook_resolve_page_row_ids( $scoped );
		} elseif ( isset( $overrides['page_id'] ) && (int) $overrides['page_id'] > 0 ) {
			$query->page_row_ids = array( (int) $overrides['page_id'] );
		} elseif ( is_object( $feed ) && isset( $feed->page_id ) && (int) $feed->page_id > 0 ) {
			$query->page_row_ids = array( (int) $feed->page_id );
		}

		// --- limit (Graph prefetch buffer; layouts still slice by feed.per_page) ---
		if ( isset( $overrides['limit'] ) ) {
			$query->limit = max( 1, min( self::GRAPH_PREFETCH_LIMIT, (int) $overrides['limit'] ) );
		} else {
			$per_page     = isset( $feed_cfg['per_page'] )
				? max( 1, min( 50, (int) $feed_cfg['per_page'] ) )
				: 9;
			$query->limit = max( $per_page, self::GRAPH_PREFETCH_LIMIT );
		}

		return $query;
	}

	/**
	 * @param array<int,mixed> $ids Raw ids.
	 * @return array<int,int>
	 */
	private static function sanitize_page_ids( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * @since 6.7.9
	 * @return string
	 */
	public function get_feed_type(): string {
		return $this->feed_type;
	}

	/**
	 * @since 6.7.9
	 * @return string
	 */
	public function get_events_filter(): string {
		return $this->events_filter;
	}

	/**
	 * @since 6.7.9
	 * @return string
	 */
	public function get_album_id(): string {
		return $this->album_id;
	}

	/**
	 * @since 6.7.9
	 * @return array<int,int>
	 */
	public function get_page_row_ids(): array {
		return $this->page_row_ids;
	}

	/**
	 * Primary page row id (first scoped page).
	 *
	 * @since 6.7.9
	 * @return int
	 */
	public function get_primary_page_row_id(): int {
		return isset( $this->page_row_ids[0] ) ? (int) $this->page_row_ids[0] : 0;
	}

	/**
	 * @since 6.7.9
	 * @return int
	 */
	public function get_feed_id(): int {
		return $this->feed_id;
	}

	/**
	 * @since 6.7.9
	 * @return int
	 */
	public function get_limit(): int {
		return $this->limit;
	}

	/**
	 * @since 6.10.0
	 * @return string newest|balanced
	 */
	public function get_multifeed_sort(): string {
		return $this->multifeed_sort;
	}

	/**
	 * Args for ESF_Facebook_Graph_Client::fetch_page_content().
	 *
	 * @since 6.7.9
	 * @return array<string,mixed>
	 */
	public function get_graph_args(): array {
		return array(
			'limit'         => $this->limit,
			'events_filter' => $this->events_filter,
			'album_id'      => $this->album_id,
		);
	}

	/**
	 * Suffix differentiating event/album variants in cache keys.
	 *
	 * @since 6.7.9
	 * @return string
	 */
	public function get_cache_variant(): string {
		$extra = '';
		if ( 'albums' === $this->feed_type && '' !== $this->album_id ) {
			// `_ap` = album *photos* (v2). Older `_a` keys may hold poisoned
			// album-*list* payloads from when album_id was ignored at Graph fetch.
			$extra .= '_ap' . preg_replace( '/[^a-zA-Z0-9_-]/', '', $this->album_id );
		}
		if ( 'events' === $this->feed_type && '' !== $this->events_filter ) {
			$extra .= '_e' . $this->events_filter;
		}
		if ( count( $this->page_row_ids ) > 1 && 'balanced' === $this->multifeed_sort ) {
			$extra .= class_exists( 'ESF_Multifeed' )
				? ESF_Multifeed::cache_variant_suffix( $this->multifeed_sort, true )
				: '_bal';
		}
		return $extra;
	}

	/**
	 * Posts + meta cache keys for this query.
	 *
	 * Each feed_type (+ event/album variant) gets its own key so switching
	 * filters does not invalidate sibling caches.
	 *
	 * @since 6.7.9
	 * @return array{cache_key:string,meta_key:string}
	 */
	public function get_cache_keys(): array {
		$variant = $this->get_cache_variant();
		$type    = $this->feed_type . $variant;

		if ( count( $this->page_row_ids ) > 1 && $this->feed_id > 0 ) {
			$key = sprintf( 'esf_fb_multi_%d_%s', $this->feed_id, $type );
			return array(
				'cache_key' => $key,
				'meta_key'  => $key . '_meta',
			);
		}

		$page_row_id = $this->get_primary_page_row_id();
		$cache_key   = class_exists( 'ESF_Facebook_Cache' )
			? ESF_Facebook_Cache::posts_cache_key( $page_row_id, $type )
			: sprintf( 'esf_fb_posts_%d_%s', $page_row_id, $type );

		return array(
			'cache_key' => $cache_key,
			'meta_key'  => $cache_key . '_meta',
		);
	}

	/**
	 * Apply feed_type + sub-options onto a feed-like object for downstream code.
	 *
	 * @since 6.7.9
	 * @param object $feed Feed object.
	 * @return object
	 */
	public function apply_to_feed_object( $feed ) {
		if ( ! is_object( $feed ) ) {
			$feed = (object) array();
		}
		$feed->feed_type = $this->feed_type;
		if ( ! isset( $feed->settings ) || ! is_array( $feed->settings ) ) {
			$feed->settings = array();
		}
		if ( ! isset( $feed->settings['feed'] ) || ! is_array( $feed->settings['feed'] ) ) {
			$feed->settings['feed'] = array();
		}
		$feed->settings['feed']['events_filter']  = $this->events_filter;
		$feed->settings['feed']['album_id']       = $this->album_id;
		$feed->settings['feed']['multifeed_sort'] = $this->multifeed_sort;
		// Keep settings.feed.filter cleared for modern writes; legacy readers use column.
		$feed->settings['feed']['filter'] = '';
		return $feed;
	}
}
