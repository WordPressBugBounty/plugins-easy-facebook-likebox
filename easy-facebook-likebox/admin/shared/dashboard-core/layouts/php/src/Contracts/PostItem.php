<?php
/**
 * PostItem contract.
 *
 * Canonical post shape consumed by shared layouts and primitives. Each module
 * (Instagram, Twitter, YouTube) ships a `PostMapper` that converts native API
 * payloads to a `PostItem` so every renderer can stay module-agnostic.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Contracts
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\Contracts;

use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\Layouts\ValueObjects\PostMedia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface PostItem {

	/**
	 * Stable, source-of-truth identifier (e.g. IG media id, tweet id, video id).
	 */
	public function get_id(): string;

	/**
	 * Public URL of the post on the source network.
	 */
	public function get_permalink(): string;

	/**
	 * ISO-8601 created-at timestamp, or empty string when unknown.
	 */
	public function get_created_at(): string;

	/**
	 * Renderable HTML for the post text/caption.
	 *
	 * Implementations are responsible for escaping. Layouts treat the value
	 * as trusted output.
	 */
	public function get_text_html(): string;

	/**
	 * Post author (may equal the feed's owning account, may not).
	 */
	public function get_author(): ?AccountSummary;

	/**
	 * Ordered media attachments.
	 *
	 * @return PostMedia[]
	 */
	public function get_media(): array;

	/**
	 * Engagement counters keyed by metric slug (likes, comments, views, …).
	 * Only metrics provided by the source network are populated.
	 *
	 * @return array<string,int>
	 */
	public function get_metrics(): array;

	/**
	 * Module-specific overflow (e.g. is_retweet, is_reel, captionable, …).
	 *
	 * @return array<string,mixed>
	 */
	public function get_extras(): array;
}
