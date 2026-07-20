<?php
/**
 * Admin module hub REST API.
 *
 * Powers the React-based `?page=feed-them-all` dashboard. Module toggling
 * reuses {@see ESF_API_Welcome::toggle_module()} so persistence stays on one path.
 *
 * Routes:
 *   GET /esf/v1/hub  Module catalogue + upgrade banner metadata.
 *
 * @package    Easy_Social_Feed
 * @subpackage Admin/API
 * @since      6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_API_Hub' ) ) {

	/**
	 * REST controller for the main module hub page.
	 *
	 * @since 6.9.0
	 */
	class ESF_API_Hub {

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
				'/hub',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_hub' ),
						'permission_callback' => array( __CLASS__, 'read_permissions_check' ),
					),
				)
			);
		}

		/**
		 * Capability required to view the hub.
		 *
		 * @since 6.9.0
		 * @return string
		 */
		public static function capability() {
			/**
			 * Capability required to view the module hub.
			 *
			 * @since 6.9.0
			 * @param string $capability Required capability. Default `administrator` to match the top-level menu.
			 */
			return apply_filters( 'esf_hub_manage_capability', 'administrator' );
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
		 * GET /hub — module catalogue and upgrade banner payload.
		 *
		 * @since 6.9.0
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response
		 */
		public static function get_hub( $request ) {
			unset( $request );

			return rest_ensure_response( self::build_payload() );
		}

		/**
		 * Build the hub response shape.
		 *
		 * @since 6.9.0
		 * @return array<string,mixed>
		 */
		public static function build_payload() {
			$payload = array(
				'modules' => class_exists( 'ESF_Module_Catalogue' )
					? ESF_Module_Catalogue::get_modules()
					: array(),
			);

			$payload['upgrade'] = self::get_upgrade_payload();

			return $payload;
		}

		/**
		 * Upgrade banner metadata for free-plan users.
		 *
		 * @since 6.9.0
		 * @return array<string,mixed>
		 */
		private static function get_upgrade_payload() {
			$is_free     = function_exists( 'efl_fs' ) && efl_fs()->is_free_plan();
			$upgrade_url = esf_get_upgrade_url( 'general' );
			$promo       = function_exists( 'esf_get_pro_promo_offer' )
				? esf_get_pro_promo_offer()
				: array(
					'discount' => '17%',
					'coupon'   => 'ESPF17',
				);

			return array(
				'visible'     => $is_free,
				'discount'    => isset( $promo['discount'] ) ? (string) $promo['discount'] : '17%',
				'coupon'      => isset( $promo['coupon'] ) ? (string) $promo['coupon'] : 'ESPF17',
				'button_text' => __( 'Upgrade Now', 'easy-facebook-likebox' ),
				'button_url'  => esc_url_raw( (string) $upgrade_url ),
				'target'      => '_blank',
			);
		}
	}
}
