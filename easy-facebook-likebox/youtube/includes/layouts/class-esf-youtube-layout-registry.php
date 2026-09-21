<?php
/**
 * YouTube Layout Registry.
 *
 * Registers and instantiates layout classes by type.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/Layouts
 * @since 6.7.5
 */

use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\Layouts\ValueObjects\LayoutDefinition;
use EasySocialFeed\YouTube\Layouts\AccountMapper;
use EasySocialFeed\YouTube\Layouts\PostMapper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_Layout_Registry
 *
 * @since 6.7.5
 */
class ESF_YouTube_Layout_Registry {

	/**
	 * Registered layout types and class names.
	 *
	 * @since 6.7.5
	 * @var array<string, string>
	 */
	private static $layouts = array();

	/**
	 * Default layout type when requested type is not registered.
	 *
	 * @since 6.7.5
	 * @var string
	 */
	const DEFAULT_LAYOUT = 'grid';

	/**
	 * Register a layout type.
	 *
	 * @since 6.7.5
	 *
	 * @param string $type      Layout type key (e.g. 'grid', 'masonry').
	 * @param string $class_name Full class name implementing ESF_YouTube_Layout_Base.
	 * @return void
	 */
	public static function register( $type, $class_name ) {
		$type = is_string( $type ) ? trim( $type ) : '';
		if ( '' === $type || ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
			return;
		}
		self::$layouts[ $type ] = $class_name;
	}

	/**
	 * Create a layout instance for the given type.
	 *
	 * Falls back to default layout if type is not registered. Bridges the
	 * legacy `(feed, videos, account)` call shape used by the Load More REST
	 * endpoints to the shared framework's `(module, definition, feed, posts,
	 * account)` constructor signature, mapping raw rows/arrays to
	 * `AccountSummary`/`Post` value objects as needed.
	 *
	 * @since 6.7.5
	 *
	 * @param string      $type    Layout type.
	 * @param object      $feed    Feed object with settings.
	 * @param array       $videos  Normalized video items.
	 * @param object|null $account Account row, or already-mapped AccountSummary. Optional.
	 * @return ESF_YouTube_Layout_Base|\EasySocialFeed\Layouts\Contracts\Layout
	 */
	public static function make( $type, $feed, $videos, $account = null ) {
		$type = is_string( $type ) ? trim( $type ) : '';

		// Pro layouts fall back to grid without a YouTube plan.
		if ( in_array( $type, array( 'carousel', 'half_width', 'full_width', 'masonry' ), true )
			&& ( ! function_exists( 'esf_youtube_has_youtube_plan' ) || ! esf_youtube_has_youtube_plan() )
		) {
			$type = self::DEFAULT_LAYOUT;
		}

		if ( '' === $type || ! isset( self::$layouts[ $type ] ) ) {
			$type = self::DEFAULT_LAYOUT;
		}
		$class_name = isset( self::$layouts[ $type ] ) ? self::$layouts[ $type ] : 'ESF_YouTube_Layout_Grid';

		$account_summary = self::to_account_summary( $account );
		$definition      = new LayoutDefinition(
			array(
				'slug'       => $type,
				'class_name' => $class_name,
			)
		);

		return new $class_name( 'youtube', $definition, $feed, self::to_posts( $videos, $account_summary ), $account_summary );
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
	 * Map normalized video arrays to Post value objects.
	 *
	 * @since 6.14.0
	 * @param array               $videos          Normalized video items.
	 * @param AccountSummary|null $account_summary Mapped account, used as post author.
	 * @return \EasySocialFeed\Layouts\ValueObjects\Post[]
	 */
	private static function to_posts( $videos, $account_summary ) {
		$posts = array();
		if ( ! is_array( $videos ) ) {
			return $posts;
		}
		foreach ( $videos as $video ) {
			if ( ! is_array( $video ) ) {
				continue;
			}
			$post = PostMapper::from_api_node( $video, $account_summary );
			if ( null !== $post ) {
				$posts[] = $post;
			}
		}
		return $posts;
	}

	/**
	 * Get all registered layout types.
	 *
	 * @since 6.7.5
	 * @return array<string> Layout type keys.
	 */
	public static function get_registered_types() {
		return array_keys( self::$layouts );
	}
}
