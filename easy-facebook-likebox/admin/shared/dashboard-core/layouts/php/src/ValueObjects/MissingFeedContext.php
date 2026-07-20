<?php
/**
 * Context for rendering a missing/deleted feed placeholder on the frontend.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\ValueObjects
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\ValueObjects;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object describing a shortcode that references a deleted feed.
 *
 * @since 6.9.0
 */
final class MissingFeedContext {

	/**
	 * Module slug (instagram, twitter, youtube).
	 *
	 * @var string
	 */
	public $module;

	/**
	 * Missing feed ID from the shortcode.
	 *
	 * @var int
	 */
	public $feed_id;

	/**
	 * Shortcode tag without brackets.
	 *
	 * @var string
	 */
	public $shortcode_tag;

	/**
	 * Post ID where the shortcode was rendered (0 for widgets/theme templates).
	 *
	 * @var int
	 */
	public $post_id;

	/**
	 * Whether the current user can manage this module.
	 *
	 * @var bool
	 */
	public $can_manage;

	/**
	 * Public-facing message (cache-safe, shown to all visitors).
	 *
	 * @var string
	 */
	public $public_message;

	/**
	 * Admin dashboard URL for the module feeds screen.
	 *
	 * @var string
	 */
	public $dashboard_url;

	/**
	 * REST path template for live preview (`{id}` placeholder).
	 *
	 * @var string
	 */
	public $preview_rest_path;

	/**
	 * REST path for listing feeds.
	 *
	 * @var string
	 */
	public $feeds_rest_path;

	/**
	 * DOM root element ID for this placeholder.
	 *
	 * @var string
	 */
	public $root_id;

	/**
	 * @param string $module             Module slug.
	 * @param int    $feed_id            Missing feed ID.
	 * @param string $shortcode_tag      Shortcode tag.
	 * @param int    $post_id            Host post ID.
	 * @param bool   $can_manage         Whether the viewer can manage the module.
	 * @param string $public_message     Visitor message.
	 * @param string $dashboard_url      Module dashboard URL.
	 * @param string $preview_rest_path  Preview REST path template.
	 * @param string $feeds_rest_path    Feeds list REST path.
	 */
	public function __construct(
		string $module,
		int $feed_id,
		string $shortcode_tag,
		int $post_id,
		bool $can_manage,
		string $public_message,
		string $dashboard_url,
		string $preview_rest_path,
		string $feeds_rest_path
	) {
		$this->module            = sanitize_key( $module );
		$this->feed_id           = max( 0, $feed_id );
		$this->shortcode_tag     = sanitize_key( $shortcode_tag );
		$this->post_id           = max( 0, $post_id );
		$this->can_manage        = $can_manage;
		$this->public_message    = $public_message;
		$this->dashboard_url     = $dashboard_url;
		$this->preview_rest_path = $preview_rest_path;
		$this->feeds_rest_path   = $feeds_rest_path;
		$this->root_id           = sprintf( 'esf-%s-feed-missing-%d', $this->module, $this->feed_id );
	}
}
