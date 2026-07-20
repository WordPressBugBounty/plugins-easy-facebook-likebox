<?php

/**
 * Contract for layouts that render MediaTile cells.
 *
 * Load More and other append flows call `render_tiles_for_posts__premium_only()`
 * without needing a concrete layout class (Grid, Row, future Facebook layouts).
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Contracts
 * @since 6.9.0
 */
namespace EasySocialFeed\Layouts\Contracts;

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Layouts that can render a batch of tile cells.
 *
 * @since 6.9.0
 */
interface RendersTiles {
}
