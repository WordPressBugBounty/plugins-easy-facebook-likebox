<?php
/**
 * Admin View: Page - Settings (global GDPR, Translation, etc.) with tabs.
 *
 * Reuses Facebook admin tab markup (efbl_wrap, efbl_tabs_*) so existing CSS applies.
 *
 * @since 6.8.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fta_settings = ( new Feed_Them_All() )->fta_get_settings();

$allowed_tabs = array( 'general', 'gdpr', 'translation' );
$active_tab   = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';
if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
	$active_tab = 'general';
}

?>

<div class="fta_wrap_outer" style="width: 98%;">
	<div class="efbl_wrap z-depth-1">
		<div class="efbl_wrap_inner">
			<div class="efbl_tabs_holder">
				<div class="efbl_tabs_header">
					<ul id="efbl_tabs" class="tabs">
						<li class="tab col s3 <?php echo 'general' === $active_tab ? 'active' : ''; ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=esf-settings&tab=general' ) ); ?>">
								<span><?php esc_html_e( 'General', 'easy-facebook-likebox' ); ?></span>
							</a>
						</li>
						<li class="tab col s3 <?php echo 'gdpr' === $active_tab ? 'active' : ''; ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=esf-settings&tab=gdpr' ) ); ?>">
								<span><?php esc_html_e( 'GDPR', 'easy-facebook-likebox' ); ?></span>
							</a>
						</li>
						<li class="tab col s3 <?php echo 'translation' === $active_tab ? 'active' : ''; ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=esf-settings&tab=translation' ) ); ?>">
								<span><?php esc_html_e( 'Translation', 'easy-facebook-likebox' ); ?></span>
							</a>
						</li>
						<?php do_action( 'esf_settings_tab_links', $active_tab ); ?>
					</ul>
				</div>
			</div>
			<div class="efbl_tab_c_holder">
				<?php
				if ( 'general' === $active_tab ) {
					require_once FTA_PLUGIN_DIR . 'admin/views/html-settings-tab-general.php';
				}
				if ( 'gdpr' === $active_tab ) {
					require_once FTA_PLUGIN_DIR . 'admin/views/html-settings-tab-gdpr.php';
				}
				if ( 'translation' === $active_tab ) {
					require_once FTA_PLUGIN_DIR . 'admin/views/html-settings-tab-translation.php';
				}
				do_action( 'esf_settings_tab_content', $active_tab, $fta_settings );
				?>
			</div>
		</div>
	</div>

	<?php
	$banner_info = $this->esf_upgrade_banner();
	require_once FTA_PLUGIN_DIR . 'admin/views/html-upgrade-notice.php';
	?>
</div>

<div class="esf-notification-holder"><?php esc_html_e( 'Copied', 'easy-facebook-likebox' ); ?></div>
</div>
