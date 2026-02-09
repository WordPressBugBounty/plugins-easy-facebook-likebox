<?php
/*
* Stop execution if someone tried to get file directly.
*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_Admin' ) ) {

	class ESF_Admin {

		/**
		 * Ensure footer review notice hook is only added once,
		 * even if ESF_Admin is instantiated multiple times.
		 *
		 * @var bool
		 */
		protected static $footer_review_hook_added = false;

		function __construct() {

			add_action(
				'admin_menu',
				array(
					$this,
					'esf_menu',
				)
			);

			add_action(
				'admin_menu',
				array(
					$this,
					'esf_settings_submenu',
				),
				101
			);

			add_action(
				'admin_head',
				array(
					$this,
					'esf_debug_token',
				)
			);

			add_action(
				'admin_enqueue_scripts',
				array(
					$this,
					'esf_admin_assets',
				)
			);

			add_action(
				'wp_ajax_esf_change_module_status',
				array(
					$this,
					'esf_change_module_status',
				)
			);

			add_action(
				'wp_ajax_esf_remove_access_token',
				array(
					$this,
					'esf_remove_access_token',
				)
			);

			add_action(
				'wp_ajax_esf_save_general_settings',
				array(
					$this,
					'esf_save_general_settings',
				)
			);

			add_action(
				'wp_ajax_esf_save_gdpr_settings',
				array(
					$this,
					'esf_save_gdpr_settings',
				)
			);

			add_action(
				'wp_ajax_esf_save_translation_settings',
				array(
					$this,
					'esf_save_translation_settings',
				)
			);

			add_action(
				'admin_notices',
				array(
					$this,
					'esf_admin_notice',
				)
			);

			add_action(
				'wp_ajax_esf_hide_rating_notice',
				array(
					$this,
					'esf_hide_rating_notice',
				)
			);

			add_action(
				'wp_ajax_esf_hide_row_notice',
				array(
					$this,
					'hide_row_notice',
				)
			);

			add_action(
				'admin_head',
				array(
					$this,
					'esf_hide_notices',
				)
			);

			if ( ! self::$footer_review_hook_added ) {
				add_action(
					'admin_footer',
					array(
						$this,
						'esf_admin_footer_review_notice',
					)
				);
				self::$footer_review_hook_added = true;
			}

			add_action(
				'pre_get_posts',
				array(
					$this,
					'esf_exclude_demo_pages',
				),
				1
			);
		}

		/**
		 * On ESF admin pages, show only notices from this plugin (and Freemius) and hide all others via CSS.
		 * ESF notices use .fta_msg, Freemius notices use .fs-notice; other plugins use .notice or .update-nag.
		 */
		public function esf_hide_notices() {
			$screen = get_current_screen();
			if ( ! isset( $screen->id ) ) {
				echo '<style>.toplevel_page_feed-them-all .wp-menu-image img{padding-top: 4px!important;}</style>';
				return;
			}

			$esf_admin_screens = array(
				'toplevel_page_feed-them-all',
				'easy-social-feed_page_easy-facebook-likebox',
				'easy-social-feed_page_mif',
				'admin_page_esf_welcome',
				'easy-social-feed_page_feed-them-all-addons',
				'easy-social-feed_page_esf-settings',
			);

			if ( in_array( $screen->id, $esf_admin_screens, true ) ) {
				$body_class = esc_attr( sanitize_html_class( $screen->id ) );
				echo '<style>';
				echo ".toplevel_page_feed-them-all .wp-menu-image img{padding-top: 4px!important;}";
				// Hide other plugins' notices: show only our Freemius (.fs-slug-easy-facebook-likebox) and ESF (.fta_msg).
				echo "body.{$body_class} .notice:not(.fs-notice){display:none !important;}";
				echo "body.{$body_class} .fs-notice:not(.fs-slug-easy-facebook-likebox){display:none !important;}";
				echo "body.{$body_class} .update-nag:not(.fta_msg){display:none !important;}";
				echo '</style>';
			} else {
				echo '<style>.toplevel_page_feed-them-all .wp-menu-image img{padding-top: 4px!important;}</style>';
			}
		}

		/**
		 * Includes common admin scripts and styles for FB and Insta.
		 *
		 * @since 1.0.0
		 *
		 * @param $hook
		 */
		public function esf_admin_assets( $hook ) {
			// load plugin files only on it's pages
			if ( 'toplevel_page_feed-them-all' !== $hook
					&& 'easy-social-feed_page_mif' !== $hook
					&& 'easy-social-feed_page_easy-facebook-likebox' !== $hook
					&& 'easy-social-feed_page_esf-settings' !== $hook
					&& 'admin_page_esf_welcome' !== $hook ) {
					return false;
			}

			wp_deregister_script( 'bootstrap.min' );
			wp_deregister_script( 'bootstrap' );
			wp_deregister_script( 'jquery-ui-tabs' );
			wp_enqueue_style(
				'esf-animations',
				FTA_PLUGIN_URL . 'admin/assets/css/esf-animations.css'
			);
			wp_enqueue_style(
				'esf-admin',
				FTA_PLUGIN_URL . 'admin/assets/css/esf-admin.css'
			);

			// Settings page reuses Facebook admin tab markup (efbl_wrap, efbl_tabs_*); load its CSS for tab bar styling.
			if ( 'easy-social-feed_page_esf-settings' === $hook ) {
				wp_enqueue_style(
					'efbl-admin-styles',
					FTA_PLUGIN_URL . 'facebook/admin/assets/css/admin.css',
					array(),
					'1.0'
				);
			}

			wp_enqueue_script( 'jquery-effects-slide' );
			wp_enqueue_script(
				'clipboard.min',
				FTA_PLUGIN_URL . 'admin/assets/js/clipboard.min.js',
				array(),
				false,
				true
			);
			wp_enqueue_script(
				'esf-admin',
				FTA_PLUGIN_URL . 'admin/assets/js/esf-admin.js',
				array( 'jquery' ),
				false,
				true
			);
			wp_localize_script(
				'esf-admin',
				'fta',
				array(
					'copied'   => __( 'Copied', 'easy-facebook-likebox' ),
					'deleting' => __( 'Deleting', 'easy-facebook-likebox' ),
					'error'    => __( 'Something went wrong!', 'easy-facebook-likebox' ),
					'saving'   => __( 'Saving…', 'easy-facebook-likebox' ),
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'esf-ajax-nonce' ),
				)
			);
			wp_enqueue_script( 'thickbox' );
			wp_enqueue_style( 'thickbox' );
			wp_enqueue_script( 'media-upload' );
			wp_enqueue_script(
				'esf-image-uploader',
				FTA_PLUGIN_URL . 'admin/assets/js/esf-image-uploader.js',
				array(
					'jquery',
					'media-upload',
					'thickbox',
				),
				'1.0.0',
				true
			);
			wp_localize_script(
				'esf-image-uploader',
				'esf_image_uploader',
				array(
					'title'    => __( 'Select or Upload Image', 'easy-facebook-likebox' ),
					'btn_text' => __( 'Use this Image', 'easy-facebook-likebox' ),
				)
			);
			wp_enqueue_media();
			return false;
		}

		/**
		 * Add plugin menu
		 *
		 * @since 1.0.0
		 */
		public function esf_menu() {
			add_menu_page(
				__( 'Easy Social Feed', 'easy-facebook-likebox' ),
				__( 'Easy Social Feed', 'easy-facebook-likebox' ),
				'administrator',
				'feed-them-all',
				array(
					$this,
					'esf_page',
				),
				FTA_PLUGIN_URL . 'admin/assets/images/plugin_icon.png',
				25
			);

			add_submenu_page(
				'hidden',
				__( 'Welcome', 'easy-facebook-likebox' ),
				__( 'Welcome', 'easy-facebook-likebox' ),
				'administrator',
				'esf_welcome',
				array(
					$this,
					'esf_welcome_page',
				)
			);
		}

		/**
		 * Add Settings submenu after Facebook and Instagram (runs at priority 101).
		 *
		 * @since 6.8.0
		 */
		public function esf_settings_submenu() {
			add_submenu_page(
				'feed-them-all',
				__( 'Settings', 'easy-facebook-likebox' ),
				__( 'Settings', 'easy-facebook-likebox' ),
				'manage_options',
				'esf-settings',
				array(
					$this,
					'esf_settings_page',
				)
			);
		}

		/**
		 * Includes view of welcome page
		 *
		 * @since 1.0.0
		 */
		function esf_welcome_page() {
			include_once FTA_PLUGIN_DIR . 'admin/views/html-admin-page-wellcome.php';
		}

		/**
		 * Includes view of Easy Soical Feed page
		 *
		 * @since 1.0.0
		 */
		function esf_page() {
			include_once FTA_PLUGIN_DIR . 'admin/views/html-admin-page-easy-social-feed.php';
		}

		/**
		 * Settings page (GDPR and other global settings).
		 *
		 * @since 6.8.0
		 */
		function esf_settings_page() {
			include_once FTA_PLUGIN_DIR . 'admin/views/html-admin-page-esf-settings.php';
		}

		/**
		 * Save global General settings (e.g. preserve on uninstall) via AJAX.
		 *
		 * @since 6.8.0
		 */
		public function esf_save_general_settings() {
			esf_check_ajax_referer();

			$FTA          = new Feed_Them_All();
			$fta_settings = $FTA->fta_get_settings();
			if ( ! is_array( $fta_settings ) ) {
				$fta_settings = array();
			}

			$preserve = isset( $_POST['preserve_settings_on_uninstall'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['preserve_settings_on_uninstall'] ) );
			$fta_settings['preserve_settings_on_uninstall'] = $preserve ? 1 : 0;

			update_option( 'fta_settings', $fta_settings );

			// update_option returns false when value is unchanged; that is still success.
			wp_send_json_success( __( 'Settings saved successfully!', 'easy-facebook-likebox' ) );
		}

		/**
		 * Save global GDPR setting via AJAX.
		 *
		 * @since 6.8.0
		 */
		public function esf_save_gdpr_settings() {
			esf_check_ajax_referer();

			$FTA          = new Feed_Them_All();
			$fta_settings = $FTA->fta_get_settings();
			$original     = $fta_settings;

			if ( isset( $_POST['gdpr'] ) ) {
				$gdpr = sanitize_text_field( wp_unslash( $_POST['gdpr'] ) );
				if ( in_array( $gdpr, array( 'auto', 'yes', 'no' ), true ) ) {
					$fta_settings['gdpr'] = $gdpr;
				}
			}

			if ( $fta_settings === $original ) {
				wp_send_json_success( __( 'Settings already saved.', 'easy-facebook-likebox' ) );
			}

			$updated = update_option( 'fta_settings', $fta_settings );

			if ( ! is_wp_error( $updated ) ) {
				wp_send_json_success( __( 'Settings saved successfully!', 'easy-facebook-likebox' ) );
			}

			wp_send_json_error( __( 'Something went wrong! Please try again.', 'easy-facebook-likebox' ) );
		}

		/**
		 * Save global translation (custom text) settings via AJAX.
		 *
		 * @since 6.8.0
		 */
		public function esf_save_translation_settings() {
			esf_check_ajax_referer();

			$FTA          = new Feed_Them_All();
			$fta_settings = $FTA->fta_get_settings();
			if ( ! is_array( $fta_settings ) ) {
				$fta_settings = array();
			}

			$allowed_keys = array();
			$all_strings  = ESF_Translation_Strings::get_all_strings();
			foreach ( $all_strings as $category ) {
				foreach ( $category['strings'] as $item ) {
					if ( ! empty( $item['key'] ) ) {
						$allowed_keys[ $item['key'] ] = true;
					}
				}
			}

			$posted = isset( $_POST['esf_translation'] ) && is_array( $_POST['esf_translation'] ) ? wp_unslash( $_POST['esf_translation'] ) : array();
			$saved  = array();
			foreach ( $posted as $key => $value ) {
				if ( isset( $allowed_keys[ $key ] ) && is_string( $value ) ) {
					$saved[ sanitize_text_field( $key ) ] = sanitize_text_field( $value );
				}
			}

			$fta_settings['translation'] = $saved;
			update_option( 'fta_settings', $fta_settings );

			// update_option returns false when value is unchanged; that is still success.
			wp_send_json_success( __( 'Settings saved successfully!', 'easy-facebook-likebox' ) );
		}

		/**
		 * Changes the module status like enable or disable Facebook/Instagram modules
		 *
		 * @since 1.0.0
		 */
		function esf_change_module_status() {

			esf_check_ajax_referer();

			$module_name                                       = sanitize_text_field( $_POST['plugin'] );
			$module_status                                     = sanitize_text_field( $_POST['status'] );
			$Feed_Them_All                                     = new Feed_Them_All();
			$esf_settings                                      = $Feed_Them_All->fta_get_settings();
			$esf_settings['plugins'][ $module_name ]['status'] = $module_status;

			$status_updated = update_option( 'fta_settings', $esf_settings );

			if ( $module_status === 'activated' ) {
				$status = __( ' Activated', 'easy-facebook-likebox' );
			} else {
				$status = __( ' Deactivated', 'easy-facebook-likebox' );
			}

			if ( isset( $status_updated ) ) {
				wp_send_json_success(
					__( ucfirst( $module_name ) . $status . ' Successfully', 'easy-facebook-likebox' )
				);
			} else {
					wp_send_json_error( __( 'Something Went Wrong! Please try again.', 'easy-facebook-likebox' ) );
			}
		}

		/**
		 * Removes the access token and deletes users access to the app.
		 *
		 * @since 1.0.0
		 */
		function esf_remove_access_token() {

			esf_check_ajax_referer();

			$Feed_Them_All = new Feed_Them_All();
			$esf_settings  = $Feed_Them_All->fta_get_settings();

			$access_token = $esf_settings['plugins']['facebook']['access_token'];

			if ( isset( $esf_settings['plugins']['facebook']['approved_pages'] ) ) {
				unset( $esf_settings['plugins']['facebook']['approved_pages'] );
			}

			if ( isset( $esf_settings['plugins']['facebook']['approved_groups'] ) ) {
				unset( $esf_settings['plugins']['facebook']['approved_groups'] );
			}

			unset( $esf_settings['plugins']['facebook']['access_token'] );
			$esf_settings['plugins']['instagram']['selected_type'] = 'personal';
			$delted_data = update_option( 'fta_settings', $esf_settings );

			$response = wp_remote_request(
				'https://graph.facebook.com/v4.0/me/permissions?access_token=' . $access_token . '',
				array(
					'method' => 'DELETE',
				)
			);
			wp_remote_retrieve_body( $response );
			if ( $delted_data ) {
				wp_send_json_success( __( 'Deleted', 'easy-facebook-likebox' ) );
			} else {
				wp_send_json_error( __( 'Something Went Wrong! Please try again.', 'easy-facebook-likebox' ) );
			}
		}

		/**
		 * Displays the admin notices
		 *
		 * @since 1.0.0
		 *
		 * @throws \Exception
		 */
		public function esf_admin_notice() {
			if ( ! current_user_can( 'install_plugins' ) ) {
				return false;
			}

			$Feed_Them_All = new Feed_Them_All();
			$install_date  = $Feed_Them_All->fta_get_settings( 'installDate' );
			$fta_settings  = $Feed_Them_All->fta_get_settings();
			$display_date  = date( 'Y-m-d h:i:s' );
			if ( ! is_string( $install_date ) || empty( $install_date ) ) {
				$install_date = $display_date;
			}

			$datetime1    = new DateTime( $install_date );
			$datetime2    = new DateTime( $display_date );
			$diff_intrval = round( ( $datetime2->format( 'U' ) - $datetime1->format( 'U' ) ) / ( 60 * 60 * 24 ) );

			if ( empty( $diff_intrval ) ) {
				$diff_intrval = 0;
			}
			if ( $diff_intrval >= 6 && get_site_option( 'fta_supported' ) !== 'yes' ) { ?>

				<div style="position:relative;padding-right:80px;background: #fff;" class="update-nag fta_msg fta_review">
					<p>
						<?php esc_html_e( 'Awesome, you have been using Easy Social Feed ', 'easy-facebook-likebox' ); ?>
						<?php esc_html_e( 'for more than a week. I would really appreciate it if you ', 'easy-facebook-likebox' ); ?>
						<b><?php esc_html_e( 'review and rate ', 'easy-facebook-likebox' ); ?></b>
						<?php esc_html_e( 'the plugin to help spread the word and ', 'easy-facebook-likebox' ); ?>
						<b><?php esc_html_e( 'encourage us to make it even better.', 'easy-facebook-likebox' ); ?></b>
					</p>
					<div class="fl_support_btns">
						<a href="https://wordpress.org/support/plugin/easy-facebook-likebox/reviews/?filter=5#new-post"
							class="esf_HideRating button button-primary"
							target="_blank">
							<?php esc_html_e( 'I Like Easy Social Feed - It increased engagement on my site', 'easy-facebook-likebox' ); ?>
						</a>
						<a href="javascript:void(0);"
							class="esf_HideRating button">
							<?php esc_html_e( 'I already rated it', 'easy-facebook-likebox' ); ?>
						</a>
						<br>
						<a style="margin-top:5px;float:left;"
							href="javascript:void(0);" class="esf_HideRating">
							<?php esc_html_e( 'No, not good enough, I do not like to rate it', 'easy-facebook-likebox' ); ?>
						</a>
						<div class="esf_HideRating" style="position:absolute;right:10px;cursor:pointer;top:4px;color: #029be4;">
							<div style="font-weight:bold;" class="dashicons dashicons-no-alt"></div>
							<span style="margin-left: 2px;">
								<?php esc_html_e( 'Dismiss', 'easy-facebook-likebox' ); ?>
							</span>
						</div>
					</div>
				</div>
				<script>
					jQuery('.esf_HideRating').click(function() {
					var data = {'action': 'esf_hide_rating_notice'};
						jQuery.ajax({
								url: "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>",
								type: 'post',
								data: data,
								dataType: 'json',
								async: !0,
								success: function(e) {
								if(e === 'success'){
									jQuery('.fta_msg').slideUp('fast');
								}
								},
						});
						});
				</script>
				<?php
			}

			return false;
		}

		/**
		 * Hide rating notice permenately
		 *
		 * @since 1.0.0
		 */
		public function esf_hide_rating_notice() {
			update_site_option( 'fta_supported', 'yes' );
			echo wp_json_encode( array( 'success' ) );
			wp_die();
		}

		/**
		 * Hide row layout notice permenately
		 */
		public function hide_row_notice() {
			update_site_option( 'fta_row_layout_notice', 'yes' );
			echo wp_json_encode( array( 'success' ) );
			wp_die();
		}

		/**
		 * Exclude demo pages from query
		 *
		 * @since 1.0.0
		 *
		 * @param $query
		 *
		 * @return mixed
		 */
		function esf_exclude_demo_pages( $query ) {
			if ( ! is_admin() ) {
				return $query;
			}
			global $pagenow;
			if ( 'edit.php' === $pagenow && ( get_query_var( 'post_type' ) && 'page' === get_query_var( 'post_type' ) ) ) {
				$fta_class    = new Feed_Them_All();
				$fta_settings = $fta_class->fta_get_settings();
				if ( isset( $fta_settings['plugins']['facebook']['default_page_id'] ) ) {
					$fb_id = $fta_settings['plugins']['facebook']['default_page_id'];
				}

				if ( isset( $fta_settings['plugins']['instagram']['default_page_id'] ) ) {
					$insta_id = $fta_settings['plugins']['instagram']['default_page_id'];
				}

				if ( $fb_id || $insta_id ) {
					$query->set( 'post__not_in', array( $fb_id, $insta_id ) );
				}
			}

			return $query;
		}

		/**
		 * Debug the token and save info in DB
		 */
		public function esf_debug_token() {

			if ( class_exists( 'Feed_Them_All' ) ) {

				$FTA = new Feed_Them_All();

				$fta_settings = $FTA->fta_get_settings();

				$access_token = '';

				$access_token_info = '';

				if ( isset( $fta_settings['plugins']['facebook']['access_token'] ) ) {

					$access_token = $fta_settings['plugins']['facebook']['access_token'];
				}

				if ( isset( $fta_settings['plugins']['facebook']['access_token_info'] ) ) {

					$access_token_info = $fta_settings['plugins']['facebook']['access_token_info'];
				}
			}

			if ( ! $access_token ) {
				return;
			}

			if ( $access_token_info ) {
				return;
			}

			/*
			 * Access token debug API endpoint
			 */
			$fb_token_debug_url = add_query_arg(
				array(
					'input_token'  => $access_token,
					'access_token' => $access_token,
				),
				'https://graph.facebook.com/v6.0/debug_token'
			);

			$fb_token_info = wp_remote_get( $fb_token_debug_url );

			if ( is_array( $fb_token_info ) && ! is_wp_error( $fb_token_info ) ) {

				$fb_token_info = json_decode( $fb_token_info['body'] );

				if ( isset( $fb_token_info->error ) ) {
					return;
				}

				if ( isset( $fb_token_info->data ) ) {

					$fta_settings['plugins']['facebook']['access_token_info']['data_access_expires_at'] = $fb_token_info->data->data_access_expires_at;

					$fta_settings['plugins']['facebook']['access_token_info']['expires_at'] = $fb_token_info->data->expires_at;

					$fta_settings['plugins']['facebook']['access_token_info']['is_valid'] = $fb_token_info->data->is_valid;

					$fta_settings['plugins']['facebook']['access_token_info']['issued_at'] = $fb_token_info->data->issued_at;

					$fta_settings['plugins']['facebook']['access_token_info']['app_id'] = $fb_token_info->data->app_id;

					update_option( 'fta_settings', $fta_settings );

					return;

				}
			}
		}

		/**
		 * Check the access token validity if exists.
		 *
		 * @return $return_arr and reason
		 */
		public function esf_access_token_valid() {

			if ( class_exists( 'Feed_Them_All' ) ) {

				$FTA = new Feed_Them_All();

				$fta_settings = $FTA->fta_get_settings();

				$access_token_info = '';

				if ( isset( $fta_settings['plugins']['facebook']['access_token_info'] ) ) {

					$access_token_info = $fta_settings['plugins']['facebook']['access_token_info'];

					$data_access_expires_at = $access_token_info['data_access_expires_at'];

					$expires_at = $access_token_info['expires_at'];

					$is_valid = $access_token_info['is_valid'];
				}
			}

			if ( ! $access_token_info ) {

				return array( 'is_valid' => true );
			}

			$return_arr = array( 'is_valid' => true );

			$current_timestamp = time();

			if ( $data_access_expires_at <= $current_timestamp ) {

				$return_arr = array(
					'is_valid'      => false,
					'reason'        => 'data_access_expired',
					'error_message' => __( 'Attention! Data access to the current access token is expired. Please re-authenticate the app.', 'easy-facebook-likebox' ),
				);

			}

			if ( ( $expires_at > 0 ) && ( $expires_at <= $current_timestamp ) ) {

				$return_arr = array(
					'is_valid'      => false,
					'reason'        => 'token_expired',
					'error_message' => __( 'Attention! Access token is expired. Please re-authenticate the app.', 'easy-facebook-likebox' ),
				);

			}

			return $return_arr;
		}


		/**
		 * Get upgrade banner info from main site
		 * @return mixed|string[]
		 */
		public function esf_upgrade_banner() {

				$banner_info = array(
					'name'              => 'Easy Social Feed',
					'bold'              => 'PRO',
					'fb-description'    => 'Increase social followers, engage more users and get 10x traffic with 17% off on all plans (including monthly billings). So grab this offer now before it will go forever.',
					'insta-description' => 'Increase social followers, engage more users and get 10x traffic with 17% off on all plans (including monthly billings). So grab this offer now before it will go forever.',
					'discount-text'     => '',
					'coupon'            => 'ESPF17',
					'discount'          => '17%',
					'button-text'       => 'Upgrade Now',
					'button-url'        => efl_fs()->get_upgrade_url(),
					'target'            => '',
				);

				return $banner_info;
		}

		/**
		 * Admin footer small review notice, similar to WP Reset example.
		 *
		 * @return void
		 */
		public function esf_admin_footer_review_notice() {
			$screen = get_current_screen();

			// Show only on Easy Social Feed screens.
			$allowed_screens = array(
				'toplevel_page_feed-them-all',
				'easy-social-feed_page_easy-facebook-likebox',
				'easy-social-feed_page_mif',
				'admin_page_esf_welcome',
				'easy-social-feed_page_feed-them-all-addons',
				'easy-social-feed_page_esf-settings',
			);

			if ( ! isset( $screen->id ) || ! in_array( $screen->id, $allowed_screens, true ) ) {
				return;
			}

			?>
			<style>
				.toplevel_page_feed-them-all #wpfooter,
				.easy-social-feed_page_easy-facebook-likebox #wpfooter,
				.easy-social-feed_page_mif #wpfooter,
				.admin_page_esf_welcome #wpfooter,
				.easy-social-feed_page_feed-them-all-addons #wpfooter,
				.easy-social-feed_page_esf-settings #wpfooter {
					display: none;
				}
			</style>
			<div class="esf-admin-footer-review" style="padding: 20px 15px; background: #ffffff; border: 1px solid #e5e5e5; font-size: 12px; color: #555; margin-left: 160px;">
				<span style="margin-top: 10px;display: block;">
					<?php esc_html_e( 'Enjoying Easy Social Feed? Please take a moment to', 'easy-facebook-likebox' ); ?>
					<a href="https://wordpress.org/support/plugin/easy-facebook-likebox/reviews/?filter=5#new-post" target="_blank">
						<b>
						<?php esc_html_e( 'rate the plugin', 'easy-facebook-likebox' ); ?>
						<span style="color:#ffb900;">★★★★★</span>
						</b>
					</a>
					<?php esc_html_e( 'Your feedback truly helps us grow and continue improving Easy Social Feed. Thank you for your support!', 'easy-facebook-likebox' ); ?>
				</span>
			</div>
			<?php
		}

	}

	new ESF_Admin();

}
