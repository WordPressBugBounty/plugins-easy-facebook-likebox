<?php

/**
 * Instagram layout stories support trait.
 *
 * Shared by tile-style layouts so stories fetching, caching, header ring
 * options, and the stories row stay in one place.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram\Layouts
 * @since 6.9.0
 */
namespace EasySocialFeed\Instagram\Layouts;

use EasySocialFeed\Instagram\Layouts\Primitives\StoriesRow;
use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Stories support for Instagram layouts.
 *
 * @since 6.9.0
 */
trait InstagramStoriesSupport
{
    /**
     * Cached story items for the current layout render pass.
     *
     * @var array<int,array<string,mixed>>|null
     */
    private $instagram_stories_items_cache = null;

}