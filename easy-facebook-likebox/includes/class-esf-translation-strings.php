<?php
/**
 * Registry of frontend translatable strings for all social feed modules.
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
			'post_text'         => array(
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
						'key'     => 'view_event',
						'default' => 'View event',
						'context' => __( 'Primary CTA on Facebook Events feed cards when no ticket URL is available.', 'easy-facebook-likebox' ),
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
			'media'             => array(
				'label'   => __( 'Media', 'easy-facebook-likebox' ),
				'strings' => array(
					array(
						'key'     => 'video_not_supported',
						'default' => 'Your browser does not support the video tag',
						'context' => __( 'Shown when the browser cannot play the video.', 'easy-facebook-likebox' ),
					),
				),
			),
			'time'              => array(
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
			'load_more'         => array(
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
			'other'             => array(
				'label'   => __( 'Other', 'easy-facebook-likebox' ),
				'strings' => array(
					array(
						'key'     => 'followers',
						'default' => 'Followers',
						'context' => __( 'Used as label or title for follower count (e.g. on Instagram).', 'easy-facebook-likebox' ),
					),
				),
			),
			'youtube_feed'      => array(
				'label'   => __( 'YouTube Feed', 'easy-facebook-likebox' ),
				'strings' => array(
					array(
						'key'     => 'yt_channel_has_no_videos',
						'default' => 'This channel has no videos yet.',
						'context' => __( 'Shown when a channel-uploads YouTube feed has no videos to display.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'yt_no_shorts_found',
						'default' => 'No Shorts found for this channel.',
						'context' => __( 'Shown when Video type is Shorts only and the channel Shorts playlist is empty.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'yt_no_long_form_videos_found',
						'default' => 'No long-form videos found for this channel.',
						'context' => __( 'Shown when Video type is Exclude Shorts and no long-form videos remain.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'yt_playlist_not_selected',
						'default' => 'Select a playlist to display its videos.',
						'context' => __( 'Shown in preview/frontend when Playlist source is chosen but no playlist is selected yet.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'yt_playlist_has_no_videos',
						'default' => 'This playlist has no videos yet.',
						'context' => __( 'Shown when a playlist feed is selected but the playlist is empty.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'yt_search_not_entered',
						'default' => 'Enter a search keyword to find videos.',
						'context' => __( 'Shown when Search source is chosen but no keyword is entered yet.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'yt_search_no_results',
						'default' => 'No videos matched this search.',
						'context' => __( 'Shown when a YouTube search feed returns no matching videos.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'yt_load_more_videos_aria',
						'default' => 'Load more videos',
						'context' => __( 'ARIA label for the YouTube Load More button.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'yt_load_more_videos_button',
						'default' => 'Load More',
						'context' => __( 'Visible text for the YouTube Load More button.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'yt_subscribe_button',
						'default' => 'Subscribe',
						'context' => __( 'Subscribe button text shown in the YouTube feed header and popup.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'yt_watch_on_youtube',
						'default' => 'Watch on YouTube',
						'context' => __( 'Link text in the YouTube popup that opens the video on youtube.com.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'yt_popup_views_label',
						'default' => 'views',
						'context' => __( 'Label used next to the view count in the YouTube popup stats bar.', 'easy-facebook-likebox' ),
					),
				),
			),
			'instagram_feed'    => array(
				'label'   => __( 'Instagram Feed', 'easy-facebook-likebox' ),
				'strings' => array(
					array(
						'key'     => 'ig_posts',
						'default' => 'Posts',
						'context' => __( 'Tooltip label for the post count in the Instagram feed header.', 'easy-facebook-likebox' ),
					),
				),
			),
			'tiktok_feed'       => array(
				'label'   => __( 'TikTok Feed', 'easy-facebook-likebox' ),
				'strings' => array(
					array(
						'key'     => 'tt_follow_on_tiktok',
						'default' => 'Follow on TikTok',
						'context' => __( 'Follow button label in the TikTok feed header.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tt_following',
						'default' => 'Following',
						'context' => __( 'Tooltip label for the following count in the TikTok feed header.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tt_videos',
						'default' => 'Videos',
						'context' => __( 'Tooltip label for the video count in the TikTok feed header.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tt_no_videos_to_display',
						'default' => 'No videos to display yet.',
						'context' => __( 'Shown when a TikTok feed has no videos to render.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tt_load_more_videos_button',
						'default' => 'Load more',
						'context' => __( 'Visible text for the TikTok Load More button.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tt_load_more_videos_aria',
						'default' => 'Load more TikTok videos',
						'context' => __( 'ARIA label for the TikTok Load More button.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tt_watch_on_tiktok',
						'default' => 'Watch on TikTok',
						'context' => __( 'Link text in the TikTok popup that opens the video on tiktok.com.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tt_view_on_tiktok',
						'default' => 'View on TikTok',
						'context' => __( 'Fallback caption on text-only TikTok tiles and popup links.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tt_views',
						'default' => 'Views',
						'context' => __( 'Tooltip label for view count on TikTok feed tiles.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tt_likes',
						'default' => 'Likes',
						'context' => __( 'Tooltip label for like count on TikTok feed tiles and in the popup.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tt_comments',
						'default' => 'Comments',
						'context' => __( 'Tooltip label for comment count on TikTok feed tiles and in the popup.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tt_shares',
						'default' => 'Shares',
						'context' => __( 'Tooltip label for share count on TikTok feed tiles and in the popup.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tt_feed_nav_hint',
						'default' => 'Scroll, swipe, or press ↑ ↓ for the next video',
						'context' => __( 'Navigation hint shown in the TikTok popup lightbox.', 'easy-facebook-likebox' ),
					),
				),
			),
			'social_wall_feed'  => array(
				'label'   => __( 'Social Wall', 'easy-facebook-likebox' ),
				'strings' => array(
					array(
						'key'     => 'sw_filter_all',
						'default' => 'All',
						'context' => __( 'Social Wall frontend filter tab that shows every network.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'sw_filter_aria',
						'default' => 'Filter by network',
						'context' => __( 'ARIA label for the Social Wall network filter bar.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'sw_no_posts',
						'default' => 'Select at least one account to display feeds.',
						'context' => __( 'Shown when a Social Wall has no connected sources or posts to render.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'sw_no_posts_filter',
						'default' => 'No posts found for this network.',
						'context' => __( 'Shown when a Social Wall network filter tab has no posts to display.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'sw_load_more_aria',
						'default' => 'Load more posts',
						'context' => __( 'ARIA label for the Social Wall Load More button.', 'easy-facebook-likebox' ),
					),
				),
			),
			'facebook_feed'     => array(
				'label'   => __( 'Facebook Feed', 'easy-facebook-likebox' ),
				'strings' => array(
					array(
						'key'     => 'fb_follow_on_facebook',
						'default' => 'Follow on Facebook',
						'context' => __( 'Follow button label in the Facebook feed header.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_likes',
						'default' => 'Likes',
						'context' => __( 'Tooltip label for like/reaction count on Facebook feed tiles and in the popup.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_comments',
						'default' => 'Comments',
						'context' => __( 'Tooltip label for comment count on Facebook feed tiles and in the popup.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_shares',
						'default' => 'Shares',
						'context' => __( 'Tooltip label for share count on Facebook feed tiles and in the popup.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_reactions',
						'default' => 'Reactions',
						'context' => __( 'Generic label for Facebook reactions.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_reaction_like',
						'default' => 'Like',
						'context' => __( 'Facebook Like reaction label.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_reaction_love',
						'default' => 'Love',
						'context' => __( 'Facebook Love reaction label.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_reaction_care',
						'default' => 'Care',
						'context' => __( 'Facebook Care reaction label.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_reaction_haha',
						'default' => 'Haha',
						'context' => __( 'Facebook Haha reaction label.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_reaction_wow',
						'default' => 'Wow',
						'context' => __( 'Facebook Wow reaction label.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_reaction_sad',
						'default' => 'Sad',
						'context' => __( 'Facebook Sad reaction label.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_reaction_angry',
						'default' => 'Angry',
						'context' => __( 'Facebook Angry reaction label.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_no_posts',
						'default' => 'No posts to display yet.',
						'context' => __( 'Shown when a Facebook feed has no posts to render.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_no_events',
						'default' => 'No events to show right now.',
						'context' => __( 'Shown on the public Events feed when Events cannot load (e.g. Page token not set up yet).', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_load_more_posts_aria',
						'default' => 'Load more Facebook posts',
						'context' => __( 'ARIA label for the Facebook Load More button.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_embed_shared_event',
						'default' => 'Shared event',
						'context' => __( 'Label on the placeholder card when a shared Facebook event cannot be fully previewed in the feed.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_embed_shared_post',
						'default' => 'Shared post',
						'context' => __( 'Label on the placeholder card when a shared Facebook post cannot be fully previewed in the feed.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_embed_shared_video',
						'default' => 'Shared video',
						'context' => __( 'Label on the placeholder card when a shared Facebook video cannot be fully previewed in the feed.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_embed_link_preview',
						'default' => 'Link preview',
						'context' => __( 'Label on the placeholder card when a link attachment cannot be fully previewed in the feed.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_embed_unavailable_body',
						'default' => 'Full details aren\'t available in the feed preview.',
						'context' => __( 'Body copy on the placeholder card when Facebook withholds shared attachment details from the Graph API.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_embed_view_event',
						'default' => 'View event on Facebook',
						'context' => __( 'CTA on the shared-event placeholder card.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_post',
						'default' => 'Post',
						'context' => __( 'Uppercase label on Facebook Row / Grid / Masonry / Carousel text-only tiles (status updates with no media).', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_previous_posts',
						'default' => 'Previous posts',
						'context' => __( 'Accessible label for the previous-slide control on the Facebook Carousel layout.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_next_posts',
						'default' => 'Next posts',
						'context' => __( 'Accessible label for the next-slide control on the Facebook Carousel layout.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_write_comment_on_facebook',
						'default' => 'Add a comment on Facebook…',
						'context' => __( 'CTA in the Facebook Pro popup comments panel linking out to Facebook.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_video',
						'default' => 'Video',
						'context' => __( 'Accessible label for a video slide in the Facebook popup.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_gallery',
						'default' => 'Gallery',
						'context' => __( 'Accessible label for a multi-image gallery in the Facebook popup.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_image',
						'default' => 'Image',
						'context' => __( 'Accessible label for an image slide in the Facebook popup.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_previous',
						'default' => 'Previous',
						'context' => __( 'Previous media control in the Facebook popup gallery.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_next',
						'default' => 'Next',
						'context' => __( 'Next media control in the Facebook popup gallery.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_all_reactions',
						'default' => 'All',
						'context' => __( 'Label for the full reactions breakdown in the Facebook popup.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_close',
						'default' => 'Close',
						'context' => __( 'Close control label for the Facebook popup lightbox.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_view_image',
						'default' => 'View image',
						'context' => __( 'Opens a comment attachment image in a nested lightbox.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_comments_showing',
						'default' => 'Showing %1$s of %2$s',
						'context' => __( 'Comment pagination status in the Facebook popup (%1$s shown, %2$s total).', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_load_more_comments',
						'default' => 'Load more comments',
						'context' => __( 'Button to load the next page of Facebook popup comments.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_load_more_replies',
						'default' => 'Load more replies',
						'context' => __( 'Button to load more replies under a Facebook popup comment.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_loading_comments',
						'default' => 'Loading comments…',
						'context' => __( 'Loading state while Facebook popup comments are fetched.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_no_comments',
						'default' => 'No comments yet.',
						'context' => __( 'Empty state when a Facebook post has no comments in the popup.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_comments_error',
						'default' => 'Could not load comments.',
						'context' => __( 'Error state when Facebook popup comments fail to load.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_load_more_photos',
						'default' => 'Load more photos',
						'context' => __( 'Button to load additional album photos in the Facebook Pro popup gallery.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_loading_photos',
						'default' => 'Loading photos…',
						'context' => __( 'Loading state while Facebook popup album photos are fetched.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_photos_error',
						'default' => "Couldn't load more photos.",
						'context' => __( 'Error state when Facebook popup album photo load-more fails.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_album_photo_count',
						'default' => '%d photos',
						'context' => __( 'Photo count on Facebook album filter cards (%d = number of photos).', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_view_all_replies',
						'default' => 'View all %d replies',
						'context' => __( 'Toggle to expand replies under a Facebook popup comment (%d = count).', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_view_reply',
						'default' => 'View %d reply',
						'context' => __( 'Singular toggle to expand one reply under a Facebook popup comment.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_hide_replies',
						'default' => 'Hide replies',
						'context' => __( 'Toggle to collapse replies under a Facebook popup comment.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_facebook_user',
						'default' => 'Facebook User',
						'context' => __( 'Fallback display name when a Facebook comment author is missing.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_powered_by',
						'default' => 'Powered by Easy Social Feed',
						'context' => __( 'Branding badge shown in the Free Facebook popup lightbox.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_live_now',
						'default' => 'is live now',
						'context' => __( 'Status suffix next to the Page name when a Facebook Page has an active live stream (e.g. “Easy Social Feed is live now”).', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_no_live_stream',
						'default' => 'This Page is not broadcasting live right now.',
						'context' => __( 'Empty state when a live-stream-only Facebook feed has no active broadcast.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_category',
						'default' => 'Category',
						'context' => __( 'Tooltip label for Page category in the Facebook feed header.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_post_alt',
						'default' => 'Facebook post',
						'context' => __( 'Default image alt text when a Facebook post has no caption.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'fb_post_alt_named',
						'default' => '%1$s Facebook post',
						'context' => __( 'Image alt text with Page name when a Facebook post has no caption. %1$s is the Page name.', 'easy-facebook-likebox' ),
					),
				),
			),
			'twitter_feed'      => array(
				'label'   => __( 'X / Twitter Feed', 'easy-facebook-likebox' ),
				'strings' => array(
					array(
						'key'     => 'tw_load_more_tweets_aria',
						'default' => 'Load more tweets',
						'context' => __( 'ARIA label for the Twitter/X Load More button.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_no_tweets_to_display',
						'default' => 'No tweets to display.',
						'context' => __( 'Shown when a Twitter/X feed has no tweets to render.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_following',
						'default' => 'Following',
						'context' => __( 'Tooltip label for the following count in Twitter/X feed header.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_tweets',
						'default' => 'Tweets',
						'context' => __( 'Tooltip label for the tweet count in Twitter/X feed header.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_follow',
						'default' => 'Follow',
						'context' => __( 'Follow button label in Twitter/X feed and popup.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_view_on_x',
						'default' => 'View on X',
						'context' => __( 'Tooltip label for links that open a tweet on x.com.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_view_tweet_on_x',
						'default' => 'View tweet on X',
						'context' => __( 'ARIA label for the tweet logo link in Twitter/X cards.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_retweeted_from',
						'default' => 'Retweeted from',
						'context' => __( 'Prefix text shown before the original retweeted account.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_retweet',
						'default' => 'Retweet',
						'context' => __( 'Fallback label used for retweets when source account is unknown.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_more',
						'default' => 'more',
						'context' => __( 'Suffix shown in media grids for extra item count (e.g. "+2 more").', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'media_more',
						'default' => 'more',
						'context' => __( 'Suffix shown in multi-image collages for extra item count (e.g. "+1 more").', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_replies',
						'default' => 'Replies',
						'context' => __( 'Tooltip label for reply count in Twitter/X card and popup metrics.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_likes',
						'default' => 'Likes',
						'context' => __( 'Tooltip label for like count in Twitter/X card and popup metrics.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_retweets',
						'default' => 'Retweets',
						'context' => __( 'Tooltip label for retweet count in Twitter/X card and popup metrics.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_quotes',
						'default' => 'Quotes',
						'context' => __( 'Tooltip label for quote count in Twitter/X card and popup metrics.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_share',
						'default' => 'Share',
						'context' => __( 'Tooltip label for the share button in Twitter/X cards.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_share_on_x',
						'default' => 'Share on X',
						'context' => __( 'Share menu link label for x.com.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_share_on_facebook',
						'default' => 'Share on Facebook',
						'context' => __( 'Share menu link label for facebook.com.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_share_on_linkedin',
						'default' => 'Share on LinkedIn',
						'context' => __( 'Share menu link label for linkedin.com.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_share_on_whatsapp',
						'default' => 'Share on WhatsApp',
						'context' => __( 'Share menu link label for WhatsApp.', 'easy-facebook-likebox' ),
					),
					array(
						'key'     => 'tw_verified_account',
						'default' => 'Verified account',
						'context' => __( 'Tooltip text shown on verified badge in Twitter/X feed and popup.', 'easy-facebook-likebox' ),
					),
				),
			),
			'errors_messages'   => array(
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
	 * Get default strings for a subset of keys.
	 *
	 * @since 6.7.6
	 *
	 * @param array $keys List of translation keys.
	 * @return array Associative array of key => default text.
	 */
	public static function get_defaults_for_keys( array $keys ) {
		if ( empty( $keys ) ) {
			return array();
		}
		$defaults = self::get_defaults_by_key();
		$result   = array();
		foreach ( $keys as $key ) {
			if ( isset( $defaults[ $key ] ) ) {
				$result[ $key ] = $defaults[ $key ];
			}
		}
		return $result;
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
		$settings                   = get_option( 'fta_settings', array() );
		self::$translation_settings = isset( $settings['translation'] ) && is_array( $settings['translation'] ) ? $settings['translation'] : array();
		return self::$translation_settings;
	}

	/**
	 * Legacy Facebook keys that now resolve through shared registry entries.
	 *
	 * Keeps saved `fta_settings['translation']` values working after duplicates
	 * were removed from the Facebook Feed category.
	 *
	 * @var array<string,string>
	 */
	private static $key_aliases = array(
		'view_on_facebook' => 'fb_view_on_facebook',
		'followers'        => 'fb_followers',
		'load_more'        => 'fb_load_more_posts_button',
		'fb_no_posts'      => 'fb_no_posts_to_display',
	);

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

		if ( isset( self::$key_aliases[ $key ] ) ) {
			$legacy = self::$key_aliases[ $key ];
			$value  = isset( $custom[ $legacy ] ) ? $custom[ $legacy ] : '';
			if ( '' !== $value && is_string( $value ) ) {
				return $value;
			}
		}

		$defaults = self::get_defaults_by_key();
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Helper function belongs with this class.
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

if ( ! function_exists( 'esf_translate_registry_string' ) ) {
	/**
	 * Resolve a translation-registry key and return a localized string.
	 *
	 * @param string $key      Registry key (e.g. `tt_watch_on_tiktok`).
	 * @param string $fallback Default English when the key is unknown.
	 * @return string
	 */
	function esf_translate_registry_string( $key, $fallback = '' ) {
		$text = function_exists( 'esf_get_translated_string' ) ? esf_get_translated_string( $key ) : '';
		if ( '' === $text || (string) $text === (string) $key ) {
			$text = $fallback;
		}

		return __( $text, 'easy-facebook-likebox' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
