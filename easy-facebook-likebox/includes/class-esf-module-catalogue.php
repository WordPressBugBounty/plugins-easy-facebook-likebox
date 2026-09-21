<?php
/**
 * Shared module catalogue for welcome wizard and admin hub.
 *
 * Centralises module metadata (names, features, brand colours, configure URLs)
 * so React surfaces do not duplicate slugs or hard-code admin links.
 *
 * @package Easy_Social_Feed
 * @since   6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_Module_Catalogue' ) ) {

	/**
	 * Builds enriched module rows for admin React consumers.
	 *
	 * @since 6.9.0
	 */
	class ESF_Module_Catalogue {

		/**
		 * Module slugs exposed in admin hub / welcome flows.
		 *
		 * @since 6.9.0
		 * @var array<int,string>
		 */
		const MODULE_SLUGS = array( 'facebook', 'instagram', 'youtube', 'twitter', 'tiktok' );

		/**
		 * Return module catalogue rows with live activation status.
		 *
		 * @since 6.9.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public static function get_modules() {
			$catalogue = self::get_base_catalogue();
			$ordered   = array();

			foreach ( self::MODULE_SLUGS as $slug ) {
				if ( ! isset( $catalogue[ $slug ] ) ) {
					continue;
				}

				$status = class_exists( 'ESF_Settings' )
					? ESF_Settings::get_module_status( $slug )
					: 'activated';

				$meta = $catalogue[ $slug ];
				$meta = self::apply_module_system_urls( $slug, $meta );

				$ordered[] = array_merge(
					array(
						'slug'   => $slug,
						'status' => $status,
					),
					$meta
				);
			}

			return $ordered;
		}

		/**
		 * Static metadata per module (before status / configure URL resolution).
		 *
		 * @since 6.9.0
		 *
		 * @return array<string,array<string,mixed>>
		 */
		private static function get_base_catalogue() {
			$has_twitter_plan = (
				function_exists( 'efl_fs' ) &&
				method_exists( efl_fs(), 'can_use_premium_code__premium_only' ) &&
				efl_fs()->can_use_premium_code__premium_only() &&
				function_exists( 'esf_twitter_has_twitter_plan' ) &&
				esf_twitter_has_twitter_plan()
			);

			return array(
				'facebook'  => array(
					'name'             => __( 'Facebook Feed', 'easy-facebook-likebox' ),
					'tagline'          => __( 'Page posts, albums, events, and the Page Plugin.', 'easy-facebook-likebox' ),
					'features'         => array(
						__( 'Timeline, photos, videos, albums, and events from your Pages', 'easy-facebook-likebox' ),
						__( 'Grid, masonry, carousel, and row layouts with popup gallery', 'easy-facebook-likebox' ),
						__( 'Load more and infinite scroll', 'easy-facebook-likebox' ),
						__( 'Visual moderation to hide posts', 'easy-facebook-likebox' ),
						__( 'Multifeed to combine multiple Pages', 'easy-facebook-likebox' ),
					),
					'configure_url'    => admin_url( 'admin.php?page=easy-facebook-likebox' ),
					'is_modern'        => false,
					'has_inline_oauth' => false,
					'brand_color'      => '#1877f2',
					'brand_color_2'    => '#0a4ea1',
				),
				'instagram' => array(
					'name'             => __( 'Instagram Feed', 'easy-facebook-likebox' ),
					'tagline'          => __( 'Photos, videos, stories, and hashtag feeds.', 'easy-facebook-likebox' ),
					'features'         => array(
						__( 'User, hashtag, and tagged media from your account', 'easy-facebook-likebox' ),
						__( 'Grid, row, carousel, and masonry layouts with lightbox', 'easy-facebook-likebox' ),
						__( 'Load more and infinite scroll', 'easy-facebook-likebox' ),
						__( 'Visual moderation to hide posts', 'easy-facebook-likebox' ),
						__( 'Multifeed to combine multiple accounts', 'easy-facebook-likebox' ),
					),
					'configure_url'    => admin_url( 'admin.php?page=mif' ),
					'is_modern'        => true,
					'has_inline_oauth' => false,
					'brand_color'      => '#e1306c',
					'brand_color_2'    => '#f77737',
				),
				'youtube'   => array(
					'name'             => __( 'YouTube Feed', 'easy-facebook-likebox' ),
					'tagline'          => __( 'Channel, playlist, and search video feeds.', 'easy-facebook-likebox' ),
					'features'         => array(
						__( 'Videos from channels, playlists, and YouTube search', 'easy-facebook-likebox' ),
						__( 'Grid, row, carousel, and masonry layouts with lightbox', 'easy-facebook-likebox' ),
						__( 'Load more and infinite scroll', 'easy-facebook-likebox' ),
						__( 'Visual moderation to hide videos', 'easy-facebook-likebox' ),
						__( 'Multifeed to combine multiple accounts', 'easy-facebook-likebox' ),
					),
					'configure_url'    => admin_url( 'admin.php?page=esf-youtube' ),
					'is_modern'        => true,
					'has_inline_oauth' => true,
					'brand_color'      => '#ff0000',
					'brand_color_2'    => '#cc0000',
				),
				'twitter'   => array(
					'name'             => __( 'X / Twitter Feed', 'easy-facebook-likebox' ),
					'tagline'          => __( 'Your timeline, or any public X account.', 'easy-facebook-likebox' ),
					'features'         => array(
						__( 'Posts from your connected X account', 'easy-facebook-likebox' ),
						__( 'Timeline, row, grid, and masonry layouts with lightbox', 'easy-facebook-likebox' ),
						__( 'Load more and infinite scroll', 'easy-facebook-likebox' ),
						__( 'Visual moderation to hide posts', 'easy-facebook-likebox' ),
						__( 'Multifeed to combine multiple accounts', 'easy-facebook-likebox' ),
					),
					'configure_url'    => admin_url( 'admin.php?page=esf-twitter' ),
					'is_modern'        => true,
					'has_inline_oauth' => true,
					'supports_public_username' => true,
					'can_add_public_username'  => $has_twitter_plan,
					'brand_color'      => '#000000',
					'brand_color_2'    => '#2a2a2a',
				),
				'tiktok'    => array(
					'name'             => __( 'TikTok Feed', 'easy-facebook-likebox' ),
					'tagline'          => __( 'Videos from your TikTok account on your site.', 'easy-facebook-likebox' ),
					'features'         => array(
						__( 'Videos from your connected TikTok account', 'easy-facebook-likebox' ),
						__( 'Grid and row layouts with popup lightbox, more coming', 'easy-facebook-likebox' ),
						__( 'Load more and infinite scroll', 'easy-facebook-likebox' ),
						__( 'Multifeed to combine multiple accounts', 'easy-facebook-likebox' ),
					),
					'configure_url'    => admin_url( 'admin.php?page=esf-tiktok' ),
					'is_modern'        => true,
					'has_inline_oauth' => true,
					'brand_color'      => '#000000',
					'brand_color_2'    => '#FE2C55',
				),
			);
		}

		/**
		 * Apply legacy/modern admin URLs for modules registered in ESF_Module_System.
		 *
		 * Does not load or alter legacy module admin code — only picks the correct link.
		 *
		 * @since 6.9.0
		 *
		 * @param string               $slug Module slug.
		 * @param array<string,mixed>  $meta Catalogue row metadata.
		 * @return array<string,mixed>
		 */
		private static function apply_module_system_urls( $slug, array $meta ) {
			if ( ! class_exists( 'ESF_Module_System' ) || ! ESF_Module_System::get_module_config( $slug ) ) {
				return $meta;
			}

			$legacy_callback = self::get_legacy_data_callback( $slug );
			if ( null === $legacy_callback ) {
				return $meta;
			}

			$status = ESF_Module_System::get_status( $slug, $legacy_callback );

			if ( ! empty( $status['using_modern'] ) && ! empty( $status['modern_admin_url'] ) ) {
				$meta['configure_url'] = (string) $status['modern_admin_url'];
				$meta['is_modern']     = true;
				// Modern Instagram / Facebook use the same popup OAuth handoff as YouTube / X.
				if ( in_array( $slug, array( 'instagram', 'facebook' ), true ) ) {
					$meta['has_inline_oauth'] = true;
				}
			} elseif ( ! empty( $status['legacy_admin_url'] ) ) {
				$meta['configure_url']    = (string) $status['legacy_admin_url'];
				$meta['is_modern']        = false;
				$meta['has_inline_oauth'] = false;
			}

			return $meta;
		}

		/**
		 * Resolve the legacy-data callback for a module slug, if registered.
		 *
		 * @since 6.9.0
		 *
		 * @param string $slug Module slug.
		 * @return (callable(): bool)|null
		 */
		private static function get_legacy_data_callback( $slug ) {
			$slug = sanitize_key( (string) $slug );

			if ( 'instagram' === $slug && function_exists( 'esf_instagram_has_legacy_data' ) ) {
				return static function () {
					return (bool) esf_instagram_has_legacy_data();
				};
			}

			if ( 'facebook' === $slug && function_exists( 'esf_facebook_has_legacy_data' ) ) {
				return static function () {
					return (bool) esf_facebook_has_legacy_data();
				};
			}

			return null;
		}
	}
}
