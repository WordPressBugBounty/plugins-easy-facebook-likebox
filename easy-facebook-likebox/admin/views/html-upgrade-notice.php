<?php
/**
 * Admin View: Upgrade notice banner (reused on settings, Facebook, Instagram, main ESF page).
 *
 * Expects $banner_info to be set (from ESF_Admin::esf_upgrade_banner()).
 * Shown only to free plan users.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $banner_info ) && class_exists( 'ESF_Admin' ) ) {
	$ESF_Admin   = new ESF_Admin();
	$banner_info = $ESF_Admin->esf_upgrade_banner();
}

if ( empty( $banner_info ) ) {
	return;
}

if ( function_exists( 'efl_fs' ) && efl_fs()->is_free_plan() ) {
	$discount = ! empty( $banner_info['discount'] ) ? $banner_info['discount'] : '';
	$coupon   = ! empty( $banner_info['coupon'] ) ? $banner_info['coupon'] : '';
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
						echo wp_kses_post( sprintf( __( '<strong>Save %1$s with code </strong><code>%2$s</code>.', 'easy-facebook-likebox' ), esc_html( $discount ), esc_html( $coupon ) ) );
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
