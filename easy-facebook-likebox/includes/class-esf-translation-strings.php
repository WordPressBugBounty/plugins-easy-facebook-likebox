<?php
/**
 * Registry of frontend translatable strings for Facebook and Instagram modules.
 * Used by the Translation settings tab and by esf_get_translated_string() on the frontend.
 *
 * @package EasySocialFeed
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Translation_Strings
 */
class ESF_Translation_Strings {

	/**
	 * Cached list of all strings (key => [ 'default' => ..., 'context' => ..., 'category' => ... ]).
	 *
	 * @var array|null
	 */
	protected static $strings = null;

	/**
	 * Cached translation settings (custom strings) for the current request.
	 * Avoids calling get_option() on every get().
	 *
	 * @var array|null null = not loaded yet, array = translation sub-array or empty.
	 */
	protected static $translation_settings = null;

	/**
	 * Flat key => default map built once from get_all_strings() for fast lookup.
	 *
	 * @var array|null
	 */
	protected static $defaults_by_key = null;

	/**
	 * Get all registered strings, grouped by category.
	 *
	 * @return array [ 'category_label' => [ [ 'key' => ..., 'default' => ..., 'context' => ... ], ... ], ... ]
	 */
	public static function get_all_strings() {
		if ( null !== self::$strings ) {
			return self::$strings;
		}

		$list = array(
			'post_text'    => array(
				'label'   => __( 'Post Text', 'easy-facebook-likebox' ),
				'strings' => array(
					array(
						'key'     => 'see_more',
						'default' => 'See more',
						'context' => __( 'Used when truncating the post text.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'see_less',
						'default' => 'See less',
						'context' => __( 'Used when truncating the post text.', 'easy-facebook-likebox' ),
					),
				),
			),
			'post_action_links' => array(
				'label'   => __( 'Post Action Links', 'easy-facebook-likebox' ),
				'strings' => array(
					array(
						'key'     => 'view_on_facebook',
						'default' => 'View on Facebook',
						'context' => __( 'Used for the link to the post on Facebook.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'view_on_instagram',
						'default' => 'View on Instagram',
						'context' => __( 'Used for the link to the post on Instagram.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'write_a_comment',
						'default' => 'Write a comment...',
						'context' => __( 'Used for the link to write a comment on the post.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'share',
						'default' => 'Share',
						'context' => __( 'Used for sharing the post via social media.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'read_full_story',
						'default' => 'Read full story',
						'context' => __( 'Used for the link to open the full post in a popup.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'follow_on_instagram',
						'default' => 'Follow on Instagram',
						'context' => __( 'Used for the follow button on Instagram feed.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'view_all_events',
						'default' => 'View All Events',
						'context' => __( 'Used for the link to view all Facebook events.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'buy_tickets',
						'default' => 'Buy Tickets',
						'context' => __( 'Used for Facebook event ticket link.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'view_map',
						'default' => 'View Map',
						'context' => __( 'Used for the link to view event location on map.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'click_to_view_all_comments',
						'default' => 'Click To View All Comments',
						'context' => __( 'Used as title/tooltip for the comments link.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'view_replies',
						'default' => 'View Replies',
						'context' => __( 'Used for the link to view comment replies.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'all_replies',
						'default' => 'All replies',
						'context' => __( 'Used as heading for the replies section.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'all_comments',
						'default' => 'All Comments',
						'context' => __( 'Used as heading for the comments popup.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'all_reactions_prefix',
						'default' => 'All ',
						'context' => __( 'Prefix before reaction count (e.g. "All 5").', 'easy-facebook-likebox' ),
					),
				),
			),
			'media'       => array(
				'label'   => __( 'Media', 'easy-facebook-likebox' ),
				'strings' => array(
					array(
						'key'     => 'video_not_supported',
						'default' => 'Your browser does not support the video tag',
						'context' => __( 'Shown when the browser cannot play the video.', 'easy-facebook-likebox' ),
					),
				),
			),
			'time'        => array(
				'label'   => __( 'Time', 'easy-facebook-likebox' ),
				'strings' => array(
					array(
						'key'     => 'ago',
						'default' => 'ago',
						'context' => __( 'Used after relative time (e.g. "5 minutes ago").', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'second',
						'default' => 'second',
						'context' => __( 'Singular time unit.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'seconds',
						'default' => 'seconds',
						'context' => __( 'Plural time unit.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'minute',
						'default' => 'minute',
						'context' => __( 'Singular time unit.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'minutes',
						'default' => 'minutes',
						'context' => __( 'Plural time unit.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'hour',
						'default' => 'hour',
						'context' => __( 'Singular time unit.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'hours',
						'default' => 'hours',
						'context' => __( 'Plural time unit.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'day',
						'default' => 'day',
						'context' => __( 'Singular time unit.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'days',
						'default' => 'days',
						'context' => __( 'Plural time unit.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'week',
						'default' => 'week',
						'context' => __( 'Singular time unit.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'weeks',
						'default' => 'weeks',
						'context' => __( 'Plural time unit.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'month',
						'default' => 'month',
						'context' => __( 'Singular time unit.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'months',
						'default' => 'months',
						'context' => __( 'Plural time unit.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'year',
						'default' => 'year',
						'context' => __( 'Singular time unit.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'years',
						'default' => 'years',
						'context' => __( 'Plural time unit.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'decade',
						'default' => 'decade',
						'context' => __( 'Time unit.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'upcoming',
						'default' => 'upcoming',
						'context' => __( 'Used for Facebook events filter (e.g. "upcoming events").', 'easy-facebook-likebox' ),
					),
				),
			),
			'load_more'   => array(
				'label'   => __( 'Load More / Pagination', 'easy-facebook-likebox' ),
				'strings' => array(
					array(
						'key'     => 'no_more_found',
						'default' => 'No More Found',
						'context' => __( 'Shown when there are no more posts to load.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'load_more',
						'default' => 'Load More',
						'context' => __( 'Button text to load more posts.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'loading',
						'default' => 'Loading...',
						'context' => __( 'Shown while more posts are loading.', 'easy-facebook-likebox' ),
					),
				),
			),
			'other'       => array(
				'label'   => __( 'Other', 'easy-facebook-likebox' ),
				'strings' => array(
					array(
						'key'     => 'followers',
						'default' => 'Followers',
						'context' => __( 'Used as label or title for follower count (e.g. on Instagram).', 'easy-facebook-likebox' ),
					),
				),
			),
			'errors_messages' => array(
				'label'   => __( 'Errors & Messages', 'easy-facebook-likebox' ),
				'strings' => array(
					array(
						'key'     => 'no_connected_account',
						'default' => 'Whoops! No connected account found. Try connecting an account first.',
						'context' => __( 'Shown when no Facebook or Instagram account is connected.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'nothing_found_fanpage',
						'default' => 'Whoops! Nothing found according to your query, Try changing fanpage ID.',
						'context' => __( 'Shown when Facebook feed returns no results.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'no_feeds_found',
						'default' => 'No Feeds Found',
						'context' => __( 'Shown when feed request returns no data.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'no_account_found',
						'default' => 'No account found, Please enter the account ID available in the dashboard',
						'context' => __( 'Shown when the given account ID is invalid.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'no_access_token',
						'default' => 'No access token found',
						'context' => __( 'Shown when API access token is missing.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'no_access_token_exclamation',
						'default' => 'No access token found!',
						'context' => __( 'Alternate message when access token is missing.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'no_more_posts_available',
						'default' => 'No more posts available',
						'context' => __( 'Shown when loading more Instagram posts and none are left.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'error_no_data_insta',
						'default' => 'Error: No data found, Try connecting an account first and make sure you have posts on your account.',
						'context' => __( 'Shown when Instagram feed has no data.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'error_prefix',
						'default' => 'Error: ',
						'context' => __( 'Prefix before error message (e.g. "Error: something went wrong").', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'no_stories_found',
						'default' => 'No stories found. Please add some stories on your account',
						'context' => __( 'Shown when Instagram stories have no data.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'invalid_page_id',
						'default' => 'Invalid page ID',
						'context' => __( 'Shown when the Facebook page ID is invalid.', 'easy-facebook-likebox' ),
					),
				),
			),
		);

		self::$strings = $list;
		return self::$strings;
	}

	/**
	 * Build flat key => default map from get_all_strings() (once per request).
	 *
	 * @return array
	 */
	protected static function get_defaults_by_key() {
		if ( null !== self::$defaults_by_key ) {
			return self::$defaults_by_key;
		}
		self::$defaults_by_key = array();
		$all                   = self::get_all_strings();
		foreach ( $all as $category ) {
			foreach ( $category['strings'] as $item ) {
				if ( ! empty( $item['key'] ) && isset( $item['default'] ) ) {
					self::$defaults_by_key[ $item['key'] ] = $item['default'];
				}
			}
		}
		return self::$defaults_by_key;
	}

	/**
	 * Get translation settings (custom strings) once per request.
	 *
	 * @return array
	 */
	protected static function get_translation_settings() {
		if ( null !== self::$translation_settings ) {
			return self::$translation_settings;
		}
		$settings                      = get_option( 'fta_settings', array() );
		self::$translation_settings    = isset( $settings['translation'] ) && is_array( $settings['translation'] ) ? $settings['translation'] : array();
		return self::$translation_settings;
	}

	/**
	 * Get the display string for a key: custom translation from settings, or default.
	 * Uses request-level cache: one get_option() and one defaults build per page load.
	 *
	 * @param string $key Key from the registry (e.g. 'see_more', 'view_on_facebook').
	 * @return string Text to display. Use with __( esf_get_translated_string( 'key' ), 'easy-facebook-likebox' ) for .po/.mo.
	 */
	public static function get( $key ) {
		$custom = self::get_translation_settings();
		$value  = isset( $custom[ $key ] ) ? $custom[ $key ] : '';
		if ( '' !== $value && is_string( $value ) ) {
			return $value;
		}

		$defaults = self::get_defaults_by_key();
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile -- Helper function belongs with this class.
if ( ! function_exists( 'esf_get_translated_string' ) ) {
	/**
	 * Get the frontend string for a translation key (custom from settings or default).
	 *
	 * @param string $key Key from ESF_Translation_Strings (e.g. 'see_more', 'view_on_facebook').
	 * @return string Text to display. Use with __( esf_get_translated_string( 'key' ), 'easy-facebook-likebox' ) so .po/.mo still apply when no custom text is set.
	 */
	function esf_get_translated_string( $key ) {
		return ESF_Translation_Strings::get( $key );
	}
}
