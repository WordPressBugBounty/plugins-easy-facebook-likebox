<?php
/**
 * Instagram Migration REST API
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/API
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_API_Migrate
 *
 * @since 6.8.0
 */
class ESF_Instagram_API_Migrate {

	/**
	 * Register migration routes.
	 *
	 * @since 6.8.0
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/instagram/migrate/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/instagram/migrate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'run' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/instagram/migrate/switch-legacy',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'switch_legacy' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => array(
					'force' => array(
						'description'       => __( 'Allow switching on fresh modern installs (support URL).', 'easy-facebook-likebox' ),
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			'esf/v1',
			'/instagram/migrate/dismiss-guidance',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'dismiss_guidance' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/instagram/migrate/dismiss-legacy-switch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'dismiss_legacy_switch' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);
	}

	/**
	 * Permission check callback.
	 *
	 * @since 6.8.0
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function permissions_check( $request ) {
		if ( ! esf_instagram_user_can_manage() ) {
			return false;
		}

		if ( $request instanceof WP_REST_Request && 'GET' === $request->get_method() ) {
			return true;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		return (bool) ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) );
	}

	/**
	 * Return migration status details.
	 *
	 * @since 6.8.0
	 * @return WP_REST_Response
	 */
	public static function status() {
		$status = function_exists( 'esf_instagram_get_module_system_status' )
			? esf_instagram_get_module_system_status()
			: array();

		return rest_ensure_response( $status );
	}

	/**
	 * Execute one-time migration.
	 *
	 * @since 6.8.0
	 * @return WP_REST_Response|WP_Error
	 */
	public static function run() {
		if ( ! class_exists( 'ESF_Instagram_Migrator' ) ) {
			return new WP_Error(
				'esf_instagram_migrate_unavailable',
				__( 'Instagram migration is unavailable.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$result = ESF_Instagram_Migrator::get_instance()->migrate();
		if ( ! is_array( $result ) ) {
			return new WP_Error(
				'esf_instagram_migrate_failed',
				__( 'Instagram migration failed.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array_merge(
				array(
					'success' => true,
				),
				$result
			)
		);
	}

	/**
	 * Switch back to the legacy Instagram admin without deleting stored data.
	 *
	 * Fresh modern installs may pass `{ "force": true }` (from `?esf_force_legacy=1`).
	 *
	 * @since 6.9.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function switch_legacy( $request = null ) {
		if ( ! class_exists( 'ESF_Module_System' ) ) {
			return new WP_Error(
				'esf_instagram_switch_legacy_unavailable',
				__( 'Module system switching is unavailable.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$force = false;
		if ( $request instanceof WP_REST_Request ) {
			$force = rest_sanitize_boolean( $request->get_param( 'force' ) );
		}

		$result = ESF_Module_System::switch_to_legacy(
			'instagram',
			'esf_instagram_has_legacy_data',
			array(
				'force' => $force,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success'          => true,
				'legacy_admin_url' => ESF_Module_System::get_admin_url( 'instagram', 'legacy' ),
			)
		);
	}

	/**
	 * Dismiss the post-migration welcome banner for this site.
	 *
	 * @since 6.9.0
	 * @return WP_REST_Response|WP_Error
	 */
	public static function dismiss_guidance() {
		if ( ! class_exists( 'ESF_Module_System' ) ) {
			return new WP_Error(
				'esf_instagram_dismiss_guidance_unavailable',
				__( 'Module system is unavailable.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		if ( ! ESF_Module_System::dismiss_migration_guidance( 'instagram' ) ) {
			return new WP_Error(
				'esf_instagram_dismiss_guidance_failed',
				__( 'Could not dismiss the welcome notice.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'                        => true,
				'migration_guidance_dismissed'   => true,
			)
		);
	}

	/**
	 * Dismiss the legacy-switch banner for this site.
	 *
	 * @since 6.9.0
	 * @return WP_REST_Response|WP_Error
	 */
	public static function dismiss_legacy_switch() {
		if ( ! class_exists( 'ESF_Module_System' ) ) {
			return new WP_Error(
				'esf_instagram_dismiss_legacy_switch_unavailable',
				__( 'Module system is unavailable.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		if ( ! ESF_Module_System::dismiss_legacy_switch_notice( 'instagram' ) ) {
			return new WP_Error(
				'esf_instagram_dismiss_legacy_switch_failed',
				__( 'Could not dismiss the legacy notice.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'                          => true,
				'legacy_switch_notice_dismissed'   => true,
			)
		);
	}
}
