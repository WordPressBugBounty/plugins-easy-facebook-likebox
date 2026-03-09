<?php
/**
 * YouTube Feed Renderer.
 *
 * Orchestrates feed loading, video fetching (with cache), and layout rendering.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube
 * @since 6.7.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_YouTube_Renderer
 *
 * @since 6.7.5
 */
class ESF_YouTube_Renderer {

	/**
	 * Maximum number of videos to fetch per feed (YouTube API max per page).
	 *
	 * @since 6.7.5
	 * @var int
	 */
	const MAX_VIDEO_FETCH = 50;

	/**
	 * Render a feed by ID.
	 *
	 * @since 6.7.5
	 *
	 * @param int   $feed_id           Feed ID.
	 * @param array $settings_override Optional. Override settings (e.g. for preview). Merged with saved settings.
	 * @param bool  $enqueue_assets    Whether to enqueue CSS/JS. Set false for REST preview.
	 * @return string HTML output. Empty string on failure.
	 */
	public function render( $feed_id, $settings_override = array(), $enqueue_assets = true ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return '';
		}

		$feed_repo = ESF_YouTube_Feed_Repository::get_instance();
		$feed_row  = $feed_repo->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return '';
		}

		$saved_settings = json_decode( $feed_row->settings, true );
		$settings       = ESF_YouTube_Feed_Repository::merge_settings_with_defaults( is_array( $saved_settings ) ? $saved_settings : array() );
		if ( ! empty( $settings_override ) && is_array( $settings_override ) ) {
			$settings = ESF_YouTube_Feed_Repository::merge_settings_with_defaults( array_merge( $settings, $settings_override ) );
		}

		$feed_obj = (object) array(
			'id'         => $feed_row->id,
			'name'       => $feed_row->name,
			'account_id' => (int) $feed_row->account_id,
			'feed_type'  => $feed_row->feed_type,
			'source_id'  => $feed_row->source_id,
			'settings'   => $settings,
		);

		$account_repo = ESF_YouTube_Account_Repository::get_instance();
		$account      = $account_repo->get_by_id( $feed_obj->account_id );
		if ( ! $account ) {
			return '';
		}

		$token_manager = ESF_YouTube_Token_Manager::get_instance();
		$access_token  = $token_manager->get_valid_access_token( $feed_obj->account_id );
		if ( ! $access_token ) {
			return '';
		}

		$videos = $this->get_videos_for_feed( $feed_id, $feed_obj, $access_token );
		if ( is_wp_error( $videos ) ) {
			$videos = array();
		}

		$layout_type = isset( $settings['layout']['type'] ) ? $settings['layout']['type'] : 'grid';
		$layout      = ESF_YouTube_Layout_Registry::make( $layout_type, $feed_obj, $videos, $account );

		if ( $enqueue_assets ) {
			$this->enqueue_layout_assets( $layout );
		}

		$layout_html      = $layout->render();
		$feed_id_attr     = (int) $feed_obj->id;
		$layout_type_attr = esc_attr( $layout_type );
		$custom_css       = $this->get_custom_css_for_feed( $feed_id_attr, $settings );
		$custom_css_html  = '';

		if ( '' !== $custom_css ) {
			$custom_css_html = '<style id="esf-youtube-feed-' . $feed_id_attr . '-custom-css">' . $custom_css . '</style>';
		}

		return '<div id="esf-youtube-feed-' . $feed_id_attr . '" class="esf-yt-feed esf-yt-feed--' . $layout_type_attr . '">' . $layout_html . '</div>' . $custom_css_html;
	}

	/**
	 * Get custom CSS for a feed from settings.
	 *
	 * Custom CSS is treated as trusted admin input and is output inside a
	 * <style> tag. It is trimmed and stripped of any existing <style> tags
	 * to avoid nested tags breaking markup.
	 *
	 * @since 6.7.5
	 *
	 * @param int   $feed_id  Feed ID for which CSS is generated.
	 * @param array $settings Normalized feed settings.
	 * @return string Custom CSS or empty string when none is defined.
	 */
	protected function get_custom_css_for_feed( $feed_id, $settings ) {
		if ( ! is_array( $settings ) ) {
			return '';
		}

		$style = isset( $settings['style'] ) && is_array( $settings['style'] ) ? $settings['style'] : array();
		$css   = isset( $style['custom_css'] ) ? (string) $style['custom_css'] : '';

		$css = trim( $css );
		if ( '' === $css ) {
			return '';
		}

		// Remove any existing <style> tags to prevent breaking the page markup.
		$css = preg_replace( '#</?style[^>]*>#i', '', $css );

		return $css;
	}

	/**
	 * Get videos for a feed by ID (from cache or API).
	 *
	 * Used by Load More REST endpoint. Returns raw list; caller applies moderation and slice.
	 *
	 * @since 6.7.5
	 * @param int $feed_id Feed ID.
	 * @return array|WP_Error Normalized video items or WP_Error on failure.
	 */
	public function get_videos_for_feed_by_id( $feed_id ) {
		$feed_id = (int) $feed_id;
		if ( $feed_id <= 0 ) {
			return array();
		}

		$feed_repo = ESF_YouTube_Feed_Repository::get_instance();
		$feed_row  = $feed_repo->get_by_id( $feed_id );
		if ( ! $feed_row ) {
			return array();
		}

		$feed_obj = (object) array(
			'id'         => $feed_row->id,
			'account_id' => (int) $feed_row->account_id,
			'feed_type'  => $feed_row->feed_type,
			'source_id'  => $feed_row->source_id,
		);

		$account_repo = ESF_YouTube_Account_Repository::get_instance();
		$account      = $account_repo->get_by_id( $feed_obj->account_id );
		if ( ! $account ) {
			return array();
		}

		$token_manager = ESF_YouTube_Token_Manager::get_instance();
		$access_token  = $token_manager->get_valid_access_token( $feed_obj->account_id );
		if ( ! $access_token ) {
			return array();
		}

		$saved_settings     = json_decode( $feed_row->settings, true );
		$feed_obj->settings = ESF_YouTube_Feed_Repository::merge_settings_with_defaults( is_array( $saved_settings ) ? $saved_settings : array() );

		$videos = $this->get_videos_for_feed( $feed_id, $feed_obj, $access_token );
		if ( is_wp_error( $videos ) ) {
			return array();
		}

		return is_array( $videos ) ? $videos : array();
	}

	/**
	 * Get videos for a feed (from cache or API).
	 *
	 * Cache key includes feed_id and account_id so that when the feed's linked
	 * account is changed, we fetch the new account's videos instead of
	 * returning the previous account's cached list.
	 *
	 * @since 6.7.5
	 *
	 * @param int    $feed_id      Feed ID (for cache key).
	 * @param object $feed_obj     Feed object with feed_type, source_id, account_id.
	 * @param string $access_token Valid access token.
	 * @return array|WP_Error Normalized video items or WP_Error on failure.
	 */
	protected function get_videos_for_feed( $feed_id, $feed_obj, $access_token ) {
		$account_id = isset( $feed_obj->account_id ) ? (int) $feed_obj->account_id : 0;
		$cache_key  = 'esf_yt_videos_' . (int) $feed_id . '_' . $account_id;
		$cached     = ESF_YouTube_Cache::get( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$api_service = ESF_YouTube_API_Service::get_instance();
		$max_results = self::MAX_VIDEO_FETCH;

		switch ( $feed_obj->feed_type ) {
			case 'channel':
				$playlist_id = $api_service->get_uploads_playlist_id( $access_token );
				if ( is_wp_error( $playlist_id ) ) {
					return $playlist_id;
				}
				$videos = $api_service->fetch_playlist_videos( $access_token, $playlist_id, $max_results );
				break;
			case 'playlist':
				$source_id = isset( $feed_obj->source_id ) ? trim( (string) $feed_obj->source_id ) : '';
				if ( '' === $source_id ) {
					return array();
				}
				$videos = $api_service->fetch_playlist_videos( $access_token, $source_id, $max_results );
				break;
			case 'search':
				$source_id = isset( $feed_obj->source_id ) ? trim( (string) $feed_obj->source_id ) : '';
				$videos    = $api_service->fetch_search_videos( $access_token, $source_id ? $source_id : ' ', $max_results );
				break;
			default:
				$playlist_id = $api_service->get_uploads_playlist_id( $access_token );
				if ( is_wp_error( $playlist_id ) ) {
					return $playlist_id;
				}
				$videos = $api_service->fetch_playlist_videos( $access_token, $playlist_id, $max_results );
		}

		if ( is_wp_error( $videos ) ) {
			return $videos;
		}

		if ( is_array( $videos ) && ! empty( $videos ) ) {
			$videos = $this->ensure_local_thumbnails_for_videos( $videos );

			$ttl = (int) esf_get_youtube_settings( 'cache_duration' );
			if ( $ttl <= 0 ) {
				$ttl = 43200;
			}

			ESF_YouTube_Cache::set( $cache_key, $videos, $ttl, 'api', (int) $feed_id, $account_id );
		}

		return $videos;
	}

	/**
	 * Ensure video thumbnails are served via local media when possible.
	 *
	 * Uses the core esf_serve_media_locally helper (also used for YouTube
	 * account thumbnails) so that thumbnails are downloaded to the
	 * uploads/esf-youtube directory and reused from there.
	 *
	 * @since 6.7.5
	 *
	 * @param array $videos Normalized video items.
	 * @return array Videos with thumbnail_url pointing to a local URL when available.
	 */
	protected function ensure_local_thumbnails_for_videos( $videos ) {
		if ( ! is_array( $videos ) || empty( $videos ) ) {
			return $videos;
		}

		if ( ! function_exists( 'esf_serve_media_locally' ) ) {
			return $videos;
		}

		foreach ( $videos as $index => $video ) {
			if ( ! is_array( $video ) ) {
				continue;
			}

			$video_id = isset( $video['video_id'] ) ? (string) $video['video_id'] : ( isset( $video['id'] ) ? (string) $video['id'] : '' );
			$thumb    = isset( $video['thumbnail_url'] ) ? (string) $video['thumbnail_url'] : '';

			if ( '' === $video_id || '' === $thumb ) {
				continue;
			}

			$local_url = esf_serve_media_locally( $video_id, $thumb, 'youtube' );
			if ( is_string( $local_url ) && '' !== $local_url ) {
				$videos[ $index ]['thumbnail_url'] = $local_url;
			}
		}

		return $videos;
	}

	/**
	 * Enqueue base feed CSS and layout-specific CSS/JS.
	 *
	 * @since 6.7.5
	 *
	 * @param ESF_YouTube_Layout_Base $layout Layout instance.
	 * @return void
	 */
	protected function enqueue_layout_assets( $layout ) {
		$base_handle = 'esf-youtube-feed';
		$base_url    = ESF_YOUTUBE_URL . 'frontend/assets/css/esf-youtube-feed.css';
		$base_path   = ESF_YOUTUBE_DIR . 'frontend/assets/css/esf-youtube-feed.css';

		if ( file_exists( $base_path ) ) {
			wp_enqueue_style(
				$base_handle,
				$base_url,
				array(),
				(string) filemtime( $base_path )
			);
		}

		$layout_handle = $layout->get_css_handle();
		$layout_slug   = str_replace( 'esf-youtube-layout-', '', $layout_handle );
		$layout_url    = ESF_YOUTUBE_URL . 'frontend/assets/css/layouts/' . $layout_slug . '.css';
		$layout_path   = ESF_YOUTUBE_DIR . 'frontend/assets/css/layouts/' . $layout_slug . '.css';

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
			$js_slug = str_replace( 'esf-youtube-layout-', '', $js_handle );
			$js_url  = ESF_YOUTUBE_URL . 'frontend/assets/js/layouts/' . $js_slug . '.js';
			$js_path = ESF_YOUTUBE_DIR . 'frontend/assets/js/layouts/' . $js_slug . '.js';
			if ( file_exists( $js_path ) ) {
				wp_enqueue_script(
					$js_handle,
					$js_url,
					array(),
					(string) filemtime( $js_path ),
					true
				);
			}
		}
	}

	/**
	 * Get the URL for the base feed CSS (for preview endpoint).
	 *
	 * @since 6.7.5
	 * @return string URL or empty string.
	 */
	public function get_base_css_url() {
		$path = ESF_YOUTUBE_DIR . 'frontend/assets/css/esf-youtube-feed.css';

		return file_exists( $path ) ? ESF_YOUTUBE_URL . 'frontend/assets/css/esf-youtube-feed.css' : '';
	}

	/**
	 * Get the URL for a layout's CSS (for preview endpoint).
	 *
	 * @since 6.7.5
	 *
	 * @param string $layout_type Layout type (e.g. 'grid').
	 * @return string URL or empty string.
	 */
	public function get_layout_css_url( $layout_type ) {
		$layout_type = is_string( $layout_type ) ? trim( $layout_type ) : 'grid';
		$path        = ESF_YOUTUBE_DIR . 'frontend/assets/css/layouts/' . $layout_type . '.css';

		return file_exists( $path ) ? ESF_YOUTUBE_URL . 'frontend/assets/css/layouts/' . $layout_type . '.css' : '';
	}
}
