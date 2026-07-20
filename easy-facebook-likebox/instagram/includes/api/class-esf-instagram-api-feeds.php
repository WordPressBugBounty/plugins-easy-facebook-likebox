<?php
/**
 * Instagram Feeds REST API
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/API
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_API_Feeds
 *
 * @since 6.8.0
 */
class ESF_Instagram_API_Feeds {

	/**
	 * Register feed routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/instagram/feeds',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_feeds' ),
					'permission_callback' => array( __CLASS__, 'read_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_feed' ),
					'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
				),
			)
		);

		register_rest_route(
			'esf/v1',
			'/instagram/feeds/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_feed' ),
					'permission_callback' => array( __CLASS__, 'read_permissions_check' ),
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

		register_rest_route(
			'esf/v1',
			'/instagram/feeds/(?P<id>[\d]+)/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'duplicate_feed' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/instagram/feeds/(?P<id>[\d]+)/clear-cache',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'clear_feed_cache' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
			)
		);

		register_rest_route(
			'esf/v1',
			'/instagram/feeds/(?P<id>[\d]+)/sync-stories',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'sync_feed_stories' ),
				'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
			)
		);
	}

	/**
	 * Read permission callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function read_permissions_check( $request ) {
		if ( $request instanceof WP_REST_Request && 'GET' !== $request->get_method() ) {
			return false;
		}
		return esf_instagram_user_can_manage();
	}

	/**
	 * Write permission callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function write_permissions_check( $request ) {
		if ( ! esf_instagram_user_can_manage() ) {
			return false;
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * List feeds.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_feeds( $request ) {
		$status = $request->get_param( 'status' );
		$rows   = ESF_Instagram_Feed_Repository::get_instance()->get_all( array( 'status' => $status ) );
		$data   = array();

		foreach ( $rows as $row ) {
			$data[] = self::format_feed( $row );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Get single feed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_feed( $request ) {
		$feed = ESF_Instagram_Feed_Repository::get_instance()->get_by_id( (int) $request['id'] );
		if ( ! $feed ) {
			return new WP_Error( 'not_found', __( 'Feed not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( self::format_feed( $feed ) );
	}

	/**
	 * Create feed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_feed( $request ) {
		$account_id = (int) $request->get_param( 'account_id' );
		$account    = ESF_Instagram_Account_Repository::get_instance()->get_by_id( $account_id );
		if ( ! $account ) {
			return new WP_Error( 'invalid_account', __( 'Selected account not found.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$feed_type = sanitize_text_field( (string) $request->get_param( 'feed_type' ) );
		$source_id = sanitize_text_field( (string) $request->get_param( 'source_id' ) );
		$validated = self::validate_hashtag_feed_payload( $feed_type, $source_id, $account );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( is_array( $validated ) ) {
			$feed_type = $validated['feed_type'];
			$source_id = $validated['source_id'];
		}

		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			$name = ESF_Feed_Name::build_auto_feed_name_for_source(
				$account,
				$feed_type,
				$source_id,
				self::get_feed_name_config()
			);
		}

		$settings = $request->get_param( 'settings' );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings = ESF_Feed_Name::set_name_meta( $settings, 'auto', $account_id );

		$id = ESF_Instagram_Feed_Repository::get_instance()->create(
			array(
				'name'       => $name,
				'account_id' => $account_id,
				'feed_type'  => $feed_type,
				'source_id'  => $source_id,
				'settings'   => $settings,
			)
		);

		if ( false === $id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create feed.', 'easy-facebook-likebox' ), array( 'status' => 500 ) );
		}

		$feed = ESF_Instagram_Feed_Repository::get_instance()->get_by_id( $id );

		if ( function_exists( 'esf_review_request_record_milestone' ) ) {
			esf_review_request_record_milestone( 'feed_saved', 'instagram' );
		}

		return new WP_REST_Response( self::format_feed( $feed ), 201 );
	}

	/**
	 * Update feed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_feed( $request ) {
		$feed_id = (int) $request['id'];
		$repo    = ESF_Instagram_Feed_Repository::get_instance();
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
					$next_settings = self::preserve_rest_managed_settings(
						$current_settings,
						$incoming_settings
					);
					$data[ $key ]  = $next_settings;
					$name_mode     = ESF_Feed_Name::get_name_mode( $next_settings );
				}
				continue;
			}

			if ( 'account_id' === $key ) {
				$account_id = (int) $request->get_param( $key );
				$account    = ESF_Instagram_Account_Repository::get_instance()->get_by_id( $account_id );
				if ( $account_id > 0 && ! $account ) {
					return new WP_Error( 'invalid_account', __( 'Selected account not found.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
				}
				$data[ $key ]    = $account_id;
				$next_account    = $account_id;
				$account_changed = ( $current_account !== $next_account );
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

		$account = ESF_Instagram_Account_Repository::get_instance()->get_by_id( $next_account );
		if ( ! $account ) {
			return new WP_Error( 'invalid_account', __( 'Selected account not found.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		$feed_type = isset( $data['feed_type'] ) ? (string) $data['feed_type'] : $current_feed_type;
		$source_id = array_key_exists( 'source_id', $data ) ? (string) $data['source_id'] : $current_source_id;
		$validated = self::validate_hashtag_feed_payload( $feed_type, $source_id, $account );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( is_array( $validated ) ) {
			$data['feed_type'] = $validated['feed_type'];
			$data['source_id'] = $validated['source_id'];
			$next_feed_type    = $validated['feed_type'];
			$next_source_id    = $validated['source_id'];
		}

		$name_mode = ESF_Feed_Name::apply_auto_name_on_source_change(
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

		if ( ! $repo->update( $feed_id, $data ) ) {
			return new WP_Error( 'update_failed', __( 'Failed to update feed.', 'easy-facebook-likebox' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after an Instagram feed is updated.
		 *
		 * Used to invalidate related caches and to notify extensions.
		 *
		 * @since 6.9.0
		 *
		 * @param int    $feed_id Feed id.
		 * @param array  $data    Update payload.
		 */
		do_action( 'esf_instagram_feed_updated', $feed_id, $data );

		return rest_ensure_response( self::format_feed( $repo->get_by_id( $feed_id ) ) );
	}

	/**
	 * Delete feed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_feed( $request ) {
		$feed_id = (int) $request['id'];
		$repo    = ESF_Instagram_Feed_Repository::get_instance();
		if ( ! $repo->get_by_id( $feed_id ) ) {
			return new WP_Error( 'not_found', __( 'Feed not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
		}
		if ( ! $repo->delete( $feed_id ) ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete feed.', 'easy-facebook-likebox' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after an Instagram feed is deleted.
		 *
		 * @since 6.9.0
		 *
		 * @param int $feed_id Feed id.
		 */
		do_action( 'esf_instagram_feed_deleted', $feed_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'id'      => $feed_id,
			)
		);
	}

	/**
	 * Duplicate feed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function duplicate_feed( $request ) {
		$new_id = ESF_Instagram_Feed_Repository::get_instance()->duplicate( (int) $request['id'] );
		if ( false === $new_id ) {
			return new WP_Error( 'duplicate_failed', __( 'Failed to duplicate feed.', 'easy-facebook-likebox' ), array( 'status' => 500 ) );
		}
		$feed = ESF_Instagram_Feed_Repository::get_instance()->get_by_id( $new_id );
		return new WP_REST_Response( self::format_feed( $feed ), 201 );
	}

	/**
	 * Clear cached API/feed rows for a single feed.
	 *
	 * @since 6.8.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function clear_feed_cache( $request ) {
		$feed_id = (int) $request['id'];
		$repo    = ESF_Instagram_Feed_Repository::get_instance();
		if ( ! $repo->get_by_id( $feed_id ) ) {
			return new WP_Error( 'not_found', __( 'Feed not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
		}

		$deleted = class_exists( 'ESF_Instagram_Cache' )
			? ESF_Instagram_Cache::flush_feed_cache( $feed_id )
			: 0;

		return rest_ensure_response(
			array(
				'success' => true,
				'id'      => $feed_id,
				'deleted' => false !== $deleted ? (int) $deleted : 0,
			)
		);
	}

	/**
	 * Clear stories cache for a feed's account and fetch fresh stories.
	 *
	 * @since 6.9.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function sync_feed_stories( $request ) {
		$feed_id = (int) $request['id'];
		$repo    = ESF_Instagram_Feed_Repository::get_instance();
		$feed    = $repo->get_by_id( $feed_id );
		if ( ! $feed ) {
			return new WP_Error( 'not_found', __( 'Feed not found.', 'easy-facebook-likebox' ), array( 'status' => 404 ) );
		}

		$account_id = isset( $feed->account_id ) ? (int) $feed->account_id : 0;
		if ( $account_id <= 0 ) {
			return new WP_Error(
				'no_account',
				__( 'Connect an Instagram account before syncing stories.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		if (
			! function_exists( 'esf_instagram_has_instagram_plan' )
			|| ! esf_instagram_has_instagram_plan()
			|| ! class_exists( 'ESF_Instagram_API_Stories' )
			|| ! class_exists( 'ESF_Instagram_Account_Repository' )
		) {
			return new WP_Error(
				'plan_required',
				__( 'Instagram Pro is required to sync stories.', 'easy-facebook-likebox' ),
				array( 'status' => 403 )
			);
		}

		$account_row = ESF_Instagram_Account_Repository::get_instance()->get_by_id( $account_id );
		if ( ! $account_row ) {
			return new WP_Error(
				'no_account',
				__( 'The connected Instagram account could not be found.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$result = ESF_Instagram_API_Stories::get_instance()->fetch_user_stories( $account_row );
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'stories_sync_failed',
				$result->get_error_message(),
				array(
					'status' => 502,
					'data'   => array(
						'code' => $result->get_error_code(),
					),
				)
			);
		}

		$nodes       = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$story_count = count( $nodes );

		if ( function_exists( 'esf_instagram_flush_stories_cache_for_account' ) ) {
			esf_instagram_flush_stories_cache_for_account( $account_id );
		}

		if ( $story_count > 0 && class_exists( 'ESF_Instagram_Cache' ) && function_exists( 'esf_instagram_stories_cache_key' ) ) {
			ESF_Instagram_Cache::set(
				esf_instagram_stories_cache_key( $account_id ),
				array( 'data' => $nodes ),
				ESF_Instagram_API_Stories::DEFAULT_TTL,
				'api',
				$account_id
			);
		}

		return rest_ensure_response(
			array(
				'success'     => true,
				'id'          => $feed_id,
				'account_id'  => $account_id,
				'story_count' => $story_count,
			)
		);
	}

	/**
	 * Format feed row.
	 *
	 * @param object $feed Feed row.
	 * @return array
	 */
	private static function format_feed( $feed ) {
		$settings = json_decode( (string) $feed->settings, true );
		if ( ! is_array( $settings ) ) {
			$settings = ESF_Instagram_Feed_Repository::get_default_settings();
		}

		$preview_is_cold = function_exists( 'esf_instagram_feed_preview_is_cold' )
			? esf_instagram_feed_preview_is_cold(
				(int) $feed->account_id,
				(string) $feed->feed_type,
				null !== $feed->source_id ? (string) $feed->source_id : '',
				$settings
			)
			: true;

		return array(
			'id'              => (int) $feed->id,
			'name'            => (string) $feed->name,
			'account_id'      => (int) $feed->account_id,
			'feed_type'       => (string) $feed->feed_type,
			'source_id'       => null !== $feed->source_id ? (string) $feed->source_id : '',
			'settings'        => $settings,
			'status'          => (string) $feed->status,
			'created_at'      => (string) $feed->created_at,
			'updated_at'      => (string) $feed->updated_at,
			'preview_is_cold' => (bool) $preview_is_cold,
		);
	}

	/**
	 * Validate hashtag feed fields before create/update.
	 *
	 * @since 6.9.0
	 *
	 * @param string $feed_type Feed type.
	 * @param string $source_id Source id / hashtag.
	 * @param object $account   Account row.
	 * @return array{feed_type:string,source_id:string}|WP_Error|null Null when not a hashtag feed.
	 */
	private static function validate_hashtag_feed_payload( $feed_type, $source_id, $account ) {
		$feed_type = sanitize_key( (string) $feed_type );
		if ( 'hashtag' !== $feed_type ) {
			return null;
		}

		if ( ! function_exists( 'esf_instagram_has_instagram_plan' ) || ! esf_instagram_has_instagram_plan() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Hashtag feeds are a premium feature.', 'easy-facebook-likebox' ),
				array( 'status' => 403 )
			);
		}

		$normalized = function_exists( 'esf_instagram_normalize_hashtag' )
			? esf_instagram_normalize_hashtag( $source_id )
			: '';

		if ( '' === $normalized ) {
			return array(
				'feed_type' => 'hashtag',
				'source_id' => '',
			);
		}

		if ( ! function_exists( 'esf_instagram_account_supports_hashtag' ) || ! esf_instagram_account_supports_hashtag( $account ) ) {
			return new WP_Error(
				'hashtag_unsupported_account',
				__( 'Hashtag feeds require an Instagram Business account connected via Facebook.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'feed_type' => 'hashtag',
			'source_id' => $normalized,
		);
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
			'account_fallback'  => __( 'Instagram Feed', 'easy-facebook-likebox' ),
			'hashtag_feed_type' => 'hashtag',
			'normalize_hashtag' => 'esf_instagram_normalize_hashtag',
		);
	}

	/**
	 * Decode stored feed settings for internal update logic.
	 *
	 * Shoppable and moderation are written by dedicated REST endpoints; the feed
	 * editor autosave must not overwrite them with stale React state.
	 *
	 * @since 6.9.0
	 *
	 * @param array<string,mixed> $current_settings Stored feed settings.
	 * @param array<string,mixed> $incoming_settings Incoming settings payload.
	 * @return array<string,mixed>
	 */
	private static function preserve_rest_managed_settings( array $current_settings, array $incoming_settings ) {
		/**
		 * Settings keys owned by dedicated REST controllers (not feed autosave).
		 *
		 * @since 6.9.0
		 *
		 * @param array<int,string> $keys Keys to preserve from the stored feed.
		 */
		$managed_keys = apply_filters(
			'esf_feed_settings_rest_managed_keys',
			array( 'shoppable', 'moderation' )
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
	 * Decode stored feed settings for internal update logic.
	 *
	 * @since 6.9.0
	 *
	 * @param object $feed Feed row object.
	 * @return array
	 */
	private static function decode_feed_settings( $feed ) {
		if ( ! is_object( $feed ) || ! isset( $feed->settings ) ) {
			return ESF_Instagram_Feed_Repository::get_default_settings();
		}

		$decoded = json_decode( (string) $feed->settings, true );
		if ( ! is_array( $decoded ) ) {
			return ESF_Instagram_Feed_Repository::get_default_settings();
		}

		return $decoded;
	}
}
