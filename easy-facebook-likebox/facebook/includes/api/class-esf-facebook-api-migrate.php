<?php
/**
 * Facebook Migration REST API
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/API
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_API_Migrate
 *
 * @since 6.7.9
 */
class ESF_Facebook_API_Migrate {

	/**
	 * Register migration routes.
	 *
	 * @since 6.7.9
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/facebook/migrate/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/facebook/migrate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'run' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/facebook/migrate/switch-legacy',
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
			'/facebook/migrate/dismiss-guidance',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'dismiss_guidance' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/facebook/migrate/dismiss-legacy-switch',
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
	 * @since 6.7.9
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function permissions_check( $request ) {
		if ( ! esf_facebook_user_can_manage() ) {
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
	 * @since 6.7.9
	 * @return WP_REST_Response
	 */
	public static function status() {
		$status = function_exists( 'esf_facebook_get_module_system_status' )
			? esf_facebook_get_module_system_status()
			: array();

		return rest_ensure_response( $status );
	}

	/**
	 * Execute one-time migration.
	 *
	 * @since 6.7.9
	 * @return WP_REST_Response|WP_Error
	 */
	public static function run() {
		if ( ! class_exists( 'ESF_Facebook_Migrator' ) ) {
			$dir = defined( 'ESF_FACEBOOK_DIR' ) ? ESF_FACEBOOK_DIR : ( defined( 'EFBL_PLUGIN_DIR' ) ? EFBL_PLUGIN_DIR : '' );
			if ( '' !== $dir && file_exists( $dir . 'includes/class-esf-facebook-migrator.php' ) ) {
				require_once $dir . 'includes/class-esf-facebook-migrator.php';
			}
		}

		if ( ! class_exists( 'ESF_Facebook_Migrator' ) ) {
			return new WP_Error(
				'esf_facebook_migrate_unavailable',
				__( 'Facebook migration is unavailable.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		if ( class_exists( 'ESF_Facebook_DB_Installer' ) && ! ESF_Facebook_DB_Installer::tables_exist() ) {
			ESF_Facebook_DB_Installer::create_tables();
		}

		$result = ESF_Facebook_Migrator::get_instance()->migrate();
		if ( ! is_array( $result ) ) {
			return new WP_Error(
				'esf_facebook_migrate_failed',
				__( 'Facebook migration failed.', 'easy-facebook-likebox' ),
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
	 * Switch back to the legacy Facebook admin without deleting stored data.
	 *
	 * @since 6.7.9
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function switch_legacy( $request = null ) {
		if ( ! class_exists( 'ESF_Module_System' ) ) {
			return new WP_Error(
				'esf_facebook_switch_legacy_unavailable',
				__( 'Module system switching is unavailable.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$force = false;
		if ( $request instanceof WP_REST_Request ) {
			$force = rest_sanitize_boolean( $request->get_param( 'force' ) );
		}

		$result = ESF_Module_System::switch_to_legacy(
			'facebook',
			'esf_facebook_has_legacy_data',
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
				'legacy_admin_url' => ESF_Module_System::get_admin_url( 'facebook', 'legacy' ),
			)
		);
	}

	/**
	 * Dismiss the post-migration welcome banner for this site.
	 *
	 * @since 6.7.9
	 * @return WP_REST_Response|WP_Error
	 */
	public static function dismiss_guidance() {
		if ( ! class_exists( 'ESF_Module_System' ) ) {
			return new WP_Error(
				'esf_facebook_dismiss_guidance_unavailable',
				__( 'Module system is unavailable.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		if ( ! ESF_Module_System::dismiss_migration_guidance( 'facebook' ) ) {
			return new WP_Error(
				'esf_facebook_dismiss_guidance_failed',
				__( 'Could not dismiss the welcome notice.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'                      => true,
				'migration_guidance_dismissed' => true,
			)
		);
	}

	/**
	 * Dismiss the legacy-switch banner for this site.
	 *
	 * @since 6.7.9
	 * @return WP_REST_Response|WP_Error
	 */
	public static function dismiss_legacy_switch() {
		if ( ! class_exists( 'ESF_Module_System' ) ) {
			return new WP_Error(
				'esf_facebook_dismiss_legacy_switch_unavailable',
				__( 'Module system is unavailable.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		if ( ! ESF_Module_System::dismiss_legacy_switch_notice( 'facebook' ) ) {
			return new WP_Error(
				'esf_facebook_dismiss_legacy_switch_failed',
				__( 'Could not dismiss the legacy notice.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'                        => true,
				'legacy_switch_notice_dismissed' => true,
			)
		);
	}
}
