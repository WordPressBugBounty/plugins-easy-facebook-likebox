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
		$status    = $request->get_param( 'status' );
		$status    = ( '' !== (string) $status ) ? $status : 'active';

		$data = array(
			'name'       => trim( (string) $request['name'] ),
			'account_id' => $account_id,
			'feed_type'  => $feed_type,
			'source_id'  => $request->get_param( 'source_id' ),
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
			$data['settings'] = $request['settings'];
		}
		if ( $request->has_param( 'status' ) ) {
			$data['status'] = $request['status'];
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
	 * Format a feed row for REST response (decode settings JSON only when included).
	 *
	 * @since 6.7.5
	 *
	 * @param object     $feed Feed row from database.
	 * @param array|null $keys Optional list of keys to include (id, name, account_id, etc.). Null = all.
	 * @return array
	 */
	private static function format_feed_for_response( $feed, $keys = null ) {
		$full = array(
			'id'         => (int) $feed->id,
			'name'       => $feed->name,
			'account_id' => (int) $feed->account_id,
			'feed_type'  => $feed->feed_type,
			'source_id'  => $feed->source_id,
			'settings'   => null, // Filled below only when needed.
			'status'     => $feed->status,
			'created_at' => $feed->created_at,
			'updated_at' => $feed->updated_at,
		);

		if ( null !== $keys ) {
			$out = array();
			foreach ( $keys as $key ) {
				if ( 'settings' === $key ) {
					$settings    = json_decode( $feed->settings, true );
					$out[ $key ] = is_array( $settings ) ? $settings : ESF_YouTube_Feed_Repository::get_default_settings();
				} else {
					$out[ $key ] = $full[ $key ];
				}
			}
			return $out;
		}

		$settings         = json_decode( $feed->settings, true );
		$full['settings'] = is_array( $settings ) ? $settings : ESF_YouTube_Feed_Repository::get_default_settings();
		return $full;
	}
}
