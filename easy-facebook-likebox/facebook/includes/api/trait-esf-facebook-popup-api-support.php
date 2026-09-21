<?php
/**
 * Shared feed-access + page-token helpers for Facebook Pro popup REST endpoints.
 *
 * Used by post comments, comment replies, and album-photo load-more so token
 * resolution and feed accessibility checks stay in one place.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/API
 * @since 6.7.9
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Trait_ESF_Facebook_Popup_Api_Support
 *
 * @since 6.7.9
 */
trait Trait_ESF_Facebook_Popup_Api_Support {

	/**
	 * Resolve feed settings from a hydrated (or raw JSON) feed row.
	 *
	 * ESF_Facebook_Feed_Repository::get_by_id() already hydrates
	 * `settings` to an array — never cast that array to string for json_decode.
	 *
	 * @since 6.7.9
	 *
	 * @param object               $feed_row Feed DB row.
	 * @param WP_REST_Request|null $request  Optional REST request for preview overrides.
	 * @return array<string,mixed>
	 */
	public static function resolve_feed_settings( $feed_row, $request = null ) {
		$defaults = ESF_Facebook_Feed_Repository::get_default_settings();

		if ( is_object( $feed_row ) && isset( $feed_row->settings ) && is_array( $feed_row->settings ) ) {
			$settings = $feed_row->settings;
		} elseif ( is_object( $feed_row ) && isset( $feed_row->settings ) && is_string( $feed_row->settings ) ) {
			$decoded  = json_decode( $feed_row->settings, true );
			$settings = array_replace_recursive( $defaults, is_array( $decoded ) ? $decoded : array() );
			$settings = ESF_Facebook_Feed_Repository::normalize_settings( $settings );
		} else {
			$settings = $defaults;
		}

		$can_manage = function_exists( 'esf_facebook_user_can_manage' ) && esf_facebook_user_can_manage();
		if ( $can_manage && $request instanceof WP_REST_Request ) {
			$override = $request->get_param( 'settings' );
			if ( is_array( $override ) && ! empty( $override ) ) {
				$settings = array_replace_recursive( $settings, $override );
				$settings = ESF_Facebook_Feed_Repository::normalize_settings( $settings );
			}
		}

		return $settings;
	}

	/**
	 * Assert a feed row is usable for popup REST endpoints.
	 *
	 * Empty/legacy status is treated as active. Any non-active status
	 * (`inactive`, `invalid`) is allowed for users who can manage the module,
	 * matching ESF_Facebook_Renderer::render_internal()'s admin-preview
	 * leniency. Public visitors only ever see `active` feeds rendered.
	 *
	 * @since 6.7.9
	 *
	 * @param object|null $feed_row Feed DB row.
	 * @return true|WP_Error
	 */
	public static function assert_feed_accessible( $feed_row ) {
		if ( ! is_object( $feed_row ) ) {
			return new WP_Error(
				'feed_not_found',
				__( 'Feed not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$content_type = isset( $feed_row->content_type ) ? sanitize_key( (string) $feed_row->content_type ) : 'feed';
		if ( 'likebox' === $content_type ) {
			return new WP_Error(
				'popup_unsupported',
				__( 'This feature is not available for Likebox.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$status = isset( $feed_row->status ) ? sanitize_key( (string) $feed_row->status ) : 'active';
		if ( '' === $status ) {
			$status = 'active';
		}

		if ( 'active' === $status ) {
			return true;
		}

		$can_manage = function_exists( 'esf_facebook_user_can_manage' ) && esf_facebook_user_can_manage();
		if ( $can_manage ) {
			return true;
		}

		if ( 'invalid' === $status && self::feed_has_public_cache_fallback( $feed_row ) ) {
			return true;
		}

		if ( 'inactive' === $status ) {
			return new WP_Error(
				'feed_inactive',
				__( 'Feed is not active.', 'easy-facebook-likebox' ),
				array( 'status' => 403 )
			);
		}

		return new WP_Error(
			'feed_not_found',
			__( 'Feed not found.', 'easy-facebook-likebox' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Build a page-token context from a page row.
	 *
	 * @since 6.7.9
	 *
	 * @param object|null $page_row Page DB row.
	 * @return array{token:string,page_row_id:int}|null
	 */
	private static function token_context_from_page_row( $page_row ) {
		if ( ! is_object( $page_row ) ) {
			return null;
		}
		if ( isset( $page_row->status ) && 'active' !== sanitize_key( (string) $page_row->status ) ) {
			return null;
		}
		$token = isset( $page_row->page_access_token ) ? trim( (string) $page_row->page_access_token ) : '';
		if ( '' === $token ) {
			return null;
		}
		$page_row_id = isset( $page_row->id ) ? (int) $page_row->id : 0;
		if ( $page_row_id <= 0 ) {
			return null;
		}
		return array(
			'token'       => $token,
			'page_row_id' => $page_row_id,
		);
	}

	/**
	 * Resolve page access token for a feed row.
	 *
	 * Prefers the Page that owns the post (`{page_id}_{post_id}`) so multifeed /
	 * album / filter layouts use the correct token, then falls back to the feed's
	 * primary page and `settings.feed.page_ids`.
	 *
	 * @since 6.7.9
	 *
	 * @param object $feed_row Feed DB row.
	 * @param string $post_id  Optional sanitized Facebook object ID.
	 * @return array{token:string,page_row_id:int}|WP_Error
	 */
	public static function resolve_page_token_for_feed( $feed_row, $post_id = '' ) {
		if ( ! is_object( $feed_row ) ) {
			return new WP_Error(
				'feed_not_found',
				__( 'Feed not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		if ( ! class_exists( 'ESF_Facebook_Page_Repository' ) ) {
			return new WP_Error(
				'page_not_found',
				__( 'Page not found for this feed.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$repo    = ESF_Facebook_Page_Repository::get_instance();
		$post_id = (string) $post_id;

		// Prefer the Page encoded in Graph object IDs (`123456789_987654321`).
		if ( '' !== $post_id && preg_match( '/^(\d+)_/', $post_id, $matches ) ) {
			$fb_page_id = (string) $matches[1];
			$ctx        = self::token_context_from_page_row( $repo->get_by_page_id( $fb_page_id ) );
			if ( null !== $ctx ) {
				return $ctx;
			}
		}

		$candidate_ids = array();
		$primary_id    = isset( $feed_row->page_id ) ? (int) $feed_row->page_id : 0;
		if ( $primary_id > 0 ) {
			$candidate_ids[] = $primary_id;
		}

		$settings = self::resolve_feed_settings( $feed_row );
		$feed_cfg = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();
		if ( isset( $feed_cfg['page_ids'] ) && is_array( $feed_cfg['page_ids'] ) ) {
			foreach ( $feed_cfg['page_ids'] as $id ) {
				$id = (int) $id;
				if ( $id > 0 && ! in_array( $id, $candidate_ids, true ) ) {
					$candidate_ids[] = $id;
				}
			}
		}

		foreach ( $candidate_ids as $page_row_id ) {
			$ctx = self::token_context_from_page_row( $repo->get_by_id( $page_row_id ) );
			if ( null !== $ctx ) {
				return $ctx;
			}
		}

		if ( empty( $candidate_ids ) ) {
			return new WP_Error(
				'page_not_found',
				__( 'Page not found for this feed.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		return new WP_Error(
			'missing_access_token',
			__( 'No page access token is available for this feed. Please reconnect the Page.', 'easy-facebook-likebox' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Whether an invalid feed may still serve cached content to public visitors.
	 *
	 * @since 6.7.9
	 *
	 * @param object $feed_row Feed DB row.
	 * @return bool
	 */
	public static function feed_has_public_cache_fallback( $feed_row ) {
		if ( ! is_object( $feed_row ) ) {
			return false;
		}

		$status = isset( $feed_row->status ) ? sanitize_key( (string) $feed_row->status ) : 'active';
		if ( 'invalid' !== $status || ! class_exists( 'ESF_Facebook_Renderer' ) ) {
			return false;
		}

		$settings = self::resolve_feed_settings( $feed_row );
		$probe    = (object) array(
			'id'        => isset( $feed_row->id ) ? (int) $feed_row->id : 0,
			'page_id'   => isset( $feed_row->page_id ) ? (int) $feed_row->page_id : 0,
			'feed_type' => isset( $feed_row->feed_type ) ? (string) $feed_row->feed_type : 'timeline',
			'settings'  => $settings,
		);

		return ESF_Facebook_Renderer::feed_has_posts_cache( $probe );
	}

	/**
	 * Whether popup endpoints must serve cache only (source disconnected).
	 *
	 * @since 6.7.9
	 *
	 * @param object $feed_row Feed DB row.
	 * @return bool
	 */
	public static function is_feed_cache_only( $feed_row ) {
		if ( ! is_object( $feed_row ) ) {
			return false;
		}

		$page_row_id = isset( $feed_row->page_id ) ? (int) $feed_row->page_id : 0;
		$page_row    = null;
		if ( $page_row_id > 0 && class_exists( 'ESF_Facebook_Page_Repository' ) ) {
			$page_row = ESF_Facebook_Page_Repository::get_instance()->get_by_id( $page_row_id );
		}

		if ( class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
			return ESF_Feed_Disconnected_Cache::facebook_source_is_disconnected( $feed_row, $page_row );
		}

		$status = isset( $feed_row->status ) ? sanitize_key( (string) $feed_row->status ) : 'active';
		return 'invalid' === $status && null === $page_row;
	}

	/**
	 * Resolve a page token when available; null when disconnected or missing.
	 *
	 * @since 6.7.9
	 *
	 * @param object $feed_row Feed DB row.
	 * @param string $post_id  Optional sanitized Facebook object ID.
	 * @return array{token:string,page_row_id:int}|null
	 */
	public static function try_resolve_page_token_for_feed( $feed_row, $post_id = '' ) {
		$result = self::resolve_page_token_for_feed( $feed_row, $post_id );
		return is_wp_error( $result ) ? null : $result;
	}
}
