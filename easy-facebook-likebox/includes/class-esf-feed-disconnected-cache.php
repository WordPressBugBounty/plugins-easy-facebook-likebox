<?php
/**
 * Graceful cache fallback when a feed source (account/page) is disconnected.
 *
 * Preserves cached posts on the public frontend until TTL expiry and surfaces
 * admin-only notices so site owners can reconnect without breaking live pages.
 *
 * @package Easy_Social_Feed
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Feed_Disconnected_Cache
 *
 * @since 6.7.9
 */
class ESF_Feed_Disconnected_Cache {

	/**
	 * Whether a frontend pin-cache button was rendered this request.
	 *
	 * @var bool
	 */
	private static $frontend_pin_script_needed = false;

	/**
	 * Register hooks for disconnect-cache frontend/admin behavior.
	 *
	 * @since 6.7.9
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'maybe_enqueue_frontend_pin_script' ), 5 );
	}

	/**
	 * Whether frontend pin-cache JS should load on this request.
	 *
	 * @since 6.7.9
	 * @return bool
	 */
	public static function should_enqueue_frontend_pin_script() {
		if ( ! self::$frontend_pin_script_needed ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return false;
		}

		if ( ! class_exists( 'ESF_GDPR_Integrations' ) ) {
			return false;
		}

		return ESF_GDPR_Integrations::current_user_can_see_admin_notice();
	}

	/**
	 * Mark that a pin-cache button was rendered and will need frontend JS.
	 *
	 * @since 6.7.9
	 * @return void
	 */
	private static function request_frontend_pin_script() {
		if ( ! self::should_enqueue_frontend_pin_script_context() ) {
			return;
		}
		self::$frontend_pin_script_needed = true;
	}

	/**
	 * Whether this request type can ever need the frontend pin script.
	 *
	 * @since 6.7.9
	 * @return bool
	 */
	private static function should_enqueue_frontend_pin_script_context() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return false;
		}

		if ( ! class_exists( 'ESF_GDPR_Integrations' ) ) {
			return false;
		}

		return ESF_GDPR_Integrations::current_user_can_see_admin_notice();
	}

	/**
	 * Enqueue shared frontend JS for pin-cache buttons (wp_footer).
	 *
	 * @since 6.7.9
	 * @return void
	 */
	public static function maybe_enqueue_frontend_pin_script() {
		if ( ! self::should_enqueue_frontend_pin_script() ) {
			return;
		}
		self::enqueue_frontend_pin_script();
	}

	/**
	 * Enqueue shared frontend JS for pin-cache buttons.
	 *
	 * @since 6.7.9
	 * @return void
	 */
	public static function enqueue_frontend_pin_script() {
		if ( ! defined( 'FTA_PLUGIN_DIR' ) || ! defined( 'FTA_PLUGIN_URL' ) ) {
			return;
		}

		$handle      = 'esf-disconnected-cache-admin';
		$script_path = FTA_PLUGIN_DIR . 'admin/shared/frontend/js/esf-disconnected-cache-admin.js';
		if ( ! file_exists( $script_path ) ) {
			return;
		}

		if ( wp_script_is( $handle, 'enqueued' ) || wp_script_is( $handle, 'done' ) ) {
			return;
		}

		wp_enqueue_script(
			$handle,
			FTA_PLUGIN_URL . 'admin/shared/frontend/js/esf-disconnected-cache-admin.js',
			array(),
			(string) filemtime( $script_path ),
			true
		);

		wp_localize_script(
			$handle,
			'esfDisconnectedCacheAdmin',
			array(
				'restUrl'        => rest_url( 'esf/v1' ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'loadingText'    => __( 'Saving…', 'easy-facebook-likebox' ),
				'successEyebrow' => __( 'Success', 'easy-facebook-likebox' ),
				'pinnedMessage'  => __(
					'Cached posts will be kept permanently until you clear cache or reconnect.',
					'easy-facebook-likebox'
				),
				'pinErrorText'   => __(
					'Could not keep cache permanently.',
					'easy-facebook-likebox'
				),
			)
		);
	}

	/**
	 * Admin dashboard reconnect URLs keyed by module slug.
	 *
	 * @var array<string,string>
	 */
	private static $reconnect_paths = array(
		'facebook'  => 'admin.php?page=esf-facebook&tab=accounts',
		'instagram' => 'admin.php?page=esf-instagram&tab=accounts',
		'youtube'   => 'admin.php?page=esf-youtube&screen=accounts',
		'twitter'   => 'admin.php?page=esf-twitter&screen=accounts',
		'tiktok'    => 'admin.php?page=esf-tiktok&screen=accounts',
	);

	/**
	 * Throttle prefix for Facebook Page disconnect emails.
	 *
	 * @var string
	 */
	const FB_PAGE_MAIL_PREFIX = 'esf_fb_page_disconnect_mail_';

	/**
	 * Throttle prefix for Facebook account disconnect emails.
	 *
	 * @var string
	 */
	const FB_ACCOUNT_MAIL_PREFIX = 'esf_fb_account_disconnect_mail_';

	/**
	 * Throttle prefix for Instagram account disconnect emails.
	 *
	 * @var string
	 */
	const IG_ACCOUNT_MAIL_PREFIX = 'esf_ig_account_disconnect_mail_';

	/**
	 * Throttle prefix for YouTube account disconnect emails.
	 *
	 * @var string
	 */
	const YT_ACCOUNT_MAIL_PREFIX = 'esf_yt_account_disconnect_mail_';

	/**
	 * Throttle prefix for X (Twitter) account disconnect emails.
	 *
	 * @var string
	 */
	const TW_ACCOUNT_MAIL_PREFIX = 'esf_tw_account_disconnect_mail_';

	/**
	 * Throttle prefix for TikTok account disconnect emails.
	 *
	 * @var string
	 */
	const TT_ACCOUNT_MAIL_PREFIX = 'esf_tt_account_disconnect_mail_';

	/**
	 * Plain-text notice for dashboard preview panels and REST `notice` payloads.
	 *
	 * @since 6.7.9
	 *
	 * @param bool $cache_pinned Whether cache was pinned permanently.
	 * @return string
	 */
	public static function get_notice_message( $cache_pinned = false ) {
		if ( $cache_pinned ) {
			return __(
				'The connected account or page was removed from this site. Cached posts are kept permanently until you clear cache or reconnect.',
				'easy-facebook-likebox'
			);
		}

		return __(
			'The connected account or page was removed from this site. Visitors still see cached posts until the cache expires. Reconnect to refresh this feed, or keep the cache permanently.',
			'easy-facebook-likebox'
		);
	}

	/**
	 * Plain-text notice for the feed editor Connect panel.
	 *
	 * @since 6.7.9
	 *
	 * @param bool $cache_pinned Whether cache was pinned permanently.
	 * @return string
	 */
	public static function get_dashboard_notice_message( $cache_pinned = false ) {
		if ( $cache_pinned ) {
			return __(
				'This feed\'s source was disconnected. Cached posts are kept permanently until you clear cache or reconnect.',
				'easy-facebook-likebox'
			);
		}

		return __(
			'This feed\'s source was disconnected. Your site still shows cached posts until they expire. Connect again to fetch fresh content, or keep the cache permanently.',
			'easy-facebook-likebox'
		);
	}

	/**
	 * Resolve the module Accounts tab admin URL.
	 *
	 * @since 6.7.9
	 *
	 * @param string $module Module slug (`facebook`, `instagram`, …).
	 * @return string Admin URL or empty when unavailable.
	 */
	public static function get_reconnect_admin_url( $module ) {
		$module = sanitize_key( (string) $module );
		if ( '' === $module || ! function_exists( 'admin_url' ) ) {
			return '';
		}

		$path = isset( self::$reconnect_paths[ $module ] ) ? self::$reconnect_paths[ $module ] : '';
		if ( '' === $path ) {
			return '';
		}

		return (string) admin_url( $path );
	}

	/**
	 * Whether a Facebook feed row lost its linked Page row.
	 *
	 * @since 6.7.9
	 *
	 * @param object      $feed_row Feed row.
	 * @param object|null $page_row Linked page row, if loaded.
	 * @return bool
	 */
	public static function facebook_source_is_disconnected( $feed_row, $page_row = null ) {
		if ( ! is_object( $feed_row ) ) {
			return false;
		}

		$status = isset( $feed_row->status ) ? sanitize_key( (string) $feed_row->status ) : 'active';
		if ( 'invalid' === $status ) {
			return true;
		}

		$page_row_id = isset( $feed_row->page_id ) ? (int) $feed_row->page_id : 0;
		return $page_row_id > 0 && null === $page_row;
	}

	/**
	 * Whether an Instagram feed row lost its linked account row.
	 *
	 * @since 6.7.9
	 *
	 * @param object      $feed_row    Feed row.
	 * @param object|null $account_row Linked account row, if loaded.
	 * @return bool
	 */
	public static function instagram_source_is_disconnected( $feed_row, $account_row = null ) {
		return self::account_source_is_disconnected( $feed_row, $account_row );
	}

	/**
	 * Whether a feed row lost its linked account row (account-based modules).
	 *
	 * @since 6.7.9
	 *
	 * @param object      $feed_row    Feed row.
	 * @param object|null $account_row Linked account row, if loaded.
	 * @return bool
	 */
	public static function account_source_is_disconnected( $feed_row, $account_row = null ) {
		if ( ! is_object( $feed_row ) ) {
			return false;
		}

		$account_id = isset( $feed_row->account_id ) ? (int) $feed_row->account_id : 0;
		return $account_id > 0 && null === $account_row;
	}

	/**
	 * Admin-only HTML banner for disconnected feeds serving cached content.
	 *
	 * @since 6.7.9
	 *
	 * @param string               $module  Module slug.
	 * @param int                  $feed_id Feed id for pin-cache action.
	 * @param array<string,mixed>  $args    Optional: cache_pinned (bool), message (string),
	 *                                      link_text (string), link_url (string).
	 * @return string Markup or empty string for visitors / when GDPR helper missing.
	 */
	public static function get_admin_notice_html( $module, $feed_id = 0, array $args = array() ) {
		if ( ! class_exists( 'ESF_GDPR_Integrations' ) ) {
			return '';
		}

		if ( ! ESF_GDPR_Integrations::current_user_can_see_admin_notice() ) {
			return '';
		}

		$cache_pinned = ! empty( $args['cache_pinned'] );
		if ( $feed_id > 0 ) {
			$cache_pinned = self::is_feed_cache_effectively_pinned( $module, $feed_id, $cache_pinned );
		}
		$link_url = isset( $args['link_url'] ) && is_string( $args['link_url'] ) && '' !== trim( $args['link_url'] )
			? trim( $args['link_url'] )
			: self::get_reconnect_admin_url( $module );
		$pin_label = '';

		if ( ! $cache_pinned && $feed_id > 0 ) {
			$pin_label = __( 'Keep cache permanently', 'easy-facebook-likebox' );
			self::request_frontend_pin_script();
		}

		$message = isset( $args['message'] ) && is_string( $args['message'] ) && '' !== trim( $args['message'] )
			? trim( $args['message'] )
			: self::get_notice_message( $cache_pinned );
		$link_text = isset( $args['link_text'] ) && is_string( $args['link_text'] ) && '' !== trim( $args['link_text'] )
			? trim( $args['link_text'] )
			: __( 'Reconnect account', 'easy-facebook-likebox' );

		$notice = ESF_GDPR_Integrations::render_admin_notice_html(
			$message,
			$module,
			$link_url,
			$link_text,
			'',
			$pin_label,
			'warning',
			( ! $cache_pinned && $feed_id > 0 ) ? (int) $feed_id : 0
		);

		return $notice;
	}

	/**
	 * Notice copy when an Events Page token expired but cached Events may still show.
	 *
	 * @since 6.9.0
	 * @param bool $cache_pinned Whether cache was pinned permanently.
	 * @return string
	 */
	public static function get_events_token_expired_notice_message( $cache_pinned = false ) {
		if ( $cache_pinned ) {
			return __(
				'The Events Page token for this feed has expired. Cached Events are kept permanently until you clear cache or paste a new Page token.',
				'easy-facebook-likebox'
			);
		}

		return __(
			'The Events Page token for this feed has expired. Visitors may still see cached Events until the cache expires. Paste a new Page token to refresh, or keep the cache permanently.',
			'easy-facebook-likebox'
		);
	}

	/**
	 * Pin cache rows and persist feed settings flag for a module feed.
	 *
	 * @since 6.7.9
	 *
	 * @param string $module  Module slug.
	 * @param int    $feed_id Feed id.
	 * @return bool
	 */
	public static function pin_feed_cache_for_module( $module, $feed_id ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return false;
		}

		$module = sanitize_key( (string) $module );
		$pinned = false;

		if ( 'facebook' === $module && class_exists( 'ESF_Facebook_Cache' ) ) {
			$result = ESF_Facebook_Cache::pin_feed_cache( $feed_id );
			$pinned = is_int( $result ) && $result > 0;
			if ( $pinned && class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
				self::mark_feed_cache_pinned(
					ESF_Facebook_Feed_Repository::get_instance(),
					$feed_id
				);
			}
		} elseif ( 'instagram' === $module && class_exists( 'ESF_Instagram_Cache' ) ) {
			$result = ESF_Instagram_Cache::pin_feed_cache( $feed_id );
			$pinned = is_int( $result ) && $result > 0;
			if ( $pinned && class_exists( 'ESF_Instagram_Feed_Repository' ) ) {
				self::mark_feed_cache_pinned(
					ESF_Instagram_Feed_Repository::get_instance(),
					$feed_id
				);
			}
		} elseif ( 'youtube' === $module && class_exists( 'ESF_YouTube_Cache' ) ) {
			$result = ESF_YouTube_Cache::pin_feed_cache( $feed_id );
			$pinned = is_int( $result ) && $result > 0;
			if ( $pinned && class_exists( 'ESF_YouTube_Feed_Repository' ) ) {
				self::mark_feed_cache_pinned(
					ESF_YouTube_Feed_Repository::get_instance(),
					$feed_id
				);
			}
		} elseif ( 'twitter' === $module && class_exists( 'ESF_Twitter_Cache' ) ) {
			$result = ESF_Twitter_Cache::pin_feed_cache( $feed_id );
			$pinned = is_int( $result ) && $result > 0;
			if ( $pinned && class_exists( 'ESF_Twitter_Feed_Repository' ) ) {
				self::mark_feed_cache_pinned(
					ESF_Twitter_Feed_Repository::get_instance(),
					$feed_id
				);
			}
		} elseif ( 'tiktok' === $module && class_exists( 'ESF_TikTok_Cache' ) ) {
			$result = ESF_TikTok_Cache::pin_feed_cache( $feed_id );
			$pinned = is_int( $result ) && $result > 0;
			if ( $pinned && class_exists( 'ESF_TikTok_Feed_Repository' ) ) {
				self::mark_feed_cache_pinned(
					ESF_TikTok_Feed_Repository::get_instance(),
					$feed_id
				);
			}
		}

		return $pinned;
	}

	/**
	 * Whether a feed is truly pinned (settings flag plus far-future cache expiry in DB).
	 *
	 * Clears a stale `cache_pinned` setting when rows were never pinned or were refreshed.
	 *
	 * @since 6.7.9
	 *
	 * @param string $module            Module slug.
	 * @param int    $feed_id           Feed id.
	 * @param bool   $settings_pinned   Value from feed settings.
	 * @return bool
	 */
	public static function is_feed_cache_effectively_pinned( $module, $feed_id, $settings_pinned = false ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 || ! $settings_pinned ) {
			return false;
		}

		$db_pinned = self::feed_has_pinned_cache_in_db( $module, $feed_id );
		if ( ! $db_pinned ) {
			$repo = self::get_feed_repository_for_module( $module );
			if ( $repo ) {
				self::clear_feed_cache_pinned( $repo, $feed_id );
			}
			return false;
		}

		return true;
	}

	/**
	 * @param string $module  Module slug.
	 * @param int    $feed_id Feed id.
	 * @return bool
	 */
	private static function feed_has_pinned_cache_in_db( $module, $feed_id ) {
		$module = sanitize_key( (string) $module );
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return false;
		}

		$checks = array(
			'facebook'  => array( 'ESF_Facebook_Cache', 'feed_has_pinned_cache_rows' ),
			'instagram' => array( 'ESF_Instagram_Cache', 'feed_has_pinned_cache_rows' ),
			'youtube'   => array( 'ESF_YouTube_Cache', 'feed_has_pinned_cache_rows' ),
			'twitter'   => array( 'ESF_Twitter_Cache', 'feed_has_pinned_cache_rows' ),
			'tiktok'    => array( 'ESF_TikTok_Cache', 'feed_has_pinned_cache_rows' ),
		);

		if ( ! isset( $checks[ $module ] ) ) {
			return false;
		}

		list( $class, $method ) = $checks[ $module ];
		if ( ! class_exists( $class ) || ! method_exists( $class, $method ) ) {
			return false;
		}

		return (bool) $class::$method( $feed_id );
	}

	/**
	 * Clear `settings.feed.cache_pinned` when a feed source is connected again.
	 *
	 * @since 6.7.9
	 *
	 * @param object $repo    Feed repository instance.
	 * @param int    $feed_id Feed id.
	 * @return void
	 */
	public static function clear_feed_cache_pinned( $repo, $feed_id ) {
		if ( ! is_object( $repo ) || ! method_exists( $repo, 'get_by_id' ) || ! method_exists( $repo, 'update' ) ) {
			return;
		}

		$feed = $repo->get_by_id( (int) $feed_id );
		if ( ! $feed ) {
			return;
		}

		$settings = isset( $feed->settings ) && is_array( $feed->settings )
			? $feed->settings
			: ( method_exists( $repo, 'get_default_settings' ) ? $repo->get_default_settings() : array() );

		if ( empty( $settings['feed']['cache_pinned'] ) ) {
			return;
		}

		if ( ! isset( $settings['feed'] ) || ! is_array( $settings['feed'] ) ) {
			$settings['feed'] = array();
		}
		unset( $settings['feed']['cache_pinned'] );

		$repo->update(
			(int) $feed_id,
			array(
				'settings' => $settings,
			)
		);
	}

	/**
	 * After a feed is linked to a live page/account again, drop disconnect-only flags and snapshots.
	 *
	 * @since 6.7.9
	 *
	 * @param string $module        Module slug.
	 * @param int    $feed_id       Feed id.
	 * @param int    $source_row_id Internal page/account row id.
	 * @return void
	 */
	public static function handle_feed_source_reconnected( $module, $feed_id, $source_row_id = 0 ) {
		$module        = sanitize_key( (string) $module );
		$feed_id       = (int) $feed_id;
		$source_row_id = (int) $source_row_id;
		if ( '' === $module || $feed_id <= 0 ) {
			return;
		}

		$repo = self::get_feed_repository_for_module( $module );
		if ( $repo ) {
			self::clear_feed_cache_pinned( $repo, $feed_id );
		}

		if ( $source_row_id <= 0 ) {
			return;
		}

		if ( 'facebook' === $module && class_exists( 'ESF_Facebook_Cache' ) ) {
			ESF_Facebook_Cache::delete_by_key(
				ESF_Facebook_Cache::page_profile_snapshot_key( $source_row_id )
			);
			return;
		}

		$cache_class = self::get_account_cache_class_for_module( $module );
		if ( ! $cache_class || ! method_exists( $cache_class, 'account_profile_snapshot_key' ) || ! method_exists( $cache_class, 'delete_by_key' ) ) {
			return;
		}

		$cache_class::delete_by_key(
			$cache_class::account_profile_snapshot_key( $source_row_id )
		);
	}

	/**
	 * @param string $module Module slug.
	 * @return object|null Feed repository instance.
	 */
	private static function get_feed_repository_for_module( $module ) {
		$module = sanitize_key( (string) $module );
		$class  = '';
		switch ( $module ) {
			case 'facebook':
				$class = 'ESF_Facebook_Feed_Repository';
				break;
			case 'instagram':
				$class = 'ESF_Instagram_Feed_Repository';
				break;
			case 'youtube':
				$class = 'ESF_YouTube_Feed_Repository';
				break;
			case 'twitter':
				$class = 'ESF_Twitter_Feed_Repository';
				break;
			case 'tiktok':
				$class = 'ESF_TikTok_Feed_Repository';
				break;
		}

		if ( '' === $class || ! class_exists( $class ) || ! method_exists( $class, 'get_instance' ) ) {
			return null;
		}

		return $class::get_instance();
	}

	/**
	 * @param string $module Module slug.
	 * @return string|null Cache class name.
	 */
	private static function get_account_cache_class_for_module( $module ) {
		$module = sanitize_key( (string) $module );
		switch ( $module ) {
			case 'instagram':
				return class_exists( 'ESF_Instagram_Cache' ) ? 'ESF_Instagram_Cache' : null;
			case 'youtube':
				return class_exists( 'ESF_YouTube_Cache' ) ? 'ESF_YouTube_Cache' : null;
			case 'twitter':
				return class_exists( 'ESF_Twitter_Cache' ) ? 'ESF_Twitter_Cache' : null;
			case 'tiktok':
				return class_exists( 'ESF_TikTok_Cache' ) ? 'ESF_TikTok_Cache' : null;
		}

		return null;
	}

	/**
	 * Email admins when a Facebook Page is disconnected and feeds still have cache.
	 *
	 * @since 6.7.9
	 *
	 * @param object $page_row Page row about to be deleted.
	 * @return void
	 */
	public static function maybe_notify_facebook_page_disconnected( $page_row ) {
		if ( ! is_object( $page_row ) || empty( $page_row->id ) ) {
			return;
		}

		if ( class_exists( 'ESF_Facebook_Page_Repository' ) && ESF_Facebook_Page_Repository::is_disconnect_notify_suppressed() ) {
			return;
		}

		$page_row_id = (int) $page_row->id;
		$feeds       = self::collect_facebook_feeds_with_preservable_cache( $page_row_id );
		if ( empty( $feeds ) ) {
			return;
		}

		$label = isset( $page_row->page_name ) ? trim( (string) $page_row->page_name ) : '';
		if ( '' === $label && isset( $page_row->page_id ) ) {
			$label = (string) $page_row->page_id;
		}
		if ( '' === $label ) {
			$label = __( 'Facebook Page', 'easy-facebook-likebox' );
		}

		self::send_disconnect_notification(
			'facebook',
			self::FB_PAGE_MAIL_PREFIX . $page_row_id,
			$label,
			$feeds,
			admin_url( 'admin.php?page=esf-facebook&tab=accounts' ),
			$page_row,
			'esf_facebook_token_reconnect_mail_recipient'
		);
	}

	/**
	 * Email admins when a Facebook account (and its Pages) is disconnected.
	 *
	 * Sends one summary email before per-Page cascade deletes run.
	 *
	 * @since 6.7.9
	 *
	 * @param object $account_row Account row about to be deleted.
	 * @return void
	 */
	public static function maybe_notify_facebook_account_disconnected( $account_row ) {
		if ( ! is_object( $account_row ) || empty( $account_row->id ) ) {
			return;
		}

		$account_id = (int) $account_row->id;
		$feeds      = self::collect_facebook_feeds_with_preservable_cache_for_account( $account_id );
		if ( empty( $feeds ) ) {
			return;
		}

		$label = isset( $account_row->facebook_user_name ) ? trim( (string) $account_row->facebook_user_name ) : '';
		if ( '' === $label && isset( $account_row->facebook_user_id ) ) {
			$label = (string) $account_row->facebook_user_id;
		}
		if ( '' === $label ) {
			$label = __( 'Facebook account', 'easy-facebook-likebox' );
		}

		self::send_disconnect_notification(
			'facebook',
			self::FB_ACCOUNT_MAIL_PREFIX . $account_id,
			$label,
			$feeds,
			admin_url( 'admin.php?page=esf-facebook&tab=accounts' ),
			$account_row,
			'esf_facebook_token_reconnect_mail_recipient'
		);
	}

	/**
	 * Email admins when an Instagram account is disconnected and feeds still have cache.
	 *
	 * @since 6.7.9
	 *
	 * @param object $account_row Account row about to be deleted.
	 * @return void
	 */
	public static function maybe_notify_instagram_account_disconnected( $account_row ) {
		self::maybe_notify_module_account_disconnected( 'instagram', $account_row );
	}

	/**
	 * Email admins when a YouTube account is disconnected and feeds still have cache.
	 *
	 * @since 6.7.9
	 *
	 * @param object $account_row Account row about to be deleted.
	 * @return void
	 */
	public static function maybe_notify_youtube_account_disconnected( $account_row ) {
		self::maybe_notify_module_account_disconnected( 'youtube', $account_row );
	}

	/**
	 * Email admins when an X account is disconnected and feeds still have cache.
	 *
	 * @since 6.7.9
	 *
	 * @param object $account_row Account row about to be deleted.
	 * @return void
	 */
	public static function maybe_notify_twitter_account_disconnected( $account_row ) {
		self::maybe_notify_module_account_disconnected( 'twitter', $account_row );
	}

	/**
	 * Email admins when a TikTok account is disconnected and feeds still have cache.
	 *
	 * @since 6.7.9
	 *
	 * @param object $account_row Account row about to be deleted.
	 * @return void
	 */
	public static function maybe_notify_tiktok_account_disconnected( $account_row ) {
		self::maybe_notify_module_account_disconnected( 'tiktok', $account_row );
	}

	/**
	 * @param string $module      Module slug.
	 * @param object $account_row Account row.
	 * @return void
	 */
	private static function maybe_notify_module_account_disconnected( $module, $account_row ) {
		if ( ! is_object( $account_row ) || empty( $account_row->id ) ) {
			return;
		}

		$module     = sanitize_key( (string) $module );
		$account_id = (int) $account_row->id;
		$feeds      = self::collect_module_feeds_with_preservable_cache( $module, $account_id );
		if ( empty( $feeds ) ) {
			return;
		}

		$label = self::resolve_account_disconnect_label( $module, $account_row );
		$mail  = self::get_module_disconnect_mail_config( $module );

		self::send_disconnect_notification(
			$module,
			$mail['prefix'] . $account_id,
			$label,
			$feeds,
			self::get_reconnect_admin_url( $module ),
			$account_row,
			$mail['recipient_filter'],
			$mail['notify_key'],
			$mail['emails_key']
		);
	}

	/**
	 * @param string $module     Module slug.
	 * @param int    $account_id Internal account id.
	 * @return array<int,object>
	 */
	private static function collect_module_feeds_with_preservable_cache( $module, $account_id ) {
		$module = sanitize_key( (string) $module );
		if ( 'instagram' === $module ) {
			return self::collect_instagram_feeds_with_preservable_cache( (int) $account_id );
		}

		$repo_class = '';
		switch ( $module ) {
			case 'youtube':
				$repo_class = 'ESF_YouTube_Feed_Repository';
				break;
			case 'twitter':
				$repo_class = 'ESF_Twitter_Feed_Repository';
				break;
			case 'tiktok':
				$repo_class = 'ESF_TikTok_Feed_Repository';
				break;
		}

		if ( '' === $repo_class || ! class_exists( $repo_class ) || ! method_exists( $repo_class, 'get_instance' ) ) {
			return array();
		}

		$repo  = $repo_class::get_instance();
		$feeds = method_exists( $repo, 'get_by_account_id' )
			? $repo->get_by_account_id( (int) $account_id )
			: array();

		$out = array();
		foreach ( $feeds as $feed ) {
			if ( self::module_feed_has_preservable_cache( $module, $feed, (int) $account_id ) ) {
				$out[] = $feed;
			}
		}

		return $out;
	}

	/**
	 * @param string $module     Module slug.
	 * @param object $feed       Feed row.
	 * @param int    $account_id Internal account id.
	 * @return bool
	 */
	private static function module_feed_has_preservable_cache( $module, $feed, $account_id ) {
		$module = sanitize_key( (string) $module );
		if ( ! is_object( $feed ) || self::feed_cache_is_pinned( $feed ) ) {
			return false;
		}

		switch ( $module ) {
			case 'instagram':
				return self::instagram_feed_has_preservable_cache( $feed, $account_id );
			case 'youtube':
				return class_exists( 'ESF_YouTube_Renderer' )
					&& ESF_YouTube_Renderer::feed_has_videos_cache( $feed );
			case 'twitter':
				return class_exists( 'ESF_Twitter_Renderer' )
					&& ESF_Twitter_Renderer::feed_has_posts_cache( $feed );
			case 'tiktok':
				return self::tiktok_feed_has_preservable_cache( $feed, $account_id );
		}

		return false;
	}

	/**
	 * @param object $feed       Feed row.
	 * @param int    $account_id Internal account id.
	 * @return bool
	 */
	private static function tiktok_feed_has_preservable_cache( $feed, $account_id ) {
		if ( ! class_exists( 'ESF_TikTok_Cache' ) ) {
			return false;
		}

		$account_id = (int) $account_id;
		$key        = ESF_TikTok_Cache::posts_cache_key( $account_id );
		$cached     = ESF_TikTok_Cache::get( $key );
		if ( ! is_array( $cached ) ) {
			$cached = ESF_TikTok_Cache::get_stale( $key );
		}

		return is_array( $cached )
			&& isset( $cached['posts'] )
			&& is_array( $cached['posts'] )
			&& ! empty( $cached['posts'] );
	}

	/**
	 * @param string $module      Module slug.
	 * @param object $account_row Account row.
	 * @return string
	 */
	private static function resolve_account_disconnect_label( $module, $account_row ) {
		$module = sanitize_key( (string) $module );
		$label  = '';

		switch ( $module ) {
			case 'instagram':
				$label = isset( $account_row->display_name ) ? trim( (string) $account_row->display_name ) : '';
				if ( '' === $label && isset( $account_row->username ) ) {
					$label = '@' . ltrim( (string) $account_row->username, '@' );
				}
				if ( '' === $label ) {
					$label = __( 'Instagram account', 'easy-facebook-likebox' );
				}
				break;
			case 'youtube':
				$label = isset( $account_row->channel_name ) ? trim( (string) $account_row->channel_name ) : '';
				if ( '' === $label && isset( $account_row->channel_id ) ) {
					$label = (string) $account_row->channel_id;
				}
				if ( '' === $label ) {
					$label = __( 'YouTube channel', 'easy-facebook-likebox' );
				}
				break;
			case 'twitter':
				$label = isset( $account_row->display_name ) ? trim( (string) $account_row->display_name ) : '';
				if ( '' === $label && isset( $account_row->username ) ) {
					$label = '@' . ltrim( (string) $account_row->username, '@' );
				}
				if ( '' === $label ) {
					$label = __( 'X account', 'easy-facebook-likebox' );
				}
				break;
			case 'tiktok':
				$label = isset( $account_row->display_name ) ? trim( (string) $account_row->display_name ) : '';
				if ( '' === $label && isset( $account_row->username ) ) {
					$label = '@' . ltrim( (string) $account_row->username, '@' );
				}
				if ( '' === $label ) {
					$label = __( 'TikTok account', 'easy-facebook-likebox' );
				}
				break;
		}

		return $label;
	}

	/**
	 * @param string $module Module slug.
	 * @return array{prefix:string,notify_key:string,emails_key:string,recipient_filter:string}
	 */
	private static function get_module_disconnect_mail_config( $module ) {
		$module = sanitize_key( (string) $module );
		$config = array(
			'prefix'           => 'esf_' . $module . '_account_disconnect_mail_',
			'notify_key'       => 'notify_token_reconnect',
			'emails_key'       => 'notify_token_reconnect_emails',
			'recipient_filter' => '',
		);

		switch ( $module ) {
			case 'instagram':
				$config['prefix']           = self::IG_ACCOUNT_MAIL_PREFIX;
				$config['recipient_filter'] = 'esf_instagram_token_reconnect_mail_recipient';
				break;
			case 'youtube':
				$config['prefix']           = self::YT_ACCOUNT_MAIL_PREFIX;
				$config['recipient_filter'] = 'esf_youtube_token_reconnect_mail_recipient';
				break;
			case 'twitter':
				$config['prefix']           = self::TW_ACCOUNT_MAIL_PREFIX;
				$config['recipient_filter'] = 'esf_twitter_token_reconnect_mail_recipient';
				break;
			case 'tiktok':
				$config['prefix']           = self::TT_ACCOUNT_MAIL_PREFIX;
				$config['recipient_filter'] = 'esf_tiktok_token_reconnect_mail_recipient';
				break;
		}

		return $config;
	}

	/**
	 * @param int $page_row_id Internal pages.id.
	 * @return array<int,object>
	 */
	private static function collect_facebook_feeds_with_preservable_cache( $page_row_id ) {
		if ( ! class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
			return array();
		}

		$feeds = ESF_Facebook_Feed_Repository::get_instance()->get_by_page_id( (int) $page_row_id );
		$out   = array();
		foreach ( $feeds as $feed ) {
			if ( self::facebook_feed_has_preservable_cache( $feed ) ) {
				$out[] = $feed;
			}
		}

		return $out;
	}

	/**
	 * @param int $account_id Internal accounts.id.
	 * @return array<int,object>
	 */
	private static function collect_facebook_feeds_with_preservable_cache_for_account( $account_id ) {
		if ( ! class_exists( 'ESF_Facebook_Page_Repository' ) ) {
			return array();
		}

		$seen  = array();
		$out   = array();
		$pages = ESF_Facebook_Page_Repository::get_instance()->get_by_account_id( (int) $account_id );
		foreach ( $pages as $page ) {
			if ( ! is_object( $page ) || empty( $page->id ) ) {
				continue;
			}
			foreach ( self::collect_facebook_feeds_with_preservable_cache( (int) $page->id ) as $feed ) {
				$feed_id = isset( $feed->id ) ? (int) $feed->id : 0;
				if ( $feed_id <= 0 || isset( $seen[ $feed_id ] ) ) {
					continue;
				}
				$seen[ $feed_id ] = true;
				$out[]            = $feed;
			}
		}

		return $out;
	}

	/**
	 * @param int $account_id Internal accounts.id.
	 * @return array<int,object>
	 */
	private static function collect_instagram_feeds_with_preservable_cache( $account_id ) {
		if ( ! class_exists( 'ESF_Instagram_Feed_Repository' ) ) {
			return array();
		}

		$out   = array();
		$feeds = ESF_Instagram_Feed_Repository::get_instance()->get_by_account_id( (int) $account_id );
		foreach ( $feeds as $feed ) {
			if ( self::instagram_feed_has_preservable_cache( $feed, $account_id ) ) {
				$out[] = $feed;
			}
		}

		return $out;
	}

	/**
	 * @param object $feed Feed row.
	 * @return bool
	 */
	private static function facebook_feed_has_preservable_cache( $feed ) {
		if ( ! is_object( $feed ) || self::feed_cache_is_pinned( $feed ) ) {
			return false;
		}
		if ( ! class_exists( 'ESF_Facebook_Renderer' ) || ! class_exists( 'ESF_Facebook_Feed_Repository' ) ) {
			return false;
		}

		$settings = isset( $feed->settings ) && is_array( $feed->settings )
			? $feed->settings
			: ESF_Facebook_Feed_Repository::get_default_settings();

		$probe = (object) array(
			'id'        => isset( $feed->id ) ? (int) $feed->id : 0,
			'page_id'   => isset( $feed->page_id ) ? (int) $feed->page_id : 0,
			'feed_type' => isset( $feed->feed_type ) ? (string) $feed->feed_type : 'timeline',
			'settings'  => $settings,
		);

		return ESF_Facebook_Renderer::feed_has_posts_cache( $probe );
	}

	/**
	 * @param object $feed       Feed row.
	 * @param int    $account_id Internal account id.
	 * @return bool
	 */
	private static function instagram_feed_has_preservable_cache( $feed, $account_id ) {
		if ( ! is_object( $feed ) || self::feed_cache_is_pinned( $feed ) || ! class_exists( 'ESF_Instagram_Cache' ) ) {
			return false;
		}

		$account_id = (int) $account_id;
		$feed_type  = isset( $feed->feed_type ) ? sanitize_key( (string) $feed->feed_type ) : 'user_timeline';
		$settings   = isset( $feed->settings ) && is_array( $feed->settings ) ? $feed->settings : array();
		$source_id  = isset( $feed->source_id ) ? (string) $feed->source_id : '';
		$cache_key  = '';

		if ( 'hashtag' === $feed_type && function_exists( 'esf_instagram_hashtag_cache_key' ) && function_exists( 'esf_instagram_normalize_hashtag_media_type' ) ) {
			$feed_cfg   = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();
			$media_type = esf_instagram_normalize_hashtag_media_type(
				isset( $feed_cfg['hashtag_media_type'] ) ? (string) $feed_cfg['hashtag_media_type'] : 'top_media'
			);
			$cache_key  = esf_instagram_hashtag_cache_key( $source_id, $media_type );
		} elseif ( class_exists( 'ESF_Instagram_Renderer' ) ) {
			$cache_key = ESF_Instagram_Renderer::media_cache_key( $account_id );
		} else {
			$cache_key = sprintf( 'esf_ig_media_%d', $account_id );
		}

		if ( ! is_string( $cache_key ) || '' === $cache_key ) {
			return false;
		}

		$cached = ESF_Instagram_Cache::get( $cache_key );
		if ( ! is_array( $cached ) ) {
			$cached = ESF_Instagram_Cache::get_stale( $cache_key );
		}

		return is_array( $cached )
			&& isset( $cached['data'] )
			&& is_array( $cached['data'] )
			&& ! empty( $cached['data'] );
	}

	/**
	 * @param object $feed Feed row.
	 * @return bool
	 */
	private static function feed_cache_is_pinned( $feed ) {
		if ( ! is_object( $feed ) ) {
			return false;
		}
		$settings = isset( $feed->settings ) && is_array( $feed->settings ) ? $feed->settings : array();
		return ! empty( $settings['feed']['cache_pinned'] );
	}

	/**
	 * @param array<int,object> $feeds Feed rows.
	 * @return string[]
	 */
	private static function format_feed_name_lines( array $feeds ) {
		$names = array();
		foreach ( $feeds as $feed ) {
			if ( ! is_object( $feed ) ) {
				continue;
			}
			$name = isset( $feed->name ) ? trim( (string) $feed->name ) : '';
			if ( '' === $name ) {
				$name = sprintf(
					/* translators: %d: feed ID */
					__( 'Feed #%d', 'easy-facebook-likebox' ),
					isset( $feed->id ) ? (int) $feed->id : 0
				);
			}
			$names[] = $name;
		}

		return $names;
	}

	/**
	 * @return string
	 */
	private static function build_disconnect_mail_subject() {
		return sprintf(
			/* translators: %s: site title */
			__( '[%s] Social feed source disconnected — cached posts still showing', 'easy-facebook-likebox' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}

	/**
	 * @param string              $module         Module slug.
	 * @param string              $source_label   Human-readable source label.
	 * @param array<int,object>   $feeds          Affected feed rows.
	 * @param string              $reconnect_url  Admin reconnect URL.
	 * @return string
	 */
	private static function build_disconnect_mail_body( $module, $source_label, array $feeds, $reconnect_url ) {
		$lines = self::format_feed_name_lines( $feeds );
		$list  = '';
		if ( ! empty( $lines ) ) {
			$list = "\n - " . implode( "\n - ", $lines );
		}

		$networks = array(
			'facebook'  => __( 'Facebook', 'easy-facebook-likebox' ),
			'instagram' => __( 'Instagram', 'easy-facebook-likebox' ),
			'youtube'   => __( 'YouTube', 'easy-facebook-likebox' ),
			'twitter'   => __( 'X', 'easy-facebook-likebox' ),
			'tiktok'    => __( 'TikTok', 'easy-facebook-likebox' ),
		);
		$module   = sanitize_key( (string) $module );
		$network  = isset( $networks[ $module ] ) ? $networks[ $module ] : __( 'Social', 'easy-facebook-likebox' );

		return sprintf(
			/* translators: 1: network name (Facebook/Instagram), 2: disconnected source label, 3: bullet list of feed names, 4: admin reconnect URL */
			__(
				"A %1\$s feed source was removed from your site (%2\$s).\n\nVisitors still see cached posts for these feeds:%3\$s\n\nPosts will disappear when the cache expires unless you keep them permanently. While logged in as an admin, open the page with the feed and choose \"Keep cache permanently\" in the notice.\n\nReconnect to fetch fresh content:\n%4\$s\n\nThis message was sent by Easy Social Feed.",
				'easy-facebook-likebox'
			),
			$network,
			$source_label,
			$list,
			$reconnect_url
		);
	}

	/**
	 * @param string       $module           Module slug.
	 * @param string       $transient_key    Throttle key.
	 * @param string       $source_label     Source label for the email body.
	 * @param array        $feeds            Feed rows.
	 * @param string       $reconnect_url    Admin URL.
	 * @param object       $filter_row       Row for recipient filter.
	 * @param string       $recipient_filter Filter tag.
	 * @param string       $notify_setting_key Notify toggle setting key.
	 * @param string       $recipient_emails_setting_key Recipient list setting key.
	 * @return void
	 */
	private static function send_disconnect_notification( $module, $transient_key, $source_label, array $feeds, $reconnect_url, $filter_row, $recipient_filter, $notify_setting_key = 'notify_token_reconnect', $recipient_emails_setting_key = 'notify_token_reconnect_emails' ) {
		if ( ! class_exists( 'ESF_Token_Reconnect_Mailer' ) || empty( $feeds ) ) {
			return;
		}

		$settings = self::resolve_module_mail_settings( $module );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( '' === (string) $notify_setting_key ) {
			return;
		}

		ESF_Token_Reconnect_Mailer::send_admin_notification(
			array(
				'settings'                     => $settings,
				'notify_setting_key'           => $notify_setting_key,
				'recipient_emails_setting_key' => $recipient_emails_setting_key,
				'recipient_filter'             => $recipient_filter,
				'transient_key'                => $transient_key,
				'throttle_ttl'                 => (int) DAY_IN_SECONDS,
				'subject'                      => self::build_disconnect_mail_subject(),
				'message'                      => self::build_disconnect_mail_body( $module, $source_label, $feeds, $reconnect_url ),
				'filter_row'                   => $filter_row,
			)
		);
	}

	/**
	 * @param string $module Module slug.
	 * @return array<string,mixed>
	 */
	private static function resolve_module_mail_settings( $module ) {
		$module = sanitize_key( (string) $module );

		if ( 'facebook' === $module && function_exists( 'esf_facebook_get_resolved_settings' ) ) {
			return esf_facebook_get_resolved_settings();
		}
		if ( 'instagram' === $module && function_exists( 'esf_instagram_get_resolved_settings' ) ) {
			return esf_instagram_get_resolved_settings();
		}
		if ( 'youtube' === $module && function_exists( 'esf_get_youtube_settings' ) ) {
			return esf_get_youtube_settings();
		}
		if ( 'twitter' === $module && function_exists( 'esf_get_twitter_settings' ) ) {
			return esf_get_twitter_settings();
		}
		if ( 'tiktok' === $module && function_exists( 'esf_get_tiktok_settings' ) ) {
			return esf_get_tiktok_settings();
		}

		return array();
	}

	/**
	 * Set `settings.feed.cache_pinned` on a feed row.
	 *
	 * @since 6.7.9
	 *
	 * @param object $repo    Feed repository instance.
	 * @param int    $feed_id Feed id.
	 * @return void
	 */
	private static function mark_feed_cache_pinned( $repo, $feed_id ) {
		if ( ! is_object( $repo ) || ! method_exists( $repo, 'get_by_id' ) || ! method_exists( $repo, 'update' ) ) {
			return;
		}

		$feed = $repo->get_by_id( (int) $feed_id );
		if ( ! $feed ) {
			return;
		}

		$settings = isset( $feed->settings ) && is_array( $feed->settings )
			? $feed->settings
			: ( method_exists( $repo, 'get_default_settings' ) ? $repo->get_default_settings() : array() );

		if ( ! isset( $settings['feed'] ) || ! is_array( $settings['feed'] ) ) {
			$settings['feed'] = array();
		}
		$settings['feed']['cache_pinned'] = true;

		$repo->update(
			(int) $feed_id,
			array(
				'settings' => $settings,
			)
		);
	}
}
