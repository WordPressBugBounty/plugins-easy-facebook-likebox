<?php
/**
 * TikTok safe cache refresher for Clear All Caches.
 *
 * @package Easy_Social_Feed
 * @since   6.8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_TikTok_Cache_Refresher
 *
 * @since 6.8.2
 */
class ESF_TikTok_Cache_Refresher implements ESF_Module_Cache_Refresher {

	/**
	 * {@inheritdoc}
	 */
	public function get_module() {
		return 'tiktok';
	}

	/**
	 * {@inheritdoc}
	 */
	public function refresh_all() {
		if (
			! class_exists( 'ESF_TikTok_Feed_Repository' )
			|| ! class_exists( 'ESF_TikTok_Account_Repository' )
			|| ! class_exists( 'ESF_TikTok_Renderer' )
		) {
			return ESF_Safe_Cache_Refresh::summarize_outcomes( array() );
		}

		$feeds = ESF_TikTok_Feed_Repository::get_instance()->get_all(
			array(
				'status' => 'active',
				'limit'  => 200,
			)
		);
		if ( empty( $feeds ) || ! is_array( $feeds ) ) {
			return ESF_Safe_Cache_Refresh::summarize_outcomes( array() );
		}

		$renderer = new ESF_TikTok_Renderer();
		$seen     = array();
		$outcomes = array();

		foreach ( $feeds as $feed ) {
			$feed_id    = isset( $feed->id ) ? (int) $feed->id : 0;
			$account_id = isset( $feed->account_id ) ? (int) $feed->account_id : 0;
			if ( $feed_id <= 0 || $account_id <= 0 || isset( $seen[ $account_id ] ) ) {
				continue;
			}
			$seen[ $account_id ] = true;

			$outcomes[] = $renderer->force_refresh_account_posts( $feed_id );
		}

		return ESF_Safe_Cache_Refresh::summarize_outcomes( $outcomes );
	}

	/**
	 * {@inheritdoc}
	 */
	public function flush_all() {
		$deleted = class_exists( 'ESF_TikTok_Cache' )
			? ESF_TikTok_Cache::flush_all_cache()
			: false;

		return array(
			'success' => false !== $deleted,
			'deleted' => false !== $deleted ? (int) $deleted : 0,
		);
	}
}
