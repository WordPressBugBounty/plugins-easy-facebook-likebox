<?php
/*
* Stop execution if someone tried to get file directly.
*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

//======================================================================
// Instagram Skins
//======================================================================

if ( ! class_exists( 'ESF_Insta_Skins' ) ) :
	class ESF_Insta_Skins {

		/**
		 * Transient key used as a soft lock so concurrent requests do not
		 * race the default-skin seeding routine.
		 */
		const SEEDING_LOCK_KEY = 'esf_insta_seeding_lock';

		function __construct() {

			// Skins-related hooks run late on init / admin_init so that any
			// dependencies registered by other plugins / the parent plugin
			// (FTA, post types, options bootstrap, etc.) are guaranteed to
			// be in place before our register + seed routines fire.
			add_action( 'init', array( $this, 'mif_skins_register' ), 100 );

			// Build the $GLOBALS['mif_skins'] map as early as possible so
			// shortcodes / widgets that fire before init still see the data.
			$this->mif_skins();

			// Seeding (which performs writes) is moved off the per-request
			// path to admin_init. This eliminates ~all duplicate creation
			// from frontend / cron / REST hits, while still letting fresh
			// installs auto-create their default skins on the first admin
			// visit. The idempotent ensure_skin_for_layout() helper is the
			// last line of defence even if this hook fires twice.
			add_action( 'admin_init', array( $this, 'mif_default_skins' ), 100 );
		}

		/*
		* Register skins posttype.
		*/
		public function mif_skins_register() {

			$args = array(
				'public'              => false,
				'label'               => __( 'MIF Skins', 'easy-facebook-likebox' ),
				'show_in_menu'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'hierarchical'        => true,
				'menu_position'       => null,
			);

			register_post_type( 'mif_skins', $args );

		}

		/**
		 * Look up an existing mif_skins post for the given layout. Returns
		 * the canonical post ID if one already exists, otherwise creates a
		 * new post with the supplied $defaults and writes the layout meta.
		 *
		 * This is the core of the duplicate-prevention fix. Even if the
		 * fta_settings option is wiped/reset, this method will not create
		 * a second post for a layout that already has one in wp_posts.
		 *
		 * @param string $layout   The layout key (grid, row, half_width, etc.)
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
					'post_type'      => 'mif_skins',
					'post_status'    => array( 'publish', 'draft', 'pending' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_key'       => 'layout',
					'meta_value'     => $layout,
					'no_found_rows'  => true,
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
		 * Combined with ensure_skin_for_layout() this gives us a belt and
		 * suspenders against duplicate creation.
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
		* Register Default skins.
		*/
		public function mif_default_skins() {

			$FTA = new Feed_Them_All();

			$fta_settings = $FTA->fta_get_settings();

			if ( ! is_array( $fta_settings ) ) {
				$fta_settings = array();
			}

			// Fast path: if every required key is already populated, skip
			// the seeding routine entirely. This keeps admin_init cheap on
			// subsequent loads.
			$is_pro_eligible = function_exists( 'efl_fs' )
				&& efl_fs()->can_use_premium_code__premium_only()
				&& ( efl_fs()->is_plan( 'instagram_premium', true ) || efl_fs()->is_plan( 'combo_premium', true ) );

			$all_seeded = ! empty( $fta_settings['plugins']['instagram']['default_skin_id'] )
				&& ! empty( $fta_settings['plugins']['instagram']['row_default_skin_id'] )
				&& ! empty( $fta_settings['plugins']['instagram']['default_page_id'] )
				&& ( ! $is_pro_eligible || ! empty( $fta_settings['plugins']['instagram']['pro_default_skins_added'] ) );

			if ( $all_seeded ) {
				return;
			}

			if ( ! $this->acquire_seeding_lock() ) {
				return;
			}

			try {
				/*
				 * Default Grid skin.
				 */
				if ( empty( $fta_settings['plugins']['instagram']['default_skin_id'] ) ) {

					$mif_new_skins = array(
						'post_title'   => __( 'Skin - Grid', 'easy-facebook-likebox' ),
						'post_content' => __( 'This is the demo skin created by Easy Social Feed plugin automatically with default values. You can edit it and change the look & feel of your Feeds.', 'easy-facebook-likebox' ),
						'post_type'    => 'mif_skins',
						'post_status'  => 'publish',
						'post_author'  => get_current_user_id(),
					);
					$mif_new_skins = apply_filters( 'mif_default_skin', $mif_new_skins );

					$skin_id = $this->ensure_skin_for_layout( 'grid', $mif_new_skins );

					if ( $skin_id ) {
						$fta_settings['plugins']['instagram']['default_skin_id'] = $skin_id;
						update_option( 'fta_settings', $fta_settings );
					}
				}

				/*
				 * Default Row skin.
				 */
				if ( empty( $fta_settings['plugins']['instagram']['row_default_skin_id'] ) ) {

					$efbl_new_skin_row = array(
						'post_title'   => __( 'Skin - Row', 'easy-facebook-likebox' ),
						'post_content' => __( 'This is the Row demo skin created by the plugin automatically with default values. You can edit it and change the look & feel of your Feeds.', 'easy-facebook-likebox' ),
						'post_type'    => 'mif_skins',
						'post_status'  => 'publish',
						'post_author'  => get_current_user_id(),
					);

					$efbl_new_skin_row_id = $this->ensure_skin_for_layout( 'row', $efbl_new_skin_row );

					if ( $efbl_new_skin_row_id ) {
						$fta_settings['plugins']['instagram']['row_default_skin_id'] = $efbl_new_skin_row_id;
						update_option( 'fta_settings', $fta_settings );
					}
				}

				/*
				 * Default demo page that hosts the customizer preview.
				 */
				if ( empty( $fta_settings['plugins']['instagram']['default_page_id'] ) && ! empty( $fta_settings['plugins']['instagram']['default_skin_id'] ) ) {

					$skin_id = $fta_settings['plugins']['instagram']['default_skin_id'];

					$user_id = esf_insta_default_id();

					$mif_default_page = array(
						'post_title'   => __( 'Instagram Demo - Customizer', 'easy-facebook-likebox' ),
						'post_content' => __( "[my-instagram-feed user_id='{$user_id}' skin_id='{$skin_id}'] <br> This is a mif demo page created by plugin automatically. Please don't delete to make the plugin work properly.", 'easy-facebook-likebox' ),
						'post_type'    => 'page',
						'post_status'  => 'private',
					);

					$mif_default_page = apply_filters( 'mif_default_page', $mif_default_page );

					$page_id = wp_insert_post( $mif_default_page, true );

					if ( ! is_wp_error( $page_id ) && $page_id ) {
						$fta_settings['plugins']['instagram']['default_page_id'] = (int) $page_id;
						update_option( 'fta_settings', $fta_settings );
					}
				}

				/*
				 * Pro-only default skins (half_width, full_width, masonary, carousel).
				 */
				if ( $is_pro_eligible && empty( $fta_settings['plugins']['instagram']['pro_default_skins_added'] ) ) {

					$pro_skin_blueprints = array(
						'half_width' => array(
							'post_title'   => __( 'Skin - Half Width', 'easy-facebook-likebox' ),
							'post_content' => __( 'This is the demo skin created by Easy Social Feed plugin automatically with default values. You can edit it and change the look & feel of your Feeds.', 'easy-facebook-likebox' ),
						),
						'full_width' => array(
							'post_title'   => __( 'Skin - Full Width', 'easy-facebook-likebox' ),
							'post_content' => __( 'This is the demo skin created by Easy Social Feed plugin automatically with default values. You can edit it and change the look & feel of your Feeds.', 'easy-facebook-likebox' ),
						),
						'masonary'   => array(
							'post_title'   => __( 'Skin - Masonry', 'easy-facebook-likebox' ),
							'post_content' => __( 'This is the demo skin created by Easy Social Feed plugin automatically with default values. You can edit it and change the look & feel of your Feeds.', 'easy-facebook-likebox' ),
						),
						'carousel'   => array(
							'post_title'   => __( 'Skin - Carousel', 'easy-facebook-likebox' ),
							'post_content' => __( 'This is the demo skin created by Easy Social Feed plugin automatically with default values. You can edit it and change the look & feel of your Feeds.', 'easy-facebook-likebox' ),
						),
					);

					$created_any = false;
					foreach ( $pro_skin_blueprints as $layout => $blueprint ) {
						$blueprint['post_type']   = 'mif_skins';
						$blueprint['post_status'] = 'publish';
						$blueprint['post_author'] = get_current_user_id();

						$created_id = $this->ensure_skin_for_layout( $layout, $blueprint );
						if ( $created_id ) {
							$created_any = true;
						}
					}

					// Mark pro seeding as complete only when at least one
					// blueprint resolved to a real ID. This prevents the
					// flag from getting stuck-set on a totally failed run.
					if ( $created_any ) {
						$fta_settings['plugins']['instagram']['pro_default_skins_added'] = true;
						update_option( 'fta_settings', $fta_settings );
					}
				}
			} catch ( Exception $e ) {
				// Never let a seeding error bubble up into wp-admin.
				if ( function_exists( 'error_log' ) ) {
					error_log( 'ESF_Insta_Skins seeding error: ' . $e->getMessage() );
				}
			}

			$this->release_seeding_lock();
		}

		/*
		* Create skin object which will have all skin data
		*/
		public function mif_skins() {

			$FTA = new Feed_Them_All();

			$fta_settings = $FTA->fta_get_settings();

			$fta_skins = array(
				'posts_per_page' => 1000,
				'post_type'      => 'mif_skins',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'order'          => 'ASC',
				'no_found_rows'  => true,
			);

			$fta_skins = get_posts( $fta_skins );

			/* If any fta_skins are in database. */
			if ( isset( $fta_skins ) && ! empty( $fta_skins ) ) {

				$fta_skins_holder = array();

				// Build a map of "canonical" skin IDs per layout. The
				// canonical ID is the one referenced from fta_settings so
				// existing shortcodes always resolve to it. Any additional
				// skin posts for the same layout (created by the old buggy
				// seeding path) are flagged as duplicates so the admin UI
				// can hide them without touching the data.
				$canonical_ids = array();
				if ( isset( $fta_settings['plugins']['instagram'] ) && is_array( $fta_settings['plugins']['instagram'] ) ) {
					if ( ! empty( $fta_settings['plugins']['instagram']['default_skin_id'] ) ) {
						$canonical_ids['grid'] = (int) $fta_settings['plugins']['instagram']['default_skin_id'];
					}
					if ( ! empty( $fta_settings['plugins']['instagram']['row_default_skin_id'] ) ) {
						$canonical_ids['row'] = (int) $fta_settings['plugins']['instagram']['row_default_skin_id'];
					}
				}

				$seen_layouts = array();

				foreach ( $fta_skins as $skin ) :

					$id = $skin->ID;

					$design_arr = array();

					$design_arr = get_option( 'mif_skin_' . $id, false );

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
					// AND (b) it is not the canonical ID recorded in
					// fta_settings for that layout. This guarantees the
					// canonical IDs always render in the admin UI even
					// when older duplicates predate them in post order.
					$is_duplicate = false;
					if ( $layout ) {
						$is_canonical = isset( $canonical_ids[ $layout ] ) && (int) $canonical_ids[ $layout ] === (int) $id;
						if ( ! $is_canonical && in_array( $layout, $seen_layouts, true ) ) {
							$is_duplicate = true;
						}
						if ( ! $is_canonical && isset( $canonical_ids[ $layout ] ) && (int) $canonical_ids[ $layout ] !== (int) $id ) {
							// Canonical ID is set and points to a different
							// post — this one is a duplicate regardless of
							// loop order.
							$is_duplicate = true;
						}
						$seen_layouts[] = $layout;
					}

					$fta_skins_holder[ $id ] = array(
						'ID'           => $id,
						'title'        => $title,
						'description'  => $skin->post_content,
						'layout'       => $layout,
						'is_duplicate' => $is_duplicate,
					);

					$fta_skins_holder[ $id ]['design'] = wp_parse_args( $design_arr, $this->esf_insta_default_skin_settings() );

				endforeach;

				$GLOBALS['mif_skins'] = $fta_skins_holder;

			} /* If no data found. */ else {
				$GLOBALS['mif_skins'] = array();
				return __( 'No skins found.', 'easy-facebook-likebox' );
			}

		}

		public function esf_insta_default_skin_settings() {

			return array(
				'show_load_more_btn'           => true,
				'number_of_cols'               => 3,
				'show_header'                  => false,
				'header_round_dp'              => true,
				'show_dp'                      => true,
				'show_no_of_followers'         => true,
				'show_next_prev_icon'          => true,
				'show_nav'                     => true,
				'loop'                         => true,
				'autoplay'                     => true,
				'show_bio'                     => true,
				'feed_header'                  => true,
				'show_comments'                => true,
				'feed_header_logo'             => true,
				'header_shadow_color'          => 'rgba(0,0,0,0.15)',
				'feed_shadow_color'            => 'rgba(0,0,0,0.15)',
				'show_likes'                   => true,
				'show_feed_caption'            => true,
				'show_feed_open_popup_icon'    => true,
				'show_feed_view_on_instagram'  => true,
				'show_feed_share_button'       => true,
				'popup_show_header'            => true,
				'popup_show_header_logo'       => true,
				'popup_show_caption'           => true,
				'popup_show_meta'              => true,
				'popup_show_reactions_counter' => true,
				'popup_show_comments_counter'  => true,
				'popup_show_view_insta_link'   => true,
				'popup_show_comments'          => true,
			);

		}


	}

	$GLOBALS['ESF_Insta_Skins'] = new ESF_Insta_Skins();
endif;
