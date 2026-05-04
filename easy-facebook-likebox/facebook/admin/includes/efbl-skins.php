<?php
/*
* Stop execution if someone tried to get file directly.
*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

//======================================================================
// Management of Facebook Skins
//======================================================================

if ( ! class_exists( 'EFBL_SKINS' ) ) :
	class EFBL_SKINS {

		/**
		 * Transient key used as a soft lock so concurrent requests do not
		 * race the default-skin seeding routine.
		 */
		const SEEDING_LOCK_KEY = 'efbl_seeding_lock';

		function __construct() {

			// Skins-related hooks run late on init / admin_init so that any
			// dependencies registered by other plugins / the parent plugin
			// (FTA, post types, options bootstrap, etc.) are guaranteed to
			// be in place before our register + load + seed routines fire.
			add_action( 'init', array( $this, 'efbl_skins_register' ), 100 );

			// Seeding (which performs writes) is moved off the per-request
			// path to admin_init. This eliminates ~all duplicate creation
			// from frontend / cron / REST hits, while still letting fresh
			// installs auto-create their default skins on the first admin
			// visit. The idempotent ensure_skin_for_layout() helper is the
			// last line of defence even if this hook fires twice.
			add_action( 'admin_init', array( $this, 'efbl_default_skins' ), 100 );

			// Skins must be loaded into $GLOBALS['efbl_skins'] AFTER the
			// post type is registered, so this runs at a later priority.
			add_action( 'init', array( $this, 'efbl_skins' ), 110 );

		}

		/*
		 * Register skins post type
		 */
		public function efbl_skins_register() {

			$args = array(
				'public'              => false,
				'label'               => __( 'Facebook Skins', 'easy-facebook-likebox' ),
				'show_in_menu'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'hierarchical'        => true,
				'menu_position'       => null,
			);

			register_post_type( 'efbl_skins', $args );

		}

		/**
		 * Look up an existing efbl_skins post for the given layout. Returns
		 * the canonical post ID if one already exists, otherwise creates a
		 * new post with the supplied $defaults and writes the layout meta.
		 *
		 * This is the core of the duplicate-prevention fix. Even if the
		 * fta_settings option is wiped/reset, this method will not create
		 * a second post for a layout that already has one in wp_posts.
		 *
		 * @param string $layout   The layout key (half, full, grid, etc.)
		 * @param array  $defaults wp_insert_post args used only when the
		 *                         skin doesn't already exist.
		 * @return int Post ID, or 0 on failure.
		 */
		protected function ensure_skin_for_layout( $layout, $defaults ) {

			if ( empty( $layout ) ) {
				return 0;
			}

			$existing = get_posts(
				array(
					'post_type'        => 'efbl_skins',
					'post_status'      => array( 'publish', 'draft', 'pending' ),
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'meta_key'         => 'layout',
					'meta_value'       => $layout,
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);

			if ( ! empty( $existing ) ) {
				return (int) $existing[0];
			}

			$new_id = wp_insert_post( $defaults, true );

			if ( is_wp_error( $new_id ) || ! $new_id ) {
				return 0;
			}

			update_post_meta( (int) $new_id, 'layout', $layout );

			return (int) $new_id;
		}

		/**
		 * Acquire a short transient lock so two concurrent admin_init
		 * requests cannot both run the seeding routine end-to-end.
		 *
		 * @return bool True if lock acquired, false if another request is seeding.
		 */
		protected function acquire_seeding_lock() {
			if ( get_transient( self::SEEDING_LOCK_KEY ) ) {
				return false;
			}
			set_transient( self::SEEDING_LOCK_KEY, 1, 30 );
			return true;
		}

		/**
		 * Release the seeding lock once the routine has finished.
		 */
		protected function release_seeding_lock() {
			delete_transient( self::SEEDING_LOCK_KEY );
		}

		/*
		 * Add default skins on install
		 */
		public function efbl_default_skins() {

			$FTA = new Feed_Them_All();

			$fta_settings = $FTA->fta_get_settings();

			if ( ! is_array( $fta_settings ) ) {
				$fta_settings = array();
			}

			$is_pro_eligible = function_exists( 'efl_fs' )
				&& efl_fs()->can_use_premium_code__premium_only()
				&& ( efl_fs()->is_plan( 'facebook_premium', true ) || efl_fs()->is_plan( 'combo_premium', true ) );

			// Fast path: if every required key is already populated, skip
			// the seeding routine entirely. Keeps admin_init cheap on
			// subsequent loads.
			$all_seeded = ! empty( $fta_settings['plugins']['facebook']['default_skin_id'] )
				&& ! empty( $fta_settings['plugins']['facebook']['row_default_skin_id'] )
				&& ! empty( $fta_settings['plugins']['facebook']['default_page_id'] )
				&& ( ! $is_pro_eligible
					|| (
						! empty( $fta_settings['plugins']['facebook']['pro_default_skins_added'] )
						&& ! empty( $fta_settings['plugins']['facebook']['carousel_default_skin_id'] )
					) );

			if ( $all_seeded ) {
				return;
			}

			if ( ! $this->acquire_seeding_lock() ) {
				return;
			}

			try {
				/*
				 * Default Half + Full + Thumbnail skins (free tier).
				 * The half-width post becomes the canonical default_skin_id.
				 */
				if ( empty( $fta_settings['plugins']['facebook']['default_skin_id'] ) ) {

					$efbl_new_skins = array(
						'post_title'   => __( 'Skin - Half Width', 'easy-facebook-likebox' ),
						'post_content' => __( 'This is the half width demo skin created by plugin automatically with default values. You can edit it and change the look & feel of your Facebook Feeds.', 'easy-facebook-likebox' ),
						'post_type'    => 'efbl_skins',
						'post_status'  => 'publish',
						'post_author'  => get_current_user_id(),
					);

					$efbl_new_skins = apply_filters( 'efbl_default_skin', $efbl_new_skins );

					$skin_id = $this->ensure_skin_for_layout( 'half', $efbl_new_skins );

					$efbl_new_skin_full = array(
						'post_title'   => __( 'Skin - Full Width', 'easy-facebook-likebox' ),
						'post_content' => __( 'This is the Full width demo skin created by plugin automatically with default values. You can edit it and change the look & feel of your Facebook Feeds.', 'easy-facebook-likebox' ),
						'post_type'    => 'efbl_skins',
						'post_status'  => 'publish',
						'post_author'  => get_current_user_id(),
					);
					$this->ensure_skin_for_layout( 'full', $efbl_new_skin_full );

					$efbl_new_skin_thumbnail = array(
						'post_title'   => __( 'Skin - Thumbnail', 'easy-facebook-likebox' ),
						'post_content' => __( 'This is the Thumbnail demo skin created by plugin automatically with default values. You can edit it and change the look & feel of your Facebook Feeds.', 'easy-facebook-likebox' ),
						'post_type'    => 'efbl_skins',
						'post_status'  => 'publish',
						'post_author'  => get_current_user_id(),
					);
					$this->ensure_skin_for_layout( 'thumbnail', $efbl_new_skin_thumbnail );

					if ( $skin_id ) {
						$fta_settings['plugins']['facebook']['default_skin_id'] = $skin_id;
						update_option( 'fta_settings', $fta_settings );
					}
				}

				/*
				 * Default Row skin.
				 */
				if ( empty( $fta_settings['plugins']['facebook']['row_default_skin_id'] ) ) {

					$efbl_new_skin_row = array(
						'post_title'   => __( 'Skin - Row', 'easy-facebook-likebox' ),
						'post_content' => __( 'This is the Row demo skin created by the plugin automatically with default values. You can edit it and change the look & feel of your Facebook Feeds.', 'easy-facebook-likebox' ),
						'post_type'    => 'efbl_skins',
						'post_status'  => 'publish',
						'post_author'  => get_current_user_id(),
					);

					$efbl_new_skin_row_id = $this->ensure_skin_for_layout( 'row', $efbl_new_skin_row );

					if ( $efbl_new_skin_row_id ) {
						$fta_settings['plugins']['facebook']['row_default_skin_id'] = $efbl_new_skin_row_id;
						update_option( 'fta_settings', $fta_settings );
					}
				}

				/*
				 * Pro-only default skins (grid + masonry).
				 */
				if ( $is_pro_eligible && empty( $fta_settings['plugins']['facebook']['pro_default_skins_added'] ) ) {

					$efbl_new_skin_grid = array(
						'post_title'   => __( 'Skin - Grid', 'easy-facebook-likebox' ),
						'post_content' => __( 'This is the Grid demo skin created by plugin automatically with default values. You can edit it and change the look & feel of your Facebook Feeds.', 'easy-facebook-likebox' ),
						'post_type'    => 'efbl_skins',
						'post_status'  => 'publish',
						'post_author'  => get_current_user_id(),
					);

					$grid_id = $this->ensure_skin_for_layout( 'grid', $efbl_new_skin_grid );

					$efbl_new_skin_masonry = array(
						'post_title'   => __( 'Skin - Masonry', 'easy-facebook-likebox' ),
						'post_content' => __( 'This is the Masonry demo skin created by plugin automatically with default values. You can edit it and change the look & feel of your Facebook Feeds.', 'easy-facebook-likebox' ),
						'post_type'    => 'efbl_skins',
						'post_status'  => 'publish',
						'post_author'  => get_current_user_id(),
					);

					$masonry_id = $this->ensure_skin_for_layout( 'masonry', $efbl_new_skin_masonry );

					if ( $grid_id || $masonry_id ) {
						$fta_settings['plugins']['facebook']['pro_default_skins_added'] = true;
						update_option( 'fta_settings', $fta_settings );
					}
				}

				/*
				 * Pro-only default carousel skin (kept as its own settings
				 * key for backwards compatibility).
				 */
				if ( $is_pro_eligible && empty( $fta_settings['plugins']['facebook']['carousel_default_skin_id'] ) ) {

					$efbl_new_skin_carousel = array(
						'post_title'   => __( 'Skin - Carousel', 'easy-facebook-likebox' ),
						'post_content' => __( 'This is the Carousel demo skin created by plugin automatically with default values. You can edit it and change the look & feel of your Facebook Feeds.', 'easy-facebook-likebox' ),
						'post_type'    => 'efbl_skins',
						'post_status'  => 'publish',
						'post_author'  => get_current_user_id(),
					);

					$efbl_new_skin_carousel_id = $this->ensure_skin_for_layout( 'carousel', $efbl_new_skin_carousel );

					if ( $efbl_new_skin_carousel_id ) {
						$fta_settings['plugins']['facebook']['carousel_default_skin_id'] = $efbl_new_skin_carousel_id;
						update_option( 'fta_settings', $fta_settings );
					}
				}

				/*
				 * Default demo page that hosts the customizer preview.
				 */
				if ( empty( $fta_settings['plugins']['facebook']['default_page_id'] ) && ! empty( $fta_settings['plugins']['facebook']['default_skin_id'] ) ) {

					$skin_id = $fta_settings['plugins']['facebook']['default_skin_id'];

					$efbl_default_page = array(
						'post_title'   => __( 'Facebook Demo - Customizer', 'easy-facebook-likebox' ),
						'post_content' => __( '[efb_feed fanpage_id="106704037405386" words_limit="25" show_like_box="1" post_limit="10" cache_unit="5" cache_duration="days" skin_id=' . $skin_id . ' ]<br> This is a Facebook demo page created by plugin automatically. Please do not delete to make the plugin work properly.', 'easy-facebook-likebox' ),
						'post_type'    => 'page',
						'post_status'  => 'private',
					);

					$efbl_default_page = apply_filters( 'efbl_default_page', $efbl_default_page );

					$page_id = wp_insert_post( $efbl_default_page, true );

					if ( ! is_wp_error( $page_id ) && $page_id ) {
						$fta_settings['plugins']['facebook']['default_page_id'] = (int) $page_id;
						update_option( 'fta_settings', $fta_settings );
					}
				}
			} catch ( Exception $e ) {
				// Never let a seeding error bubble up into wp-admin.
				if ( function_exists( 'error_log' ) ) {
					error_log( 'EFBL_SKINS seeding error: ' . $e->getMessage() );
				}
			}

			$this->release_seeding_lock();
		}

		/*
		* Create skin object which will have all skin data
		*/
		public function efbl_skins() {

			$FTA = new Feed_Them_All();

			$fta_settings = $FTA->fta_get_settings();

			$efbl_skins = array(
				// Bumped from 10 to 1000 to cover legacy installs that
				// already accumulated duplicate skin posts. Renders are
				// keyed by post ID so the lookup must include every saved
				// skin even when the canonical card count is small.
				'posts_per_page' => 1000,
				'post_type'      => 'efbl_skins',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'order'          => 'ASC',
				'no_found_rows'  => true,
			);

			$efbl_skins = get_posts( $efbl_skins );

			if ( isset( $efbl_skins ) && ! empty( $efbl_skins ) ) {

				$efbl_skins_holder = array();

				// Build a map of "canonical" skin IDs per layout. Canonical
				// IDs are the ones referenced from fta_settings so existing
				// shortcodes always resolve to them. Any extra skin posts
				// for the same layout (created by the old buggy seeding
				// path) are flagged as duplicates so the admin UI can hide
				// them without touching the data.
				$canonical_ids = array();
				if ( isset( $fta_settings['plugins']['facebook'] ) && is_array( $fta_settings['plugins']['facebook'] ) ) {
					if ( ! empty( $fta_settings['plugins']['facebook']['default_skin_id'] ) ) {
						$canonical_ids['half'] = (int) $fta_settings['plugins']['facebook']['default_skin_id'];
					}
					if ( ! empty( $fta_settings['plugins']['facebook']['row_default_skin_id'] ) ) {
						$canonical_ids['row'] = (int) $fta_settings['plugins']['facebook']['row_default_skin_id'];
					}
					if ( ! empty( $fta_settings['plugins']['facebook']['carousel_default_skin_id'] ) ) {
						$canonical_ids['carousel'] = (int) $fta_settings['plugins']['facebook']['carousel_default_skin_id'];
					}
				}

				$seen_layouts = array();

				foreach ( $efbl_skins as $skin ) :

					$id = $skin->ID;

					$design_arr = array();

					$design_arr = get_option( 'efbl_skin_' . $id, false );

					$layout = get_post_meta( $id, 'layout', true );

					if ( ! $layout ) {

						$layout = isset( $design_arr['layout_option'] ) ? $design_arr['layout_option'] : '';

						if ( isset( $design_arr['feed_background_color'] ) && $design_arr['feed_background_color'] == 'transparent' ) {

							$design_arr['feed_background_color'] = '#fff';
						}

						if ( isset( $design_arr['feed_meta_data_color'] ) && $design_arr['feed_meta_data_color'] == '#fff' ) {

							$design_arr['feed_meta_data_color'] = '#343a40';
						}
					}

					$title = $skin->post_title;

					if ( empty( $title ) ) {
						$title = __( 'Skin', 'easy-facebook-likebox' );
					}

					// A skin is considered a "duplicate" only if (a) we
					// already saw a skin with the same layout in this loop
					// AND it is not the canonical ID, OR (b) the canonical
					// ID for this layout is set to a different post.
					$is_duplicate = false;
					if ( $layout ) {
						$is_canonical = isset( $canonical_ids[ $layout ] ) && (int) $canonical_ids[ $layout ] === (int) $id;
						if ( ! $is_canonical && in_array( $layout, $seen_layouts, true ) ) {
							$is_duplicate = true;
						}
						if ( ! $is_canonical && isset( $canonical_ids[ $layout ] ) && (int) $canonical_ids[ $layout ] !== (int) $id ) {
							$is_duplicate = true;
						}
						$seen_layouts[] = $layout;
					}

					$efbl_skins_holder[ $id ] = array(
						'ID'           => $id,
						'title'        => $title,
						'description'  => $skin->post_content,
						'layout'       => $layout,
						'is_duplicate' => $is_duplicate,
					);

					$efbl_skins_holder[ $id ]['design'] = wp_parse_args( $design_arr, $this->efbl_default_skin_settings() );

				endforeach;

				$GLOBALS['efbl_skins'] = $efbl_skins_holder;

			} else {
				$GLOBALS['efbl_skins'] = array();
				return __( 'No skin found.', 'easy-facebook-likebox' );
			}

		}

		public function efbl_default_skin_settings() {

			return array(
				'number_of_cols'               => 3,
				'show_load_more_btn'           => true,
				'show_header'                  => false,
				'show_dp'                      => true,
				'show_next_prev_icon'          => true,
				'show_nav'                     => true,
				'loop'                         => true,
				'autoplay'                     => true,
				'show_page_category'           => true,
				'show_no_of_followers'         => true,
				'show_bio'                     => true,
				'feed_header'                  => true,
				'header_shadow_color'          => 'rgba(0,0,0,0.15)',
				'feed_shadow_color'            => 'rgba(0,0,0,0.15)',
				'show_comments'                => true,
				'feed_header_logo'             => true,
				'show_likes'                   => true,
				'show_shares'                  => true,
				'show_feed_caption'            => true,
				'show_feed_open_popup_icon'    => true,
				'show_feed_view_on_facebook'   => true,
				'show_feed_share_button'       => true,
				'popup_show_header'            => true,
				'feed_media_before_caption'    => false,
				'popup_show_header_logo'       => true,
				'popup_show_caption'           => true,
				'popup_show_meta'              => true,
				'popup_show_reactions_counter' => true,
				'popup_show_comments_counter'  => true,
				'popup_show_view_fb_link'      => true,
				'popup_show_comments'          => true,
			);

		}


	}

	$GLOBALS['EFBL_SKINS'] = new EFBL_SKINS();

endif;
