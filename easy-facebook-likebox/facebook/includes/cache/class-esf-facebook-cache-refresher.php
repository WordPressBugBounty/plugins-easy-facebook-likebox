<?php
/**
 * Facebook safe cache refresher for Clear All Caches.
 *
 * @package Easy_Social_Feed
 * @since   6.8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Cache_Refresher
 *
 * @since 6.8.2
 */
class ESF_Facebook_Cache_Refresher implements ESF_Module_Cache_Refresher {

	/**
	 * {@inheritdoc}
	 */
	public function get_module() {
		return 'facebook';
	}

	/**
	 * {@inheritdoc}
	 */
	public function refresh_all() {
		if ( ! class_exists( 'ESF_Facebook_Feed_Repository' ) || ! class_exists( 'ESF_Facebook_Renderer' ) ) {
			return ESF_Safe_Cache_Refresh::summarize_outcomes( array() );
		}

		$feeds = ESF_Facebook_Feed_Repository::get_instance()->get_all( array( 'status' => 'active' ) );
		if ( empty( $feeds ) || ! is_array( $feeds ) ) {
			return ESF_Safe_Cache_Refresh::summarize_outcomes( array() );
		}

		$renderer  = new ESF_Facebook_Renderer();
		$seen_keys = array();
		$outcomes  = array();

		foreach ( $feeds as $feed ) {
			$content_type = isset( $feed->content_type ) ? sanitize_key( (string) $feed->content_type ) : 'feed';
			if ( 'likebox' === $content_type ) {
				continue;
			}

			$feed_obj = $this->normalize_feed_object( $feed );
			if ( null === $feed_obj ) {
				continue;
			}

			$keys      = ESF_Facebook_Renderer::resolve_posts_cache_keys( $feed_obj );
			$cache_key = isset( $keys['cache_key'] ) ? (string) $keys['cache_key'] : '';
			if ( '' === $cache_key || isset( $seen_keys[ $cache_key ] ) ) {
				continue;
			}
			$seen_keys[ $cache_key ] = true;

			$outcomes[] = $renderer->force_refresh_feed_posts( $feed_obj );
		}

		return ESF_Safe_Cache_Refresh::summarize_outcomes( $outcomes );
	}

	/**
	 * {@inheritdoc}
	 */
	public function flush_all() {
		$deleted = class_exists( 'ESF_Facebook_Cache' )
			? ESF_Facebook_Cache::flush_all_cache()
			: false;

		return array(
			'success' => false !== $deleted,
			'deleted' => false !== $deleted ? (int) $deleted : 0,
		);
	}

	/**
	 * Build a renderer-ready feed object from a repository row.
	 *
	 * @param object $feed Feed row.
	 * @return object|null
	 */
	private function normalize_feed_object( $feed ) {
		if ( ! is_object( $feed ) || empty( $feed->id ) ) {
			return null;
		}

		$settings = array();
		if ( isset( $feed->settings ) ) {
			$decoded  = is_string( $feed->settings ) ? json_decode( $feed->settings, true ) : $feed->settings;
			$settings = is_array( $decoded ) ? $decoded : array();
		}

		return (object) array(
			'id'           => (int) $feed->id,
			'page_id'      => isset( $feed->page_id ) ? (int) $feed->page_id : 0,
			'feed_type'    => isset( $feed->feed_type ) ? (string) $feed->feed_type : 'timeline',
			'content_type' => isset( $feed->content_type ) ? (string) $feed->content_type : 'feed',
			'settings'     => $settings,
		);
	}
}
