<?php
/**
 * Instagram feed renderer.
 *
 * Thin orchestrator that delegates HTML generation to the shared
 * `EasySocialFeed\Layouts\Renderer`. Owns the module-specific bits:
 *  - feed/account row loading from their repositories,
 *  - settings merge with the IG-specific defaults,
 *  - post fetching (currently filter-based until the new fetcher lands).
 *
 * Mirrors the public API of `ESF_Twitter_Renderer::render()` so the same
 * patterns can be reused everywhere.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.9.0
 */

use EasySocialFeed\Instagram\Layouts\AccountMapper;
use EasySocialFeed\Instagram\Layouts\PostMapper;
use EasySocialFeed\Layouts\PreviewResponseBuilder;
use EasySocialFeed\Layouts\Primitives\MissingFeedState;
use EasySocialFeed\Layouts\Renderer as SharedRenderer;
use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\Layouts\ValueObjects\MissingFeedContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_Renderer
 *
 * @since 6.9.0
 */
class ESF_Instagram_Renderer {

	/**
	 * Module slug.
	 *
	 * @var string
	 */
	const MODULE = 'instagram';

	/**
	 * Maximum media nodes persisted in local feed cache for load-more reuse.
	 *
	 * @since 6.9.0
	 * @var int
	 */
	const MAX_LOCAL_CACHE_POSTS = 120;

	/**
	 * Render a feed by ID.
	 *
	 * @param int   $feed_id             Feed ID.
	 * @param array $settings_override   Optional settings to merge (for live preview).
	 * @param bool  $enqueue_assets      Whether to enqueue CSS/JS. Set false for REST preview.
	 * @param int   $account_id_override Optional account override for unsaved source changes.
	 * @return string HTML output, or empty string on failure.
	 */
	public function render( $feed_id, $settings_override = array(), $enqueue_assets = true, $account_id_override = 0 ) {
		$rendered = $this->render_internal( (int) $feed_id, is_array( $settings_override ) ? $settings_override : array(), (int) $account_id_override );

		if ( '' === $rendered['html'] ) {
			return '';
		}

		if ( $enqueue_assets && null !== $rendered['definition'] ) {
			( new SharedRenderer() )->enqueue_assets( $rendered['definition'] );
		}

		return $rendered['html'];
	}

	/**
	 * Build the REST preview payload for a feed.
	 *
	 * @param int   $feed_id             Feed ID.
	 * @param array $settings_override   Optional settings override.
	 * @param int   $account_id_override Optional account override.
	 * @param array $feed_overrides    Optional feed_type/source_id overrides for live preview.
	 *
	 * @return array{html:string,css_url:string,layout_css_url:string,has_local_cache:bool,js_url:string|null,debug?:array}
	 */
	public function build_preview_payload( $feed_id, $settings_override = array(), $account_id_override = 0, array $feed_overrides = array() ) {
		$render_options = array( 'media_context' => 'preview' );
		if ( isset( $feed_overrides['feed_type'] ) && is_string( $feed_overrides['feed_type'] ) ) {
			$render_options['feed_type'] = $feed_overrides['feed_type'];
		}
		if ( array_key_exists( 'source_id', $feed_overrides ) ) {
			$render_options['source_id'] = (string) $feed_overrides['source_id'];
		}

		$rendered = $this->render_internal(
			(int) $feed_id,
			is_array( $settings_override ) ? $settings_override : array(),
			(int) $account_id_override,
			$render_options
		);

		$payload = PreviewResponseBuilder::build( $rendered['renderer_output'], $rendered['has_local_cache'] );
		if ( ! empty( $rendered['media_error'] ) && is_array( $rendered['media_error'] ) ) {
			$payload['media_error'] = $rendered['media_error'];
		}
		if ( isset( $rendered['notice'] ) && is_string( $rendered['notice'] ) && '' !== $rendered['notice'] ) {
			$payload['notice'] = array( 'message' => $rendered['notice'] );
		}
		if ( function_exists( 'esf_instagram_debug_enabled' ) && esf_instagram_debug_enabled() && ! empty( $rendered['debug'] ) ) {
			$payload['debug'] = $rendered['debug'];
		}

		return $payload;
	}

	/**
	 * Build the media cache key for an account.
	 *
	 * Graph media is account-scoped: every feed using the same account reads
	 * and writes the same cache row.
	 *
	 * @since 6.9.0
	 *
	 * @param int $account_id Account ID.
	 * @return string
	 */
	public static function media_cache_key( int $account_id ): string {
		return sprintf( 'esf_ig_media_%d', max( 0, $account_id ) );
	}

	/**
	 * Resolve cache keys for a feed's media payload.
	 *
	 * Hashtag feeds use tag-scoped keys so multiple feeds/skins share one row.
	 *
	 * @since 6.9.0
	 *
	 * @param object $feed_obj Feed object.
	 * @return array{cache_key:string,meta_key:string}
	 */
	public static function resolve_media_cache_keys( $feed_obj ): array {
		if ( function_exists( 'esf_instagram_feed_is_hashtag' ) && esf_instagram_feed_is_hashtag( $feed_obj ) ) {
			$tag = function_exists( 'esf_instagram_normalize_hashtag' )
				? esf_instagram_normalize_hashtag( isset( $feed_obj->source_id ) ? (string) $feed_obj->source_id : '' )
				: '';
			$media_type = function_exists( 'esf_instagram_feed_hashtag_media_type' )
				? esf_instagram_feed_hashtag_media_type( $feed_obj )
				: 'top_media';
			$key        = function_exists( 'esf_instagram_hashtag_cache_key' )
				? esf_instagram_hashtag_cache_key( $tag, $media_type )
				: '';
			if ( '' !== $key ) {
				return array(
					'cache_key' => $key,
					'meta_key'  => $key . '_meta',
				);
			}
		}

		$account_id = is_object( $feed_obj ) && isset( $feed_obj->account_id ) ? (int) $feed_obj->account_id : 0;
		$cache_key  = self::media_cache_key( $account_id );

		return array(
			'cache_key' => $cache_key,
			'meta_key'  => $cache_key . '_meta',
		);
	}

	/**
	 * Shared internal pipeline returning everything callers can need.
	 *
	 * @param int   $feed_id             Feed ID.
	 * @param array $settings_override   Settings override.
	 * @param int   $account_id_override Account override.
	 * @param array $render_options      Optional: media_context ("preview"|"frontend"), feed_type, source_id.
	 *
	 * @return array{html:string,renderer_output:array<string,mixed>,definition:?\EasySocialFeed\Layouts\ValueObjects\LayoutDefinition,has_local_cache:bool,debug:array<string,mixed>,media_error?:array{code:string,message:string}|null,notice?:string|null}
	 */
	private function render_internal( int $feed_id, array $settings_override, int $account_id_override, array $render_options = array() ): array {
		$empty = array(
			'html'            => '',
			'renderer_output' => array(
				'html'        => '',
				'assets'      => array(
					'base_css'   => null,
					'layout_css' => null,
					'layout_js'  => null,
				),
				'inline_css'  => '',
				'layout_slug' => '',
			),
			'definition'      => null,
			'has_local_cache' => false,
			'debug'           => array(),
			'notice'          => null,
		);

		if ( $feed_id <= 0 ) {
			return $empty;
		}

		if ( ! class_exists( 'ESF_Instagram_Feed_Repository' ) || ! class_exists( 'ESF_Instagram_Account_Repository' ) ) {
			return $empty;
		}

		$feed_repo = ESF_Instagram_Feed_Repository::get_instance();
		$feed_row  = $feed_repo->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return $this->build_missing_feed_response( $feed_id );
		}

		$saved = isset( $feed_row->settings ) ? json_decode( (string) $feed_row->settings, true ) : array();
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$settings = array_replace_recursive( ESF_Instagram_Feed_Repository::get_default_settings(), $saved );
		// Live preview (and customize) overrides must affect Multifeed detection/fetch
		// as well as layout chrome — not only SharedRenderer merge later.
		if ( ! empty( $settings_override ) ) {
			$settings = array_replace_recursive( $settings, $settings_override );
		}

		$feed_obj = (object) array(
			'id'         => (int) $feed_row->id,
			'name'       => isset( $feed_row->name ) ? (string) $feed_row->name : '',
			'account_id' => isset( $feed_row->account_id ) ? (int) $feed_row->account_id : 0,
			'feed_type'  => isset( $feed_row->feed_type ) ? (string) $feed_row->feed_type : 'user_timeline',
			'source_id'  => isset( $feed_row->source_id ) ? (string) $feed_row->source_id : '',
			'settings'   => $settings,
		);

		if ( $account_id_override > 0 ) {
			$feed_obj->account_id = $account_id_override;
		}

		if ( isset( $render_options['feed_type'] ) && is_string( $render_options['feed_type'] ) ) {
			$feed_type = sanitize_key( $render_options['feed_type'] );
			if ( in_array( $feed_type, array( 'user_timeline', 'hashtag' ), true ) ) {
				$feed_obj->feed_type = $feed_type;
				if ( 'user_timeline' === $feed_type ) {
					$feed_obj->source_id = '';
				}
			}
		}
		if ( array_key_exists( 'source_id', $render_options ) ) {
			$feed_obj->source_id = function_exists( 'esf_instagram_normalize_hashtag' )
				? esf_instagram_normalize_hashtag( (string) $render_options['source_id'] )
				: sanitize_text_field( (string) $render_options['source_id'] );
		}

		$account_repo = ESF_Instagram_Account_Repository::get_instance();
		$account_row  = $account_repo->get_by_id( $feed_obj->account_id );
		$account      = $account_row ? AccountMapper::from_row( $account_row ) : null;
		if ( $account && class_exists( 'ESF_Instagram_Local_Media' ) ) {
			$account = ESF_Instagram_Local_Media::with_localized_avatar( $account, $account_row );
		}

		$media_context = isset( $render_options['media_context'] ) && 'preview' === $render_options['media_context']
			? 'preview'
			: 'frontend';

		// Multifeed: hide the single-account header + stories (same UX as legacy).
		$is_multifeed = function_exists( 'esf_instagram_feed_is_multifeed' ) && esf_instagram_feed_is_multifeed( $feed_obj );
		if ( $is_multifeed ) {
			if ( ! isset( $feed_obj->settings ) || ! is_array( $feed_obj->settings ) ) {
				$feed_obj->settings = array();
			}
			if ( ! isset( $feed_obj->settings['header'] ) || ! is_array( $feed_obj->settings['header'] ) ) {
				$feed_obj->settings['header'] = array();
			}
			$feed_obj->settings['header']['show']          = false;
			$feed_obj->settings['header']['show_stories'] = false;
			$feed_obj->settings['header']['stories_row']  = false;
			$feed_obj->settings['header']['stories_ring'] = false;
		}

		$fetch_result = $this->fetch_posts( $feed_obj, $account, $media_context );
		$posts        = $fetch_result['posts'];

		if ( function_exists( 'esf_instagram_feed_is_hashtag' ) && esf_instagram_feed_is_hashtag( $feed_obj ) && null !== $account ) {
			$account = AccountMapper::for_hashtag( (string) $feed_obj->source_id, $account );
		}

		$layout_overrides = $settings_override;
		if ( $is_multifeed ) {
			// Preview may force header/stories on for client toggles — keep Multifeed hidden.
			$layout_overrides = array_replace_recursive(
				is_array( $layout_overrides ) ? $layout_overrides : array(),
				array(
					'header' => array(
						'show'         => false,
						'show_stories' => false,
						'stories_row'  => false,
						'stories_ring' => false,
					),
				)
			);
		}
		if (
			$fetch_result['media_error'] instanceof WP_Error
			&& empty( $posts )
		) {
			$layout_overrides = array_replace_recursive(
				is_array( $layout_overrides ) ? $layout_overrides : array(),
				array(
					'feed' => array(
						'media_error_message' => $fetch_result['media_error']->get_error_message(),
					),
				)
			);
		}

		$renderer_output = ( new SharedRenderer() )->render(
			self::MODULE,
			$feed_obj,
			$account,
			$posts,
			$layout_overrides
		);

		$definition = null;
		if ( '' !== $renderer_output['layout_slug'] ) {
			$registry   = \EasySocialFeed\Layouts\LayoutRegistry::for( self::MODULE );
			$definition = $registry->get( $renderer_output['layout_slug'] );
		}

		$notice = isset( $fetch_result['notice'] ) && is_string( $fetch_result['notice'] ) && '' !== $fetch_result['notice']
			? $fetch_result['notice']
			: null;

		if (
			null === $notice
			&& 'preview' === $media_context
			&& function_exists( 'esf_instagram_feed_is_hashtag' )
			&& esf_instagram_feed_is_hashtag( $feed_obj )
		) {
			$settings = isset( $feed_obj->settings ) && is_array( $feed_obj->settings ) ? $feed_obj->settings : array();
			$feed_cfg = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();
			$per_page = isset( $feed_cfg['per_page'] ) ? max( 1, min( 50, (int) $feed_cfg['per_page'] ) ) : 9;
			$tag      = function_exists( 'esf_instagram_normalize_hashtag' )
				? esf_instagram_normalize_hashtag( isset( $feed_obj->source_id ) ? (string) $feed_obj->source_id : '' )
				: '';
			$media_type = function_exists( 'esf_instagram_feed_hashtag_media_type' )
				? esf_instagram_feed_hashtag_media_type( $feed_obj )
				: 'top_media';

			if ( '' !== $tag ) {
				$notice = $this->build_hashtag_api_limit_notice(
					count( $posts ),
					'',
					$tag,
					$media_type,
					$per_page,
					$media_context
				);
			}
		}

		return array(
			'html'            => $renderer_output['html'],
			'renderer_output' => $renderer_output,
			'definition'      => $definition,
			'has_local_cache' => $fetch_result['has_local_cache'],
			'debug'           => isset( $fetch_result['debug'] ) && is_array( $fetch_result['debug'] ) ? $fetch_result['debug'] : array(),
			'media_error'     => $this->serialize_media_error(
				isset( $fetch_result['media_error'] ) ? $fetch_result['media_error'] : null
			),
			'notice'          => $notice,
		);
	}

	/**
	 * Build the shared missing-feed placeholder when a shortcode references a deleted feed.
	 *
	 * @since 6.9.0
	 *
	 * @param int $feed_id Missing feed ID from the shortcode.
	 * @return array{html:string,renderer_output:array<string,mixed>,definition:null,has_local_cache:bool,debug:array<string,mixed>,notice:null}
	 */
	private function build_missing_feed_response( int $feed_id ): array {
		$empty_output = array(
			'html'        => '',
			'assets'      => array(
				'base_css'   => null,
				'layout_css' => null,
				'layout_js'  => null,
			),
			'inline_css'  => '',
			'layout_slug' => '',
		);

		$context = $this->build_missing_feed_context( $feed_id );
		$html    = MissingFeedState::render( $context );

		return array(
			'html'            => $html,
			'renderer_output' => $empty_output,
			'definition'      => null,
			'has_local_cache' => false,
			'debug'           => array(),
			'notice'          => null,
		);
	}

	/**
	 * Assemble the missing-feed context for Instagram shortcodes.
	 *
	 * @since 6.9.0
	 *
	 * @param int $feed_id Missing feed ID.
	 * @return MissingFeedContext
	 */
	private function build_missing_feed_context( int $feed_id ): MissingFeedContext {
		$can_manage = function_exists( 'esf_instagram_user_can_manage' ) && esf_instagram_user_can_manage();
		$post_id    = function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0;

		return new MissingFeedContext(
			self::MODULE,
			$feed_id,
			'esf_instagram_feed',
			$post_id,
			$can_manage,
			MissingFeedState::default_public_message( self::MODULE ),
			admin_url( 'admin.php?page=esf-instagram&screen=feeds' ),
			'/instagram/feeds/{id}/preview',
			'/instagram/feeds'
		);
	}

	/**
	 * Normalize a media fetch error for preview/admin consumers.
	 *
	 * @param mixed $error WP_Error or null.
	 * @return array{code:string,message:string}|null
	 */
	private function serialize_media_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return null;
		}

		return array(
			'code'    => (string) $error->get_error_code(),
			'message' => (string) $error->get_error_message(),
		);
	}

	/**
	 * Fetch the post list for a feed.
	 *
	 * Pipeline:
	 *   1. Allow integrators to short-circuit via `esf_instagram_render_posts_payload`.
	 *   2. When no override, fetch raw Graph nodes via {@see ESF_Instagram_API_Media}
	 *      with results cached in {@see ESF_Instagram_Cache}.
	 *   3. Run raw nodes through the moderation pipeline (`esf_instagram_filter_raw_posts`
	 *      and its generic `esf_layouts_filter_raw_posts` alias). This is the seam
	 *      reserved for the upcoming hide/show-per-post feature so it can drop in
	 *      without touching the renderer.
	 *   4. Map every node to a Post value object.
	 *
	 * Listeners on the payload filter may return either:
	 *   - `{ posts: array, has_local_cache: bool }` shape, or
	 *   - a flat array of Graph nodes / Post arrays / Post objects.
	 *
	 * @param object              $feed_obj Feed object.
	 * @param AccountSummary|null $account  Account.
	 *
	 * @return array{posts:array<int,\EasySocialFeed\Layouts\ValueObjects\Post>,has_local_cache:bool,debug:array<string,mixed>,media_error:\WP_Error|null,notice:string|null}
	 */
	private function fetch_posts( $feed_obj, ?AccountSummary $account, string $media_context = 'frontend' ): array {
		$preset = array(
			'posts'           => array(),
			'has_local_cache' => false,
		);

		/**
		 * Filter the Instagram post payload before mapping.
		 *
		 * Returning a non-default value short-circuits the API fetch. This is
		 * Hook for extensions to supply posts before the cache or API fetch.
		 *
		 * Listeners may return either:
		 *   - `array{posts:array,has_local_cache:bool}` (preferred shape), or
		 *   - a flat `array<int, array|Post>` of Graph nodes / Post VOs.
		 *
		 * @param array               $value         Default empty payload.
		 * @param object              $feed_obj      Feed object.
		 * @param AccountSummary|null $account       Owning account.
		 * @param string              $media_context Display context (`frontend`|`preview`).
		 */
		$payload = apply_filters( 'esf_instagram_render_posts_payload', $preset, $feed_obj, $account, $media_context );

		$has_cache       = false;
		$candidates      = array();
		$candidates_raw  = array();
		$short_circuited = false;
		$debug           = array();
		$media_error     = null;
		$notice          = null;

		if ( is_array( $payload ) ) {
			if ( array_key_exists( 'posts', $payload ) || array_key_exists( 'has_local_cache', $payload ) ) {
				$candidates = isset( $payload['posts'] ) && is_array( $payload['posts'] ) ? $payload['posts'] : array();
				$has_cache  = ! empty( $payload['has_local_cache'] );
				if ( ! empty( $candidates ) ) {
					$short_circuited = true;
				}
			} else {
				$candidates      = $payload;
				$short_circuited = ! empty( $candidates );
			}
		}

		if ( ! $short_circuited ) {
			$fetched        = $this->fetch_raw_nodes( $feed_obj, $account, $media_context );
			$candidates_raw = $fetched['nodes'];
			if ( ! empty( $fetched['debug'] ) && is_array( $fetched['debug'] ) ) {
				$debug = $fetched['debug'];
			}
			if ( isset( $fetched['media_error'] ) && $fetched['media_error'] instanceof WP_Error ) {
				$media_error = $fetched['media_error'];
			}
			if ( isset( $fetched['notice'] ) && is_string( $fetched['notice'] ) && '' !== $fetched['notice'] ) {
				$notice = $fetched['notice'];
			}
		}

		/**
		 * Filter the raw Graph nodes for a feed before they are mapped to Post objects.
		 *
		 * Reserved for moderation: the upcoming hide/show feature should attach here
		 * and drop nodes whose `id` is marked as hidden (or keep only the explicitly
		 * shown ones). Doing it before mapping guarantees we never expose a moderated
		 * post to a layout class.
		 *
		 * @param array<int,array<string,mixed>> $nodes    Raw Graph nodes.
		 * @param object                         $feed_obj Feed object.
		 * @param AccountSummary|null            $account  Owning account.
		 */
		$candidates_raw = apply_filters( 'esf_instagram_filter_raw_posts', $candidates_raw, $feed_obj, $account );

		/**
		 * Generic module-agnostic alias of the raw-posts filter.
		 *
		 * Layouts/extensions that want to act on every module use this hook;
		 * IG-specific listeners should prefer `esf_instagram_filter_raw_posts`.
		 *
		 * @param array<int,array<string,mixed>> $nodes    Raw nodes.
		 * @param string                         $module   Module slug.
		 * @param object                         $feed_obj Feed object.
		 * @param AccountSummary|null            $account  Owning account.
		 */
		$candidates_raw = apply_filters( 'esf_layouts_filter_raw_posts', $candidates_raw, self::MODULE, $feed_obj, $account );

		$posts = array();
		foreach ( $candidates_raw as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$post = PostMapper::from_graph_node( $node, $account );
			if ( null === $post ) {
				continue;
			}
			if ( empty( $post->get_media() ) ) {
				continue;
			}
			$posts[] = $post;
		}

		// Then map the (already-iterable) filter short-circuit input.
		foreach ( $candidates as $entry ) {
			if ( $entry instanceof \EasySocialFeed\Layouts\ValueObjects\Post ) {
				$posts[] = $entry;
				continue;
			}
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( isset( $entry['media_type'] ) ) {
				$post = PostMapper::from_graph_node( $entry, $account );
				if ( null !== $post ) {
					$posts[] = $post;
				}
				continue;
			}
			$posts[] = new \EasySocialFeed\Layouts\ValueObjects\Post( $entry );
		}

		return array(
			'posts'           => $posts,
			'has_local_cache' => $has_cache,
			'debug'           => $debug,
			'media_error'     => $media_error,
			'notice'          => $notice,
		);
	}

	/**
	 * Fetch raw Graph media nodes for a feed via {@see ESF_Instagram_API_Media}
	 * with results cached in {@see ESF_Instagram_Cache}.
	 *
	 * Failures are silenced (we never surface an exception to the layout), but
	 * the WP_Error is forwarded to the `esf_instagram_media_fetch_failed` action
	 * so admin UIs can surface it.
	 *
	 * @param object              $feed_obj Feed object.
	 * @param AccountSummary|null $account  Owning account VO.
	 *
	 * @return array{nodes:array<int,array<string,mixed>>,has_local_cache:bool,debug?:array<string,mixed>,media_error?:\WP_Error|null,notice?:string|null}
	 */
	private function fetch_raw_nodes( $feed_obj, ?AccountSummary $account, string $media_context = 'frontend' ): array {
		if ( function_exists( 'esf_instagram_feed_is_hashtag' ) && esf_instagram_feed_is_hashtag( $feed_obj ) ) {
			return $this->fetch_hashtag_raw_nodes( $feed_obj, $account, $media_context );
		}

		return $this->fetch_timeline_raw_nodes( $feed_obj, $account, $media_context );
	}

	/**
	 * Fetch raw Graph media nodes for a user timeline feed.
	 *
	 * @param object              $feed_obj Feed object.
	 * @param AccountSummary|null $account  Owning account VO.
	 * @param string              $media_context Display context.
	 *
	 * @return array{nodes:array<int,array<string,mixed>>,has_local_cache:bool,media_error?:\WP_Error|null}
	 */
	private function fetch_timeline_raw_nodes( $feed_obj, ?AccountSummary $account, string $media_context = 'frontend' ): array {
		$result = array(
			'nodes'           => array(),
			'has_local_cache' => false,
		);

		if ( null === $account || ! class_exists( 'ESF_Instagram_API_Media' ) || ! class_exists( 'ESF_Instagram_Cache' ) ) {
			return $result;
		}

		$account_id = $account->get_id();
		if ( $account_id <= 0 ) {
			return $result;
		}

		$settings = isset( $feed_obj->settings ) && is_array( $feed_obj->settings ) ? $feed_obj->settings : array();
		$feed_cfg      = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();
		$per_page      = isset( $feed_cfg['per_page'] ) ? max( 1, min( 50, (int) $feed_cfg['per_page'] ) ) : 9;
		$media_context = in_array( $media_context, array( 'preview', 'frontend' ), true ) ? $media_context : 'frontend';
		$fetch_limit   = min( self::MAX_LOCAL_CACHE_POSTS, ESF_Instagram_API_Media::MAX_LIMIT );

		$cache_key = self::media_cache_key( $account_id );
		$meta_key  = $cache_key . '_meta';

		$cached = ESF_Instagram_Cache::get( $cache_key );
		if ( is_array( $cached ) && isset( $cached['data'] ) && is_array( $cached['data'] ) ) {
			$nodes = $cached['data'];
			if ( class_exists( 'ESF_Instagram_Local_Media' ) ) {
				$nodes = ESF_Instagram_Local_Media::prepare_nodes_for_display( $nodes, $account_id, $media_context, $per_page );
			}
			return array(
				'nodes'           => $nodes,
				'has_local_cache' => true,
			);
		}

		if ( ! class_exists( 'ESF_Instagram_Account_Repository' ) ) {
			return $result;
		}

		$account_row = ESF_Instagram_Account_Repository::get_instance()->get_by_id( $account_id );
		if ( ! $account_row ) {
			return $result;
		}

		$response = ESF_Instagram_API_Media::get_instance()->fetch_user_media( $account_row, $fetch_limit );

		if ( is_wp_error( $response ) ) {
			/**
			 * Fires when an Instagram media fetch fails. Used by the admin dashboard
			 * to surface API errors without polluting the feed render itself.
			 *
			 * @param WP_Error $error    The error.
			 * @param object   $feed_obj Feed object.
			 * @param int      $account_id Account id.
			 */
			do_action( 'esf_instagram_media_fetch_failed', $response, $feed_obj, $account_id );
			return array(
				'nodes'           => array(),
				'has_local_cache' => false,
				'media_error'     => $response,
			);
		}

		$nodes = isset( $response['data'] ) && is_array( $response['data'] ) ? array_values( $response['data'] ) : array();

		// Persist remote Graph nodes; localization runs at display time (preview: limited sync batch).
		$pagination = isset( $response['pagination'] ) && is_array( $response['pagination'] ) ? $response['pagination'] : array();
		$cursor     = isset( $pagination['cursor'] ) ? sanitize_text_field( (string) $pagination['cursor'] ) : '';
		$ttl        = esf_instagram_get_cache_ttl_secs( $feed_obj, $account_id );

		ESF_Instagram_Cache::set(
			$cache_key,
			array(
				'data'       => $nodes,
				'pagination' => $pagination,
			),
			$ttl,
			'account',
			$account_id
		);

		if ( '' !== $cursor && function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() && function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan() ) {
			ESF_Instagram_Cache::set(
				$meta_key,
				array(
					'cursor' => $cursor,
				),
				$ttl,
				'account',
				$account_id
			);
		}

		if ( ! empty( $nodes ) && class_exists( 'ESF_Instagram_Local_Media' ) ) {
			$nodes = ESF_Instagram_Local_Media::prepare_nodes_for_display( $nodes, $account_id, $media_context, $per_page );
		}

		return array(
			'nodes'           => $nodes,
			'has_local_cache' => false,
		);
	}

	/**
	 * Fetch raw Graph hashtag media nodes for a hashtag feed.
	 *
	 * @param object              $feed_obj Feed object.
	 * @param AccountSummary|null $account  Linked account VO (for API auth).
	 * @param string              $media_context Display context.
	 *
	 * @return array{nodes:array<int,array<string,mixed>>,has_local_cache:bool,debug:array<string,mixed>,media_error?:\WP_Error|null,notice?:string|null}
	 */
	private function fetch_hashtag_raw_nodes( $feed_obj, ?AccountSummary $account, string $media_context = 'frontend' ): array {
		$result = array(
			'nodes'           => array(),
			'has_local_cache' => false,
			'debug'           => array(),
			'media_error'     => null,
			'notice'          => null,
		);

		$log_step = static function ( $step, $context = array() ) use ( &$result ) {
			$result['debug']['steps'][] = array_merge(
				array( 'step' => (string) $step ),
				is_array( $context ) ? $context : array()
			);
		};

		if (
			! function_exists( 'esf_instagram_has_instagram_plan' )
			|| ! esf_instagram_has_instagram_plan()
			|| ! class_exists( 'ESF_Instagram_API_Hashtag' )
			|| ! class_exists( 'ESF_Instagram_Cache' )
		) {
			$log_step( 'plan_or_class_unavailable' );
			return $result;
		}

		$tag = function_exists( 'esf_instagram_normalize_hashtag' )
			? esf_instagram_normalize_hashtag( isset( $feed_obj->source_id ) ? (string) $feed_obj->source_id : '' )
			: '';
		if ( '' === $tag ) {
			$log_step( 'empty_hashtag', array( 'source_id' => isset( $feed_obj->source_id ) ? (string) $feed_obj->source_id : '' ) );
			return $result;
		}

		$media_type = function_exists( 'esf_instagram_feed_hashtag_media_type' )
			? esf_instagram_feed_hashtag_media_type( $feed_obj )
			: 'top_media';

		$cache_key = function_exists( 'esf_instagram_hashtag_cache_key' )
			? esf_instagram_hashtag_cache_key( $tag, $media_type )
			: '';
		if ( '' === $cache_key ) {
			$log_step( 'invalid_cache_key', array( 'tag' => $tag ) );
			return $result;
		}

		$result['debug']['tag']        = $tag;
		$result['debug']['media_type'] = $media_type;
		$result['debug']['cache_key']  = $cache_key;

		$meta_key = $cache_key . '_meta';

		$settings      = isset( $feed_obj->settings ) && is_array( $feed_obj->settings ) ? $feed_obj->settings : array();
		$feed_cfg      = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();
		$per_page      = isset( $feed_cfg['per_page'] ) ? max( 1, min( 50, (int) $feed_cfg['per_page'] ) ) : 9;
		$media_context = in_array( $media_context, array( 'preview', 'frontend' ), true ) ? $media_context : 'frontend';
		$fetch_limit   = ESF_Instagram_API_Hashtag::resolve_initial_fetch_limit( $per_page );

		$cached = ESF_Instagram_Cache::get( $cache_key );
		if ( is_array( $cached ) && isset( $cached['data'] ) && is_array( $cached['data'] ) ) {
			$buffer        = array_values( $cached['data'] );
			$cached_cursor = isset( $cached['pagination']['cursor'] ) ? (string) $cached['pagination']['cursor'] : '';
			$nodes         = $this->prepare_hashtag_nodes_for_display( $buffer, $account, $media_context, $per_page, $tag, $media_type );
			$log_step( 'cache_hit', array( 'node_count' => count( $nodes ) ) );
			return array(
				'nodes'           => $nodes,
				'has_local_cache' => true,
				'debug'           => $result['debug'],
				'notice'          => $this->combine_preview_notices(
					array(
						$this->build_hashtag_api_limit_notice(
							count( $buffer ),
							$cached_cursor,
							$tag,
							$media_type,
							$per_page,
							$media_context
						),
						$this->build_hashtag_video_poster_notice( $buffer, $media_context ),
					)
				),
			);
		}

		$log_step( 'cache_miss', array( 'fetch_limit' => $fetch_limit ) );

		if ( null === $account || ! class_exists( 'ESF_Instagram_Account_Repository' ) ) {
			$log_step( 'missing_account' );
			return $result;
		}

		$account_id = $account->get_id();
		if ( $account_id <= 0 ) {
			$log_step( 'invalid_account_id' );
			return $result;
		}

		$result['debug']['account_id'] = $account_id;

		$account_row = ESF_Instagram_Account_Repository::get_instance()->get_by_id( $account_id );
		if ( ! $account_row || ! function_exists( 'esf_instagram_account_supports_hashtag' ) || ! esf_instagram_account_supports_hashtag( $account_row ) ) {
			$token_present = function_exists( 'esf_instagram_resolve_facebook_user_token' )
				? ( '' !== esf_instagram_resolve_facebook_user_token( $account_row ) )
				: false;
			$log_step(
				'account_unsupported_for_hashtag',
				array(
					'account_found' => (bool) $account_row,
					'token_present' => $token_present,
					'auth_source'   => $account_row && function_exists( 'esf_instagram_auth_source_for_account_row' )
						? esf_instagram_auth_source_for_account_row( $account_row )
						: '',
				)
			);
			return $result;
		}

		$meta_cache  = ESF_Instagram_Cache::get( $meta_key );
		$known_tag_id = is_array( $meta_cache ) && ! empty( $meta_cache['hashtag_id'] )
			? sanitize_text_field( (string) $meta_cache['hashtag_id'] )
			: '';

		$response = ESF_Instagram_API_Hashtag::get_instance()->fetch_hashtag_media_filled(
			$account_row,
			$tag,
			$fetch_limit,
			'',
			$known_tag_id,
			$media_type
		);

		if ( is_wp_error( $response ) ) {
			$log_step(
				'api_error',
				array(
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
				)
			);
			do_action( 'esf_instagram_media_fetch_failed', $response, $feed_obj, $account_id );
			$result['media_error'] = $response;
			return $result;
		}

		$nodes      = isset( $response['data'] ) && is_array( $response['data'] ) ? array_values( $response['data'] ) : array();
		$hashtag_id = isset( $response['hashtag_id'] ) ? sanitize_text_field( (string) $response['hashtag_id'] ) : '';
		$log_step( 'api_success', array( 'node_count' => count( $nodes ), 'fetch_limit' => $fetch_limit ) );
		$pagination = isset( $response['pagination'] ) && is_array( $response['pagination'] ) ? $response['pagination'] : array();
		$cursor     = isset( $pagination['cursor'] ) ? sanitize_text_field( (string) $pagination['cursor'] ) : '';
		$ttl        = esf_instagram_get_cache_ttl_secs( $feed_obj, $account_id );

		ESF_Instagram_Cache::set(
			$cache_key,
			array(
				'data'       => $nodes,
				'pagination' => $pagination,
			),
			$ttl,
			'api',
			$account_id
		);
		$log_step( 'cache_written', array( 'node_count' => count( $nodes ), 'ttl' => $ttl ) );

		ESF_Instagram_Cache::set(
			$meta_key,
			array(
				'cursor'     => $cursor,
				'hashtag_id' => $hashtag_id,
				'account_id' => $account_id,
			),
			$ttl,
			'api',
			$account_id
		);

		$available_count = count( $nodes );
		$video_notice    = $this->build_hashtag_video_poster_notice( $nodes, $media_context );
		$nodes           = $this->prepare_hashtag_nodes_for_display( $nodes, $account, $media_context, $per_page, $tag, $media_type );

		return array(
			'nodes'           => $nodes,
			'has_local_cache' => false,
			'debug'           => $result['debug'],
			'notice'          => $this->combine_preview_notices(
				array(
					$this->build_hashtag_api_limit_notice(
						$available_count,
						$cursor,
						$tag,
						$media_type,
						$per_page,
						$media_context
					),
					$video_notice,
				)
			),
		);
	}

	/**
	 * Build an admin-only notice when Instagram caps a hashtag's results.
	 *
	 * The Graph `top_media`/`recent_media` edges return a limited, ranked set
	 * per hashtag and the pagination cursor often runs out early. When the
	 * cursor is exhausted and fewer than a full page were returned, the short
	 * feed is an Instagram API limit rather than a plugin bug — surface a calm
	 * note in the dashboard preview so admins understand the cause.
	 *
	 * @since 6.9.0
	 *
	 * @param int    $available_count Nodes Instagram returned for this tag/edge.
	 * @param string $cursor          Remaining pagination cursor ('' when exhausted).
	 * @param string $tag             Normalized hashtag (no #).
	 * @param string $media_type      Hashtag edge ('top_media'|'recent_media').
	 * @param int    $per_page        Requested page size.
	 * @param string $media_context   Render context ('preview'|'frontend').
	 * @return string|null Notice message, or null when there is nothing to flag.
	 */
	private function build_hashtag_api_limit_notice( int $available_count, string $cursor, string $tag, string $media_type, int $per_page, string $media_context ): ?string {
		unset( $cursor );
		if ( 'preview' !== $media_context ) {
			return null;
		}
		if ( $available_count <= 0 || $available_count >= $per_page ) {
			return null;
		}

		$media_type = esf_instagram_normalize_hashtag_media_type( $media_type );

		if ( 'recent_media' === $media_type ) {
			return sprintf(
				/* translators: 1: posts returned, 2: page size, 3: hashtag without # */
				__( 'Showing %1$d of %2$d recent posts for #%3$s. Instagram mostly returns hashtag posts from the last 24 hours.', 'easy-facebook-likebox' ),
				$available_count,
				$per_page,
				$tag
			);
		}

		return sprintf(
			/* translators: 1: posts returned, 2: page size, 3: hashtag without # */
			__( 'Showing %1$d of %2$d posts for #%3$s. Instagram limits how many hashtag posts it returns.', 'easy-facebook-likebox' ),
			$available_count,
			$per_page,
			$tag
		);
	}

	/**
	 * Build an admin-only notice when hashtag videos have no poster image.
	 *
	 * @param array<int,array<string,mixed>> $nodes         Raw Graph nodes (pre-localize).
	 * @param string                         $media_context Render context.
	 * @return string|null
	 */
	private function build_hashtag_video_poster_notice( array $nodes, string $media_context ): ?string {
		if ( 'preview' !== $media_context ) {
			return null;
		}

		$missing = $this->count_hashtag_videos_without_poster( $nodes );
		if ( $missing <= 0 ) {
			return null;
		}

		if ( 1 === $missing ) {
			return __( 'One video has no thumbnail — Instagram\'s hashtag API does not include video posters, so a placeholder is shown instead.', 'easy-facebook-likebox' );
		}

		return sprintf(
			/* translators: %d: number of videos without a poster image */
			__( '%d videos have no thumbnail — Instagram\'s hashtag API does not include video posters, so placeholders are shown instead.', 'easy-facebook-likebox' ),
			$missing
		);
	}

	/**
	 * Count video nodes that lack a `thumbnail_url` from the hashtag API.
	 *
	 * @param array<int,array<string,mixed>> $nodes Graph nodes.
	 * @return int
	 */
	private function count_hashtag_videos_without_poster( array $nodes ): int {
		$missing = 0;

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$missing += $this->count_videos_without_poster_in_node( $node );
		}

		return $missing;
	}

	/**
	 * Count missing video posters within a single node (including carousel children).
	 *
	 * @param array<string,mixed> $node Graph node.
	 * @return int
	 */
	private function count_videos_without_poster_in_node( array $node ): int {
		$missing    = 0;
		$media_type = strtoupper( isset( $node['media_type'] ) ? (string) $node['media_type'] : '' );
		$thumb      = isset( $node['thumbnail_url'] ) ? (string) $node['thumbnail_url'] : '';

		if (
			in_array( $media_type, array( 'VIDEO', 'REELS', 'REEL' ), true )
			&& '' === $thumb
		) {
			++$missing;
		}

		if ( 'CAROUSEL_ALBUM' === $media_type && isset( $node['children']['data'] ) && is_array( $node['children']['data'] ) ) {
			foreach ( $node['children']['data'] as $child ) {
				if ( is_array( $child ) ) {
					$missing += $this->count_videos_without_poster_in_node( $child );
				}
			}
		}

		return $missing;
	}

	/**
	 * Merge multiple admin preview notices into one payload string.
	 *
	 * @param array<int,string|null> $notices Notice fragments.
	 * @return string|null
	 */
	private function combine_preview_notices( array $notices ): ?string {
		$lines = array();
		foreach ( $notices as $notice ) {
			if ( is_string( $notice ) && '' !== trim( $notice ) ) {
				$lines[] = trim( $notice );
			}
		}

		if ( empty( $lines ) ) {
			return null;
		}

		return implode( "\n\n", $lines );
	}

	/**
	 * Prepare hashtag nodes for display (media localization).
	 *
	 * Video poster fallbacks are handled centrally in {@see PostMapper} so every
	 * render path (preview, frontend, load-more) behaves identically.
	 *
	 * @param array<int,array<string,mixed>> $nodes         Raw nodes.
	 * @param AccountSummary|null            $account       Linked account.
	 * @param string                         $media_context Display context.
	 * @param int                            $per_page      Items needed.
	 * @param string                         $hashtag       Normalized hashtag.
	 * @param string                         $media_type    Hashtag edge ('top_media'|'recent_media').
	 * @return array<int,array<string,mixed>>
	 */
	private function prepare_hashtag_nodes_for_display( array $nodes, ?AccountSummary $account, string $media_context, int $per_page, string $hashtag = '', string $media_type = 'top_media' ): array {
		$account_id = null !== $account ? $account->get_id() : 0;

		$media_type_suffix = function_exists( 'esf_instagram_normalize_hashtag_media_type' )
			&& 'recent_media' === esf_instagram_normalize_hashtag_media_type( $media_type )
			? 'recent'
			: 'top';

		$hashtag_folder = '' !== $hashtag ? $hashtag . '_' . $media_type_suffix : '';

		if ( $account_id > 0 && class_exists( 'ESF_Instagram_Local_Media' ) ) {
			$nodes = ESF_Instagram_Local_Media::prepare_nodes_for_display( $nodes, $account_id, $media_context, $per_page, $hashtag_folder );
		}

		return $nodes;
	}
}
