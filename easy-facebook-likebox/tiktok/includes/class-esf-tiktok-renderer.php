<?php
/**
 * TikTok feed renderer.
 *
 * Thin orchestrator: load feed/account, fetch+cache posts, map to VOs,
 * delegate HTML to shared EasySocialFeed\Layouts\Renderer.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok
 * @since 6.9.0
 */

use EasySocialFeed\Layouts\PreviewResponseBuilder;
use EasySocialFeed\Layouts\Primitives\MissingFeedState;
use EasySocialFeed\Layouts\Renderer as SharedRenderer;
use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\Layouts\ValueObjects\MissingFeedContext;
use EasySocialFeed\TikTok\Layouts\AccountMapper;
use EasySocialFeed\TikTok\Layouts\PostMapper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_TikTok_Renderer
 *
 * @since 6.9.0
 */
class ESF_TikTok_Renderer {

	/**
	 * Module slug.
	 *
	 * @var string
	 */
	const MODULE = 'tiktok';

	/**
	 * Whether wrapper attribute filter is registered.
	 *
	 * @var bool
	 */
	private static $wrapper_filter_registered = false;

	/**
	 * Max posts stored in the account-scoped cache row.
	 *
	 * @var int
	 */
	const MAX_CACHE_POSTS = 50;

	/**
	 * Render a feed by ID.
	 *
	 * @param int   $feed_id             Feed ID.
	 * @param array $settings_override   Optional settings merge (preview).
	 * @param bool  $enqueue_assets      Enqueue CSS/JS.
	 * @param int   $account_id_override Optional account override.
	 * @return string
	 */
	public function render( $feed_id, $settings_override = array(), $enqueue_assets = true, $account_id_override = 0 ) {
		$rendered = $this->render_internal( (int) $feed_id, is_array( $settings_override ) ? $settings_override : array(), (int) $account_id_override );

		if ( '' === $rendered['html'] ) {
			return '';
		}

		if ( $enqueue_assets && null !== $rendered['definition'] ) {
			( new SharedRenderer() )->enqueue_assets( $rendered['definition'] );
		}

		$html = $rendered['html'];
		if ( ! empty( $rendered['admin_notice_html'] ) ) {
			$html = (string) $rendered['admin_notice_html'] . $html;
		}

		return $html;
	}

	/**
	 * Build REST preview payload.
	 *
	 * @param int   $feed_id             Feed ID.
	 * @param array $settings_override   Settings override.
	 * @param int   $account_id_override Account override.
	 * @return array<string,mixed>
	 */
	public function build_preview_payload( $feed_id, $settings_override = array(), $account_id_override = 0 ) {
		$rendered = $this->render_internal(
			(int) $feed_id,
			is_array( $settings_override ) ? $settings_override : array(),
			(int) $account_id_override,
			array( 'media_context' => 'preview' )
		);

		$payload = PreviewResponseBuilder::build( $rendered['renderer_output'], $rendered['has_local_cache'] );
		if ( isset( $rendered['notice'] ) && is_string( $rendered['notice'] ) && '' !== $rendered['notice'] ) {
			$payload['notice'] = array( 'message' => $rendered['notice'] );
		}

		return $payload;
	}

	/**
	 * Resolve AccountSummary for a live or disconnected account.
	 *
	 * @since 6.7.9
	 *
	 * @param int         $account_id  Internal account id.
	 * @param object|null $account_row Live account row when connected.
	 * @return AccountSummary|null
	 */
	public static function resolve_tiktok_account( $account_id, $account_row = null ): ?AccountSummary {
		$account_id = (int) $account_id;
		if ( $account_id <= 0 ) {
			return null;
		}

		if ( is_object( $account_row ) ) {
			$account = AccountMapper::from_row( $account_row );
			if ( null !== $account && class_exists( 'ESF_TikTok_Local_Media' ) ) {
				$account = ESF_TikTok_Local_Media::with_localized_logo( $account, $account_row );
			}
			return $account;
		}

		$snapshot = class_exists( 'ESF_TikTok_Cache' )
			? ESF_TikTok_Cache::get_account_profile_snapshot( $account_id )
			: null;
		if ( ! is_array( $snapshot ) || empty( $snapshot ) ) {
			return null;
		}

		$account = AccountMapper::from_row( $snapshot );
		if ( null !== $account && class_exists( 'ESF_TikTok_Local_Media' ) ) {
			$account = ESF_TikTok_Local_Media::with_localized_logo( $account, (object) $snapshot );
		}

		return $account;
	}

	/**
	 * Whether a feed still has cached video nodes (including expired rows).
	 *
	 * @since 6.7.9
	 *
	 * @param object $feed_obj Feed object.
	 * @return bool
	 */
	public static function feed_has_posts_cache( $feed_obj ): bool {
		if ( ! class_exists( 'ESF_TikTok_Cache' ) || ! is_object( $feed_obj ) ) {
			return false;
		}

		$account_id = isset( $feed_obj->account_id ) ? (int) $feed_obj->account_id : 0;
		if ( $account_id <= 0 ) {
			return false;
		}

		$cache_key = ESF_TikTok_Cache::posts_cache_key( $account_id );
		$cached    = ESF_TikTok_Cache::get( $cache_key );
		if ( ! is_array( $cached ) ) {
			$cached = ESF_TikTok_Cache::get_stale( $cache_key );
		}

		return is_array( $cached )
			&& isset( $cached['posts'] )
			&& is_array( $cached['posts'] )
			&& ! empty( $cached['posts'] );
	}

	/**
	 * Base CSS URL for dashboard preview iframes.
	 *
	 * @return string
	 */
	public function get_base_css_url() {
		return ESF_TIKTOK_URL . 'frontend/assets/css/esf-tiktok-feed.css';
	}

	/**
	 * Layout CSS URL for a layout slug.
	 *
	 * @param string $layout_type Layout slug.
	 * @return string
	 */
	public function get_layout_css_url( $layout_type ) {
		$layout_type = sanitize_key( (string) $layout_type );
		if ( '' === $layout_type ) {
			$layout_type = 'grid';
		}
		$file = str_replace( '_', '-', $layout_type );
		return ESF_TIKTOK_URL . 'frontend/assets/css/layouts/' . $file . '.css';
	}

	/**
	 * Warm / return posts for a feed (used by cron and Social Wall).
	 *
	 * @since 6.9.0
	 *
	 * @param int                 $feed_id Feed ID.
	 * @param array<string,mixed> $args    Optional: limit (int), media_context (preview|frontend), cache_only (bool).
	 * @return array<int,\EasySocialFeed\Layouts\ValueObjects\Post>
	 */
	public function get_posts_for_feed_by_id( $feed_id, $args = array() ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 || ! class_exists( 'ESF_TikTok_Feed_Repository' ) ) {
			return array();
		}

		$args          = is_array( $args ) ? $args : array();
		$media_context = isset( $args['media_context'] ) && 'preview' === $args['media_context']
			? 'preview'
			: 'frontend';
		$cache_only    = ! empty( $args['cache_only'] );

		$feed_row = ESF_TikTok_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return array();
		}

		$saved    = isset( $feed_row->settings ) ? json_decode( (string) $feed_row->settings, true ) : array();
		$settings = ESF_TikTok_Feed_Repository::merge_settings_with_defaults( is_array( $saved ) ? $saved : array() );
		$feed_obj = (object) array(
			'id'         => (int) $feed_row->id,
			'account_id' => (int) $feed_row->account_id,
			'feed_type'  => (string) $feed_row->feed_type,
			'settings'   => $settings,
		);

		$account_row         = class_exists( 'ESF_TikTok_Account_Repository' )
			? ESF_TikTok_Account_Repository::get_instance()->get_by_id( (int) $feed_obj->account_id )
			: null;
		$account             = $account_row ? AccountMapper::from_row( $account_row ) : null;
		$source_disconnected = $cache_only || ( $feed_obj->account_id > 0 && null === $account_row );

		$result = $this->fetch_posts( $feed_obj, $account, $media_context, $source_disconnected );
		$posts  = isset( $result['posts'] ) && is_array( $result['posts'] ) ? $result['posts'] : array();

		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 0;
		if ( $limit > 0 && count( $posts ) > $limit ) {
			$posts = array_slice( $posts, 0, $limit );
		}

		return $posts;
	}

	/**
	 * Force-refresh one account's TikTok posts from the API.
	 *
	 * Existing cache is kept when the API errors or returns an empty payload.
	 *
	 * @since 6.8.2
	 *
	 * @param int $feed_id Any feed ID belonging to the account (drives settings/per_page).
	 * @return true|WP_Error
	 */
	public function force_refresh_account_posts( $feed_id ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 || ! class_exists( 'ESF_TikTok_Feed_Repository' ) ) {
			return new WP_Error(
				'invalid_feed',
				__( 'Invalid TikTok feed.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$feed_row = ESF_TikTok_Feed_Repository::get_instance()->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return new WP_Error(
				'feed_not_found',
				__( 'TikTok feed not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$saved    = isset( $feed_row->settings ) ? json_decode( (string) $feed_row->settings, true ) : array();
		$settings = ESF_TikTok_Feed_Repository::merge_settings_with_defaults( is_array( $saved ) ? $saved : array() );
		$feed_obj = (object) array(
			'id'         => (int) $feed_row->id,
			'account_id' => (int) $feed_row->account_id,
			'feed_type'  => (string) $feed_row->feed_type,
			'settings'   => $settings,
		);

		$account_id = (int) $feed_obj->account_id;
		if ( $account_id <= 0 ) {
			return new WP_Error(
				'invalid_account',
				__( 'Invalid TikTok account.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$feed_cfg = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();
		$per_page = isset( $feed_cfg['per_page'] ) ? max( 1, min( 50, (int) $feed_cfg['per_page'] ) ) : 9;

		$fetched = $this->fetch_and_cache_posts( $feed_obj, $account_id, $per_page, true );
		if ( isset( $fetched['media_error'] ) && $fetched['media_error'] instanceof WP_Error ) {
			return $fetched['media_error'];
		}

		$posts = isset( $fetched['posts'] ) && is_array( $fetched['posts'] ) ? $fetched['posts'] : array();
		if ( empty( $posts ) ) {
			return new WP_Error(
				'empty_timeline',
				__( 'No TikTok videos were returned. Existing cache was kept.', 'easy-facebook-likebox' ),
				array( 'status' => 502 )
			);
		}

		return true;
	}

	/**
	 * Shared internal pipeline.
	 *
	 * @param int   $feed_id             Feed ID.
	 * @param array $settings_override   Overrides.
	 * @param int   $account_id_override Account override.
	 * @param array $render_options      Options.
	 * @return array<string,mixed>
	 */
	private function render_internal( int $feed_id, array $settings_override, int $account_id_override, array $render_options = array() ): array {
		$empty = array(
			'html'              => '',
			'renderer_output'   => array(
				'html'        => '',
				'assets'      => array(
					'base_css'   => null,
					'layout_css' => null,
					'layout_js'  => null,
				),
				'inline_css'  => '',
				'layout_slug' => '',
			),
			'definition'        => null,
			'has_local_cache'   => false,
			'admin_notice_html' => '',
			'notice'            => null,
		);

		if ( $feed_id <= 0 ) {
			return $empty;
		}

		self::ensure_wrapper_attributes_filter();

		if ( ! class_exists( 'ESF_TikTok_Feed_Repository' ) || ! class_exists( 'ESF_TikTok_Account_Repository' ) ) {
			return $empty;
		}

		$feed_repo = ESF_TikTok_Feed_Repository::get_instance();
		$feed_row  = $feed_repo->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return $this->build_missing_feed_response( $feed_id );
		}

		$saved = isset( $feed_row->settings ) ? json_decode( (string) $feed_row->settings, true ) : array();
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$settings = ESF_TikTok_Feed_Repository::merge_settings_with_defaults( $saved );
		if ( ! empty( $settings_override ) ) {
			$settings = array_replace_recursive( $settings, $settings_override );
		}

		$feed_obj = (object) array(
			'id'         => (int) $feed_row->id,
			'name'       => isset( $feed_row->name ) ? (string) $feed_row->name : '',
			'account_id' => isset( $feed_row->account_id ) ? (int) $feed_row->account_id : 0,
			'feed_type'  => isset( $feed_row->feed_type ) ? (string) $feed_row->feed_type : 'user_videos',
			'source_id'  => isset( $feed_row->source_id ) ? (string) $feed_row->source_id : '',
			'settings'   => $settings,
		);

		if ( $account_id_override > 0 ) {
			$feed_obj->account_id = $account_id_override;
		}

		$account_row         = ESF_TikTok_Account_Repository::get_instance()->get_by_id( (int) $feed_obj->account_id );
		$account             = self::resolve_tiktok_account( (int) $feed_obj->account_id, $account_row );
		$source_disconnected = class_exists( 'ESF_Feed_Disconnected_Cache' )
			? ESF_Feed_Disconnected_Cache::account_source_is_disconnected( $feed_row, $account_row )
			: ( $feed_obj->account_id > 0 && null === $account_row );

		$media_context = isset( $render_options['media_context'] ) && 'preview' === $render_options['media_context']
			? 'preview'
			: 'frontend';

		$fetch_result = $this->fetch_posts( $feed_obj, $account, $media_context, $source_disconnected );
		$posts        = $fetch_result['posts'];

		$layout_overrides = $settings_override;
		if (
			isset( $fetch_result['media_error'] )
			&& $fetch_result['media_error'] instanceof WP_Error
			&& empty( $posts )
		) {
			$error_overrides  = $this->build_media_error_layout_overrides( $fetch_result['media_error'] );
			$layout_overrides = array_replace_recursive(
				is_array( $layout_overrides ) ? $layout_overrides : array(),
				$error_overrides
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
			&& $source_disconnected
			&& ! empty( $fetch_result['has_local_cache'] )
			&& class_exists( 'ESF_Feed_Disconnected_Cache' )
		) {
			$cache_pinned = ESF_Feed_Disconnected_Cache::is_feed_cache_effectively_pinned(
				self::MODULE,
				(int) $feed_row->id,
				! empty( $settings['feed']['cache_pinned'] )
			);
			$notice       = ESF_Feed_Disconnected_Cache::get_notice_message( $cache_pinned );
		}

		$admin_notice_html = '';
		if ( $source_disconnected && ! empty( $fetch_result['has_local_cache'] ) && class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
			$cache_pinned      = ESF_Feed_Disconnected_Cache::is_feed_cache_effectively_pinned(
				self::MODULE,
				(int) $feed_row->id,
				! empty( $settings['feed']['cache_pinned'] )
			);
			$admin_notice_html = ESF_Feed_Disconnected_Cache::get_admin_notice_html(
				self::MODULE,
				(int) $feed_row->id,
				array(
					'cache_pinned' => $cache_pinned,
				)
			);
		}

		return array(
			'html'              => isset( $renderer_output['html'] ) ? (string) $renderer_output['html'] : '',
			'renderer_output'   => $renderer_output,
			'definition'        => $definition,
			'has_local_cache'   => ! empty( $fetch_result['has_local_cache'] ),
			'admin_notice_html' => $admin_notice_html,
			'notice'            => $notice,
		);
	}

	/**
	 * Fetch and map posts (cache-aware).
	 *
	 * @param object              $feed_obj Feed.
	 * @param AccountSummary|null $account  Account VO.
	 * @param string              $media_context Context.
	 * @param bool                $source_disconnected Whether the linked account row is missing.
	 * @return array{posts:array<int,\EasySocialFeed\Layouts\ValueObjects\Post>,has_local_cache:bool,media_error:\WP_Error|null,notice?:string|null}
	 */
	private function fetch_posts( $feed_obj, ?AccountSummary $account, string $media_context = 'frontend', bool $source_disconnected = false ): array {
		$result = array(
			'posts'           => array(),
			'has_local_cache' => false,
			'media_error'     => null,
		);

		$preset = array(
			'posts'           => array(),
			'has_local_cache' => false,
		);

		/**
		 * Filter the TikTok post payload before cache/API fetch.
		 *
		 * Returning posts short-circuits the API fetch. Prefer raw API video
		 * nodes in `posts` (same shape as the account videos cache).
		 *
		 * @since 6.16.0
		 *
		 * @param array               $value         Default empty payload.
		 * @param object              $feed_obj      Feed object.
		 * @param AccountSummary|null $account       Owning account.
		 * @param string              $media_context Display context (`frontend`|`preview`).
		 */
		$payload = apply_filters( 'esf_tiktok_render_posts_payload', $preset, $feed_obj, $account, $media_context );

		$nodes           = array();
		$short_circuited = false;
		$account_id      = $account instanceof AccountSummary
			? $account->get_id()
			: ( is_object( $feed_obj ) && isset( $feed_obj->account_id ) ? (int) $feed_obj->account_id : 0 );

		$settings = isset( $feed_obj->settings ) && is_array( $feed_obj->settings ) ? $feed_obj->settings : array();
		$feed_cfg = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();
		$per_page = isset( $feed_cfg['per_page'] ) ? max( 1, min( 50, (int) $feed_cfg['per_page'] ) ) : 10;

		if ( function_exists( 'esf_resolve_render_payload_override' ) ) {
			$override = esf_resolve_render_payload_override( $payload, 'posts' );
			if ( $override['short_circuited'] ) {
				$nodes                     = $override['items'];
				$result['has_local_cache'] = ! empty( $override['has_local_cache'] ) || ! empty( $nodes );
				$short_circuited           = true;
			}
		}

		if ( ! $short_circuited ) {
			if ( $account_id <= 0 || ! class_exists( 'ESF_TikTok_Cache' ) ) {
				return $result;
			}

			$account_row = ESF_TikTok_Account_Repository::get_instance()->get_by_id( $account_id );
			if (
				! $source_disconnected
				&& $account_row
				&& function_exists( 'esf_tiktok_account_needs_reconnect' )
				&& esf_tiktok_account_needs_reconnect( $account_row )
			) {
				$result['media_error'] = new WP_Error(
					'reconnect_required',
					class_exists( 'ESF_TikTok_API_Error' )
						? ESF_TikTok_API_Error::reconnect_required_message()
						: __( 'TikTok access was revoked or expired. Please reconnect your TikTok account.', 'easy-facebook-likebox' )
				);
				// Still try cache below so existing local posts can show until cache is cleared.
			}

			$cache_key = ESF_TikTok_Cache::posts_cache_key( $account_id );
			$cached    = ESF_TikTok_Cache::get( $cache_key );
			if ( ! is_array( $cached ) && method_exists( 'ESF_TikTok_Cache', 'get_stale' ) ) {
				$cached = ESF_TikTok_Cache::get_stale( $cache_key );
			}

			if ( is_array( $cached ) && isset( $cached['posts'] ) && is_array( $cached['posts'] ) ) {
				$nodes                     = $cached['posts'];
				$result['has_local_cache'] = true;
				if ( $source_disconnected && class_exists( 'ESF_Feed_Disconnected_Cache' ) ) {
					$result['notice'] = ESF_Feed_Disconnected_Cache::get_notice_message( ! empty( $feed_cfg['cache_pinned'] ) );
				}
			} elseif ( ! $source_disconnected ) {
				$fetched = $this->fetch_and_cache_posts( $feed_obj, $account_id, $per_page );
				$nodes   = isset( $fetched['posts'] ) && is_array( $fetched['posts'] ) ? $fetched['posts'] : array();
				if ( isset( $fetched['media_error'] ) && $fetched['media_error'] instanceof WP_Error ) {
					$result['media_error'] = $fetched['media_error'];
				}
			}
		}

		if ( class_exists( 'ESF_TikTok_Local_Media' ) && array() !== $nodes ) {
			$nodes = ESF_TikTok_Local_Media::prepare_nodes_for_display(
				$nodes,
				$account_id,
				$per_page,
				$media_context
			);
			if ( ! is_array( $nodes ) ) {
				$nodes = array();
			}

			// Background warm for anything still missing locally (does not block render).
			$feed_id = isset( $feed_obj->id ) ? (int) $feed_obj->id : 0;
			if (
				$feed_id > 0
				&& ESF_TikTok_Local_Media::visible_page_needs_media_recovery( $nodes, $account_id, $per_page )
			) {
				ESF_TikTok_Local_Media::schedule_warm_for_feed( $feed_id, $account_id );
			}
		}

		// Map the full cached set (not just the visible page) so the shared
		// layout's `get_display_state()` can accurately detect when there are
		// more posts than `per_page` and render the Pro Load More button.
		$posts = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$post = PostMapper::from_api_node( $node, $account );
			if ( null !== $post ) {
				$posts[] = $post;
			}
		}

		$result['posts'] = $posts;
		return $result;
	}

	/**
	 * Call TikTok API and write account-scoped cache.
	 *
	 * @param object $feed_obj           Feed.
	 * @param int    $account_id         Account ID.
	 * @param int    $per_page           Desired page size (fetch at least this many).
	 * @param bool   $preserve_on_empty  When true, skip writing empty API payloads.
	 * @return array{posts:array<int,array<string,mixed>>,media_error:\WP_Error|null}
	 */
	private function fetch_and_cache_posts( $feed_obj, int $account_id, int $per_page, bool $preserve_on_empty = false ): array {
		$out = array(
			'posts'       => array(),
			'media_error' => null,
		);

		if ( ! class_exists( 'ESF_TikTok_Token_Manager' ) || ! class_exists( 'ESF_TikTok_API_Service' ) ) {
			return $out;
		}

		$account_row = ESF_TikTok_Account_Repository::get_instance()->get_by_id( $account_id );
		if ( ! $account_row ) {
			$out['media_error'] = new WP_Error(
				'account_missing',
				__( 'TikTok account not found. Connect a TikTok account in Easy Social Feed → TikTok → Accounts.', 'easy-facebook-likebox' )
			);
			return $out;
		}

		if (
			function_exists( 'esf_tiktok_account_needs_reconnect' )
			&& esf_tiktok_account_needs_reconnect( $account_row )
		) {
			$out['media_error'] = new WP_Error(
				'reconnect_required',
				ESF_TikTok_API_Error::reconnect_required_message()
			);
			return $out;
		}

		$token = ESF_TikTok_Token_Manager::get_instance()->get_valid_access_token( $account_id );
		if ( ! $token ) {
			$out['media_error'] = new WP_Error(
				'reconnect_required',
				ESF_TikTok_API_Error::reconnect_required_message()
			);
			return $out;
		}

		$count  = min( self::MAX_CACHE_POSTS, max( $per_page, 20 ) );
		$api    = ESF_TikTok_API_Service::get_instance();
		$result = $api->fetch_user_videos( $token, $count );
		if ( is_wp_error( $result ) ) {
			$this->handle_posts_fetch_failure( $result, $feed_obj, $account_id );
			$out['media_error'] = $this->normalize_posts_fetch_error( $result );
			return $out;
		}

		$posts = array();
		if ( isset( $result['videos'] ) && is_array( $result['videos'] ) ) {
			$posts = array_values( $result['videos'] );
		} elseif ( isset( $result['posts'] ) && is_array( $result['posts'] ) ) {
			$posts = array_values( $result['posts'] );
		}

		if ( $preserve_on_empty && empty( $posts ) ) {
			$out['media_error'] = new WP_Error(
				'empty_timeline',
				__( 'No TikTok videos were returned. Existing cache was kept.', 'easy-facebook-likebox' ),
				array( 'status' => 502 )
			);
			return $out;
		}

		$meta = array();
		if ( isset( $result['meta'] ) && is_array( $result['meta'] ) ) {
			$meta = $result['meta'];
		} else {
			$meta = array(
				'cursor'   => isset( $result['cursor'] ) ? $result['cursor'] : '',
				'has_more' => ! empty( $result['has_more'] ),
			);
		}
		$ttl = (int) esf_get_tiktok_settings( 'cache_duration' );
		if ( $ttl <= 0 ) {
			$ttl = ESF_TikTok_Cache::DEFAULT_TTL;
		}

		ESF_TikTok_Cache::set(
			ESF_TikTok_Cache::posts_cache_key( $account_id ),
			array(
				'posts' => $posts,
				'meta'  => $meta,
			),
			$ttl,
			'account',
			$account_id
		);

		// Mirror Instagram: keep a dedicated meta row for pagination / warm bookkeeping.
		ESF_TikTok_Cache::set(
			ESF_TikTok_Cache::posts_meta_cache_key( $account_id ),
			$meta,
			$ttl,
			'account',
			$account_id
		);

		$out['posts'] = $posts;
		return $out;
	}

	/**
	 * Mark account + notify when TikTok says access is gone.
	 *
	 * @param WP_Error $error      Fetch error.
	 * @param object   $feed_obj   Feed object.
	 * @param int      $account_id Account id.
	 * @return void
	 */
	private function handle_posts_fetch_failure( WP_Error $error, $feed_obj, int $account_id ): void {
		/**
		 * Fires when a TikTok posts fetch fails.
		 *
		 * @param WP_Error $error      Error.
		 * @param object   $feed_obj   Feed.
		 * @param int      $account_id Account id.
		 */
		do_action( 'esf_tiktok_posts_fetch_failed', $error, $feed_obj, $account_id );

		if ( $account_id <= 0 || ! class_exists( 'ESF_TikTok_API_Error' ) ) {
			return;
		}
		if ( ! ESF_TikTok_API_Error::requires_reconnect( $error ) ) {
			return;
		}

		$status = 'token_invalid' === $error->get_error_code() ? 'invalid' : 'expired';
		ESF_TikTok_Account_Repository::get_instance()->update_status( $account_id, $status );
		if ( class_exists( 'ESF_TikTok_Token_Notifications' ) ) {
			ESF_TikTok_Token_Notifications::maybe_notify_reconnect_required( $account_id );
		}
	}

	/**
	 * Prefer a clear reconnect message for auth failures.
	 *
	 * @param WP_Error $error Raw API error.
	 * @return WP_Error
	 */
	private function normalize_posts_fetch_error( WP_Error $error ): WP_Error {
		if ( class_exists( 'ESF_TikTok_API_Error' ) && ESF_TikTok_API_Error::requires_reconnect( $error ) ) {
			return new WP_Error(
				$error->get_error_code() ? $error->get_error_code() : 'reconnect_required',
				ESF_TikTok_API_Error::reconnect_required_message(),
				$error->get_error_data()
			);
		}
		return $error;
	}

	/**
	 * Build layout overrides for an empty feed caused by a fetch/auth error.
	 *
	 * Admins get reconnect guidance + dashboard link; visitors get a short
	 * unavailable notice. Reconnect failures also hide the account header —
	 * that chrome is served from local DB profile fields and would otherwise
	 * look “healthy” while posts cannot load.
	 *
	 * @param WP_Error $error Fetch error.
	 * @return array<string,mixed>
	 */
	private function build_media_error_layout_overrides( WP_Error $error ): array {
		$can_manage   = function_exists( 'esf_tiktok_user_can_manage' ) && esf_tiktok_user_can_manage();
		$is_reconnect = class_exists( 'ESF_TikTok_API_Error' )
			&& ESF_TikTok_API_Error::requires_reconnect( $error );

		if ( $is_reconnect ) {
			$message = $can_manage
				? ESF_TikTok_API_Error::reconnect_required_message_admin()
				: ESF_TikTok_API_Error::reconnect_required_message_public();
		} elseif ( $can_manage ) {
			$message = $error->get_error_message();
		} else {
			$message = ESF_TikTok_API_Error::feed_unavailable_message_public();
		}

		$overrides = array(
			'feed' => array(
				'media_error_message' => $message,
			),
		);

		if ( $is_reconnect ) {
			$overrides['header'] = array(
				'show' => false,
			);
		}

		if ( $can_manage && $is_reconnect ) {
			$overrides['feed']['media_error_action_url']   = admin_url(
				'admin.php?page=esf-tiktok&screen=accounts&esf_tt_reconnect=1'
			);
			$overrides['feed']['media_error_action_label'] = __( 'Reconnect in TikTok Accounts', 'easy-facebook-likebox' );
		}

		return $overrides;
	}

	/**
	 * Missing-feed placeholder.
	 *
	 * @param int $feed_id Feed ID.
	 * @return array<string,mixed>
	 */
	private function build_missing_feed_response( int $feed_id ): array {
		$post_id    = function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0;
		$can_manage = function_exists( 'esf_tiktok_user_can_manage' ) && esf_tiktok_user_can_manage();
		$context    = new MissingFeedContext(
			self::MODULE,
			$feed_id,
			'esf_tiktok_feed',
			$post_id,
			$can_manage,
			MissingFeedState::default_public_message( self::MODULE ),
			admin_url( 'admin.php?page=esf-tiktok&screen=feeds' ),
			'/tiktok/feeds/{id}/preview',
			'/tiktok/feeds'
		);
		$html       = MissingFeedState::render( $context );
		return array(
			'html'              => $html,
			'renderer_output'   => array(
				'html'        => $html,
				'assets'      => array(
					'base_css'   => null,
					'layout_css' => null,
					'layout_js'  => null,
				),
				'inline_css'  => '',
				'layout_slug' => '',
			),
			'definition'        => null,
			'has_local_cache'   => false,
			'admin_notice_html' => '',
			'notice'            => null,
		);
	}

	/**
	 * Register hover overlay data-attribute filter once.
	 *
	 * @since 6.16.0
	 * @return void
	 */
	private static function ensure_wrapper_attributes_filter(): void {
		if ( self::$wrapper_filter_registered ) {
			return;
		}
		self::$wrapper_filter_registered = true;
		add_filter( 'esf_tiktok_layout_render_wrapper_attributes', array( __CLASS__, 'filter_wrapper_attributes' ), 10, 3 );
	}

	/**
	 * Global hover overlay / play-icon data attributes on `.esf-tiktok-feed`.
	 *
	 * @since 6.16.0
	 *
	 * @param array                                                      $attrs      Wrapper attributes.
	 * @param object                                                     $feed       Feed record.
	 * @param \EasySocialFeed\Layouts\ValueObjects\LayoutDefinition|null $definition Layout definition (unused).
	 * @return array<string,string>
	 */
	public static function filter_wrapper_attributes( $attrs, $feed = null, $definition = null ) {
		if ( ! function_exists( 'esf_tiktok_hover_overlay_wrapper_attributes' ) ) {
			return is_array( $attrs ) ? $attrs : array();
		}

		return esf_tiktok_hover_overlay_wrapper_attributes( is_array( $attrs ) ? $attrs : array(), $feed );
	}
}
