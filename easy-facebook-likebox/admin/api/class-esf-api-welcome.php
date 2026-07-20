<?php
/**
 * Welcome wizard REST API.
 *
 * Exposes the endpoints the first-run setup wizard talks to. Mirrors the
 * conventions used by ESF_Twitter_API_Settings / ESF_YouTube_API_Settings:
 * single namespace (`esf/v1`), capability-based read perms, additional
 * `wp_rest` nonce on writes.
 *
 * Routes:
 *   GET  /esf/v1/welcome/state               Wizard state + module catalogue.
 *   PUT  /esf/v1/welcome/state               Persist current_step / completed / chosen_modules.
 *   POST /esf/v1/welcome/modules/(?P<slug>)  Toggle a module's activation status.
 *
 * @package    Easy_Social_Feed
 * @subpackage Welcome/API
 * @since      6.8.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_API_Welcome' ) ) {

	/**
	 * Class ESF_API_Welcome
	 *
	 * @since 6.8.0
	 */
	class ESF_API_Welcome {

		/**
		 * REST namespace.
		 *
		 * @since 6.8.0
		 * @var string
		 */
		const NAMESPACE_V1 = 'esf/v1';

		/**
		 * Quick-start video YouTube ID used on step 4.
		 *
		 * @since 6.8.0
		 * @var string
		 */
		const QUICK_START_VIDEO_ID = '9ZvHmlozcHA';

		/**
		 * Register the REST routes.
		 *
		 * Hooked from ESF_Admin on `rest_api_init`.
		 *
		 * @since 6.8.0
		 * @return void
		 */
		public static function register_routes() {
			register_rest_route(
				self::NAMESPACE_V1,
				'/welcome/state',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_state' ),
						'permission_callback' => array( __CLASS__, 'read_permissions_check' ),
					),
					array(
						'methods'             => 'PUT',
						'callback'            => array( __CLASS__, 'update_state' ),
						'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				'/welcome/modules/(?P<slug>[a-z0-9_-]+)',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'toggle_module' ),
					'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
					'args'                => array(
						'slug'   => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'status' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( 'activated', 'deactivated' ),
						),
					),
				)
			);
		}

		/**
		 * Capability used by the wizard endpoints.
		 *
		 * Filterable so site owners can swap in a custom capability without
		 * patching core code, mirroring the `esf_twitter_manage_capability`
		 * filter on the Twitter side.
		 *
		 * @since 6.8.0
		 * @return string
		 */
		public static function capability() {
			/**
			 * Capability required to manage the welcome wizard.
			 *
			 * @since 6.8.0
			 * @param string $capability Required capability. Default 'manage_options'.
			 */
			return apply_filters( 'esf_welcome_manage_capability', 'manage_options' );
		}

		/**
		 * Read permission callback.
		 *
		 * @since 6.8.0
		 * @param WP_REST_Request $request Request object.
		 * @return bool
		 */
		public static function read_permissions_check( $request ) {
			if ( $request instanceof WP_REST_Request && 'GET' !== $request->get_method() ) {
				return false;
			}
			return current_user_can( self::capability() );
		}

		/**
		 * Write permission callback (cap + REST nonce).
		 *
		 * @since 6.8.0
		 * @param WP_REST_Request $request Request object.
		 * @return bool
		 */
		public static function write_permissions_check( $request ) {
			if ( ! current_user_can( self::capability() ) ) {
				return false;
			}

			$nonce = $request->get_header( 'X-WP-Nonce' );
			return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
		}

		/**
		 * GET /welcome/state — wizard state + module catalogue.
		 *
		 * @since 6.8.0
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response
		 */
		public static function get_state( $request ) {
			unset( $request );

			return rest_ensure_response( self::build_state_payload() );
		}

		/**
		 * PUT /welcome/state — persist a partial state update.
		 *
		 * @since 6.8.0
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function update_state( $request ) {
			$body = $request->get_json_params();
			if ( ! is_array( $body ) ) {
				return new WP_Error(
					'esf_welcome_invalid_body',
					__( 'Invalid request body.', 'easy-facebook-likebox' ),
					array( 'status' => 400 )
				);
			}

			ESF_Welcome_State::update_state( $body );

			return rest_ensure_response( self::build_state_payload() );
		}

		/**
		 * POST /welcome/modules/{slug} — toggle a module's status.
		 *
		 * Writes to `fta_settings['plugins'][$slug]['status']` so the rest of
		 * the plugin (Feed_Them_All::module_status etc.) sees the new state
		 * immediately, with no duplicate storage.
		 *
		 * @since 6.8.0
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function toggle_module( $request ) {
			$slug   = sanitize_key( (string) $request->get_param( 'slug' ) );
			$status = (string) $request->get_param( 'status' );

			if ( ! in_array( $slug, ESF_Welcome_State::ALLOWED_MODULES, true ) ) {
				return new WP_Error(
					'esf_welcome_invalid_module',
					__( 'Unknown module.', 'easy-facebook-likebox' ),
					array( 'status' => 400 )
				);
			}

			if ( ! in_array( $status, array( 'activated', 'deactivated' ), true ) ) {
				return new WP_Error(
					'esf_welcome_invalid_status',
					__( 'Invalid status.', 'easy-facebook-likebox' ),
					array( 'status' => 400 )
				);
			}

			if ( class_exists( 'ESF_Settings' ) ) {
				$saved = ESF_Settings::set_module_status( $slug, $status );
			} else {
				$fta_settings = get_option( 'fta_settings', array() );
				if ( ! is_array( $fta_settings ) ) {
					$fta_settings = array();
				}
				if ( ! isset( $fta_settings['plugins'] ) || ! is_array( $fta_settings['plugins'] ) ) {
					$fta_settings['plugins'] = array();
				}
				if ( ! isset( $fta_settings['plugins'][ $slug ] ) || ! is_array( $fta_settings['plugins'][ $slug ] ) ) {
					$fta_settings['plugins'][ $slug ] = array();
				}
				$fta_settings['plugins'][ $slug ]['status'] = $status;
				$saved                                      = update_option( 'fta_settings', $fta_settings );
			}

			if ( ! $saved ) {
				return new WP_Error(
					'esf_welcome_module_save_failed',
					__( 'Could not update module status.', 'easy-facebook-likebox' ),
					array( 'status' => 500 )
				);
			}

			if ( 'deactivated' === $status && 'twitter' === $slug && function_exists( 'esf_twitter_teardown_scheduled_jobs' ) ) {
				esf_twitter_teardown_scheduled_jobs();
			}

			return rest_ensure_response(
				array(
					'slug'   => $slug,
					'status' => $status,
				)
			);
		}

		/**
		 * Build the full state response payload returned by GET / PUT.
		 *
		 * Centralised so the GET and PUT handlers always return the same
		 * shape, which keeps the React client simple.
		 *
		 * @since 6.8.0
		 * @return array
		 */
		private static function build_state_payload() {
			return array(
				'state'   => ESF_Welcome_State::get_state(),
				'modules' => self::get_module_catalogue(),
				'video'   => self::get_video_payload(),
				'links'   => self::get_links_payload(),
			);
		}

		/**
		 * Return module info enriched with brand metadata for the wizard UI.
		 *
		 * Keeps the wizard UI declarative — every visual / behavioural detail
		 * (brand colour, configure URL, "modern" flag, OAuth capability) is
		 * decided server-side so we don't duplicate slugs/colours in JS.
		 *
		 * @since 6.8.0
		 * @return array<int,array<string,mixed>>
		 */
		private static function get_module_catalogue() {
			if ( ! class_exists( 'ESF_Module_Catalogue' ) ) {
				return array();
			}

			$modules = ESF_Module_Catalogue::get_modules();
			$allowed = array_flip( ESF_Welcome_State::ALLOWED_MODULES );

			return array_values(
				array_filter(
					$modules,
					static function ( $module ) use ( $allowed ) {
						return is_array( $module )
							&& isset( $module['slug'] )
							&& isset( $allowed[ $module['slug'] ] );
					}
				)
			);
		}

		/**
		 * Quick-start video metadata for step 4 (lazy loaded thumbnail).
		 *
		 * @since 6.8.0
		 * @return array
		 */
		private static function get_video_payload() {
			$id = (string) apply_filters( 'esf_welcome_quick_start_video_id', self::QUICK_START_VIDEO_ID );

			return array(
				'youtube_id'    => $id,
				'embed_url'     => sprintf( 'https://www.youtube.com/embed/%s?autoplay=1&rel=0', rawurlencode( $id ) ),
				'thumbnail_url' => sprintf( 'https://i.ytimg.com/vi/%s/hqdefault.jpg', rawurlencode( $id ) ),
				'title'         => __( 'Easy Social Feed quick-start video', 'easy-facebook-likebox' ),
			);
		}

		/**
		 * Useful URLs returned alongside the state.
		 *
		 * @since 6.8.0
		 * @return array<string,string>
		 */
		private static function get_links_payload() {
			$upgrade = esf_get_upgrade_url( 'general' );

			return array(
				'dashboard' => ESF_Admin_Paths::hub_admin_url(),
				'support'   => 'https://easysocialfeed.com/support/',
				'docs'      => 'https://easysocialfeed.com/documentation/',
				'upgrade'   => esc_url_raw( $upgrade ),
			);
		}
	}
}
