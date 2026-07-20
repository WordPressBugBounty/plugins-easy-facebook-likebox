<?php
/**
 * Wires shared SEO / AEO enhancements into modern feed renderers.
 *
 * @package Easy_Social_Feed
 * @subpackage SEO
 * @since 6.9.0
 */

namespace EasySocialFeed\SEO;

use EasySocialFeed\Layouts\Contracts\PostItem;
use EasySocialFeed\Layouts\ValueObjects\AccountSummary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers filters on Instagram (shared Renderer), Twitter, and YouTube outputs.
 *
 * @since 6.9.0
 */
final class FeedSeoIntegration {

	/**
	 * Bootstrap SEO hooks once per request.
	 *
	 * @since 6.9.0
	 */
	public static function init(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		// Instagram (shared layouts renderer).
		\add_filter( 'esf_layouts_render_wrapper_attributes', array( __CLASS__, 'filter_layouts_wrapper_attributes' ), 10, 4 );
		\add_filter( 'esf_instagram_layout_render_html', array( __CLASS__, 'filter_instagram_render_html' ), 20, 5 );

		// Twitter/X.
		\add_filter( 'esf_twitter_render_wrapper_attributes', array( __CLASS__, 'filter_twitter_wrapper_attributes' ), 10, 4 );
		\add_filter( 'esf_twitter_render_html', array( __CLASS__, 'filter_twitter_render_html' ), 20, 6 );

		// YouTube.
		\add_filter( 'esf_youtube_render_wrapper_attributes', array( __CLASS__, 'filter_youtube_wrapper_attributes' ), 10, 4 );
		\add_filter( 'esf_youtube_render_html', array( __CLASS__, 'filter_youtube_render_html' ), 20, 5 );
	}

	/**
	 * Add feed landmark attributes for Instagram module renders.
	 *
	 * @since 6.9.0
	 *
	 * @param array<string,string> $attrs      Existing attributes.
	 * @param string               $module     Module slug.
	 * @param object               $feed       Feed object.
	 * @param mixed                $definition Layout definition.
	 * @return array<string,string>
	 */
	public static function filter_layouts_wrapper_attributes( array $attrs, string $module, $feed, $definition ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( 'instagram' !== \sanitize_key( $module ) ) {
			return $attrs;
		}

		return self::merge_feed_landmark_attributes( $attrs, self::feed_name_from_object( $feed ) );
	}

	/**
	 * Append JSON-LD and noscript fallbacks to Instagram feed HTML.
	 *
	 * @since 6.9.0
	 *
	 * @param string   $html       Rendered feed HTML.
	 * @param object   $feed       Feed object.
	 * @param mixed    $definition Layout definition.
	 * @param PostItem[] $posts    Posts in this render.
	 * @param AccountSummary|null $account Account summary.
	 * @return string
	 */
	public static function filter_instagram_render_html( string $html, $feed, $definition, array $posts, ?AccountSummary $account ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return self::append_seo_extras(
			$html,
			SchemaBuilder::for_instagram_posts( $posts, $account, self::feed_name_from_object( $feed ) ),
			self::noscript_links_from_posts( $posts )
		);
	}

	/**
	 * Add feed landmark attributes for Twitter feeds.
	 *
	 * @since 6.9.0
	 *
	 * @param array<string,string> $attrs       Existing attributes.
	 * @param int                  $feed_id     Feed ID.
	 * @param object               $feed_obj    Feed object.
	 * @param string               $layout_type Layout slug.
	 * @return array<string,string>
	 */
	public static function filter_twitter_wrapper_attributes( array $attrs, int $feed_id, $feed_obj, string $layout_type ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return self::merge_feed_landmark_attributes( $attrs, self::feed_name_from_object( $feed_obj ) );
	}

	/**
	 * Append JSON-LD and noscript fallbacks to Twitter feed HTML.
	 *
	 * @since 6.9.0
	 *
	 * @param string               $html        Rendered feed HTML.
	 * @param int                  $feed_id     Feed ID.
	 * @param object               $feed_obj    Feed object.
	 * @param string               $layout_type Layout slug.
	 * @param array<int,mixed>     $tweets      Tweet rows.
	 * @param object|null          $account     Account record.
	 * @return string
	 */
	public static function filter_twitter_render_html( string $html, int $feed_id, $feed_obj, string $layout_type, array $tweets, $account ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return self::append_seo_extras(
			$html,
			SchemaBuilder::for_twitter_tweets( $tweets, $account, self::feed_name_from_object( $feed_obj ) ),
			self::noscript_links_from_tweets( $tweets )
		);
	}

	/**
	 * Add feed landmark attributes for YouTube feeds.
	 *
	 * @since 6.9.0
	 *
	 * @param array<string,string> $attrs       Existing attributes.
	 * @param int                  $feed_id     Feed ID.
	 * @param object               $feed_obj    Feed object.
	 * @param string               $layout_type Layout slug.
	 * @return array<string,string>
	 */
	public static function filter_youtube_wrapper_attributes( array $attrs, int $feed_id, $feed_obj, string $layout_type ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return self::merge_feed_landmark_attributes( $attrs, self::feed_name_from_object( $feed_obj ) );
	}

	/**
	 * Append JSON-LD and noscript fallbacks to YouTube feed HTML.
	 *
	 * @since 6.9.0
	 *
	 * @param string           $html        Rendered feed HTML.
	 * @param int              $feed_id     Feed ID.
	 * @param object           $feed_obj    Feed object.
	 * @param string           $layout_type Layout slug.
	 * @param array<int,mixed> $videos      Video rows.
	 * @return string
	 */
	public static function filter_youtube_render_html( string $html, int $feed_id, $feed_obj, string $layout_type, array $videos ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$account = null;
		if ( is_object( $feed_obj ) && isset( $feed_obj->account_id ) && class_exists( '\ESF_YouTube_Account_Repository' ) ) {
			$account = \ESF_YouTube_Account_Repository::get_instance()->get_by_id( (int) $feed_obj->account_id );
		}

		return self::append_seo_extras(
			$html,
			SchemaBuilder::for_youtube_videos( $videos, $account, self::feed_name_from_object( $feed_obj ) ),
			self::noscript_links_from_videos( $videos )
		);
	}

	/**
	 * @param array<string,string> $attrs     Existing attributes.
	 * @param string               $feed_name Feed display name.
	 * @return array<string,string>
	 */
	private static function merge_feed_landmark_attributes( array $attrs, string $feed_name ): array {
		return array_merge( $attrs, SeoHelper::feed_landmark_attributes( $feed_name ) );
	}

	/**
	 * @param object|null $feed Feed object.
	 * @return string
	 */
	private static function feed_name_from_object( $feed ): string {
		if ( is_object( $feed ) && isset( $feed->name ) ) {
			return trim( (string) $feed->name );
		}

		return '';
	}

	/**
	 * @param string                         $html   Feed HTML.
	 * @param array<int,array<string,mixed>> $schema Schema graph.
	 * @param array<int,array{url:string,label:string}> $noscript_links Permalink rows.
	 * @return string
	 */
	private static function append_seo_extras( string $html, array $schema, array $noscript_links ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		$extras = SeoHelper::render_json_ld_script( $schema );
		$extras .= SeoHelper::render_noscript_link_list( $noscript_links );

		if ( '' === $extras ) {
			return $html;
		}

		return $html . $extras;
	}

	/**
	 * @param PostItem[] $posts Posts.
	 * @return array<int,array{url:string,label:string}>
	 */
	private static function noscript_links_from_posts( array $posts ): array {
		$links = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof PostItem ) {
				continue;
			}
			$url = $post->get_permalink();
			if ( '' === $url ) {
				continue;
			}
			$label = trim( \wp_strip_all_tags( (string) $post->get_text_html() ) );
			if ( '' === $label ) {
				$label = $url;
			} else {
				$label = \wp_trim_words( $label, 8, '…' );
			}
			$links[] = array(
				'url'   => $url,
				'label' => $label,
			);
		}

		return $links;
	}

	/**
	 * @param array<int,mixed> $tweets Tweet rows.
	 * @return array<int,array{url:string,label:string}>
	 */
	private static function noscript_links_from_tweets( array $tweets ): array {
		$links = array();

		foreach ( $tweets as $tweet ) {
			if ( ! is_array( $tweet ) ) {
				continue;
			}
			$url = isset( $tweet['url'] ) ? (string) $tweet['url'] : '';
			if ( '' === $url && isset( $tweet['id'] ) ) {
				$url = 'https://x.com/i/web/status/' . rawurlencode( (string) $tweet['id'] );
			}
			if ( '' === $url ) {
				continue;
			}
			$label = isset( $tweet['text'] ) ? trim( \wp_strip_all_tags( (string) $tweet['text'] ) ) : '';
			if ( '' === $label && isset( $tweet['full_text'] ) ) {
				$label = trim( \wp_strip_all_tags( (string) $tweet['full_text'] ) );
			}
			if ( '' === $label ) {
				$label = $url;
			} else {
				$label = \wp_trim_words( $label, 8, '…' );
			}
			$links[] = array(
				'url'   => $url,
				'label' => $label,
			);
		}

		return $links;
	}

	/**
	 * @param array<int,mixed> $videos Video rows.
	 * @return array<int,array{url:string,label:string}>
	 */
	private static function noscript_links_from_videos( array $videos ): array {
		$links = array();

		foreach ( $videos as $video ) {
			if ( ! is_array( $video ) ) {
				continue;
			}
			$video_id = isset( $video['id'] ) ? (string) $video['id'] : '';
			$url      = '' !== $video_id
				? 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id )
				: ( isset( $video['url'] ) ? (string) $video['url'] : '' );
			if ( '' === $url ) {
				continue;
			}
			$label = isset( $video['title'] ) ? trim( (string) $video['title'] ) : $url;
			$links[] = array(
				'url'   => $url,
				'label' => $label,
			);
		}

		return $links;
	}
}
