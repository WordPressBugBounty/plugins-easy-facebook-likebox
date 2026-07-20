<?php
/**
 * Instagram Grid layout.
 *
 * Multi-column tile grid. Free layout; popup/load-more/stories attach via
 * {@see AbstractTileLayout} traits.
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
 * Instagram Grid layout class.
 *
 * @since 6.9.0
 */
final class Grid extends AbstractTileLayout {

	/**
	 * {@inheritdoc}
	 */
	protected function get_layout_settings_slug(): string {
		return 'grid';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_shell_class(): string {
		return 'esf-instagram-feed__grid';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_inner_class(): string {
		return 'esf-instagram-feed__grid-inner esf-instagram-feed__tiles-inner';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_cell_class(): string {
		return 'esf-instagram-feed__grid-cell esf-instagram-feed__tile-cell';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_tile_shadow_modifier_class(): string {
		return 'esf-instagram-feed__grid--tile-shadow';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_default_gap(): int {
		return 8;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_default_tile_radius(): int {
		return 8;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_default_tile_shadow(): bool {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function is_gap_free(): bool {
		return false;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string,string>
	 */
	protected function get_shell_geometry_style_vars(): array {
		$cols_desktop = (int) $this->setting( 'layout.grid.columns', 3 );
		$cols_tablet  = (int) $this->setting( 'layout.grid.columns_tablet', max( 1, $cols_desktop - 1 ) );
		$cols_mobile  = (int) $this->setting( 'layout.grid.columns_mobile', 1 );

		$cols_desktop = max( 1, min( 6, $cols_desktop ) );
		$cols_tablet  = max( 1, min( 6, $cols_tablet ) );
		$cols_mobile  = max( 1, min( 6, $cols_mobile ) );

		return array(
			'--esf-ig-cols-desktop' => (string) $cols_desktop,
			'--esf-ig-cols-tablet'  => (string) $cols_tablet,
			'--esf-ig-cols-mobile'  => (string) $cols_mobile,
		);
	}
}
