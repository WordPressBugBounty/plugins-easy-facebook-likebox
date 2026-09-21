<?php

/**
 * TikTok layout popup/lightbox helpers.
 *
 * Shared by tile layouts so popup payload generation and markup attributes
 * stay in one place — mirrors {@see \EasySocialFeed\Instagram\Layouts\InstagramPopupSupport}.
 * TikTok Display API has no MP4 URL; the lightbox embeds via `embed_link` / embed/v2.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok\Layouts
 * @since 6.9.0
 */
namespace EasySocialFeed\TikTok\Layouts;

use EasySocialFeed\Layouts\Contracts\PostItem;
use EasySocialFeed\Layouts\ValueObjects\PostMedia;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Popup support for TikTok layouts.
 *
 * @since 6.9.0
 */
trait TikTokPopupSupport
{
    /**
     * Whether popup/lightbox can render for the current feed.
     *
     * @since 6.9.0
     * @return bool
     */
    protected function can_render_popup() : bool {
        if ( !function_exists( 'efl_fs' ) || !efl_fs()->can_use_premium_code__premium_only() ) {
            return false;
        }
        if ( !function_exists( 'esf_tiktok_has_tiktok_plan' ) || !esf_tiktok_has_tiktok_plan() ) {
            return false;
        }
        return $this->is_tiktok_popup_enabled__premium_only();
    }

}