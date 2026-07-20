<?php
/**
 * Canonical admin URL slugs for Easy Social Feed.
 *
 * The main hub moved from the legacy `feed-them-all` slug to `easy-social-feed`
 * in 6.9.0. Legacy URLs redirect automatically so bookmarks keep working.
 *
 * @package Easy_Social_Feed
 * @since   6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_Admin_Paths' ) ) {

	/**
	 * Admin menu slugs, screen IDs, and redirect helpers.
	 *
	 * @since 6.9.0
	 */
	class ESF_Admin_Paths {

		/**
		 * Current main hub admin page slug.
		 *
		 * @since 6.9.0
		 * @var string
		 */
		const HUB_SLUG = 'easy-social-feed';

		/**
		 * Legacy hub slug kept for redirects only.
		 *
		 * @since 6.9.0
		 * @var string
		 */
		const LEGACY_HUB_SLUG = 'feed-them-all';

		/**
		 * Global settings submenu slug.
		 *
		 * @since 6.9.0
		 * @var string
		 */
		const SETTINGS_SLUG = 'esf-settings';

		/**
		 * First-run wizard page slug.
		 *
		 * @since 6.9.0
		 * @var string
		 */
		const WELCOME_SLUG = 'esf_welcome';

		/**
		 * Build the hub admin URL.
		 *
		 * @since 6.9.0
		 * @return string
		 */
		public static function hub_admin_url() {
			return admin_url( 'admin.php?page=' . rawurlencode( self::HUB_SLUG ) );
		}

		/**
		 * Build the global settings admin URL.
		 *
		 * @since 6.9.0
		 *
		 * @param string $tab Optional tab slug.
		 * @return string
		 */
		public static function settings_admin_url( $tab = '' ) {
			$url = admin_url( 'admin.php?page=' . rawurlencode( self::SETTINGS_SLUG ) );
			$tab = sanitize_key( (string) $tab );
			if ( '' !== $tab ) {
				$url = add_query_arg( 'tab', $tab, $url );
			}
			return $url;
		}

		/**
		 * WordPress screen ID for the top-level hub page.
		 *
		 * @since 6.9.0
		 * @return string
		 */
		public static function hub_screen_id() {
			return 'toplevel_page_' . self::HUB_SLUG;
		}

		/**
		 * WordPress screen ID for a hub submenu page.
		 *
		 * @since 6.9.0
		 *
		 * @param string $submenu_slug Submenu page slug.
		 * @return string
		 */
		public static function submenu_screen_id( $submenu_slug ) {
			return sanitize_key( self::HUB_SLUG . '_page_' . (string) $submenu_slug );
		}

		/**
		 * Redirect legacy `?page=feed-them-all` requests to the new hub slug.
		 *
		 * @since 6.9.0
		 * @return void
		 */
		public static function maybe_redirect_legacy_hub() {
			if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
				return;
			}

			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
			if ( self::LEGACY_HUB_SLUG !== $page ) {
				return;
			}

			$target = self::hub_admin_url();
			$args   = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- passthrough query args.
			unset( $args['page'] );
			if ( ! empty( $args ) ) {
				$target = add_query_arg( $args, $target );
			}

			wp_safe_redirect( $target );
			exit;
		}

		/**
		 * Screen IDs for shared admin chrome (notices, review banner, etc.).
		 *
		 * Includes the modern hub ID and legacy submenu IDs that remain registered
		 * under Freemius/add-on pages.
		 *
		 * @since 6.9.0
		 * @return array<int,string>
		 */
		public static function get_esf_admin_screen_ids() {
			return array(
				self::hub_screen_id(),
				'easy-social-feed_page_easy-facebook-likebox',
				'easy-social-feed_page_mif',
				'admin_page_' . self::WELCOME_SLUG,
				'easy-social-feed_page_feed-them-all-addons',
				self::submenu_screen_id( self::SETTINGS_SLUG ),
				// Legacy submenu screen IDs (older installs / direct links).
				'feed-them-all_page_easy-facebook-likebox',
				'feed-them-all_page_mif',
				'feed-them-all_page_esf-instagram',
				'feed-them-all_page_esf-twitter',
				'feed-them-all_page_esf-youtube',
				'feed-them-all_page_feed-them-all-addons',
				'feed-them-all_page_esf-settings',
				// Modern hub submenu screen IDs.
				self::submenu_screen_id( 'easy-facebook-likebox' ),
				self::submenu_screen_id( 'mif' ),
				self::submenu_screen_id( 'esf-instagram' ),
				self::submenu_screen_id( 'esf-twitter' ),
				self::submenu_screen_id( 'esf-youtube' ),
				self::submenu_screen_id( 'feed-them-all-addons' ),
			);
		}
	}
}
