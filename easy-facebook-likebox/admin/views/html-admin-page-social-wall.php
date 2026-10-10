<?php
/**
 * Social Wall admin page mount.
 *
 * @package Easy_Social_Feed
 * @since   6.8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap esf-dsh-wrap esf-social-wall-wrap">
	<div id="esf-social-wall-app" class="esf-social-wall-app" role="main">
		<noscript>
			<div class="esf-social-wall-noscript">
				<?php esc_html_e( 'JavaScript is required to manage Social Wall.', 'easy-facebook-likebox' ); ?>
			</div>
		</noscript>
	</div>
</div>
