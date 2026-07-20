<?php
/**
 * Admin View: Page - Settings (global GDPR, Translation, etc.) with tabs.
 *
 * @since 6.8.0
 * @since 6.9.0 React mount for General, GDPR, and Translation tabs.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap esf-dsh-wrap esf-settings-wrap">
	<?php // Anchor for WP core notice relocation (common.js). ?>
	<hr class="wp-header-end" />
	<div id="esf-settings-app" class="esf-settings-app" role="main">
		<noscript>
			<div class="esf-settings-noscript">
				<p>
					<?php esc_html_e( 'The Easy Social Feed settings page requires JavaScript. Please enable JavaScript and reload this page.', 'easy-facebook-likebox' ); ?>
				</p>
			</div>
		</noscript>
	</div>
</div>
