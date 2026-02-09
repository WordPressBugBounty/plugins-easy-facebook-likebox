<?php
/**
 * Admin View: Page - Easy Social Feed
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$FTA        = new Feed_Them_All();
$ESF_Admin  = new ESF_Admin();
$banner_info = $ESF_Admin->esf_upgrade_banner();

$fta_all_plugs = $FTA->fta_plugins();
$fta_settings  = $FTA->fta_get_settings();

?>
	<div class="fta_wrap_outer">
		<h1 class="esf-main-heading">
			<?php esc_html_e( 'Easy Social Feed (Previously Easy Facebook Likebox)', 'easy-facebook-likebox' ); ?>
		</h1>
		<div class="fta_wrap z-depth-1">
		<div class="fta_wrap_inner">
			<div class="fta_tabs_holder">
				<div class="fta_tabs_header">
					<div class="fta_sliders_wrap">
						<div id="fta_sliders">
			<span>
			    <div class="box"></div>
			</span>
			<span>
			    <div class="box"></div>
			</span>
			<span>
			  <div class="box"></div>
			</span>
						</div>

					</div>
				</div>
				<div class="fta_tab_c_holder">
						<h5>
							<?php esc_html_e( 'Welcome to the modules management page', 'easy-facebook-likebox' ); ?>
						</h5>
						<p>
							<?php esc_html_e( 'You can disable the module which you are not using. It will help us to include only required resources to make your site load faster', 'easy-facebook-likebox' ); ?>.
						</p>

						<div class="fta_all_plugs col s12">
							<?php
							$Feed_Them_All = new Feed_Them_All();
							$status        = $Feed_Them_All->module_status( 'facebook' );

							if ( $status === 'activated' ) {
								$btn = __( 'Deactivate', 'easy-facebook-likebox' );
							} else {
								$btn = __( 'Activate', 'easy-facebook-likebox' );
							}
							?>
							<div class="card col fta_single_plug s5 fta_plug_facebook   fta_plug_<?php esc_attr_e( $status ); ?>">

										<div class="card-content">
												<span class="card-title  grey-text text-darken-4">
													<?php esc_html_e( 'Custom Facebook Feed - Page Plugin (Likebox)' ); ?>
												</span>
										</div>
										<hr>
										<div class="fta_cta_holder">
											<p>
												<?php esc_html_e( 'This module allows you to display:', 'easy-facebook-likebox' ); ?>
											</p>
											<ul>
												<li>
													<?php esc_html_e( 'Customizable and mobile-friendly Facebook post, images, videos, events, and albums feed', 'easy-facebook-likebox' ); ?>
												</li>
												<li>
													<?php esc_html_e( 'Facebook Group feed', 'easy-facebook-likebox' ); ?>
												</li>
												<li>
													<?php esc_html_e( 'Facebook Page Plugin (previously like box)', 'easy-facebook-likebox' ); ?>
												</li>
												<li>
													<?php esc_html_e( 'using shortcode, widget, inside popup and widget.', 'easy-facebook-likebox' ); ?>
												</li>
											</ul>
											<a class="btn waves-effect fta_plug_activate waves-light"
											    data-status="<?php esc_attr_e( $status ); ?>"
											    data-plug="facebook"
											    href="#"><?php esc_attr_e( $btn ); ?></a>

												<a class="btn waves-effect fta_setting_btn right waves-light" href="<?php echo esc_url( admin_url( 'admin.php?page=easy-facebook-likebox' ) ); ?>">
													<?php esc_html_e( 'Settings', 'easy-facebook-likebox' ); ?>
												</a>
										</div>
									</div>
							<?php
							$Feed_Them_All = new Feed_Them_All();
							$status        = $Feed_Them_All->module_status( 'instagram' );

							if ( $status === 'activated' ) {
								$btn = __( 'Deactivate', 'easy-facebook-likebox' );
							} else {
								$btn = __( 'Activate', 'easy-facebook-likebox' );
							}
							?>
							<div class="card col fta_single_plug s5 fta_plug_instagram   fta_plug_<?php esc_attr_e( $status ); ?>">

								<div class="card-content">
												<span class="card-title  grey-text text-darken-4">
													<?php esc_html_e( 'Custom Instagram Feed' ); ?>
												</span>
								</div>
								<hr>
								<div class="fta_cta_holder">
									<p>
										<?php esc_html_e( 'This module allows you to display:', 'easy-facebook-likebox' ); ?>
									</p>
									<ul>
										<li>
											<?php esc_html_e( 'Display stunning photos from the Instagram account in the feed', 'easy-facebook-likebox' ); ?>
										</li>
										<li>
											<?php esc_html_e( 'Any Hashtag Feed', 'easy-facebook-likebox' ); ?>
										</li>
										<li>
											<?php esc_html_e( 'Gallery of photos in the PopUp', 'easy-facebook-likebox' ); ?>
										</li>
										<li>
											<?php esc_html_e( 'using shortcode, widget, inside popup and widget', 'easy-facebook-likebox' ); ?>
										</li>
									</ul>
									<a class="btn waves-effect fta_plug_activate waves-light"
									    data-status="<?php esc_attr_e( $status ); ?>"
									    data-plug="instagram"
									    href="#"><?php esc_attr_e( $btn ); ?></a>

									<a class="btn waves-effect fta_setting_btn right waves-light" href="<?php echo esc_url( admin_url( 'admin.php?page=mif' ) ); ?>">
										<?php esc_html_e( 'Settings', 'easy-facebook-likebox' ); ?>
									</a>
								</div>
							</div>

							</div>

				</div>
			</div>
		</div>
	</div>
	<?php require_once FTA_PLUGIN_DIR . 'admin/views/html-upgrade-notice.php'; ?>
	</div>
</div>
<div class="esf-notification-holder"><?php esc_html_e( 'Copied', 'easy-facebook-likebox' ); ?></div>