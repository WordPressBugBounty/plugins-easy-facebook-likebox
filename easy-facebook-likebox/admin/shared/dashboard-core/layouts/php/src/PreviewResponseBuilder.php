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
	 *   js_url: string|null,
	 *   js_urls?: list<string>
	 * }
	 */
	public static function build( array $renderer_output, bool $has_local_cache ): array {
		$assets    = isset( $renderer_output['assets'] ) && is_array( $renderer_output['assets'] )
			? $renderer_output['assets']
			: array();
		$base_css  = isset( $assets['base_css'] ) && is_array( $assets['base_css'] ) ? $assets['base_css'] : null;
		$layout_css = isset( $assets['layout_css'] ) && is_array( $assets['layout_css'] ) ? $assets['layout_css'] : null;
		$layout_js  = isset( $assets['layout_js'] ) && is_array( $assets['layout_js'] ) ? $assets['layout_js'] : null;

		$js_url  = null !== $layout_js ? self::asset_url_with_ver( $layout_js ) : null;
		$js_urls = array();

		$deps = ( null !== $layout_js && isset( $layout_js['deps'] ) && is_array( $layout_js['deps'] ) )
			? $layout_js['deps']
			: array();
		if ( in_array( 'esf-masonry-pack', $deps, true ) && function_exists( 'esf_get_masonry_pack_script_url' ) ) {
			$pack_url = esf_get_masonry_pack_script_url();
			if ( '' !== $pack_url ) {
				$js_urls[] = $pack_url;
			}
		}
		if ( is_string( $js_url ) && '' !== $js_url ) {
			$js_urls[] = $js_url;
		}

		$payload = array(
			'html'            => isset( $renderer_output['html'] ) ? (string) $renderer_output['html'] : '',
			'css_url'         => self::asset_url_with_ver( $base_css ),
			'layout_css_url'  => self::asset_url_with_ver( $layout_css ),
			'has_local_cache' => $has_local_cache,
			'js_url'          => $js_url,
		);

		$layout_css_urls = array();
		if (
			function_exists( 'esf_is_feed_entrance_animation_enabled' )
			&& esf_is_feed_entrance_animation_enabled()
			&& function_exists( 'esf_get_feed_reveal_css_url' )
		) {
			$reveal_css = esf_get_feed_reveal_css_url();
			if ( '' !== $reveal_css ) {
				$layout_css_urls[] = $reveal_css;
			}
		}
		$layout_css_url = self::asset_url_with_ver( $layout_css );
		if ( '' !== $layout_css_url ) {
			$layout_css_urls[] = $layout_css_url;
		}
		if ( count( $layout_css_urls ) > 1 ) {
			$payload['layout_css_urls'] = $layout_css_urls;
		}

		if ( count( $js_urls ) > 1 || ( 1 === count( $js_urls ) && $js_urls[0] !== $js_url ) ) {
			$payload['js_urls'] = $js_urls;
		} elseif ( count( $js_urls ) > 0 ) {
			// Single layout script — keep js_url only; callers may still prefer js_urls.
			$payload['js_urls'] = $js_urls;
		}

		return $payload;
	}

	/**
	 * Append asset `ver` as a cache-busting query arg for dashboard preview CSS/JS.
	 *
	 * Preview injects raw URLs (not via wp_enqueue_*), so without `?ver=` the
	 * browser can keep serving a stale stylesheet after SCSS rebuilds.
	 *
	 * @param array<string,mixed>|null $asset Asset descriptor with src/ver.
	 * @return string Empty when src is missing.
	 */
	private static function asset_url_with_ver( ?array $asset ): string {
		if ( null === $asset || empty( $asset['src'] ) || ! is_string( $asset['src'] ) ) {
			return '';
		}
		$url = (string) $asset['src'];
		if ( isset( $asset['ver'] ) && '' !== (string) $asset['ver'] && function_exists( 'add_query_arg' ) ) {
			$url = (string) add_query_arg( 'ver', (string) $asset['ver'], $url );
		}
		return $url;
	}
}
