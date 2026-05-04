# Twitter Frontend Hook Reference

This document lists the frontend rendering hooks added to the Twitter module so third-party code can extend output without editing plugin core files.

## Renderer Hooks

### Filters

- `esf_twitter_render_settings( array $settings, int $feed_id, object $feed_row, array $settings_override ): array`
  - Modify merged feed settings before rendering starts.
- `esf_twitter_render_feed_object( object $feed_obj, int $feed_id, object $feed_row, array $settings ): object`
  - Modify normalized feed object before tweets/layout are resolved.
- `esf_twitter_render_tweets( array $tweets, int $feed_id, object $feed_obj, object $account ): array`
  - Change tweet payload before layout rendering.
- `esf_twitter_render_layout_type( string $layout_type, int $feed_id, object $feed_obj, array $tweets, object $account ): string`
  - Override selected layout type.
- `esf_twitter_render_wrapper_classes( array $classes, int $feed_id, object $feed_obj, string $layout_type ): array`
  - Add/remove wrapper CSS classes.
- `esf_twitter_render_wrapper_attributes( array $attributes, int $feed_id, object $feed_obj, string $layout_type ): array`
  - Add wrapper attributes (for example `data-*` attributes).
- `esf_twitter_render_layout_html( string $layout_html, int $feed_id, object $feed_obj, string $layout_type, array $tweets, object $account ): string`
  - Replace/append rendered layout HTML.
- `esf_twitter_render_html( string $html, int $feed_id, object $feed_obj, string $layout_type, array $tweets, object $account ): string`
  - Final output filter for full feed HTML.

### Actions

- `esf_twitter_before_feed_render( int $feed_id, object $feed_obj, string $layout_type, array $tweets, object $account )`
- `esf_twitter_after_feed_render( int $feed_id, object $feed_obj, string $layout_type, array $tweets, object $account, string $html )`

Use these for logging/analytics/debug side effects. Do not echo markup in actions unless you control output buffering flow.

## Base Layout Hooks

### Filters

- `esf_twitter_layout_empty_state_html( string $html, object $feed, ?object $account, ESF_Twitter_Layout_Base $layout ): string`
- `esf_twitter_layout_show_header( bool $show, array $header_settings, object $feed, ?object $account, ESF_Twitter_Layout_Base $layout ): bool`
- `esf_twitter_layout_header_data( array $header_data, array $header_settings, object $feed, ?object $account, ESF_Twitter_Layout_Base $layout ): array`
- `esf_twitter_layout_header_html( string $html, array $header_data, object $feed, ?object $account, ESF_Twitter_Layout_Base $layout ): string`
- `esf_twitter_layout_tweet_context( array $tweet_context, array $tweet, object $feed, ?object $account, ESF_Twitter_Layout_Base $layout ): array`
- `esf_twitter_layout_tweet_card_html( string $html, array $tweet_context, object $feed, ?object $account, ESF_Twitter_Layout_Base $layout ): string`
- `esf_twitter_layout_meta_actions_args( array $args, object $feed, ?object $account, ESF_Twitter_Layout_Base $layout ): array`
- `esf_twitter_layout_meta_actions_html( string $html, array $args, object $feed, ?object $account, ESF_Twitter_Layout_Base $layout ): string`

### Actions

- `esf_twitter_layout_before_header( array $header_data, object $feed, ?object $account, ESF_Twitter_Layout_Base $layout )`
- `esf_twitter_layout_after_header( array $header_data, object $feed, ?object $account, ESF_Twitter_Layout_Base $layout )`
- `esf_twitter_layout_before_tweet_card( array $tweet_context, object $feed, ?object $account, ESF_Twitter_Layout_Base $layout )`
- `esf_twitter_layout_after_tweet_card( array $tweet_context, object $feed, ?object $account, ESF_Twitter_Layout_Base $layout )`
- `esf_twitter_layout_before_meta_actions( array $args, object $feed, ?object $account, ESF_Twitter_Layout_Base $layout )`
- `esf_twitter_layout_after_meta_actions( array $args, object $feed, ?object $account, ESF_Twitter_Layout_Base $layout )`

## Timeline Layout Hooks

### Filters

- `esf_twitter_layout_timeline_header_html( string $header_html, object $feed, ?object $account, array $tweets, ESF_Twitter_Layout_Timeline $layout ): string`
- `esf_twitter_layout_timeline_tweets( array $tweets, object $feed, ?object $account, ESF_Twitter_Layout_Timeline $layout ): array`
- `esf_twitter_layout_timeline_html( string $html, object $feed, ?object $account, array $tweets, ESF_Twitter_Layout_Timeline $layout ): string`

### Actions

- `esf_twitter_layout_timeline_before_cards( array $tweets, object $feed, ?object $account, ESF_Twitter_Layout_Timeline $layout )`
- `esf_twitter_layout_timeline_after_cards( array $tweets, object $feed, ?object $account, ESF_Twitter_Layout_Timeline $layout, string $cards_html )`

## Usage Examples

### Add a custom wrapper attribute

```php
add_filter( 'esf_twitter_render_wrapper_attributes', function( $attributes, $feed_id ) {
	$attributes['data-feed-id'] = (string) $feed_id;
	$attributes['data-source']  = 'partner-plugin';
	return $attributes;
}, 10, 2 );
```

### Hide retweets for a specific feed at runtime

```php
add_filter( 'esf_twitter_render_tweets', function( $tweets, $feed_id ) {
	if ( 42 !== (int) $feed_id ) {
		return $tweets;
	}
	return array_values( array_filter( $tweets, function( $tweet ) {
		return empty( $tweet['is_retweet'] );
	} ) );
}, 10, 2 );
```

### Inject custom action icon into meta row HTML

```php
add_filter( 'esf_twitter_layout_meta_actions_html', function( $html ) {
	$button = '<a class="esf-tw-feed__metric esf-tw-feed__metric--bookmark" href="#" aria-label="Bookmark">🔖</a>';
	return str_replace(
		'</div>' . "\n" . "\t\t</footer>",
		$button . '</div>' . "\n" . "\t\t</footer>",
		$html
	);
}, 10, 1 );
```

## Implementation Notes

- Always return the same data type expected by each filter.
- Sanitize/escape any custom attributes or HTML you add.
- Keep heavy API calls out of filters to avoid rendering latency.
- Prefer filters (return new value) over actions (side effects only) for markup/data changes.
