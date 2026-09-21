<?php
/**
 * Modern Facebook dashboard mount point.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook/Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap esf-dsh-wrap esf-facebook-admin">
	<?php // Anchor for WP core notice relocation (common.js). Without this, notices insert after the first React h2 mid-page. ?>
	<hr class="wp-header-end" />
	<div id="esf-facebook-dashboard">
		<p><?php esc_html_e( 'Loading Facebook dashboard…', 'easy-facebook-likebox' ); ?></p>
	</div>
</div>
