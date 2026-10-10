<?php
/**
 * YouTube Feed Renderer.
 *
 * Thin orchestrator: load feed/account, fetch videos, map to VOs,
 * delegate HTML to shared EasySocialFeed\Layouts\Renderer.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube
 * @since 6.7.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EasySocialFeed\Layouts\Primitives\MissingFeedState;
use EasySocialFeed\Layouts\ValueObjects\MissingFeedContext;
use EasySocialFeed\Layouts\Renderer as SharedRenderer;
use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\YouTube\Layouts\AccountMapper;
use EasySocialFeed\YouTube\Layouts\PostMapper;

/**
 * Class ESF_YouTube_Renderer
 *
 * @since 6.7.5
 */
class ESF_YouTube_Renderer {

	/**
	 * Module slug for shared missing-feed placeholders.
	 *
	 * @since 6.11.0
	 * @var string
	 */
	const MODULE = 'youtube';

	/**
	 * Whether the historical BEM wrapper-class filter has been registered.
	 *
	 * @since 6.14.0
	 * @var bool
	 */
	private static $bem_wrapper_filter_registered = false;

	/**
	 * Maximum number of videos to fetch per feed (YouTube API max per page).
	 *
	 * @since 6.7.5
	 * @var int
	 */
	const MAX_VIDEO_FETCH = 50;

	/**
	 * Last search match total from get_videos_for_feed (YouTube pageInfo.totalResults).
	 *
	 * @since 6.7.6
	 * @var int|null
	 */
	private $last_search_total_results = null;

	/**
	 * Total matching videos for the last search feed render, if any.
	 *
	 * @since 6.7.6
	 * @return int|null
	 */
	public function get_last_search_total_results() {
		return $this->last_search_total_results;
	}

	/**
	 * Render a feed by ID.
	 *
	 * @since 6.7.5
	 *
	 * @param int   $feed_id           Feed ID.
	 * @param array $settings_override Optional. Override settings (e.g. for preview). Merged with saved settings.
	 * @param bool  $enqueue_assets    Whether to enqueue CSS/JS. Set false for REST preview.
	 * @param array $feed_overrides    Optional. Live preview overrides for feed_type / source_id / account_id.
	 * @return string HTML output. Empty string on failure.
	 */
	public function render( $feed_id, $settings_override = array(), $enqueue_assets = true, $feed_overrides = array() ) {
		$rendered = $this->render_internal(
			(int) $feed_id,
			is_array( $settings_override ) ? $settings_override : array(),
			is_array( $feed_overrides ) ? $feed_overrides : array()
		);

		if ( '' === $rendered['html'] ) {
			return '';
		}

		if ( $enqueue_assets && null !== $rendered['definition'] ) {
			( new SharedRenderer() )->enqueue_assets( $rendered['definition'] );
			// Flag that a feed rendered so loading script can be enqueued
			if ( function_exists( 'esf_youtube_flag_feed_script' ) ) {
				esf_youtube_flag_feed_script();
			}
		}

		$html = $rendered['html'];
		// Shared renderer may attach an optional admin notice key.
		$admin_notice = isset( $rendered['admin_notice_html'] ) ? (string) $rendered['admin_notice_html'] : '';
		if ( '' !== $admin_notice ) {
			$html = $admin_notice . $html;
		}

		return $html;
	}

	/**
	 * Resolve AccountSummary for a live or disconnected channel.
	 *
	 * @since 6.7.9
	 *
	 * @param int         $account_id  Internal account id.
	 * @param object|null $account_row Live account row when connected.
	 * @return AccountSummary|null
	 */
	public static function resolve_youtube_account( $account_id, $account_row = null ): ?AccountSummary {
		$account_id = (int) $account_id;
		if ( $account_id <= 0 ) {
			return null;
		}

		if ( is_object( $account_row ) && class_exists( 'ESF_YouTube_Account_Repository' ) ) {
			$account_row = ESF_YouTube_Account_Repository::get_instance()->repair_channel_images_if_needed( $account_row );
			return AccountMapper::from_row( $account_row );
		}

		$snapshot = class_exists( 'ESF_YouTube_Cache' )
			? ESF_YouTube_Cache::get_account_profile_snapshot( $account_id )
			: null;
		if ( ! is_array( $snapshot ) || empty( $snapshot ) ) {
			return null;
		}

		return AccountMapper::from_row( (object) $snapshot );
	}

	/**
	 * Whether a feed still has cached video items (including expired rows).
	 *
	 * @since 6.7.9
	 *
	 * @param object $feed_obj Feed object.
	 * @return bool
	 */
	public static function feed_has_videos_cache( $feed_obj ): bool {
		if ( ! class_exists( 'ESF_YouTube_Cache' ) || ! is_object( $feed_obj ) ) {
			return false;
		}

		$account_id = isset( $feed_obj->account_id ) ? (int) $feed_obj->account_id : 0;
		if ( $account_id <= 0 ) {
			return false;
		}

		$feed_type      = isset( $feed_obj->feed_type ) ? (string) $feed_obj->feed_type : 'channel';
		$source_id      = isset( $feed_obj->source_id ) ? trim( (string) $feed_obj->source_id ) : '';
		$settings       = isset( $feed_obj->settings ) && is_array( $feed_obj->settings )
			? $feed_obj->settings
			: ( class_exists( 'ESF_YouTube_Feed_Repository' )
				? ESF_YouTube_Feed_Repository::get_default_settings()
				: array() );
		$content_filter = function_exists( 'esf_youtube_content_filter_from_settings' )
			? esf_youtube_content_filter_from_settings( $settings )
			: 'all';

		$channel_id = '';
		// Cache class is always available in this module bootstrap; keep the guard for safety.
		$snapshot = ESF_YouTube_Cache::get_account_profile_snapshot( $account_id );
		if ( is_array( $snapshot ) && ! empty( $snapshot['channel_id'] ) ) {
			$channel_id = (string) $snapshot['channel_id'];
		}

		if ( function_exists( 'esf_youtube_resolve_videos_source' ) ) {
			$resolved  = esf_youtube_resolve_videos_source(
				$feed_type,
				$source_id,
				$content_filter,
				$channel_id
			);
			$feed_type = $resolved['feed_type'];
			$source_id = $resolved['source_id'];
		}

		$cache_key = function_exists( 'esf_youtube_videos_cache_key' )
			? esf_youtube_videos_cache_key( $account_id, $feed_type, $source_id )
			: 'esf_yt_videos_' . $account_id;
		if ( '' === $cache_key ) {
			return false;
		}

		$cached = ESF_YouTube_Cache::get( $cache_key );
		if ( ! is_array( $cached ) ) {
			$cached = ESF_YouTube_Cache::get_stale( $cache_key );
		}

		if ( ! is_array( $cached ) && function_exists( 'esf_youtube_videos_cache_legacy_keys' ) ) {
			$feed_id = isset( $feed_obj->id ) ? (int) $feed_obj->id : 0;
			foreach ( esf_youtube_videos_cache_legacy_keys( $feed_id, $account_id, $feed_type, $source_id ) as $legacy_key ) {
				$legacy = ESF_YouTube_Cache::get( $legacy_key );
				if ( ! is_array( $legacy ) ) {
					$legacy = ESF_YouTube_Cache::get_stale( $legacy_key );
				}
				if ( is_array( $legacy ) ) {
					$cached = $legacy;
					break;
				}
			}
		}

		if ( ! is_array( $cached ) ) {
			return false;
		}

		$unpacked = function_exists( 'esf_youtube_unpack_videos_cache' )
			? esf_youtube_unpack_videos_cache( $cached )
			: array(
				'items'         => $cached,
				'total_results' => null,
			);
		$items    = isset( $unpacked['items'] ) && is_array( $unpacked['items'] )
			? $unpacked['items']
			: array();

		return ! empty( $items );
	}

	/**
	 * Internal render implementation using SharedRenderer.
	 *
	 * @param int   $feed_id           Feed ID.
	 * @param array $settings_override Settings override.
	 * @param array $feed_overrides    Optional feed_type / source_id / account_id overrides.
	 * @return array{html:string,renderer_output:array,definition:?\EasySocialFeed\Layouts\ValueObjects\LayoutDefinition}
	 */
	private function render_internal( int $feed_id, array $settings_override, array $feed_overrides = array() ): array {
		self::ensure_bem_wrapper_filter();

		$empty = array(
			'html'            => '',
			'renderer_output' => array(
				'html'        => '',
				'assets'      => array(),
				'inline_css'  => '',
				'layout_slug' => '',
			),
			'definition'      => null,
		);

		if ( $feed_id <= 0 ) {
			return $empty;
		}

		$feed_repo = ESF_YouTube_Feed_Repository::get_instance();
		$feed_row  = $feed_repo->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return array(
				'html'            => $this->render_missing_feed( $feed_id ),
				'renderer_output' => $empty['renderer_output'],
				'definition'      => null,
			);
		}

		$saved_settings = json_decode( $feed_row->settings, true );
		$settings       = ESF_YouTube_Feed_Repository::merge_settings_with_defaults( is_array( $saved_settings ) ? $saved_settings : array() );
		if ( ! empty( $settings_override ) ) {
			$settings = ESF_YouTube_Feed_Repository::merge_settings_with_defaults( array_merge( $settings, $settings_override ) );
		}

		$feed_type  = isset( $feed_row->feed_type ) ? (string) $feed_row->feed_type : 'channel';
		$source_id  = isset( $feed_row->source_id ) ? (string) $feed_row->source_id : '';
		$account_id = (int) $feed_row->account_id;

		if ( isset( $feed_overrides['feed_type'] ) && is_string( $feed_overrides['feed_type'] ) ) {
			$override_type = sanitize_key( $feed_overrides['feed_type'] );
			if ( in_array( $override_type, array( 'channel', 'playlist', 'search' ), true ) ) {
				$feed_type = $override_type;
			}
		}
		if ( array_key_exists( 'source_id', $feed_overrides ) ) {
			$source_id = (string) $feed_overrides['source_id'];
		}
		if ( isset( $feed_overrides['account_id'] ) && (int) $feed_overrides['account_id'] > 0 ) {
			$account_id = (int) $feed_overrides['account_id'];
		}

		// Defense in depth: Pro feed types fall back to channel uploads without a plan.
		if (
			function_exists( 'esf_youtube_is_pro_feed_type' )
			&& esf_youtube_is_pro_feed_type( $feed_type )
			&& ( ! function_exists( 'esf_youtube_has_youtube_plan' ) || ! esf_youtube_has_youtube_plan() )
		) {
			$feed_type = 'channel';
			$source_id = '';
		}

		$feed_obj = (object) array(
			'id'         => $feed_row->id,
			'name'       => $feed_row->name,
			'account_id' => $account_id,
			'feed_type'  => $feed_type,
			'source_id'  => $source_id,
			'settings'   => $settings,
		);

		$account_repo        = ESF_YouTube_Account_Repository::get_instance();
		$account_row         = $account_repo->get_by_id( $feed_obj->account_id );
		$source_disconnected = class_exists( 'ESF_Feed_Disconnected_Cache' )
			? ESF_Feed_Disconnected_Cache::account_source_is_disconnected( $feed_row, $account_row )
			: ( $feed_obj->account_id > 0 && null === $account_row );
		$has_videos_cache    = $source_disconnected ? self::feed_has_videos_cache( $feed_obj ) : false;

		if ( is_object( $account_row ) ) {
			// Rebuild channel logo/banner after uploads/esf-youtube was cleared.
			$account_row = $account_repo->repair_channel_images_if_needed( $account_row );
		}

		$account = self::resolve_youtube_account( (int) $feed_obj->account_id, $account_row );
		if ( null === $account && ! $has_videos_cache ) {
			return $empty;
		}

		// Multifeed: hide the single-channel header (same UX as Facebook / Instagram).
		$is_multifeed = function_exists( 'esf_youtube_feed_is_multifeed' ) && esf_youtube_feed_is_multifeed( $feed_obj );
		if ( $is_multifeed ) {
			if ( ! isset( $feed_obj->settings ) || ! is_array( $feed_obj->settings ) ) {
				$feed_obj->settings = array();
			}
			if ( ! isset( $feed_obj->settings['header'] ) || ! is_array( $feed_obj->settings['header'] ) ) {
				$feed_obj->settings['header'] = array();
			}
			$feed_obj->settings['header']['show'] = false;
			$settings                             = $feed_obj->settings;
		}

		$this->last_search_total_results = null;

		// Fetch videos (unfiltered — moderate grid uses get_videos_for_feed_by_id()).
		$videos = $this->get_videos_for_feed( $feed_id, $feed_obj );
		if ( is_wp_error( $videos ) ) {
			$videos = array();
		}

		// Apply hide/show before Post mapping so every layout (including Half/Full
		// Width via AbstractSplit/StackedLayout) respects moderation — same seam as
		// Facebook/Twitter renderers and Load More.
		if ( function_exists( 'esf_youtube_apply_moderation_filter' ) ) {
			$videos = esf_youtube_apply_moderation_filter( $videos, $feed_id, $settings );
		}

		$has_local_cache = self::feed_has_videos_cache( $feed_obj );

		if ( null !== $this->last_search_total_results ) {
			$feed_obj->search_total_results = $this->last_search_total_results;
		}

		// Map videos to Post value objects (Multifeed: per-video AccountSummary).
		$posts         = array();
		$account_cache = array();
		if ( $is_multifeed ) {
			// Prefetch AccountSummaries for every Multifeed source (Facebook / Instagram pattern).
			foreach ( esf_youtube_resolve_account_ids( $feed_obj ) as $aid ) {
				$aid = (int) $aid;
				if ( $aid <= 0 || array_key_exists( $aid, $account_cache ) ) {
					continue;
				}
				$row                   = $account_repo->get_by_id( $aid );
				$account_cache[ $aid ] = self::resolve_youtube_account( $aid, $row );
			}
		}
		foreach ( $videos as $video ) {
			if ( ! is_array( $video ) ) {
				continue;
			}
			$node_account = function_exists( 'esf_youtube_account_for_video' )
				? esf_youtube_account_for_video( $video, $account, $account_cache )
				: $account;
			$post         = PostMapper::from_api_node( $video, $node_account );
			if ( null !== $post ) {
				$posts[] = $post;
			}
		}

		// Preview may force header on for client toggles — keep Multifeed hidden.
		$layout_overrides = $settings_override;
		if ( $is_multifeed ) {
			$layout_overrides = array_replace_recursive(
				is_array( $layout_overrides ) ? $layout_overrides : array(),
				array(
					'header' => array(
						'show' => false,
					),
				)
			);
		}

		// Use shared renderer
		$renderer_output = ( new SharedRenderer() )->render(
			'youtube',
			$feed_obj,
			$account,
			$posts,
			$layout_overrides
		);

		$definition = null;
		if ( '' !== $renderer_output['layout_slug'] ) {
			$registry   = \EasySocialFeed\Layouts\LayoutRegistry::for( 'youtube' );
			$definition = $registry->get( $renderer_output['layout_slug'] );
		}

		$notice            = null;
		$admin_notice_html = '';
		if ( $source_disconnected && $has_local_cache && class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
			$cache_pinned      = ESF_Feed_Disconnected_Cache::is_feed_cache_effectively_pinned(
				self::MODULE,
				(int) $feed_row->id,
				! empty( $settings['feed']['cache_pinned'] )
			);
			$admin_notice_html = ESF_Feed_Disconnected_Cache::get_admin_notice_html(
				self::MODULE,
				(int) $feed_row->id,
				array(
					'cache_pinned' => $cache_pinned,
				)
			);
			$notice            = ESF_Feed_Disconnected_Cache::get_notice_message( $cache_pinned );
		}

		return array(
			'html'              => $renderer_output['html'],
			'renderer_output'   => $renderer_output,
			'definition'        => $definition,
			'has_local_cache'   => $has_local_cache,
			'notice'            => $notice,
			'admin_notice_html' => $admin_notice_html,
		);
	}

	/**
	 * Get custom CSS for a feed from settings.
	 *
	 * Custom CSS is treated as trusted admin input and is output inside a
	 * <style> tag. It is trimmed and stripped of any existing <style> tags
	 * to avoid nested tags breaking markup.
	 *
	 * @since 6.7.5
	 *
	 * @param int   $feed_id  Feed ID for which CSS is generated.
	 * @param array $settings Normalized feed settings.
	 * @return string Custom CSS or empty string when none is defined.
	 */
	protected function get_custom_css_for_feed( $feed_id, $settings ) {
		if ( ! is_array( $settings ) ) {
			return '';
		}

		$style = isset( $settings['style'] ) && is_array( $settings['style'] ) ? $settings['style'] : array();
		$css   = isset( $style['custom_css'] ) ? (string) $style['custom_css'] : '';

		$css = trim( $css );
		if ( '' === $css ) {
			return '';
		}

		// Remove any existing <style> tags to prevent breaking the page markup.
		$css = preg_replace( '#</?style[^>]*>#i', '', $css );

		return $css;
	}

	/**
	 * Return mapped Post objects for a feed by ID (Social Wall / aggregation).
	 *
	 * @since 6.7.5
	 *
	 * @param int                 $feed_id Feed ID.
	 * @param array<string,mixed> $args    Optional: limit (int), media_context (preview|frontend), cache_only (bool).
	 * @return array<int,\EasySocialFeed\Layouts\ValueObjects\Post>
	 */
	public function get_posts_for_feed_by_id( $feed_id, $args = array() ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 || ! class_exists( 'ESF_YouTube_Feed_Repository' ) ) {
			return array();
		}

		$args       = is_array( $args ) ? $args : array();
		$cache_only = ! empty( $args['cache_only'] );

		$feed_repo = ESF_YouTube_Feed_Repository::get_instance();
		$feed_row  = $feed_repo->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return array();
		}

		$saved_settings = isset( $feed_row->settings ) ? json_decode( (string) $feed_row->settings, true ) : array();
		$settings       = ESF_YouTube_Feed_Repository::merge_settings_with_defaults( is_array( $saved_settings ) ? $saved_settings : array() );

		$feed_obj = (object) array(
			'id'         => (int) $feed_row->id,
			'name'       => isset( $feed_row->name ) ? (string) $feed_row->name : '',
			'account_id' => (int) $feed_row->account_id,
			'feed_type'  => isset( $feed_row->feed_type ) ? (string) $feed_row->feed_type : 'channel',
			'source_id'  => isset( $feed_row->source_id ) ? (string) $feed_row->source_id : '',
			'settings'   => $settings,
		);

		$account_repo        = ESF_YouTube_Account_Repository::get_instance();
		$account_row         = $account_repo->get_by_id( (int) $feed_obj->account_id );
		$source_disconnected = $feed_obj->account_id > 0 && null === $account_row;

		if ( ( $cache_only || $source_disconnected ) && ! self::feed_has_videos_cache( $feed_obj ) ) {
			return array();
		}

		$videos = $this->get_videos_for_feed( $feed_id, $feed_obj );
		if ( is_wp_error( $videos ) || ! is_array( $videos ) ) {
			return array();
		}

		if ( function_exists( 'esf_youtube_apply_moderation_filter' ) ) {
			$videos = esf_youtube_apply_moderation_filter( $videos, $feed_id, $settings );
		}

		$account       = self::resolve_youtube_account( (int) $feed_obj->account_id, $account_row );
		$is_multifeed  = function_exists( 'esf_youtube_feed_is_multifeed' ) && esf_youtube_feed_is_multifeed( $feed_obj );
		$posts         = array();
		$account_cache = array();

		if ( $is_multifeed && function_exists( 'esf_youtube_resolve_account_ids' ) ) {
			foreach ( esf_youtube_resolve_account_ids( $feed_obj ) as $aid ) {
				$aid = (int) $aid;
				if ( $aid <= 0 || array_key_exists( $aid, $account_cache ) ) {
					continue;
				}
				$row                   = $account_repo->get_by_id( $aid );
				$account_cache[ $aid ] = self::resolve_youtube_account( $aid, $row );
			}
		}

		foreach ( $videos as $video ) {
			if ( ! is_array( $video ) ) {
				continue;
			}
			$node_account = function_exists( 'esf_youtube_account_for_video' )
				? esf_youtube_account_for_video( $video, $account, $account_cache )
				: $account;
			$post         = PostMapper::from_api_node( $video, $node_account );
			if ( null !== $post ) {
				$posts[] = $post;
			}
		}

		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 0;
		if ( $limit > 0 && count( $posts ) > $limit ) {
			$posts = array_slice( $posts, 0, $limit );
		}

		return $posts;
	}

	/**
	 * Get videos for a feed by ID (from cache or API).
	 *
	 * Used by Load More REST endpoint. Returns raw list; caller applies moderation and slice.
	 *
	 * @since 6.7.5
	 * @param int $feed_id Feed ID.
	 * @return array|WP_Error Normalized video items or WP_Error on failure.
	 */
	public function get_videos_for_feed_by_id( $feed_id ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return array();
		}

		$feed_repo = ESF_YouTube_Feed_Repository::get_instance();
		$feed_row  = $feed_repo->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return array();
		}

		$feed_obj = (object) array(
			'id'         => $feed_row->id,
			'account_id' => (int) $feed_row->account_id,
			'feed_type'  => $feed_row->feed_type,
			'source_id'  => $feed_row->source_id,
		);

		$account_repo        = ESF_YouTube_Account_Repository::get_instance();
		$account             = $account_repo->get_by_id( $feed_obj->account_id );
		$source_disconnected = $feed_obj->account_id > 0 && null === $account;

		$saved_settings     = json_decode( $feed_row->settings, true );
		$feed_obj->settings = ESF_YouTube_Feed_Repository::merge_settings_with_defaults( is_array( $saved_settings ) ? $saved_settings : array() );

		if ( $source_disconnected && ! self::feed_has_videos_cache( $feed_obj ) ) {
			return array();
		}

		$videos = $this->get_videos_for_feed( $feed_id, $feed_obj );
		if ( is_wp_error( $videos ) ) {
			return array();
		}

		return is_array( $videos ) ? $videos : array();
	}

	/**
	 * Force-refresh one feed's video cache from the YouTube API.
	 *
	 * Bypasses warm cache. Existing rows are kept when the API errors or
	 * returns an empty payload.
	 *
	 * @since 6.8.2
	 *
	 * @param int $feed_id Feed ID.
	 * @return true|WP_Error
	 */
	public function force_refresh_feed_videos( $feed_id ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return new WP_Error(
				'invalid_feed',
				__( 'Invalid YouTube feed.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$feed_repo = ESF_YouTube_Feed_Repository::get_instance();
		$feed_row  = $feed_repo->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return new WP_Error(
				'feed_not_found',
				__( 'YouTube feed not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$feed_obj = (object) array(
			'id'         => $feed_row->id,
			'account_id' => (int) $feed_row->account_id,
			'feed_type'  => $feed_row->feed_type,
			'source_id'  => $feed_row->source_id,
		);

		$saved_settings     = json_decode( $feed_row->settings, true );
		$feed_obj->settings = ESF_YouTube_Feed_Repository::merge_settings_with_defaults( is_array( $saved_settings ) ? $saved_settings : array() );

		$videos = $this->get_videos_for_single_account( $feed_id, $feed_obj, null, true );
		if ( is_wp_error( $videos ) ) {
			return $videos;
		}

		if ( ! is_array( $videos ) || empty( $videos ) ) {
			return new WP_Error(
				'empty_videos',
				__( 'No YouTube videos were returned. Existing cache was kept.', 'easy-facebook-likebox' ),
				array( 'status' => 502 )
			);
		}

		return true;
	}

	/**
	 * Get videos for a feed (from integrator payload, cache, or API).
	 *
	 * Pipeline:
	 *   1. Allow integrators to short-circuit via `esf_youtube_render_videos_payload`.
	 *   2. When no override, read from {@see ESF_YouTube_Cache} or fetch via API.
	 *
	 * Cache key includes feed_id and account_id so that when the feed's linked
	 * account is changed, we fetch the new account's videos instead of
	 * returning the previous account's cached list.
	 *
	 * @since 6.7.5
	 *
	 * @param int         $feed_id      Feed ID (legacy cache fallback + logging).
	 * @param object      $feed_obj     Feed object with feed_type, source_id, account_id.
	 * @param string|null $access_token Optional valid access token. Resolved internally when omitted.
	 * @return array|WP_Error Normalized video items or WP_Error on failure.
	 */
	protected function get_videos_for_feed( $feed_id, $feed_obj, $access_token = null ) {
		$preset = array(
			'videos'          => array(),
			'has_local_cache' => false,
		);

		/**
		 * Filter the YouTube video payload before cache/API fetch.
		 *
		 * Returning videos short-circuits the cache and API fetch. This is the
		 * canonical hook for tests and third-party integrations.
		 *
		 * Listeners may return either:
		 *   - `array{videos:array,has_local_cache?:bool}` (preferred shape), or
		 *   - a flat `array<int, array>` of normalized video items.
		 *
		 * @since 6.7.8
		 *
		 * @param array  $value    Default empty payload.
		 * @param int    $feed_id  Feed ID.
		 * @param object $feed_obj Feed object.
		 */
		$payload = apply_filters( 'esf_youtube_render_videos_payload', $preset, (int) $feed_id, $feed_obj );

		if ( function_exists( 'esf_resolve_render_payload_override' ) ) {
			$override = esf_resolve_render_payload_override( $payload, 'videos' );
			if ( $override['short_circuited'] ) {
				return $override['items'];
			}
		}

		// Multifeed: resolve all account IDs and merge results.
		$account_ids = function_exists( 'esf_youtube_resolve_account_ids' )
			? esf_youtube_resolve_account_ids( $feed_obj )
			: array( isset( $feed_obj->account_id ) ? (int) $feed_obj->account_id : 0 );

		$is_multifeed = count( $account_ids ) > 1;
		if ( $is_multifeed ) {
			return $this->get_videos_for_multifeed( $feed_id, $feed_obj, $account_ids, $access_token );
		}

		// Single account: delegate to single-account method.
		return $this->get_videos_for_single_account( $feed_id, $feed_obj, $access_token );
	}

	/**
	 * Fetch and merge videos from multiple accounts (Multifeed).
	 *
	 * @since 6.14.0
	 *
	 * @param int         $feed_id      Feed ID.
	 * @param object      $feed_obj     Feed object.
	 * @param int[]       $account_ids  Array of account IDs.
	 * @param string|null $access_token Optional access token.
	 * @return array|WP_Error Merged and sorted video items.
	 */
	protected function get_videos_for_multifeed( $feed_id, $feed_obj, array $account_ids, $access_token = null ) {
		$settings      = isset( $feed_obj->settings ) && is_array( $feed_obj->settings ) ? $feed_obj->settings : array();
		$feed_cfg_sort = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();

		// Resolve sort mode.
		$sort_mode = function_exists( 'esf_youtube_resolve_multifeed_sort' )
			? esf_youtube_resolve_multifeed_sort( $feed_cfg_sort )
			: 'newest';

		$merged = array();

		foreach ( $account_ids as $account_id ) {
			$account_id = (int) $account_id;
			if ( $account_id <= 0 ) {
				continue;
			}

			// Create a temporary feed object for this account.
			$single_feed             = clone $feed_obj;
			$single_feed->account_id = $account_id;

			// Fetch videos for this account (will use cache if available).
			$videos = $this->get_videos_for_single_account( $feed_id, $single_feed, $access_token );
			if ( is_wp_error( $videos ) ) {
				continue;
			}

			// Stamp each video with source account_id for balanced sorting / authors.
			$stamped = array();
			foreach ( $videos as $video ) {
				if ( ! is_array( $video ) ) {
					continue;
				}
				$video['_esf_account_id'] = $account_id;
				$stamped[]                = $video;
			}

			$merged = array_merge( $merged, $stamped );
		}

		// Sort merged results by mode.
		if ( function_exists( 'esf_youtube_sort_multifeed_nodes' ) ) {
			$merged = esf_youtube_sort_multifeed_nodes( $merged, $sort_mode );
		}

		return $merged;
	}

	/**
	 * Fetch videos for a single account (called by both single-feed and multifeed paths).
	 *
	 * This method extracts the core video-fetching logic so both render paths
	 * (single account and multifeed) can reuse the same cache/API fetch flow.
	 *
	 * @since 6.14.0
	 *
	 * @param int         $feed_id       Feed ID.
	 * @param object      $feed_obj      Feed object.
	 * @param string|null $access_token  Optional access token.
	 * @param bool        $force_refresh When true, bypass warm cache and skip empty writes.
	 * @return array|WP_Error Video items or WP_Error.
	 */
	protected function get_videos_for_single_account( $feed_id, $feed_obj, $access_token = null, $force_refresh = false ) {
		$account_id = isset( $feed_obj->account_id ) ? (int) $feed_obj->account_id : 0;
		$feed_type  = isset( $feed_obj->feed_type ) ? (string) $feed_obj->feed_type : 'channel';
		$source_id  = isset( $feed_obj->source_id ) ? trim( (string) $feed_obj->source_id ) : '';

		if (
			function_exists( 'esf_youtube_is_pro_feed_type' )
			&& esf_youtube_is_pro_feed_type( $feed_type )
			&& ( ! function_exists( 'esf_youtube_has_youtube_plan' ) || ! esf_youtube_has_youtube_plan() )
		) {
			$feed_type = 'channel';
			$source_id = '';
		}

		$settings       = ( isset( $feed_obj->settings ) && is_array( $feed_obj->settings ) )
			? $feed_obj->settings
			: array();
		$content_filter = function_exists( 'esf_youtube_content_filter_from_settings' )
			? esf_youtube_content_filter_from_settings( $settings )
			: 'all';

		if (
			function_exists( 'esf_youtube_is_pro_content_filter' )
			&& esf_youtube_is_pro_content_filter( $content_filter )
			&& ( ! function_exists( 'esf_youtube_has_youtube_plan' ) || ! esf_youtube_has_youtube_plan() )
		) {
			$content_filter = 'all';
		}

		$channel_id  = '';
		$account_row = null;
		if ( $account_id > 0 && class_exists( 'ESF_YouTube_Account_Repository' ) ) {
			$account_row = ESF_YouTube_Account_Repository::get_instance()->get_by_id( $account_id );
			$channel_id  = ( $account_row && isset( $account_row->channel_id ) )
				? (string) $account_row->channel_id
				: '';
		}
		$source_disconnected = $account_id > 0 && null === $account_row;
		if ( $source_disconnected && class_exists( 'ESF_YouTube_Cache' ) ) {
			$snapshot = ESF_YouTube_Cache::get_account_profile_snapshot( $account_id );
			if ( is_array( $snapshot ) && ! empty( $snapshot['channel_id'] ) ) {
				$channel_id = (string) $snapshot['channel_id'];
			}
		}

		$shorts_playlist = false;
		if ( function_exists( 'esf_youtube_resolve_videos_source' ) ) {
			$resolved        = esf_youtube_resolve_videos_source(
				$feed_type,
				$source_id,
				$content_filter,
				$channel_id
			);
			$feed_type       = $resolved['feed_type'];
			$source_id       = $resolved['source_id'];
			$content_filter  = $resolved['content_filter'];
			$shorts_playlist = ! empty( $resolved['shorts_playlist'] );
		}

		// Account-scoped cache key; playlist/search include type + source.
		// Channel + Shorts-only uses the UUSH playlist key (via resolve above).
		$cache_key   = function_exists( 'esf_youtube_videos_cache_key' )
			? esf_youtube_videos_cache_key( $account_id, $feed_type, $source_id )
			: 'esf_yt_videos_' . $account_id;
		$cached      = null;
		$from_legacy = false;

		if ( ! $force_refresh ) {
			$cached = '' !== $cache_key ? ESF_YouTube_Cache::get( $cache_key ) : null;

			if ( ! is_array( $cached ) && method_exists( 'ESF_YouTube_Cache', 'get_stale' ) && '' !== $cache_key ) {
				$cached = ESF_YouTube_Cache::get_stale( $cache_key );
			}

			// One-time fallback to older feed-scoped keys, then migrate on write.
			if ( ! is_array( $cached ) && function_exists( 'esf_youtube_videos_cache_legacy_keys' ) ) {
				foreach ( esf_youtube_videos_cache_legacy_keys( $feed_id, $account_id, $feed_type, $source_id ) as $legacy_key ) {
					$legacy = ESF_YouTube_Cache::get( $legacy_key );
					if ( ! is_array( $legacy ) && method_exists( 'ESF_YouTube_Cache', 'get_stale' ) ) {
						$legacy = ESF_YouTube_Cache::get_stale( $legacy_key );
					}
					if ( is_array( $legacy ) ) {
						$cached      = $legacy;
						$from_legacy = true;
						break;
					}
				}
			}
		}

		if ( ! $force_refresh && is_array( $cached ) ) {
			$unpacked = function_exists( 'esf_youtube_unpack_videos_cache' )
				? esf_youtube_unpack_videos_cache( $cached )
				: array(
					'items'         => $cached,
					'total_results' => null,
				);
			$items    = isset( $unpacked['items'] ) && is_array( $unpacked['items'] )
				? $unpacked['items']
				: array();

			if ( 'search' === $feed_type && null !== $unpacked['total_results'] ) {
				$this->last_search_total_results = (int) $unpacked['total_results'];
			}

			// Legacy caches stored localized upload URLs. Flush them so we can
			// re-fetch remote CDN URLs after uploads are deleted or variants change.
			// When the account is disconnected we cannot re-fetch — keep serving
			// the cached items (same disconnect fallback as Instagram / X).
			if ( $this->videos_cache_contains_localized_urls( $items ) ) {
				if ( $source_disconnected ) {
					return $this->finalize_feed_videos(
						$items,
						$account_id,
						$content_filter,
						$shorts_playlist,
						$channel_id,
						$access_token
					);
				}
				if ( method_exists( 'ESF_YouTube_Cache', 'flush_videos_cache_for_source' ) ) {
					ESF_YouTube_Cache::flush_videos_cache_for_source(
						$account_id,
						$feed_type,
						$source_id,
						(int) $feed_id
					);
				} else {
					ESF_YouTube_Cache::flush_feed_cache( (int) $feed_id );
				}
			} elseif ( $this->videos_cache_missing_duration( $items ) ) {
				// Pre-duration caches: enrich via videos.list (stats + contentDetails)
				// then re-store so duration badges appear without a full playlist refetch.
				$items = $this->backfill_cached_video_duration( $items, $account_id, $access_token );
				if ( is_array( $items ) && ! $this->videos_cache_missing_duration( $items ) ) {
					$ttl = (int) esf_get_youtube_settings( 'cache_duration' );
					if ( $ttl <= 0 ) {
						$ttl = 43200;
					}
					if ( '' !== $cache_key ) {
						$to_store = ( 'search' === $feed_type && function_exists( 'esf_youtube_pack_videos_cache' ) )
							? esf_youtube_pack_videos_cache( $items, $this->last_search_total_results )
							: $items;
						ESF_YouTube_Cache::set( $cache_key, $to_store, $ttl, 'api', null, $account_id );
					}
				}
				return $this->finalize_feed_videos(
					is_array( $items ) ? $items : array(),
					$account_id,
					$content_filter,
					$shorts_playlist,
					$channel_id,
					$access_token
				);
			} else {
				if ( $from_legacy && '' !== $cache_key ) {
					$ttl = (int) esf_get_youtube_settings( 'cache_duration' );
					if ( $ttl <= 0 ) {
						$ttl = 43200;
					}
					$to_store = ( 'search' === $feed_type && function_exists( 'esf_youtube_pack_videos_cache' ) )
						? esf_youtube_pack_videos_cache( $items, $this->last_search_total_results )
						: $items;
					ESF_YouTube_Cache::set( $cache_key, $to_store, $ttl, 'api', null, $account_id );
				}
				return $this->finalize_feed_videos(
					$items,
					$account_id,
					$content_filter,
					$shorts_playlist,
					$channel_id,
					$access_token
				);
			}
		}

		if ( $source_disconnected ) {
			return array();
		}

		if ( null === $access_token || '' === $access_token ) {
			$token_manager = ESF_YouTube_Token_Manager::get_instance();
			$access_token  = $token_manager->get_valid_access_token( $account_id );
		}
		if ( ! $access_token ) {
			return array();
		}

		$api_service  = ESF_YouTube_API_Service::get_instance();
		$max_results  = self::MAX_VIDEO_FETCH;
		$search_total = null;

		switch ( $feed_type ) {
			case 'channel':
				$playlist_id = $api_service->get_uploads_playlist_id( $access_token );
				if ( is_wp_error( $playlist_id ) ) {
					return $playlist_id;
				}
				$videos = $api_service->fetch_playlist_videos( $access_token, $playlist_id, $max_results );
				break;
			case 'playlist':
				if ( '' === $source_id ) {
					return array();
				}
				$videos = $api_service->fetch_playlist_videos( $access_token, $source_id, $max_results );
				break;
			case 'search':
				$search_result = $api_service->fetch_search_videos(
					$access_token,
					'' !== $source_id ? $source_id : ' ',
					$max_results,
					$channel_id
				);
				if ( is_wp_error( $search_result ) ) {
					$videos = $search_result;
				} else {
					$videos                          = isset( $search_result['items'] ) && is_array( $search_result['items'] )
						? $search_result['items']
						: array();
					$search_total                    = isset( $search_result['total_results'] )
						? max( 0, (int) $search_result['total_results'] )
						: count( $videos );
					$this->last_search_total_results = $search_total;
				}
				break;
			default:
				$playlist_id = $api_service->get_uploads_playlist_id( $access_token );
				if ( is_wp_error( $playlist_id ) ) {
					return $playlist_id;
				}
				$videos = $api_service->fetch_playlist_videos( $access_token, $playlist_id, $max_results );
				break;
		}

		if ( is_wp_error( $videos ) ) {
			return $videos;
		}

		$videos = $this->finalize_feed_videos(
			is_array( $videos ) ? $videos : array(),
			$account_id,
			$content_filter,
			$shorts_playlist,
			$channel_id,
			$access_token
		);

		if ( $force_refresh && empty( $videos ) ) {
			return new WP_Error(
				'empty_videos',
				__( 'No YouTube videos were returned. Existing cache was kept.', 'easy-facebook-likebox' ),
				array( 'status' => 502 )
			);
		}

		// Cache the results.
		$ttl = (int) esf_get_youtube_settings( 'cache_duration' );
		if ( $ttl <= 0 ) {
			$ttl = 43200;
		}
		if ( '' !== $cache_key ) {
			$to_store = ( 'search' === $feed_type && function_exists( 'esf_youtube_pack_videos_cache' ) )
				? esf_youtube_pack_videos_cache( $videos, $search_total )
				: $videos;
			ESF_YouTube_Cache::set( $cache_key, $to_store, $ttl, 'api', null, $account_id );
		}

		return $videos;
	}

	/**
	 * Localize thumbnails and apply Pro content_filter (UUSH Shorts IDs when possible).
	 *
	 * @since 6.7.6
	 *
	 * @param array       $videos          Normalized videos.
	 * @param int         $account_id      Account ID.
	 * @param string      $content_filter  all|shorts|long.
	 * @param bool        $shorts_playlist True when videos already come from UUSH.
	 * @param string      $channel_id      Channel ID.
	 * @param string|null $access_token    Optional token for Shorts ID fetch.
	 * @return array
	 */
	protected function finalize_feed_videos( $videos, $account_id, $content_filter, $shorts_playlist, $channel_id, $access_token = null ) {
		$videos = $this->ensure_local_thumbnails_for_videos(
			is_array( $videos ) ? $videos : array(),
			$account_id
		);

		if ( $shorts_playlist ) {
			$lookup = function_exists( 'esf_youtube_video_ids_lookup' )
				? esf_youtube_video_ids_lookup( $videos )
				: array();
			$this->remember_short_video_ids( $account_id, $lookup );
			return function_exists( 'esf_youtube_mark_short_videos' )
				? esf_youtube_mark_short_videos( $videos, $lookup, true )
				: $videos;
		}

		$content_filter = function_exists( 'esf_youtube_normalize_content_filter' )
			? esf_youtube_normalize_content_filter( $content_filter )
			: 'all';

		$short_ids = null;
		if (
			'all' !== $content_filter
			&& function_exists( 'esf_youtube_is_pro_content_filter' )
			&& esf_youtube_is_pro_content_filter( $content_filter )
			&& function_exists( 'esf_youtube_apply_content_filter' )
		) {
			$short_ids = $this->get_channel_short_video_ids( $account_id, $channel_id, $access_token );
			$videos    = esf_youtube_apply_content_filter( $videos, $content_filter, $short_ids );
		} else {
			$short_ids = $this->read_cached_short_video_ids( $account_id );
		}

		return function_exists( 'esf_youtube_mark_short_videos' )
			? esf_youtube_mark_short_videos( $videos, $short_ids, 'shorts' === $content_filter )
			: $videos;
	}

	/**
	 * Persist Shorts video-ID lookup for an account.
	 *
	 * @since 6.7.6
	 *
	 * @param int                $account_id Account ID.
	 * @param array<string,bool> $lookup    Video ID map.
	 * @return void
	 */
	protected function remember_short_video_ids( $account_id, $lookup ) {
		if ( ! is_array( $lookup ) || ! function_exists( 'esf_youtube_shorts_ids_cache_key' ) ) {
			return;
		}

		$key = esf_youtube_shorts_ids_cache_key( $account_id );
		if ( '' === $key ) {
			return;
		}

		$ttl = (int) esf_get_youtube_settings( 'cache_duration' );
		if ( $ttl <= 0 ) {
			$ttl = 43200;
		}

		ESF_YouTube_Cache::set(
			$key,
			array(
				'ids' => array_keys( $lookup ),
			),
			$ttl,
			'api',
			null,
			(int) $account_id
		);
	}

	/**
	 * Read cached UUSH Shorts IDs without hitting the YouTube API.
	 *
	 * @since 6.16.0
	 *
	 * @param int $account_id Account ID.
	 * @return array<string,bool>|null
	 */
	protected function read_cached_short_video_ids( $account_id ) {
		$account_id = (int) $account_id;
		if ( $account_id <= 0 || ! function_exists( 'esf_youtube_shorts_ids_cache_key' ) ) {
			return null;
		}

		$key = esf_youtube_shorts_ids_cache_key( $account_id );
		if ( '' === $key ) {
			return null;
		}

		$cached = ESF_YouTube_Cache::get( $key );
		if ( ! is_array( $cached ) && method_exists( 'ESF_YouTube_Cache', 'get_stale' ) ) {
			$cached = ESF_YouTube_Cache::get_stale( $key );
		}
		if ( ! is_array( $cached ) || ! isset( $cached['ids'] ) || ! is_array( $cached['ids'] ) ) {
			return null;
		}

		$lookup = array();
		foreach ( $cached['ids'] as $id ) {
			$id = trim( (string) $id );
			if ( '' !== $id ) {
				$lookup[ $id ] = true;
			}
		}

		return $lookup;
	}

	/**
	 * Load official Shorts video IDs from the channel UUSH playlist (cached).
	 *
	 * Returns null when the Shorts playlist cannot be resolved/fetched so callers
	 * can fall back to the #shorts /shorts/ heuristic.
	 *
	 * @since 6.7.6
	 *
	 * @param int         $account_id   Account ID.
	 * @param string      $channel_id   Channel ID (UC…).
	 * @param string|null $access_token Optional access token.
	 * @return array<string,bool>|null
	 */
	protected function get_channel_short_video_ids( $account_id, $channel_id, $access_token = null ) {
		$account_id = (int) $account_id;
		$channel_id = trim( (string) $channel_id );
		if ( $account_id <= 0 || '' === $channel_id ) {
			return null;
		}

		$cached = $this->read_cached_short_video_ids( $account_id );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$shorts_pl = function_exists( 'esf_youtube_channel_shorts_playlist_id' )
			? esf_youtube_channel_shorts_playlist_id( $channel_id )
			: '';
		if ( '' === $shorts_pl ) {
			return null;
		}

		// Reuse a cached UUSH videos row when a Shorts-only feed already warmed it.
		$videos_key = function_exists( 'esf_youtube_videos_cache_key' )
			? esf_youtube_videos_cache_key( $account_id, 'playlist', $shorts_pl )
			: '';
		if ( '' !== $videos_key ) {
			$cached_videos = ESF_YouTube_Cache::get( $videos_key );
			if ( ! is_array( $cached_videos ) && method_exists( 'ESF_YouTube_Cache', 'get_stale' ) ) {
				$cached_videos = ESF_YouTube_Cache::get_stale( $videos_key );
			}
			if ( is_array( $cached_videos ) ) {
				$unpacked = function_exists( 'esf_youtube_unpack_videos_cache' )
					? esf_youtube_unpack_videos_cache( $cached_videos )
					: array( 'items' => $cached_videos );
				$items    = isset( $unpacked['items'] ) && is_array( $unpacked['items'] )
					? $unpacked['items']
					: array();
				$lookup   = function_exists( 'esf_youtube_video_ids_lookup' )
					? esf_youtube_video_ids_lookup( $items )
					: array();
				$this->remember_short_video_ids( $account_id, $lookup );
				return $lookup;
			}
		}

		if ( null === $access_token || '' === $access_token ) {
			$token_manager = ESF_YouTube_Token_Manager::get_instance();
			$access_token  = $token_manager->get_valid_access_token( $account_id );
		}
		if ( ! $access_token ) {
			return null;
		}

		$api_service = ESF_YouTube_API_Service::get_instance();
		$videos      = $api_service->fetch_playlist_videos(
			$access_token,
			$shorts_pl,
			self::MAX_VIDEO_FETCH
		);
		if ( is_wp_error( $videos ) || ! is_array( $videos ) ) {
			return null;
		}

		$ttl = (int) esf_get_youtube_settings( 'cache_duration' );
		if ( $ttl <= 0 ) {
			$ttl = 43200;
		}
		if ( '' !== $videos_key ) {
			ESF_YouTube_Cache::set( $videos_key, $videos, $ttl, 'api', null, $account_id );
		}

		$lookup = function_exists( 'esf_youtube_video_ids_lookup' )
			? esf_youtube_video_ids_lookup( $videos )
			: array();
		$this->remember_short_video_ids( $account_id, $lookup );
		return $lookup;
	}

	/**
	 * Localize video thumbnails for frontend display (public wrapper for load-more).
	 *
	 * @since 6.9.5
	 * @param array $videos     Normalized video items.
	 * @param int   $account_id Internal account id.
	 * @return array
	 */
	public function localize_videos_for_display( $videos, $account_id = 0 ) {
		return $this->ensure_local_thumbnails_for_videos( $videos, $account_id );
	}

	/**
	 * Detect caches that pre-date duration enrichment.
	 *
	 * @since 6.7.5
	 * @param array $videos Cached video items.
	 * @return bool True when no item carries a duration key.
	 */
	protected function videos_cache_missing_duration( array $videos ) {
		if ( empty( $videos ) ) {
			return false;
		}

		foreach ( $videos as $video ) {
			if ( ! is_array( $video ) ) {
				continue;
			}
			if ( array_key_exists( 'duration', $video ) || array_key_exists( 'duration_formatted', $video ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Backfill duration (and refresh stats) on cached videos via videos.list.
	 *
	 * @since 6.7.5
	 * @param array       $videos       Cached video items.
	 * @param int         $account_id   Account ID.
	 * @param string|null $access_token Optional token; resolved when empty.
	 * @return array Enriched videos (or original on failure).
	 */
	protected function backfill_cached_video_duration( array $videos, $account_id = 0, $access_token = null ) {
		if ( null === $access_token || '' === $access_token ) {
			$token_manager = ESF_YouTube_Token_Manager::get_instance();
			$access_token  = $token_manager->get_valid_access_token( (int) $account_id );
		}
		if ( ! $access_token ) {
			return $videos;
		}

		$api_service = ESF_YouTube_API_Service::get_instance();
		if ( ! method_exists( $api_service, 'apply_videos_statistics' ) ) {
			return $videos;
		}

		$enriched = $api_service->apply_videos_statistics( $access_token, $videos );
		return is_array( $enriched ) ? $enriched : $videos;
	}

	/**
	 * Detect legacy video caches that stored localized upload URLs.
	 *
	 * @since 6.9.5
	 * @param array $videos Cached video items.
	 * @return bool
	 */
	protected function videos_cache_contains_localized_urls( array $videos ) {
		if ( ! function_exists( 'esf_is_local_media_url' ) ) {
			return false;
		}

		foreach ( $videos as $video ) {
			if ( ! is_array( $video ) ) {
				continue;
			}
			$thumb = isset( $video['thumbnail_url'] ) ? (string) $video['thumbnail_url'] : '';
			if ( '' !== $thumb && esf_is_local_media_url( $thumb, 'youtube' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve a remote YouTube thumbnail URL (never a local upload URL).
	 *
	 * When the cached thumb is a dead local path, fall back to the standard
	 * i.ytimg.com URL derived from the video id.
	 *
	 * @since 6.9.5
	 * @param array  $video    Video item.
	 * @param string $video_id YouTube video id.
	 * @return string
	 */
	protected function resolve_youtube_thumbnail_remote_url( array $video, $video_id ) {
		$candidate = isset( $video['thumbnail_url'] ) ? (string) $video['thumbnail_url'] : '';
		if ( '' !== $candidate ) {
			if ( ! function_exists( 'esf_is_local_media_url' ) || ! esf_is_local_media_url( $candidate, 'youtube' ) ) {
				return $candidate;
			}
		}

		$video_id = (string) $video_id;
		if ( '' === $video_id ) {
			return '';
		}

		return 'https://i.ytimg.com/vi/' . rawurlencode( $video_id ) . '/hqdefault.jpg';
	}

	/**
	 * Ensure video thumbnails are served via local media when possible.
	 *
	 * Uses the core esf_serve_media_locally helper (also used for YouTube
	 * account thumbnails) so that thumbnails are downloaded to the
	 * uploads/esf-youtube directory and reused from there.
	 *
	 * @since 6.7.5
	 *
	 * @param array $videos     Normalized video items.
	 * @param int   $account_id Internal account id for uploads/esf-youtube/{id}/.
	 * @return array Videos with thumbnail_url pointing to a local URL when available.
	 */
	protected function ensure_local_thumbnails_for_videos( $videos, $account_id = 0 ) {
		$account_id = function_exists( 'esf_normalize_local_media_account_id' )
			? esf_normalize_local_media_account_id( $account_id )
			: max( 0, (int) $account_id );
		if ( ! is_array( $videos ) || empty( $videos ) ) {
			return $videos;
		}

		if ( ! function_exists( 'esf_serve_media_locally' ) ) {
			return $videos;
		}

		$use_variants = class_exists( 'ESF_YouTube_Local_Media' )
			&& ESF_YouTube_Local_Media::should_generate_grid_variants();
		$thumb_width  = $use_variants
			? ESF_YouTube_Local_Media::get_grid_thumb_width()
			: 0;

		foreach ( $videos as $index => $video ) {
			if ( ! is_array( $video ) ) {
				continue;
			}

			$video_id = isset( $video['video_id'] ) ? (string) $video['video_id'] : ( isset( $video['id'] ) ? (string) $video['id'] : '' );
			if ( '' === $video_id ) {
				continue;
			}

			$remote_url = $this->resolve_youtube_thumbnail_remote_url( $video, $video_id );
			if ( '' === $remote_url ) {
				continue;
			}

			$local_url = esf_serve_media_locally( $video_id, $remote_url, 'youtube', $account_id );
			if ( ! is_string( $local_url ) || '' === $local_url ) {
				// Fall back to remote CDN when local download fails.
				$videos[ $index ]['thumbnail_url'] = $remote_url;
				continue;
			}

			// Keep full-size for lightbox cover / blocked-embed fallbacks.
			$videos[ $index ]['thumbnail_url_full'] = $local_url;

			$feed_url = $local_url;
			if ( $use_variants && $thumb_width > 0 && function_exists( 'esf_ensure_local_media_variant' ) ) {
				$variant = esf_ensure_local_media_variant( $video_id, 'youtube', $thumb_width, $account_id );
				if ( is_string( $variant ) && '' !== $variant ) {
					$feed_url = $variant;
				}
			}

			// Feed cards use the smaller variant when available.
			$videos[ $index ]['thumbnail_url'] = $feed_url;
		}

		return $videos;
	}

	/**
	 * Enqueue base feed CSS and layout-specific CSS/JS.
	 *
	 * @since 6.7.5
	 *
	 * @param ESF_YouTube_Layout_Base $layout Layout instance.
	 * @return void
	 */
	protected function enqueue_layout_assets( $layout ) {
		$base_handle = 'esf-youtube-feed';
		$base_url    = ESF_YOUTUBE_URL . 'frontend/assets/css/esf-youtube-feed.css';
		$base_path   = ESF_YOUTUBE_DIR . 'frontend/assets/css/esf-youtube-feed.css';

		if ( file_exists( $base_path ) ) {
			wp_enqueue_style(
				$base_handle,
				$base_url,
				array(),
				(string) filemtime( $base_path )
			);
		}

		$layout_handle = $layout->get_css_handle();
		$layout_slug   = str_replace( 'esf-youtube-layout-', '', $layout_handle );
		$layout_url    = ESF_YOUTUBE_URL . 'frontend/assets/css/layouts/' . $layout_slug . '.css';
		$layout_path   = ESF_YOUTUBE_DIR . 'frontend/assets/css/layouts/' . $layout_slug . '.css';
		if ( function_exists( 'esf_locate_premium_only_asset' ) ) {
			$located = esf_locate_premium_only_asset(
				ESF_YOUTUBE_DIR . 'frontend/assets/css/layouts',
				ESF_YOUTUBE_URL . 'frontend/assets/css/layouts',
				$layout_slug,
				'css'
			);
			if ( '' !== $located['path'] ) {
				$layout_path = $located['path'];
				$layout_url  = $located['url'];
			}
		}

		if ( file_exists( $layout_path ) ) {
			wp_enqueue_style(
				$layout_handle,
				$layout_url,
				array( $base_handle ),
				(string) filemtime( $layout_path )
			);
		}

		$js_handle = $layout->get_js_handle();
		if ( $js_handle ) {
			$js_src = method_exists( $layout, 'get_js_src' ) ? (string) $layout->get_js_src() : '';
			if ( '' !== $js_src ) {
				$js_path = '';
				$js_deps = array();

				// Prefer the actual layout script for filemtime (carousel / masonry / etc.).
				if ( defined( 'FTA_PLUGIN_DIR' ) && false !== strpos( $js_src, 'admin/shared/frontend/js/' ) ) {
					$relative  = substr( $js_src, strpos( $js_src, 'admin/shared/frontend/js/' ) );
					$candidate = FTA_PLUGIN_DIR . $relative;
					if ( file_exists( $candidate ) ) {
						$js_path = $candidate;
					}
				}
				if ( '' === $js_path && defined( 'ESF_YOUTUBE_DIR' ) && false !== strpos( $js_src, 'esf-youtube-' ) ) {
					$candidate = ESF_YOUTUBE_DIR . 'frontend/assets/js/' . basename( parse_url( $js_src, PHP_URL_PATH ) );
					if ( file_exists( $candidate ) ) {
						$js_path = $candidate;
					}
				}
				if ( '' === $js_path && defined( 'FTA_PLUGIN_DIR' ) ) {
					$candidate = FTA_PLUGIN_DIR . 'admin/shared/frontend/js/esf-carousel__premium_only.js';
					if ( file_exists( $candidate ) && false !== strpos( $js_src, 'esf-carousel__premium_only.js' ) ) {
						$js_path = $candidate;
					}
				}

				if ( false !== strpos( (string) $js_handle, 'masonry' ) && function_exists( 'esf_register_masonry_pack_script' ) ) {
					esf_register_masonry_pack_script();
					$js_deps[] = 'esf-masonry-pack';
				}

				wp_enqueue_script(
					$js_handle,
					$js_src,
					$js_deps,
					'' !== $js_path ? (string) filemtime( $js_path ) : '1.0.0',
					true
				);
			} else {
				$js_slug = str_replace( 'esf-youtube-layout-', '', $js_handle );
				$js_url  = ESF_YOUTUBE_URL . 'frontend/assets/js/layouts/' . $js_slug . '.js';
				$js_path = ESF_YOUTUBE_DIR . 'frontend/assets/js/layouts/' . $js_slug . '.js';
				if ( file_exists( $js_path ) ) {
					wp_enqueue_script(
						$js_handle,
						$js_url,
						array(),
						(string) filemtime( $js_path ),
						true
					);
				}
			}
		}
	}

	/**
	 * Get the URL for the base feed CSS (for preview endpoint).
	 *
	 * @since 6.7.5
	 * @return string URL or empty string.
	 */
	public function get_base_css_url() {
		$path = ESF_YOUTUBE_DIR . 'frontend/assets/css/esf-youtube-feed.css';

		return file_exists( $path ) ? ESF_YOUTUBE_URL . 'frontend/assets/css/esf-youtube-feed.css' : '';
	}

	/**
	 * Get the URL for a layout's CSS (for preview endpoint).
	 *
	 * @since 6.7.5
	 *
	 * @param string $layout_type Layout type (e.g. 'grid').
	 * @return string URL or empty string.
	 */
	public function get_layout_css_url( $layout_type ) {
		$layout_type = is_string( $layout_type ) ? trim( $layout_type ) : 'grid';
		// Asset filenames use hyphens; layout slugs use underscores (half_width).
		$file = str_replace( '_', '-', $layout_type );
		if ( function_exists( 'esf_locate_premium_only_asset' ) ) {
			$located = esf_locate_premium_only_asset(
				ESF_YOUTUBE_DIR . 'frontend/assets/css/layouts',
				ESF_YOUTUBE_URL . 'frontend/assets/css/layouts',
				$file,
				'css'
			);
			return $located['url'];
		}
		$path = ESF_YOUTUBE_DIR . 'frontend/assets/css/layouts/' . $file . '.css';

		return file_exists( $path ) ? ESF_YOUTUBE_URL . 'frontend/assets/css/layouts/' . $file . '.css' : '';
	}

	/**
	 * Get the URL for a layout's JS (for preview endpoint).
	 *
	 * Carousel uses the shared esf-carousel__premium_only.js; other layouts return empty.
	 *
	 * @since 6.13.0
	 *
	 * @param string $layout_type Layout type (e.g. 'carousel').
	 * @return string URL or empty string.
	 */
	public function get_layout_js_url( $layout_type ) {
		$layout_type = is_string( $layout_type ) ? trim( $layout_type ) : '';
		if ( 'carousel' === $layout_type ) {
			$plugin_url = defined( 'FTA_PLUGIN_URL' )
				? FTA_PLUGIN_URL
				: trailingslashit( dirname( ESF_YOUTUBE_URL ) );

			return $plugin_url . 'admin/shared/frontend/js/esf-carousel__premium_only.js';
		}

		if ( 'half_width' === $layout_type ) {
			$path = ESF_YOUTUBE_DIR . 'frontend/assets/js/esf-youtube-half-width__premium_only.js';
			return file_exists( $path )
				? ESF_YOUTUBE_URL . 'frontend/assets/js/esf-youtube-half-width__premium_only.js'
				: '';
		}

		if ( 'full_width' === $layout_type ) {
			$path = ESF_YOUTUBE_DIR . 'frontend/assets/js/esf-youtube-full-width__premium_only.js';
			return file_exists( $path )
				? ESF_YOUTUBE_URL . 'frontend/assets/js/esf-youtube-full-width__premium_only.js'
				: '';
		}

		if ( 'masonry' === $layout_type ) {
			$path = ESF_YOUTUBE_DIR . 'frontend/assets/js/esf-youtube-masonry__premium_only.js';
			return file_exists( $path )
				? ESF_YOUTUBE_URL . 'frontend/assets/js/esf-youtube-masonry__premium_only.js'
				: '';
		}

		return '';
	}

	/**
	 * Render the shared missing-feed placeholder for deleted/invalid feed IDs.
	 *
	 * @since 6.11.0
	 * @param int $feed_id Missing feed ID from the shortcode.
	 * @return string HTML output.
	 */
	private function render_missing_feed( int $feed_id ): string {
		$post_id    = function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0;
		$can_manage = function_exists( 'esf_youtube_user_can_manage' ) && esf_youtube_user_can_manage();
		$context    = new MissingFeedContext(
			self::MODULE,
			$feed_id,
			'esf_youtube_feed',
			$post_id,
			$can_manage,
			MissingFeedState::default_public_message( self::MODULE ),
			admin_url( 'admin.php?page=esf-youtube&screen=feeds' ),
			'/youtube/feeds/{id}/preview',
			'/youtube/feeds'
		);

		return MissingFeedState::render( $context );
	}

	/**
	 * Register the historical BEM wrapper-class filter once.
	 *
	 * Shared Renderer emits `esf-youtube-feed`, but CSS/JS (lightbox, load more,
	 * base SCSS) historically key off `.esf-yt-feed`. Without this remap,
	 * `$card.closest('.esf-yt-feed')` fails and the lightbox silently no-ops.
	 *
	 * @since 6.14.0
	 * @return void
	 */
	private static function ensure_bem_wrapper_filter(): void {
		if ( self::$bem_wrapper_filter_registered ) {
			return;
		}
		self::$bem_wrapper_filter_registered = true;
		add_filter( 'esf_youtube_layout_render_wrapper_classes', array( __CLASS__, 'filter_wrapper_classes' ), 10, 3 );
		add_filter( 'esf_youtube_layout_render_wrapper_attributes', array( __CLASS__, 'filter_wrapper_attributes' ), 10, 3 );
	}

	/**
	 * Global hover overlay / play-icon data attributes on `.esf-yt-feed`.
	 *
	 * @since 6.16.0
	 *
	 * @param array                                                      $attrs      Wrapper attributes.
	 * @param object                                                     $feed       Feed record.
	 * @param \EasySocialFeed\Layouts\ValueObjects\LayoutDefinition|null $definition Layout definition (unused).
	 * @return array<string,string>
	 */
	public static function filter_wrapper_attributes( $attrs, $feed = null, $definition = null ) {
		if ( ! function_exists( 'esf_youtube_hover_overlay_wrapper_attributes' ) ) {
			return is_array( $attrs ) ? $attrs : array();
		}

		return esf_youtube_hover_overlay_wrapper_attributes( is_array( $attrs ) ? $attrs : array(), $feed );
	}

	/**
	 * Map shared-renderer wrapper classes to the historical YouTube BEM root.
	 *
	 * @since 6.14.0
	 *
	 * @param array                                                      $classes    Proposed wrapper classes.
	 * @param object                                                     $feed       Feed object.
	 * @param \EasySocialFeed\Layouts\ValueObjects\LayoutDefinition|null $definition Layout definition.
	 * @return array<int,string>
	 */
	public static function filter_wrapper_classes( $classes, $feed = null, $definition = null ) {
		$slug = 'grid';
		if ( is_object( $definition ) && method_exists( $definition, 'get_slug' ) ) {
			$slug = (string) $definition->get_slug();
		} elseif ( is_object( $feed ) && isset( $feed->settings['layout']['type'] ) ) {
			$slug = (string) $feed->settings['layout']['type'];
		}
		$slug = sanitize_html_class( $slug );
		if ( '' === $slug ) {
			$slug = 'grid';
		}

		return array( 'esf-yt-feed', 'esf-yt-feed--' . $slug );
	}
}
