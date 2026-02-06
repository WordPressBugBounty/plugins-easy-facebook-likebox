<?php
if ( $efbl_bio_data ) {
	if ( isset( $fb_settings['approved_pages'] ) && ! empty( $fb_settings['approved_pages'] ) ) {
		$page_meta = $fb_settings['approved_pages'];
	} else {
		$page_meta = '';
	}
	do_action( 'efbl_before_feed_header', $page_meta );

	// Serve header/profile image locally when possible, same as feed/popup.
	if ( ! empty( $auth_img_src ) && isset( $efbl_bio_data->id ) ) {
		$local_header_img = esf_serve_media_locally( $efbl_bio_data->id, $auth_img_src, 'facebook' );
		if ( $local_header_img ) {
			$auth_img_src = $local_header_img;
		}
	}

	// GDPR: same as grid – placeholder + data-image-url when active, so JS can swap on consent.
	$header_img_src   = $auth_img_src;
	$header_img_class = '';
	$header_img_attr  = '';
	if ( ! empty( $auth_img_src ) && ! empty( $efbl_skin_values['design']['show_dp'] ) ) {
		// Skip GDPR placeholder when image is already served from local uploads.
		if ( isset( $gdpr_active ) && $gdpr_active && ! esf_is_local_media_url( $auth_img_src, 'facebook' ) ) {
			$header_img_src   = ESF_GDPR_Integrations::get_placeholder_image();
			$header_img_class = ' esf-no-consent';
			$header_img_attr  = ' data-image-url="' . esc_url( $auth_img_src ) . '"';
		}
	}
	?>

	<div class="efbl_header">
		<div class="efbl_header_inner_wrap">

			<?php if ( $auth_img_src && $efbl_skin_values['design']['show_dp'] ) : ?>

				<div class="efbl_header_img">
					<a href="https://www.facebook.com/<?php echo esc_attr( $efbl_bio_data->id ); ?>"
						target="_blank" rel="nofollow"><img alt="" src="<?php echo esc_url( $header_img_src ); ?>"
						class="<?php echo esc_attr( trim( $header_img_class ) ); ?>"<?php echo $header_img_attr; ?>/>
					</a>
				</div>

			<?php endif; ?>
			<div class="efbl_header_content">
				<div class="efbl_header_meta">
					<div class="efbl_header_title">
						<h4><?php echo esc_html( $efbl_bio_data->name ); ?></h4>
					</div>
					<?php if ( isset( $efbl_bio_data->verification_status ) && 'blue_verified' == $efbl_bio_data->verification_status ) : ?>

					<div class="efbl-verified-status">
						<i class="icon icon-esf-check"
							aria-hidden="true"></i>
					</div>

					<?php endif; ?>

					<?php if ( isset( $efbl_bio_data->category ) && $efbl_skin_values['design']['show_page_category'] ) : ?>

						<div class="efbl_cat"
								title="<?php esc_attr_e( 'Category', 'easy-facebook-likebox' ); ?>">
								<i class="icon icon-esf-tag"
									aria-hidden="true"></i><?php echo esc_html( $efbl_bio_data->category ); ?>
						</div>

						<?php
					endif;

					if ( isset( $efbl_bio_data->fan_count ) && $efbl_skin_values['design']['show_no_of_followers'] ) :
						?>

						<div class="efbl_followers"
								title="<?php esc_html_e( 'Followers', 'easy-facebook-likebox' ); ?>">
							<i class="icon icon-esf-user"
								aria-hidden="true"></i><?php echo esc_html( efbl_readable_count( $efbl_bio_data->fan_count ) ); ?>
						</div>

					<?php endif; ?>
				</div>
				<?php if ( isset( $efbl_bio_data->about ) && $efbl_skin_values['design']['show_bio'] ) : ?>

					<p class="efbl_bio"><?php echo esc_html( ecff_makeClickableLinks( $efbl_bio_data->about ) ); ?></p>

				<?php endif; ?>

				<?php if ( isset( $efbl_bio_data->description ) && $efbl_skin_values['design']['show_bio'] ) : ?>

					<p class="efbl_bio"><?php echo esc_html( ecff_makeClickableLinks( $efbl_bio_data->description ) ); ?></p>

				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php
	do_action( 'efbl_after_feed_header', $page_meta );
}
