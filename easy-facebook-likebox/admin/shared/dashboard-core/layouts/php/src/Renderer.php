<?php
/**
 * Shared layout renderer for pre-mapped posts (frontend + admin preview).
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts;

use EasySocialFeed\Layouts\Contracts\Layout;
use EasySocialFeed\Layouts\Contracts\PostItem;
use EasySocialFeed\Layouts\ValueObjects\AccountSummary;
use EasySocialFeed\Layouts\ValueObjects\LayoutDefinition;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Renderer {

	/**
	 * Render a feed for a module.
	 *
	 * @param string              $module    Module slug.
	 * @param object              $feed      Feed record (id, name, settings).
	 * @param AccountSummary|null $account   Owning account.
	 * @param PostItem[]          $posts     Pre-mapped post items.
	 * @param array<string,mixed> $overrides Optional settings override for live preview.
	 *
	 * @return array{html:string, assets:array<string,mixed>, inline_css:string, layout_slug:string}
	 *         Empty html on failure.
	 */
	public function render( string $module, $feed, ?AccountSummary $account, array $posts, array $overrides = array() ): array {
		$module = sanitize_key( $module );

		if ( ! is_object( $feed ) ) {
			return $this->empty_response();
		}

		$settings = isset( $feed->settings ) && is_array( $feed->settings ) ? $feed->settings : array();
		if ( ! empty( $overrides ) ) {
			$settings = array_replace_recursive( $settings, $overrides );
		}
		$settings    = (array) apply_filters( 'esf_layouts_render_settings', $settings, $module, $feed, $overrides );
		$settings    = (array) apply_filters( 'esf_' . $module . '_layout_render_settings', $settings, $feed, $overrides );
		$feed->settings = $settings;

		$feed = apply_filters( 'esf_layouts_render_feed_object', $feed, $module, $settings );
		$feed = apply_filters( 'esf_' . $module . '_layout_render_feed_object', $feed, $settings );

		$posts = (array) apply_filters( 'esf_layouts_render_posts', $posts, $module, $feed, $account );
		$posts = (array) apply_filters( 'esf_' . $module . '_layout_render_posts', $posts, $feed, $account );

		$registry    = LayoutRegistry::for( $module );
		$layout_slug = isset( $settings['layout']['type'] ) ? (string) $settings['layout']['type'] : '';
		$layout_slug = (string) apply_filters( 'esf_layouts_render_layout_type', $layout_slug, $module, $feed, $posts, $account );
		$layout_slug = (string) apply_filters( 'esf_' . $module . '_layout_render_layout_type', $layout_slug, $feed, $posts, $account );

		$layout = $registry->make( $layout_slug, $feed, $posts, $account );
		if ( ! $layout instanceof Layout ) {
			return $this->empty_response();
		}

		$definition = $layout->get_definition();

		$wrapper_classes = array(
			'esf-' . $module . '-feed',
			'esf-' . $module . '-feed--' . $definition->get_slug(),
		);
		$wrapper_classes = (array) apply_filters( 'esf_layouts_render_wrapper_classes', $wrapper_classes, $module, $feed, $definition );
		$wrapper_classes = (array) apply_filters( 'esf_' . $module . '_layout_render_wrapper_classes', $wrapper_classes, $feed, $definition );
		$wrapper_classes = array_filter( array_map( 'sanitize_html_class', $wrapper_classes ) );
		if ( empty( $wrapper_classes ) ) {
			$wrapper_classes = array( 'esf-' . $module . '-feed', 'esf-' . $module . '-feed--' . $definition->get_slug() );
		}

		$wrapper_attrs = apply_filters( 'esf_layouts_render_wrapper_attributes', array(), $module, $feed, $definition );
		$wrapper_attrs = apply_filters( 'esf_' . $module . '_layout_render_wrapper_attributes', $wrapper_attrs, $feed, $definition );
		$wrapper_attr_html = $this->serialize_attributes( is_array( $wrapper_attrs ) ? $wrapper_attrs : array() );

		do_action( 'esf_layouts_before_feed_render', $module, $feed, $account, $definition, $posts );
		do_action( 'esf_' . $module . '_layout_before_feed_render', $feed, $account, $definition, $posts );

		$layout_html = $layout->render();
		$layout_html = (string) apply_filters( 'esf_layouts_render_layout_html', $layout_html, $module, $feed, $definition, $posts, $account );
		$layout_html = (string) apply_filters( 'esf_' . $module . '_layout_render_layout_html', $layout_html, $feed, $definition, $posts, $account );

		$feed_id        = isset( $feed->id ) ? (int) $feed->id : 0;
		$inline_css     = $this->extract_custom_css( $settings, $module );
		$inline_css_tag = '' !== $inline_css && $feed_id > 0
			? '<style id="esf-' . $module . '-feed-' . $feed_id . '-custom-css">' . $inline_css . '</style>'
			: '';

		// Add simple loading overlay for modern modules — frontend only (not admin/preview).
		$loader_html    = '';
		$is_preview     = isset( $overrides['media_context'] ) && 'preview' === $overrides['media_context'];
		$is_admin       = \is_admin();
		$is_rest        = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$is_customize   = ( function_exists( 'is_customize_preview' ) && is_customize_preview() );
		$has_admin_path = isset( $_SERVER['REQUEST_URI'] ) && 
		                  ( false !== strpos( $_SERVER['REQUEST_URI'], '/wp-admin/' ) || 
		                    false !== strpos( $_SERVER['REQUEST_URI'], 'page=esf-' ) ||
		                    false !== strpos( $_SERVER['REQUEST_URI'], 'tab=customize' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		
		// Only show loader on actual public frontend (not admin/REST/customize/admin-facing URLs)
		if ( ! $is_preview && ! $is_admin && ! $is_rest && ! $is_customize && ! $has_admin_path && in_array( $module, array( 'twitter', 'youtube', 'facebook', 'instagram' ), true ) && function_exists( 'esf_get_simple_loader_html' ) ) {
			$loader_html = esf_get_simple_loader_html( $module );
		}

		$html = sprintf(
			'<div id="esf-%1$s-feed-%2$d" class="%3$s"%4$s>%5$s%6$s</div>%7$s',
			esc_attr( $module ),
			$feed_id,
			esc_attr( implode( ' ', $wrapper_classes ) ),
			$wrapper_attr_html, // already escaped in serializer
			$loader_html, // simple loading overlay (hidden after content loads)
			$layout_html, // primitive output is pre-escaped
			$inline_css_tag // primitive output is pre-escaped
		);

		$html = (string) apply_filters( 'esf_layouts_render_html', $html, $module, $feed, $definition, $posts, $account );
		$html = (string) apply_filters( 'esf_' . $module . '_layout_render_html', $html, $feed, $definition, $posts, $account );

		do_action( 'esf_layouts_after_feed_render', $module, $feed, $account, $definition, $posts, $html );
		do_action( 'esf_' . $module . '_layout_after_feed_render', $feed, $account, $definition, $posts, $html );

		return array(
			'html'        => $html,
			'assets'      => $definition->get_assets()->to_array(),
			'inline_css'  => $inline_css,
			'layout_slug' => $definition->get_slug(),
		);
	}

	/**
	 * Enqueue every asset declared by a layout. Used by shortcode rendering.
	 *
	 * @param LayoutDefinition $definition Layout definition whose assets should load.
	 * @param string           $module     Optional module slug used to resolve cross-layout CSS deps.
	 */
	public function enqueue_assets( LayoutDefinition $definition, string $module = '' ): void {
		$assets = $definition->get_assets();

		$base_css = $assets->get_base_css();
		if ( null !== $base_css ) {
			wp_enqueue_style( $base_css['handle'], $base_css['src'], $base_css['deps'], $base_css['ver'] );
		}

		$layout_css = $assets->get_layout_css();
		if ( null !== $layout_css ) {
			$this->register_layout_style_dependencies( $layout_css, $module );
			wp_enqueue_style( $layout_css['handle'], $layout_css['src'], $layout_css['deps'], $layout_css['ver'] );
		}

		$layout_js = $assets->get_layout_js();
		if ( null !== $layout_js ) {
			$js_deps = isset( $layout_js['deps'] ) && is_array( $layout_js['deps'] ) ? $layout_js['deps'] : array();
			if ( in_array( 'esf-masonry-pack', $js_deps, true ) && function_exists( 'esf_register_masonry_pack_script' ) ) {
				esf_register_masonry_pack_script();
			}
			wp_enqueue_script(
				$layout_js['handle'],
				$layout_js['src'],
				$js_deps,
				$layout_js['ver'],
				$layout_js['in_footer']
			);
		}
	}

	/**
	 * Register layout CSS declared in the registry when another layout lists it as a dependency.
	 *
	 * @param array<string,mixed> $style  Layout CSS asset array.
	 * @param string              $module Module slug used to resolve sibling layout handles.
	 */
	private function register_layout_style_dependencies( array $style, string $module ): void {
		$deps = isset( $style['deps'] ) && is_array( $style['deps'] ) ? $style['deps'] : array();

		foreach ( $deps as $dep ) {
			$dep = sanitize_key( (string) $dep );
			if ( '' === $dep || wp_style_is( $dep, 'registered' ) || wp_style_is( $dep, 'enqueued' ) ) {
				continue;
			}

			$dep_style = LayoutRegistry::find_layout_css_by_handle( $dep, $module );
			if ( null === $dep_style ) {
				continue;
			}

			$this->register_layout_style_dependencies( $dep_style, $module );

			if ( ! wp_style_is( $dep, 'registered' ) ) {
				wp_register_style(
					$dep_style['handle'],
					$dep_style['src'],
					isset( $dep_style['deps'] ) && is_array( $dep_style['deps'] ) ? $dep_style['deps'] : array(),
					$dep_style['ver'] ?? false
				);
			}
		}
	}

	/**
	 * Serialize a key => value attribute map to a leading-space attribute string.
	 */
	private function serialize_attributes( array $attrs ): string {
		$out = '';
		foreach ( $attrs as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || null === $value || false === $value ) {
				continue;
			}
			$out .= ' ' . $key . '="' . esc_attr( (string) $value ) . '"';
		}
		return $out;
	}

	/**
	 * Pull and sanitize the user's custom CSS from the feed settings.
	 *
	 * @param array<string,mixed> $settings Feed settings.
	 * @param string              $module   Module slug.
	 * @return string
	 */
	private function extract_custom_css( array $settings, string $module = '' ): string {
		$style = isset( $settings['style'] ) && is_array( $settings['style'] ) ? $settings['style'] : array();
		$css   = isset( $style['custom_css'] ) ? trim( (string) $style['custom_css'] ) : '';

		if ( '' === $css ) {
			return '';
		}

		$css = preg_replace( '#</?style[^>]*>#i', '', $css );
		if ( ! is_string( $css ) || '' === trim( $css ) ) {
			return '';
		}

		/**
		 * Filter layout custom CSS before inline injection.
		 *
		 * @since 6.7.12
		 * @param string              $css      Sanitized CSS (style tags stripped).
		 * @param string              $module   Module slug (facebook, instagram, …).
		 * @param array<string,mixed> $settings Feed settings.
		 */
		$css = apply_filters( 'esf_layouts_custom_css', $css, $module, $settings );
		if ( '' !== $module ) {
			$css = apply_filters( "esf_{$module}_layouts_custom_css", $css, $settings );
		}

		return is_string( $css ) ? $css : '';
	}

	/**
	 * @return array{html:string, assets:array<string,mixed>, inline_css:string, layout_slug:string}
	 */
	private function empty_response(): array {
		return array(
			'html'        => '',
			'assets'      => array(
				'base_css'   => null,
				'layout_css' => null,
				'layout_js'  => null,
			),
			'inline_css'  => '',
			'layout_slug' => '',
		);
	}
}
