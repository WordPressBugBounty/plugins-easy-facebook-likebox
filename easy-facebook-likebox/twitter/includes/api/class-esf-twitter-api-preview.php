<?php
/**
 * Twitter Feed Preview REST API.
 *
 * Returns rendered HTML (and asset URLs) for dashboard live preview.
 *
 * Settings overrides are accepted in the POST body (not the query string) so
 * large feed configs do not hit nginx/Apache "414 Request-URI Too Large".
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/API
 * @since 6.7.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Twitter_API_Preview
 *
 * @since 6.7.6
 */
class ESF_Twitter_API_Preview {

	/**
	 * Register REST route for feed preview.
	 *
	 * @since 6.7.6
	 *
	 * @return void
	 */
	public static function register_routes() {
		$args = array(
			'id'         => array(
				/* translators: %s: feed ID. */
				'description'       => __( 'Feed ID.', 'easy-facebook-likebox' ),
				'type'              => 'integer',
				'required'          => true,
				'validate_callback' => static function ( $param ) {
					return is_numeric( $param ) && (int) $param > 0;
				},
			),
			'settings'   => array(
				'description'       => __( 'Optional settings override for live preview (prefer POST body).', 'easy-facebook-likebox' ),
				'required'          => false,
				'validate_callback' => array( __CLASS__, 'validate_settings_param' ),
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings_param' ),
			),
			'account_id' => array(
				'description'       => __( 'Optional account ID override for unsaved source changes.', 'easy-facebook-likebox' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			'esf/v1',
			'/twitter/feeds/(?P<id>[\d]+)/preview',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'get_preview' ),
					'permission_callback' => array( __CLASS__, 'permissions_check' ),
					'args'                => $args,
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_preview' ),
					'permission_callback' => array( __CLASS__, 'permissions_check' ),
					'args'                => $args,
				),
			)
		);
	}

	/**
	 * Allow object (POST JSON) or legacy JSON string (GET query).
	 *
	 * @param mixed           $value   Raw param.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Param name.
	 * @return bool
	 */
	public static function validate_settings_param( $value, $request = null, $param = '' ) {
		unset( $request, $param );
		return null === $value || is_array( $value ) || is_string( $value );
	}

	/**
	 * Normalize settings override to an array.
	 *
	 * @param mixed           $value   Raw param.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Param name.
	 * @return array
	 */
	public static function sanitize_settings_param( $value, $request = null, $param = '' ) {
		unset( $request, $param );
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Permission callback: only users who can manage Twitter module.
	 *
	 * @since 6.7.6
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function permissions_check( $request ) {
		unset( $request );
		return function_exists( 'esf_twitter_user_can_manage' ) ? esf_twitter_user_can_manage() : current_user_can( 'manage_options' );
	}

	/**
	 * Get preview HTML and asset URLs for a feed.
	 *
	 * @since 6.7.6
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_preview( $request ) {
		$feed_id           = (int) $request['id'];
		$account_id        = (int) $request->get_param( 'account_id' );
		$settings_override = $request->get_param( 'settings' );
		if ( ! is_array( $settings_override ) ) {
			$settings_override = array();
		}

		if ( $feed_id <= 0 ) {
			return new WP_Error(
				'invalid_feed_id',
				__( 'Invalid feed ID.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$feed_repo           = ESF_Twitter_Feed_Repository::get_instance();
		$feed_row            = $feed_repo->get_by_id( $feed_id );
		$resolved_account_id = $account_id > 0
			? $account_id
			: ( $feed_row && ! empty( $feed_row->account_id ) ? (int) $feed_row->account_id : 0 );
		$has_local_cache     = false;
		$notice              = null;
		if ( $feed_row && class_exists( 'ESF_Twitter_Renderer' ) ) {
			$saved           = json_decode( $feed_row->settings, true );
			$merged          = ESF_Twitter_Feed_Repository::merge_settings_with_defaults( is_array( $saved ) ? $saved : array() );
			$merged          = array_merge( $merged, $settings_override );
			$probe           = (object) array(
				'id'         => (int) $feed_row->id,
				'account_id' => $resolved_account_id,
				'feed_type'  => isset( $feed_row->feed_type ) ? (string) $feed_row->feed_type : 'timeline',
				'source_id'  => isset( $feed_row->source_id ) ? (string) $feed_row->source_id : '',
				'settings'   => $merged,
			);
			$has_local_cache = ESF_Twitter_Renderer::feed_has_posts_cache( $probe );

			$account_row  = $resolved_account_id > 0 && class_exists( 'ESF_Twitter_Account_Repository' )
				? ESF_Twitter_Account_Repository::get_instance()->get_by_id( $resolved_account_id )
				: null;
			$disconnected = class_exists( 'ESF_Feed_Disconnected_Cache' )
				? ESF_Feed_Disconnected_Cache::account_source_is_disconnected( $feed_row, $account_row )
				: ( $resolved_account_id > 0 && null === $account_row );
			if ( $disconnected && $has_local_cache && class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
				$cache_pinned = ! empty( $merged['feed']['cache_pinned'] );
				$notice       = array(
					'message' => ESF_Feed_Disconnected_Cache::get_notice_message( $cache_pinned ),
				);
			}
		}

		$renderer = new ESF_Twitter_Renderer();

		$html = $renderer->render( $feed_id, $settings_override, false, $account_id, 'preview' );
		if ( '' === $html ) {
			return new WP_Error(
				'preview_failed',
				__( 'Could not generate preview.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$layout_type = 'timeline';
		if ( $feed_row && ! empty( $feed_row->settings ) ) {
			$saved       = json_decode( $feed_row->settings, true );
			$merged      = ESF_Twitter_Feed_Repository::merge_settings_with_defaults( is_array( $saved ) ? $saved : array() );
			$merged      = array_merge( $merged, $settings_override );
			$layout_type = isset( $merged['layout']['type'] ) ? $merged['layout']['type'] : 'timeline';
		}

		$css_url        = $renderer->get_base_css_url();
		$layout_css_url = $renderer->get_layout_css_url( $layout_type );
		$js_url         = method_exists( $renderer, 'get_layout_js_url' )
			? $renderer->get_layout_js_url( $layout_type )
			: null;

		$js_urls = array();
		if ( 'masonry' === $layout_type && function_exists( 'esf_get_masonry_pack_script_url' ) ) {
			$pack_url = esf_get_masonry_pack_script_url();
			if ( '' !== $pack_url ) {
				$js_urls[] = $pack_url;
			}
		}
		if ( $js_url ) {
			$js_urls[] = $js_url;
		}

		$layout_css_urls = array();
		if (
			function_exists( 'esf_is_feed_entrance_animation_enabled' )
			&& esf_is_feed_entrance_animation_enabled()
			&& function_exists( 'esf_get_feed_reveal_css_url' )
		) {
			$reveal_css = esf_get_feed_reveal_css_url();
			if ( '' !== $reveal_css ) {
				$layout_css_urls[] = $reveal_css;
			}
		}
		if ( $layout_css_url ) {
			$layout_css_urls[] = $layout_css_url;
		}

		return rest_ensure_response(
			array(
				'html'            => $html,
				'css_url'         => $css_url,
				'layout_css_url'  => $layout_css_url,
				'layout_css_urls' => $layout_css_urls,
				'has_local_cache' => $has_local_cache,
				'js_url'          => $js_url ? $js_url : null,
				'js_urls'         => $js_urls,
				'notice'          => $notice,
			)
		);
	}
}
