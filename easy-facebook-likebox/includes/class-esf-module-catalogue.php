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
		const MODULE_SLUGS = array( 'facebook', 'instagram', 'youtube', 'twitter' );

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
			$has_instagram_plan = function_exists( 'esf_instagram_has_instagram_plan' )
				&& esf_instagram_has_instagram_plan();
			$has_twitter_plan   = (
				function_exists( 'efl_fs' ) &&
				method_exists( efl_fs(), 'can_use_premium_code__premium_only' ) &&
				efl_fs()->can_use_premium_code__premium_only() &&
				function_exists( 'esf_twitter_has_twitter_plan' ) &&
				esf_twitter_has_twitter_plan()
			);

			return array(
				'facebook'  => array(
					'name'             => __( 'Facebook Feed', 'easy-facebook-likebox' ),
					'tagline'          => __( 'Posts, albums, events and the Like Box (Page Plugin).', 'easy-facebook-likebox' ),
					'features'         => array(
						__( 'Custom feed of posts, photos and videos', 'easy-facebook-likebox' ),
						__( 'Facebook Page Plugin (Like Box)', 'easy-facebook-likebox' ),
						__( 'Lightbox / popup gallery', 'easy-facebook-likebox' ),
					),
					'configure_url'    => admin_url( 'admin.php?page=easy-facebook-likebox' ),
					'is_modern'        => false,
					'has_inline_oauth' => false,
					'brand_color'      => '#1877f2',
					'brand_color_2'    => '#0a4ea1',
				),
				'instagram' => array(
					'name'             => __( 'Instagram Feed', 'easy-facebook-likebox' ),
					'tagline'          => __( 'Photos, videos and hashtag feeds from your Instagram account.', 'easy-facebook-likebox' ),
					'features'         => array(
						__( 'Account, hashtag and gallery feeds', 'easy-facebook-likebox' ),
						__( 'Lightbox / popup gallery', 'easy-facebook-likebox' ),
						$has_instagram_plan
							? __( 'Shoppable feeds', 'easy-facebook-likebox' )
							: __( 'Shoppable feeds (Pro)', 'easy-facebook-likebox' ),
					),
					'configure_url'    => admin_url( 'admin.php?page=mif' ),
					'is_modern'        => false,
					'has_inline_oauth' => false,
					'brand_color'      => '#e1306c',
					'brand_color_2'    => '#f77737',
				),
				'youtube'   => array(
					'name'             => __( 'YouTube Feed', 'easy-facebook-likebox' ),
					'tagline'          => __( 'Latest videos from your channel via secure OAuth.', 'easy-facebook-likebox' ),
					'features'         => array(
						__( 'Secure OAuth 2.0 connection (no API key)', 'easy-facebook-likebox' ),
						__( 'Modern dashboard with live preview', 'easy-facebook-likebox' ),
						__( 'Customisable layouts and caching', 'easy-facebook-likebox' ),
					),
					'configure_url'    => admin_url( 'admin.php?page=esf-youtube' ),
					'is_modern'        => true,
					'has_inline_oauth' => true,
					'brand_color'      => '#ff0000',
					'brand_color_2'    => '#cc0000',
				),
				'twitter'   => array(
					'name'             => __( 'X / Twitter Feed', 'easy-facebook-likebox' ),
					'tagline'          => $has_twitter_plan
						? __( 'Display your X timeline or any public account.', 'easy-facebook-likebox' )
						: __( 'Display your X timeline or any public account (Pro).', 'easy-facebook-likebox' ),
					'features'         => array(
						__( 'Secure OAuth connection to your X account', 'easy-facebook-likebox' ),
						__( 'DB-backed caching for fast page loads', 'easy-facebook-likebox' ),
						$has_twitter_plan
							? __( 'Public account feeds', 'easy-facebook-likebox' )
							: __( 'Public account feeds (Pro)', 'easy-facebook-likebox' ),
					),
					'configure_url'    => admin_url( 'admin.php?page=esf-twitter' ),
					'is_modern'        => true,
					'has_inline_oauth' => true,
					'supports_public_username' => true,
					'can_add_public_username'  => $has_twitter_plan,
					'brand_color'      => '#000000',
					'brand_color_2'    => '#2a2a2a',
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
				// Modern Instagram uses the same popup OAuth handoff as YouTube / X.
				if ( 'instagram' === $slug ) {
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
