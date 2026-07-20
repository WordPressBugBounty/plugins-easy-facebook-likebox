<?php
/**
 * Shared WordPress.org review request logic for ESF admin surfaces.
 *
 * Shows a friendly, dismissible notice after meaningful milestones (account
 * connected, feed saved) once the site has used the plugin for at least seven
 * days. Uses a two-step happiness filter so unhappy users are routed to
 * support instead of WordPress.org.
 *
 * @package Easy_Social_Feed
 * @since   6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_Review_Request' ) ) {

	/**
	 * Class ESF_Review_Request
	 *
	 * @since 6.9.0
	 */
	class ESF_Review_Request {

		const MIN_ACTIVE_DAYS     = 7;
		const FALLBACK_DAYS       = 14;
		const SNOOZE_DAYS         = 30;
		const NEGATIVE_SNOOZE_DAYS = 90;

		const USER_META_STATE     = 'esf_review_request_state';
		const SITE_MILESTONES_KEY = 'esf_review_request_milestones';
		const LEGACY_DISMISSED_KEY = 'fta_supported';
		const PREVIEW_TRANSIENT_PREFIX = 'esf_review_preview_';

		const REVIEW_URL = 'https://wordpress.org/support/plugin/easy-facebook-likebox/reviews/?filter=5#new-post';
		const SUPPORT_URL = 'https://easysocialfeed.com/support/';

		/**
		 * Register dev-preview helpers (admin only).
		 *
		 * @since 6.9.0
		 * @return void
		 */
		public static function register_hooks() {
			add_action( 'admin_init', array( __CLASS__, 'maybe_handle_dev_query_args' ), 1 );
		}

		/**
		 * Dev-only query args:
		 * - ?esf_preview_review=1  Force the notice visible (requires WP_DEBUG or ESF_PREVIEW_REVIEW_REQUEST).
		 * - ?esf_reset_review=1    Clear dismiss/snooze/milestones for the current user.
		 *
		 * @since 6.9.0
		 * @return void
		 */
		public static function maybe_handle_dev_query_args() {
			if ( ! self::dev_tools_allowed() || ! is_admin() ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- dev-only read-only routing.
			$reset = isset( $_GET['esf_reset_review'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['esf_reset_review'] ) );
			if ( $reset ) {
				self::reset_state_for_user( get_current_user_id(), true );
				self::clear_dev_preview();
				$redirect = remove_query_arg( array( 'esf_reset_review', 'esf_preview_review' ) );
				wp_safe_redirect( add_query_arg( 'esf_preview_review', '1', $redirect ) );
				exit;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- dev-only UI preview flag.
			$preview = isset( $_GET['esf_preview_review'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['esf_preview_review'] ) );
			if ( $preview ) {
				self::arm_dev_preview();
			}
		}

		/**
		 * Whether developer preview tools are enabled on this site.
		 *
		 * @since 6.9.0
		 * @return bool
		 */
		public static function dev_tools_allowed() {
			$user_id = get_current_user_id();
			if ( $user_id <= 0 || ! user_can( $user_id, 'manage_options' ) ) {
				return false;
			}

			if ( defined( 'ESF_PREVIEW_REVIEW_REQUEST' ) && ESF_PREVIEW_REVIEW_REQUEST ) {
				return true;
			}

			if ( function_exists( 'wp_get_environment_type' ) ) {
				$env = wp_get_environment_type();
				if ( in_array( $env, array( 'local', 'development' ), true ) ) {
					return true;
				}
			}

			return defined( 'WP_DEBUG' ) && WP_DEBUG;
		}

		/**
		 * Persist dev preview for the current admin (survives REST requests).
		 *
		 * @since 6.9.0
		 * @param int $user_id User ID.
		 * @return void
		 */
		public static function arm_dev_preview( $user_id = 0 ) {
			$user_id = $user_id > 0 ? $user_id : get_current_user_id();
			if ( $user_id <= 0 || ! self::dev_tools_allowed() ) {
				return;
			}

			set_transient( self::get_preview_transient_key( $user_id ), '1', HOUR_IN_SECONDS );
		}

		/**
		 * @since 6.9.0
		 * @param int $user_id User ID.
		 * @return void
		 */
		public static function clear_dev_preview( $user_id = 0 ) {
			$user_id = $user_id > 0 ? $user_id : get_current_user_id();
			if ( $user_id <= 0 ) {
				return;
			}

			delete_transient( self::get_preview_transient_key( $user_id ) );
		}

		/**
		 * @since 6.9.0
		 * @param int $user_id User ID.
		 * @return string
		 */
		private static function get_preview_transient_key( $user_id ) {
			return self::PREVIEW_TRANSIENT_PREFIX . (int) $user_id;
		}

		/**
		 * Force the review notice for local QA (bypasses day/milestone gates).
		 *
		 * @since 6.9.0
		 * @return bool
		 */
		public static function is_dev_preview_enabled() {
			if ( ! self::dev_tools_allowed() ) {
				return false;
			}

			if ( defined( 'ESF_PREVIEW_REVIEW_REQUEST' ) && ESF_PREVIEW_REVIEW_REQUEST ) {
				return true;
			}

			$user_id = get_current_user_id();
			if ( $user_id > 0 && get_transient( self::get_preview_transient_key( $user_id ) ) ) {
				return true;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- dev-only UI preview flag.
			if ( isset( $_GET['esf_preview_review'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['esf_preview_review'] ) ) ) {
				return true;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- dev-only REST preview flag.
			if ( isset( $_GET['preview'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['preview'] ) ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Clear review notice state for QA.
		 *
		 * @since 6.9.0
		 * @param int  $user_id User ID.
		 * @param bool $include_site_milestones Whether to clear site milestones + legacy dismiss.
		 * @return void
		 */
		public static function reset_state_for_user( $user_id, $include_site_milestones = false ) {
			$user_id = (int) $user_id;
			if ( $user_id > 0 ) {
				delete_user_meta( $user_id, self::USER_META_STATE );
			}

			if ( $include_site_milestones ) {
				delete_option( self::SITE_MILESTONES_KEY );
				delete_site_option( self::LEGACY_DISMISSED_KEY );
			}
		}

		/**
		 * Admin screen IDs used across ESF (legacy + modern parent slugs).
		 *
		 * @since 6.9.0
		 * @return string[]
		 */
		public static function get_esf_admin_screen_ids() {
			if ( class_exists( 'ESF_Admin_Paths' ) ) {
				return ESF_Admin_Paths::get_esf_admin_screen_ids();
			}

			return array(
				'toplevel_page_easy-social-feed',
				'admin_page_esf_welcome',
			);
		}

		/**
		 * React module dashboards that render the shared in-app notice.
		 *
		 * @since 6.9.0
		 * @return string[]
		 */
		public static function get_modern_dashboard_screen_ids() {
			return array(
				ESF_Admin_Paths::submenu_screen_id( 'esf-instagram' ),
				ESF_Admin_Paths::submenu_screen_id( 'esf-twitter' ),
				ESF_Admin_Paths::submenu_screen_id( 'esf-youtube' ),
			);
		}

		/**
		 * Record a positive usage milestone for contextual review prompts.
		 *
		 * @since 6.9.0
		 * @param string $type   Milestone type: account_connected|feed_saved.
		 * @param string $module Module slug: instagram|twitter|youtube|facebook.
		 * @return void
		 */
		public static function record_milestone( $type, $module = '' ) {
			$type   = sanitize_key( (string) $type );
			$module = sanitize_key( (string) $module );

			if ( ! in_array( $type, array( 'account_connected', 'feed_saved' ), true ) ) {
				return;
			}

			$milestones = self::get_milestones();
			$milestones['last_type']   = $type;
			$milestones['last_module'] = $module;
			$milestones['last_at']     = time();
			$milestones['pending']     = true;

			if ( empty( $milestones['history'][ $type ] ) ) {
				$milestones['history'][ $type ] = time();
			}

			update_option( self::SITE_MILESTONES_KEY, $milestones, false );
		}

		/**
		 * REST / React payload for the current user.
		 *
		 * @since 6.9.0
		 * @param int $user_id User ID.
		 * @return array<string,mixed>
		 */
		public static function get_state_for_user( $user_id = 0 ) {
			$user_id = $user_id > 0 ? $user_id : get_current_user_id();

			$base = array(
				'visible'      => false,
				'title'        => '',
				'message'      => '',
				'review_url'   => self::REVIEW_URL,
				'support_url'  => self::SUPPORT_URL,
				'milestone'    => '',
				'module'       => '',
				'active_days'  => self::get_active_days(),
				'preview'      => self::is_dev_preview_enabled(),
			);

			if ( ! self::user_can_see_notice( $user_id ) ) {
				return $base;
			}

			if ( ! self::is_eligible_to_show( $user_id ) ) {
				return $base;
			}

			$copy = self::get_notice_copy();
			$base['visible']     = true;
			$base['title']       = $copy['title'];
			$base['message']     = $copy['message'];
			$base['milestone']   = $copy['milestone'];
			$base['module']      = $copy['module'];

			return $base;
		}

		/**
		 * Whether the legacy PHP admin notice should render.
		 *
		 * @since 6.9.0
		 * @return bool
		 */
		public static function should_show_legacy_admin_notice() {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || empty( $screen->id ) ) {
				return false;
			}

			if ( in_array( $screen->id, self::get_modern_dashboard_screen_ids(), true ) ) {
				return false;
			}

			return in_array( $screen->id, self::get_esf_admin_screen_ids(), true )
				&& self::get_state_for_user()['visible'];
		}

		/**
		 * Handle a user action on the review notice.
		 *
		 * @since 6.9.0
		 * @param string $action Action slug.
		 * @param int    $user_id User ID.
		 * @return array<string,mixed>
		 */
		public static function handle_action( $action, $user_id = 0 ) {
			$user_id = $user_id > 0 ? $user_id : get_current_user_id();
			$action  = sanitize_key( (string) $action );

			if ( ! self::user_can_see_notice( $user_id ) ) {
				return array(
					'success' => false,
					'state'   => self::get_state_for_user( $user_id ),
				);
			}

			$state = self::get_user_state( $user_id );

			switch ( $action ) {
				case 'snooze':
					$state['snoozed_until'] = time() + ( DAY_IN_SECONDS * self::SNOOZE_DAYS );
					break;
				case 'dismiss':
				case 'already_rated':
				case 'left_review':
					$state['dismissed'] = true;
					self::set_legacy_dismissed();
					break;
				case 'not_happy':
					$state['snoozed_until'] = time() + ( DAY_IN_SECONDS * self::NEGATIVE_SNOOZE_DAYS );
					break;
				default:
					return array(
						'success' => false,
						'state'   => self::get_state_for_user( $user_id ),
					);
			}

			self::clear_pending_milestone();
			self::save_user_state( $user_id, $state );
			self::clear_dev_preview( $user_id );

			return array(
				'success' => true,
				'state'   => self::get_state_for_user( $user_id ),
			);
		}

		/**
		 * Render the legacy dismissible admin notice markup.
		 *
		 * @since 6.9.0
		 * @return void
		 */
		public static function render_legacy_admin_notice() {
			if ( ! self::should_show_legacy_admin_notice() ) {
				return;
			}

			$state = self::get_state_for_user();
			?>
			<div class="notice notice-info is-dismissible esf-review-request-notice fta_msg" data-esf-review-request="legacy">
				<?php if ( self::is_dev_preview_enabled() ) : ?>
					<p><span class="esf-dsh-review-request__preview-badge"><?php esc_html_e( 'Developer preview', 'easy-facebook-likebox' ); ?></span></p>
				<?php endif; ?>
				<p class="esf-review-request-notice__title"><strong><?php echo esc_html( $state['title'] ); ?></strong></p>
				<p class="esf-review-request-notice__text"><?php echo esc_html( $state['message'] ); ?></p>
				<p class="esf-review-request-notice__question"><?php esc_html_e( 'Are you enjoying Easy Social Feed so far?', 'easy-facebook-likebox' ); ?></p>
				<p class="esf-review-request-notice__actions">
					<button type="button" class="button button-primary esf-review-request-action" data-esf-review-action="happy">
						<?php esc_html_e( 'Yes, happy to leave a review', 'easy-facebook-likebox' ); ?>
					</button>
					<button type="button" class="button esf-review-request-action" data-esf-review-action="snooze">
						<?php esc_html_e( 'Maybe later', 'easy-facebook-likebox' ); ?>
					</button>
					<button type="button" class="button-link esf-review-request-action" data-esf-review-action="not_happy">
						<?php esc_html_e( 'Not really', 'easy-facebook-likebox' ); ?>
					</button>
				</p>
				<p class="esf-review-request-notice__followup" hidden>
					<?php esc_html_e( 'Thank you! A quick WordPress.org review helps other site owners discover Easy Social Feed.', 'easy-facebook-likebox' ); ?>
					<a class="button button-primary esf-review-request-action" data-esf-review-action="left_review" href="<?php echo esc_url( self::REVIEW_URL ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Leave a ★★★★★ review', 'easy-facebook-likebox' ); ?>
					</a>
					<button type="button" class="button esf-review-request-action" data-esf-review-action="already_rated">
						<?php esc_html_e( 'I already reviewed it', 'easy-facebook-likebox' ); ?>
					</button>
				</p>
				<p class="esf-review-request-notice__support" hidden>
					<?php
					printf(
						/* translators: %s: support URL */
						wp_kses_post( __( 'Sorry to hear that. <a href="%s" target="_blank" rel="noopener noreferrer">Contact our support team</a> and we will help.', 'easy-facebook-likebox' ) ),
						esc_url( self::SUPPORT_URL )
					);
					?>
				</p>
			</div>
			<?php
		}

		/**
		 * @since 6.9.0
		 * @param int $user_id User ID.
		 * @return bool
		 */
		private static function user_can_see_notice( $user_id ) {
			return user_can( $user_id, 'install_plugins' ) || user_can( $user_id, 'manage_options' );
		}

		/**
		 * @since 6.9.0
		 * @param int $user_id User ID.
		 * @return bool
		 */
		private static function is_eligible_to_show( $user_id ) {
			if ( self::is_dismissed_forever( $user_id ) ) {
				return false;
			}

			if ( self::is_snoozed( $user_id ) ) {
				return false;
			}

			if ( self::is_dev_preview_enabled() ) {
				self::maybe_seed_preview_milestone();
				return true;
			}

			$active_days = self::get_active_days();
			if ( $active_days < self::MIN_ACTIVE_DAYS ) {
				return false;
			}

			$milestones = self::get_milestones();
			if ( ! empty( $milestones['pending'] ) ) {
				return true;
			}

			if ( ! empty( $milestones['history'] ) && $active_days >= self::MIN_ACTIVE_DAYS ) {
				return true;
			}

			return $active_days >= self::FALLBACK_DAYS;
		}

		/**
		 * @since 6.9.0
		 * @return int
		 */
		private static function get_active_days() {
			$install_date = '';
			if ( class_exists( 'Feed_Them_All' ) ) {
				$fta          = new Feed_Them_All();
				$install_date = $fta->fta_get_settings( 'installDate' );
			}

			if ( ! is_string( $install_date ) || '' === $install_date ) {
				$days = 0;
			} else {
				try {
					$datetime1 = new DateTime( $install_date );
					$datetime2 = new DateTime( 'now' );
					$days      = max( 0, (int) round( ( $datetime2->format( 'U' ) - $datetime1->format( 'U' ) ) / DAY_IN_SECONDS ) );
				} catch ( Exception $e ) {
					$days = 0;
				}
			}

			/**
			 * Filter the calculated active install days (testing/adjustments).
			 *
			 * @since 6.9.0
			 * @param int $days Active days since install.
			 */
			return (int) apply_filters( 'esf_review_request_active_days', $days );
		}

		/**
		 * @since 6.9.0
		 * @param int $user_id User ID.
		 * @return bool
		 */
		private static function is_dismissed_forever( $user_id ) {
			if ( 'yes' === get_site_option( self::LEGACY_DISMISSED_KEY ) ) {
				return true;
			}

			$state = self::get_user_state( $user_id );
			return ! empty( $state['dismissed'] );
		}

		/**
		 * @since 6.9.0
		 * @param int $user_id User ID.
		 * @return bool
		 */
		private static function is_snoozed( $user_id ) {
			$state = self::get_user_state( $user_id );
			return ! empty( $state['snoozed_until'] ) && (int) $state['snoozed_until'] > time();
		}

		/**
		 * @since 6.9.0
		 * @return array<string,mixed>
		 */
		private static function get_milestones() {
			$milestones = get_option( self::SITE_MILESTONES_KEY, array() );
			return is_array( $milestones ) ? $milestones : array();
		}

		/**
		 * @since 6.9.0
		 * @return void
		 */
		private static function clear_pending_milestone() {
			$milestones = self::get_milestones();
			if ( empty( $milestones['pending'] ) ) {
				return;
			}
			$milestones['pending'] = false;
			update_option( self::SITE_MILESTONES_KEY, $milestones, false );
		}

		/**
		 * @since 6.9.0
		 * @return void
		 */
		private static function set_legacy_dismissed() {
			update_site_option( self::LEGACY_DISMISSED_KEY, 'yes' );
		}

		/**
		 * @since 6.9.0
		 * @param int $user_id User ID.
		 * @return array<string,mixed>
		 */
		private static function get_user_state( $user_id ) {
			$state = get_user_meta( $user_id, self::USER_META_STATE, true );
			return is_array( $state ) ? $state : array();
		}

		/**
		 * @since 6.9.0
		 * @param int                  $user_id User ID.
		 * @param array<string,mixed> $state State array.
		 * @return void
		 */
		private static function save_user_state( $user_id, $state ) {
			update_user_meta( $user_id, self::USER_META_STATE, $state );
		}

		/**
		 * @since 6.9.0
		 * @return array{title:string,message:string,milestone:string,module:string}
		 */
		private static function get_notice_copy() {
			$milestones = self::get_milestones();
			$type       = isset( $milestones['last_type'] ) ? sanitize_key( (string) $milestones['last_type'] ) : '';
			$module     = isset( $milestones['last_module'] ) ? sanitize_key( (string) $milestones['last_module'] ) : '';
			$labels     = self::get_module_labels();

			if ( 'feed_saved' === $type && isset( $labels[ $module ] ) ) {
				return array(
					'title'     => sprintf(
						/* translators: %s: social network name */
						__( 'Your %s feed is ready!', 'easy-facebook-likebox' ),
						$labels[ $module ]
					),
					'message'   => __(
						'If Easy Social Feed has been helpful, sharing your experience on WordPress.org helps other site owners discover it.',
						'easy-facebook-likebox'
					),
					'milestone' => $type,
					'module'    => $module,
				);
			}

			if ( 'account_connected' === $type && isset( $labels[ $module ] ) ) {
				return array(
					'title'     => sprintf(
						/* translators: %s: social network name */
						__( '%s account connected successfully!', 'easy-facebook-likebox' ),
						$labels[ $module ]
					),
					'message'   => __(
						'You are all set. If the setup was smooth, a quick review helps us keep improving Easy Social Feed.',
						'easy-facebook-likebox'
					),
					'milestone' => $type,
					'module'    => $module,
				);
			}

			return array(
				'title'     => __( 'Thanks for using Easy Social Feed!', 'easy-facebook-likebox' ),
				'message'   => __(
					'If the plugin has been useful on your site, we would really appreciate a kind review on WordPress.org.',
					'easy-facebook-likebox'
				),
				'milestone' => '',
				'module'    => '',
			);
		}

		/**
		 * @since 6.9.0
		 * @return void
		 */
		private static function maybe_seed_preview_milestone() {
			$milestones = self::get_milestones();
			if ( ! empty( $milestones['last_type'] ) ) {
				return;
			}

			self::record_milestone( 'feed_saved', 'instagram' );
		}

		/**
		 * @since 6.9.0
		 * @return array<string,string>
		 */
		private static function get_module_labels() {
			return array(
				'instagram' => __( 'Instagram', 'easy-facebook-likebox' ),
				'twitter'   => __( 'X', 'easy-facebook-likebox' ),
				'youtube'   => __( 'YouTube', 'easy-facebook-likebox' ),
				'facebook'  => __( 'Facebook', 'easy-facebook-likebox' ),
			);
		}
	}
}

/**
 * Record a review-request milestone when available.
 *
 * @since 6.9.0
 * @param string $type   Milestone type.
 * @param string $module Module slug.
 * @return void
 */
function esf_review_request_record_milestone( $type, $module = '' ) {
	if ( class_exists( 'ESF_Review_Request' ) ) {
		ESF_Review_Request::record_milestone( $type, $module );
	}
}

if ( function_exists( 'add_action' ) ) {
	ESF_Review_Request::register_hooks();
}
