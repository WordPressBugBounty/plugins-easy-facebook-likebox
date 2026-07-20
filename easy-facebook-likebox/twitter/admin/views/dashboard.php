<?php
/**
 * Twitter Dashboard Page Shell
 *
 * The React SPA mounts on #esf-twitter-dashboard.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/Admin
 * @since 6.7.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap esf-dsh-wrap">
	<?php // Anchor for WP core notice relocation (common.js). Without this, notices insert after the first React h2 mid-page. ?>
	<hr class="wp-header-end" />
	<div id="esf-twitter-dashboard"></div>
</div>
