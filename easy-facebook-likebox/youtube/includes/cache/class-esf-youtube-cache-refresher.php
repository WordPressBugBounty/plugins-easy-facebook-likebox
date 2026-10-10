<?php
/**
 * YouTube safe cache refresher for Clear All Caches.
 *
 * @package Easy_Social_Feed
 * @since   6.8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_Cache_Refresher
 *
 * @since 6.8.2
 */
class ESF_YouTube_Cache_Refresher implements ESF_Module_Cache_Refresher {

	/**
	 * {@inheritdoc}
	 */
	public function get_module() {
		return 'youtube';
	}

	/**
	 * {@inheritdoc}
	 */
	public function refresh_all() {
		if ( ! class_exists( 'ESF_YouTube_Feed_Repository' ) || ! class_exists( 'ESF_YouTube_Renderer' ) ) {
			return ESF_Safe_Cache_Refresh::summarize_outcomes( array() );
		}

		$feeds = ESF_YouTube_Feed_Repository::get_instance()->get_all( array( 'status' => 'active' ) );
		if ( empty( $feeds ) || ! is_array( $feeds ) ) {
			return ESF_Safe_Cache_Refresh::summarize_outcomes( array() );
		}

		$renderer     = new ESF_YouTube_Renderer();
		$account_repo = class_exists( 'ESF_YouTube_Account_Repository' )
			? ESF_YouTube_Account_Repository::get_instance()
			: null;
		$seen_keys    = array();
		$outcomes     = array();

		foreach ( $feeds as $feed ) {
			$feed_id    = isset( $feed->id ) ? (int) $feed->id : 0;
			$account_id = isset( $feed->account_id ) ? (int) $feed->account_id : 0;
			if ( $feed_id <= 0 || $account_id <= 0 ) {
				continue;
			}

			$feed_type = isset( $feed->feed_type ) ? (string) $feed->feed_type : 'channel';
			$source_id = isset( $feed->source_id ) ? (string) $feed->source_id : '';

			$settings = array();
			if ( isset( $feed->settings ) ) {
				$decoded  = is_string( $feed->settings ) ? json_decode( $feed->settings, true ) : $feed->settings;
				$settings = is_array( $decoded ) ? $decoded : array();
			}
			$content_filter = function_exists( 'esf_youtube_content_filter_from_settings' )
				? esf_youtube_content_filter_from_settings(
					ESF_YouTube_Feed_Repository::merge_settings_with_defaults( $settings )
				)
				: 'all';

			$channel_id = '';
			if ( $account_repo ) {
				$account = $account_repo->get_by_id( $account_id );
				if ( $account && isset( $account->channel_id ) ) {
					$channel_id = (string) $account->channel_id;
				}
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

			if ( '' === $cache_key || isset( $seen_keys[ $cache_key ] ) ) {
				continue;
			}
			$seen_keys[ $cache_key ] = true;

			$outcomes[] = $renderer->force_refresh_feed_videos( $feed_id );
		}

		return ESF_Safe_Cache_Refresh::summarize_outcomes( $outcomes );
	}

	/**
	 * {@inheritdoc}
	 */
	public function flush_all() {
		$deleted = class_exists( 'ESF_YouTube_Cache' )
			? ESF_YouTube_Cache::flush_api_cache()
			: false;

		return array(
			'success' => false !== $deleted,
			'deleted' => false !== $deleted ? (int) $deleted : 0,
		);
	}
}
