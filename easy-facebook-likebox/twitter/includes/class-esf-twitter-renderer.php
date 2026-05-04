<?php
/**
 * Twitter Feed Renderer
 *
 * Orchestrates feed loading, tweet fetching (with DB cache), and layout rendering.
 * Entry point called by the shortcode and the REST preview endpoint.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter
 * @since 6.7.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Twitter_Renderer
 *
 * @since 6.7.6
 */
class ESF_Twitter_Renderer {

	/**
	 * Render a feed by its internal ID.
	 *
	 * @since 6.7.6
	 * @param int   $feed_id           Feed ID.
	 * @param array $settings_override  Optional settings to merge (e.g. for live preview).
	 * @param bool  $enqueue_assets     Whether to enqueue CSS/JS. Set false for REST preview.
	 * @param int   $account_id_override Optional account ID override for unsaved source changes.
	 * @return string HTML output, or empty string on failure.
	 */
	public function render( $feed_id, $settings_override = array(), $enqueue_assets = true, $account_id_override = 0 ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return '';
		}

		$feed_repo = ESF_Twitter_Feed_Repository::get_instance();
		$feed_row  = $feed_repo->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return '';
		}

		$saved    = json_decode( $feed_row->settings, true );
		$settings = ESF_Twitter_Feed_Repository::merge_settings_with_defaults( is_array( $saved ) ? $saved : array() );
		if ( ! empty( $settings_override ) && is_array( $settings_override ) ) {
			$settings = ESF_Twitter_Feed_Repository::merge_settings_with_defaults( array_merge( $settings, $settings_override ) );
		}
		$settings = apply_filters( 'esf_twitter_render_settings', $settings, $feed_id, $feed_row, $settings_override );

		$feed_obj = (object) array(
			'id'         => $feed_row->id,
			'name'       => $feed_row->name,
			'account_id' => (int) $feed_row->account_id,
			'feed_type'  => $feed_row->feed_type,
			'source_id'  => $feed_row->source_id,
			'settings'   => $settings,
		);
		$feed_obj = apply_filters( 'esf_twitter_render_feed_object', $feed_obj, $feed_id, $feed_row, $settings );
		if ( (int) $account_id_override > 0 ) {
			$feed_obj->account_id = (int) $account_id_override;
		}

		$account_repo = ESF_Twitter_Account_Repository::get_instance();
		$account      = $account_repo->get_by_id( $feed_obj->account_id );
		if ( ! $account ) {
			return '';
		}

		$tweets = $this->get_tweets_for_feed( $feed_id, $feed_obj, $account );
		if ( is_wp_error( $tweets ) ) {
			$tweets = array();
		}
		$tweets = apply_filters( 'esf_twitter_render_tweets', $tweets, $feed_id, $feed_obj, $account );

		$layout_type = isset( $settings['layout']['type'] ) ? $settings['layout']['type'] : 'timeline';
		$layout_type = apply_filters( 'esf_twitter_render_layout_type', $layout_type, $feed_id, $feed_obj, $tweets, $account );
		$layout      = ESF_Twitter_Layout_Registry::make( $layout_type, $feed_obj, $tweets, $account );

		if ( $enqueue_assets ) {
			$this->enqueue_layout_assets( $layout );
		}

		$custom_css      = $this->get_custom_css( $feed_id, $settings );
		$custom_css_html = '' !== $custom_css
			? '<style id="esf-twitter-feed-' . (int) $feed_id . '-custom-css">' . $custom_css . '</style>'
			: '';

		$layout_type_attr = esc_attr( $layout_type );
		$wrapper_classes = array( 'esf-tw-feed', 'esf-tw-feed--' . $layout_type );
		$wrapper_classes = apply_filters( 'esf_twitter_render_wrapper_classes', $wrapper_classes, $feed_id, $feed_obj, $layout_type );
		$wrapper_classes = array_filter(
			array_map(
				'sanitize_html_class',
				is_array( $wrapper_classes ) ? $wrapper_classes : array()
			)
		);
		if ( empty( $wrapper_classes ) ) {
			$wrapper_classes = array( 'esf-tw-feed', 'esf-tw-feed--' . $layout_type_attr );
		}

		$wrapper_attrs     = apply_filters(
			'esf_twitter_render_wrapper_attributes',
			array(),
			$feed_id,
			$feed_obj,
			$layout_type
		);
		$wrapper_attr_html = '';
		if ( is_array( $wrapper_attrs ) ) {
			foreach ( $wrapper_attrs as $attr_name => $attr_value ) {
				$attr_name = sanitize_key( (string) $attr_name );
				if ( '' === $attr_name || null === $attr_value || false === $attr_value ) {
					continue;
				}
				$wrapper_attr_html .= ' ' . $attr_name . '="' . esc_attr( (string) $attr_value ) . '"';
			}
		}

		$layout_html = $layout->render();
		$layout_html = apply_filters( 'esf_twitter_render_layout_html', $layout_html, $feed_id, $feed_obj, $layout_type, $tweets, $account );

		do_action( 'esf_twitter_before_feed_render', $feed_id, $feed_obj, $layout_type, $tweets, $account );

		$html = '<div id="esf-twitter-feed-' . (int) $feed_id . '" class="' . esc_attr( implode( ' ', $wrapper_classes ) ) . '"' . $wrapper_attr_html . '>'
			. $layout_html
			. '</div>'
			. $custom_css_html;

		$html = apply_filters( 'esf_twitter_render_html', $html, $feed_id, $feed_obj, $layout_type, $tweets, $account );

		do_action( 'esf_twitter_after_feed_render', $feed_id, $feed_obj, $layout_type, $tweets, $account, $html );

		return $html;
	}

	/**
	 * Get tweets for a feed (from cache or API).
	 *
	 * Public for use by the cron cache-refresh job.
	 *
	 * @since 6.7.6
	 * @param int    $feed_id  Feed ID (used in the cache key).
	 * @param object $feed_obj Feed object.
	 * @param object $account  Account object.
	 * @return array|WP_Error Normalized tweet array, or WP_Error.
	 */
	public function get_tweets_for_feed( $feed_id, $feed_obj, $account ) {
		$account_id = (int) $feed_obj->account_id;
		$cache_key  = 'esf_tw_tweets_' . (int) $feed_id . '_' . $account_id;
		$meta_key   = $cache_key . '_meta';

		$cached = ESF_Twitter_Cache::get( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$api_service = ESF_Twitter_API_Service::get_instance();
		$settings    = isset( $feed_obj->settings ) && is_array( $feed_obj->settings )
			? $feed_obj->settings
			: array();
		$feed_cfg    = isset( $settings['feed'] ) && is_array( $settings['feed'] )
			? $settings['feed']
			: array();

		$tweet_count = isset( $feed_cfg['tweet_count'] )
			? max( 5, min( ESF_Twitter_API_Service::MAX_TWEET_FETCH, (int) $feed_cfg['tweet_count'] ) )
			: 20;

		$exclude = array();
		if ( ! empty( $feed_cfg['exclude_retweets'] ) ) {
			$exclude[] = 'retweets';
		}
		if ( ! empty( $feed_cfg['exclude_replies'] ) ) {
			$exclude[] = 'replies';
		}

		$x_user_id = isset( $account->x_user_id ) ? (string) $account->x_user_id : '';
		if ( '' === $x_user_id ) {
			return array();
		}

		if ( 'public' === $account->account_type ) {
			// Pro: use ESF proxy with Bearer Token.
			$page = $api_service->fetch_public_timeline_page( $x_user_id, $tweet_count, $exclude );
		} else {
			// Connected account: use OAuth access token.
			$token_manager = ESF_Twitter_Token_Manager::get_instance();
			$access_token  = $token_manager->get_valid_access_token( $account_id );
			if ( ! $access_token ) {
				return array();
			}
			$page = $api_service->fetch_user_timeline_page( $x_user_id, $access_token, $tweet_count, $exclude );
		}

		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$tweets     = isset( $page['tweets'] ) && is_array( $page['tweets'] ) ? $page['tweets'] : array();
		$next_token = isset( $page['next_token'] ) ? sanitize_text_field( (string) $page['next_token'] ) : '';

		if ( is_array( $tweets ) && ! empty( $tweets ) ) {
			$tweets = $this->ensure_local_media_for_tweets( $tweets );

			$ttl = (int) esf_get_twitter_settings( 'cache_duration' );
			if ( $ttl <= 0 ) {
				$ttl = 43200; // 12 hours.
			}
			ESF_Twitter_Cache::set( $cache_key, $tweets, $ttl, 'api', (int) $feed_id, $account_id );
			ESF_Twitter_Cache::set(
				$meta_key,
				array(
					'next_token' => $next_token,
				),
				$ttl,
				'api',
				(int) $feed_id,
				$account_id
			);
		}

		return is_array( $tweets ) ? $tweets : array();
	}

	/**
	 * Ensure tweet media URLs are served from local uploads when possible.
	 *
	 * Mirrors YouTube's local thumbnail serving behavior by converting remote
	 * tweet media and author avatars into first-party media URLs via
	 * esf_serve_media_locally().
	 *
	 * @since 6.7.6
	 * @param array $tweets Normalized tweet items.
	 * @return array Tweets with local media URLs when available.
	 */
	protected function ensure_local_media_for_tweets( $tweets ) {
		if ( ! is_array( $tweets ) || empty( $tweets ) ) {
			return $tweets;
		}

		if ( ! function_exists( 'esf_serve_media_locally' ) ) {
			return $tweets;
		}

		foreach ( $tweets as $index => $tweet ) {
			if ( ! is_array( $tweet ) ) {
				continue;
			}

			$tweet_id = isset( $tweet['tweet_id'] ) ? (string) $tweet['tweet_id'] : '';

			// Localize card author avatar (covers retweets/quoted authors too).
			if ( ! empty( $tweet['author'] ) && is_array( $tweet['author'] ) ) {
				$author_avatar = isset( $tweet['author']['profile_image_url'] ) ? (string) $tweet['author']['profile_image_url'] : '';
				if ( '' !== $author_avatar ) {
					if ( function_exists( 'esf_twitter_get_best_profile_image_url' ) ) {
						$author_avatar = esf_twitter_get_best_profile_image_url( $author_avatar );
					}
					$author_id = isset( $tweet['author']['x_user_id'] ) ? (string) $tweet['author']['x_user_id'] : '';
					if ( '' === $author_id ) {
						$author_id = 'tweet_' . $tweet_id . '_author';
					}
					$local_author_avatar = esf_serve_media_locally( 'tw_author_' . $author_id, $author_avatar, 'twitter' );
					if ( is_string( $local_author_avatar ) && '' !== $local_author_avatar ) {
						$tweets[ $index ]['author']['profile_image_url'] = $local_author_avatar;
					}
				}
			}

			// Localize media thumbnails/photos for tweet cards.
			if ( empty( $tweet['media'] ) || ! is_array( $tweet['media'] ) ) {
				continue;
			}

			foreach ( $tweet['media'] as $media_index => $media_item ) {
				if ( ! is_array( $media_item ) ) {
					continue;
				}

				$type      = isset( $media_item['type'] ) ? (string) $media_item['type'] : 'photo';
				$url_field = 'photo' === $type ? 'url' : 'preview_url';
				$media_url = isset( $media_item[ $url_field ] ) ? (string) $media_item[ $url_field ] : '';
				if ( '' === $media_url ) {
					continue;
				}

				$media_key       = 'tw_media_' . $tweet_id . '_' . (int) $media_index;
				$local_media_url = esf_serve_media_locally( $media_key, $media_url, 'twitter' );
				if ( is_string( $local_media_url ) && '' !== $local_media_url ) {
					$tweets[ $index ]['media'][ $media_index ][ $url_field ] = $local_media_url;
				}

				// Localize direct video files so popup playback is first-party and stable.
				if ( 'photo' !== $type ) {
					$video_url = isset( $media_item['video_url'] ) ? (string) $media_item['video_url'] : '';
					if ( '' !== $video_url ) {
						$video_key       = 'tw_video_' . $tweet_id . '_' . (int) $media_index;
						$local_video_url = esf_serve_media_locally( $video_key, $video_url, 'twitter' );
						if ( is_string( $local_video_url ) && '' !== $local_video_url ) {
							$tweets[ $index ]['media'][ $media_index ]['video_url'] = $local_video_url;
						}
					}
				}
			}
		}

		return $tweets;
	}

	/**
	 * Get tweets for a feed by ID only (used by cron and REST load-more).
	 *
	 * @since 6.7.6
	 * @param int $feed_id Feed ID.
	 * @return array Normalized tweet items (empty on failure).
	 */
	public function get_tweets_for_feed_by_id( $feed_id ) {
		$feed_id   = (int) $feed_id;
		$feed_repo = ESF_Twitter_Feed_Repository::get_instance();
		$feed_row  = $feed_repo->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return array();
		}

		$account_repo = ESF_Twitter_Account_Repository::get_instance();
		$account      = $account_repo->get_by_id( (int) $feed_row->account_id );
		if ( ! $account ) {
			return array();
		}

		$saved    = json_decode( $feed_row->settings, true );
		$feed_obj = (object) array(
			'id'         => $feed_row->id,
			'account_id' => (int) $feed_row->account_id,
			'feed_type'  => $feed_row->feed_type,
			'source_id'  => $feed_row->source_id,
			'settings'   => ESF_Twitter_Feed_Repository::merge_settings_with_defaults( is_array( $saved ) ? $saved : array() ),
		);

		$tweets = $this->get_tweets_for_feed( $feed_id, $feed_obj, $account );
		if ( is_wp_error( $tweets ) ) {
			return array();
		}

		return is_array( $tweets ) ? $tweets : array();
	}

	/**
	 * Enqueue base feed CSS and layout-specific CSS.
	 *
	 * @since 6.7.6
	 * @param ESF_Twitter_Layout_Base $layout Layout instance.
	 * @return void
	 */
	protected function enqueue_layout_assets( $layout ) {
		$base_handle = 'esf-twitter-feed';
		$base_path   = ESF_TWITTER_DIR . 'frontend/assets/css/esf-twitter-feed.css';
		$base_url    = ESF_TWITTER_URL . 'frontend/assets/css/esf-twitter-feed.css';

		if ( file_exists( $base_path ) ) {
			wp_enqueue_style(
				$base_handle,
				$base_url,
				array(),
				(string) filemtime( $base_path )
			);
		}

		$layout_handle = $layout->get_css_handle();
		$layout_slug   = str_replace( 'esf-twitter-layout-', '', $layout_handle );
		$layout_path   = ESF_TWITTER_DIR . 'frontend/assets/css/layouts/' . $layout_slug . '.css';
		$layout_url    = ESF_TWITTER_URL . 'frontend/assets/css/layouts/' . $layout_slug . '.css';

		if ( file_exists( $layout_path ) ) {
			wp_enqueue_style(
				$layout_handle,
				$layout_url,
				array( $base_handle ),
				(string) filemtime( $layout_path )
			);
		}

		$js_handle = $layout->get_js_handle();
		if ( $js_handle ) {
			$js_slug = str_replace( 'esf-twitter-layout-', '', $js_handle );
			$js_path = ESF_TWITTER_DIR . 'frontend/assets/js/layouts/' . $js_slug . '.js';
			$js_url  = ESF_TWITTER_URL . 'frontend/assets/js/layouts/' . $js_slug . '.js';
			if ( file_exists( $js_path ) ) {
				wp_enqueue_script( $js_handle, $js_url, array(), (string) filemtime( $js_path ), true );
			}
		}
	}

	/**
	 * Extract and sanitize custom CSS from feed settings.
	 *
	 * Custom CSS is treated as trusted admin input and output inside a
	 * <style> tag. Any existing <style> tags are stripped to prevent nesting.
	 *
	 * @since 6.7.6
	 * @param int   $feed_id  Feed ID (unused, reserved for future scoping).
	 * @param array $settings Merged feed settings.
	 * @return string Custom CSS or empty string.
	 */
	protected function get_custom_css( $feed_id, $settings ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( ! is_array( $settings ) ) {
			return '';
		}

		$style = isset( $settings['style'] ) && is_array( $settings['style'] ) ? $settings['style'] : array();
		$css   = isset( $style['custom_css'] ) ? (string) $style['custom_css'] : '';
		$css   = trim( $css );

		if ( '' === $css ) {
			return '';
		}

		return preg_replace( '#</?style[^>]*>#i', '', $css );
	}

	/**
	 * Get the URL for the base feed CSS (for the REST preview endpoint).
	 *
	 * @since 6.7.6
	 * @return string URL or empty string.
	 */
	public function get_base_css_url() {
		$path = ESF_TWITTER_DIR . 'frontend/assets/css/esf-twitter-feed.css';

		return file_exists( $path ) ? ESF_TWITTER_URL . 'frontend/assets/css/esf-twitter-feed.css' : '';
	}

	/**
	 * Get the URL for a layout's CSS (for the REST preview endpoint).
	 *
	 * @since 6.7.6
	 * @param string $layout_type Layout slug.
	 * @return string URL or empty string.
	 */
	public function get_layout_css_url( $layout_type ) {
		$layout_type = is_string( $layout_type ) ? trim( $layout_type ) : 'timeline';
		$path        = ESF_TWITTER_DIR . 'frontend/assets/css/layouts/' . $layout_type . '.css';

		return file_exists( $path )
			? ESF_TWITTER_URL . 'frontend/assets/css/layouts/' . $layout_type . '.css'
			: '';
	}
}
