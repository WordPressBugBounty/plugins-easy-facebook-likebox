<?php
/**
 * LayoutAssets value object.
 *
 * Describes the CSS/JS that a layout owns so the Renderer can enqueue the
 * exact set required (frontend) or return their URLs (admin preview).
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\ValueObjects
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\ValueObjects;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LayoutAssets {

	/**
	 * Base feed stylesheet (applies to every layout in this module).
	 *
	 * @var array{handle:string,src:string,deps:string[],ver:string|null}|null
	 */
	private $base_css;

	/**
	 * Layout-specific stylesheet.
	 *
	 * @var array{handle:string,src:string,deps:string[],ver:string|null}|null
	 */
	private $layout_css;

	/**
	 * Optional layout-specific script.
	 *
	 * @var array{handle:string,src:string,deps:string[],ver:string|null,in_footer:bool}|null
	 */
	private $layout_js;

	/**
	 * Build a value object from a definition array.
	 *
	 * Shape:
	 * - base_css   => [ handle, src, deps[], ver ]
	 * - layout_css => [ handle, src, deps[], ver ]
	 * - layout_js  => [ handle, src, deps[], ver, in_footer ]
	 *
	 * @param array<string,mixed> $assets Raw asset definition.
	 */
	public function __construct( array $assets = array() ) {
		$this->base_css   = $this->normalize_style( isset( $assets['base_css'] ) ? $assets['base_css'] : null );
		$this->layout_css = $this->normalize_style( isset( $assets['layout_css'] ) ? $assets['layout_css'] : null );
		$this->layout_js  = $this->normalize_script( isset( $assets['layout_js'] ) ? $assets['layout_js'] : null );
	}

	/**
	 * Base CSS tuple or null.
	 *
	 * @return array{handle:string,src:string,deps:string[],ver:string|null}|null
	 */
	public function get_base_css() {
		return $this->base_css;
	}

	/**
	 * Layout CSS tuple or null.
	 *
	 * @return array{handle:string,src:string,deps:string[],ver:string|null}|null
	 */
	public function get_layout_css() {
		return $this->layout_css;
	}

	/**
	 * Layout JS tuple or null.
	 *
	 * @return array{handle:string,src:string,deps:string[],ver:string|null,in_footer:bool}|null
	 */
	public function get_layout_js() {
		return $this->layout_js;
	}

	/**
	 * Plain-array projection for serialization (JS localization / REST preview).
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'base_css'   => $this->base_css,
			'layout_css' => $this->layout_css,
			'layout_js'  => $this->layout_js,
		);
	}

	/**
	 * Normalize a style tuple.
	 *
	 * @param mixed $style Raw style definition.
	 *
	 * @return array{handle:string,src:string,deps:string[],ver:string|null}|null
	 */
	private function normalize_style( $style ) {
		if ( ! is_array( $style ) ) {
			return null;
		}

		$handle = isset( $style['handle'] ) ? (string) $style['handle'] : '';
		$src    = isset( $style['src'] ) ? (string) $style['src'] : '';
		if ( '' === $handle || '' === $src ) {
			return null;
		}

		$deps = isset( $style['deps'] ) && is_array( $style['deps'] )
			? array_values( array_map( 'strval', $style['deps'] ) )
			: array();
		$ver  = isset( $style['ver'] ) && '' !== $style['ver'] ? (string) $style['ver'] : null;

		return array(
			'handle' => $handle,
			'src'    => $src,
			'deps'   => $deps,
			'ver'    => $ver,
		);
	}

	/**
	 * Normalize a script tuple.
	 *
	 * @param mixed $script Raw script definition.
	 *
	 * @return array{handle:string,src:string,deps:string[],ver:string|null,in_footer:bool}|null
	 */
	private function normalize_script( $script ) {
		if ( ! is_array( $script ) ) {
			return null;
		}

		$handle = isset( $script['handle'] ) ? (string) $script['handle'] : '';
		$src    = isset( $script['src'] ) ? (string) $script['src'] : '';
		if ( '' === $handle || '' === $src ) {
			return null;
		}

		$deps      = isset( $script['deps'] ) && is_array( $script['deps'] )
			? array_values( array_map( 'strval', $script['deps'] ) )
			: array();
		$ver       = isset( $script['ver'] ) && '' !== $script['ver'] ? (string) $script['ver'] : null;
		$in_footer = isset( $script['in_footer'] ) ? (bool) $script['in_footer'] : true;

		return array(
			'handle'    => $handle,
			'src'       => $src,
			'deps'      => $deps,
			'ver'       => $ver,
			'in_footer' => $in_footer,
		);
	}
}
