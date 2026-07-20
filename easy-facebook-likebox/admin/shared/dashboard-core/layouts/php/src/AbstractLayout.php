<?php
/**
 * Abstract layout base class.
 *
 * Concrete module layouts extend this and implement `render()` (and may
 * override `render_post` for tile-style layouts). The base owns settings
 * access, hook firing, and the shared primitives.
 *
 * Hook fan-out: every filter/action is fired twice — once with the generic
 * `esf_layouts_*` name and once with the per-module alias
 * `esf_{module}_layout_*`. This lets us migrate X/YT to the shared base later
 * without breaking any registered listener.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts;

use EasySocialFeed\Layouts\Contracts\Layout;
use EasySocialFeed\Layouts\Contracts\PostItem;
use EasySocialFeed\Layouts\Primitives\AccountHeader;
use EasySocialFeed\Layouts\Primitives\EmptyState;
use EasySocialFeed\Layouts\Primitives\LoadMoreButton;
use EasySocialFeed\Layouts\Primitives\PostCard;
use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\Layouts\ValueObjects\LayoutDefinition;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AbstractLayout implements Layout {

	/**
	 * Module slug (instagram|twitter|youtube).
	 *
	 * @var string
	 */
	protected $module;

	/**
	 * Feed record (object with id, name, account_id, settings).
	 *
	 * @var object
	 */
	protected $feed;

	/**
	 * Owning account.
	 *
	 * @var AccountSummary|null
	 */
	protected $account;

	/**
	 * Posts to render.
	 *
	 * @var PostItem[]
	 */
	protected $posts;

	/**
	 * Layout definition (slug, supports, assets, ...).
	 *
	 * @var LayoutDefinition
	 */
	protected $definition;

	/**
	 * @param string           $module     Module slug.
	 * @param LayoutDefinition $definition Layout definition.
	 * @param object           $feed       Feed record.
	 * @param PostItem[]       $posts      Pre-fetched, pre-mapped posts.
	 * @param AccountSummary|null $account Account summary for the feed.
	 */
	public function __construct(
		string $module,
		LayoutDefinition $definition,
		$feed,
		array $posts,
		?AccountSummary $account = null
	) {
		$this->module     = sanitize_key( $module );
		$this->definition = $definition;
		$this->feed       = $feed;
		$this->posts      = array_values( array_filter( $posts, static fn( $p ) => $p instanceof PostItem ) );
		$this->account    = $account;
	}

	public function get_definition(): LayoutDefinition {
		return $this->definition;
	}

	public function get_module(): string {
		return $this->module;
	}

	public function get_feed() {
		return $this->feed;
	}

	public function get_account(): ?AccountSummary {
		return $this->account;
	}

	/**
	 * @return PostItem[]
	 */
	public function get_posts(): array {
		return $this->posts;
	}

	abstract public function render(): string;

	/**
	 * Render the account header using shared primitive + hooks.
	 *
	 * @param array<string,mixed> $options Header rendering options (see AccountHeader::render).
	 */
	protected function render_header( array $options = array() ): string {
		if ( null === $this->account ) {
			return '';
		}

		$settings = $this->get_settings();
		$header   = isset( $settings['header'] ) && is_array( $settings['header'] ) ? $settings['header'] : array();

		$show = ! isset( $header['show'] ) || (bool) $header['show'];
		$show = (bool) $this->apply_filters( 'show_header', $show, $header );
		if ( ! $show ) {
			return '';
		}

		$merged_options = array_merge(
			array(
				'show_avatar'        => ! isset( $header['show_avatar'] ) || ! empty( $header['show_avatar'] ),
				'show_name'          => ! isset( $header['show_name'] ) || ! empty( $header['show_name'] ),
				'show_bio'           => ! isset( $header['show_bio'] ) || ! empty( $header['show_bio'] ),
				'show_follow_button' => ! empty( $header['show_follow_button'] ),
			),
			$options
		);
		$merged_options = $this->apply_filters( 'header_data', $merged_options, $header );

		$this->do_action( 'before_header', $merged_options );
		$html = AccountHeader::render( $this->module, $this->account, $merged_options );
		$this->do_action( 'after_header', $merged_options );

		return (string) $this->apply_filters( 'header_html', $html, $merged_options );
	}

	/**
	 * Render the empty-state notice.
	 */
	protected function render_empty_state( string $message ): string {
		$html = EmptyState::render( $this->module, $message );

		return (string) $this->apply_filters( 'empty_state_html', $html, $message );
	}

	/**
	 * Render a single post card via shared primitive + hooks.
	 *
	 * @param array<string,mixed> $options Card rendering options (see PostCard::render).
	 */
	protected function render_post_card( PostItem $post, array $options = array() ): string {
		$context = array(
			'post'    => $post,
			'options' => $options,
		);
		$context = $this->apply_filters( 'post_context', $context );

		$this->do_action( 'before_post_card', $context );
		$html = PostCard::render(
			$this->module,
			$context['post'],
			isset( $context['options'] ) && is_array( $context['options'] ) ? $context['options'] : array()
		);
		$this->do_action( 'after_post_card', $context );

		return (string) $this->apply_filters( 'post_card_html', $html, $context );
	}

	/**
	 * Render the load-more button when the feed has more items than `per_page`.
	 *
	 * Modules opt-in via the `load_more` support flag and the feed setting
	 * `feed.load_more`. Plan-gating is left to the calling layout via
	 * `$can_load_more`.
	 */
	protected function render_load_more( int $per_page, bool $can_load_more, string $label, string $aria_label = '' ): string {
		if ( ! $can_load_more ) {
			return '';
		}

		$settings = $this->get_settings();
		$feed_cfg = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();
		$total    = count( $this->posts );

		if ( $total <= $per_page ) {
			return '';
		}

		$feed_id   = isset( $this->feed->id ) ? (int) $this->feed->id : 0;
		$bg_color         = isset( $feed_cfg['load_more_bg_color'] ) ? (string) $feed_cfg['load_more_bg_color'] : '';
		$txt_color        = isset( $feed_cfg['load_more_text_color'] ) ? (string) $feed_cfg['load_more_text_color'] : '';
		$hover_bg_color   = isset( $feed_cfg['load_more_hover_bg_color'] ) ? (string) $feed_cfg['load_more_hover_bg_color'] : '';
		$hover_text_color = isset( $feed_cfg['load_more_hover_text_color'] ) ? (string) $feed_cfg['load_more_hover_text_color'] : '';

		return LoadMoreButton::render(
			$this->module,
			array(
				'feed_id'          => $feed_id,
				'offset'           => $per_page,
				'next_token'       => isset( $feed_cfg['next_token'] ) ? (string) $feed_cfg['next_token'] : '',
				'label'            => $label,
				'aria_label'       => '' !== $aria_label ? $aria_label : $label,
				'bg_color'         => $bg_color,
				'text_color'       => $txt_color,
				'hover_bg_color'   => $hover_bg_color,
				'hover_text_color' => $hover_text_color,
			)
		);
	}

	/**
	 * Compute the visible slice + total count for paging.
	 *
	 * @return array{posts: PostItem[], total: int, per_page: int}
	 */
	protected function get_display_state(): array {
		$settings = $this->get_settings();
		$feed_cfg = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();
		$per_page = isset( $feed_cfg['per_page'] ) ? max( 1, min( 50, (int) $feed_cfg['per_page'] ) ) : 9;
		$total    = count( $this->posts );
		$visible  = $total > $per_page ? array_slice( $this->posts, 0, $per_page ) : $this->posts;

		return array(
			'posts'    => $visible,
			'total'    => $total,
			'per_page' => $per_page,
		);
	}

	/**
	 * Return the merged settings array from the feed object.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_settings(): array {
		return isset( $this->feed->settings ) && is_array( $this->feed->settings )
			? $this->feed->settings
			: array();
	}

	/**
	 * Read a nested setting via dot-notation (e.g. "layout.grid.columns").
	 *
	 * @param string $path    Dot-notation path.
	 * @param mixed  $default Default when the path is missing.
	 * @return mixed
	 */
	protected function setting( string $path, $default = null ) {
		$cursor = $this->get_settings();
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return $default;
			}
			$cursor = $cursor[ $segment ];
		}
		return $cursor;
	}

	/**
	 * Apply both the generic and module-aliased filters.
	 *
	 * @param string $hook  Hook suffix (e.g. "header_html").
	 * @param mixed  $value Value to filter.
	 * @param mixed  ...$args Additional args passed through to listeners.
	 * @return mixed
	 */
	protected function apply_filters( string $hook, $value, ...$args ) {
		$generic = 'esf_layouts_' . $hook;
		$aliased = 'esf_' . $this->module . '_layout_' . $hook;

		$args_for_generic = array_merge( array( $value, $this->module, $this->feed, $this->account, $this ), $args );
		$value            = apply_filters( $generic, ...$args_for_generic );

		$args_for_alias = array_merge( array( $value, $this->feed, $this->account, $this ), $args );
		return apply_filters( $aliased, ...$args_for_alias );
	}

	/**
	 * Fire both the generic and module-aliased actions.
	 *
	 * @param string $hook Hook suffix (e.g. "before_header").
	 * @param mixed  ...$args Action args.
	 */
	protected function do_action( string $hook, ...$args ): void {
		$generic = 'esf_layouts_' . $hook;
		$aliased = 'esf_' . $this->module . '_layout_' . $hook;

		$args_for_generic = array_merge( array( $this->module, $this->feed, $this->account, $this ), $args );
		do_action( $generic, ...$args_for_generic );

		$args_for_alias = array_merge( array( $this->feed, $this->account, $this ), $args );
		do_action( $aliased, ...$args_for_alias );
	}
}
