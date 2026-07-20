<?php
/**
 * Instagram Modern Dashboard View
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/Admin
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap esf-dsh-wrap">
	<?php // Anchor for WP core notice relocation (common.js). Without this, notices insert after the first React h2 mid-page. ?>
	<hr class="wp-header-end" />
	<div id="esf-instagram-dashboard">
		<p><?php esc_html_e( 'Loading Instagram dashboard…', 'easy-facebook-likebox' ); ?></p>
	</div>
</div>
