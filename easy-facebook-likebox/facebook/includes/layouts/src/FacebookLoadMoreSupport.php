<?php

/**
 * Facebook Load More helpers for layout classes.
 *
 * Ships in free builds. Pro markup/cursor helpers use `__premium_only`.
 * Layouts enable Load More when `esf_facebook_layout_can_load_more` is true
 * (defaults false; registered from Main when Pro).
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook\Layouts
 * @since 6.7.9
 */
namespace EasySocialFeed\Facebook\Layouts;

use EasySocialFeed\Layouts\Primitives\LoadMoreButton;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Load More helpers for Facebook layout classes.
 *
 * @since 6.7.9
 */
trait FacebookLoadMoreSupport
{
    /**
     * Whether this layout may emit a Load More control.
     *
     * @since 6.7.9
     * @return bool
     */
    protected function can_render_facebook_load_more() : bool {
        return (bool) $this->setting( 'feed.load_more', false ) && $this->definition->supports( 'load_more' ) && (bool) apply_filters(
            'esf_facebook_layout_can_load_more',
            false,
            $this->feed,
            $this->account,
            $this
        );
    }

    /**
     * Emit Load More when Pro code and filter allow it.
     *
     * @since 6.7.9
     * @param int $display_offset Posts already rendered.
     * @return string
     */
    protected function maybe_render_facebook_load_more( int $display_offset ) : string {
        if ( !$this->can_render_facebook_load_more() ) {
            return '';
        }
        if ( !function_exists( 'efl_fs' ) || !efl_fs()->can_use_premium_code__premium_only() ) {
            return '';
        }
        if ( !function_exists( 'esf_facebook_has_facebook_plan' ) || !esf_facebook_has_facebook_plan() ) {
            return '';
        }
        if ( !method_exists( $this, 'render_facebook_load_more__premium_only' ) ) {
            return '';
        }
        return $this->render_facebook_load_more__premium_only( $display_offset, ( function_exists( 'esf_translate_registry_string' ) ? esf_translate_registry_string( 'load_more', 'Load More' ) : __( 'Load more', 'easy-facebook-likebox' ) ), ( function_exists( 'esf_translate_registry_string' ) ? esf_translate_registry_string( 'fb_load_more_posts_aria', 'Load more Facebook posts' ) : __( 'Load more Facebook posts', 'easy-facebook-likebox' ) ) );
    }

}