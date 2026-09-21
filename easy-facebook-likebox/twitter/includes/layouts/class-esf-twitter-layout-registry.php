<?php
/**
 * Twitter Layout Registry
 *
 * Stores registered layout class names and creates layout instances.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/Layouts
 * @since 6.7.6
 */

use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\Layouts\ValueObjects\LayoutDefinition;
use EasySocialFeed\Twitter\Layouts\AccountMapper;
use EasySocialFeed\Twitter\Layouts\PostMapper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Twitter_Layout_Registry
 *
 * @since 6.7.6
 */
class ESF_Twitter_Layout_Registry {

	/**
	 * Registered layout class names keyed by layout type slug.
	 *
	 * @since 6.7.6
	 * @var array<string, string>
	 */
	private static $layouts = array();

	/**
	 * Register a layout class for a given slug.
	 *
	 * @since 6.7.6
	 * @param string $type       Layout type slug (e.g. 'timeline').
	 * @param string $class_name Fully-qualified class name.
	 * @return void
	 */
	public static function register( $type, $class_name ) {
		self::$layouts[ (string) $type ] = (string) $class_name;
	}

	/**
	 * Create a layout instance for the given type.
	 *
	 * Falls back to 'timeline' when the requested type is not registered.
	 * Bridges the legacy `(feed, tweets, account)` call shape used by the
	 * Load More REST endpoint to the shared framework's `(module, definition,
	 * feed, posts, account)` constructor signature, mapping raw rows/arrays
	 * to `AccountSummary`/`Post` value objects as needed.
	 *
	 * @since 6.7.6
	 * @param string      $type    Layout type slug.
	 * @param object      $feed    Feed object (id, name, account_id, settings).
	 * @param array       $tweets  Normalized tweet items.
	 * @param object|null $account Account row, or already-mapped AccountSummary.
	 * @return object Layout instance (Timeline/Carousel/Half Width).
	 */
	public static function make( $type, $feed, $tweets, $account = null ) {
		$type = (string) $type;

		// Pro layouts fall back to timeline without an X / Twitter plan.
		if ( in_array( $type, array( 'carousel', 'grid', 'half_width', 'masonry' ), true )
			&& ( ! function_exists( 'esf_twitter_has_twitter_plan' ) || ! esf_twitter_has_twitter_plan() )
		) {
			$type = 'timeline';
		}

		$class = isset( self::$layouts[ $type ] ) ? self::$layouts[ $type ] : '';

		if ( '' === $class || ! class_exists( $class ) ) {
			// Fallback to the first registered layout.
			$class = reset( self::$layouts );
		}

		if ( ! $class || ! class_exists( $class ) ) {
			$class = 'ESF_Twitter_Layout_Timeline';
		}

		$account_summary = self::to_account_summary( $account );
		$definition      = new LayoutDefinition(
			array(
				'slug'       => $type,
				'class_name' => $class,
			)
		);

		return new $class( 'twitter', $definition, $feed, self::to_posts( $tweets, $account_summary ), $account_summary );
	}

	/**
	 * Normalize an account argument to an AccountSummary value object.
	 *
	 * @since 6.14.0
	 * @param mixed $account Account row (object/array), AccountSummary, or null.
	 * @return AccountSummary|null
	 */
	private static function to_account_summary( $account ) {
		if ( $account instanceof AccountSummary ) {
			return $account;
		}
		if ( null === $account ) {
			return null;
		}
		return AccountMapper::from_row( $account );
	}

	/**
	 * Map normalized tweet arrays to Post value objects.
	 *
	 * @since 6.14.0
	 * @param array               $tweets          Normalized tweet items.
	 * @param AccountSummary|null $account_summary Mapped account, used as post author fallback.
	 * @return \EasySocialFeed\Layouts\ValueObjects\Post[]
	 */
	private static function to_posts( $tweets, $account_summary ) {
		$posts = array();
		if ( ! is_array( $tweets ) ) {
			return $posts;
		}
		foreach ( $tweets as $tweet ) {
			if ( ! is_array( $tweet ) ) {
				continue;
			}
			$post = PostMapper::from_api_node( $tweet, $account_summary );
			if ( null !== $post ) {
				$posts[] = $post;
			}
		}
		return $posts;
	}

	/**
	 * Get all registered layout slugs.
	 *
	 * @since 6.7.6
	 * @return string[]
	 */
	public static function get_registered_types() {
		return array_keys( self::$layouts );
	}
}
