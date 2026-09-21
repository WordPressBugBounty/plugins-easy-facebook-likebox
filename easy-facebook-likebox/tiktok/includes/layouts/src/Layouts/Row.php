<?php

/**
 * TikTok Row layout (Free; wave stagger is Pro).
 *
 * Single horizontal band of equal tiles (flush by default). Pro wave style:
 * alternating up/down stagger with animation. Follows the same pattern as
 * LinkedIn/Instagram Row layouts on the shared AbstractTileLayout framework.
 *
 * @package Easy_Social_Feed
 * @subpackage TikTok\Layouts\Layouts
 * @since 6.17.0
 */
namespace EasySocialFeed\TikTok\Layouts\Layouts;

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * TikTok Row layout class.
 *
 * @since 6.17.0
 */
final class Row extends AbstractTileLayout {
    /**
     * Pro wave style: horizontal gap between tiles (px).
     */
    private const WAVE_GAP = 12;

    /**
     * Pro wave style: rounded-square corner radius (px).
     */
    private const WAVE_TILE_RADIUS = 16;

    /**
     * {@inheritdoc}
     */
    protected function get_layout_settings_slug() : string {
        return 'row';
    }

    /**
     * {@inheritdoc}
     */
    protected function get_shell_class() : string {
        $class = 'esf-tiktok-feed__row';
        if ( method_exists( $this, 'get_row_wave_shell_modifier__premium_only' ) ) {
            $wave_class = $this->get_row_wave_shell_modifier__premium_only();
            if ( '' !== $wave_class ) {
                $class .= ' ' . $wave_class;
            }
        }
        return $class;
    }

    /**
     * {@inheritdoc}
     */
    protected function get_inner_class() : string {
        return 'esf-tiktok-feed__row-inner esf-tiktok-feed__tiles-inner';
    }

    /**
     * {@inheritdoc}
     */
    protected function get_cell_class() : string {
        return 'esf-tiktok-feed__row-cell esf-tiktok-feed__tile-cell';
    }

    /**
     * {@inheritdoc}
     */
    protected function get_tile_shadow_modifier_class() : string {
        return 'esf-tiktok-feed__row--tile-shadow';
    }

    /**
     * {@inheritdoc}
     *
     * Flush band: zero gap by default.
     */
    protected function get_default_gap() : int {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    protected function get_default_tile_radius() : int {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    protected function get_default_tile_shadow() : bool {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * Gap is free to customize on Row (same as Instagram/LinkedIn).
     */
    protected function is_gap_free() : bool {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string,string>
     */
    protected function get_shell_geometry_style_vars() : array {
        $cols_desktop = (int) $this->setting( 'layout.row.columns', 6 );
        $cols_tablet = (int) $this->setting( 'layout.row.columns_tablet', 3 );
        $cols_mobile = (int) $this->setting( 'layout.row.columns_mobile', 2 );
        $cols_desktop = max( 1, min( 6, $cols_desktop ) );
        $cols_tablet = max( 1, min( 6, $cols_tablet ) );
        $cols_mobile = max( 1, min( 6, $cols_mobile ) );
        $gap = (int) $this->setting( 'layout.row.gap', $this->get_default_gap() );
        $gap = max( 0, min( 100, $gap ) );
        $vars = array(
            '--esf-tiktok-row-cols-desktop' => (string) $cols_desktop,
            '--esf-tiktok-row-cols-tablet'  => (string) $cols_tablet,
            '--esf-tiktok-row-cols-mobile'  => (string) $cols_mobile,
            '--esf-tiktok-row-gap'          => $gap . 'px',
        );
        if ( method_exists( $this, 'apply_row_wave_geometry_vars__premium_only' ) ) {
            $vars = $this->apply_row_wave_geometry_vars__premium_only( $vars );
        }
        $shadow_color = $this->sanitize_tile_shadow_color( (string) $this->setting( 'layout.row.tile_shadow_color', 'rgba(0,0,0,0.12)' ) );
        if ( '' !== $shadow_color ) {
            $vars['--esf-tiktok-tile-shadow-color'] = $shadow_color;
        }
        $scope = $this->setting( 'layout.row', array() );
        if ( !is_array( $scope ) ) {
            $scope = array();
        }
        if ( function_exists( 'esf_layout_dimension_style_vars' ) ) {
            $vars = array_merge( $vars, esf_layout_dimension_style_vars( $scope, '--esf-tiktok' ) );
        }
        return $vars;
    }

    /**
     * {@inheritdoc}
     *
     * Wave always uses 1:1. Standard may use aspect-ratio options.
     *
     * @since 6.17.0
     * @return string
     */
    protected function resolve_tile_media_aspect_ratio() : string {
        if ( method_exists( $this, 'resolve_row_wave_media_aspect_ratio__premium_only' ) ) {
            $wave_ratio = $this->resolve_row_wave_media_aspect_ratio__premium_only();
            if ( '' !== $wave_ratio ) {
                return $wave_ratio;
            }
        }
        $raw = (string) $this->setting( 'layout.row.media_aspect_ratio', '1:1' );
        if ( in_array( $raw, array(
            '1:1',
            '4:5',
            '3:4',
            '9:16'
        ), true ) ) {
            return $raw;
        }
        return '1:1';
    }

    /**
     * {@inheritdoc}
     */
    protected function get_default_hover_plus_size() : int {
        return 28;
    }

    /**
     * {@inheritdoc}
     *
     * Wave forces gap, radius, and shadow so the stagger has breathing room.
     */
    protected function build_tile_shell_attributes( int $gap, int $tile_radius, bool $tile_shadow ) : array {
        if ( method_exists( $this, 'apply_row_wave_shell_presets__premium_only' ) ) {
            $presets = $this->apply_row_wave_shell_presets__premium_only( $gap, $tile_radius, $tile_shadow );
            $gap = $presets['gap'];
            $tile_radius = $presets['tile_radius'];
            $tile_shadow = $presets['tile_shadow'];
        }
        return parent::build_tile_shell_attributes( $gap, $tile_radius, $tile_shadow );
    }

    /**
     * {@inheritdoc}
     *
     * Wave tiles skip hover chrome so the stagger stays readable.
     */
    protected function build_tile_options() : array {
        $options = parent::build_tile_options();
        if ( method_exists( $this, 'apply_row_wave_tile_options__premium_only' ) ) {
            $options = $this->apply_row_wave_tile_options__premium_only( $options );
        }
        return $options;
    }

}
