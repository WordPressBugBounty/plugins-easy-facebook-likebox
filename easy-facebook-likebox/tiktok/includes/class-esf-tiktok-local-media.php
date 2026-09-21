<?php
/**
 * TikTok local media helper — cover images + avatars under uploads/esf-tiktok/{account_id}/.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_TikTok_Local_Media
 *
 * @since 6.9.0
 */
class ESF_TikTok_Local_Media {

	const MODULE             = 'tiktok';
	const WARM_CRON_HOOK     = 'esf_tiktok_warm_media';
	const DEFAULT_WARM_BATCH = 5;

	/**
	 * @param array<int,array<string,mixed>> $nodes      Cached video nodes.
	 * @param int                            $account_id Account id.
	 * @param int                            $per_page   Visible page size.
	 * @param string                         $context    `preview` or `frontend`.
	 * @return array<int,array<string,mixed>>
	 */
	public static function prepare_nodes_for_display( array $nodes, $account_id, $per_page = 9, $context = 'frontend' ) {
		unset( $context );
		$account_id = self::normalize_account_id( $account_id );
		$per_page   = max( 1, min( 50, (int) $per_page ) );

		if ( $account_id <= 0 || empty( $nodes ) || ! function_exists( 'esf_serve_media_locally' ) ) {
			return $nodes;
		}

		$changed = false;
		$nodes   = self::resolve_nodes_display_urls( $nodes, $account_id, $changed );
		$synced  = 0;

		foreach ( $nodes as $i => $node ) {
			if ( $synced >= $per_page || ! is_array( $node ) ) {
				continue;
			}
			++$synced;

			if ( self::node_has_local_file( $node, $account_id ) ) {
				continue;
			}

			$cover = isset( $node['cover_image_url'] ) ? trim( (string) $node['cover_image_url'] ) : '';
			if ( '' === $cover || ! preg_match( '#^https?://#i', $cover ) ) {
				continue;
			}

			$local = esf_serve_media_locally( self::node_media_key( $node, $i ), $cover, self::MODULE, $account_id );
			if ( is_string( $local ) && '' !== $local ) {
				$nodes[ $i ]['local_media_url'] = $local;
				$changed                        = true;
			}
		}

		if ( $changed ) {
			self::persist_nodes_to_account_cache( $account_id, $nodes );
		}

		return $nodes;
	}

	/**
	 * @param array<int,array<string,mixed>> $nodes      Nodes.
	 * @param int                            $account_id Account id.
	 * @param bool                           $changed    Set when stale locals are cleared.
	 * @return array<int,array<string,mixed>>
	 */
	public static function resolve_nodes_display_urls( array $nodes, $account_id, &$changed = null ) {
		$account_id = self::normalize_account_id( $account_id );
		if ( $account_id <= 0 || ! function_exists( 'esf_local_media_url' ) ) {
			return $nodes;
		}

		foreach ( $nodes as $i => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$local = esf_local_media_url( self::node_media_key( $node, $i ), self::MODULE, 0, $account_id );
			if ( is_string( $local ) && '' !== $local ) {
				$nodes[ $i ]['local_media_url'] = $local;
				continue;
			}
			if ( ! empty( $node['local_media_url'] ) ) {
				unset( $nodes[ $i ]['local_media_url'] );
				if ( null !== $changed ) {
					$changed = true;
				}
			}
		}

		return $nodes;
	}

	/**
	 * @param array<string,mixed> $node       Video node.
	 * @param int                 $account_id Account id.
	 * @return bool
	 */
	public static function node_has_local_file( array $node, $account_id ) {
		if ( ! function_exists( 'esf_local_media_file_path' ) ) {
			return ! empty( $node['local_media_url'] );
		}
		$file = esf_local_media_file_path( self::node_media_key( $node, 0 ), self::MODULE, 0, self::normalize_account_id( $account_id ) );
		return is_string( $file ) && '' !== $file && file_exists( $file );
	}

	/**
	 * @param int $feed_id    Feed ID (unused).
	 * @param int $account_id Account ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_feed_media_nodes( $feed_id, $account_id ) {
		unset( $feed_id );
		$account_id = (int) $account_id;
		if ( $account_id <= 0 || ! class_exists( 'ESF_TikTok_Cache' ) ) {
			return array();
		}
		$data = ESF_TikTok_Cache::get( ESF_TikTok_Cache::posts_cache_key( $account_id ) );
		return ( is_array( $data ) && ! empty( $data['posts'] ) && is_array( $data['posts'] ) ) ? $data['posts'] : array();
	}

	/**
	 * @param array<int,array<string,mixed>> $nodes      Nodes.
	 * @param int                            $account_id Account id.
	 * @return int
	 */
	public static function count_nodes_needing_local( array $nodes, $account_id ) {
		$account_id = self::normalize_account_id( $account_id );
		$pending    = 0;
		foreach ( $nodes as $i => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$cover = isset( $node['cover_image_url'] ) ? trim( (string) $node['cover_image_url'] ) : '';
			if ( '' === $cover || ! preg_match( '#^https?://#i', $cover ) ) {
				continue;
			}
			if ( ! self::node_has_local_file( $node, $account_id ) ) {
				++$pending;
			}
		}
		unset( $i );
		return $pending;
	}

	/**
	 * @param array<int,array<string,mixed>> $nodes      Cached nodes.
	 * @param int                            $account_id Account id.
	 * @param int                            $per_page   Visible page size.
	 * @return bool
	 */
	public static function visible_page_needs_media_recovery( array $nodes, $account_id, $per_page = 9 ) {
		$account_id = self::normalize_account_id( $account_id );
		$per_page   = max( 1, min( 50, (int) $per_page ) );
		$checked    = 0;
		foreach ( $nodes as $node ) {
			if ( $checked >= $per_page || ! is_array( $node ) ) {
				break;
			}
			++$checked;
			$cover = isset( $node['cover_image_url'] ) ? trim( (string) $node['cover_image_url'] ) : '';
			if ( '' !== $cover && ! self::node_has_local_file( $node, $account_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param int $feed_id    Feed id.
	 * @param int $account_id Account id.
	 * @return array{scheduled:bool,total:int,pending:int,offset:int}
	 */
	public static function schedule_warm_for_feed( $feed_id, $account_id ) {
		$feed_id    = max( 0, (int) $feed_id );
		$account_id = self::normalize_account_id( $account_id );
		$result     = array(
			'scheduled' => false,
			'total'     => 0,
			'pending'   => 0,
			'offset'    => 0,
		);

		if ( $feed_id <= 0 || $account_id <= 0 || ! function_exists( 'esf_serve_media_locally' ) ) {
			return $result;
		}

		$nodes             = self::get_feed_media_nodes( $feed_id, $account_id );
		$result['total']   = count( $nodes );
		$result['pending'] = self::count_nodes_needing_local( $nodes, $account_id );

		if ( $result['pending'] <= 0 ) {
			delete_transient( self::warm_key( $feed_id, $account_id ) );
			return $result;
		}

		set_transient(
			self::warm_key( $feed_id, $account_id ),
			array(
				'offset'  => 0,
				'total'   => $result['total'],
				'pending' => $result['pending'],
			),
			3600
		);

		$args = array( $feed_id, $account_id );
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
	 * @param int $feed_id    Feed id.
	 * @param int $account_id Account id.
	 * @return void
	 */
	public static function run_warm_cron( $feed_id, $account_id ) {
		$feed_id    = max( 0, (int) $feed_id );
		$account_id = self::normalize_account_id( $account_id );
		if ( $feed_id <= 0 || $account_id <= 0 ) {
			return;
		}

		$batch  = max( 1, min( 25, (int) apply_filters( 'esf_tiktok_local_media_warm_batch_size', self::DEFAULT_WARM_BATCH ) ) );
		$state  = get_transient( self::warm_key( $feed_id, $account_id ) );
		$offset = ( is_array( $state ) && isset( $state['offset'] ) ) ? max( 0, (int) $state['offset'] ) : 0;
		$nodes  = self::get_feed_media_nodes( $feed_id, $account_id );
		$total  = count( $nodes );

		if ( empty( $nodes ) || $offset >= $total ) {
			delete_transient( self::warm_key( $feed_id, $account_id ) );
			return;
		}

		self::prepare_nodes_for_display( array_slice( $nodes, $offset, $batch ), $account_id, $batch );
		$offset += $batch;
		$pending = self::count_nodes_needing_local( self::get_feed_media_nodes( $feed_id, $account_id ), $account_id );

		if ( $offset < $total && $pending > 0 ) {
			set_transient(
				self::warm_key( $feed_id, $account_id ),
				array(
					'offset'  => $offset,
					'total'   => $total,
					'pending' => $pending,
				),
				3600
			);
			$args = array( $feed_id, $account_id );
			if ( ! wp_next_scheduled( self::WARM_CRON_HOOK, $args ) ) {
				wp_schedule_single_event( time() + 2, self::WARM_CRON_HOOK, $args );
			}
			return;
		}

		delete_transient( self::warm_key( $feed_id, $account_id ) );
	}

	/**
	 * @param object|array<string,mixed>|null $account Account row.
	 * @return string
	 */
	public static function resolve_avatar_display_url( $account ) {
		$meta = self::avatar_meta( $account );
		if ( null === $meta ) {
			return '';
		}
		if ( function_exists( 'esf_local_media_url' ) && $meta['account_id'] > 0 ) {
			$local = esf_local_media_url( $meta['key'], self::MODULE, 0, $meta['account_id'] );
			if ( is_string( $local ) && '' !== $local ) {
				return $local;
			}
		}
		return $meta['cdn_url'];
	}

	/** @param object|array<string,mixed>|null $account Account row. @return string */
	public static function resolve_logo_display_url( $account ) {
		return self::resolve_avatar_display_url( $account );
	}

	/**
	 * @param object|array<string,mixed>|null $account Account row.
	 * @return string
	 */
	public static function localize_avatar_from_row( $account ) {
		$meta = self::avatar_meta( $account );
		if ( null === $meta || ! function_exists( 'esf_serve_media_locally' ) ) {
			return null !== $meta ? $meta['cdn_url'] : '';
		}
		if ( $meta['account_id'] > 0 && '' !== $meta['cdn_url'] && preg_match( '#^https?://#i', $meta['cdn_url'] ) ) {
			esf_serve_media_locally( $meta['key'], $meta['cdn_url'], self::MODULE, $meta['account_id'] );
		}
		return self::resolve_avatar_display_url( $account );
	}

	/**
	 * @param \EasySocialFeed\Layouts\ValueObjects\AccountSummary|null $account     VO.
	 * @param object|array<string,mixed>|null                          $account_row DB row.
	 * @return \EasySocialFeed\Layouts\ValueObjects\AccountSummary|null
	 */
	public static function with_localized_avatar( $account, $account_row = null ) {
		if ( ! ( $account instanceof \EasySocialFeed\Layouts\ValueObjects\AccountSummary ) ) {
			return $account;
		}
		$row = $account_row ?? array(
			'id'         => $account->get_id(),
			'open_id'    => $account->get_extra( 'open_id' ),
			'avatar_url' => $account->get_avatar_url(),
		);
		$url = self::localize_avatar_from_row( $row );
		if ( '' === $url ) {
			return $account;
		}
		$data               = $account->to_array();
		$data['avatar_url'] = $url;
		return new \EasySocialFeed\Layouts\ValueObjects\AccountSummary( $data );
	}

	/** @param \EasySocialFeed\Layouts\ValueObjects\AccountSummary|null $account VO. @return \EasySocialFeed\Layouts\ValueObjects\AccountSummary|null */
	public static function with_localized_logo( $account, $account_row = null ) {
		return self::with_localized_avatar( $account, $account_row );
	}

	/** @param int $account_id Account ID. */
	public static function purge_account_folder( $account_id ) {
		self::purge_account_media( $account_id );
	}

	/** @param int $account_id Account ID. */
	public static function purge_account_media( $account_id ) {
		$account_id = self::normalize_account_id( $account_id );
		if ( $account_id > 0 && function_exists( 'esf_delete_media_account_folder' ) ) {
			esf_delete_media_account_folder( self::MODULE, $account_id );
		}
	}

	/**
	 * @param int                            $account_id Account id.
	 * @param array<int,array<string,mixed>> $nodes      Nodes.
	 */
	private static function persist_nodes_to_account_cache( $account_id, array $nodes ) {
		if ( ! class_exists( 'ESF_TikTok_Cache' ) ) {
			return;
		}
		$key  = ESF_TikTok_Cache::posts_cache_key( $account_id );
		$data = ESF_TikTok_Cache::get( $key );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$existing = isset( $data['posts'] ) && is_array( $data['posts'] ) ? $data['posts'] : array();
		$by_id    = array();
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && ! empty( $node['id'] ) && is_string( $node['id'] ) ) {
				$by_id[ (string) $node['id'] ] = $node;
			}
		}

		if ( array() !== $by_id && array() !== $existing ) {
			foreach ( $existing as $i => $old ) {
				if ( is_array( $old ) && ! empty( $old['id'] ) && isset( $by_id[ (string) $old['id'] ] ) ) {
					$existing[ $i ] = $by_id[ (string) $old['id'] ];
				}
			}
			$data['posts'] = $existing;
		} else {
			$data['posts'] = array() === $existing ? $nodes : $existing;
		}

		$ttl = function_exists( 'esf_get_tiktok_settings' ) ? (int) esf_get_tiktok_settings( 'cache_duration' ) : ESF_TikTok_Cache::DEFAULT_TTL;
		ESF_TikTok_Cache::set( $key, $data, max( 1, $ttl ), 'account', (int) $account_id );
	}

	/**
	 * @param object|array<string,mixed>|null $account Account.
	 * @return array{key:string,account_id:int,cdn_url:string}|null
	 */
	private static function avatar_meta( $account ) {
		if ( null === $account ) {
			return null;
		}
		$data = is_object( $account ) ? get_object_vars( $account ) : $account;
		if ( ! is_array( $data ) ) {
			return null;
		}
		$cdn     = isset( $data['avatar_url'] ) ? trim( (string) $data['avatar_url'] ) : '';
		$open_id = isset( $data['open_id'] ) ? trim( (string) $data['open_id'] ) : '';
		if ( '' === $cdn || '' === $open_id || ! preg_match( '#^https?://#i', $cdn ) ) {
			return null;
		}
		return array(
			'key'        => 'tt_avatar_' . $open_id,
			'account_id' => isset( $data['id'] ) ? max( 0, (int) $data['id'] ) : 0,
			'cdn_url'    => $cdn,
		);
	}

	/**
	 * @param array<string,mixed> $node  Node.
	 * @param int                 $index Index.
	 */
	private static function node_media_key( array $node, $index ): string {
		if ( ! empty( $node['id'] ) && is_string( $node['id'] ) ) {
			$safe = preg_replace( '/[^a-zA-Z0-9_\-]/', '_', (string) $node['id'] );
			$safe = is_string( $safe ) ? trim( $safe, '_' ) : '';
			if ( '' !== $safe ) {
				return 'tt_video_' . $safe;
			}
		}
		return 'tt_video_' . (int) $index;
	}

	/** @param int $account_id Account id. */
	private static function normalize_account_id( $account_id ): int {
		return function_exists( 'esf_normalize_local_media_account_id' )
			? esf_normalize_local_media_account_id( $account_id )
			: max( 0, (int) $account_id );
	}

	/**
	 * @param int $feed_id    Feed id.
	 * @param int $account_id Account id.
	 * @return void
	 */
	public static function clear_feed_warm_transient( $feed_id, $account_id ) {
		$feed_id    = max( 0, (int) $feed_id );
		$account_id = self::normalize_account_id( $account_id );
		if ( $feed_id <= 0 || $account_id <= 0 ) {
			return;
		}

		delete_transient( self::warm_key( $feed_id, $account_id ) );

		$args = array( $feed_id, $account_id );
		if ( wp_next_scheduled( self::WARM_CRON_HOOK, $args ) ) {
			wp_clear_scheduled_hook( self::WARM_CRON_HOOK, $args );
		}
	}

	/** @param int $feed_id Feed id. @param int $account_id Account id. */
	private static function warm_key( $feed_id, $account_id ): string {
		return 'esf_tt_warm_' . (int) $feed_id . '_' . (int) $account_id;
	}
}
