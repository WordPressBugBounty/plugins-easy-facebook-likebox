<?php
/**
 * Social Wall admin bootstrap (free + Pro).
 *
 * Registers the submenu for everyone and enqueues the React app. Live
 * aggregation / shortcode live under includes/premium/social-wall/.
 *
 * @package Easy_Social_Feed
 * @since   6.8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Social_Wall_Admin
 *
 * @since 6.8.2
 */
class ESF_Social_Wall_Admin {

	/**
	 * Submenu page slug.
	 *
	 * @since 6.8.2
	 * @var string
	 */
	const PAGE_SLUG = 'esf-social-wall';

	/**
	 * Page hook suffix.
	 *
	 * @since 6.8.2
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Singleton.
	 *
	 * @since 6.8.2
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @since 6.8.2
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 6.8.2
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 55 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( 'ESF_Social_Wall_DB_Installer', 'maybe_upgrade' ) );
		add_action( 'rest_api_init', array( $this, 'register_free_rest_routes' ) );
	}

	/**
	 * Register Social Wall submenu (always visible).
	 *
	 * @since 6.8.2
	 * @return void
	 */
	public function register_menu() {
		$parent = class_exists( 'ESF_Admin_Paths' ) ? ESF_Admin_Paths::HUB_SLUG : 'easy-social-feed';
		$pos    = class_exists( 'ESF_Admin_Menu_Order' ) ? ESF_Admin_Menu_Order::SOCIAL_WALL : 48;

		$this->page_hook = add_submenu_page(
			$parent,
			__( 'Social Wall', 'easy-facebook-likebox' ),
			__( 'Social Wall', 'easy-facebook-likebox' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			$pos
		);
	}

	/**
	 * Render admin page mount point.
	 *
	 * @since 6.8.2
	 * @return void
	 */
	public function render_page() {
		include_once FTA_PLUGIN_DIR . 'admin/views/html-admin-page-social-wall.php';
	}

	/**
	 * Enqueue React app on the Social Wall screen.
	 *
	 * @since 6.8.2
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $this->page_hook !== $hook && false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
			return;
		}

		$base_url = defined( 'FTA_PLUGIN_URL' ) ? FTA_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) );
		$base_dir = defined( 'FTA_PLUGIN_DIR' ) ? FTA_PLUGIN_DIR : plugin_dir_path( dirname( __DIR__ ) );

		$asset_path = $base_dir . 'admin/assets/build/social-wall/social-wall.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return;
		}

		$asset = include $asset_path;
		if ( ! is_array( $asset ) ) {
			return;
		}

		$dependencies = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$version      = isset( $asset['version'] ) ? (string) $asset['version'] : ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );

		wp_enqueue_script(
			'esf-social-wall',
			$base_url . 'admin/assets/build/social-wall/social-wall.js',
			$dependencies,
			$version,
			true
		);

		$css_path = $base_dir . 'admin/assets/build/social-wall/social-wall.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'esf-social-wall',
				$base_url . 'admin/assets/build/social-wall/social-wall.css',
				array( 'wp-components' ),
				$version
			);
		}

		wp_set_script_translations( 'esf-social-wall', 'easy-facebook-likebox' );

		$can_use_pro = function_exists( 'esf_social_wall_can_use_pro' ) && esf_social_wall_can_use_pro();
		$upgrade_url = '';
		if ( function_exists( 'efl_fs' ) && is_object( efl_fs() ) && method_exists( efl_fs(), 'get_upgrade_url' ) ) {
			$upgrade_url = (string) efl_fs()->get_upgrade_url();
		}

		$module_links = array();
		foreach ( function_exists( 'esf_social_wall_supported_modules' ) ? esf_social_wall_supported_modules() : array() as $slug ) {
			if ( function_exists( 'esf_social_wall_module_filter_label' ) ) {
				$label = esf_social_wall_module_filter_label( $slug );
			} elseif ( function_exists( 'esf_social_wall_module_label' ) ) {
				$label = esf_social_wall_module_label( $slug );
			} else {
				$label = $slug;
			}
			$module_links[ $slug ] = array(
				'label'  => $label,
				'active' => function_exists( 'esf_social_wall_is_module_active' ) && esf_social_wall_is_module_active( $slug ),
				'url'    => $this->get_module_admin_url( $slug ),
			);
		}

		$layouts = array();
		if ( $can_use_pro && class_exists( '\EasySocialFeed\Layouts\LayoutRegistry' ) ) {
			$layouts = \EasySocialFeed\Layouts\LayoutRegistry::for( 'social_wall' )->to_array();
		}

		// Frontend feed CSS so live preview matches the public wall.
		$feed_css = $base_dir . 'includes/premium/social-wall/frontend/css/esf-social-wall-feed.css';
		if ( $can_use_pro && file_exists( $feed_css ) ) {
			wp_enqueue_style(
				'esf-social-wall-feed',
				$base_url . 'includes/premium/social-wall/frontend/css/esf-social-wall-feed.css',
				array( 'esf-social-wall' ),
				(string) filemtime( $feed_css )
			);

			$layout_css_dir = $base_dir . 'includes/premium/social-wall/frontend/css/layouts/';
			foreach ( array( 'grid', 'row', 'masonry', 'carousel' ) as $layout_slug ) {
				$layout_css = $layout_css_dir . $layout_slug . '.css';
				if ( ! file_exists( $layout_css ) ) {
					continue;
				}
				wp_enqueue_style(
					'esf-social-wall-layout-' . $layout_slug,
					$base_url . 'includes/premium/social-wall/frontend/css/layouts/' . $layout_slug . '.css',
					array( 'esf-social-wall-feed' ),
					(string) filemtime( $layout_css )
				);
			}

			$this->enqueue_preview_lightbox_assets( $base_dir, $base_url );

			// Shared carousel runtime for live preview rebind.
			$carousel_js = $base_dir . 'admin/shared/frontend/js/esf-carousel__premium_only.js';
			if ( file_exists( $carousel_js ) ) {
				wp_enqueue_script(
					'esf-carousel',
					$base_url . 'admin/shared/frontend/js/esf-carousel__premium_only.js',
					array(),
					(string) filemtime( $carousel_js ),
					true
				);
			}

			$filter_js = $base_dir . 'includes/premium/social-wall/frontend/js/esf-social-wall-filter__premium_only.js';
			if ( file_exists( $filter_js ) ) {
				wp_enqueue_script(
					'esf-social-wall-filter',
					$base_url . 'includes/premium/social-wall/frontend/js/esf-social-wall-filter__premium_only.js',
					array(),
					(string) filemtime( $filter_js ),
					true
				);
			}
			$lm_js = $base_dir . 'includes/premium/social-wall/frontend/js/esf-social-wall-load-more__premium_only.js';
			if ( file_exists( $lm_js ) ) {
				wp_enqueue_script(
					'esf-social-wall-load-more',
					$base_url . 'includes/premium/social-wall/frontend/js/esf-social-wall-load-more__premium_only.js',
					array(),
					(string) filemtime( $lm_js ),
					true
				);
			}
			$row_wave_js = $base_dir . 'includes/premium/social-wall/frontend/js/esf-social-wall-row-wave__premium_only.js';
			if ( file_exists( $row_wave_js ) ) {
				wp_enqueue_script(
					'esf-social-wall-row-wave',
					$base_url . 'includes/premium/social-wall/frontend/js/esf-social-wall-row-wave__premium_only.js',
					array(),
					(string) filemtime( $row_wave_js ),
					true
				);
			}
			if ( function_exists( 'esf_register_masonry_pack_script' ) ) {
				esf_register_masonry_pack_script();
				wp_enqueue_script( 'esf-masonry-pack' );
			}
			$masonry_js = $base_dir . 'includes/premium/social-wall/frontend/js/esf-social-wall-masonry__premium_only.js';
			if ( file_exists( $masonry_js ) ) {
				wp_enqueue_script(
					'esf-social-wall-masonry',
					$base_url . 'includes/premium/social-wall/frontend/js/esf-social-wall-masonry__premium_only.js',
					array( 'esf-masonry-pack' ),
					(string) filemtime( $masonry_js ),
					true
				);
			}
		}

		wp_localize_script(
			'esf-social-wall',
			'esfSocialWallBootstrap',
			array(
				'restUrl'      => esc_url_raw( rest_url() ),
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
				'pluginUrl'    => esc_url_raw( $base_url ),
				'logoUrl'      => esc_url_raw( $base_url . 'admin/assets/images/plugin-logo.png' ),
				'hasComboPlan' => (bool) $can_use_pro,
				'upgradeUrl'   => esc_url_raw( $upgrade_url ),
				'hubUrl'       => esc_url_raw( class_exists( 'ESF_Admin_Paths' ) ? ESF_Admin_Paths::hub_admin_url() : admin_url( 'admin.php?page=easy-social-feed' ) ),
				'modules'      => $module_links,
				'layouts'      => $layouts,
				'settings'     => function_exists( 'esf_social_wall_get_settings' ) ? esf_social_wall_get_settings() : array(),
			)
		);

		// Load More REST base for live preview (same global as frontend).
		wp_localize_script(
			'esf-social-wall',
			'esfSocialWallPublic',
			array(
				'restUrl' => esc_url_raw( rest_url( 'esf/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'noPostsFilter' => function_exists( 'esf_translate_registry_string' )
						? esf_translate_registry_string( 'sw_no_posts_filter', 'No posts found for this network.' )
						: __( 'No posts found for this network.', 'easy-facebook-likebox' ),
				),
			)
		);
	}

	/**
	 * Free REST: capability probe only (no wall data writes).
	 *
	 * @since 6.8.2
	 * @return void
	 */
	public function register_free_rest_routes() {
		register_rest_route(
			'esf/v1',
			'/social-wall/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Status payload for the React shell.
	 *
	 * @since 6.8.2
	 * @return WP_REST_Response
	 */
	public function get_status() {
		return rest_ensure_response(
			array(
				'hasComboPlan' => function_exists( 'esf_social_wall_can_use_pro' ) && esf_social_wall_can_use_pro(),
			)
		);
	}

	/**
	 * Enqueue FancyBox + module lightboxes for admin live preview popups.
	 *
	 * Popup layout CSS lives in each module’s feed stylesheet (YouTube uses a
	 * dedicated lightbox CSS). Those styles are enqueued with the same handles
	 * the modules use so preview matches the frontend and WordPress dedupes.
	 *
	 * Social Wall only — does not change Facebook/Instagram admin codepaths.
	 *
	 * @since 6.8.2
	 * @param string $base_dir Plugin directory.
	 * @param string $base_url Plugin URL.
	 * @return void
	 */
	private function enqueue_preview_lightbox_assets( $base_dir, $base_url ) {
		$fancybox_style  = $base_dir . 'frontend/assets/premium/jquery.fancybox.min.css';
		$fancybox_script = $base_dir . 'frontend/assets/premium/jquery.fancybox.min.js';
		if ( ! file_exists( $fancybox_script ) ) {
			return;
		}

		$version = defined( 'FTA_VERSION' ) ? FTA_VERSION : null;

		wp_enqueue_style(
			'jquery.fancybox.min',
			$base_url . 'frontend/assets/premium/jquery.fancybox.min.css',
			array(),
			file_exists( $fancybox_style ) ? (string) filemtime( $fancybox_style ) : null
		);
		if ( function_exists( 'esf_enqueue_fancybox_controls_style' ) ) {
			esf_enqueue_fancybox_controls_style();
		}
		wp_enqueue_script(
			'jquery.fancybox.min',
			$base_url . 'frontend/assets/premium/jquery.fancybox.min.js',
			array( 'jquery' ),
			(string) filemtime( $fancybox_script ),
			true
		);

		// Module popup chrome/layout CSS (same handles as frontend Social Wall).
		$styles = array(
			array(
				'handle' => 'esf-facebook-feed',
				'path'   => $base_dir . 'facebook/frontend/assets/css/esf-facebook-feed.css',
				'url'    => $base_url . 'facebook/frontend/assets/css/esf-facebook-feed.css',
			),
			array(
				'handle' => 'esf-instagram-feed',
				'path'   => $base_dir . 'instagram/frontend/assets/css/esf-ig-feed.css',
				'url'    => $base_url . 'instagram/frontend/assets/css/esf-ig-feed.css',
			),
			array(
				'handle' => 'esf-twitter-feed',
				'path'   => $base_dir . 'twitter/frontend/assets/css/esf-twitter-feed.css',
				'url'    => $base_url . 'twitter/frontend/assets/css/esf-twitter-feed.css',
			),
			array(
				'handle' => 'esf-tiktok-feed',
				'path'   => $base_dir . 'tiktok/frontend/assets/css/esf-tiktok-feed.css',
				'url'    => $base_url . 'tiktok/frontend/assets/css/esf-tiktok-feed.css',
			),
			array(
				'handle' => 'esf-youtube-lightbox',
				'path'   => $base_dir . 'youtube/frontend/assets/css/esf-youtube-lightbox__premium_only.css',
				'url'    => $base_url . 'youtube/frontend/assets/css/esf-youtube-lightbox__premium_only.css',
			),
		);
		foreach ( $styles as $style ) {
			if ( ! file_exists( $style['path'] ) ) {
				continue;
			}
			if ( wp_style_is( $style['handle'], 'registered' ) || wp_style_is( $style['handle'], 'enqueued' ) ) {
				wp_enqueue_style( $style['handle'] );
				continue;
			}
			wp_enqueue_style(
				$style['handle'],
				$style['url'],
				array(),
				null !== $version ? $version : (string) filemtime( $style['path'] )
			);
		}

		$default_deps = array( 'jquery', 'jquery.fancybox.min' );
		$lightboxes   = array(
			array(
				'handle' => 'esf-facebook-lightbox',
				'path'   => $base_dir . 'facebook/frontend/assets/js/esf-facebook-lightbox__premium_only.js',
				'url'    => $base_url . 'facebook/frontend/assets/js/esf-facebook-lightbox__premium_only.js',
				'local'  => 'esfFacebookLightbox',
				'deps'   => $default_deps,
				'data'   => function_exists( 'esf_facebook_get_lightbox_localize_data' )
					? 'esf_facebook_get_lightbox_localize_data'
					: null,
			),
			array(
				'handle' => 'esf-instagram-lightbox',
				'path'   => $base_dir . 'instagram/frontend/assets/js/esf-ig-lightbox__premium_only.js',
				'url'    => $base_url . 'instagram/frontend/assets/js/esf-ig-lightbox__premium_only.js',
				'local'  => 'esfInstagramLightbox',
				'deps'   => $default_deps,
				'data'   => function_exists( 'esf_instagram_get_lightbox_localize_data' )
					? 'esf_instagram_get_lightbox_localize_data'
					: null,
			),
			array(
				'handle' => 'esf-tiktok-lightbox',
				'path'   => $base_dir . 'tiktok/frontend/assets/js/esf-tiktok-lightbox__premium_only.js',
				'url'    => $base_url . 'tiktok/frontend/assets/js/esf-tiktok-lightbox__premium_only.js',
				'local'  => 'esfTikTokLightbox',
				'deps'   => $default_deps,
				'data'   => function_exists( 'esf_tiktok_get_lightbox_localize_data' )
					? 'esf_tiktok_get_lightbox_localize_data'
					: null,
			),
			array(
				'handle' => 'esf-twitter-lightbox',
				'path'   => $base_dir . 'twitter/frontend/assets/js/esf-twitter-lightbox__premium_only.js',
				'url'    => $base_url . 'twitter/frontend/assets/js/esf-twitter-lightbox__premium_only.js',
				'local'  => 'esfTwitterLightbox',
				'deps'   => $default_deps,
				'data'   => function_exists( 'esf_twitter_get_lightbox_localize_data' )
					? 'esf_twitter_get_lightbox_localize_data'
					: null,
			),
			array(
				'handle' => 'esf-twitter-lightbox-comments',
				'path'   => $base_dir . 'twitter/frontend/assets/js/esf-twitter-lightbox-comments__premium_only.js',
				'url'    => $base_url . 'twitter/frontend/assets/js/esf-twitter-lightbox-comments__premium_only.js',
				'local'  => '',
				'deps'   => array( 'jquery', 'jquery.fancybox.min', 'esf-twitter-lightbox' ),
				'data'   => null,
			),
			array(
				'handle' => 'esf-youtube-lightbox',
				'path'   => $base_dir . 'youtube/frontend/assets/js/esf-youtube-lightbox__premium_only.js',
				'url'    => $base_url . 'youtube/frontend/assets/js/esf-youtube-lightbox__premium_only.js',
				'local'  => 'esfYoutubeTranslations',
				'deps'   => $default_deps,
				'data'   => function_exists( 'esf_youtube_get_lightbox_localize_data' )
					? 'esf_youtube_get_lightbox_localize_data'
					: null,
			),
		);

		foreach ( $lightboxes as $box ) {
			if ( ! file_exists( $box['path'] ) ) {
				continue;
			}
			wp_enqueue_script(
				$box['handle'],
				$box['url'],
				$box['deps'],
				(string) filemtime( $box['path'] ),
				true
			);
			if ( is_string( $box['data'] ) && function_exists( $box['data'] ) ) {
				$data                   = call_user_func( $box['data'] );
				$data                   = is_array( $data ) ? $data : array();
				$data['isAdminPreview'] = true;
				wp_localize_script(
					$box['handle'],
					$box['local'],
					$data
				);
			}
		}

		/*
		 * Wall-order FancyBox coordinator — reuses module adapters registered above.
		 * Enqueued after module lightboxes so window.esfSwPopupAdapters is populated.
		 */
		$popup_js = $base_dir . 'includes/premium/social-wall/frontend/js/esf-social-wall-popup__premium_only.js';
		if ( file_exists( $popup_js ) ) {
			wp_enqueue_script(
				'esf-social-wall-popup',
				$base_url . 'includes/premium/social-wall/frontend/js/esf-social-wall-popup__premium_only.js',
				array( 'jquery', 'jquery.fancybox.min' ),
				(string) filemtime( $popup_js ),
				true
			);
		}
	}

	/**
	 * Best-effort admin URL for a module feeds screen.
	 *
	 * @since 6.8.2
	 * @param string $module Module slug.
	 * @return string
	 */
	private function get_module_admin_url( $module ) {
		$map  = array(
			'facebook'  => 'esf-facebook',
			'instagram' => 'esf-instagram',
			'twitter'   => 'esf-twitter',
			'youtube'   => 'esf-youtube',
			'tiktok'    => 'esf-tiktok',
		);
		$slug = isset( $map[ $module ] ) ? $map[ $module ] : '';
		if ( '' === $slug ) {
			return admin_url( 'admin.php?page=easy-social-feed' );
		}

		return admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
	}
}
