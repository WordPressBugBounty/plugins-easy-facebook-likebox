<?php
/**
 * Twitter API tweet → Post mapper.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter\Layouts
 * @since 6.9.0
 */

namespace EasySocialFeed\Twitter\Layouts;

use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\Layouts\ValueObjects\Post;
use EasySocialFeed\Layouts\ValueObjects\PostMedia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a Twitter API tweet node to a Post value object.
 *
 * @since 6.9.0
 */
final class PostMapper {

	/**
	 * Convert a tweet array to a Post value object.
	 *
	 * @param array<string,mixed> $tweet   Normalized tweet from cache/API.
	 * @param AccountSummary|null $account Owning account.
	 * @return Post|null
	 */
	public static function from_api_node( array $tweet, ?AccountSummary $account = null ): ?Post {
		// Handle both raw API format (id) and normalized cached format (tweet_id)
		$id = '';
		if ( isset( $tweet['id'] ) ) {
			$id = (string) $tweet['id'];
		} elseif ( isset( $tweet['tweet_id'] ) ) {
			$id = (string) $tweet['tweet_id'];
		}

		if ( '' === $id ) {
			return null;
		}

		$text = isset( $tweet['text'] ) ? (string) $tweet['text'] : '';

		// Use precomputed text_html from normalized format if available, otherwise format it
		$text_html = '';
		if ( isset( $tweet['text_html'] ) && '' !== $tweet['text_html'] ) {
			$text_html = (string) $tweet['text_html'];
		}

		$author_data = isset( $tweet['author'] ) && is_array( $tweet['author'] ) ? $tweet['author'] : array();

		// Build author from tweet data if not provided
		$author = $account;
		if ( null === $author && ! empty( $author_data ) ) {
			$author = new AccountSummary(
				array(
					'id'          => isset( $author_data['id'] ) ? (int) $author_data['id'] : 0,
					'name'        => isset( $author_data['name'] ) ? (string) $author_data['name'] : '',
					'handle'      => isset( $author_data['username'] ) ? (string) $author_data['username'] : '',
					'avatar_url'  => isset( $author_data['profile_image_url'] ) ? (string) $author_data['profile_image_url'] : '',
					'profile_url' => isset( $author_data['username'] ) ? 'https://twitter.com/' . $author_data['username'] : '',
					'is_verified' => isset( $author_data['verified'] ) ? (bool) $author_data['verified'] : false,
				)
			);
		}

		// Handle both raw API format (public_metrics) and normalized cached format (flat keys)
		$metrics = isset( $tweet['public_metrics'] ) && is_array( $tweet['public_metrics'] )
			? $tweet['public_metrics']
			: array();

		// Extract metrics from either nested or flat structure
		$like_count    = 0;
		$reply_count   = 0;
		$retweet_count = 0;
		$quote_count   = 0;

		if ( ! empty( $metrics ) ) {
			// Raw API format: nested in public_metrics
			$like_count    = isset( $metrics['like_count'] ) ? (int) $metrics['like_count'] : 0;
			$reply_count   = isset( $metrics['reply_count'] ) ? (int) $metrics['reply_count'] : 0;
			$retweet_count = isset( $metrics['retweet_count'] ) ? (int) $metrics['retweet_count'] : 0;
			$quote_count   = isset( $metrics['quote_count'] ) ? (int) $metrics['quote_count'] : 0;
		} else {
			// Normalized cached format: flat keys
			$like_count    = isset( $tweet['like_count'] ) ? (int) $tweet['like_count'] : 0;
			$reply_count   = isset( $tweet['reply_count'] ) ? (int) $tweet['reply_count'] : 0;
			$retweet_count = isset( $tweet['retweet_count'] ) ? (int) $tweet['retweet_count'] : 0;
			$quote_count   = isset( $tweet['quote_count'] ) ? (int) $tweet['quote_count'] : 0;
		}

		// Use precomputed text_html if available, otherwise format it
		if ( '' === $text_html ) {
			$text_html = self::format_text_html( $text, $tweet );
		}

		return new Post(
			array(
				'id'         => $id,
				'permalink'  => self::build_permalink( $tweet ),
				'created_at' => isset( $tweet['created_at'] ) ? (string) $tweet['created_at'] : '',
				'text_html'  => $text_html,
				'author'     => $author,
				'media'      => self::extract_media( $tweet ),
				'metrics'    => array(
					'comments' => $reply_count,
					'likes'    => $like_count,
					'shares'   => $retweet_count,
					'quotes'   => $quote_count,
				),
				'extras'     => self::extract_extras( $tweet ),
			)
		);
	}

	/**
	 * Build permalink to tweet.
	 *
	 * @param array<string,mixed> $tweet Tweet data.
	 * @return string
	 */
	private static function build_permalink( array $tweet ): string {
		// Handle normalized cached format (tweet_url)
		if ( isset( $tweet['tweet_url'] ) && '' !== $tweet['tweet_url'] ) {
			return (string) $tweet['tweet_url'];
		}

		// Handle raw API format - build from username and id
		$author   = isset( $tweet['author'] ) && is_array( $tweet['author'] ) ? $tweet['author'] : array();
		$username = isset( $author['username'] ) ? (string) $author['username'] : '';

		// Try both id formats
		$id = '';
		if ( isset( $tweet['id'] ) ) {
			$id = (string) $tweet['id'];
		} elseif ( isset( $tweet['tweet_id'] ) ) {
			$id = (string) $tweet['tweet_id'];
		}

		if ( '' !== $username && '' !== $id ) {
			return 'https://twitter.com/' . \rawurlencode( $username ) . '/status/' . \rawurlencode( $id );
		}

		return '';
	}

	/**
	 * Format tweet text as HTML with links.
	 *
	 * @param string              $text  Raw text.
	 * @param array<string,mixed> $tweet Full tweet for entities.
	 * @return string
	 */
	private static function format_text_html( string $text, array $tweet ): string {
		if ( function_exists( 'esf_twitter_format_tweet_html' ) ) {
			return esf_twitter_format_tweet_html( $text, $tweet );
		}

		// Fallback: basic formatting
		return \wpautop( \make_clickable( \esc_html( $text ) ) );
	}

	/**
	 * Extract media from tweet.
	 *
	 * Supports the module's normalized cache shape (`media` array with
	 * type/url/preview_url/video_url) and the raw X API shape
	 * (`includes.media` + optional variants).
	 *
	 * @param array<string,mixed> $tweet Tweet data.
	 * @return array<int,array<string,mixed>>
	 */
	private static function extract_media( array $tweet ): array {
		// Normalized cache format used by ESF_Twitter_API_Service::normalize_tweets_response().
		if ( isset( $tweet['media'] ) && is_array( $tweet['media'] ) && ! empty( $tweet['media'] ) ) {
			$result = array();
			foreach ( $tweet['media'] as $media ) {
				if ( ! is_array( $media ) ) {
					continue;
				}

				$type    = isset( $media['type'] ) ? (string) $media['type'] : 'photo';
				$url     = isset( $media['url'] ) ? (string) $media['url'] : '';
				$preview = isset( $media['preview_url'] ) ? (string) $media['preview_url'] : '';
				if ( '' === $preview && isset( $media['preview_image_url'] ) ) {
					$preview = (string) $media['preview_image_url'];
				}
				if ( '' === $preview ) {
					$preview = $url;
				}

				if ( 'photo' === $type ) {
					if ( '' === $url ) {
						continue;
					}
					$result[] = array(
						'type'        => PostMedia::TYPE_PHOTO,
						'url'         => $url,
						'preview_url' => $preview,
						'alt'         => isset( $media['alt_text'] ) ? (string) $media['alt_text'] : '',
						'width'       => isset( $media['width'] ) ? (int) $media['width'] : 0,
						'height'      => isset( $media['height'] ) ? (int) $media['height'] : 0,
					);
					continue;
				}

				if ( 'video' === $type || 'animated_gif' === $type ) {
					$video_url = isset( $media['video_url'] ) ? (string) $media['video_url'] : '';
					if ( '' === $video_url && isset( $media['variants'][0]['url'] ) ) {
						$video_url = (string) $media['variants'][0]['url'];
					}
					$thumb = '' !== $preview ? $preview : $url;
					if ( '' === $thumb && '' === $video_url ) {
						continue;
					}
					$result[] = array(
						'type'        => PostMedia::TYPE_VIDEO,
						'url'         => $thumb,
						'preview_url' => $thumb,
						'video_url'   => $video_url,
						'alt'         => isset( $media['alt_text'] ) ? (string) $media['alt_text'] : '',
						'width'       => isset( $media['width'] ) ? (int) $media['width'] : 0,
						'height'      => isset( $media['height'] ) ? (int) $media['height'] : 0,
					);
				}
			}

			return $result;
		}

		// Raw X API format.
		$includes   = isset( $tweet['includes'] ) && is_array( $tweet['includes'] ) ? $tweet['includes'] : array();
		$media_data = isset( $includes['media'] ) && is_array( $includes['media'] ) ? $includes['media'] : array();

		$result = array();
		foreach ( $media_data as $media ) {
			if ( ! is_array( $media ) ) {
				continue;
			}

			$type    = isset( $media['type'] ) ? (string) $media['type'] : '';
			$url     = isset( $media['url'] ) ? (string) $media['url'] : '';
			$preview = isset( $media['preview_image_url'] ) ? (string) $media['preview_image_url'] : $url;

			if ( 'photo' === $type && '' !== $url ) {
				$result[] = array(
					'type'        => PostMedia::TYPE_PHOTO,
					'url'         => $url,
					'preview_url' => $preview,
					'alt'         => isset( $media['alt_text'] ) ? (string) $media['alt_text'] : '',
					'width'       => isset( $media['width'] ) ? (int) $media['width'] : 0,
					'height'      => isset( $media['height'] ) ? (int) $media['height'] : 0,
				);
			} elseif ( 'video' === $type || 'animated_gif' === $type ) {
				$video_url = isset( $media['variants'][0]['url'] ) ? (string) $media['variants'][0]['url'] : '';
				$result[]  = array(
					'type'        => PostMedia::TYPE_VIDEO,
					'url'         => $preview,
					'preview_url' => $preview,
					'video_url'   => $video_url,
					'alt'         => isset( $media['alt_text'] ) ? (string) $media['alt_text'] : '',
					'width'       => isset( $media['width'] ) ? (int) $media['width'] : 0,
					'height'      => isset( $media['height'] ) ? (int) $media['height'] : 0,
				);
			}
		}

		return $result;
	}

	/**
	 * Extract extras for Twitter-specific features.
	 *
	 * @param array<string,mixed> $tweet Tweet data.
	 * @return array<string,mixed>
	 */
	private static function extract_extras( array $tweet ): array {
		$extras = array();

		// Store raw tweet for backwards compatibility with existing layout render logic
		$extras['raw_tweet'] = $tweet;

		// Quote tweet data
		if ( isset( $tweet['referenced_tweets'] ) && is_array( $tweet['referenced_tweets'] ) ) {
			foreach ( $tweet['referenced_tweets'] as $ref ) {
				if ( isset( $ref['type'] ) && 'quoted' === $ref['type'] ) {
					$extras['is_quote']     = true;
					$extras['quoted_tweet'] = $ref;
					break;
				}
				if ( isset( $ref['type'] ) && 'retweeted' === $ref['type'] ) {
					$extras['is_retweet'] = true;
					break;
				}
			}
		}

		// Poll data
		if ( isset( $tweet['poll'] ) ) {
			$extras['poll'] = $tweet['poll'];
		}

		return $extras;
	}
}
