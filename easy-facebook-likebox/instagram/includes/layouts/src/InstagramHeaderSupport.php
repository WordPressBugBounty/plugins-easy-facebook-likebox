<?php
/**
 * Instagram layout header support trait.
 *
 * Centralizes header option building and rendering for all Instagram layouts
 * so stories, colors, and stats toggles are not duplicated per layout.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram\Layouts
 * @since 6.9.0
 */

namespace EasySocialFeed\Instagram\Layouts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Header rendering helpers for Instagram layouts.
 *
 * @since 6.9.0
 */
trait InstagramHeaderSupport {

	use InstagramStoriesSupport;

	/**
	 * Build AccountHeader options from feed settings.
	 *
	 * @since 6.9.0
	 * @return array<string,mixed>
	 */
	protected function build_instagram_header_options(): array {
		if ( $this->feed_is_hashtag() ) {
			return array(
				'show_avatar'        => (bool) $this->setting( 'header.show_avatar', true ),
				'show_name'          => (bool) $this->setting( 'header.show_name', true ),
				'show_handle'        => false,
				'show_bio'           => false,
				'avatar_round'       => true,
				'avatar_icon_html'   => $this->render_hashtag_header_icon(),
				'show_stats'         => array(),
				'show_follow_button' => false,
				'follow_label'       => __( 'Follow on Instagram', 'easy-facebook-likebox' ),
			);
		}

		$header_stats = array();
		if ( $this->setting( 'header.show_followers', true ) ) {
			$header_stats[] = 'followers';
		}
		if ( $this->setting( 'header.show_media', true ) ) {
			$header_stats[] = 'media';
		}

		$header_options = array(
			'show_avatar'        => (bool) $this->setting( 'header.show_avatar', true ),
			'show_name'          => (bool) $this->setting( 'header.show_name', true ),
			'show_handle'        => (bool) $this->setting( 'header.show_handle', true ),
			'show_bio'           => (bool) $this->setting( 'header.show_bio', true ),
			'avatar_round'       => (bool) $this->setting( 'header.avatar_round', true ),
			'show_stats'         => $header_stats,
			'stat_display'       => 'icons',
			'stat_labels'        => array(
				'followers' => __( 'Followers', 'easy-facebook-likebox' ),
				'media'     => __( 'Posts', 'easy-facebook-likebox' ),
			),
			'show_follow_button' => (bool) $this->setting( 'header.show_follow_button', true ),
			'follow_label'       => __( 'Follow on Instagram', 'easy-facebook-likebox' ),
		);

		if ( function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan() ) {
			if ( $this->setting( 'header.show_link', true ) && null !== $this->account && method_exists( $this->account, 'get_website' ) ) {
				$website = \esc_url_raw( $this->account->get_website() );
				if ( '' !== $website ) {
					$header_options['show_link'] = true;
					$header_options['link_url']  = $website;
				}
			}

			$header_options['rounded']     = (bool) $this->setting( 'header.header_rounded', true );
			$header_options['show_border'] = (bool) $this->setting( 'header.show_border', true );
			$padding                       = max( 0, min( 48, (int) $this->setting( 'header.padding', 16 ) ) );
			if ( 16 !== $padding ) {
				$header_options['padding'] = $padding;
			}

			$border_color = sanitize_hex_color( (string) $this->setting( 'header.border_color', '' ) );
			if ( is_string( $border_color ) && '' !== $border_color ) {
				$header_options['border_color'] = $border_color;
			}

			$bg_color = sanitize_hex_color( (string) $this->setting( 'header.bg_color', '' ) );
			if ( is_string( $bg_color ) && '' !== $bg_color ) {
				$header_options['bg_color'] = $bg_color;
			}
			$text_color = sanitize_hex_color( (string) $this->setting( 'header.text_color', '' ) );
			if ( is_string( $text_color ) && '' !== $text_color ) {
				$header_options['text_color'] = $text_color;
			}
		}

		if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
			if ( function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan() ) {
				if ( method_exists( $this, 'resolve_instagram_story_items__premium_only' ) ) {
					$story_items = $this->resolve_instagram_story_items__premium_only();
					if ( ! empty( $story_items ) && method_exists( $this, 'merge_instagram_stories_header_options__premium_only' ) ) {
						$header_options = $this->merge_instagram_stories_header_options__premium_only( $header_options, $story_items );
					}
				}
			}
		}

		return $header_options;
	}

	/**
	 * Render the Instagram feed header.
	 *
	 * @since 6.9.0
	 * @return string
	 */
	protected function render_instagram_header(): string {
		return $this->render_header( $this->build_instagram_header_options() );
	}

	/**
	 * Render the stories row and return stories payload attribute for the layout wrapper.
	 *
	 * @since 6.9.0
	 *
	 * @return array{row_html:string,payload_attr:string}
	 */
	protected function render_instagram_stories_section(): array {
		$empty = array(
			'row_html'     => '',
			'payload_attr' => '',
		);

		if ( $this->feed_is_hashtag() ) {
			return $empty;
		}

		// This entire code block will be removed from the free version.
		if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
			if ( function_exists( 'esf_instagram_has_instagram_plan' ) && esf_instagram_has_instagram_plan() ) {
				if ( method_exists( $this, 'resolve_instagram_story_items__premium_only' ) ) {
					$items = $this->resolve_instagram_story_items__premium_only();
					if ( ! empty( $items ) ) {
						$payload      = method_exists( $this, 'build_instagram_stories_payload__premium_only' )
							? $this->build_instagram_stories_payload__premium_only( $items )
							: null;
						$row_html     = method_exists( $this, 'render_instagram_stories_row__premium_only' )
							? $this->render_instagram_stories_row__premium_only( $items )
							: '';
						$payload_attr = method_exists( $this, 'instagram_stories_payload_attribute__premium_only' )
							? $this->instagram_stories_payload_attribute__premium_only( $payload )
							: '';

						return array(
							'row_html'     => $row_html,
							'payload_attr' => $payload_attr,
						);
					}
				}
			}
		}

		return $empty;
	}

	/**
	 * Whether the current feed is a hashtag feed.
	 *
	 * @since 6.9.0
	 * @return bool
	 */
	protected function feed_is_hashtag(): bool {
		return function_exists( 'esf_instagram_feed_is_hashtag' ) && esf_instagram_feed_is_hashtag( $this->feed );
	}

	/**
	 * Markup for the hashtag feed header icon (replaces the account avatar).
	 *
	 * @since 6.9.0
	 * @return string
	 */
	protected function render_hashtag_header_icon(): string {
		return '<span class="esf-instagram-feed__header-avatar-icon" aria-hidden="true">#</span>';
	}
}
