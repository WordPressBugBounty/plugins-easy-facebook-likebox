<?php
/**
 * Missing-feed placeholder primitive for deleted/invalid feed shortcodes.
 *
 * Renders a cache-safe public message for all visitors. Admin tooling is
 * injected client-side so full-page caches never leak privileged UI.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Primitives
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\Primitives;

use EasySocialFeed\Layouts\ValueObjects\MissingFeedContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Missing feed placeholder renderer.
 *
 * @since 6.9.0
 */
final class MissingFeedState {

	/**
	 * Default public message per module.
	 *
	 * @var array<string, string>
	 */
	private static $default_messages = array(
		'instagram' => 'This Instagram feed is currently unavailable.',
		'twitter'   => 'This social feed is currently unavailable.',
		'youtube'   => 'This YouTube feed is currently unavailable.',
	);

	/**
	 * Render the missing-feed placeholder HTML.
	 *
	 * @param MissingFeedContext $context Placeholder context.
	 * @return string Escaped HTML.
	 */
	public static function render( MissingFeedContext $context ): string {
		$module  = sanitize_key( $context->module );
		$feed_id = (int) $context->feed_id;
		if ( '' === $module || $feed_id <= 0 ) {
			return '';
		}

		$message = '' !== trim( $context->public_message )
			? $context->public_message
			: self::default_public_message( $module );

		$message = (string) apply_filters( 'esf_layouts_missing_feed_public_message', $message, $module, $feed_id, $context );
		$message = (string) apply_filters( 'esf_' . $module . '_missing_feed_public_message', $message, $feed_id, $context );

		$inner = sprintf(
			'<div class="esf-%1$s-feed__missing esf-%1$s-feed__empty"><p class="esf-%1$s-feed__missing-text esf-%1$s-feed__empty-text">%2$s</p></div>',
			esc_attr( $module ),
			esc_html( $message )
		);

		$inner = (string) apply_filters( 'esf_layouts_missing_feed_inner_html', $inner, $module, $feed_id, $context );
		$inner = (string) apply_filters( 'esf_' . $module . '_missing_feed_inner_html', $inner, $feed_id, $context );

		$html = sprintf(
			'<div id="%1$s" class="esf-%2$s-feed esf-%2$s-feed--missing"%3$s>%4$s</div>',
			esc_attr( $context->root_id ),
			esc_attr( $module ),
			self::serialize_data_attributes( $context ),
			$inner
		);

		$html = (string) apply_filters( 'esf_layouts_missing_feed_html', $html, $module, $feed_id, $context );
		$html = (string) apply_filters( 'esf_' . $module . '_missing_feed_html', $html, $feed_id, $context );

		if ( $context->can_manage && function_exists( 'esf_missing_feed_register' ) ) {
			esf_missing_feed_register(
				array(
					'module'            => $module,
					'feed_id'           => $feed_id,
					'shortcode_tag'     => $context->shortcode_tag,
					'post_id'           => (int) $context->post_id,
					'root_id'           => $context->root_id,
					'preview_rest_path' => $context->preview_rest_path,
					'feeds_rest_path'   => $context->feeds_rest_path,
					'dashboard_url'     => $context->dashboard_url,
				)
			);
		}

		return $html;
	}

	/**
	 * Resolve the default visitor-facing copy for a module.
	 *
	 * @param string $module Module slug.
	 * @return string
	 */
	public static function default_public_message( string $module ): string {
		$module = sanitize_key( $module );
		if ( isset( self::$default_messages[ $module ] ) ) {
			return __( self::$default_messages[ $module ], 'easy-facebook-likebox' );
		}

		return __( 'This social feed is currently unavailable.', 'easy-facebook-likebox' );
	}

	/**
	 * Build the data-attribute string used by the shared admin script.
	 *
	 * @param MissingFeedContext $context Placeholder context.
	 * @return string
	 */
	private static function serialize_data_attributes( MissingFeedContext $context ): string {
		$attrs = array(
			'data-esf-missing-feed'     => '1',
			'data-esf-module'           => $context->module,
			'data-esf-feed-id'          => (string) $context->feed_id,
			'data-esf-shortcode-tag'    => $context->shortcode_tag,
			'data-esf-post-id'          => (string) $context->post_id,
			'data-esf-preview-path'     => $context->preview_rest_path,
			'data-esf-feeds-path'       => $context->feeds_rest_path,
			'data-esf-dashboard-url'    => $context->dashboard_url,
		);

		$out = '';
		foreach ( $attrs as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || '' === (string) $value ) {
				continue;
			}
			$out .= ' ' . $key . '="' . esc_attr( (string) $value ) . '"';
		}

		return $out;
	}
}
