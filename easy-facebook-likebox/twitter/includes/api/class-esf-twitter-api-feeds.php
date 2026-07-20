<?php
/**
 * Twitter Feeds REST API
 *
 * CRUD endpoints for managing Twitter feed configurations.
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
 * Class ESF_Twitter_API_Feeds
 *
 * @since 6.7.6
 */
class ESF_Twitter_API_Feeds {

	/**
	 * Register REST API routes.
	 *
	 * @since 6.7.6
	 * @return void
	 */
	public static function register_routes() {
		// Collection.
		register_rest_route(
			'esf/v1',
			'/twitter/feeds',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_feeds' ),
					'permission_callback' => array( __CLASS__, 'read_permissions_check' ),
					'args'                => array(
						'fields'  => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'  => array(
							'type'              => 'string',
							'required'          => false,
							'enum'              => array( 'active', 'inactive' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'orderby' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'created_at',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'order'   => array(
							'type'     => 'string',
							'required' => false,
							'default'  => 'desc',
							'enum'     => array( 'asc', 'desc' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_feed' ),
					'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
					'args'                => self::get_create_args(),
				),
			)
		);

		// Single feed.
		register_rest_route(
			'esf/v1',
			'/twitter/feeds/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_feed' ),
					'permission_callback' => array( __CLASS__, 'read_permissions_check' ),
					'args'                => array(
						'id'     => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && (int) $param > 0;
							},
						),
						'fields' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => 'PUT,PATCH',
					'callback'            => array( __CLASS__, 'update_feed' ),
					'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_feed' ),
					'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
				),
			)
		);

		// Duplicate.
		register_rest_route(
			'esf/v1',
			'/twitter/feeds/(?P<id>[\d]+)/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'duplicate_feed' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
			)
		);

		// Flush cache (legacy route kept for backward compatibility).
		register_rest_route(
			'esf/v1',
			'/twitter/feeds/(?P<id>[\d]+)/flush-cache',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'flush_feed_cache' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
			)
		);

		// Clear cache (preferred route).
		register_rest_route(
			'esf/v1',
			'/twitter/feeds/(?P<id>[\d]+)/clear-cache',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'clear_feed_cache' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
			)
		);
	}

	/**
	 * Read permission callback.
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function read_permissions_check( $request ) {
		if ( $request instanceof WP_REST_Request && 'GET' !== $request->get_method() ) {
			return false;
		}

		return esf_twitter_user_can_manage();
	}

	/**
	 * Write permission callback (nonce + capability).
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function write_permissions_check( $request ) {
		if ( ! esf_twitter_user_can_manage() ) {
			return false;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );

		return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * GET /esf/v1/twitter/feeds
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_feeds( $request ) {
		$args = array(
			'status'  => $request->get_param( 'status' ),
			'orderby' => $request->get_param( 'orderby' ),
			'order'   => $request->get_param( 'order' ),
		);

		$repo  = ESF_Twitter_Feed_Repository::get_instance();
		$feeds = $repo->get_all( $args );

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

		$items = array();
		foreach ( $feeds as $feed ) {
			$items[] = self::format_feed_for_response( $feed, $requested_keys );
		}

		return rest_ensure_response( $items );
	}

	/**
	 * GET /esf/v1/twitter/feeds/:id
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_feed( $request ) {
		$feed_id = (int) $request['id'];
		$repo    = ESF_Twitter_Feed_Repository::get_instance();
		$feed    = $repo->get_by_id( $feed_id );

		if ( ! $feed ) {
			return new WP_Error( 'not_found', __( 'Feed not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
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
	 * POST /esf/v1/twitter/feeds
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_feed( $request ) {
		$account_id   = (int) $request->get_param( 'account_id' );
		$account_repo = ESF_Twitter_Account_Repository::get_instance();
		$account      = $account_repo->get_by_id( $account_id );
		if ( ! $account ) {
			return new WP_Error( 'invalid_account', __( 'Selected account not found.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			$name = ESF_Feed_Name::build_auto_feed_name_for_source(
				$account,
				sanitize_text_field( (string) $request->get_param( 'feed_type' ) ),
				self::resolve_source_id_for_create( $request->get_param( 'source_id' ), $account ),
				self::get_feed_name_config()
			);
		}

		$settings = $request->get_param( 'settings' );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings = ESF_Feed_Name::set_name_meta( $settings, 'auto', $account_id );

		$data = array(
			'name'       => $name,
			'account_id' => $account_id,
			'feed_type'  => sanitize_text_field( (string) $request->get_param( 'feed_type' ) ),
			'source_id'  => self::resolve_source_id_for_create( $request->get_param( 'source_id' ), $account ),
			'settings'   => $settings,
			'status'     => sanitize_text_field( (string) $request->get_param( 'status' ) ),
		);

		$repo    = ESF_Twitter_Feed_Repository::get_instance();
		$feed_id = $repo->create( $data );

		if ( false === $feed_id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create feed.', 'easy-facebook-likebox' ), array( 'status' => 500 ) );
		}

		$feed = $repo->get_by_id( $feed_id );

		if ( function_exists( 'esf_review_request_record_milestone' ) ) {
			esf_review_request_record_milestone( 'feed_saved', 'twitter' );
		}

		return new WP_REST_Response( self::format_feed_for_response( $feed ), 201 );
	}

	/**
	 * PUT/PATCH /esf/v1/twitter/feeds/:id
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_feed( $request ) {
		$feed_id = (int) $request['id'];
		$repo    = ESF_Twitter_Feed_Repository::get_instance();
		$feed    = $repo->get_by_id( $feed_id );

		if ( ! $feed ) {
			return new WP_Error( 'not_found', __( 'Feed not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
		}

		$data                = array();
		$current_settings    = self::decode_feed_settings( $feed );
		$next_settings       = $current_settings;
		$name_mode           = ESF_Feed_Name::get_name_mode( $current_settings );
		$current_name        = isset( $feed->name ) ? sanitize_text_field( (string) $feed->name ) : '';
		$current_account     = isset( $feed->account_id ) ? (int) $feed->account_id : 0;
		$current_feed_type   = isset( $feed->feed_type ) ? (string) $feed->feed_type : 'user_timeline';
		$current_source_id   = isset( $feed->source_id ) ? (string) $feed->source_id : '';
		$next_account        = $current_account;
		$next_feed_type      = $current_feed_type;
		$next_source_id      = $current_source_id;
		$account_changed     = false;
		$feed_type_changed   = false;
		$source_id_changed   = false;
		$name_updated        = false;

		foreach ( array( 'name', 'account_id', 'feed_type', 'source_id', 'status', 'settings' ) as $key ) {
			if ( null === $request->get_param( $key ) ) {
				continue;
			}

			if ( 'settings' === $key ) {
				$incoming_settings = $request->get_param( $key );
				if ( is_array( $incoming_settings ) ) {
					$next_settings = $incoming_settings;
					$data[ $key ]  = $incoming_settings;
					$name_mode     = ESF_Feed_Name::get_name_mode( $incoming_settings );
				}
				continue;
			}

			if ( 'account_id' === $key ) {
				$account_id   = (int) $request->get_param( $key );
				$account_repo = ESF_Twitter_Account_Repository::get_instance();
				if ( $account_id > 0 && ! $account_repo->get_by_id( $account_id ) ) {
					return new WP_Error( 'invalid_account', __( 'Selected account not found.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
				}
				$data[ $key ]      = $account_id;
				$next_account      = $account_id;
				$account_changed   = ( $current_account !== $next_account );
				continue;
			}

			$value = sanitize_text_field( (string) $request->get_param( $key ) );
			if ( 'feed_type' === $key ) {
				$next_feed_type    = $value;
				$feed_type_changed = ( $current_feed_type !== $value );
			}
			if ( 'source_id' === $key ) {
				$next_source_id    = $value;
				$source_id_changed = ( $current_source_id !== $value );
			}
			$data[ $key ] = $value;
			if ( 'name' === $key ) {
				$name_updated = ( $value !== $current_name );
			}
		}

		$source_context_changed = $account_changed || $feed_type_changed || $source_id_changed;

		if ( ESF_Feed_Name::should_switch_to_manual_mode( $name_updated, $name_mode, $source_context_changed ) ) {
			$next_settings    = ESF_Feed_Name::set_name_meta( $next_settings, 'manual', $next_account );
			$data['settings'] = $next_settings;
			$name_mode        = 'manual';
		}

		$account_repo = ESF_Twitter_Account_Repository::get_instance();
		$account      = $account_repo->get_by_id( $next_account );
		if ( ! $account ) {
			return new WP_Error( 'invalid_account', __( 'Selected account not found.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		ESF_Feed_Name::apply_auto_name_on_source_change(
			$data,
			$next_settings,
			$name_mode,
			$account,
			$next_feed_type,
			$next_source_id,
			$next_account,
			array_merge(
				self::get_feed_name_config(),
				array(
					'source_context_changed' => $source_context_changed,
				)
			)
		);

		$updated = $repo->update( $feed_id, $data );
		if ( ! $updated ) {
			return new WP_Error( 'update_failed', __( 'Failed to update feed.', 'easy-facebook-likebox' ), array( 'status' => 500 ) );
		}

		// Invalidate cache so the next render fetches fresh tweets.
		ESF_Twitter_Cache::flush_feed_cache( $feed_id );

		return rest_ensure_response( self::format_feed_for_response( $repo->get_by_id( $feed_id ) ) );
	}

	/**
	 * DELETE /esf/v1/twitter/feeds/:id
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_feed( $request ) {
		$feed_id = (int) $request['id'];
		$repo    = ESF_Twitter_Feed_Repository::get_instance();

		if ( ! $repo->get_by_id( $feed_id ) ) {
			return new WP_Error( 'not_found', __( 'Feed not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
		}

		if ( ! $repo->delete( $feed_id ) ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete feed.', 'easy-facebook-likebox' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'id'      => $feed_id,
			)
		);
	}

	/**
	 * POST /esf/v1/twitter/feeds/:id/duplicate
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function duplicate_feed( $request ) {
		$feed_id = (int) $request['id'];
		$repo    = ESF_Twitter_Feed_Repository::get_instance();
		if ( ! $repo->get_by_id( $feed_id ) ) {
			return new WP_Error( 'not_found', __( 'Feed not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
		}

		$new_id = $repo->duplicate( $feed_id );
		if ( false === $new_id ) {
			return new WP_Error( 'duplicate_failed', __( 'Failed to duplicate feed.', 'easy-facebook-likebox' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( self::format_feed_for_response( $repo->get_by_id( $new_id ) ), 201 );
	}

	/**
	 * POST /esf/v1/twitter/feeds/:id/flush-cache
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function flush_feed_cache( $request ) {
		return self::clear_feed_cache( $request );
	}

	/**
	 * POST /esf/v1/twitter/feeds/:id/clear-cache
	 *
	 * @since 6.7.6
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function clear_feed_cache( $request ) {
		$feed_id = (int) $request['id'];
		$repo    = ESF_Twitter_Feed_Repository::get_instance();
		if ( ! $repo->get_by_id( $feed_id ) ) {
			return new WP_Error( 'not_found', __( 'Feed not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
		}
		ESF_Twitter_Cache::flush_feed_cache( $feed_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'id'      => $feed_id,
			)
		);
	}

	/**
	 * Format feed row for REST response.
	 *
	 * @since 6.7.6
	 * @param object     $feed Feed row.
	 * @param array|null $keys Optional subset of keys.
	 * @return array
	 */
	private static function format_feed_for_response( $feed, $keys = null ) {
		$full = array(
			'id'         => (int) $feed->id,
			'name'       => $feed->name,
			'account_id' => (int) $feed->account_id,
			'feed_type'  => $feed->feed_type,
			'source_id'  => null !== $feed->source_id ? (string) $feed->source_id : '',
			'settings'   => null,
			'status'     => $feed->status,
			'created_at' => $feed->created_at,
			'updated_at' => $feed->updated_at,
		);

		if ( null !== $keys ) {
			$out = array();
			foreach ( $keys as $key ) {
				if ( 'settings' === $key ) {
					$settings    = json_decode( $feed->settings, true );
					$out[ $key ] = is_array( $settings ) ? $settings : ESF_Twitter_Feed_Repository::get_default_settings();
				} else {
					$out[ $key ] = $full[ $key ];
				}
			}
			return $out;
		}

		$settings         = json_decode( $feed->settings, true );
		$full['settings'] = is_array( $settings ) ? $settings : ESF_Twitter_Feed_Repository::get_default_settings();
		return $full;
	}

	/**
	 * Arguments schema for create endpoint.
	 *
	 * @since 6.7.6
	 * @return array
	 */
	private static function get_create_args() {
		return array(
			'name'       => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'account_id' => array(
				'type'              => 'integer',
				'required'          => true,
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && (int) $param > 0;
				},
			),
			'feed_type'  => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'source_id'  => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'settings'   => array(
				'type'     => 'object',
				'required' => false,
			),
			'status'     => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Resolve source identifier for new feeds.
	 *
	 * Falls back to the selected account's X user ID when source_id is omitted.
	 *
	 * @since 6.7.6
	 *
	 * @param mixed  $requested_source_id Raw source_id value from REST request.
	 * @param object $account             Account row selected for the new feed.
	 * @return string
	 */
	private static function resolve_source_id_for_create( $requested_source_id, $account ) {
		$source_id = sanitize_text_field( (string) $requested_source_id );
		if ( '' !== $source_id ) {
			return $source_id;
		}

		$account_source_id = '';
		if ( is_object( $account ) && isset( $account->x_user_id ) ) {
			$account_source_id = sanitize_text_field( (string) $account->x_user_id );
		}

		return $account_source_id;
	}

	/**
	 * Module-specific feed naming config for shared helpers.
	 *
	 * @since 6.9.0
	 *
	 * @return array<string,mixed>
	 */
	private static function get_feed_name_config() {
		return array(
			'account_fallback' => __( 'Twitter Feed', 'easy-facebook-likebox' ),
		);
	}

	/**
	 * Decode stored feed settings for internal update logic.
	 *
	 * @since 6.7.6
	 *
	 * @param object $feed Feed row object.
	 * @return array
	 */
	private static function decode_feed_settings( $feed ) {
		if ( ! is_object( $feed ) || ! isset( $feed->settings ) ) {
			return ESF_Twitter_Feed_Repository::get_default_settings();
		}

		$decoded = json_decode( (string) $feed->settings, true );
		if ( ! is_array( $decoded ) ) {
			return ESF_Twitter_Feed_Repository::get_default_settings();
		}

		return $decoded;
	}
}
