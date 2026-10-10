<?php
/**
 * Instagram safe cache refresher for Clear All Caches.
 *
 * @package Easy_Social_Feed
 * @since   6.8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_Cache_Refresher
 *
 * @since 6.8.2
 */
class ESF_Instagram_Cache_Refresher implements ESF_Module_Cache_Refresher {

	/**
	 * {@inheritdoc}
	 */
	public function get_module() {
		return 'instagram';
	}

	/**
	 * {@inheritdoc}
	 */
	public function refresh_all() {
		if ( ! class_exists( 'ESF_Instagram_Feed_Repository' ) || ! class_exists( 'ESF_Instagram_Renderer' ) ) {
			return ESF_Safe_Cache_Refresh::summarize_outcomes( array() );
		}

		$feeds = ESF_Instagram_Feed_Repository::get_instance()->get_all( array( 'status' => 'active' ) );
		if ( empty( $feeds ) || ! is_array( $feeds ) ) {
			return ESF_Safe_Cache_Refresh::summarize_outcomes( array() );
		}

		$renderer  = new ESF_Instagram_Renderer();
		$seen_keys = array();
		$outcomes  = array();

		foreach ( $feeds as $feed ) {
			$feed_id = isset( $feed->id ) ? (int) $feed->id : 0;
			if ( $feed_id <= 0 ) {
				continue;
			}

			$keys      = ESF_Instagram_Renderer::resolve_media_cache_keys( $feed );
			$cache_key = isset( $keys['cache_key'] ) ? (string) $keys['cache_key'] : '';
			if ( '' === $cache_key || isset( $seen_keys[ $cache_key ] ) ) {
				continue;
			}
			$seen_keys[ $cache_key ] = true;

			$outcomes[] = $renderer->force_refresh_feed_media( $feed_id );
		}

		return ESF_Safe_Cache_Refresh::summarize_outcomes( $outcomes );
	}

	/**
	 * {@inheritdoc}
	 */
	public function flush_all() {
		$deleted = class_exists( 'ESF_Instagram_Cache' )
			? ESF_Instagram_Cache::flush_all_cache()
			: false;

		return array(
			'success' => false !== $deleted,
			'deleted' => false !== $deleted ? (int) $deleted : 0,
		);
	}
}
