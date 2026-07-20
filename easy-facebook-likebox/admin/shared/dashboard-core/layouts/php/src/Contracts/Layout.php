<?php
/**
 * Layout contract.
 *
 * Every registered layout class must implement this so the shared Renderer
 * can drive it without knowing about modules.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Contracts
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\Contracts;

use EasySocialFeed\Layouts\ValueObjects\LayoutDefinition;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Layout {

	/**
	 * Render the layout output as a single HTML string.
	 */
	public function render(): string;

	/**
	 * Definition this layout was instantiated from. Used by the Renderer to
	 * resolve assets, supports and plan info.
	 */
	public function get_definition(): LayoutDefinition;
}
