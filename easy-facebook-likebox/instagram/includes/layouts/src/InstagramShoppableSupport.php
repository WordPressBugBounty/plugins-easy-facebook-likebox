<?php

/**
 * Instagram shoppable link support for modern layouts.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram\Layouts
 * @since 6.9.0
 */
namespace EasySocialFeed\Instagram\Layouts;

use EasySocialFeed\Layouts\Contracts\PostItem;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Resolves shoppable destinations for feed posts.
 *
 * @since 6.9.0
 */
trait InstagramShoppableSupport
{
    /**
     * Cached shoppable settings for the current feed render.
     *
     * @since 6.9.0
     * @var array{defaults:array<string,mixed>,posts:array<string,array<string,mixed>>}|null
     */
    private $instagram_shoppable_settings = null;

}