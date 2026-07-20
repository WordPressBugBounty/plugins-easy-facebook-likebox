<?php
/**
 * Global plugin settings REST API.
 *
 * Powers the React-based `?page=esf-settings` screen (General, GDPR, Translation).
 *
 * Routes:
 *   GET  /esf/v1/settings
 *   PUT  /esf/v1/settings/general
 *   PUT  /esf/v1/settings/gdpr
 *   PUT  /esf/v1/settings/translation
 *   POST /esf/v1/settings/translation/suggestions  (premium)
 *
 * @package    Easy_Social_Feed
 * @subpackage Admin/API
 * @since      6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_API_Settings' ) ) {

	/**
	 * REST controller for global plugin settings.
	 *
	 * @since 6.9.0
	 */
	class ESF_API_Settings {

		/**
		 * REST namespace.
		 *
		 * @since 6.9.0
		 * @var string
		 */
		const NAMESPACE_V1 = 'esf/v1';

		/**
		 * Register REST routes.
		 *
		 * @since 6.9.0
		 * @return void
		 */
		public static function register_routes() {
			register_rest_route(
				self::NAMESPACE_V1,
				'/settings',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_settings' ),
						'permission_callback' => array( __CLASS__, 'read_permissions_check' ),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				'/settings/general',
				array(
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( __CLASS__, 'put_general' ),
						'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
						'args'                => array(
							'preserve_settings_on_uninstall' => array(
								'type'     => 'boolean',
								'required' => true,
							),
							'api_locale'                     => array(
								'type'     => 'string',
								'required' => true,
							),
						),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				'/settings/gdpr',
				array(
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( __CLASS__, 'put_gdpr' ),
						'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
						'args'                => array(
							'gdpr' => array(
								'type'     => 'string',
								'required' => true,
								'enum'     => array( 'auto', 'yes', 'no' ),
							),
						),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				'/settings/translation',
				array(
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( __CLASS__, 'put_translation' ),
						'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
						'args'                => array(
							'translation' => array(
								'type'                 => 'object',
								'required'             => true,
								'additionalProperties' => array(
									'type' => 'string',
								),
							),
						),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				'/settings/translation/suggestions',
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( __CLASS__, 'post_translation_suggestions' ),
						'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
						'args'                => array(
							'locale' => array(
								'type'     => 'string',
								'required' => true,
							),
							'keys'   => array(
								'type'     => 'array',
								'required' => true,
								'items'    => array(
									'type' => 'string',
								),
							),
						),
					),
				)
			);
		}

		/**
		 * Capability required to view settings.
		 *
		 * @since 6.9.0
		 * @return string
		 */
		public static function capability() {
			/**
			 * Capability required to manage global plugin settings.
			 *
			 * @since 6.9.0
			 * @param string $capability Required capability. Default `administrator`.
			 */
			return apply_filters( 'esf_settings_manage_capability', 'administrator' );
		}

		/**
		 * Read permission callback.
		 *
		 * @since 6.9.0
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
		 * Write permission callback.
		 *
		 * @since 6.9.0
		 * @param WP_REST_Request $request Request object.
		 * @return bool
		 */
		public static function write_permissions_check( $request ) {
			unset( $request );
			return current_user_can( self::capability() );
		}

		/**
		 * GET /settings — full settings payload for the React app.
		 *
		 * @since 6.9.0
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response
		 */
		public static function get_settings( $request ) {
			unset( $request );

			if ( ! class_exists( 'ESF_Settings' ) ) {
				return new WP_Error(
					'esf_settings_unavailable',
					__( 'Settings storage is unavailable.', 'easy-facebook-likebox' ),
					array( 'status' => 500 )
				);
			}

			return rest_ensure_response( ESF_Settings::build_admin_payload() );
		}

		/**
		 * PUT /settings/general
		 *
		 * @since 6.9.0
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function put_general( $request ) {
			if ( ! class_exists( 'ESF_Settings' ) ) {
				return new WP_Error(
					'esf_settings_unavailable',
					__( 'Settings storage is unavailable.', 'easy-facebook-likebox' ),
					array( 'status' => 500 )
				);
			}

			$preserve   = (bool) $request->get_param( 'preserve_settings_on_uninstall' );
			$api_locale = (string) $request->get_param( 'api_locale' );

			ESF_Settings::save_general( $preserve, $api_locale );

			return rest_ensure_response(
				array(
					'message' => __( 'Settings saved successfully!', 'easy-facebook-likebox' ),
				)
			);
		}

		/**
		 * PUT /settings/gdpr
		 *
		 * @since 6.9.0
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function put_gdpr( $request ) {
			if ( ! class_exists( 'ESF_Settings' ) ) {
				return new WP_Error(
					'esf_settings_unavailable',
					__( 'Settings storage is unavailable.', 'easy-facebook-likebox' ),
					array( 'status' => 500 )
				);
			}

			$gdpr = (string) $request->get_param( 'gdpr' );
			if ( ! ESF_Settings::save_gdpr( $gdpr ) ) {
				return new WP_Error(
					'esf_invalid_gdpr',
					__( 'Something went wrong! Please try again.', 'easy-facebook-likebox' ),
					array( 'status' => 400 )
				);
			}

			return rest_ensure_response(
				array(
					'message' => __( 'Settings saved successfully!', 'easy-facebook-likebox' ),
				)
			);
		}

		/**
		 * PUT /settings/translation
		 *
		 * @since 6.9.0
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function put_translation( $request ) {
			if ( ! class_exists( 'ESF_Settings' ) ) {
				return new WP_Error(
					'esf_settings_unavailable',
					__( 'Settings storage is unavailable.', 'easy-facebook-likebox' ),
					array( 'status' => 500 )
				);
			}

			$translation = $request->get_param( 'translation' );
			if ( $translation instanceof stdClass ) {
				$translation = (array) $translation;
			}
			if ( ! is_array( $translation ) ) {
				$json = $request->get_json_params();
				if ( isset( $json['translation'] ) && is_array( $json['translation'] ) ) {
					$translation = $json['translation'];
				} elseif ( isset( $json['translation'] ) && $json['translation'] instanceof stdClass ) {
					$translation = (array) $json['translation'];
				} else {
					$translation = array();
				}
			}

			$flat = array();
			foreach ( $translation as $key => $value ) {
				if ( is_string( $key ) && is_string( $value ) ) {
					$flat[ $key ] = $value;
				}
			}

			if ( ! ESF_Settings::save_translation( $flat ) ) {
				return new WP_Error(
					'esf_translation_save_failed',
					__( 'Something went wrong! Please try again.', 'easy-facebook-likebox' ),
					array( 'status' => 500 )
				);
			}

			return rest_ensure_response(
				array(
					'message' => __( 'Settings saved successfully!', 'easy-facebook-likebox' ),
				)
			);
		}

		/**
		 * POST /settings/translation/suggestions — premium AI autofill.
		 *
		 * @since 6.9.0
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function post_translation_suggestions( $request ) {
			if ( ! function_exists( 'efl_fs' ) || ! efl_fs()->can_use_premium_code__premium_only() ) {
				return new WP_Error(
					'esf_premium_required',
					__( 'Auto-translation is available in the premium version only.', 'easy-facebook-likebox' ),
					array( 'status' => 403 )
				);
			}

			if ( ! class_exists( 'ESF_Settings' ) ) {
				return new WP_Error(
					'esf_settings_unavailable',
					__( 'Settings storage is unavailable.', 'easy-facebook-likebox' ),
					array( 'status' => 500 )
				);
			}

			$locale = (string) $request->get_param( 'locale' );
			$keys   = $request->get_param( 'keys' );
			if ( ! is_array( $keys ) ) {
				$keys = array();
			}

			$result = ESF_Settings::fetch_translation_suggestions( $locale, $keys );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response(
				array(
					'suggestions' => $result,
				)
			);
		}
	}
}
