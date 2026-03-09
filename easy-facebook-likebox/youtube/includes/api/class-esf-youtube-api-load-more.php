<?php
/**
 * YouTube Feed Load More REST API.
 *
 * Returns next batch of video cards HTML for premium Load More.
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
 * Class ESF_YouTube_API_Load_More
 *
 * @since 6.7.5
 */
class ESF_YouTube_API_Load_More {

	/**
	 * Register REST route for feed load more.
	 *
	 * @since 6.7.5
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/youtube/feeds/(?P<id>[\d]+)/load-more',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_load_more' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'     => array(
						'description'       => __( 'Feed ID.', 'easy-facebook-likebox' ),
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0;
						},
					),
					'offset' => array(
						'description'       => __( 'Number of videos already displayed.', 'easy-facebook-likebox' ),
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'minimum'           => 0,
						'maximum'           => 500,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Get load more HTML and pagination state.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_load_more( $request ) {
		if ( ! function_exists( 'esf_youtube_has_youtube_plan' ) || ! esf_youtube_has_youtube_plan() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Load More is a premium feature.', 'easy-facebook-likebox' ),
				array( 'status' => 403 )
			);
		}

		$feed_id = (int) $request['id'];
		$offset  = (int) $request['offset'];

		$feed_repo = ESF_YouTube_Feed_Repository::get_instance();
		$feed_row  = $feed_repo->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return new WP_Error(
				'feed_not_found',
				__( 'Feed not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$saved_settings = json_decode( $feed_row->settings, true );
		$settings       = ESF_YouTube_Feed_Repository::merge_settings_with_defaults( is_array( $saved_settings ) ? $saved_settings : array() );
		$feed_obj       = (object) array(
			'id'         => $feed_row->id,
			'name'       => $feed_row->name,
			'account_id' => (int) $feed_row->account_id,
			'feed_type'  => $feed_row->feed_type,
			'source_id'  => $feed_row->source_id,
			'settings'   => $settings,
		);

		$account_repo = ESF_YouTube_Account_Repository::get_instance();
		$account      = $account_repo->get_by_id( $feed_obj->account_id );
		if ( ! $account ) {
			return new WP_Error(
				'account_not_found',
				__( 'Account not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		if ( ! class_exists( 'ESF_YouTube_Frontend' ) ) {
			return new WP_Error(
				'frontend_unavailable',
				__( 'Frontend not loaded.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$renderer   = ESF_YouTube_Frontend::get_instance()->get_renderer();
		$all_videos = $renderer->get_videos_for_feed_by_id( $feed_id );
		$filtered   = esf_youtube_apply_moderation_filter( $all_videos, $feed_id );
		$total      = count( $filtered );

		$feed_settings = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();
		$per_page      = isset( $feed_settings['per_page'] ) ? max( 1, min( 50, (int) $feed_settings['per_page'] ) ) : 12;

		$slice    = array_slice( $filtered, $offset, $per_page );
		$count    = count( $slice );
		$next     = $offset + $count;
		$has_more = $next < $total;

		$layout_type = isset( $settings['layout']['type'] ) ? $settings['layout']['type'] : 'grid';
		$layout      = ESF_YouTube_Layout_Registry::make( $layout_type, $feed_obj, array(), $account );
		$html        = $layout->render_cards_for_videos( $slice );

		return rest_ensure_response(
			array(
				'html'        => $html,
				'has_more'    => $has_more,
				'next_offset' => $has_more ? $next : $offset + $count,
			)
		);
	}
}
