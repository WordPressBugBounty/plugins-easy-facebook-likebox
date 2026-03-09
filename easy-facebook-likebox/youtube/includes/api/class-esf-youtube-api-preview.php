<?php
/**
 * YouTube Feed Preview REST API.
 *
 * Returns rendered HTML (and asset URLs) for dashboard live preview.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/API
 * @since 6.7.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_API_Preview
 *
 * @since 6.7.5
 */
class ESF_YouTube_API_Preview {

	/**
	 * Register REST route for feed preview.
	 *
	 * @since 6.7.5
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/youtube/feeds/(?P<id>[\d]+)/preview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_preview' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => array(
					'id'       => array(
						'description'       => __( 'Feed ID.', 'easy-facebook-likebox' ),
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0;
						},
					),
					'settings' => array(
						'description'       => __( 'Optional settings override (JSON string) for live preview.', 'easy-facebook-likebox' ),
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => function ( $value ) {
							if ( ! is_string( $value ) || '' === trim( $value ) ) {
								return array();
							}
							$decoded = json_decode( $value, true );
							return is_array( $decoded ) ? $decoded : array();
						},
					),
				),
			)
		);
	}

	/**
	 * Permission callback: only users who can manage options.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object (unused; required by REST permission_callback signature).
	 * @return bool
	 */
	public static function permissions_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST permission_callback signature.
		return esf_youtube_user_can_manage();
	}

	/**
	 * Get preview HTML and asset URLs.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_preview( $request ) {
		$feed_id           = (int) $request['id'];
		$settings_override = $request->get_param( 'settings' );
		if ( ! is_array( $settings_override ) ) {
			$settings_override = array();
		}

		if ( ! class_exists( 'ESF_YouTube_Frontend' ) ) {
			return new WP_Error(
				'preview_unavailable',
				__( 'Frontend not loaded.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}
		$frontend = ESF_YouTube_Frontend::get_instance();
		$renderer = $frontend->get_renderer();

		$html = $renderer->render( $feed_id, $settings_override, false );
		if ( '' === $html ) {
			return new WP_Error(
				'preview_failed',
				__( 'Could not generate preview.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$feed_repo   = ESF_YouTube_Feed_Repository::get_instance();
		$feed_row    = $feed_repo->get_by_id( $feed_id );
		$layout_type = 'grid';
		if ( $feed_row && ! empty( $feed_row->settings ) ) {
			$saved       = json_decode( $feed_row->settings, true );
			$merged      = ESF_YouTube_Feed_Repository::merge_settings_with_defaults( is_array( $saved ) ? $saved : array() );
			$merged      = array_merge( $merged, $settings_override );
			$layout_type = isset( $merged['layout']['type'] ) ? $merged['layout']['type'] : 'grid';
		}

		$css_url        = $renderer->get_base_css_url();
		$layout_css_url = $renderer->get_layout_css_url( $layout_type );

		return rest_ensure_response(
			array(
				'html'           => $html,
				'css_url'        => $css_url,
				'layout_css_url' => $layout_css_url,
				'js_url'         => null,
			)
		);
	}
}
