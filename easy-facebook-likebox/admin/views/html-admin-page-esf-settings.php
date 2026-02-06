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

$allowed_tabs = array( 'gdpr', 'translation' );
$active_tab   = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'gdpr';
if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
	$active_tab = 'gdpr';
}

?>

<div class="fta_wrap_outer" style="width: 98%;">
	<div class="efbl_wrap z-depth-1">
		<div class="efbl_wrap_inner">
			<div class="efbl_tabs_holder">
				<div class="efbl_tabs_header">
					<ul id="efbl_tabs" class="tabs">
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
	if ( function_exists( 'efl_fs' ) && efl_fs()->is_free_plan() ) {
		$banner_info = $this->esf_upgrade_banner();
		$discount    = ! empty( $banner_info['discount'] ) ? $banner_info['discount'] : '';
		$coupon      = ! empty( $banner_info['coupon'] ) ? $banner_info['coupon'] : '';
		?>
		<div class="esf-settings-upgrade-notice" role="region" aria-label="<?php esc_attr_e( 'Upgrade to Pro', 'easy-facebook-likebox' ); ?>">
			<span class="esf-settings-upgrade-notice__icon dashicons dashicons-star-filled" aria-hidden="true"></span>
			<div class="esf-settings-upgrade-notice__content">
				<p class="esf-settings-upgrade-notice__title"><?php esc_html_e( 'You\'re on the free plan', 'easy-facebook-likebox' ); ?></p>
				<p class="esf-settings-upgrade-notice__text">
					<?php esc_html_e( 'Pro unlocks a lot more: visual moderation, Load More for unlimited posts, hashtag & event feeds, featured and shoppable posts, advanced popups, and priority support — with new features added regularly.', 'easy-facebook-likebox' ); ?>
					<?php
					if ( $discount || $coupon ) {
						echo ' ';
						if ( $discount && $coupon ) {
							/* translators: 1: discount (e.g. 17%), 2: coupon code */
							echo wp_kses_post( sprintf( __( 'Save %1$s with code <code>%2$s</code>.', 'easy-facebook-likebox' ), esc_html( $discount ), esc_html( $coupon ) ) );
						} elseif ( $discount ) {
							/* translators: %s: discount (e.g. 17%) */
							echo esc_html( sprintf( __( 'Save %s on Pro.', 'easy-facebook-likebox' ), $discount ) );
						} else {
							/* translators: %s: coupon code */
							echo wp_kses_post( sprintf( __( 'Use code <code>%s</code> for a discount.', 'easy-facebook-likebox' ), esc_html( $coupon ) ) );
						}
					}
					?>
				</p>
			</div>
			<a href="<?php echo esc_url( $banner_info['button-url'] ); ?>" class="esf-settings-upgrade-notice__button button button-primary" <?php echo ! empty( $banner_info['target'] ) ? 'target="' . esc_attr( $banner_info['target'] ) . '"' : ''; ?>>
				<?php echo esc_html( $banner_info['button-text'] ); ?>
			</a>
		</div>
		<?php
	}
	?>
</div>

<div class="esf-notification-holder"><?php esc_html_e( 'Copied', 'easy-facebook-likebox' ); ?></div>
</div>
