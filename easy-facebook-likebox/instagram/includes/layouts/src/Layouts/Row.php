<?php
/**
 * Instagram Row layout.
 *
 * Single horizontal row of square MediaTiles — suited to headers, footers, and
 * narrow sections. Free layout; shares tile/header/popup plumbing with Grid via
 * {@see AbstractTileLayout}.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram\Layouts\Layouts
 * @since 6.9.0
 */

namespace EasySocialFeed\Instagram\Layouts\Layouts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instagram Row layout class.
 *
 * @since 6.9.0
 */
final class Row extends AbstractTileLayout {

	/**
	 * Pro wave style: horizontal gap between circular tiles (px).
	 */
	private const WAVE_GAP = 12;

	/**
	 * Pro wave style: rounded-square corner radius (px), not a full circle.
	 */
	private const WAVE_TILE_RADIUS = 16;

	/**
	 * {@inheritdoc}
	 */
	protected function get_layout_settings_slug(): string {
		return 'row';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_shell_class(): string {
		return 'esf-instagram-feed__row';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_inner_class(): string {
		return 'esf-instagram-feed__row-inner esf-instagram-feed__tiles-inner';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_cell_class(): string {
		return 'esf-instagram-feed__row-cell esf-instagram-feed__tile-cell';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_tile_shadow_modifier_class(): string {
		return 'esf-instagram-feed__row--tile-shadow';
	}

	/**
	 * {@inheritdoc}
	 *
	 * Competitor-style flush band: zero gap by default.
	 */
	protected function get_default_gap(): int {
		return 0;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_default_tile_radius(): int {
		return 0;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_default_tile_shadow(): bool {
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function is_gap_free(): bool {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string,string>
	 */
	protected function get_shell_geometry_style_vars(): array {
		$cols_desktop = (int) $this->setting( 'layout.row.columns', 6 );
		$cols_tablet  = (int) $this->setting( 'layout.row.columns_tablet', 3 );
		$cols_mobile  = (int) $this->setting( 'layout.row.columns_mobile', 2 );

		$cols_desktop = max( 1, min( 6, $cols_desktop ) );
		$cols_tablet  = max( 1, min( 6, $cols_tablet ) );
		$cols_mobile  = max( 1, min( 6, $cols_mobile ) );

		$vars = array(
			'--esf-ig-cols-desktop' => (string) $cols_desktop,
			'--esf-ig-cols-tablet'  => (string) $cols_tablet,
			'--esf-ig-cols-mobile'  => (string) $cols_mobile,
		);

		if ( $this->is_row_wave_style_enabled() ) {
			$wave_offset = (int) $this->setting( 'layout.row.wave_offset', 16 );
			$wave_offset = max( 8, min( 48, $wave_offset ) );
			$vars['--esf-ig-wave-offset'] = $wave_offset . 'px';
		}

		return $vars;
	}

	/**
	 * Whether the Pro wave stagger style is active for this row feed.
	 *
	 * @since 6.9.0
	 * @return bool
	 */
	protected function is_row_wave_style_enabled(): bool {
		if ( ! function_exists( 'esf_instagram_has_instagram_plan' ) || ! esf_instagram_has_instagram_plan() ) {
			return false;
		}

		$row_style = (string) $this->setting( 'layout.row.row_style', 'standard' );

		return 'wave' === $row_style;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_extra_shell_modifier_classes(): string {
		if ( ! $this->is_row_wave_style_enabled() ) {
			return '';
		}

		return 'esf-instagram-feed__row--wave';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function build_tile_shell_attributes( int $gap, int $tile_radius, bool $tile_shadow ): array {
		if ( $this->is_row_wave_style_enabled() ) {
			$gap         = self::WAVE_GAP;
			$tile_radius = self::WAVE_TILE_RADIUS;
			$tile_shadow = true;
		}

		$shell = parent::build_tile_shell_attributes( $gap, $tile_radius, $tile_shadow );

		return $shell;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function build_tile_options(): array {
		$options = parent::build_tile_options();

		if ( ! $this->is_row_wave_style_enabled() ) {
			return $options;
		}

		$options['show_overlay']        = false;
		$options['show_hover_plus']     = false;
		$options['show_hover_likes']    = false;
		$options['show_hover_comments'] = false;

		return $options;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_default_hover_plus_size(): int {
		return 28;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_default_hover_stats_size(): int {
		return 11;
	}
}
