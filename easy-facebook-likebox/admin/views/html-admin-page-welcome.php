<?php
/**
 * Admin View: First-run setup wizard.
 *
 * Mounts the React wizard SPA on #esf-welcome-app. All UI, navigation and
 * persistence happen client-side; this template intentionally stays a thin
 * shell so theme/admin styles cannot leak into the wizard's flow.
 *
 * @package    Easy_Social_Feed
 * @subpackage Welcome
 * @since      6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap esf-welcome-wrap">
	<div id="esf-welcome-app" class="esf-welcome-app" role="main">
		<noscript>
			<div class="esf-welcome-noscript">
				<p>
					<?php esc_html_e( 'The Easy Social Feed setup wizard requires JavaScript. Please enable JavaScript and reload this page.', 'easy-facebook-likebox' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=feed-them-all' ) ); ?>">
						<?php esc_html_e( 'Skip and go to the dashboard', 'easy-facebook-likebox' ); ?>
					</a>
				</p>
			</div>
		</noscript>
	</div>
</div>
