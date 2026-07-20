<?php
/**
 * JSON-LD schema builder for modern social feed modules.
 *
 * @package Easy_Social_Feed
 * @subpackage SEO
 * @since 6.9.0
 */

namespace EasySocialFeed\SEO;

use EasySocialFeed\Layouts\Contracts\PostItem;
use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\Layouts\ValueObjects\PostMedia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds schema.org graphs (ItemList + per-post types) for SSR feeds.
 *
 * @since 6.9.0
 */
final class SchemaBuilder {

	/**
	 * Build ItemList + SocialMediaPosting schema for Instagram posts.
	 *
	 * @since 6.9.0
	 *
	 * @param PostItem[]          $posts   Mapped posts.
	 * @param AccountSummary|null $account Feed account.
	 * @param string              $feed_name Feed display name.
	 * @return array<string,mixed>|array<int,array<string,mixed>>
	 */
	public static function for_instagram_posts( array $posts, ?AccountSummary $account, string $feed_name ): array {
		$list_items = array();
		$postings   = array();

		foreach ( $posts as $index => $post ) {
			if ( ! $post instanceof PostItem ) {
				continue;
			}

			$permalink = \esc_url_raw( $post->get_permalink() );
			if ( '' === $permalink ) {
				continue;
			}

			$list_items[] = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'url'      => $permalink,
			);

			$caption = trim( \wp_strip_all_tags( (string) $post->get_text_html() ) );
			$images  = self::instagram_post_images( $post );
			$author  = self::person_from_account( $account );

			$posting = array(
				'@type'         => 'SocialMediaPosting',
				'@id'           => $permalink . '#posting',
				'url'           => $permalink,
				'headline'      => '' !== $caption ? \wp_trim_words( $caption, 20, '…' ) : $permalink,
				'datePublished' => SeoHelper::normalize_datetime_iso( (string) $post->get_created_at() ),
			);

			if ( '' !== $caption ) {
				$posting['articleBody'] = $caption;
			}
			if ( ! empty( $images ) ) {
				$posting['image'] = $images;
			}
			if ( null !== $author ) {
				$posting['author'] = $author;
			}

			$postings[] = $posting;
		}

		return self::wrap_item_list( $feed_name, $list_items, $postings, $account );
	}

	/**
	 * Build ItemList + SocialMediaPosting schema for Twitter/X tweets.
	 *
	 * @since 6.9.0
	 *
	 * @param array<int,array<string,mixed>> $tweets Normalized tweet rows.
	 * @param object|null                    $account Account record.
	 * @param string                         $feed_name Feed display name.
	 * @return array<string,mixed>|array<int,array<string,mixed>>
	 */
	public static function for_twitter_tweets( array $tweets, $account, string $feed_name ): array {
		$list_items = array();
		$postings   = array();

		foreach ( $tweets as $index => $tweet ) {
			if ( ! is_array( $tweet ) ) {
				continue;
			}

			$tweet_url = isset( $tweet['url'] ) ? \esc_url_raw( (string) $tweet['url'] ) : '';
			if ( '' === $tweet_url && isset( $tweet['id'] ) ) {
				$tweet_url = 'https://x.com/i/web/status/' . rawurlencode( (string) $tweet['id'] );
			}
			if ( '' === $tweet_url ) {
				continue;
			}

			$list_items[] = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'url'      => $tweet_url,
			);

			$text = isset( $tweet['text'] ) ? trim( \wp_strip_all_tags( (string) $tweet['text'] ) ) : '';
			if ( '' === $text && isset( $tweet['full_text'] ) ) {
				$text = trim( \wp_strip_all_tags( (string) $tweet['full_text'] ) );
			}

			$created = '';
			if ( isset( $tweet['created_at'] ) ) {
				$created = SeoHelper::normalize_datetime_iso( (string) $tweet['created_at'] );
			}

			$author_name = '';
			if ( is_object( $account ) && isset( $account->name ) ) {
				$author_name = (string) $account->name;
			} elseif ( isset( $tweet['author']['name'] ) ) {
				$author_name = (string) $tweet['author']['name'];
			}

			$posting = array(
				'@type'    => 'SocialMediaPosting',
				'@id'      => $tweet_url . '#posting',
				'url'      => $tweet_url,
				'headline' => '' !== $text ? \wp_trim_words( $text, 20, '…' ) : $tweet_url,
			);

			if ( '' !== $created ) {
				$posting['datePublished'] = $created;
			}
			if ( '' !== $text ) {
				$posting['articleBody'] = $text;
			}

			$images = self::twitter_tweet_images( $tweet );
			if ( ! empty( $images ) ) {
				$posting['image'] = $images;
			}

			if ( '' !== $author_name ) {
				$posting['author'] = array(
					'@type' => 'Person',
					'name'  => $author_name,
				);
			}

			$postings[] = $posting;
		}

		$account_summary = null;
		if ( is_object( $account ) ) {
			$account_summary = new AccountSummary(
				array(
					'id'     => isset( $account->id ) ? (int) $account->id : 0,
					'name'   => isset( $account->name ) ? (string) $account->name : '',
					'handle'      => isset( $account->username ) ? (string) $account->username : '',
					'profile_url' => isset( $account->profile_url ) ? (string) $account->profile_url : '',
				)
			);
		}

		return self::wrap_item_list( $feed_name, $list_items, $postings, $account_summary );
	}

	/**
	 * Build ItemList + VideoObject schema for YouTube videos.
	 *
	 * @since 6.9.0
	 *
	 * @param array<int,array<string,mixed>> $videos Normalized video rows.
	 * @param object|null                    $account Channel record.
	 * @param string                         $feed_name Feed display name.
	 * @return array<string,mixed>|array<int,array<string,mixed>>
	 */
	public static function for_youtube_videos( array $videos, $account, string $feed_name ): array {
		$list_items = array();
		$video_objs = array();

		$channel_name = is_object( $account ) && isset( $account->name ) ? (string) $account->name : '';

		foreach ( $videos as $index => $video ) {
			if ( ! is_array( $video ) ) {
				continue;
			}

			$video_id = isset( $video['id'] ) ? (string) $video['id'] : '';
			$watch_url = '' !== $video_id
				? 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id )
				: ( isset( $video['url'] ) ? \esc_url_raw( (string) $video['url'] ) : '' );

			if ( '' === $watch_url ) {
				continue;
			}

			$list_items[] = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'url'      => $watch_url,
			);

			$title = isset( $video['title'] ) ? trim( (string) $video['title'] ) : '';
			$desc  = isset( $video['description'] ) ? trim( \wp_strip_all_tags( (string) $video['description'] ) ) : '';
			$thumb = isset( $video['thumbnail'] ) ? \esc_url_raw( (string) $video['thumbnail'] ) : '';
			if ( '' === $thumb && isset( $video['thumbnails']['high']['url'] ) ) {
				$thumb = \esc_url_raw( (string) $video['thumbnails']['high']['url'] );
			}

			$published = '';
			if ( isset( $video['published_at'] ) ) {
				$published = SeoHelper::normalize_datetime_iso( (string) $video['published_at'] );
			}

			$video_obj = array(
				'@type'    => 'VideoObject',
				'@id'      => $watch_url . '#video',
				'url'      => $watch_url,
				'name'     => '' !== $title ? $title : $watch_url,
			);

			if ( '' !== $desc ) {
				$video_obj['description'] = \wp_trim_words( $desc, 40, '…' );
			}
			if ( '' !== $thumb ) {
				$video_obj['thumbnailUrl'] = $thumb;
			}
			if ( '' !== $published ) {
				$video_obj['uploadDate'] = $published;
			}
			if ( '' !== $channel_name ) {
				$video_obj['author'] = array(
					'@type' => 'Person',
					'name'  => $channel_name,
				);
			}

			$video_objs[] = $video_obj;
		}

		$account_summary = null;
		if ( is_object( $account ) ) {
			$account_summary = new AccountSummary(
				array(
					'id'          => isset( $account->id ) ? (int) $account->id : 0,
					'name'        => $channel_name,
					'profile_url' => isset( $account->channel_url ) ? (string) $account->channel_url : '',
				)
			);
		}

		return self::wrap_item_list( $feed_name, $list_items, $video_objs, $account_summary );
	}

	/**
	 * @param string              $feed_name Feed title.
	 * @param array<int,mixed>    $list_items ListItem nodes.
	 * @param array<int,mixed>    $entities   Per-item schema nodes.
	 * @param AccountSummary|null $account    Feed account.
	 * @return array<int,array<string,mixed>>
	 */
	private static function wrap_item_list( string $feed_name, array $list_items, array $entities, ?AccountSummary $account ): array {
		if ( empty( $list_items ) ) {
			return array();
		}

		$graph = array();

		$item_list = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => '' !== trim( $feed_name ) ? trim( $feed_name ) : \__( 'Social feed', 'easy-facebook-likebox' ),
			'numberOfItems'   => count( $list_items ),
			'itemListElement' => $list_items,
		);

		$publisher = self::organization_from_account( $account );
		if ( null !== $publisher ) {
			$item_list['publisher'] = $publisher;
		}

		$graph[] = $item_list;

		foreach ( $entities as $entity ) {
			if ( is_array( $entity ) && ! empty( $entity ) ) {
				$entity['@context'] = 'https://schema.org';
				$graph[]            = $entity;
			}
		}

		/**
		 * Filter the JSON-LD graph before it is embedded in feed HTML.
		 *
		 * @since 6.9.0
		 *
		 * @param array<int,array<string,mixed>> $graph     Schema nodes.
		 * @param string                         $feed_name Feed display name.
		 * @param AccountSummary|null            $account   Feed account.
		 */
		$graph = \apply_filters( 'esf_feed_schema_graph', $graph, $feed_name, $account );

		return is_array( $graph ) ? $graph : array();
	}

	/**
	 * @param AccountSummary|null $account Feed account.
	 * @return array<string,mixed>|null
	 */
	private static function person_from_account( ?AccountSummary $account ): ?array {
		if ( null === $account || '' === trim( $account->get_name() ) ) {
			return null;
		}

		$person = array(
			'@type' => 'Person',
			'name'  => $account->get_name(),
		);

		$url = $account->get_profile_url();
		if ( '' !== $url ) {
			$person['url'] = \esc_url_raw( $url );
		}

		return $person;
	}

	/**
	 * @param AccountSummary|null $account Feed account.
	 * @return array<string,mixed>|null
	 */
	private static function organization_from_account( ?AccountSummary $account ): ?array {
		if ( null === $account || '' === trim( $account->get_name() ) ) {
			return null;
		}

		$org = array(
			'@type' => 'Organization',
			'name'  => $account->get_name(),
		);

		$url = $account->get_profile_url();
		if ( '' !== $url ) {
			$org['url'] = \esc_url_raw( $url );
		}

		return $org;
	}

	/**
	 * @param PostItem $post Instagram post.
	 * @return array<int,string>
	 */
	private static function instagram_post_images( PostItem $post ): array {
		$urls = array();

		foreach ( $post->get_media() as $media ) {
			if ( ! $media instanceof PostMedia ) {
				continue;
			}
			$url = \esc_url_raw( $media->get_display_url() );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * @param array<string,mixed> $tweet Normalized tweet.
	 * @return array<int,string>
	 */
	private static function twitter_tweet_images( array $tweet ): array {
		$urls = array();

		if ( ! isset( $tweet['media'] ) || ! is_array( $tweet['media'] ) ) {
			return $urls;
		}

		foreach ( $tweet['media'] as $media ) {
			if ( ! is_array( $media ) ) {
				continue;
			}
			$url = '';
			if ( isset( $media['url'] ) ) {
				$url = \esc_url_raw( (string) $media['url'] );
			} elseif ( isset( $media['preview_image_url'] ) ) {
				$url = \esc_url_raw( (string) $media['preview_image_url'] );
			}
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
	}
}
