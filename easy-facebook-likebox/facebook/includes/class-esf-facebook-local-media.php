<?php
/**
 * Facebook local media helper.
 *
 * Stores Page avatars, cover photos, and post images under
 * uploads/esf-facebook/{page_row_id}/. New downloads honour General →
 * Download images locally. Cache keeps remote Graph URLs; localization
 * runs at display time. Videos are never downloaded.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Local_Media
 *
 * @since 6.7.9
 */
class ESF_Facebook_Local_Media {

	/**
	 * Default max width for localized page avatar thumbnails.
	 *
	 * @since 6.7.9
	 * @var int
	 */
	const DEFAULT_AVATAR_THUMB_WIDTH = 96;

	/**
	 * Default max width for feed tile / collage preview variants.
	 *
	 * @since 6.7.9
	 * @var int
	 */
	const DEFAULT_GRID_THUMB_WIDTH = 600;

	/**
	 * Module slug passed to core media helpers.
	 *
	 * @since 6.7.9
	 * @var string
	 */
	const MODULE = 'facebook';

	/**
	 * WP-Cron hook for background post-media warm.
	 *
	 * @since 6.7.9
	 * @var string
	 */
	const WARM_CRON_HOOK = 'esf_facebook_warm_local_media';

	/**
	 * Default warm batch size.
	 *
	 * @since 6.7.9
	 * @var int
	 */
	const DEFAULT_WARM_BATCH_SIZE = 8;

	/**
	 * Target max width for localized avatar thumbnails (admin lists, feed headers).
	 *
	 * @since 6.7.9
	 * @return int
	 */
	public static function get_avatar_thumb_width() {
		$width = (int) apply_filters( 'esf_facebook_avatar_thumb_width', self::DEFAULT_AVATAR_THUMB_WIDTH );
		return max( 48, min( 512, $width ) );
	}

	/**
	 * Max width (px) for feed tile variants.
	 *
	 * @since 6.7.9
	 * @return int
	 */
	public static function get_grid_thumb_width() {
		$width = (int) apply_filters( 'esf_facebook_local_media_grid_width', self::DEFAULT_GRID_THUMB_WIDTH );
		return max( 120, min( 1200, $width ) );
	}

	/**
	 * Whether grid image variants should be generated.
	 *
	 * @since 6.7.9
	 * @return bool
	 */
	private static function should_use_grid_variants() {
		return function_exists( 'esf_ensure_local_media_variant' )
			|| function_exists( 'esf_serve_media_locally_sized' );
	}

	/**
	 * Normalize page folder / account scope id.
	 *
	 * @since 6.7.9
	 * @param int $page_row_id Internal pages.id.
	 * @return int
	 */
	private static function normalize_scope_id( $page_row_id ) {
		return function_exists( 'esf_normalize_local_media_account_id' )
			? esf_normalize_local_media_account_id( $page_row_id )
			: max( 0, (int) $page_row_id );
	}

	/**
	 * Resolve avatar cache metadata from a page row.
	 *
	 * Folder scope uses the internal pages table primary key (same pattern as
	 * Instagram account folders). Cache key includes the Graph page_id.
	 *
	 * @since 6.7.9
	 *
	 * @param object|array<string,mixed>|null $page_row Page row.
	 * @return array{key:string,page_id:string,scope_id:int,cdn_url:string}|null
	 */
	private static function avatar_row_meta( $page_row ) {
		if ( null === $page_row ) {
			return null;
		}

		$data = is_object( $page_row ) ? get_object_vars( $page_row ) : $page_row;
		if ( ! is_array( $data ) ) {
			return null;
		}

		$cdn_url = isset( $data['profile_image_url'] ) ? (string) $data['profile_image_url'] : '';
		if ( '' === $cdn_url ) {
			return null;
		}

		$page_id = isset( $data['page_id'] ) ? (string) $data['page_id'] : '';
		if ( '' === $page_id && isset( $data['id'] ) ) {
			$page_id = (string) (int) $data['id'];
		}
		if ( '' === $page_id ) {
			return null;
		}

		$scope_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		if ( $scope_id <= 0 ) {
			$scope_id = (int) $page_id;
		}
		if ( $scope_id <= 0 ) {
			return null;
		}

		return array(
			'key'      => 'fb_page_avatar_' . $page_id,
			'page_id'  => $page_id,
			'scope_id' => $scope_id,
			'cdn_url'  => $cdn_url,
		);
	}

	/**
	 * Resolve a profile image URL for read-only display (admin lists, REST).
	 *
	 * @since 6.7.9
	 *
	 * @param object|array<string,mixed>|null $page_row     Page row.
	 * @param bool                            $prefer_thumb When true, prefer the small variant.
	 * @return string
	 */
	public static function resolve_page_avatar_display_url( $page_row, $prefer_thumb = true ) {
		$meta = self::avatar_row_meta( $page_row );
		if ( null === $meta ) {
			return '';
		}

		if ( ! function_exists( 'esf_local_media_url' ) ) {
			return $meta['cdn_url'];
		}

		if ( $prefer_thumb ) {
			$thumb = esf_local_media_url(
				$meta['key'],
				self::MODULE,
				self::get_avatar_thumb_width(),
				$meta['scope_id']
			);
			if ( '' !== $thumb ) {
				return $thumb;
			}
		}

		$local = esf_local_media_url( $meta['key'], self::MODULE, 0, $meta['scope_id'] );
		if ( '' !== $local ) {
			return $local;
		}

		return $meta['cdn_url'];
	}

	/**
	 * Localize a profile image URL from a page DB row.
	 *
	 * @since 6.7.9
	 *
	 * @param object|array<string,mixed>|null $page_row Page row.
	 * @return string Local URL when available, otherwise the original URL.
	 */
	public static function localize_page_avatar_from_row( $page_row ) {
		$meta = self::avatar_row_meta( $page_row );
		if ( null === $meta ) {
			return '';
		}

		self::ensure_avatar_full( $meta );
		self::ensure_avatar_thumb_variant( $meta );

		return self::resolve_page_avatar_display_url( $page_row );
	}

	/**
	 * Whether an avatar file exists for a page scope folder.
	 *
	 * @since 6.7.9
	 *
	 * @param string $key       Avatar cache key.
	 * @param int    $scope_id  Internal pages.id used as folder id.
	 * @param int    $max_width 0 for full size, otherwise variant width.
	 * @return bool
	 */
	private static function avatar_file_exists( $key, $scope_id, $max_width = 0 ) {
		if ( ! function_exists( 'esf_local_media_file_path' ) ) {
			return false;
		}

		$scope_id = self::normalize_scope_id( $scope_id );
		if ( $scope_id <= 0 ) {
			return false;
		}

		$file = esf_local_media_file_path( $key, self::MODULE, $max_width, $scope_id );
		return '' !== $file && file_exists( $file );
	}

	/**
	 * Ensure the full-size avatar exists locally.
	 *
	 * @since 6.7.9
	 *
	 * @param array{key:string,page_id:string,scope_id:int,cdn_url:string} $meta Avatar metadata.
	 * @return void
	 */
	private static function ensure_avatar_full( array $meta ) {
		if ( self::avatar_file_exists( $meta['key'], $meta['scope_id'], 0 ) ) {
			return;
		}

		if ( ! function_exists( 'esf_serve_media_locally' ) ) {
			return;
		}

		esf_serve_media_locally( $meta['key'], $meta['cdn_url'], self::MODULE, $meta['scope_id'] );
	}

	/**
	 * Ensure the thumbnail variant exists locally.
	 *
	 * @since 6.7.9
	 *
	 * @param array{key:string,page_id:string,scope_id:int,cdn_url:string} $meta Avatar metadata.
	 * @return void
	 */
	private static function ensure_avatar_thumb_variant( array $meta ) {
		if ( ! function_exists( 'esf_ensure_local_media_variant' ) ) {
			return;
		}

		$thumb_width = self::get_avatar_thumb_width();
		if ( self::avatar_file_exists( $meta['key'], $meta['scope_id'], $thumb_width ) ) {
			return;
		}

		esf_ensure_local_media_variant( $meta['key'], self::MODULE, $thumb_width, $meta['scope_id'] );
	}

	/**
	 * Enrich an AccountSummary (or array) with a localized avatar URL.
	 *
	 * @since 6.7.9
	 *
	 * @param object|array<string,mixed>|\EasySocialFeed\Layouts\ValueObjects\AccountSummary|null $page_or_summary Page row or AccountSummary VO.
	 * @param object|array<string,mixed>|null                                                     $page_row        Original page row from DB.
	 * @return object|array<string,mixed>|\EasySocialFeed\Layouts\ValueObjects\AccountSummary|null
	 */
	public static function with_localized_page_avatar( $page_or_summary, $page_row = null ) {
		if ( null === $page_or_summary ) {
			return null;
		}

		$localized = self::localize_page_avatar_from_row( $page_row );
		if ( '' === $localized && is_object( $page_or_summary ) && method_exists( $page_or_summary, 'to_array' ) ) {
			$localized = self::localize_page_avatar_from_row( $page_or_summary->to_array() );
		}
		if ( '' === $localized ) {
			return $page_or_summary;
		}

		if ( $page_or_summary instanceof \EasySocialFeed\Layouts\ValueObjects\AccountSummary ) {
			if ( $localized === $page_or_summary->get_avatar_url() ) {
				return $page_or_summary;
			}
			$data               = $page_or_summary->to_array();
			$data['avatar_url'] = $localized;
			return new \EasySocialFeed\Layouts\ValueObjects\AccountSummary( $data );
		}

		if ( is_object( $page_or_summary ) ) {
			$clone             = clone $page_or_summary;
			$clone->avatar_url = $localized;
			return $clone;
		}

		if ( is_array( $page_or_summary ) ) {
			$page_or_summary['avatar_url'] = $localized;
		}

		return $page_or_summary;
	}

	/**
	 * Resolve cover cache metadata from a page row.
	 *
	 * Same folder scope as avatars (internal pages.id). Cache key is
	 * `fb_page_cover_{graph_page_id}`.
	 *
	 * @since 6.7.9
	 *
	 * @param object|array<string,mixed>|null $page_row Page row.
	 * @return array{key:string,page_id:string,scope_id:int,cdn_url:string}|null
	 */
	private static function cover_row_meta( $page_row ) {
		if ( null === $page_row ) {
			return null;
		}

		$data = is_object( $page_row ) ? get_object_vars( $page_row ) : $page_row;
		if ( ! is_array( $data ) ) {
			return null;
		}

		// AccountSummary::to_array() nests cover under extras.
		$cdn_url = isset( $data['cover_photo_url'] ) ? (string) $data['cover_photo_url'] : '';
		if ( '' === $cdn_url && isset( $data['extras'] ) && is_array( $data['extras'] ) && isset( $data['extras']['cover_photo_url'] ) ) {
			$cdn_url = (string) $data['extras']['cover_photo_url'];
		}
		if ( '' === $cdn_url ) {
			return null;
		}

		$page_id = isset( $data['page_id'] ) ? (string) $data['page_id'] : '';
		if ( '' === $page_id && isset( $data['extras'] ) && is_array( $data['extras'] ) && isset( $data['extras']['page_id'] ) ) {
			$page_id = (string) $data['extras']['page_id'];
		}
		if ( '' === $page_id && isset( $data['id'] ) ) {
			$page_id = (string) (int) $data['id'];
		}
		if ( '' === $page_id ) {
			return null;
		}

		$scope_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		if ( $scope_id <= 0 ) {
			$scope_id = (int) $page_id;
		}
		if ( $scope_id <= 0 ) {
			return null;
		}

		return array(
			'key'      => 'fb_page_cover_' . $page_id,
			'page_id'  => $page_id,
			'scope_id' => $scope_id,
			'cdn_url'  => $cdn_url,
		);
	}

	/**
	 * Resolve a cover photo URL for read-only display (admin lists, REST).
	 *
	 * @since 6.7.9
	 *
	 * @param object|array<string,mixed>|null $page_row Page row.
	 * @return string
	 */
	public static function resolve_page_cover_display_url( $page_row ) {
		$meta = self::cover_row_meta( $page_row );
		if ( null === $meta ) {
			return '';
		}

		if ( ! function_exists( 'esf_local_media_url' ) ) {
			return $meta['cdn_url'];
		}

		$local = esf_local_media_url( $meta['key'], self::MODULE, 0, $meta['scope_id'] );
		if ( '' !== $local ) {
			return $local;
		}

		return $meta['cdn_url'];
	}

	/**
	 * Localize a cover photo URL from a page DB row.
	 *
	 * @since 6.7.9
	 *
	 * @param object|array<string,mixed>|null $page_row Page row.
	 * @return string Local URL when available, otherwise the original URL.
	 */
	public static function localize_page_cover_from_row( $page_row ) {
		$meta = self::cover_row_meta( $page_row );
		if ( null === $meta ) {
			return '';
		}

		self::ensure_cover_full( $meta );

		return self::resolve_page_cover_display_url( $page_row );
	}

	/**
	 * Ensure the full-size cover exists locally.
	 *
	 * @since 6.7.9
	 *
	 * @param array{key:string,page_id:string,scope_id:int,cdn_url:string} $meta Cover metadata.
	 * @return void
	 */
	private static function ensure_cover_full( array $meta ) {
		if ( self::avatar_file_exists( $meta['key'], $meta['scope_id'], 0 ) ) {
			return;
		}

		if ( ! function_exists( 'esf_serve_media_locally' ) ) {
			return;
		}

		// Skip re-download when the stored value is already a first-party local URL.
		if (
			function_exists( 'esf_is_local_media_url' )
			&& esf_is_local_media_url( $meta['cdn_url'], self::MODULE )
		) {
			return;
		}

		esf_serve_media_locally( $meta['key'], $meta['cdn_url'], self::MODULE, $meta['scope_id'] );
	}

	/**
	 * Enrich an AccountSummary (or array) with a localized cover URL.
	 *
	 * @since 6.7.9
	 *
	 * @param object|array<string,mixed>|\EasySocialFeed\Layouts\ValueObjects\AccountSummary|null $page_or_summary Page row or AccountSummary VO.
	 * @param object|array<string,mixed>|null                                                     $page_row        Original page row from DB.
	 * @return object|array<string,mixed>|\EasySocialFeed\Layouts\ValueObjects\AccountSummary|null
	 */
	public static function with_localized_page_cover( $page_or_summary, $page_row = null ) {
		if ( null === $page_or_summary ) {
			return null;
		}

		$localized = self::localize_page_cover_from_row( $page_row );
		if ( '' === $localized && is_object( $page_or_summary ) && method_exists( $page_or_summary, 'to_array' ) ) {
			$localized = self::localize_page_cover_from_row( $page_or_summary->to_array() );
		}
		if ( '' === $localized ) {
			return $page_or_summary;
		}

		if ( $page_or_summary instanceof \EasySocialFeed\Layouts\ValueObjects\AccountSummary ) {
			if ( $localized === $page_or_summary->get_extra( 'cover_photo_url', '' ) ) {
				return $page_or_summary;
			}
			$data                              = $page_or_summary->to_array();
			$data['extras']                    = isset( $data['extras'] ) && is_array( $data['extras'] ) ? $data['extras'] : array();
			$data['extras']['cover_photo_url'] = $localized;
			return new \EasySocialFeed\Layouts\ValueObjects\AccountSummary( $data );
		}

		if ( is_object( $page_or_summary ) ) {
			$clone                  = clone $page_or_summary;
			$clone->cover_photo_url = $localized;
			return $clone;
		}

		if ( is_array( $page_or_summary ) ) {
			$page_or_summary['cover_photo_url'] = $localized;
		}

		return $page_or_summary;
	}

	/**
	 * Resolve account avatar from esf_facebook_accounts row.
	 *
	 * @since 6.7.9
	 *
	 * @param object|array<string,mixed>|null $account_row Account row.
	 * @param bool                            $prefer_thumb Prefer thumbnail variant.
	 * @return string
	 */
	public static function resolve_account_avatar_display_url( $account_row, $prefer_thumb = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return '';
	}

	/**
	 * Prepare Graph feed nodes for display (read existing locals, sync first page).
	 *
	 * @since 6.7.9
	 *
	 * @param array<int,array<string,mixed>> $nodes       Graph post nodes.
	 * @param int                            $page_row_id Internal pages.id (folder scope).
	 * @param string                         $context     `preview` or `frontend`.
	 * @param int                            $per_page    Visible page size.
	 * @return array<int,array<string,mixed>>
	 */
	public static function prepare_nodes_for_display( array $nodes, $page_row_id, $context = 'frontend', $per_page = 9 ) {
		$page_row_id = self::normalize_scope_id( $page_row_id );
		$per_page    = max( 1, min( 50, (int) $per_page ) );
		if ( ! in_array( $context, array( 'preview', 'frontend' ), true ) ) {
			$context = 'frontend';
		}

		if ( $page_row_id <= 0 || empty( $nodes ) || ! function_exists( 'esf_serve_media_locally' ) ) {
			return $nodes;
		}

		$nodes = self::resolve_nodes_display_urls( $nodes, $page_row_id );

		return self::ensure_local_media_for_nodes(
			$nodes,
			$page_row_id,
			array(
				'limit'      => $per_page,
				'skip_local' => true,
			)
		);
	}

	/**
	 * Ensure Graph nodes use first-party image URLs when possible.
	 *
	 * Rewrites `full_picture` and attachment / subattachment image srcs in place,
	 * and sets `local_media_url` / `local_media_thumb_url` for PostMapper.
	 *
	 * @since 6.7.9
	 *
	 * @param array<int,array<string,mixed>> $nodes       Graph nodes.
	 * @param int                            $page_row_id Page folder scope.
	 * @param array<string,mixed>            $args        Optional: limit, offset, skip_local.
	 * @return array<int,array<string,mixed>>
	 */
	public static function ensure_local_media_for_nodes( array $nodes, $page_row_id = 0, array $args = array() ) {
		if ( empty( $nodes ) || ! function_exists( 'esf_serve_media_locally' ) ) {
			return $nodes;
		}

		$page_row_id  = self::normalize_scope_id( $page_row_id );
		$limit        = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;
		$offset       = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$skip_local   = ! empty( $args['skip_local'] );
		$use_variants = self::should_use_grid_variants();
		$thumb_width  = $use_variants ? self::get_grid_thumb_width() : 0;
		$processed    = 0;

		foreach ( $nodes as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( $index < $offset ) {
				continue;
			}

			if ( $limit > 0 && $processed >= $limit ) {
				break;
			}

			if ( $skip_local && self::node_is_fully_local( $node, $page_row_id, $use_variants, $thumb_width ) ) {
				$nodes[ $index ] = self::attach_local_fields_from_disk( $node, $page_row_id, $use_variants, $thumb_width );
				++$processed;
				continue;
			}

			$nodes[ $index ] = self::localize_node( $node, $use_variants, $thumb_width, $page_row_id );
			++$processed;
		}

		return $nodes;
	}

	/**
	 * Swap display URLs to existing local files without downloading (read-only).
	 *
	 * @since 6.7.9
	 *
	 * @param array<int,array<string,mixed>> $nodes       Graph nodes.
	 * @param int                            $page_row_id Page folder scope.
	 * @return array<int,array<string,mixed>>
	 */
	public static function resolve_nodes_display_urls( array $nodes, $page_row_id = 0 ) {
		if ( empty( $nodes ) ) {
			return $nodes;
		}

		$page_row_id  = self::normalize_scope_id( $page_row_id );
		$use_variants = self::should_use_grid_variants();
		$thumb_width  = $use_variants ? self::get_grid_thumb_width() : 0;

		foreach ( $nodes as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$nodes[ $index ] = self::resolve_node_display_urls( $node, $page_row_id, $use_variants, $thumb_width );
		}

		return $nodes;
	}

	/**
	 * Count nodes that still need a local download.
	 *
	 * @since 6.7.9
	 *
	 * @param array<int,array<string,mixed>> $nodes       Graph nodes.
	 * @param int                            $page_row_id Page folder scope.
	 * @return int
	 */
	public static function count_nodes_needing_local( array $nodes, $page_row_id = 0 ) {
		$page_row_id  = self::normalize_scope_id( $page_row_id );
		$use_variants = self::should_use_grid_variants();
		$thumb_width  = $use_variants ? self::get_grid_thumb_width() : 0;
		$pending      = 0;

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( ! self::node_has_downloadable_image( $node ) ) {
				continue;
			}
			if ( ! self::node_is_fully_local( $node, $page_row_id, $use_variants, $thumb_width ) ) {
				++$pending;
			}
		}

		return $pending;
	}

	/**
	 * Schedule batched background downloads for cached feed media.
	 *
	 * @since 6.7.9
	 *
	 * @param int $feed_id     Feed id.
	 * @param int $page_row_id Internal pages.id.
	 * @return array{scheduled:bool,total:int,pending:int,offset:int}
	 */
	public static function schedule_warm_for_feed( $feed_id, $page_row_id ) {
		$feed_id     = max( 0, (int) $feed_id );
		$page_row_id = self::normalize_scope_id( $page_row_id );

		$result = array(
			'scheduled' => false,
			'total'     => 0,
			'pending'   => 0,
			'offset'    => 0,
		);

		if ( $feed_id <= 0 || $page_row_id <= 0 || ! function_exists( 'esf_serve_media_locally' ) ) {
			return $result;
		}

		$nodes   = self::get_feed_media_nodes( $feed_id );
		$total   = count( $nodes );
		$pending = self::count_nodes_needing_local( $nodes, $page_row_id );

		$result['total']   = $total;
		$result['pending'] = $pending;

		if ( $pending <= 0 ) {
			self::clear_warm_state( $feed_id, $page_row_id );
			return $result;
		}

		self::set_warm_state(
			$feed_id,
			$page_row_id,
			array(
				'offset'  => 0,
				'total'   => $total,
				'pending' => $pending,
			)
		);

		$args = array( $feed_id, $page_row_id );
		if ( ! wp_next_scheduled( self::WARM_CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 1, self::WARM_CRON_HOOK, $args );
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		$result['scheduled'] = true;
		return $result;
	}

	/**
	 * Run one warm-cron batch.
	 *
	 * @since 6.7.9
	 *
	 * @param int $feed_id     Feed id.
	 * @param int $page_row_id Page folder scope.
	 * @return void
	 */
	public static function run_warm_cron( $feed_id, $page_row_id ) {
		$feed_id     = max( 0, (int) $feed_id );
		$page_row_id = self::normalize_scope_id( $page_row_id );

		if ( $feed_id <= 0 || $page_row_id <= 0 || ! function_exists( 'esf_serve_media_locally' ) ) {
			return;
		}

		$batch_size = (int) apply_filters( 'esf_facebook_local_media_warm_batch_size', self::DEFAULT_WARM_BATCH_SIZE );
		$batch_size = max( 1, min( 25, $batch_size ) );

		$state  = self::get_warm_state( $feed_id, $page_row_id );
		$offset = isset( $state['offset'] ) ? max( 0, (int) $state['offset'] ) : 0;
		$nodes  = self::get_feed_media_nodes( $feed_id );

		if ( empty( $nodes ) ) {
			self::clear_warm_state( $feed_id, $page_row_id );
			return;
		}

		self::ensure_local_media_for_nodes(
			$nodes,
			$page_row_id,
			array(
				'limit'      => $batch_size,
				'offset'     => $offset,
				'skip_local' => true,
			)
		);

		$next_offset = $offset + $batch_size;
		$pending     = self::count_nodes_needing_local( $nodes, $page_row_id );

		if ( $pending <= 0 || $next_offset >= count( $nodes ) ) {
			self::clear_warm_state( $feed_id, $page_row_id );
			return;
		}

		self::set_warm_state(
			$feed_id,
			$page_row_id,
			array(
				'offset'  => $next_offset,
				'total'   => count( $nodes ),
				'pending' => $pending,
			)
		);

		$args = array( $feed_id, $page_row_id );
		if ( ! wp_next_scheduled( self::WARM_CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 2, self::WARM_CRON_HOOK, $args );
		}
	}

	/**
	 * Read cached Graph nodes for a feed (remote URLs; no auto-fetch).
	 *
	 * @since 6.7.9
	 *
	 * @param int $feed_id Feed id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_feed_media_nodes( $feed_id ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 || ! class_exists( 'ESF_Facebook_Feed_Repository' ) || ! class_exists( 'ESF_Facebook_Cache' ) ) {
			return array();
		}

		$feed_row = ESF_Facebook_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return array();
		}

		$feed_obj = (object) array(
			'id'           => (int) $feed_row->id,
			'page_id'      => isset( $feed_row->page_id ) ? (int) $feed_row->page_id : 0,
			'feed_type'    => isset( $feed_row->feed_type ) ? (string) $feed_row->feed_type : 'timeline',
			'settings'     => isset( $feed_row->settings ) && is_array( $feed_row->settings ) ? $feed_row->settings : array(),
			'content_type' => isset( $feed_row->content_type ) ? (string) $feed_row->content_type : 'feed',
		);

		if ( ! class_exists( 'ESF_Facebook_Renderer' ) ) {
			return array();
		}

		$keys   = ESF_Facebook_Renderer::resolve_posts_cache_keys( $feed_obj );
		$cached = ESF_Facebook_Cache::get( $keys['cache_key'] );
		if ( ! is_array( $cached ) && method_exists( 'ESF_Facebook_Cache', 'get_stale' ) ) {
			$cached = ESF_Facebook_Cache::get_stale( $keys['cache_key'] );
		}

		if ( is_array( $cached ) && isset( $cached['data'] ) && is_array( $cached['data'] ) ) {
			return array_values( $cached['data'] );
		}

		return array();
	}

	/**
	 * Whether the visible page still needs background media recovery.
	 *
	 * @since 6.7.9
	 *
	 * @param array<int,array<string,mixed>> $nodes       Prepared nodes.
	 * @param int                            $page_row_id Page scope.
	 * @param int                            $per_page    Visible size.
	 * @return bool
	 */
	public static function visible_page_needs_media_recovery( array $nodes, $page_row_id, $per_page = 9 ) {
		$page_row_id  = self::normalize_scope_id( $page_row_id );
		$per_page     = max( 1, min( 50, (int) $per_page ) );
		$use_variants = self::should_use_grid_variants();
		$thumb_width  = $use_variants ? self::get_grid_thumb_width() : 0;
		$checked      = 0;

		foreach ( $nodes as $node ) {
			if ( $checked >= $per_page || ! is_array( $node ) ) {
				continue;
			}
			++$checked;
			if ( ! self::node_has_downloadable_image( $node ) ) {
				continue;
			}
			if ( ! self::node_is_fully_local( $node, $page_row_id, $use_variants, $thumb_width ) ) {
				return true;
			}
		}

		// Also warm when later pages have pending media.
		return self::count_nodes_needing_local( $nodes, $page_row_id ) > 0;
	}

	/**
	 * Clear warm progress for a feed/page pair.
	 *
	 * @since 6.7.9
	 *
	 * @param int $feed_id     Feed id.
	 * @param int $page_row_id Page scope.
	 * @return void
	 */
	public static function clear_feed_warm_transient( $feed_id, $page_row_id ) {
		self::clear_warm_state( (int) $feed_id, self::normalize_scope_id( $page_row_id ) );
	}

	/**
	 * @param int $feed_id     Feed id.
	 * @param int $page_row_id Page scope.
	 * @return string
	 */
	private static function warm_state_key( $feed_id, $page_row_id ) {
		return 'esf_fb_warm_' . (int) $feed_id . '_' . (int) $page_row_id;
	}

	/**
	 * @param int $feed_id     Feed id.
	 * @param int $page_row_id Page scope.
	 * @return array<string,mixed>
	 */
	private static function get_warm_state( $feed_id, $page_row_id ) {
		$state = get_transient( self::warm_state_key( $feed_id, $page_row_id ) );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param int                 $feed_id     Feed id.
	 * @param int                 $page_row_id Page scope.
	 * @param array<string,mixed> $state       State.
	 * @return void
	 */
	private static function set_warm_state( $feed_id, $page_row_id, array $state ) {
		set_transient( self::warm_state_key( $feed_id, $page_row_id ), $state, HOUR_IN_SECONDS );
	}

	/**
	 * @param int $feed_id     Feed id.
	 * @param int $page_row_id Page scope.
	 * @return void
	 */
	private static function clear_warm_state( $feed_id, $page_row_id ) {
		delete_transient( self::warm_state_key( $feed_id, $page_row_id ) );
	}

	/**
	 * Localize a page of album photo tuples returned by load-more.
	 *
	 * Continues the existing `fb_sub_{post_id}_{i}` key sequence so warm-cron
	 * and display-time localization stay consistent.
	 *
	 * @since 6.7.9
	 *
	 * @param string                         $post_id     Facebook post ID.
	 * @param int                            $page_row_id Internal pages.id (folder scope).
	 * @param array<int,array<string,mixed>> $items       Popup media items (`src`/`url`).
	 * @param int                            $start_index Absolute index of the first item in the full album list.
	 * @return array<int,array<string,mixed>>
	 */
	public static function localize_album_page( $post_id, $page_row_id, array $items, $start_index = 0 ) {
		$post_id     = sanitize_key( (string) $post_id );
		$page_row_id = self::normalize_scope_id( $page_row_id );
		$start_index = max( 0, (int) $start_index );

		if ( '' === $post_id || $page_row_id <= 0 || empty( $items ) || ! function_exists( 'esf_serve_media_locally' ) ) {
			return $items;
		}

		foreach ( $items as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$remote = '';
			if ( isset( $item['src'] ) && is_string( $item['src'] ) ) {
				$remote = trim( $item['src'] );
			} elseif ( isset( $item['url'] ) && is_string( $item['url'] ) ) {
				$remote = trim( $item['url'] );
			}
			if ( '' === $remote || ! preg_match( '#^https?://#i', $remote ) ) {
				continue;
			}
			if ( function_exists( 'esf_is_local_media_url' ) && esf_is_local_media_url( $remote, self::MODULE ) ) {
				continue;
			}

			$sub_key = 'fb_sub_' . $post_id . '_' . ( $start_index + (int) $i );
			$local   = esf_serve_media_locally( $sub_key, $remote, self::MODULE, $page_row_id );
			if ( is_string( $local ) && '' !== $local ) {
				$items[ $i ]['src'] = $local;
				$items[ $i ]['url'] = $local;
			}
		}

		return $items;
	}

	/**
	 * Stable cache key for a post's primary image / poster.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed> $node Graph node.
	 * @return string
	 */
	public static function node_primary_media_key( array $node ) {
		$id = isset( $node['id'] ) ? sanitize_key( (string) $node['id'] ) : '';
		if ( '' === $id ) {
			return '';
		}
		return 'fb_media_' . $id;
	}

	/**
	 * Whether the node has a remote image worth localizing.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed> $node Graph node.
	 * @return bool
	 */
	private static function node_has_downloadable_image( array $node ) {
		$url = self::extract_primary_image_url( $node );
		if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
			return true;
		}

		$subs = self::extract_subattachment_urls( $node );
		return array() !== $subs;
	}

	/**
	 * Primary image URL from Graph node (full_picture or attachment image).
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed> $node Graph node.
	 * @return string
	 */
	private static function extract_primary_image_url( array $node ) {
		if ( isset( $node['full_picture'] ) && is_string( $node['full_picture'] ) ) {
			$url = trim( $node['full_picture'] );
			if ( '' !== $url ) {
				return $url;
			}
		}

		if ( isset( $node['attachments']['data'][0] ) && is_array( $node['attachments']['data'][0] ) ) {
			$att = $node['attachments']['data'][0];
			if ( isset( $att['media']['image']['src'] ) && is_string( $att['media']['image']['src'] ) ) {
				return trim( (string) $att['media']['image']['src'] );
			}
		}

		// Photos edge shape (Pro images / album filters) — prefer largest size.
		if ( isset( $node['images'] ) && is_array( $node['images'] ) ) {
			$best   = '';
			$best_w = -1;
			foreach ( $node['images'] as $img ) {
				if ( ! is_array( $img ) || ! isset( $img['source'] ) || ! is_string( $img['source'] ) ) {
					continue;
				}
				$url = trim( $img['source'] );
				if ( '' === $url ) {
					continue;
				}
				$width = isset( $img['width'] ) ? (int) $img['width'] : 0;
				if ( $width >= $best_w ) {
					$best_w = $width;
					$best   = $url;
				}
			}
			if ( '' !== $best ) {
				return $best;
			}
		}
		if ( isset( $node['picture'] ) && is_string( $node['picture'] ) ) {
			return trim( (string) $node['picture'] );
		}

		return '';
	}

	/**
	 * Subattachment image URLs keyed by index.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed> $node Graph node.
	 * @return array<int,string>
	 */
	private static function extract_subattachment_urls( array $node ) {
		$out = array();
		if ( ! isset( $node['attachments']['data'][0]['subattachments']['data'] ) || ! is_array( $node['attachments']['data'][0]['subattachments']['data'] ) ) {
			return $out;
		}

		foreach ( $node['attachments']['data'][0]['subattachments']['data'] as $i => $sub ) {
			if ( ! is_array( $sub ) ) {
				continue;
			}
			if ( ! isset( $sub['media']['image']['src'] ) || ! is_string( $sub['media']['image']['src'] ) ) {
				continue;
			}
			$url = trim( (string) $sub['media']['image']['src'] );
			if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
				$out[ (int) $i ] = $url;
			}
		}

		return $out;
	}

	/**
	 * Whether primary + sub images already exist on disk.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed> $node         Graph node.
	 * @param int                 $page_row_id  Scope.
	 * @param bool                $use_variants Grid variants.
	 * @param int                 $thumb_width  Variant width.
	 * @return bool
	 */
	private static function node_is_fully_local( array $node, $page_row_id, $use_variants, $thumb_width ) {
		if ( ! function_exists( 'esf_local_media_url' ) ) {
			return false;
		}

		if ( ! self::node_has_downloadable_image( $node ) ) {
			return true;
		}

		$media_key = self::node_primary_media_key( $node );
		if ( '' === $media_key ) {
			return true;
		}

		if ( '' === esf_local_media_url( $media_key, self::MODULE, 0, $page_row_id ) ) {
			return false;
		}

		if ( $use_variants && $thumb_width > 0 && '' === esf_local_media_url( $media_key, self::MODULE, $thumb_width, $page_row_id ) ) {
			return false;
		}

		$post_id = isset( $node['id'] ) ? sanitize_key( (string) $node['id'] ) : '';
		foreach ( array_keys( self::extract_subattachment_urls( $node ) ) as $i ) {
			$sub_key = 'fb_sub_' . $post_id . '_' . (int) $i;
			if ( '' === esf_local_media_url( $sub_key, self::MODULE, 0, $page_row_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Attach local_media_* fields from disk without downloading.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed> $node         Graph node.
	 * @param int                 $page_row_id  Scope.
	 * @param bool                $use_variants Variants.
	 * @param int                 $thumb_width  Width.
	 * @return array<string,mixed>
	 */
	private static function attach_local_fields_from_disk( array $node, $page_row_id, $use_variants, $thumb_width ) {
		return self::resolve_node_display_urls( $node, $page_row_id, $use_variants, $thumb_width );
	}

	/**
	 * Read-only URL swap for one node.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed> $node         Graph node.
	 * @param int                 $page_row_id  Scope.
	 * @param bool                $use_variants Variants.
	 * @param int                 $thumb_width  Width.
	 * @return array<string,mixed>
	 */
	private static function resolve_node_display_urls( array $node, $page_row_id, $use_variants, $thumb_width ) {
		if ( ! function_exists( 'esf_local_media_url' ) ) {
			return $node;
		}

		$media_key = self::node_primary_media_key( $node );
		if ( '' === $media_key ) {
			return $node;
		}

		$full_local = esf_local_media_url( $media_key, self::MODULE, 0, $page_row_id );
		if ( '' !== $full_local ) {
			$node['full_picture']    = $full_local;
			$node['local_media_url'] = $full_local;
			$node                    = self::rewrite_primary_attachment_src( $node, $full_local );

			if ( $use_variants && $thumb_width > 0 ) {
				$thumb = esf_local_media_url( $media_key, self::MODULE, $thumb_width, $page_row_id );
				if ( '' !== $thumb ) {
					$node['local_media_thumb_url'] = $thumb;
				} else {
					$node['local_media_thumb_url'] = $full_local;
				}
			} else {
				$node['local_media_thumb_url'] = $full_local;
			}
		}

		$post_id = isset( $node['id'] ) ? sanitize_key( (string) $node['id'] ) : '';
		if ( '' !== $post_id && isset( $node['attachments']['data'][0]['subattachments']['data'] ) && is_array( $node['attachments']['data'][0]['subattachments']['data'] ) ) {
			foreach ( $node['attachments']['data'][0]['subattachments']['data'] as $i => $sub ) {
				if ( ! is_array( $sub ) ) {
					continue;
				}
				$sub_key = 'fb_sub_' . $post_id . '_' . (int) $i;
				$local   = esf_local_media_url( $sub_key, self::MODULE, 0, $page_row_id );
				if ( '' !== $local ) {
					$node['attachments']['data'][0]['subattachments']['data'][ $i ]['media']['image']['src'] = $local;
				}
			}
		}

		return $node;
	}

	/**
	 * Download and rewrite one Graph node.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed> $node         Graph node.
	 * @param bool                $use_variants Variants.
	 * @param int                 $thumb_width  Width.
	 * @param int                 $page_row_id  Scope.
	 * @return array<string,mixed>
	 */
	private static function localize_node( array $node, $use_variants, $thumb_width, $page_row_id ) {
		$media_key = self::node_primary_media_key( $node );
		if ( '' === $media_key ) {
			return $node;
		}

		$remote = self::extract_primary_image_url( $node );
		if ( '' !== $remote && preg_match( '#^https?://#i', $remote ) ) {
			// Skip re-download when URL is already a local first-party file.
			if ( function_exists( 'esf_is_local_media_url' ) && esf_is_local_media_url( $remote, self::MODULE ) ) {
				$node['local_media_url'] = $remote;
				$node['full_picture']    = $remote;
			} else {
				$full_local = esf_serve_media_locally( $media_key, $remote, self::MODULE, $page_row_id );
				if ( is_string( $full_local ) && '' !== $full_local ) {
					$node['full_picture']    = $full_local;
					$node['local_media_url'] = $full_local;
					$node                    = self::rewrite_primary_attachment_src( $node, $full_local );

					if ( $use_variants && $thumb_width > 0 && function_exists( 'esf_ensure_local_media_variant' ) ) {
						$variant                       = esf_ensure_local_media_variant( $media_key, self::MODULE, $thumb_width, $page_row_id );
						$node['local_media_thumb_url'] = ( is_string( $variant ) && '' !== $variant ) ? $variant : $full_local;
					} else {
						$node['local_media_thumb_url'] = $full_local;
					}
				}
			}
		}

		$post_id = isset( $node['id'] ) ? sanitize_key( (string) $node['id'] ) : '';
		if ( '' === $post_id ) {
			return $node;
		}

		foreach ( self::extract_subattachment_urls( $node ) as $i => $sub_url ) {
			if ( function_exists( 'esf_is_local_media_url' ) && esf_is_local_media_url( $sub_url, self::MODULE ) ) {
				continue;
			}
			$sub_key = 'fb_sub_' . $post_id . '_' . (int) $i;
			$local   = esf_serve_media_locally( $sub_key, $sub_url, self::MODULE, $page_row_id );
			if ( is_string( $local ) && '' !== $local ) {
				$node['attachments']['data'][0]['subattachments']['data'][ $i ]['media']['image']['src'] = $local;
			}
		}

		return $node;
	}

	/**
	 * Rewrite primary attachment image src when present.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed> $node Graph node.
	 * @param string              $url  Local URL.
	 * @return array<string,mixed>
	 */
	private static function rewrite_primary_attachment_src( array $node, $url ) {
		if ( isset( $node['attachments']['data'][0]['media']['image']['src'] ) ) {
			$node['attachments']['data'][0]['media']['image']['src'] = $url;
		}
		if ( isset( $node['picture'] ) && is_string( $node['picture'] ) ) {
			$node['picture'] = $url;
		}
		return $node;
	}
}
