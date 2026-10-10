<?php
/**
 * Twitter/X safe cache refresher for Clear All Caches.
 *
 * @package Easy_Social_Feed
 * @since   6.8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Twitter_Cache_Refresher
 *
 * @since 6.8.2
 */
class ESF_Twitter_Cache_Refresher implements ESF_Module_Cache_Refresher {

	/**
	 * {@inheritdoc}
	 */
	public function get_module() {
		return 'twitter';
	}

	/**
	 * {@inheritdoc}
	 */
	public function refresh_all() {
		if (
			! class_exists( 'ESF_Twitter_Feed_Repository' )
			|| ! class_exists( 'ESF_Twitter_Account_Repository' )
			|| ! class_exists( 'ESF_Twitter_Renderer' )
		) {
			return ESF_Safe_Cache_Refresh::summarize_outcomes( array() );
		}

		$feeds = ESF_Twitter_Feed_Repository::get_instance()->get_all( array( 'status' => 'active' ) );
		if ( empty( $feeds ) || ! is_array( $feeds ) ) {
			return ESF_Safe_Cache_Refresh::summarize_outcomes( array() );
		}

		$account_repo = ESF_Twitter_Account_Repository::get_instance();
		$renderer     = new ESF_Twitter_Renderer();
		$seen         = array();
		$outcomes     = array();

		foreach ( $feeds as $feed ) {
			$account_id = isset( $feed->account_id ) ? (int) $feed->account_id : 0;
			if ( $account_id <= 0 || isset( $seen[ $account_id ] ) ) {
				continue;
			}
			$seen[ $account_id ] = true;

			$account = $account_repo->get_by_id( $account_id );
			if ( ! $account ) {
				$outcomes[] = new WP_Error(
					'account_missing',
					__( 'X account not found for one of the feeds.', 'easy-facebook-likebox' )
				);
				continue;
			}

			$outcomes[] = $renderer->force_refresh_account_tweets( $account );
		}

		return ESF_Safe_Cache_Refresh::summarize_outcomes( $outcomes );
	}

	/**
	 * {@inheritdoc}
	 */
	public function flush_all() {
		$deleted = class_exists( 'ESF_Twitter_Cache' )
			? ESF_Twitter_Cache::flush_all_cache()
			: false;

		return array(
			'success' => false !== $deleted,
			'deleted' => false !== $deleted ? (int) $deleted : 0,
		);
	}
}
