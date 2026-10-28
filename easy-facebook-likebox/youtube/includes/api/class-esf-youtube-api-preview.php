<?php
/**
 * YouTube Feed Preview REST API.
 *
 * Returns rendered HTML (and asset URLs) for dashboard live preview.
 *
 * Settings overrides are accepted in the POST body (not the query string) so
 * large feed configs do not hit nginx/Apache "414 Request-URI Too Large".
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
		$args = array(
			'id'         => array(
				'description'       => __( 'Feed ID.', 'easy-facebook-likebox' ),
				'type'              => 'integer',
				'required'          => true,
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && (int) $param > 0;
				},
			),
			'settings'   => array(
				'description'       => __( 'Optional settings override for live preview (prefer POST body).', 'easy-facebook-likebox' ),
				'required'          => false,
				'validate_callback' => array( __CLASS__, 'validate_settings_param' ),
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings_param' ),
			),
			'feed_type'  => array(
				'description'       => __( 'Optional feed type override for live preview.', 'easy-facebook-likebox' ),
				'type'              => 'string',
				'required'          => false,
				'enum'              => array( 'channel', 'playlist', 'search' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'source_id'  => array(
				'description'       => __( 'Optional source id / search query override for live preview.', 'easy-facebook-likebox' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'account_id' => array(
				'description'       => __( 'Optional account id override for live preview.', 'easy-facebook-likebox' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			'esf/v1',
			'/youtube/feeds/(?P<id>[\d]+)/preview',
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

		$feed_overrides = array();
		$feed_type      = sanitize_key( (string) $request->get_param( 'feed_type' ) );
		if ( in_array( $feed_type, array( 'channel', 'playlist', 'search' ), true ) ) {
			$feed_overrides['feed_type'] = $feed_type;
		}
		if ( null !== $request->get_param( 'source_id' ) ) {
			$feed_overrides['source_id'] = (string) $request->get_param( 'source_id' );
		}
		$account_id = (int) $request->get_param( 'account_id' );
		if ( $account_id > 0 ) {
			$feed_overrides['account_id'] = $account_id;
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

		$html = $renderer->render( $feed_id, $settings_override, false, $feed_overrides );
		if ( '' === $html ) {
			return new WP_Error(
				'preview_failed',
				__( 'Could not generate preview.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$feed_repo = ESF_YouTube_Feed_Repository::get_instance();
		$feed_row  = $feed_repo->get_by_id( $feed_id );
		$saved     = array();
		if ( $feed_row && ! empty( $feed_row->settings ) ) {
			$decoded = json_decode( (string) $feed_row->settings, true );
			$saved   = is_array( $decoded ) ? $decoded : array();
		}
		$merged      = ESF_YouTube_Feed_Repository::merge_settings_with_defaults( $saved );
		$merged      = array_merge( $merged, is_array( $settings_override ) ? $settings_override : array() );
		$layout_type = isset( $merged['layout']['type'] ) ? (string) $merged['layout']['type'] : 'grid';

		$css_url        = $renderer->get_base_css_url();
		$layout_css_url = $renderer->get_layout_css_url( $layout_type );
		$js_url         = method_exists( $renderer, 'get_layout_js_url' )
			? $renderer->get_layout_js_url( $layout_type )
			: null;

		$js_urls = array();
		$caption_js_path = ESF_YOUTUBE_DIR . 'frontend/assets/js/esf-youtube-caption.js';
		if ( file_exists( $caption_js_path ) ) {
			$js_urls[] = ESF_YOUTUBE_URL . 'frontend/assets/js/esf-youtube-caption.js';
		}
		if ( 'masonry' === $layout_type && function_exists( 'esf_get_masonry_pack_script_url' ) ) {
			$pack_url = esf_get_masonry_pack_script_url();
			if ( '' !== $pack_url ) {
				$js_urls[] = $pack_url;
			}
		}
		if ( is_string( $js_url ) && '' !== $js_url ) {
			$js_urls[] = $js_url;
		}

		$playback_mode = function_exists( 'esf_youtube_resolve_playback_mode' )
			? esf_youtube_resolve_playback_mode( $merged, $layout_type )
			: 'new_tab';

		if ( 'showcase' === $playback_mode && method_exists( $renderer, 'get_showcase_js_url' ) ) {
			$showcase_js = $renderer->get_showcase_js_url();
			if ( is_string( $showcase_js ) && '' !== $showcase_js ) {
				$js_urls[] = $showcase_js;
			}
		}

		$payload = array(
			'html'           => $html,
			'css_url'        => $css_url,
			'layout_css_url' => $layout_css_url,
			'js_url'         => '' !== $js_url ? $js_url : null,
			'js_urls'        => $js_urls,
		);

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
		if ( is_string( $layout_css_url ) && '' !== $layout_css_url ) {
			$layout_css_urls[] = $layout_css_url;
		}
		if ( 'showcase' === $playback_mode && method_exists( $renderer, 'get_showcase_css_url' ) ) {
			$showcase_css = $renderer->get_showcase_css_url();
			if ( is_string( $showcase_css ) && '' !== $showcase_css ) {
				$layout_css_urls[] = $showcase_css;
			}
		}
		if ( ! empty( $layout_css_urls ) ) {
			$payload['layout_css_urls'] = array_values( array_unique( $layout_css_urls ) );
		}

		if ( method_exists( $renderer, 'get_last_search_total_results' ) ) {
			$search_total = $renderer->get_last_search_total_results();
			if ( null !== $search_total ) {
				$payload['search_total_results'] = (int) $search_total;
			}
		}

		if ( $feed_row && class_exists( 'ESF_YouTube_Renderer' ) ) {
			$saved                      = json_decode( $feed_row->settings, true );
			$merged                     = ESF_YouTube_Feed_Repository::merge_settings_with_defaults( is_array( $saved ) ? $saved : array() );
			$merged                     = array_merge( $merged, $settings_override );
			$override_account_id        = isset( $feed_overrides['account_id'] ) ? (int) $feed_overrides['account_id'] : 0;
			$account_id                 = $override_account_id > 0 ? $override_account_id : (int) $feed_row->account_id;
			$feed_type                  = isset( $feed_overrides['feed_type'] ) ? (string) $feed_overrides['feed_type'] : (string) $feed_row->feed_type;
			$source_id                  = array_key_exists( 'source_id', $feed_overrides )
				? (string) $feed_overrides['source_id']
				: (string) $feed_row->source_id;
			$probe                      = (object) array(
				'id'         => (int) $feed_row->id,
				'account_id' => $account_id,
				'feed_type'  => $feed_type,
				'source_id'  => $source_id,
				'settings'   => $merged,
			);
			$payload['has_local_cache'] = ESF_YouTube_Renderer::feed_has_videos_cache( $probe );

			$account_row  = $account_id > 0 && class_exists( 'ESF_YouTube_Account_Repository' )
				? ESF_YouTube_Account_Repository::get_instance()->get_by_id( $account_id )
				: null;
			$disconnected = class_exists( 'ESF_Feed_Disconnected_Cache' )
				? ESF_Feed_Disconnected_Cache::account_source_is_disconnected( $feed_row, $account_row )
				: ( $account_id > 0 && null === $account_row );
			if ( $disconnected && ! empty( $payload['has_local_cache'] ) && class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
				$cache_pinned      = ! empty( $merged['feed']['cache_pinned'] );
				$payload['notice'] = array(
					'message' => ESF_Feed_Disconnected_Cache::get_notice_message( $cache_pinned ),
				);
			}
		}

		return rest_ensure_response( $payload );
	}
}
