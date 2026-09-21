<?php
/**
 * Local avatar and tweet image storage for the X module.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter
 * @since 6.14.1
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Twitter_Local_Media
 *
 * @since 6.14.1
 */
class ESF_Twitter_Local_Media {

	/**
	 * Module slug for shared media helpers.
	 *
	 * @var string
	 */
	const MODULE = 'twitter';

	/**
	 * WP-Cron hook for batched background media downloads.
	 *
	 * @var string
	 */
	const WARM_CRON_HOOK = 'esf_twitter_warm_local_media';

	/**
	 * Default number of tweets processed per warm batch.
	 *
	 * @var int
	 */
	const DEFAULT_WARM_BATCH_SIZE = 8;

	/**
	 * Return a usable avatar URL, downloading again if the local file is gone.
	 *
	 * @since 6.14.1
	 * @param object|array<string,mixed>|null $account Account row.
	 * @return string
	 */
	public static function resolve_avatar_display_url( $account ) {
		$meta = self::avatar_row_meta( $account );
		if ( null === $meta ) {
			return '';
		}

		$media_key  = $meta['key'];
		$account_id = $meta['account_id'];
		$stored     = $meta['stored_url'];
		$remote     = $meta['remote_url'];

		if ( '' !== $media_key && $account_id > 0 && function_exists( 'esf_local_media_url' ) ) {
			$existing = esf_local_media_url( $media_key, self::MODULE, 0, $account_id );
			if ( is_string( $existing ) && '' !== $existing ) {
				return $existing;
			}
			// Avatars stored before per-account folders existed.
			$legacy = esf_local_media_url( $media_key, self::MODULE, 0, 0 );
			if ( is_string( $legacy ) && '' !== $legacy ) {
				return $legacy;
			}
		}

		if ( '' === $remote && $account_id > 0 ) {
			$remote = self::remote_avatar_from_tweets_cache( $account_id, $meta['x_user_id'] );
		}

		if (
			'' !== $stored
			&& function_exists( 'esf_is_local_media_url' )
			&& esf_is_local_media_url( $stored, self::MODULE )
		) {
			$stored = '';
		}

		if ( '' === $remote ) {
			$remote = $stored;
		}

		if ( '' === $remote ) {
			return '';
		}

		if ( function_exists( 'esf_twitter_get_best_profile_image_url' ) ) {
			$remote = esf_twitter_get_best_profile_image_url( $remote );
		}

		if ( '' !== $media_key && $account_id > 0 && function_exists( 'esf_serve_media_locally' ) ) {
			$local = esf_serve_media_locally( $media_key, $remote, self::MODULE, $account_id );
			if ( is_string( $local ) && '' !== $local ) {
				return $local;
			}
		}

		return $remote;
	}

	/**
	 * Localize `profile_image_url` on a REST account row in place.
	 *
	 * @since 6.14.1
	 *
	 * @param array<string,mixed> $row Account row (ARRAY_A).
	 * @return array<string,mixed>
	 */
	public static function localize_account_row_images( array $row ) {
		if ( ! isset( $row['profile_image_url'] ) && empty( $row['x_user_id'] ) ) {
			return $row;
		}

		$resolved = self::resolve_avatar_display_url( $row );
		if ( '' !== $resolved ) {
			$row['profile_image_url'] = $resolved;
		}

		return $row;
	}

	/**
	 * Warm a local avatar copy. Always returns the remote CDN URL for DB storage.
	 *
	 * @since 6.14.1
	 *
	 * @param int    $account_id        Internal account id.
	 * @param string $x_user_id         X platform user id.
	 * @param string $profile_image_url Remote (or previously stored) URL.
	 * @return string Remote URL to persist in `profile_image_url`.
	 */
	public static function persist_account_avatar( $account_id, $x_user_id, $profile_image_url ) {
		$account_id        = function_exists( 'esf_normalize_local_media_account_id' )
			? esf_normalize_local_media_account_id( $account_id )
			: max( 0, (int) $account_id );
		$x_user_id         = (string) $x_user_id;
		$profile_image_url = (string) $profile_image_url;

		if ( '' !== $profile_image_url && function_exists( 'esf_twitter_get_best_profile_image_url' ) ) {
			$profile_image_url = esf_twitter_get_best_profile_image_url( $profile_image_url );
		}

		if (
			'' !== $profile_image_url
			&& function_exists( 'esf_is_local_media_url' )
			&& esf_is_local_media_url( $profile_image_url, self::MODULE )
		) {
			return '';
		}

		if ( $account_id > 0 && '' !== $x_user_id && '' !== $profile_image_url && function_exists( 'esf_serve_media_locally' ) ) {
			esf_serve_media_locally( 'tw_' . $x_user_id, $profile_image_url, self::MODULE, $account_id );
		}

		return $profile_image_url;
	}

	/**
	 * Schedule batched background downloads for cached tweet images.
	 *
	 * @since 6.14.1
	 *
	 * @param int $feed_id    Feed id.
	 * @param int $account_id Account id.
	 * @return array{scheduled:bool,total:int,pending:int,offset:int}
	 */
	public static function schedule_warm_for_feed( $feed_id, $account_id ) {
		$feed_id    = max( 0, (int) $feed_id );
		$account_id = function_exists( 'esf_normalize_local_media_account_id' )
			? esf_normalize_local_media_account_id( $account_id )
			: max( 0, (int) $account_id );

		$result = array(
			'scheduled' => false,
			'total'     => 0,
			'pending'   => 0,
			'offset'    => 0,
		);

		if ( $feed_id <= 0 || $account_id <= 0 || ! function_exists( 'esf_serve_media_locally' ) ) {
			return $result;
		}

		$tweets  = self::get_cached_tweets( $account_id, $feed_id );
		$total   = count( $tweets );
		$pending = self::count_tweets_needing_local( $tweets, $account_id );

		$result['total']   = $total;
		$result['pending'] = $pending;

		if ( $pending <= 0 ) {
			self::clear_warm_state( $feed_id, $account_id );
			return $result;
		}

		self::set_warm_state(
			$feed_id,
			$account_id,
			array(
				'offset'  => 0,
				'total'   => $total,
				'pending' => $pending,
			)
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
	 * Cron callback: process the next warm batch.
	 *
	 * @since 6.14.1
	 *
	 * @param int $feed_id    Feed id.
	 * @param int $account_id Account id.
	 * @return void
	 */
	public static function run_warm_cron( $feed_id, $account_id ) {
		$feed_id    = max( 0, (int) $feed_id );
		$account_id = function_exists( 'esf_normalize_local_media_account_id' )
			? esf_normalize_local_media_account_id( $account_id )
			: max( 0, (int) $account_id );

		if ( $feed_id <= 0 || $account_id <= 0 || ! function_exists( 'esf_serve_media_locally' ) ) {
			return;
		}

		$batch_size = (int) apply_filters( 'esf_twitter_local_media_warm_batch_size', self::DEFAULT_WARM_BATCH_SIZE );
		$batch_size = max( 1, min( 25, $batch_size ) );

		$tweets = self::get_cached_tweets( $account_id, $feed_id );
		if ( empty( $tweets ) ) {
			self::clear_warm_state( $feed_id, $account_id );
			return;
		}

		$state  = self::get_warm_state( $feed_id, $account_id );
		$offset = isset( $state['offset'] ) ? max( 0, (int) $state['offset'] ) : 0;
		$total  = count( $tweets );

		if ( $offset >= $total ) {
			self::clear_warm_state( $feed_id, $account_id );
			return;
		}

		$slice = array_slice( $tweets, $offset, $batch_size );
		if ( class_exists( 'ESF_Twitter_Renderer' ) ) {
			$renderer = new ESF_Twitter_Renderer();
			$renderer->localize_tweets_for_display( $slice, $account_id, count( $slice ) );
		}

		$offset += count( $slice );
		$pending = self::count_tweets_needing_local( $tweets, $account_id );

		if ( $offset < $total && $pending > 0 ) {
			self::set_warm_state(
				$feed_id,
				$account_id,
				array(
					'offset'  => $offset,
					'total'   => $total,
					'pending' => $pending,
				)
			);
			$args = array( $feed_id, $account_id );
			if ( ! wp_next_scheduled( self::WARM_CRON_HOOK, $args ) ) {
				wp_schedule_single_event( time() + 2, self::WARM_CRON_HOOK, $args );
			}
			return;
		}

		self::clear_warm_state( $feed_id, $account_id );
	}

	/**
	 * Read cached tweets for an account (new key, then legacy feed-scoped).
	 *
	 * @since 6.14.1
	 *
	 * @param int $account_id Account id.
	 * @param int $feed_id    Optional feed id for legacy fallback.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_cached_tweets( $account_id, $feed_id = 0 ) {
		$account_id = (int) $account_id;
		$feed_id    = (int) $feed_id;
		if ( $account_id <= 0 || ! class_exists( 'ESF_Twitter_Cache' ) ) {
			return array();
		}

		$cache_key = function_exists( 'esf_twitter_tweets_cache_key' )
			? esf_twitter_tweets_cache_key( $account_id )
			: 'esf_tw_tweets_' . $account_id;
		$cached    = '' !== $cache_key ? ESF_Twitter_Cache::get( $cache_key ) : null;
		if ( ! is_array( $cached ) && '' !== $cache_key && method_exists( 'ESF_Twitter_Cache', 'get_stale' ) ) {
			$cached = ESF_Twitter_Cache::get_stale( $cache_key );
		}

		if ( ! is_array( $cached ) && $feed_id > 0 && function_exists( 'esf_twitter_tweets_cache_legacy_keys' ) ) {
			foreach ( esf_twitter_tweets_cache_legacy_keys( $feed_id, $account_id ) as $legacy_key ) {
				$cached = ESF_Twitter_Cache::get( $legacy_key );
				if ( ! is_array( $cached ) && method_exists( 'ESF_Twitter_Cache', 'get_stale' ) ) {
					$cached = ESF_Twitter_Cache::get_stale( $legacy_key );
				}
				if ( is_array( $cached ) ) {
					break;
				}
			}
		}

		return is_array( $cached ) ? $cached : array();
	}

	/**
	 * Count tweets that still need a local image download.
	 *
	 * @since 6.14.1
	 *
	 * @param array<int,array<string,mixed>> $tweets     Tweet items.
	 * @param int                            $account_id Account id.
	 * @return int
	 */
	public static function count_tweets_needing_local( array $tweets, $account_id ) {
		$account_id = function_exists( 'esf_normalize_local_media_account_id' )
			? esf_normalize_local_media_account_id( $account_id )
			: max( 0, (int) $account_id );
		if ( $account_id <= 0 || ! function_exists( 'esf_local_media_url' ) ) {
			return 0;
		}

		$pending = 0;
		foreach ( $tweets as $tweet ) {
			if ( ! is_array( $tweet ) ) {
				continue;
			}
			$tweet_id = isset( $tweet['tweet_id'] ) ? (string) $tweet['tweet_id'] : '';
			if ( empty( $tweet['media'] ) || ! is_array( $tweet['media'] ) ) {
				continue;
			}
			foreach ( $tweet['media'] as $media_index => $media_item ) {
				if ( ! is_array( $media_item ) ) {
					continue;
				}
				$type        = isset( $media_item['type'] ) ? (string) $media_item['type'] : 'photo';
				$needs_image = true;
				if ( 'photo' !== $type && empty( $media_item['preview_url'] ) && empty( $media_item['url'] ) ) {
					$needs_image = false;
				}
				if ( $needs_image ) {
					$media_key = 'tw_media_' . $tweet_id . '_' . (int) $media_index;
					if ( '' === esf_local_media_url( $media_key, self::MODULE, 0, $account_id ) ) {
						++$pending;
						break;
					}
				}
			}
		}

		return $pending;
	}

	/**
	 * First remote author avatar from the disconnected tweet cache.
	 *
	 * Used when the profile snapshot only has a missing local upload URL
	 * (older disconnects that stored `resolve_avatar_display_url()` output).
	 *
	 * @since 6.7.9
	 *
	 * @param int    $account_id Internal account id.
	 * @param string $x_user_id  Preferred X user id when tweets include retweets.
	 * @return string
	 */
	public static function remote_avatar_from_tweets_cache( $account_id, $x_user_id = '' ) {
		$account_id = (int) $account_id;
		$x_user_id  = (string) $x_user_id;
		if ( $account_id <= 0 ) {
			return '';
		}

		$cached = self::get_cached_tweets( $account_id );
		$tweets = function_exists( 'esf_twitter_unwrap_cached_tweets' )
			? esf_twitter_unwrap_cached_tweets( $cached )
			: $cached;
		if ( ! is_array( $tweets ) || empty( $tweets ) ) {
			return '';
		}

		$fallback = '';
		foreach ( $tweets as $tweet ) {
			if ( ! is_array( $tweet ) || empty( $tweet['author'] ) || ! is_array( $tweet['author'] ) ) {
				continue;
			}

			$url = isset( $tweet['author']['profile_image_url'] ) ? trim( (string) $tweet['author']['profile_image_url'] ) : '';
			if ( '' === $url ) {
				continue;
			}
			if ( function_exists( 'esf_is_local_media_url' ) && esf_is_local_media_url( $url, self::MODULE ) ) {
				continue;
			}

			$author_id = isset( $tweet['author']['x_user_id'] ) ? (string) $tweet['author']['x_user_id'] : '';
			if ( '' !== $x_user_id && $author_id === $x_user_id ) {
				return $url;
			}
			if ( '' === $fallback ) {
				$fallback = $url;
			}
		}

		return $fallback;
	}

	/**
	 * Normalize an account row, snapshot, or AccountSummary to an array.
	 *
	 * @since 6.7.9
	 *
	 * @param object|array<string,mixed>|null $account Account payload.
	 * @return array<string,mixed>|null
	 */
	private static function account_payload( $account ) {
		if ( null === $account ) {
			return null;
		}

		if ( is_object( $account ) && method_exists( $account, 'to_array' ) && method_exists( $account, 'get_avatar_url' ) ) {
			$data = $account->to_array();
			if ( ! is_array( $data ) ) {
				$data = array();
			}
			$data['profile_image_url'] = (string) $account->get_avatar_url();
			if ( method_exists( $account, 'get_extra' ) ) {
				$x_user_id = (string) $account->get_extra( 'x_user_id', '' );
				if ( '' !== $x_user_id ) {
					$data['x_user_id'] = $x_user_id;
				}
			}
			return $data;
		}

		if ( is_object( $account ) ) {
			$data = get_object_vars( $account );
			return is_array( $data ) ? $data : null;
		}

		return is_array( $account ) ? $account : null;
	}

	/**
	 * Extract avatar media key + remote/source URLs from an account row.
	 *
	 * @since 6.14.1
	 *
	 * @param object|array<string,mixed>|null $account Account row.
	 * @return array{key:string,account_id:int,stored_url:string,remote_url:string,x_user_id:string}|null
	 */
	private static function avatar_row_meta( $account ) {
		$data = self::account_payload( $account );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$stored = isset( $data['profile_image_url'] ) ? trim( (string) $data['profile_image_url'] ) : '';
		if ( '' === $stored && isset( $data['avatar_url'] ) ) {
			$stored = trim( (string) $data['avatar_url'] );
		}
		$account_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$x_user_id  = isset( $data['x_user_id'] ) ? (string) $data['x_user_id'] : '';
		if ( '' === $x_user_id && isset( $data['extras'] ) && is_array( $data['extras'] ) && ! empty( $data['extras']['x_user_id'] ) ) {
			$x_user_id = (string) $data['extras']['x_user_id'];
		}
		$remote = '';

		$account_data = isset( $data['account_data'] ) ? $data['account_data'] : '';
		if ( is_string( $account_data ) && '' !== $account_data ) {
			$decoded      = json_decode( $account_data, true );
			$account_data = is_array( $decoded ) ? $decoded : array();
		}
		if ( is_array( $account_data ) && ! empty( $account_data['profile_image_url'] ) ) {
			$candidate = (string) $account_data['profile_image_url'];
			if ( ! function_exists( 'esf_is_local_media_url' ) || ! esf_is_local_media_url( $candidate, self::MODULE ) ) {
				$remote = $candidate;
			}
		}

		if ( '' === $remote && '' !== $stored && ( ! function_exists( 'esf_is_local_media_url' ) || ! esf_is_local_media_url( $stored, self::MODULE ) ) ) {
			$remote = $stored;
		}

		if ( '' === $x_user_id && $account_id <= 0 && '' === $stored && '' === $remote ) {
			return null;
		}

		return array(
			'key'        => '' !== $x_user_id ? 'tw_' . $x_user_id : '',
			'account_id' => $account_id,
			'stored_url' => $stored,
			'remote_url' => $remote,
			'x_user_id'  => $x_user_id,
		);
	}

	/**
	 * Delete the account media folder.
	 *
	 * @since 6.14.1
	 * @param int $account_id Account ID.
	 * @return void
	 */
	public static function purge_account_media( $account_id ) {
		$account_id = function_exists( 'esf_normalize_local_media_account_id' )
			? esf_normalize_local_media_account_id( $account_id )
			: max( 0, (int) $account_id );
		if ( $account_id <= 0 ) {
			return;
		}

		if ( function_exists( 'esf_delete_media_account_folder' ) ) {
			esf_delete_media_account_folder( self::MODULE, $account_id );
		}
	}

	/**
	 * Clear the background download transient for a feed.
	 *
	 * @since 6.14.1
	 * @param int $feed_id    Feed ID.
	 * @param int $account_id Account ID.
	 * @return void
	 */
	public static function clear_feed_warm_transient( $feed_id, $account_id ) {
		self::clear_warm_state( $feed_id, $account_id );
	}

	/**
	 * Transient key for warm progress.
	 *
	 * @param int $feed_id    Feed id.
	 * @param int $account_id Account id.
	 * @return string
	 */
	private static function warm_state_key( $feed_id, $account_id ) {
		return 'esf_tw_warm_' . (int) $feed_id . '_' . (int) $account_id;
	}

	/**
	 * @param int $feed_id    Feed id.
	 * @param int $account_id Account id.
	 * @return array<string,mixed>
	 */
	private static function get_warm_state( $feed_id, $account_id ) {
		$state = get_transient( self::warm_state_key( $feed_id, $account_id ) );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param int                 $feed_id    Feed id.
	 * @param int                 $account_id Account id.
	 * @param array<string,mixed> $state      State.
	 * @return void
	 */
	private static function set_warm_state( $feed_id, $account_id, array $state ) {
		set_transient( self::warm_state_key( $feed_id, $account_id ), $state, 3600 );
	}

	/**
	 * @param int $feed_id    Feed id.
	 * @param int $account_id Account id.
	 * @return void
	 */
	private static function clear_warm_state( $feed_id, $account_id ) {
		delete_transient( self::warm_state_key( $feed_id, $account_id ) );
	}
}
