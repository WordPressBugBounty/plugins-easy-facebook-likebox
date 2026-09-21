<?php
/**
 * YouTube Feeds REST API.
 *
 * Exposes CRUD and duplicate endpoints for YouTube feeds.
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
 * Class ESF_YouTube_API_Feeds
 *
 * @since 6.7.5
 */
class ESF_YouTube_API_Feeds {

	/**
	 * Register REST API routes.
	 *
	 * @since 6.7.5
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/youtube/feeds',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_feeds' ),
				'permission_callback' => array( __CLASS__, 'permissions_check' ),
				'args'                => array(
					'fields'  => array(
						'description'       => __( 'Comma-separated list of fields to return (id, name, account_id, feed_type, source_id, settings, status, created_at, updated_at). Omit for full response.', 'easy-facebook-likebox' ),
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'  => array(
						'description'       => __( 'Filter by status (active, inactive).', 'easy-facebook-likebox' ),
						'type'              => 'string',
						'required'          => false,
						'enum'              => array( 'active', 'inactive' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'orderby' => array(
						'description'       => __( 'Sort column.', 'easy-facebook-likebox' ),
						'type'              => 'string',
						'required'          => false,
						'default'           => 'created_at',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'order'   => array(
						'description' => __( 'Sort order (asc, desc).', 'easy-facebook-likebox' ),
						'type'        => 'string',
						'required'    => false,
						'default'     => 'desc',
						'enum'        => array( 'asc', 'desc' ),
					),
				),
			)
		);

		register_rest_route(
			'esf/v1',
			'/youtube/feeds',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_feed' ),
				'permission_callback' => array( __CLASS__, 'mutate_permissions_check' ),
				'args'                => self::get_feed_schema_for_request(),
			)
		);

		register_rest_route(
			'esf/v1',
			'/youtube/feeds/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_feed' ),
					'permission_callback' => array( __CLASS__, 'permissions_check' ),
					'args'                => array(
						'id'     => array(
							'description'       => __( 'Feed ID.', 'easy-facebook-likebox' ),
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && (int) $param > 0;
							},
						),
						'fields' => array(
							'description'       => __( 'Comma-separated list of fields to return. Omit for full response.', 'easy-facebook-likebox' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_feed' ),
					'permission_callback' => array( __CLASS__, 'mutate_permissions_check' ),
					'args'                => array_merge(
						array(
							'id' => array(
								'description'       => __( 'Feed ID.', 'easy-facebook-likebox' ),
								'type'              => 'integer',
								'required'          => true,
								'validate_callback' => function ( $param ) {
									return is_numeric( $param ) && (int) $param > 0;
								},
							),
						),
						self::get_feed_schema_for_request( true )
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_feed' ),
					'permission_callback' => array( __CLASS__, 'mutate_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description'       => __( 'Feed ID to delete.', 'easy-facebook-likebox' ),
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && (int) $param > 0;
							},
						),
					),
				),
			)
		);

		register_rest_route(
			'esf/v1',
			'/youtube/feeds/(?P<id>[\d]+)/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'duplicate_feed' ),
				'permission_callback' => array( __CLASS__, 'mutate_permissions_check' ),
				'args'                => array(
					'id' => array(
						'description'       => __( 'Feed ID to duplicate.', 'easy-facebook-likebox' ),
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0;
						},
					),
				),
			)
		);

		register_rest_route(
			'esf/v1',
			'/youtube/feeds/(?P<id>[\d]+)/clear-cache',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'clear_feed_cache' ),
				'permission_callback' => array( __CLASS__, 'mutate_permissions_check' ),
				'args'                => array(
					'id' => array(
						'description'       => __( 'Feed ID to clear cache for.', 'easy-facebook-likebox' ),
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0;
						},
					),
				),
			)
		);

		register_rest_route(
			'esf/v1',
			'/youtube/feeds/(?P<id>[\d]+)/pin-cache',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'pin_feed_cache' ),
				'permission_callback' => array( __CLASS__, 'mutate_permissions_check' ),
			)
		);
	}

	/**
	 * Schema for feed create/update request (all optional for update).
	 *
	 * @since 6.7.5
	 *
	 * @param bool $optional_all If true, all args are optional (for update).
	 * @return array
	 */
	private static function get_feed_schema_for_request( $optional_all = false ) {
		$required = $optional_all ? false : true;
		return array(
			'name'       => array(
				'description'       => __( 'Feed name.', 'easy-facebook-likebox' ),
				'type'              => 'string',
				'required'          => $required,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && '' !== trim( $param );
				},
			),
			'account_id' => array(
				'description' => __( 'YouTube account ID.', 'easy-facebook-likebox' ),
				'type'        => 'integer',
				'required'    => $required,
				'minimum'     => 1,
			),
			'feed_type'  => array(
				'description' => __( 'Feed source type.', 'easy-facebook-likebox' ),
				'type'        => 'string',
				'required'    => false,
				'default'     => 'channel',
				'enum'        => array( 'channel', 'playlist', 'search' ),
			),
			'source_id'  => array(
				'description'       => __( 'Playlist ID, search query, or other source identifier.', 'easy-facebook-likebox' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'settings'   => array(
				'description' => __( 'Feed settings (layout, video, header, moderation, style).', 'easy-facebook-likebox' ),
				'type'        => 'object',
				'required'    => false,
			),
			'status'     => array(
				'description' => __( 'Feed status.', 'easy-facebook-likebox' ),
				'type'        => 'string',
				'required'    => false,
				'default'     => 'active',
				'enum'        => array( 'active', 'inactive' ),
			),
		);
	}

	/**
	 * Permission callback for read operations.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function permissions_check( $request ) {
		if ( $request instanceof WP_REST_Request && 'GET' !== $request->get_method() ) {
			return false;
		}

		return esf_youtube_user_can_manage();
	}

	/**
	 * Permission callback for create/update/delete/duplicate.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function mutate_permissions_check( $request ) {
		if ( ! esf_youtube_user_can_manage() ) {
			return false;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );

		return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Get all feeds.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_feeds( $request ) {
		$args = array(
			'status'  => $request->get_param( 'status' ),
			'orderby' => $request->get_param( 'orderby' ),
			'order'   => $request->get_param( 'order' ),
		);

		$repo  = ESF_YouTube_Feed_Repository::get_instance();
		$feeds = $repo->get_all( $args );

		$fields_param   = $request->get_param( 'fields' );
		$allowed_keys   = array( 'id', 'name', 'account_id', 'feed_type', 'source_id', 'settings', 'status', 'created_at', 'updated_at' );
		$requested_keys = null;
		if ( is_string( $fields_param ) && '' !== trim( $fields_param ) ) {
			$requested      = array_map( 'trim', explode( ',', $fields_param ) );
			$requested_keys = array_intersect( $allowed_keys, $requested );
			if ( empty( $requested_keys ) ) {
				$requested_keys = null;
			}
		}

		$items = array();
		foreach ( $feeds as $feed ) {
			$item    = self::format_feed_for_response( $feed, $requested_keys );
			$items[] = $item;
		}

		return rest_ensure_response( $items );
	}

	/**
	 * Get a single feed by ID.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_feed( $request ) {
		$feed_id = (int) $request['id'];
		$repo    = ESF_YouTube_Feed_Repository::get_instance();
		$feed    = $repo->get_by_id( $feed_id );

		if ( ! $feed ) {
			return new WP_Error(
				'feed_not_found',
				__( 'Feed not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$allowed_keys   = array( 'id', 'name', 'account_id', 'feed_type', 'source_id', 'settings', 'status', 'created_at', 'updated_at' );
		$requested_keys = null;
		$fields_param   = $request->get_param( 'fields' );
		if ( is_string( $fields_param ) && '' !== trim( $fields_param ) ) {
			$requested      = array_map( 'trim', explode( ',', $fields_param ) );
			$requested_keys = array_values( array_intersect( $allowed_keys, $requested ) );
			if ( empty( $requested_keys ) ) {
				$requested_keys = null;
			}
		}

		return rest_ensure_response( self::format_feed_for_response( $feed, $requested_keys ) );
	}

	/**
	 * Create a new feed.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_feed( $request ) {
		$account_id   = (int) $request['account_id'];
		$account_repo = ESF_YouTube_Account_Repository::get_instance();
		if ( ! $account_repo->get_by_id( $account_id ) ) {
			return new WP_Error(
				'invalid_account',
				__( 'Selected account not found.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$feed_type = $request->get_param( 'feed_type' );
		$feed_type = ( '' !== (string) $feed_type ) ? $feed_type : 'channel';
		$source_id = $request->get_param( 'source_id' );
		$validated = self::validate_pro_feed_type_payload( $feed_type, $source_id );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( is_array( $validated ) ) {
			$feed_type = $validated['feed_type'];
			$source_id = $validated['source_id'];
		}

		$status = $request->get_param( 'status' );
		$status = ( '' !== (string) $status ) ? $status : 'active';

		$data = array(
			'name'       => trim( (string) $request['name'] ),
			'account_id' => $account_id,
			'feed_type'  => $feed_type,
			'source_id'  => $source_id,
			'settings'   => $request->get_param( 'settings' ),
			'status'     => $status,
		);

		$repo = ESF_YouTube_Feed_Repository::get_instance();
		$id   = $repo->create( $data );

		if ( false === $id ) {
			return new WP_Error(
				'create_failed',
				__( 'Failed to create feed.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$feed = $repo->get_by_id( $id );

		if ( function_exists( 'esf_review_request_record_milestone' ) ) {
			esf_review_request_record_milestone( 'feed_saved', 'youtube' );
		}

		return rest_ensure_response( self::format_feed_for_response( $feed ), 201 );
	}

	/**
	 * Update an existing feed.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_feed( $request ) {
		$feed_id = (int) $request['id'];
		$repo    = ESF_YouTube_Feed_Repository::get_instance();
		$feed    = $repo->get_by_id( $feed_id );

		if ( ! $feed ) {
			return new WP_Error(
				'feed_not_found',
				__( 'Feed not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$data = array();
		if ( $request->has_param( 'name' ) ) {
			$data['name'] = trim( (string) $request['name'] );
		}
		if ( $request->has_param( 'account_id' ) ) {
			$account_id = (int) $request['account_id'];
			if ( $account_id > 0 ) {
				$account_repo = ESF_YouTube_Account_Repository::get_instance();
				if ( ! $account_repo->get_by_id( $account_id ) ) {
					return new WP_Error(
						'invalid_account',
						__( 'Selected account not found.', 'easy-facebook-likebox' ),
						array( 'status' => 400 )
					);
				}
				$data['account_id'] = $account_id;
			}
		}
		if ( $request->has_param( 'feed_type' ) ) {
			$data['feed_type'] = $request['feed_type'];
		}
		if ( $request->has_param( 'source_id' ) ) {
			$data['source_id'] = $request['source_id'];
		}
		if ( $request->has_param( 'settings' ) ) {
			$incoming_settings = $request['settings'];
			if ( is_array( $incoming_settings ) ) {
				$current_settings = self::decode_feed_settings( $feed );
				$data['settings'] = self::preserve_rest_managed_settings( $current_settings, $incoming_settings );
			}
		}
		if ( $request->has_param( 'status' ) ) {
			$data['status'] = $request['status'];
		}

		$next_feed_type = array_key_exists( 'feed_type', $data )
			? $data['feed_type']
			: ( isset( $feed->feed_type ) ? $feed->feed_type : 'channel' );
		$next_source_id = array_key_exists( 'source_id', $data )
			? $data['source_id']
			: ( isset( $feed->source_id ) ? $feed->source_id : '' );

		$validated = self::validate_pro_feed_type_payload( $next_feed_type, $next_source_id );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( is_array( $validated ) ) {
			$data['feed_type'] = $validated['feed_type'];
			$data['source_id'] = $validated['source_id'];
		}

		$source_changed = (
			( array_key_exists( 'feed_type', $data ) && (string) ( isset( $feed->feed_type ) ? $feed->feed_type : '' ) !== (string) $data['feed_type'] )
			|| ( array_key_exists( 'source_id', $data ) && (string) ( isset( $feed->source_id ) ? $feed->source_id : '' ) !== (string) $data['source_id'] )
			|| ( array_key_exists( 'account_id', $data ) && (int) ( isset( $feed->account_id ) ? $feed->account_id : 0 ) !== (int) $data['account_id'] )
		);

		// Clear the previous shared source cache before the row changes.
		if ( $source_changed && class_exists( 'ESF_YouTube_Cache' ) ) {
			ESF_YouTube_Cache::flush_videos_cache_for_source(
				isset( $feed->account_id ) ? (int) $feed->account_id : 0,
				isset( $feed->feed_type ) ? (string) $feed->feed_type : 'channel',
				isset( $feed->source_id ) ? (string) $feed->source_id : '',
				$feed_id
			);
		}

		$updated = $repo->update( $feed_id, $data );
		if ( ! $updated ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update feed.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$feed = $repo->get_by_id( $feed_id );

		return rest_ensure_response( self::format_feed_for_response( $feed ) );
	}

	/**
	 * Delete a feed.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_feed( $request ) {
		$feed_id = (int) $request['id'];
		$repo    = ESF_YouTube_Feed_Repository::get_instance();
		$feed    = $repo->get_by_id( $feed_id );

		if ( ! $feed ) {
			return new WP_Error(
				'feed_not_found',
				__( 'Feed not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$deleted = $repo->delete( $feed_id );
		if ( ! $deleted ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete feed.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Feed deleted successfully.', 'easy-facebook-likebox' ),
			)
		);
	}

	/**
	 * Duplicate a feed.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function duplicate_feed( $request ) {
		$feed_id = (int) $request['id'];
		$repo    = ESF_YouTube_Feed_Repository::get_instance();
		$feed    = $repo->get_by_id( $feed_id );

		if ( ! $feed ) {
			return new WP_Error(
				'feed_not_found',
				__( 'Feed not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$feed_type = isset( $feed->feed_type ) ? (string) $feed->feed_type : 'channel';
		if ( function_exists( 'esf_youtube_is_pro_feed_type' ) && esf_youtube_is_pro_feed_type( $feed_type ) ) {
			if ( ! function_exists( 'esf_youtube_has_youtube_plan' ) || ! esf_youtube_has_youtube_plan() ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'Playlist and search feeds are premium features.', 'easy-facebook-likebox' ),
					array( 'status' => 403 )
				);
			}
		}

		$new_id = $repo->duplicate( $feed_id );
		if ( false === $new_id ) {
			return new WP_Error(
				'duplicate_failed',
				__( 'Failed to duplicate feed.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$new_feed = $repo->get_by_id( $new_id );

		return rest_ensure_response( self::format_feed_for_response( $new_feed ), 201 );
	}

	/**
	 * Clear cache for a single feed.
	 *
	 * @since 6.7.5
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function clear_feed_cache( $request ) {
		$feed_id = (int) $request['id'];
		$repo    = ESF_YouTube_Feed_Repository::get_instance();
		if ( ! $repo->get_by_id( $feed_id ) ) {
			return new WP_Error(
				'feed_not_found',
				__( 'Feed not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$deleted = ESF_YouTube_Cache::flush_feed_cache( $feed_id );

		return rest_ensure_response(
			array(
				'success' => ( false !== $deleted ),
				'deleted' => false !== $deleted ? (int) $deleted : 0,
			)
		);
	}

	/**
	 * Pin a disconnected feed's cached videos permanently (no TTL expiry).
	 *
	 * @since 6.7.9
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pin_feed_cache( $request ) {
		$id   = (int) $request['id'];
		$feed = ESF_YouTube_Feed_Repository::get_instance()->get_by_id( $id );
		if ( ! $feed ) {
			return new WP_Error(
				'feed_not_found',
				__( 'Feed not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		if ( empty( $feed->account_id ) ) {
			return new WP_Error(
				'no_account',
				__( 'This feed has no linked account to pin cache for.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$pinned = class_exists( 'ESF_Feed_Disconnected_Cache' )
			? ESF_Feed_Disconnected_Cache::pin_feed_cache_for_module( 'youtube', $id )
			: ( class_exists( 'ESF_YouTube_Cache' ) && false !== ESF_YouTube_Cache::pin_feed_cache( $id ) );

		if ( ! $pinned ) {
			return new WP_Error(
				'pin_failed',
				__( 'Could not pin feed cache.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$updated = ESF_YouTube_Feed_Repository::get_instance()->get_by_id( $id );

		return rest_ensure_response(
			array(
				'pinned' => true,
				'feed'   => $updated ? self::format_feed_for_response( $updated ) : null,
			)
		);
	}

	/**
	 * Validate and normalize playlist/search feed payloads (Pro-gated).
	 *
	 * Channel feeds pass through with an empty source_id.
	 *
	 * @since 6.7.6
	 *
	 * @param mixed $feed_type Raw feed type.
	 * @param mixed $source_id Raw source id / query.
	 * @return array{feed_type:string,source_id:string}|WP_Error
	 */
	private static function validate_pro_feed_type_payload( $feed_type, $source_id ) {
		$feed_type = sanitize_key( (string) $feed_type );
		if ( '' === $feed_type ) {
			$feed_type = 'channel';
		}

		if ( 'channel' === $feed_type ) {
			return array(
				'feed_type' => 'channel',
				'source_id' => '',
			);
		}

		if ( ! function_exists( 'esf_youtube_is_pro_feed_type' ) || ! esf_youtube_is_pro_feed_type( $feed_type ) ) {
			return array(
				'feed_type' => 'channel',
				'source_id' => '',
			);
		}

		if ( ! function_exists( 'esf_youtube_has_youtube_plan' ) || ! esf_youtube_has_youtube_plan() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Playlist and search feeds are premium features.', 'easy-facebook-likebox' ),
				array( 'status' => 403 )
			);
		}

		if ( 'playlist' === $feed_type ) {
			$normalized = function_exists( 'esf_youtube_normalize_playlist_id' )
				? esf_youtube_normalize_playlist_id( $source_id )
				: sanitize_text_field( (string) $source_id );

			return array(
				'feed_type' => 'playlist',
				'source_id' => $normalized,
			);
		}

		$normalized = function_exists( 'esf_youtube_normalize_search_query' )
			? esf_youtube_normalize_search_query( $source_id )
			: sanitize_text_field( (string) $source_id );

		return array(
			'feed_type' => 'search',
			'source_id' => $normalized,
		);
	}

	/**
	 * Decode stored feed settings for internal update logic.
	 *
	 * @since 6.7.9
	 *
	 * @param object $feed Feed DB row.
	 * @return array<string,mixed>
	 */
	private static function decode_feed_settings( $feed ) {
		$decoded = json_decode( isset( $feed->settings ) ? (string) $feed->settings : '', true );
		if ( ! is_array( $decoded ) ) {
			return ESF_YouTube_Feed_Repository::get_default_settings();
		}

		return ESF_YouTube_Feed_Repository::merge_settings_with_defaults( $decoded );
	}

	/**
	 * Preserve settings owned by dedicated REST controllers during feed autosave.
	 *
	 * Moderation is written by the shared moderation REST controller; feed
	 * editor autosave must not overwrite it with stale React state.
	 *
	 * @since 6.7.9
	 *
	 * @param array<string,mixed> $current_settings  Stored feed settings.
	 * @param array<string,mixed> $incoming_settings Incoming settings payload.
	 * @return array<string,mixed>
	 */
	private static function preserve_rest_managed_settings( array $current_settings, array $incoming_settings ) {
		/**
		 * Settings keys owned by dedicated REST controllers (not feed autosave).
		 *
		 * @since 6.7.9
		 *
		 * @param array<int,string> $keys Keys to preserve from the stored feed.
		 */
		$managed_keys = apply_filters(
			'esf_feed_settings_rest_managed_keys',
			array( 'moderation' )
		);

		if ( ! is_array( $managed_keys ) ) {
			return $incoming_settings;
		}

		foreach ( $managed_keys as $managed_key ) {
			$managed_key = sanitize_key( (string) $managed_key );
			if ( '' === $managed_key ) {
				continue;
			}
			if ( isset( $current_settings[ $managed_key ] ) && is_array( $current_settings[ $managed_key ] ) ) {
				$incoming_settings[ $managed_key ] = $current_settings[ $managed_key ];
			}
		}

		return $incoming_settings;
	}

	/**
	 * Format a feed row for REST response (decode settings JSON only when included).
	 *
	 * @since 6.7.5
	 *
	 * @param object     $feed Feed row from database.
	 * @param array|null $keys Optional list of keys to include (id, name, account_id, etc.). Null = all.
	 * @return array
	 */
	private static function format_feed_for_response( $feed, $keys = null ) {
		$settings = json_decode( $feed->settings, true );
		if ( ! is_array( $settings ) ) {
			$settings = ESF_YouTube_Feed_Repository::get_default_settings();
		}

		$preview_is_cold = function_exists( 'esf_youtube_feed_preview_is_cold' )
			? esf_youtube_feed_preview_is_cold(
				(int) $feed->account_id,
				(string) $feed->feed_type,
				null !== $feed->source_id ? (string) $feed->source_id : '',
				$settings
			)
			: true;

		$full = array(
			'id'              => (int) $feed->id,
			'name'            => $feed->name,
			'account_id'      => (int) $feed->account_id,
			'feed_type'       => $feed->feed_type,
			'source_id'       => $feed->source_id,
			'settings'        => $settings,
			'status'          => $feed->status,
			'created_at'      => $feed->created_at,
			'updated_at'      => $feed->updated_at,
			'preview_is_cold' => (bool) $preview_is_cold,
		);

		if ( null !== $keys ) {
			$out = array();
			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, $full ) ) {
					$out[ $key ] = $full[ $key ];
				}
			}
			return $out;
		}

		return $full;
	}
}
