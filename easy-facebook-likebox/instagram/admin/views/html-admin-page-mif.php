<?php
/**
 * Admin View: Page - Instagram
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$FTA = new Feed_Them_All();

$ESF_Admin = new ESF_Admin();

$banner_info = $ESF_Admin->esf_upgrade_banner();

$fta_settings = $FTA->fta_get_settings();

$app_ID = array( '468599428373231' );

$rand_app_ID = array_rand( $app_ID, 1 );

$u_app_ID = $app_ID[ $rand_app_ID ];

$auth_url = esc_url(
	add_query_arg(
		array(
			'client_id'    => $u_app_ID,
			'redirect_uri' => 'https://maltathemes.com/efbl/app-' . $u_app_ID . '/index.php',
			'scope'        => 'pages_show_list,pages_read_engagement,pages_read_user_content,instagram_basic,instagram_manage_insights,business_management',
			'state'        => admin_url( 'admin.php?page=mif' ),
		),
		'https://www.facebook.com/dialog/oauth'
	)
);

$mif_personal_clients = array( '1097984338516189' );

$mif_personal_app_ID = $mif_personal_clients[ array_rand( $mif_personal_clients, '1' ) ];

$personal_auth_url = esc_url(
	add_query_arg(
		array(
			'client_id'     => $mif_personal_app_ID,
			'redirect_uri'  => 'https://easysocialfeed.com/apps/meta/' . $mif_personal_app_ID . '/index.php',
			'scope'         => 'instagram_business_basic',
			'response_type' => 'code',
			'state'         => admin_url( 'admin.php?page=mif' ),
		),
		'https://api.instagram.com/oauth/authorize'
	)
);

if ( isset( $_GET['tab'] ) ) {
	$active_tab = sanitize_text_field( $_GET['tab'] );
} else {
	$active_tab = 'mif-general';
}

if ( efl_fs()->is_free_plan() || efl_fs()->is_plan( 'facebook_premium', true ) ) {
	$is_free = true;
} else {
	$is_free = false;
}
?>
	<div class="fta_wrap_outer">
		<div class="mif_wrap z-depth-1">
		<div class="mif_wrap_inner">
			<div class="mif_tabs_holder">
				<div class="mif_tabs_header">
					<ul id="mif_tabs" class="tabs">
						<li class="tab <?php echo $active_tab == 'mif-general' ? 'active' : ''; ?>">
							<a class="mif-general" href="<?php echo esc_url( admin_url( 'admin.php?page=mif&tab=mif-general' ) ); ?>">
								<span><?php esc_html_e( '1', 'easy-facebook-likebox' ); ?>. <?php esc_html_e( 'Authenticate', 'easy-facebook-likebox' ); ?></span>
							</a></li>

						<li class="tab <?php echo $active_tab == 'mif-shortcode' ? 'active' : ''; ?>"><a
									class=" mif_for_disable mif-shortcode"
									href="<?php echo esc_url( admin_url( 'admin.php?page=mif&tab=mif-shortcode' ) ); ?>">
								<span><?php esc_html_e( '2', 'easy-facebook-likebox' ); ?>. <?php esc_html_e( 'Use', 'easy-facebook-likebox' ); ?></span>
							</a>
						</li>

						<li class="tab <?php echo $active_tab == 'mif-skins' ? 'active' : ''; ?>">
							<a class="mif_for_disable mif-skins"
									href="<?php echo esc_url( admin_url( 'admin.php?page=mif&tab=mif-skins' ) ); ?>">
								<span><?php esc_html_e( '3', 'easy-facebook-likebox' ); ?>. <?php esc_html_e( 'Customize (skins)', 'easy-facebook-likebox' ); ?></span>
							</a>
						</li>

						<li class="tab <?php echo $active_tab == 'mif-moderate' ? 'active' : ''; ?>">
							<a class=" mif_for_disable mif-moderate"
									href="<?php echo esc_url( admin_url( 'admin.php?page=mif&tab=mif-moderate' ) ); ?>">
								<span>
								<?php
								esc_html_e( 'Moderate', 'easy-facebook-likebox' );
								if ( efl_fs()->is_free_plan() || efl_fs()->is_plan( 'facebook_premium', true ) ) {
									?>
										(<?php esc_html_e( 'Pro', 'easy-facebook-likebox' ); ?>)
									<?php } ?>
								</span>
							</a>
						</li>

						<li class="tab <?php echo $active_tab == 'mif-shoppable' ? 'active' : ''; ?>">
							<a class="mif_for_disable mif-shoppable"
								href="<?php echo esc_url( admin_url( 'admin.php?page=mif&tab=mif-shoppable' ) ); ?>">
								<span>
								<?php
								esc_html_e( 'Shoppable', 'easy-facebook-likebox' );
								if ( efl_fs()->is_free_plan() || efl_fs()->is_plan( 'facebook_premium', true ) ) {
									?>
									(<?php esc_html_e( 'Pro', 'easy-facebook-likebox' ); ?>)
								<?php } ?>
								</span>
							</a>
						</li>

						<?php do_action( 'esf_insta_admin_tab', $fta_settings ); ?>

						<li class="tab <?php echo $active_tab == 'mif-cache' ? 'active' : ''; ?>"><a
									class=" mif_for_disable mif-cache"
									href="<?php echo esc_url( admin_url( 'admin.php?page=mif&tab=mif-cache' ) ); ?>">
								<span><?php esc_html_e( 'Clear Cache', 'easy-facebook-likebox' ); ?></span>
							</a>
						</li>

					</ul>
					<div class="mif_tabs_right">
						<?php if ( $fta_settings['plugins']['facebook']['status'] && 'activated' == $fta_settings['plugins']['facebook']['status'] ) { ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=easy-facebook-likebox' ) ); ?>"><?php esc_html_e( 'Facebook', 'easy-facebook-likebox' ); ?></a>
						<?php } ?>
					</div>
				</div>
				<?php do_action( 'esf_insta_admin_after_tabs', $fta_settings ); ?>
				<div class="mif_tab_c_holder">
					<?php

					if ( 'mif-general' == $active_tab ) {
						require_once ESF_INSTA_PLUGIN_DIR . 'admin/views/html-autenticate-tab.php';
					}

					if ( 'mif-shortcode' == $active_tab ) {
						require_once ESF_INSTA_PLUGIN_DIR . 'admin/views/html-how-to-use-tab.php';
					}

					if ( 'mif-skins' == $active_tab ) {
						require_once ESF_INSTA_PLUGIN_DIR . 'admin/views/html-skins-tab.php';
					}

					if ( 'mif-moderate' == $active_tab ) {
						require_once ESF_INSTA_PLUGIN_DIR . 'admin/views/html-moderate-tab.php';
					}

					if ( 'mif-shoppable' == $active_tab ) {
						require_once ESF_INSTA_PLUGIN_DIR . 'admin/views/html-shoppable-tab.php';
					}

					if ( 'mif-cache' == $active_tab ) {
						require_once ESF_INSTA_PLUGIN_DIR . 'admin/views/html-clear-cache-tab.php';
					}

					do_action( 'esf_insta_admin_tab_content', $fta_settings );
					?>
				</div>
			</div>

		</div>

		<div id="fta-remove-at" class="esf-modal">
			<div class="modal-content">
				<div class="mif-modal-content"><span class="mif-lock-icon">
						<span class="dashicons dashicons-warning"></span>
					</span>
					<h5><?php esc_html_e( 'Are you sure?', 'easy-facebook-likebox' ); ?></h5>
					<p><?php esc_html_e( 'Do you really want to delete the access token? It will delete all the pages data, access tokens, and permissions given to the app.', 'easy-facebook-likebox' ); ?></p>
					<a class=" btn modal-close"
						href="javascript:void(0)"><?php esc_html_e( 'Cancel', 'easy-facebook-likebox' ); ?></a>
					<a class=" btn efbl_delete_at_confirmed modal-close"
						href="javascript:void(0)"><?php esc_html_e( 'Delete', 'easy-facebook-likebox' ); ?></a>
				</div>
			</div>

		</div>

		<div id="mif-remove-at" class="esf-modal">
			<div class="modal-content">
				<div class="mif-modal-content"><span class="mif-lock-icon"><span class="dashicons dashicons-warning"></span></span>
					<h5><?php esc_html_e( 'Are you sure?', 'easy-facebook-likebox' ); ?></h5>
					<p><?php esc_html_e( 'Do you really want to delete the access token? It will delete the access token saved in your website databse.', 'easy-facebook-likebox' ); ?></p>
					<a class=" btn modal-close"
						href="javascript:void(0)"><?php esc_html_e( 'Cancel', 'easy-facebook-likebox' ); ?></a>
					<a class=" btn mif_delete_at_confirmed"
						href="#"><?php esc_html_e( 'Delete', 'easy-facebook-likebox' ); ?></a>
					<div class="mif-revoke-access-steps">
						<p><?php esc_html_e( 'If you want to disconnect plugin app also follow the steps below:', 'easy-facebook-likebox' ); ?></p>
						<ol>
							<li><?php esc_html_e( 'Go to ', 'easy-facebook-likebox' ); ?>
								<a target="_blank"
									href="<?php echo esc_url( 'https://www.instagram.com/' ); ?>">instagram.com</a> <?php esc_html_e( 'Log in with your username and password', 'easy-facebook-likebox' ); ?>
							</li>
							<li><?php esc_html_e( 'Click on the user icon located on the top right of your screen.', 'easy-facebook-likebox' ); ?></li>
							<li><?php esc_html_e( 'Go in your Instagram Settings and select “Authorized Apps”', 'easy-facebook-likebox' ); ?></li>
							<li><?php esc_html_e( 'You will see a list of the apps & websites that are linked to your Instagram account. Click “Revoke Access” and “Yes” on the button below which you authenticated', 'easy-facebook-likebox' ); ?></li>
						</ol>
					</div>
				</div>
			</div>

		</div>


		<div id="fta-auth-error" class="esf-modal">
			<div class="modal-content">
				<div class="mif-modal-content"><span class="mif-lock-icon"><span class="dashicons dashicons-warning"></span> </span>
					<p><?php esc_html_e( 'Sorry, Plugin is unable to get the accounts data. Please delete the access token and select accounts in the second step of authentication to give the permission.', 'easy-facebook-likebox' ); ?></p>

					<a class=" efbl_authentication_btn btn"
						href="<?php echo esc_url( $auth_url ); ?>"><span class="dashicons dashicons-camera"></span><?php esc_html_e( 'Connect My Instagram Account', 'easy-facebook-likebox' ); ?>
					</a>
				</div>
			</div>

		</div>

		<div id="mif-free-masonry-upgrade" class="fta-upgrade-modal esf-modal fadeIn">
			<div class="modal-content">

				<div class="mif-modal-content"><span class="mif-lock-icon"><span class="dashicons dashicons-lock"></span></span>
					<h5><?php esc_html_e( 'Premium Feature', 'easy-facebook-likebox' ); ?></h5>
					<p><?php esc_html_e( "We're sorry, Masonry layout is not included in your plan. Please upgrade to premium version to unlock this and all other cool features.", 'easy-facebook-likebox' ); ?>
						<a target="_blank"
							href="<?php echo esc_url( 'https://easysocialfeed.com/my-instagram-feed-demo/masonary' ); ?>"><?php esc_html_e( 'Check out the demo', 'easy-facebook-likebox' ); ?></a>
					</p>
					<p><?php esc_html_e( 'Upgrade today and get ' . $banner_info['discount'] . ' discount! On the checkout click on "Have a promotional code?" and enter ', 'easy-facebook-likebox' ); ?>
						<?php if ( $banner_info['coupon'] ) { ?>
							<code><?php esc_html_e( $banner_info['coupon'] ); ?></code>
						<?php } ?>
					</p>
					<hr/>
					<a href="<?php echo esc_url( efl_fs()->get_upgrade_url() ); ?>"
						class=" btn"><span class="dashicons dashicons-unlock"></span><?php esc_html_e( 'Upgrade to pro', 'easy-facebook-likebox' ); ?>
					</a>

				</div>
			</div>

		</div>

		<?php if ( efl_fs()->is_free_plan() ) { ?>

			<div id="mif-free-carousel-upgrade" class="fta-upgrade-modal esf-modal fadeIn">
				<div class="modal-content">

					<div class="mif-modal-content"><span
								class="mif-lock-icon"><span class="dashicons dashicons-lock"></span></span>
						<h5><?php esc_html_e( 'Premium Feature', 'easy-facebook-likebox' ); ?></h5>
						<p><?php esc_html_e( "We're sorry, Carousel layout is not included in your plan. Please upgrade to premium version to unlock this and all other cool features.", 'easy-facebook-likebox' ); ?>
							<a target="_blank"
								href="<?php echo esc_url( 'https://easysocialfeed.com/my-instagram-feed-demo/carousel' ); ?>"><?php esc_html_e( 'Check out the demo', 'easy-facebook-likebox' ); ?></a>
						</p>
						<p><?php esc_html_e( 'Upgrade today and get ' . $banner_info['discount'] . ' discount! On the checkout click on "Have a promotional code?" and enter ', 'easy-facebook-likebox' ); ?>
							<?php if ( $banner_info['coupon'] ) { ?>
								<code><?php esc_html_e( $banner_info['coupon'] ); ?></code>
							<?php } ?>
						</p>
						<hr/>
						<a href="<?php echo esc_url( efl_fs()->get_upgrade_url() ); ?>"
							class=" btn"><span class="dashicons dashicons-unlock"></span><?php esc_html_e( 'Upgrade to pro', 'easy-facebook-likebox' ); ?>
						</a>

					</div>
				</div>

			</div>


			<div id="mif-free-half_width-upgrade"
				class="fta-upgrade-modal esf-modal fadeIn">
				<div class="modal-content">

					<div class="mif-modal-content"><span
								class="mif-lock-icon"><span class="dashicons dashicons-lock"></span></span>
						<h5><?php esc_html_e( 'Premium Feature', 'easy-facebook-likebox' ); ?></h5>
						<p><?php esc_html_e( "We're sorry, Half Width layout is not included in your plan. Please upgrade to premium version to unlock this and all other cool features.", 'easy-facebook-likebox' ); ?>
							<a target="_blank"
								href="<?php echo esc_url( 'https://easysocialfeed.com/my-instagram-feed-demo/blog-layout' ); ?>"><?php esc_html_e( 'Check out the demo', 'easy-facebook-likebox' ); ?></a>
						</p>
						<p><?php esc_html_e( 'Upgrade today and get ' . $banner_info['discount'] . ' discount! On the checkout click on "Have a promotional code?" and enter ', 'easy-facebook-likebox' ); ?>
							<?php if ( $banner_info['coupon'] ) { ?>
								<code><?php esc_html_e( $banner_info['coupon'] ); ?></code>
							<?php } ?>
						</p>
						<hr/>
						<a href="<?php echo esc_url( efl_fs()->get_upgrade_url() ); ?>"
							class=" btn"><span class="dashicons dashicons-lock"></span><?php esc_html_e( 'Upgrade to pro', 'easy-facebook-likebox' ); ?>
						</a>

					</div>
				</div>

			</div>


			<div id="mif-free-full_width-upgrade"
				class="fta-upgrade-modal esf-modal fadeIn">
				<div class="modal-content">

					<div class="mif-modal-content"><span
								class="mif-lock-icon"><span class="dashicons dashicons-lock"></span></span>
						<h5><?php esc_html_e( 'Premium Feature', 'easy-facebook-likebox' ); ?></h5>
						<p><?php esc_html_e( "We're sorry, Full Width layout is not included in your plan. Please upgrade to premium version to unlock this and all other cool features.", 'easy-facebook-likebox' ); ?>
							<a target="_blank"
								href="<?php echo esc_url( 'https://easysocialfeed.com/my-instagram-feed-demo/full-width' ); ?>"><?php esc_html_e( 'Check out the demo', 'easy-facebook-likebox' ); ?></a>
						</p>
						<p><?php esc_html_e( 'Upgrade today and get ' . $banner_info['coupon'] . ' discount! On the checkout click on "Have a promotional code?" and enter ', 'easy-facebook-likebox' ); ?>
							<?php if ( $banner_info['coupon'] ) { ?>
								<code><?php esc_html_e( $banner_info['coupon'] ); ?></code>
							<?php } ?>
						</p>
						<hr/>
						<a href="<?php echo esc_url( efl_fs()->get_upgrade_url() ); ?>"
							class=" btn"><span class="dashicons dashicons-unlock"></span><?php esc_html_e( 'Upgrade to pro', 'easy-facebook-likebox' ); ?>
						</a>

					</div>
				</div>

			</div>
		<?php } ?>
			<div id="esf-insta-addon-upgrade" class="fta-upgrade-modal esf-modal fadeIn">
				<div class="modal-content">
					<div class="mif-modal-content"><span class="mif-lock-icon"><span class="dashicons dashicons-unlock"></span></span>
						<h5><?php esc_html_e( 'Multifeed Add-on', 'easy-facebook-likebox' ); ?></h5>
						<p><?php esc_html_e( 'The Multifeed add-on gives you the ability to display posts of multiple Instagram accounts in one single feed ordered by date.', 'easy-facebook-likebox' ); ?>
							<a target="_blank" href="https://easysocialfeed.com/my-instagram-feed-demo/multifeed"><?php esc_html_e( 'Check out the demo', 'easy-facebook-likebox' ); ?></a>
						</p>
						<hr>
						<a href="<?php echo esc_url( admin_url( 'admin.php?slug=esf-multifeed&page=feed-them-all-addons' ) ); ?>"
							class=" btn"><span class="dashicons dashicons-unlock"></span><?php esc_html_e( 'Get Started', 'easy-facebook-likebox' ); ?>
						</a>
					</div>
				</div>
			</div>
	</div>
	<?php require_once FTA_PLUGIN_DIR . 'admin/views/html-upgrade-notice.php'; ?>
	</div>

<div class="esf-notification-holder"><?php esc_html_e( 'Copied', 'easy-facebook-likebox' ); ?></div>
</div>