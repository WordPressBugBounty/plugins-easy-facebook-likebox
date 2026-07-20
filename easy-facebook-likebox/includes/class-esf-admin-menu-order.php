<?php
/**
 * Fixed submenu positions for the Easy Social Feed admin menu.
 *
 * Gaps between slots (10, 20, 30, …) keep module order stable when a module
 * is deactivated and its submenu is not registered.
 *
 * @package Easy_Social_Feed
 * @since   6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_Admin_Menu_Order' ) ) {

	/**
	 * Admin submenu ordering helpers.
	 *
	 * @since 6.9.0
	 */
	class ESF_Admin_Menu_Order {

		/** @var int */
		const PARENT_DUPLICATE = 0;

		/** @var int */
		const FACEBOOK = 10;

		/** @var int */
		const INSTAGRAM = 20;

		/** @var int */
		const TWITTER = 30;

		/** @var int */
		const YOUTUBE = 40;

		/** @var int */
		const SETTINGS = 50;

		/** @var int First slot for third-party items (Freemius, add-ons). */
		const AFTER_MODULES = 60;

		/** @var string */
		const PARENT_SLUG = 'easy-social-feed';

		/**
		 * Map submenu slugs to fixed positions.
		 *
		 * @since 6.9.0
		 * @return array<string,int>
		 */
		public static function get_slug_positions() {
			return array(
				'easy-facebook-likebox' => self::FACEBOOK,
				'esf-youtube'           => self::YOUTUBE,
				'esf-instagram'         => self::INSTAGRAM,
				'mif'                   => self::INSTAGRAM,
				'esf-twitter'           => self::TWITTER,
				'esf-settings'          => self::SETTINGS,
			);
		}

		/**
		 * Normalize a submenu slug (strip query args).
		 *
		 * @since 6.9.0
		 * @param string $slug Raw submenu slug.
		 * @return string
		 */
		public static function normalize_slug( $slug ) {
			$slug = (string) $slug;
			if ( false !== strpos( $slug, '?' ) ) {
				$slug = strstr( $slug, '?', true );
			}
			return $slug;
		}

		/**
		 * Reorder easy-social-feed submenus: Facebook, Instagram, X/Twitter, YouTube, Settings.
		 *
		 * @since 6.9.0
		 * @return void
		 */
		public static function reorder_submenus() {
			global $submenu;

			if ( empty( $submenu[ self::PARENT_SLUG ] ) || ! is_array( $submenu[ self::PARENT_SLUG ] ) ) {
				return;
			}

			$slug_positions = self::get_slug_positions();
			$parent_dupes   = array();
			$known          = array();
			$unknown        = array();

			foreach ( $submenu[ self::PARENT_SLUG ] as $item ) {
				if ( ! is_array( $item ) || empty( $item[2] ) ) {
					$unknown[] = $item;
					continue;
				}

				$slug = self::normalize_slug( $item[2] );

				if ( self::PARENT_SLUG === $slug ) {
					$parent_dupes[] = $item;
					continue;
				}

				if ( isset( $slug_positions[ $slug ] ) ) {
					$position = (int) $slug_positions[ $slug ];
					if ( ! isset( $known[ $position ] ) ) {
						$known[ $position ] = $item;
					} else {
						$unknown[] = $item;
					}
					continue;
				}

				$unknown[] = $item;
			}

			$ordered = array();

			if ( ! empty( $parent_dupes ) ) {
				$ordered[ self::PARENT_DUPLICATE ] = $parent_dupes[0];
			}

			foreach ( $known as $position => $item ) {
				$ordered[ (int) $position ] = $item;
			}

			$next = self::AFTER_MODULES;
			foreach ( $unknown as $item ) {
				while ( isset( $ordered[ $next ] ) ) {
					$next += 10;
				}
				$ordered[ $next ] = $item;
				$next            += 10;
			}

			ksort( $ordered, SORT_NUMERIC );
			$submenu[ self::PARENT_SLUG ] = $ordered;
		}
	}
}
