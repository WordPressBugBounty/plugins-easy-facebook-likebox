<?php
/**
 * Twitter Layout Base
 *
 * Abstract base class for all Twitter feed layout renderers.
 * Provides shared rendering helpers (header, tweet cards, empty state).
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/Layouts
 * @since 6.7.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Twitter_Layout_Base
 *
 * @since 6.7.6
 */
abstract class ESF_Twitter_Layout_Base {

	/**
	 * Feed object (id, name, account_id, feed_type, source_id, settings).
	 *
	 * @since 6.7.6
	 * @var object
	 */
	protected $feed;

	/**
	 * Normalized tweet items.
	 *
	 * @since 6.7.6
	 * @var array
	 */
	protected $tweets;

	/**
	 * Account object for the feed header.
	 *
	 * @since 6.7.6
	 * @var object|null
	 */
	protected $account;

	/**
	 * Constructor.
	 *
	 * @since 6.7.6
	 * @param object      $feed    Feed object with settings.
	 * @param array       $tweets  Normalized tweet items.
	 * @param object|null $account Account object. Optional.
	 */
	public function __construct( $feed, $tweets, $account = null ) {
		$this->feed    = $feed;
		$this->tweets  = is_array( $tweets ) ? $tweets : array();
		$this->account = $account;
	}

	/**
	 * Render the layout output.
	 *
	 * @since 6.7.6
	 * @return string HTML output.
	 */
	abstract public function render();

	/**
	 * Get the WordPress style handle for this layout's CSS.
	 *
	 * @since 6.7.6
	 * @return string
	 */
	abstract public function get_css_handle();

	/**
	 * Get the WordPress script handle for this layout's JS.
	 *
	 * Returns null when the layout requires no custom JS.
	 *
	 * @since 6.7.6
	 * @return string|null
	 */
	public function get_js_handle() {
		return null;
	}

	/**
	 * Get display state for initial render: tweets and Load More button HTML.
	 *
	 * @since 6.7.6
	 * @return array{
	 *   tweets: array,
	 *   load_more_html: string
	 * }
	 */
	protected function get_display_state() {
		$tweets   = is_array( $this->tweets ) ? $this->tweets : array();
		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings ) ? $this->feed->settings : array();
		$feed     = isset( $settings['feed'] ) && is_array( $settings['feed'] ) ? $settings['feed'] : array();

		$per_page         = isset( $feed['per_page'] ) ? max( 1, min( 50, (int) $feed['per_page'] ) ) : 10;
		$has_loadmore_plan = function_exists( 'esf_twitter_has_twitter_plan' ) && esf_twitter_has_twitter_plan();
		$wants_load_more   = ! array_key_exists( 'load_more', $feed ) || ! empty( $feed['load_more'] );
		$total            = count( $tweets );
		$visible_tweets   = $tweets;
		$load_more_html   = '';

		if ( $total > $per_page ) {
			$visible_tweets = array_slice( $tweets, 0, $per_page );
		}

		if ( $has_loadmore_plan && $wants_load_more && $total > $per_page ) {
			if ( function_exists( 'esf_twitter_flag_load_more_script' ) ) {
				esf_twitter_flag_load_more_script();
			}
			$feed_id_attr = isset( $this->feed->id ) ? (int) $this->feed->id : 0;
			$next_token   = '';
			if ( $feed_id_attr > 0 && isset( $this->feed->account_id ) ) {
				$meta_key   = 'esf_tw_tweets_' . $feed_id_attr . '_' . (int) $this->feed->account_id . '_meta';
				$meta_cache = ESF_Twitter_Cache::get( $meta_key );
				if ( is_array( $meta_cache ) && ! empty( $meta_cache['next_token'] ) ) {
					$next_token = sanitize_text_field( (string) $meta_cache['next_token'] );
				}
			}
			$bg_color     = isset( $feed['load_more_bg_color'] ) && is_string( $feed['load_more_bg_color'] )
				? sanitize_hex_color( $feed['load_more_bg_color'] )
				: '';
			$text_color   = isset( $feed['load_more_text_color'] ) && is_string( $feed['load_more_text_color'] )
				? sanitize_hex_color( $feed['load_more_text_color'] )
				: '';
			$style_parts  = array();
			if ( '' !== $bg_color ) {
				$style_parts[] = 'background-color:' . esc_attr( $bg_color );
			}
			if ( '' !== $text_color ) {
				$style_parts[] = 'color:' . esc_attr( $text_color );
			}
			$button_style  = ! empty( $style_parts ) ? implode( ';', $style_parts ) : '';
			$load_more_html = sprintf(
				'<div class="esf-tw-feed__load-more-wrap"><button type="button" class="esf-tw-feed__load-more" data-feed-id="%1$d" data-offset="%2$d" data-next-token="%6$s" aria-label="%3$s" %5$s>%4$s</button></div>',
				$feed_id_attr,
				$per_page,
				esc_attr__( esf_get_translated_string( 'tw_load_more_tweets_aria' ), 'easy-facebook-likebox' ),
				esc_html__( esf_get_translated_string( 'load_more' ), 'easy-facebook-likebox' ),
				'' !== $button_style ? 'style="' . esc_attr( $button_style ) . '"' : '',
				esc_attr( $next_token )
			);
		}

		return array(
			'tweets'         => $visible_tweets,
			'load_more_html' => $load_more_html,
		);
	}

	/**
	 * Render tweet cards only.
	 *
	 * Public so REST Load More can request cards HTML for a slice.
	 *
	 * @since 6.7.6
	 * @param array $tweets Tweet items.
	 * @return string HTML output.
	 */
	public function render_cards_for_tweets( $tweets ) {
		return $this->render_tweet_cards( $tweets );
	}

	/**
	 * Render tweet cards only.
	 *
	 * @since 6.7.6
	 * @param array $tweets Tweet items.
	 * @return string HTML output.
	 */
	protected function render_tweet_cards( $tweets ) {
		if ( ! is_array( $tweets ) ) {
			return '';
		}

		$html = '';
		foreach ( $tweets as $tweet ) {
			$html .= $this->render_tweet_card( $tweet );
		}

		return $html;
	}

	/**
	 * Render the empty-state notice when no tweets are available.
	 *
	 * @since 6.7.6
	 * @return string HTML output.
	 */
	protected function render_empty_state() {
		$html = '<div class="esf-tw-feed__empty"><p class="esf-tw-feed__empty-text">'
			. esc_html__( esf_get_translated_string( 'tw_no_tweets_to_display' ), 'easy-facebook-likebox' )
			. '</p></div>';

		return apply_filters( 'esf_twitter_layout_empty_state_html', $html, $this->feed, $this->account, $this );
	}

	/**
	 * Render the account header above the tweet list.
	 *
	 * Displays profile image, display name, @username, follower count,
	 * and an optional "Follow on X" button.
	 *
	 * @since 6.7.6
	 * @return string HTML output (empty string when header is disabled).
	 */
	protected function render_header() {
		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings )
			? $this->feed->settings
			: array();
		$header   = isset( $settings['header'] ) && is_array( $settings['header'] )
			? $settings['header']
			: array();

		$show = ! empty( $header['show'] );
		$show = (bool) apply_filters( 'esf_twitter_layout_show_header', $show, $header, $this->feed, $this->account, $this );
		if ( ! $show || ! $this->account ) {
			return '';
		}

		$show_avatar        = ! isset( $header['show_avatar'] ) || ! empty( $header['show_avatar'] );
		$show_name          = ! isset( $header['show_name'] ) || ! empty( $header['show_name'] );
		$show_followers     = ! isset( $header['show_followers'] ) || ! empty( $header['show_followers'] );
		$show_following     = ! isset( $header['show_following'] ) || ! empty( $header['show_following'] );
		$show_tweets        = ! isset( $header['show_tweets'] ) || ! empty( $header['show_tweets'] );
		$show_bio           = ! isset( $header['show_bio'] ) || ! empty( $header['show_bio'] );
		$show_follow_button       = false;
		$can_toggle_follow_button = false;

		if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
			if ( function_exists( 'esf_twitter_has_twitter_plan' ) && esf_twitter_has_twitter_plan() ) {
				$can_toggle_follow_button = true;
				$show_follow_button       = ! isset( $header['show_follow_button'] ) || ! empty( $header['show_follow_button'] );
			}
		}

		// Keep backward compatibility for older feeds that only stored show_followers.
		$has_legacy_header_stats_config =
			array_key_exists( 'show_followers', $header ) &&
			! array_key_exists( 'show_following', $header ) &&
			! array_key_exists( 'show_tweets', $header ) &&
			! array_key_exists( 'show_bio', $header );
		if ( $has_legacy_header_stats_config ) {
			$show_followers = true;
			$show_following = true;
			$show_tweets    = true;
			$show_bio       = true;
		}

		$display_name = isset( $this->account->display_name ) ? (string) $this->account->display_name : '';
		$username     = isset( $this->account->username ) ? (string) $this->account->username : '';
		$avatar       = isset( $this->account->profile_image_url ) ? (string) $this->account->profile_image_url : '';
		if ( function_exists( 'esf_twitter_get_best_profile_image_url' ) ) {
			$avatar = esf_twitter_get_best_profile_image_url( $avatar );
		}
		$followers    = isset( $this->account->followers_count ) ? (int) $this->account->followers_count : 0;
		$following    = isset( $this->account->following_count ) ? (int) $this->account->following_count : 0;
		$tweet_count  = isset( $this->account->tweet_count ) ? (int) $this->account->tweet_count : 0;
		$bio          = isset( $this->account->description ) ? (string) $this->account->description : '';
		$profile_url  = $username ? 'https://x.com/' . $username : 'https://x.com';
		$is_verified  = $this->is_account_verified();
		$header_data  = array(
			'display_name'       => $display_name,
			'username'           => $username,
			'avatar'             => $avatar,
			'followers'          => $followers,
			'following'          => $following,
			'tweet_count'        => $tweet_count,
			'bio'                => $bio,
			'profile_url'        => $profile_url,
			'show_avatar'        => $show_avatar,
			'show_name'          => $show_name,
			'show_followers'     => $show_followers,
			'show_following'     => $show_following,
			'show_tweets'        => $show_tweets,
			'show_bio'           => $show_bio,
			'show_follow_button' => $show_follow_button,
			'is_verified'        => $is_verified,
		);
		$header_data  = apply_filters( 'esf_twitter_layout_header_data', $header_data, $header, $this->feed, $this->account, $this );

		$display_name       = isset( $header_data['display_name'] ) ? (string) $header_data['display_name'] : $display_name;
		$username           = isset( $header_data['username'] ) ? (string) $header_data['username'] : $username;
		$avatar             = isset( $header_data['avatar'] ) ? (string) $header_data['avatar'] : $avatar;
		$followers          = isset( $header_data['followers'] ) ? (int) $header_data['followers'] : $followers;
		$following          = isset( $header_data['following'] ) ? (int) $header_data['following'] : $following;
		$tweet_count        = isset( $header_data['tweet_count'] ) ? (int) $header_data['tweet_count'] : $tweet_count;
		$bio                = isset( $header_data['bio'] ) ? (string) $header_data['bio'] : $bio;
		$profile_url        = isset( $header_data['profile_url'] ) ? (string) $header_data['profile_url'] : $profile_url;
		$show_avatar        = isset( $header_data['show_avatar'] ) ? (bool) $header_data['show_avatar'] : $show_avatar;
		$show_name          = isset( $header_data['show_name'] ) ? (bool) $header_data['show_name'] : $show_name;
		$show_followers     = isset( $header_data['show_followers'] ) ? (bool) $header_data['show_followers'] : $show_followers;
		$show_following     = isset( $header_data['show_following'] ) ? (bool) $header_data['show_following'] : $show_following;
		$show_tweets        = isset( $header_data['show_tweets'] ) ? (bool) $header_data['show_tweets'] : $show_tweets;
		$show_bio           = isset( $header_data['show_bio'] ) ? (bool) $header_data['show_bio'] : $show_bio;
		$show_follow_button = isset( $header_data['show_follow_button'] ) ? (bool) $header_data['show_follow_button'] : $show_follow_button;
		$is_verified        = isset( $header_data['is_verified'] ) ? (bool) $header_data['is_verified'] : $is_verified;
		if ( ! $can_toggle_follow_button ) {
			$show_follow_button = false;
		}

		ob_start();
		?>
		<?php do_action( 'esf_twitter_layout_before_header', $header_data, $this->feed, $this->account, $this ); ?>
		<div class="esf-tw-feed__header">
			<div class="esf-tw-feed__header-inner">
				<?php if ( $show_avatar && $avatar ) : ?>
					<a class="esf-tw-feed__header-avatar-link"
						href="<?php echo esc_url( $profile_url ); ?>"
						target="_blank" rel="noopener noreferrer">
						<img src="<?php echo esc_url( $avatar ); ?>"
							alt="<?php echo esc_attr( $display_name ); ?>"
							class="esf-tw-feed__header-avatar"
							width="48" height="48" loading="lazy" />
					</a>
				<?php endif; ?>
				<div class="esf-tw-feed__header-meta">
					<div class="esf-tw-feed__header-top">
						<div class="esf-tw-feed__header-info">
							<?php if ( $show_name && '' !== $display_name ) : ?>
								<span class="esf-tw-feed__header-name-wrap">
									<a class="esf-tw-feed__header-name"
										href="<?php echo esc_url( $profile_url ); ?>"
										target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( $display_name ); ?>
									</a>
									<?php echo $this->render_verified_badge( $is_verified, 'esf-tw-feed__header-verified' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped static markup. ?>
								</span>
							<?php endif; ?>
							<?php if ( ( $show_followers && $followers > 0 ) || ( $show_following && $following > 0 ) || ( $show_tweets && $tweet_count > 0 ) ) : ?>
								<div class="esf-tw-feed__header-stats">
									<?php if ( $show_followers && $followers > 0 ) : ?>
										<span class="esf-tw-feed__header-stat esf-tw-feed__header-stat--followers esf-tw-tooltip-target" <?php echo $this->render_tooltip_attributes( __( esf_get_translated_string( 'followers' ), 'easy-facebook-likebox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe escaped attributes from helper. ?>>
											<svg class="esf-tw-tooltip-target" <?php echo $this->render_tooltip_attributes( __( esf_get_translated_string( 'followers' ), 'easy-facebook-likebox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe escaped attributes from helper. ?> width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg>
											<span class="esf-tw-feed__header-stat-count"><?php echo esc_html( esf_twitter_format_number( $followers ) ); ?></span>
										</span>
									<?php endif; ?>
									<?php if ( $show_following && $following > 0 ) : ?>
										<span class="esf-tw-feed__header-stat esf-tw-feed__header-stat--following esf-tw-tooltip-target" <?php echo $this->render_tooltip_attributes( __( esf_get_translated_string( 'tw_following' ), 'easy-facebook-likebox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe escaped attributes from helper. ?>>
											<svg class="esf-tw-tooltip-target" <?php echo $this->render_tooltip_attributes( __( esf_get_translated_string( 'tw_following' ), 'easy-facebook-likebox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe escaped attributes from helper. ?> width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
											<span class="esf-tw-feed__header-stat-count"><?php echo esc_html( esf_twitter_format_number( $following ) ); ?></span>
										</span>
									<?php endif; ?>
									<?php if ( $show_tweets && $tweet_count > 0 ) : ?>
										<span class="esf-tw-feed__header-stat esf-tw-feed__header-stat--tweets esf-tw-tooltip-target" <?php echo $this->render_tooltip_attributes( __( esf_get_translated_string( 'tw_tweets' ), 'easy-facebook-likebox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe escaped attributes from helper. ?>>
											<svg class="esf-tw-tooltip-target" <?php echo $this->render_tooltip_attributes( __( esf_get_translated_string( 'tw_tweets' ), 'easy-facebook-likebox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe escaped attributes from helper. ?> width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
											<span class="esf-tw-feed__header-stat-count"><?php echo esc_html( esf_twitter_format_number( $tweet_count ) ); ?></span>
										</span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</div>
						<?php if ( $show_follow_button && $username ) : ?>
							<a class="esf-tw-feed__header-follow"
								href="<?php echo esc_url( $profile_url ); ?>"
								target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( esf_get_translated_string( 'tw_follow' ), 'easy-facebook-likebox' ); ?>
							</a>
						<?php endif; ?>
					</div>
					<?php if ( $show_bio && '' !== $bio ) : ?>
						<p class="esf-tw-feed__header-bio">
							<?php echo esc_html( $bio ); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php do_action( 'esf_twitter_layout_after_header', $header_data, $this->feed, $this->account, $this ); ?>
		<?php
		$html = ob_get_clean();

		return apply_filters( 'esf_twitter_layout_header_html', $html, $header_data, $this->feed, $this->account, $this );
	}

	/**
	 * Render a single tweet card.
	 *
	 * Timeline layout: image (if any) on left, tweet info on right.
	 *
	 * @since 6.7.6
	 * @param array $tweet Normalized tweet item.
	 * @return string HTML output.
	 */
	protected function render_tweet_card( $tweet ) {
		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings )
			? $this->feed->settings
			: array();
		$card     = isset( $settings['card'] ) && is_array( $settings['card'] )
			? $settings['card']
			: array();
		$feed     = isset( $settings['feed'] ) && is_array( $settings['feed'] )
			? $settings['feed']
			: array();

		$show_image    = ! isset( $card['show_image'] ) || ! empty( $card['show_image'] );
		$show_text     = ! isset( $card['show_text'] ) || ! empty( $card['show_text'] );
		$show_date     = ! isset( $card['show_date'] ) || ! empty( $card['show_date'] );
		$show_likes    = ! isset( $card['show_likes'] ) || ! empty( $card['show_likes'] );
		$show_retweets = ! isset( $card['show_retweets'] ) || ! empty( $card['show_retweets'] );
		$show_replies  = ! isset( $card['show_replies'] ) || ! empty( $card['show_replies'] );
		$show_quotes   = ! isset( $card['show_quotes'] ) || ! empty( $card['show_quotes'] );
		$popup_show_author    = ! isset( $feed['popup_show_author'] ) || ! empty( $feed['popup_show_author'] );
		$popup_show_date      = ! isset( $feed['popup_show_date'] ) || ! empty( $feed['popup_show_date'] );
		$popup_show_follow    = ! isset( $feed['popup_show_follow'] ) || ! empty( $feed['popup_show_follow'] );
		$popup_show_text      = ! isset( $feed['popup_show_text'] ) || ! empty( $feed['popup_show_text'] );
		$popup_show_metrics   = ! isset( $feed['popup_show_metrics'] ) || ! empty( $feed['popup_show_metrics'] );
		$popup_show_view_on_x = ! isset( $feed['popup_show_view_on_x'] ) || ! empty( $feed['popup_show_view_on_x'] );

		$tweet_id   = isset( $tweet['tweet_id'] ) ? $tweet['tweet_id'] : '';
		$text_html  = isset( $tweet['text_html'] ) ? $tweet['text_html'] : '';
		$created_at = isset( $tweet['created_at'] ) ? $tweet['created_at'] : '';
		$tweet_url  = isset( $tweet['tweet_url'] ) ? $tweet['tweet_url'] : '';
		$media      = isset( $tweet['media'] ) && is_array( $tweet['media'] ) ? $tweet['media'] : array();

		// Card-level author data with feed-account fallback.
		$author        = isset( $tweet['author'] ) && is_array( $tweet['author'] ) ? $tweet['author'] : array();
		$author_name   = ! empty( $author['display_name'] ) ? $author['display_name']
			: ( $this->account ? (string) $this->account->display_name : '' );
		$author_handle = ! empty( $author['username'] ) ? $author['username']
			: ( $this->account ? (string) $this->account->username : '' );
		$author_is_verified = $this->is_user_verified( $author );
		if ( ! $author_is_verified && $this->account ) {
			$account_username = isset( $this->account->username ) ? (string) $this->account->username : '';
			if ( '' !== $account_username && '' !== $author_handle && 0 === strcasecmp( $account_username, $author_handle ) ) {
				$author_is_verified = $this->is_account_verified();
			}
		}
		$author_avatar = ! empty( $author['profile_image_url'] ) ? $author['profile_image_url']
			: ( $this->account ? (string) $this->account->profile_image_url : '' );
		if ( function_exists( 'esf_twitter_get_best_profile_image_url' ) ) {
			$author_avatar = esf_twitter_get_best_profile_image_url( $author_avatar );
		}
		$profile_url   = $author_handle ? 'https://x.com/' . $author_handle : 'https://x.com';

		// Media rendering starts from the first available item.
		$first_media = ! empty( $media ) ? $media[0] : array();
		$media_url   = '';
		$media_type  = '';
		if ( ! empty( $first_media ) ) {
			$media_type = isset( $first_media['type'] ) ? $first_media['type'] : '';
			if ( 'photo' === $media_type ) {
				$media_url = isset( $first_media['url'] ) ? $first_media['url'] : '';
			} else {
				$media_url = isset( $first_media['preview_url'] ) ? $first_media['preview_url'] : '';
			}
		}

		$has_media    = $show_image && '' !== $media_url;
		$date_human   = esf_twitter_format_date( $created_at );
		$like_count   = isset( $tweet['like_count'] ) ? (int) $tweet['like_count'] : 0;
		$rt_count     = isset( $tweet['retweet_count'] ) ? (int) $tweet['retweet_count'] : 0;
		$reply_count  = isset( $tweet['reply_count'] ) ? (int) $tweet['reply_count'] : 0;
		$quote_count  = isset( $tweet['quote_count'] ) ? (int) $tweet['quote_count'] : 0;
		$feed_id_attr = isset( $this->feed->id ) ? (int) $this->feed->id : 0;
		$is_retweet   = ! empty( $tweet['is_retweet'] );
		$retweet_from = $is_retweet ? $this->extract_retweet_source_handle( $tweet ) : '';
		if ( $is_retweet && '' !== $text_html ) {
			$text_html = $this->strip_retweet_prefix_from_text_html( $text_html );
		}
		if ( $has_media && '' !== $text_html ) {
			$text_html = $this->strip_media_url_links_from_text_html( $text_html );
		}

		$popup_payload_attr = '';
		$card_classes       = 'esf-tw-feed__card' . ( $has_media ? ' esf-tw-feed__card--has-media' : '' );
		$popup_payload      = null;

		if ( $this->is_popup_enabled_for_feed() ) {
			$popup_payload = $this->build_popup_payload(
				array(
					'tweet'         => $tweet,
					'tweet_url'     => $tweet_url,
					'text_html'     => $text_html,
					'created_at'    => $created_at,
					'date_human'    => $date_human,
					'author_name'   => $author_name,
					'author_handle' => $author_handle,
					'author_avatar' => $author_avatar,
					'author_is_verified' => $author_is_verified,
					'profile_url'   => $profile_url,
					'media'         => $media,
					'like_count'    => $like_count,
					'reply_count'   => $reply_count,
					'retweet_count' => $rt_count,
					'quote_count'   => $quote_count,
					'show_likes'    => $show_likes,
					'show_replies'  => $show_replies,
					'show_retweets' => $show_retweets,
					'show_quotes'   => $show_quotes,
					'popup_show_author'    => $popup_show_author,
					'popup_show_date'      => $popup_show_date,
					'popup_show_follow'    => $popup_show_follow,
					'popup_show_text'      => $popup_show_text,
					'popup_show_metrics'   => $popup_show_metrics,
					'popup_show_view_on_x' => $popup_show_view_on_x,
				)
			);
		}

		if ( ! empty( $popup_payload ) ) {
			$card_classes      .= ' esf-tw-feed__card--popup';
			$popup_json         = wp_json_encode( $popup_payload );
			$popup_payload_attr = is_string( $popup_json ) && '' !== $popup_json
				? ' data-esf-tw-popup="' . esc_attr( $popup_json ) . '"'
				: '';
			if ( '' !== $popup_payload_attr && function_exists( 'esf_twitter_flag_lightbox_script' ) ) {
				esf_twitter_flag_lightbox_script();
			}
		}

		$tweet_context = array(
			'tweet'          => $tweet,
			'tweet_id'       => $tweet_id,
			'text_html'      => $text_html,
			'created_at'     => $created_at,
			'tweet_url'      => $tweet_url,
			'media'          => $media,
			'first_media'    => $first_media,
			'media_url'      => $media_url,
			'media_type'     => $media_type,
			'has_media'      => $has_media,
			'date_human'     => $date_human,
			'like_count'     => $like_count,
			'retweet_count'  => $rt_count,
			'reply_count'    => $reply_count,
			'quote_count'    => $quote_count,
			'is_retweet'     => $is_retweet,
			'retweet_from'   => $retweet_from,
			'show_image'     => $show_image,
			'show_text'      => $show_text,
			'show_date'      => $show_date,
			'show_likes'     => $show_likes,
			'show_retweets'  => $show_retweets,
			'show_replies'   => $show_replies,
			'show_quotes'    => $show_quotes,
			'author_name'    => $author_name,
			'author_handle'  => $author_handle,
			'author_avatar'  => $author_avatar,
			'author_is_verified' => $author_is_verified,
			'profile_url'    => $profile_url,
			'feed_id'        => $feed_id_attr,
		);
		$tweet_context = apply_filters( 'esf_twitter_layout_tweet_context', $tweet_context, $tweet, $this->feed, $this->account, $this );

		ob_start();
		?>
		<?php do_action( 'esf_twitter_layout_before_tweet_card', $tweet_context, $this->feed, $this->account, $this ); ?>
		<article class="<?php echo esc_attr( $card_classes ); ?>"
				data-feed-id="<?php echo esc_attr( (string) $feed_id_attr ); ?>"<?php echo $popup_payload_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped JSON data attribute. ?>>
			<div class="esf-tw-feed__card-body">
				<header class="esf-tw-feed__card-header">
					<?php if ( $author_avatar ) : ?>
						<a href="<?php echo esc_url( $profile_url ); ?>" target="_blank" rel="noopener noreferrer"
							class="esf-tw-feed__card-avatar-link">
							<img src="<?php echo esc_url( $author_avatar ); ?>"
								alt="<?php echo esc_attr( $author_name ); ?>"
								class="esf-tw-feed__card-avatar"
								width="36" height="36" loading="lazy" />
						</a>
					<?php endif; ?>
					<div class="esf-tw-feed__card-author">
						<?php if ( $author_name ) : ?>
							<div class="esf-tw-feed__card-name-wrap">
								<a class="esf-tw-feed__card-name"
									href="<?php echo esc_url( $profile_url ); ?>"
									target="_blank" rel="noopener noreferrer">
									<?php echo esc_html( $author_name ); ?>
								</a>
								<?php echo $this->render_verified_badge( $author_is_verified, 'esf-tw-feed__card-verified esf-tw-tooltip-target--bottom' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped static markup. ?>
							</div>
						<?php endif; ?>
						<?php if ( $show_date && '' !== $date_human ) : ?>
							<time class="esf-tw-feed__card-time" datetime="<?php echo esc_attr( $created_at ); ?>">
								<?php echo esc_html( $date_human ); ?>
							</time>
						<?php endif; ?>
					</div>
					<?php if ( $tweet_id ) : ?>
						<a class="esf-tw-feed__card-logo esf-tw-tooltip-target esf-tw-tooltip-target--bottom esf-tw-tooltip-target--align-right"
							href="<?php echo esc_url( $tweet_url ); ?>"
							target="_blank" rel="noopener noreferrer"
							<?php echo $this->render_tooltip_attributes( __( esf_get_translated_string( 'tw_view_on_x' ), 'easy-facebook-likebox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe escaped attributes from helper. ?>
							aria-label="<?php esc_attr_e( esf_get_translated_string( 'tw_view_tweet_on_x' ), 'easy-facebook-likebox' ); ?>">
							<?php echo $this->get_x_logo_svg( 16, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG helper output. ?>
						</a>
					<?php endif; ?>
				</header>

				<?php if ( $is_retweet ) : ?>
					<p class="esf-tw-feed__retweet-notice">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
						<?php if ( '' !== $retweet_from ) : ?>
							<?php
							$retweet_profile_url = 'https://x.com/' . rawurlencode( $retweet_from );
							?>
							<?php esc_html_e( esf_get_translated_string( 'tw_retweeted_from' ), 'easy-facebook-likebox' ); ?>
							<a href="<?php echo esc_url( $retweet_profile_url ); ?>" target="_blank" rel="noopener noreferrer">@<?php echo esc_html( $retweet_from ); ?></a>
						<?php else : ?>
							<?php esc_html_e( esf_get_translated_string( 'tw_retweet' ), 'easy-facebook-likebox' ); ?>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<?php if ( $show_text && '' !== $text_html ) : ?>
					<p class="esf-tw-feed__card-text"><?php echo $text_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is pre-escaped in normalize_tweets_response via esc_html + filtered anchor tags. ?></p>
				<?php endif; ?>

				<?php if ( $has_media ) : ?>
					<div class="esf-tw-feed__card-media">
						<?php if ( count( $media ) > 1 ) : ?>
							<?php
							$grid_items = array();
							foreach ( array_slice( $media, 0, 3 ) as $grid_media_item ) {
								if ( ! is_array( $grid_media_item ) ) {
									continue;
								}
								$grid_type = isset( $grid_media_item['type'] ) ? (string) $grid_media_item['type'] : 'photo';
								$grid_src  = 'photo' === $grid_type
									? ( isset( $grid_media_item['url'] ) ? (string) $grid_media_item['url'] : '' )
									: ( isset( $grid_media_item['preview_url'] ) ? (string) $grid_media_item['preview_url'] : '' );
								if ( '' === $grid_src ) {
									continue;
								}
								$grid_items[] = array(
									'type' => $grid_type,
									'src'  => $grid_src,
								);
							}
							$extra_count = max( 0, count( $media ) - 3 );
							?>
							<?php if ( ! empty( $grid_items ) ) : ?>
								<a href="<?php echo esc_url( $tweet_url ); ?>" target="_blank" rel="noopener noreferrer"
									class="esf-tw-feed__card-media-grid-link<?php echo ! empty( $popup_payload ) ? ' esf-tw-feed__card-media-link' : ''; ?>"
									<?php if ( ! empty( $popup_payload ) ) : ?>
										data-esf-tw-popup-trigger="1"
										data-esf-tw-media-type="gallery"
									<?php endif; ?>>
									<?php if ( 2 === count( $grid_items ) ) : ?>
										<div class="esf-tw-feed__card-media-grid esf-tw-feed__card-media-grid--two">
											<div class="esf-tw-feed__card-media-grid-cell">
												<img src="<?php echo esc_url( $grid_items[0]['src'] ); ?>" alt="<?php echo esc_attr( $author_name ); ?>" class="esf-tw-feed__card-media-grid-img" loading="lazy" />
											</div>
											<div class="esf-tw-feed__card-media-grid-cell">
												<img src="<?php echo esc_url( $grid_items[1]['src'] ); ?>" alt="<?php echo esc_attr( $author_name ); ?>" class="esf-tw-feed__card-media-grid-img" loading="lazy" />
											</div>
										</div>
									<?php else : ?>
										<div class="esf-tw-feed__card-media-grid">
											<div class="esf-tw-feed__card-media-grid-left">
												<img src="<?php echo esc_url( $grid_items[0]['src'] ); ?>" alt="<?php echo esc_attr( $author_name ); ?>" class="esf-tw-feed__card-media-grid-img" loading="lazy" />
											</div>
											<div class="esf-tw-feed__card-media-grid-right">
												<?php if ( isset( $grid_items[1] ) ) : ?>
													<div class="esf-tw-feed__card-media-grid-cell">
														<img src="<?php echo esc_url( $grid_items[1]['src'] ); ?>" alt="<?php echo esc_attr( $author_name ); ?>" class="esf-tw-feed__card-media-grid-img" loading="lazy" />
													</div>
												<?php endif; ?>
												<?php if ( isset( $grid_items[2] ) ) : ?>
													<div class="esf-tw-feed__card-media-grid-cell">
														<img src="<?php echo esc_url( $grid_items[2]['src'] ); ?>" alt="<?php echo esc_attr( $author_name ); ?>" class="esf-tw-feed__card-media-grid-img" loading="lazy" />
														<?php if ( $extra_count > 0 ) : ?>
															<span class="esf-tw-feed__card-media-grid-more">+<?php echo esc_html( (string) $extra_count ); ?> <?php esc_html_e( esf_get_translated_string( 'tw_more' ), 'easy-facebook-likebox' ); ?></span>
														<?php endif; ?>
													</div>
												<?php elseif ( isset( $grid_items[1] ) && $extra_count > 0 ) : ?>
													<div class="esf-tw-feed__card-media-grid-cell">
														<img src="<?php echo esc_url( $grid_items[1]['src'] ); ?>" alt="<?php echo esc_attr( $author_name ); ?>" class="esf-tw-feed__card-media-grid-img" loading="lazy" />
														<span class="esf-tw-feed__card-media-grid-more">+<?php echo esc_html( (string) $extra_count ); ?> <?php esc_html_e( esf_get_translated_string( 'tw_more' ), 'easy-facebook-likebox' ); ?></span>
													</div>
												<?php endif; ?>
											</div>
										</div>
									<?php endif; ?>
									<?php if ( ! empty( $popup_payload ) ) : ?>
										<span class="esf-tw-feed__card-media-overlay" aria-hidden="true">
											<span class="esf-tw-feed__card-media-overlay-plus">+</span>
											<span class="esf-tw-feed__card-media-overlay-icon esf-tw-feed__card-media-overlay-icon--gallery">
												<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
													<rect x="8" y="8" width="12" height="12" rx="2"></rect>
													<path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"></path>
												</svg>
											</span>
										</span>
									<?php endif; ?>
								</a>
							<?php endif; ?>
						<?php else : ?>
							<a href="<?php echo esc_url( $tweet_url ); ?>" target="_blank" rel="noopener noreferrer"
								class="esf-tw-feed__card-media-link<?php echo 'photo' !== $media_type ? ' esf-tw-feed__card-media-link--video' : ''; ?>"
								<?php if ( ! empty( $popup_payload ) ) : ?>
									data-esf-tw-popup-trigger="1"
									data-esf-tw-media-type="<?php echo esc_attr( 'photo' === $media_type ? 'image' : 'video' ); ?>"
								<?php endif; ?>>
								<img src="<?php echo esc_url( $media_url ); ?>"
									alt="<?php echo esc_attr( $author_name ); ?>"
									class="esf-tw-feed__card-media-img" loading="lazy" />
								<span class="esf-tw-feed__card-media-overlay" aria-hidden="true">
									<span class="esf-tw-feed__card-media-overlay-plus">+</span>
									<?php if ( 'photo' !== $media_type ) : ?>
										<span class="esf-tw-feed__card-media-overlay-icon esf-tw-feed__card-media-overlay-icon--video">
											<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
												<polygon points="10 8 16 12 10 16 10 8"></polygon>
												<rect x="4" y="5" width="16" height="14" rx="2"></rect>
											</svg>
										</span>
									<?php endif; ?>
								</span>
							</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php
				echo $this->render_tweet_meta_actions(
					array(
						'tweet_url'      => $tweet_url,
						'reply_count'    => $reply_count,
						'like_count'     => $like_count,
						'retweet_count'  => $rt_count,
						'quote_count'    => $quote_count,
						'show_replies'   => $show_replies,
						'show_likes'     => $show_likes,
						'show_retweets'  => $show_retweets,
						'show_quotes'    => $show_quotes,
					)
				);
				?>
			</div>
		</article>
		<?php do_action( 'esf_twitter_layout_after_tweet_card', $tweet_context, $this->feed, $this->account, $this ); ?>
		<?php
		$html = ob_get_clean();

		return apply_filters( 'esf_twitter_layout_tweet_card_html', $html, $tweet_context, $this->feed, $this->account, $this );
	}

	/**
	 * Render reusable tweet meta/actions row.
	 *
	 * Kept as a dedicated renderer so the same block can be reused across
	 * feed layouts and future popup/lightbox card templates.
	 *
	 * @since 6.7.6
	 *
	 * @param array $args Meta rendering args.
	 * @return string HTML output.
	 */
	public function render_tweet_meta_actions( $args ) {
		$args = is_array( $args ) ? $args : array();
		$args = apply_filters( 'esf_twitter_layout_meta_actions_args', $args, $this->feed, $this->account, $this );

		$tweet_url     = isset( $args['tweet_url'] ) ? (string) $args['tweet_url'] : '';
		$reply_count   = isset( $args['reply_count'] ) ? (int) $args['reply_count'] : 0;
		$like_count    = isset( $args['like_count'] ) ? (int) $args['like_count'] : 0;
		$retweet_count = isset( $args['retweet_count'] ) ? (int) $args['retweet_count'] : 0;
		$quote_count   = isset( $args['quote_count'] ) ? (int) $args['quote_count'] : 0;
		$show_replies  = ! empty( $args['show_replies'] );
		$show_likes    = ! empty( $args['show_likes'] );
		$show_retweets = ! empty( $args['show_retweets'] );
		$show_quotes   = ! empty( $args['show_quotes'] );

		ob_start();
		?>
		<?php do_action( 'esf_twitter_layout_before_meta_actions', $args, $this->feed, $this->account, $this ); ?>
		<footer class="esf-tw-feed__card-footer">
			<div class="esf-tw-feed__card-metrics">
				<?php if ( $show_replies ) : ?>
					<span class="esf-tw-feed__metric esf-tw-feed__metric--replies esf-tw-tooltip-target" <?php echo $this->render_tooltip_attributes( __( esf_get_translated_string( 'tw_replies' ), 'easy-facebook-likebox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe escaped attributes from helper. ?>>
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
						<?php echo esc_html( esf_twitter_format_number( $reply_count ) ); ?>
					</span>
				<?php endif; ?>
				<?php if ( $show_likes ) : ?>
					<span class="esf-tw-feed__metric esf-tw-feed__metric--likes esf-tw-tooltip-target" <?php echo $this->render_tooltip_attributes( __( esf_get_translated_string( 'tw_likes' ), 'easy-facebook-likebox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe escaped attributes from helper. ?>>
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
						<?php echo esc_html( esf_twitter_format_number( $like_count ) ); ?>
					</span>
				<?php endif; ?>
				<?php if ( $show_retweets ) : ?>
					<span class="esf-tw-feed__metric esf-tw-feed__metric--retweets esf-tw-tooltip-target" <?php echo $this->render_tooltip_attributes( __( esf_get_translated_string( 'tw_retweets' ), 'easy-facebook-likebox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe escaped attributes from helper. ?>>
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
						<?php echo esc_html( esf_twitter_format_number( $retweet_count ) ); ?>
					</span>
				<?php endif; ?>
				<?php if ( $show_quotes ) : ?>
					<span class="esf-tw-feed__metric esf-tw-feed__metric--quotes esf-tw-tooltip-target" <?php echo $this->render_tooltip_attributes( __( esf_get_translated_string( 'tw_quotes' ), 'easy-facebook-likebox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe escaped attributes from helper. ?>>
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-8 8H8l-4 3v-5a8 8 0 0 1 0-12 8 8 0 0 1 8-2 8 8 0 0 1 9 8z"/><path d="M10.5 10H9v3h2.5v-1.3H10l.5-1.7z"/><path d="M15.5 10H14v3h2.5v-1.3H15l.5-1.7z"/></svg>
						<?php echo esc_html( esf_twitter_format_number( $quote_count ) ); ?>
					</span>
				<?php endif; ?>
				<details class="esf-tw-feed__share-menu">
					<summary class="esf-tw-feed__metric esf-tw-feed__metric--share esf-tw-tooltip-target" <?php echo $this->render_tooltip_attributes( __( esf_get_translated_string( 'tw_share' ), 'easy-facebook-likebox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe escaped attributes from helper. ?>>
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51 15.42 17.49"/><path d="M15.41 6.51 8.59 10.49"/></svg>
					</summary>
					<div class="esf-tw-feed__share-popover">
						<a href="<?php echo esc_url( 'https://x.com/intent/tweet?url=' . rawurlencode( $tweet_url ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( esf_get_translated_string( 'tw_share_on_x' ), 'easy-facebook-likebox' ); ?></a>
						<a href="<?php echo esc_url( 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $tweet_url ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( esf_get_translated_string( 'tw_share_on_facebook' ), 'easy-facebook-likebox' ); ?></a>
						<a href="<?php echo esc_url( 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode( $tweet_url ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( esf_get_translated_string( 'tw_share_on_linkedin' ), 'easy-facebook-likebox' ); ?></a>
						<a href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( $tweet_url ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( esf_get_translated_string( 'tw_share_on_whatsapp' ), 'easy-facebook-likebox' ); ?></a>
					</div>
				</details>
			</div>
		</footer>
		<?php do_action( 'esf_twitter_layout_after_meta_actions', $args, $this->feed, $this->account, $this ); ?>
		<?php
		$html = ob_get_clean();

		return apply_filters( 'esf_twitter_layout_meta_actions_html', $html, $args, $this->feed, $this->account, $this );
	}

	/**
	 * Build reusable tooltip attributes for any element.
	 *
	 * @since 6.7.6
	 *
	 * @param string $label Tooltip label.
	 * @return string Escaped HTML attributes.
	 */
	protected function render_tooltip_attributes( $label ) {
		$label = sanitize_text_field( (string) $label );
		if ( '' === $label ) {
			return '';
		}

		return 'data-tooltip="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '"';
	}

	/**
	 * Return reusable inline X logo SVG markup.
	 *
	 * @since 6.7.6
	 *
	 * @param int $width  Icon width.
	 * @param int $height Icon height.
	 * @return string SVG markup.
	 */
	protected function get_x_logo_svg( $width = 16, $height = 16 ) {
		$width  = max( 1, (int) $width );
		$height = max( 1, (int) $height );

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.74l7.73-8.835L1.254 2.25H8.08l4.262 5.636L18.244 2.25zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
			$width,
			$height
		);
	}

	/**
	 * Extract the source handle for a retweet notice.
	 *
	 * @since 6.7.6
	 *
	 * @param array $tweet Normalized tweet item.
	 * @return string Retweeted account handle (without @), or empty string.
	 */
	protected function extract_retweet_source_handle( $tweet ) {
		$text = isset( $tweet['text'] ) ? (string) $tweet['text'] : '';
		if ( '' === $text ) {
			return '';
		}

		$text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );

		if ( preg_match( '/\bRT\s+@([A-Za-z0-9_]{1,15})\b/i', $text, $matches ) ) {
			return sanitize_text_field( $matches[1] );
		}

		return '';
	}

	/**
	 * Remove the leading "RT @handle:" prefix from rendered tweet text HTML.
	 *
	 * Handles both plain-text and linkified mention variants so retweets do not
	 * duplicate the source line once the dedicated retweet notice is shown.
	 *
	 * @since 6.7.6
	 *
	 * @param string $text_html Tweet text HTML.
	 * @return string Cleaned tweet text HTML.
	 */
	protected function strip_retweet_prefix_from_text_html( $text_html ) {
		$text_html = is_string( $text_html ) ? $text_html : '';
		if ( '' === $text_html ) {
			return '';
		}

		// Linkified mention prefix: RT <a ...>@handle</a>: .
		$text_html = preg_replace( '/^\s*RT\s*<a\b[^>]*>@[A-Za-z0-9_]{1,15}<\/a>\s*:\s*/i', '', $text_html );

		// Plain-text mention prefix fallback: RT @handle: .
		$text_html = preg_replace( '/^\s*RT\s*@[A-Za-z0-9_]{1,15}\s*:\s*/i', '', $text_html );

		return is_string( $text_html ) ? ltrim( $text_html ) : '';
	}

	/**
	 * Remove media attachment links (pic.x.com / pic.twitter.com) from text HTML.
	 *
	 * Cached tweets may still contain legacy linkified media URLs. Strip them at
	 * render time so media tweets don't show redundant caption links.
	 *
	 * @since 6.7.6
	 *
	 * @param string $text_html Tweet text HTML.
	 * @return string Cleaned tweet text HTML.
	 */
	protected function strip_media_url_links_from_text_html( $text_html ) {
		$text_html = is_string( $text_html ) ? $text_html : '';
		if ( '' === $text_html ) {
			return '';
		}

		// Remove linkified media URLs when either href OR visible link text points to pic.x.com.
		$text_html = preg_replace_callback(
			'/\s*<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>\s*/is',
			static function ( $matches ) {
				$href      = strtolower( html_entity_decode( (string) $matches[1], ENT_QUOTES, 'UTF-8' ) );
				$link_text = strtolower( wp_strip_all_tags( html_entity_decode( (string) $matches[2], ENT_QUOTES, 'UTF-8' ) ) );
				$is_media  = (
					false !== strpos( $href, 'pic.x.com/' ) ||
					false !== strpos( $href, 'pic.twitter.com/' ) ||
					false !== strpos( $link_text, 'pic.x.com/' ) ||
					false !== strpos( $link_text, 'pic.twitter.com/' )
				);

				return $is_media ? ' ' : $matches[0];
			},
			$text_html
		);

		// Remove bare media URLs when they exist without anchors.
		$text_html = preg_replace( '/\s*https?:\/\/(?:pic\.x\.com|pic\.twitter\.com)\/\S+\s*/i', ' ', $text_html );
		$text_html = preg_replace( '/\s*(?:pic\.x\.com|pic\.twitter\.com)\/\S+\s*/i', ' ', $text_html );
		$text_html = preg_replace( '/\s{2,}/', ' ', $text_html );
		$text_html = preg_replace( '/(?:<br\s*\/?>\s*){2,}/i', '<br />', $text_html );

		return is_string( $text_html ) ? trim( $text_html ) : '';
	}

	/**
	 * Check whether popup/lightbox is enabled for this feed.
	 *
	 * @since 6.7.6
	 * @return bool
	 */
	protected function is_popup_enabled_for_feed() {
		if ( ! function_exists( 'esf_twitter_has_twitter_plan' ) || ! esf_twitter_has_twitter_plan() ) {
			return false;
		}

		$settings = isset( $this->feed->settings ) && is_array( $this->feed->settings )
			? $this->feed->settings
			: array();
		$feed     = isset( $settings['feed'] ) && is_array( $settings['feed'] )
			? $settings['feed']
			: array();

		return ! isset( $feed['enable_popup'] ) || ! empty( $feed['enable_popup'] );
	}

	/**
	 * Build popup payload for frontend lightbox rendering.
	 *
	 * @since 6.7.6
	 * @param array $context Tweet context for popup payload.
	 * @return array|null
	 */
	protected function build_popup_payload( $context ) {
		if ( ! is_array( $context ) ) {
			return null;
		}

		$media = isset( $context['media'] ) && is_array( $context['media'] ) ? $context['media'] : array();
		if ( empty( $media ) ) {
			return null;
		}

		$media_items = array();
		foreach ( $media as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type    = isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : 'photo';
			$image   = 'photo' === $type
				? ( isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '' )
				: ( isset( $item['preview_url'] ) ? esc_url_raw( (string) $item['preview_url'] ) : '' );
			$alt     = isset( $item['alt_text'] ) ? sanitize_text_field( (string) $item['alt_text'] ) : '';
			if ( '' === $image ) {
				continue;
			}
			$media_items[] = array(
				'type'      => $type,
				'src'       => $image,
				'video_url' => isset( $item['video_url'] ) ? esc_url_raw( (string) $item['video_url'] ) : '',
				'alt'       => $alt,
				'width'  => isset( $item['width'] ) ? (int) $item['width'] : 0,
				'height' => isset( $item['height'] ) ? (int) $item['height'] : 0,
			);
		}

		if ( empty( $media_items ) ) {
			return null;
		}

		return array(
			'tweet_id'       => isset( $context['tweet']['tweet_id'] ) ? sanitize_text_field( (string) $context['tweet']['tweet_id'] ) : '',
			'tweet_url'      => isset( $context['tweet_url'] ) ? esc_url_raw( (string) $context['tweet_url'] ) : '',
			'text_html'      => isset( $context['text_html'] ) ? (string) $context['text_html'] : '',
			'created_at'     => isset( $context['created_at'] ) ? sanitize_text_field( (string) $context['created_at'] ) : '',
			'date_human'     => isset( $context['date_human'] ) ? sanitize_text_field( (string) $context['date_human'] ) : '',
			'author_name'    => isset( $context['author_name'] ) ? sanitize_text_field( (string) $context['author_name'] ) : '',
			'author_handle'  => isset( $context['author_handle'] ) ? sanitize_text_field( (string) $context['author_handle'] ) : '',
			'author_avatar'  => isset( $context['author_avatar'] ) ? esc_url_raw( (string) $context['author_avatar'] ) : '',
			'author_is_verified' => ! empty( $context['author_is_verified'] ),
			'profile_url'    => isset( $context['profile_url'] ) ? esc_url_raw( (string) $context['profile_url'] ) : '',
			'media'          => $media_items,
			'like_count'     => isset( $context['like_count'] ) ? (int) $context['like_count'] : 0,
			'reply_count'    => isset( $context['reply_count'] ) ? (int) $context['reply_count'] : 0,
			'retweet_count'  => isset( $context['retweet_count'] ) ? (int) $context['retweet_count'] : 0,
			'quote_count'    => isset( $context['quote_count'] ) ? (int) $context['quote_count'] : 0,
			'show_likes'     => ! empty( $context['show_likes'] ),
			'show_replies'   => ! empty( $context['show_replies'] ),
			'show_retweets'  => ! empty( $context['show_retweets'] ),
			'show_quotes'    => ! empty( $context['show_quotes'] ),
			'popup_show_author'    => ! isset( $context['popup_show_author'] ) || ! empty( $context['popup_show_author'] ),
			'popup_show_date'      => ! isset( $context['popup_show_date'] ) || ! empty( $context['popup_show_date'] ),
			'popup_show_follow'    => ! isset( $context['popup_show_follow'] ) || ! empty( $context['popup_show_follow'] ),
			'popup_show_text'      => ! isset( $context['popup_show_text'] ) || ! empty( $context['popup_show_text'] ),
			'popup_show_metrics'   => ! isset( $context['popup_show_metrics'] ) || ! empty( $context['popup_show_metrics'] ),
			'popup_show_view_on_x' => ! isset( $context['popup_show_view_on_x'] ) || ! empty( $context['popup_show_view_on_x'] ),
		);
	}

	/**
	 * Determine whether a normalized user payload is verified.
	 *
	 * @since 6.7.6
	 * @param array $user Normalized user payload.
	 * @return bool
	 */
	protected function is_user_verified( $user ) {
		if ( ! is_array( $user ) ) {
			return false;
		}
		if ( $this->has_verified_flag( isset( $user['is_verified'] ) ? $user['is_verified'] : null ) ) {
			return true;
		}

		return $this->has_valid_verified_type( isset( $user['verified_type'] ) ? $user['verified_type'] : '' );
	}

	/**
	 * Determine whether current feed account is verified.
	 *
	 * @since 6.7.6
	 * @return bool
	 */
	protected function is_account_verified() {
		if ( ! $this->account ) {
			return false;
		}
		if ( $this->has_verified_flag( isset( $this->account->is_verified ) ? $this->account->is_verified : null ) ) {
			return true;
		}
		if ( $this->has_valid_verified_type( isset( $this->account->verified_type ) ? $this->account->verified_type : '' ) ) {
			return true;
		}
		if ( isset( $this->account->account_data ) && is_string( $this->account->account_data ) && '' !== $this->account->account_data ) {
			$account_data = json_decode( $this->account->account_data, true );
			if ( is_array( $account_data ) ) {
				return $this->has_verified_flag( isset( $account_data['is_verified'] ) ? $account_data['is_verified'] : null )
					|| $this->has_valid_verified_type( isset( $account_data['verified_type'] ) ? $account_data['verified_type'] : '' );
			}
		}
		return false;
	}

	/**
	 * Check normalized verified flag value.
	 *
	 * @since 6.7.6
	 * @param mixed $value Verified flag value.
	 * @return bool
	 */
	protected function has_verified_flag( $value ) {
		if ( is_bool( $value ) ) {
			return true === $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value === 1;
		}

		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );
			return in_array( $normalized, array( '1', 'true', 'yes' ), true );
		}

		return false;
	}

	/**
	 * Validate verified_type against known verified tiers.
	 *
	 * @since 6.7.6
	 * @param string $verified_type Verified type value.
	 * @return bool
	 */
	protected function has_valid_verified_type( $verified_type ) {
		$verified_type = strtolower( trim( (string) $verified_type ) );
		if ( '' === $verified_type ) {
			return false;
		}

		return in_array( $verified_type, array( 'blue', 'business', 'government' ), true );
	}

	/**
	 * Render reusable verified badge markup.
	 *
	 * @since 6.7.6
	 * @param bool   $is_verified Whether badge should render.
	 * @param string $class_name  Additional class name.
	 * @return string
	 */
	protected function render_verified_badge( $is_verified, $class_name = '' ) {
		if ( ! $is_verified ) {
			return '';
		}
		$extra_classes = array_filter( preg_split( '/\s+/', (string) $class_name ) );
		$extra_classes = array_map( 'sanitize_html_class', $extra_classes );
		$classes       = trim( 'esf-tw-feed__verified-badge ' . implode( ' ', $extra_classes ) );
		return sprintf(
			'<span class="%1$s esf-tw-tooltip-target" %2$s><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path fill="#1d9bf0" d="M22.5 12l-2.3 2.6.3 3.4-3.3.8-1.7 2.9L12 20.2l-3.5 1.5-1.7-2.9-3.3-.8.3-3.4L1.5 12l2.3-2.6-.3-3.4 3.3-.8 1.7-2.9L12 3.8l3.5-1.5 1.7 2.9 3.3.8-.3 3.4z"></path><path fill="#ffffff" d="M10.4 15.6l-3-3 1.4-1.4 1.6 1.6 4.8-4.8 1.4 1.4z"></path></svg></span>',
			esc_attr( $classes ),
			$this->render_tooltip_attributes( __( esf_get_translated_string( 'tw_verified_account' ), 'easy-facebook-likebox' ) )
		);
	}
}
