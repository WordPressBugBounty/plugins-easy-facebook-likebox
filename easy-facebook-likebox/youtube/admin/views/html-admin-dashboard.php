<?php
/**
 * YouTube Admin Dashboard Template
 *
 * Main dashboard view for YouTube module.
 * Serves as container for React application.
 *
 * @package Easy_Social_Feed
 * @subpackage YouTube/Admin/Views
 * @since 6.7.5
 *
 * @var array $stats Database statistics.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap esf-youtube-wrap">
	<div class="esf-youtube-dashboard">
		<!-- React Application Mount Point -->
		<div id="esf-youtube-dashboard-root" class="esf-youtube-dashboard__app">
			<!-- Fallback content if JavaScript is disabled or build not loaded -->
			<noscript>
				<div class="esf-youtube-empty-state">
					<div class="esf-youtube-empty-state__icon">
						<span class="dashicons dashicons-video-alt3"></span>
					</div>
					<h2 class="esf-youtube-empty-state__title">
						<?php echo esc_html__( 'JavaScript is disabled in your browser.', 'easy-facebook-likebox' ); ?>
					</h2>
					<p class="esf-youtube-empty-state__description">
						<?php echo esc_html__( 'Please enable JavaScript to manage your YouTube accounts from this dashboard.', 'easy-facebook-likebox' ); ?>
					</p>
				</div>
			</noscript>
		</div>
	</div>
</div>
</div>
<script>
( function () {
	// If this page is loaded inside the OAuth popup window, close it.
	// The parent window's polling mechanism will automatically fetch and display the connected account.
	if ( window.opener ) {
		window.close();
	}
}() );
</script>
