<?php
/**
 * Shared REST API for missing-feed admin recovery on the frontend.
 *
 * @package Easy_Social_Feed
 * @subpackage Admin/API
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_API_Missing_Feed' ) ) {

	/**
	 * Missing feed recovery endpoints.
	 *
	 * @since 6.9.0
	 */
	class ESF_API_Missing_Feed {

		/**
		 * REST namespace.
		 *
		 * @var string
		 */
		const NAMESPACE_V1 = 'esf/v1';

		/**
		 * Register routes.
		 *
		 * @return void
		 */
		public static function register_routes() {
			register_rest_route(
				self::NAMESPACE_V1,
				'/missing-feed/replace-shortcode',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'replace_shortcode' ),
					'permission_callback' => array( __CLASS__, 'write_permissions_check' ),
					'args'                => array(
						'module'       => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'post_id'      => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'old_feed_id'  => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'new_feed_id'  => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'shortcode_tag' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				)
			);
		}

		/**
		 * Permission callback for write operations.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return bool
		 */
		public static function write_permissions_check( $request ) {
			if ( ! $request instanceof WP_REST_Request ) {
				return false;
			}

			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return false;
			}

			$module = sanitize_key( (string) $request->get_param( 'module' ) );
			if ( ! self::user_can_manage_module( $module ) ) {
				return false;
			}

			$post_id = (int) $request->get_param( 'post_id' );
			return $post_id > 0 && current_user_can( 'edit_post', $post_id );
		}

		/**
		 * Replace a missing feed ID inside post content.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function replace_shortcode( $request ) {
			$module      = sanitize_key( (string) $request->get_param( 'module' ) );
			$post_id     = (int) $request->get_param( 'post_id' );
			$old_feed_id = (int) $request->get_param( 'old_feed_id' );
			$new_feed_id = (int) $request->get_param( 'new_feed_id' );
			$tag         = sanitize_key( (string) $request->get_param( 'shortcode_tag' ) );

			if ( '' === $tag ) {
				$tag = self::default_shortcode_tag( $module );
			}
			if ( '' === $tag ) {
				return new WP_Error(
					'invalid_module',
					__( 'Unsupported module.', 'easy-facebook-likebox' ),
					array( 'status' => 400 )
				);
			}

			if ( $old_feed_id <= 0 || $new_feed_id <= 0 ) {
				if ( ! self::is_legacy_shortcode_without_feed_id( $module, $tag ) || $new_feed_id <= 0 ) {
					return new WP_Error(
						'invalid_feed_id',
						__( 'Invalid feed ID.', 'easy-facebook-likebox' ),
						array( 'status' => 400 )
					);
				}
			}

			if ( ! self::feed_exists( $module, $new_feed_id ) ) {
				return new WP_Error(
					'feed_not_found',
					__( 'Selected feed was not found.', 'easy-facebook-likebox' ),
					array( 'status' => 404 )
				);
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				return new WP_Error(
					'post_not_found',
					__( 'Page not found.', 'easy-facebook-likebox' ),
					array( 'status' => 404 )
				);
			}

			$content  = (string) $post->post_content;
			$pattern  = self::shortcode_pattern( $tag, $old_feed_id );
			$new_tag  = self::is_legacy_shortcode_without_feed_id( $module, $tag )
				? self::default_shortcode_tag( $module )
				: $tag;
			$replaced = (string) preg_replace(
				$pattern,
				'[' . $new_tag . ' id="' . $new_feed_id . '"]',
				$content,
				1,
				$count
			);

			if ( 0 === $count ) {
				return new WP_Error(
					'shortcode_not_found',
					__( 'Shortcode was not found in this page content.', 'easy-facebook-likebox' ),
					array( 'status' => 404 )
				);
			}

			$updated = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $replaced,
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}

			return rest_ensure_response(
				array(
					'success'     => true,
					'post_id'     => $post_id,
					'new_feed_id' => $new_feed_id,
					'shortcode'   => '[' . $new_tag . ' id="' . $new_feed_id . '"]',
				)
			);
		}

		/**
		 * Build a regex that matches a single shortcode instance.
		 *
		 * @param string $tag     Shortcode tag.
		 * @param int    $feed_id Feed ID.
		 * @return string
		 */
		public static function shortcode_pattern( string $tag, int $feed_id ): string {
			if ( self::is_legacy_shortcode_without_feed_id( 'instagram', $tag ) ) {
				return '/\[' . preg_quote( 'my-instagram-feed', '/' ) . '(?:\s[^\]]*)?\]/i';
			}

			$tag_quoted = preg_quote( sanitize_key( $tag ), '/' );
			$id_quoted  = preg_quote( (string) max( 0, $feed_id ), '/' );

			return '/\[' . $tag_quoted . '\s+id\s*=\s*["\']?' . $id_quoted . '["\']?\s*\]/i';
		}

		/**
		 * Whether a shortcode tag omits feed IDs (legacy Instagram).
		 *
		 * @param string $module Module slug.
		 * @param string $tag    Shortcode tag.
		 * @return bool
		 */
		public static function is_legacy_shortcode_without_feed_id( string $module, string $tag ): bool {
			return 'instagram' === sanitize_key( $module ) && 'my-instagram-feed' === sanitize_key( $tag );
		}

		/**
		 * Default shortcode tag for a module.
		 *
		 * @param string $module Module slug.
		 * @return string
		 */
		public static function default_shortcode_tag( string $module ): string {
			$map = array(
				'instagram' => 'esf_instagram_feed',
				'twitter'   => 'esf_twitter_feed',
				'youtube'   => 'esf_youtube_feed',
			);

			$module = sanitize_key( $module );
			return isset( $map[ $module ] ) ? $map[ $module ] : '';
		}

		/**
		 * Whether the current user can manage a module.
		 *
		 * @param string $module Module slug.
		 * @return bool
		 */
		public static function user_can_manage_module( string $module ): bool {
			$module = sanitize_key( $module );
			$fn     = 'esf_' . $module . '_user_can_manage';
			if ( function_exists( $fn ) ) {
				return (bool) call_user_func( $fn );
			}

			$capability = apply_filters( 'esf_' . $module . '_manage_capability', 'manage_options' );
			return current_user_can( $capability );
		}

		/**
		 * Verify that a feed exists for the given module.
		 *
		 * @param string $module  Module slug.
		 * @param int    $feed_id Feed ID.
		 * @return bool
		 */
		public static function feed_exists( string $module, int $feed_id ): bool {
			$module  = sanitize_key( $module );
			$feed_id = (int) $feed_id;
			if ( $feed_id <= 0 ) {
				return false;
			}

			$exists = apply_filters( 'esf_missing_feed_exists', null, $module, $feed_id );
			if ( is_bool( $exists ) ) {
				return $exists;
			}

			$class_map = array(
				'instagram' => 'ESF_Instagram_Feed_Repository',
				'twitter'   => 'ESF_Twitter_Feed_Repository',
				'youtube'   => 'ESF_YouTube_Feed_Repository',
			);

			if ( ! isset( $class_map[ $module ] ) || ! class_exists( $class_map[ $module ] ) ) {
				return false;
			}

			$repo = call_user_func( array( $class_map[ $module ], 'get_instance' ) );
			if ( ! is_object( $repo ) || ! method_exists( $repo, 'get_by_id' ) ) {
				return false;
			}

			return (bool) $repo->get_by_id( $feed_id );
		}
	}
}
