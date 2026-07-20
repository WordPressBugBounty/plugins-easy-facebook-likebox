<?php
/**
 * Preview-response builder.
 *
 * Normalizes the Renderer's output array into the JSON shape the dashboard
 * `useFeedPreview` hook consumes. Shape is identical to today's Twitter
 * preview endpoint so the React layer needs no changes when X/YT migrate.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PreviewResponseBuilder {

	/**
	 * Build the preview-response payload.
	 *
	 * @param array<string,mixed> $renderer_output Output from `Renderer::render()`.
	 * @param bool                $has_local_cache Whether the module already has cached posts for this feed.
	 *
	 * @return array{
	 *   html: string,
	 *   css_url: string,
	 *   layout_css_url: string,
	 *   has_local_cache: bool,
	 *   js_url: string|null
	 * }
	 */
	public static function build( array $renderer_output, bool $has_local_cache ): array {
		$assets    = isset( $renderer_output['assets'] ) && is_array( $renderer_output['assets'] )
			? $renderer_output['assets']
			: array();
		$base_css  = isset( $assets['base_css'] ) && is_array( $assets['base_css'] ) ? $assets['base_css'] : null;
		$layout_css = isset( $assets['layout_css'] ) && is_array( $assets['layout_css'] ) ? $assets['layout_css'] : null;
		$layout_js  = isset( $assets['layout_js'] ) && is_array( $assets['layout_js'] ) ? $assets['layout_js'] : null;

		return array(
			'html'           => isset( $renderer_output['html'] ) ? (string) $renderer_output['html'] : '',
			'css_url'        => null !== $base_css && isset( $base_css['src'] ) ? (string) $base_css['src'] : '',
			'layout_css_url' => null !== $layout_css && isset( $layout_css['src'] ) ? (string) $layout_css['src'] : '',
			'has_local_cache' => $has_local_cache,
			'js_url'         => null !== $layout_js && isset( $layout_js['src'] ) ? (string) $layout_js['src'] : null,
		);
	}
}
