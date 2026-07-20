<?php
/**
 * LayoutDefinition value object.
 *
 * Single source of truth for a registered layout: slug, label, plan info,
 * supported feature flags, assets, default settings and the concrete class
 * that renders it.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\ValueObjects
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\ValueObjects;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LayoutDefinition {

	/**
	 * @var string
	 */
	private $slug;

	/**
	 * @var string
	 */
	private $label;

	/**
	 * @var string
	 */
	private $description;

	/**
	 * @var string
	 */
	private $icon;

	/**
	 * @var string
	 */
	private $class_name;

	/**
	 * @var bool
	 */
	private $is_pro;

	/**
	 * @var string|null
	 */
	private $requires_plan;

	/**
	 * Optional callable returning bool — true when the current site can use this layout.
	 *
	 * @var callable|null
	 */
	private $plan_check;

	/**
	 * Feature flags supported by this layout (header, columns, metrics, …).
	 *
	 * @var string[]
	 */
	private $supports;

	/**
	 * @var LayoutAssets
	 */
	private $assets;

	/**
	 * Default settings merged into the feed when this layout is selected.
	 *
	 * @var array<string,mixed>
	 */
	private $default_settings;

	/**
	 * Construct a definition from a config array.
	 *
	 * Required keys: slug, label, class_name.
	 *
	 * @param array<string,mixed> $config Config array.
	 */
	public function __construct( array $config ) {
		$this->slug             = isset( $config['slug'] ) ? (string) $config['slug'] : '';
		$this->label            = isset( $config['label'] ) ? (string) $config['label'] : $this->slug;
		$this->description      = isset( $config['description'] ) ? (string) $config['description'] : '';
		$this->icon             = isset( $config['icon'] ) ? (string) $config['icon'] : '';
		$this->class_name       = isset( $config['class_name'] ) ? (string) $config['class_name'] : '';
		$this->is_pro           = ! empty( $config['is_pro'] );
		$this->requires_plan    = isset( $config['requires_plan'] ) && '' !== $config['requires_plan']
			? (string) $config['requires_plan']
			: null;
		$this->plan_check       = isset( $config['plan_check'] ) && is_callable( $config['plan_check'] )
			? $config['plan_check']
			: null;
		$this->supports         = isset( $config['supports'] ) && is_array( $config['supports'] )
			? array_values( array_filter( array_map( 'strval', $config['supports'] ) ) )
			: array();
		$assets_config          = isset( $config['assets'] ) && is_array( $config['assets'] ) ? $config['assets'] : array();
		$this->assets           = new LayoutAssets( $assets_config );
		$this->default_settings = isset( $config['default_settings'] ) && is_array( $config['default_settings'] )
			? $config['default_settings']
			: array();
	}

	public function get_slug(): string {
		return $this->slug;
	}

	public function get_label(): string {
		return $this->label;
	}

	public function get_description(): string {
		return $this->description;
	}

	public function get_icon(): string {
		return $this->icon;
	}

	public function get_class_name(): string {
		return $this->class_name;
	}

	public function is_pro(): bool {
		return $this->is_pro;
	}

	/**
	 * Plan slug required to use this layout, or null when always available.
	 */
	public function get_required_plan(): ?string {
		return $this->requires_plan;
	}

	/**
	 * @return string[]
	 */
	public function get_supports(): array {
		return $this->supports;
	}

	public function supports( string $feature ): bool {
		return in_array( $feature, $this->supports, true );
	}

	public function get_assets(): LayoutAssets {
		return $this->assets;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_default_settings(): array {
		return $this->default_settings;
	}

	/**
	 * Whether the current user/site can use this layout.
	 *
	 * When no plan_check is registered the layout is treated as available.
	 */
	public function is_available(): bool {
		if ( null === $this->plan_check ) {
			return true;
		}
		return (bool) call_user_func( $this->plan_check, $this );
	}

	/**
	 * Plain-array projection suitable for JS localization.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'slug'             => $this->slug,
			'label'            => $this->label,
			'description'      => $this->description,
			'icon'             => $this->icon,
			'is_pro'           => $this->is_pro,
			'requires_plan'    => $this->requires_plan,
			'available'        => $this->is_available(),
			'supports'         => $this->supports,
			'assets'           => $this->assets->to_array(),
			'default_settings' => $this->default_settings,
		);
	}
}
