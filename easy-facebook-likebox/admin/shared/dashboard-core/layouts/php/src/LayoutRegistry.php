<?php
/**
 * Per-module layout registry.
 *
 * Modules grab their registry via `LayoutRegistry::for( 'instagram' )` and
 * `register( $definition )` each layout they ship. The registry is the only
 * place that knows about plan-gating, fallbacks and JS-shape projection.
 *
 * Static state is per-request; `reset()` is exposed for tests.
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

final class LayoutRegistry {

	/**
	 * Per-module registry instances.
	 *
	 * @var array<string,LayoutRegistry>
	 */
	private static $instances = array();

	/**
	 * Module slug this registry belongs to.
	 *
	 * @var string
	 */
	private $module;

	/**
	 * Registered layouts keyed by slug, in insertion order.
	 *
	 * @var array<string,LayoutDefinition>
	 */
	private $layouts = array();

	private function __construct( string $module ) {
		$this->module = sanitize_key( $module );
	}

	/**
	 * Get (or create) the registry for a module.
	 */
	public static function for( string $module ): self {
		$module = sanitize_key( $module );
		if ( ! isset( self::$instances[ $module ] ) ) {
			self::$instances[ $module ] = new self( $module );
		}
		return self::$instances[ $module ];
	}

	/**
	 * Reset all module registries (test-only).
	 */
	public static function reset_all(): void {
		self::$instances = array();
	}

	public function get_module(): string {
		return $this->module;
	}

	/**
	 * Register a layout. Re-registering an existing slug overwrites the prior entry
	 * so Pro can extend a free layout if desired.
	 */
	public function register( LayoutDefinition $definition ): self {
		$slug = $definition->get_slug();
		if ( '' === $slug ) {
			return $this;
		}
		$this->layouts[ $slug ] = $definition;
		return $this;
	}

	public function unregister( string $slug ): self {
		unset( $this->layouts[ $slug ] );
		return $this;
	}

	public function has( string $slug ): bool {
		return isset( $this->layouts[ $slug ] );
	}

	/**
	 * Find a definition by slug or null when missing.
	 */
	public function get( string $slug ): ?LayoutDefinition {
		return $this->layouts[ $slug ] ?? null;
	}

	/**
	 * All registered definitions (including locked ones for JS rendering).
	 *
	 * @return LayoutDefinition[]
	 */
	public function get_definitions(): array {
		return array_values( $this->layouts );
	}

	/**
	 * Definitions the current site can actually use (plan check passes).
	 *
	 * @return LayoutDefinition[]
	 */
	public function get_available(): array {
		return array_values(
			array_filter(
				$this->layouts,
				static function ( LayoutDefinition $def ) {
					return $def->is_available();
				}
			)
		);
	}

	/**
	 * Slug of the first available (non-Pro-locked) layout. Used as fallback.
	 */
	public function get_fallback_slug(): ?string {
		$available = $this->get_available();
		if ( ! empty( $available ) ) {
			return $available[0]->get_slug();
		}
		$all = $this->get_definitions();
		return empty( $all ) ? null : $all[0]->get_slug();
	}

	/**
	 * Build a JS-friendly array of every registered layout (locked entries
	 * include `available: false` so the dashboard can show locked-state).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function to_array(): array {
		return array_map(
			static function ( LayoutDefinition $def ) {
				return $def->to_array();
			},
			$this->get_definitions()
		);
	}

	/**
	 * Default settings for the layout slug. Falls back to the registry's
	 * fallback layout's defaults so dashboards can always render something
	 * sensible after a plan downgrade.
	 *
	 * @return array<string,mixed>
	 */
	public function get_default_settings_for( string $slug ): array {
		$definition = $this->get( $slug );
		if ( null === $definition || ! $definition->is_available() ) {
			$fallback = $this->get_fallback_slug();
			$definition = null !== $fallback ? $this->get( $fallback ) : null;
		}
		return null === $definition ? array() : $definition->get_default_settings();
	}

	/**
	 * Instantiate a Layout class from its slug, with feed + posts + account.
	 *
	 * Falls back to the first available layout when the requested slug is
	 * missing or unavailable.
	 *
	 * @param string              $slug    Requested layout slug.
	 * @param object              $feed    Feed record.
	 * @param PostItem[]          $posts   Pre-mapped posts.
	 * @param AccountSummary|null $account Account summary.
	 */
	public function make( string $slug, $feed, array $posts, ?AccountSummary $account = null ): ?Layout {
		$definition = $this->get( $slug );
		if ( null === $definition || ! $definition->is_available() ) {
			$fallback   = $this->get_fallback_slug();
			$definition = null !== $fallback ? $this->get( $fallback ) : null;
		}

		if ( null === $definition ) {
			return null;
		}

		$class = $definition->get_class_name();
		if ( '' === $class || ! class_exists( $class ) ) {
			return null;
		}

		$instance = new $class( $this->module, $definition, $feed, $posts, $account );
		return $instance instanceof Layout ? $instance : null;
	}
}
