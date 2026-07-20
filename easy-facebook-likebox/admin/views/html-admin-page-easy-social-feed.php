<?php
/**
 * Admin View: Page - Easy Social Feed module hub.
 *
 * @package Easy_Social_Feed
 * @since   6.9.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap esf-dsh-wrap esf-hub-wrap">
	<?php // Anchor for WP core notice relocation (common.js). ?>
	<hr class="wp-header-end" />
	<div id="esf-hub-app" class="esf-hub-app" role="main">
		<noscript>
			<div class="esf-hub-noscript">
				<p>
					<?php esc_html_e( 'The Easy Social Feed modules page requires JavaScript. Please enable JavaScript and reload this page.', 'easy-facebook-likebox' ); ?>
				</p>
			</div>
		</noscript>
	</div>
</div>
