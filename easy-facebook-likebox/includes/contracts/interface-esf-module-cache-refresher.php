<?php
/**
 * Contract for module-specific safe cache refresh providers.
 *
 * @package Easy_Social_Feed
 * @since   6.8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module adapters register with {@see ESF_Safe_Cache_Refresh}.
 *
 * Implementations must:
 * - Hit the live API before replacing any cache row
 * - Never delete existing cache first
 * - Keep existing cache when the API errors or returns an empty payload
 *
 * @since 6.8.2
 */
interface ESF_Module_Cache_Refresher {

	/**
	 * Module slug (facebook, instagram, youtube, twitter, tiktok).
	 *
	 * @since 6.8.2
	 * @return string
	 */
	public function get_module();

	/**
	 * Safely refresh every active cache target for this module.
	 *
	 * @since 6.8.2
	 * @return array{
	 *     success:bool,
	 *     refreshed:int,
	 *     failed:int,
	 *     skipped:int,
	 *     kept:bool,
	 *     errors:array<int,string>
	 * }
	 */
	public function refresh_all();

	/**
	 * Hard-delete this module's feed/API cache rows (no API call).
	 *
	 * Used only after Clear All Caches failed to refresh and the admin
	 * explicitly confirms a force delete.
	 *
	 * @since 6.8.2
	 * @return array{success:bool,deleted:int}
	 */
	public function flush_all();
}
