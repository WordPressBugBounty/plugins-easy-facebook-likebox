<?php
/**
 * Admin View: Tab - Authenticate
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( isset( $fta_settings['plugins']['instagram']['instagram_connected_account'] ) ) {

	$mif_personal_connected_accounts = $fta_settings['plugins']['instagram']['instagram_connected_account'];

} else {

	$mif_personal_connected_accounts = array();

}
if ( ( isset( $_GET['access_token'] ) && ! empty( $_GET['access_token'] ) ) || ( isset( $_GET['mif_access_token'] ) && ! empty( $_GET['mif_access_token'] ) ) ) {

	if ( ! empty( $_GET['access_token'] ) ) {
		$access_token = sanitize_text_field( $_GET['access_token'] );
		$action       = 'mif_save_business_access_token';
		$remove_pram  = 'access_token';
	}

	if ( ! empty( $_GET['mif_access_token'] ) ) {
		$access_token = sanitize_text_field( $_GET['mif_access_token'] );
		$action       = 'mif_save_access_token';
		$remove_pram  = 'mif_access_token';
	}

	if ( current_user_can( 'editor' ) || current_user_can( 'administrator' ) ) { ?>

		<script>
			jQuery(document).ready(function() {
			function MIFremoveURLParameter(url, parameter) {
				var urlparts = url.split('?');
				if (urlparts.length >= 2) {
				var prefix = encodeURIComponent(parameter) + '=';
				var pars = urlparts[1].split(/[&;]/g);
				for (var i = pars.length; i-- > 0;) {
					if (pars[i].lastIndexOf(prefix, 0) !== -1) {
					pars.splice(i, 1);
					}
				}
				url = urlparts[0] + '?' + pars.join('&');
				return url;
				}
				else { return url; }
			}

			esfRemoveNotification();
			/*
			 * Show the dialog for Saving.
			 */
			esfShowNotification('Please wait! Authenticating...', 50000000);

			var url = window.location.href;

			url = MIFremoveURLParameter(url, "<?php echo $remove_pram; ?>");

			jQuery('#efbl_access_token').text("<?php echo $access_token; ?>");

			var data = {
				'action': '<?php echo esc_html( $action ); ?>',
				'access_token': '<?php echo esc_html( $access_token ); ?>',
				'id': 'insta',
				'nonce' : '<?php echo wp_create_nonce( 'esf-ajax-nonce' ); ?>',
			};

			jQuery.ajax({
				url: "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>",
				type: 'post',
				data: data,
				dataType: 'json',
				success: function(response) {

				window.history.pushState('newurl', 'newurl', url);

				if (response.success) {

					var pages_html = response.data['1'];
					esfShowNotification(response.data['0'], 3000);
					jQuery('.efbl_all_pages').
						html(' ').
						html(response.data['1']).
						slideDown('slow');
					jQuery('.fta_noti_holder').fadeOut('slow');
				}
				else {
					esfShowNotification(response.data, 3000);
				}
				},
			});

			});
		</script>
		<?php
	}
}
?>
<div id="mif-general" class="mif_tab_c slideLeft <?php echo $active_tab == 'mif-general' ? 'active' : ''; ?>">
	<h5><?php esc_html_e( "Let's connect your account with plugin", 'easy-facebook-likebox' ); ?></h5>
	<p><?php esc_html_e( 'Click the button below, log into your Instagram account and authorize the app to get access token.', 'easy-facebook-likebox' ); ?></p>
	<a class="mif_auth_btn mif_auth_btn_st btn  esf-modal-trigger"
		href="#mif-authentication-modal">
		<img src="<?php echo ESF_INSTA_PLUGIN_URL; ?>/admin/assets/images/insta-logo.png"/><?php esc_html_e( 'Connect My Instagram Account', 'easy-facebook-likebox' ); ?>
	</a>
	<span class="mif-or-placeholder"><?php esc_html_e( 'OR', 'easy-facebook-likebox' ); ?></span>
	<a class="mif_auth_btn mif_auth_btn_st btn mif-connect-manually">
		<?php esc_html_e( 'Setup Manually', 'easy-facebook-likebox' ); ?>
	</a>
	<div class="row mif-connect-manually-wrap">
		<form action="" method="get">
			<div class="mif-fields-wrap">
				<input type="hidden" name="page" value="mif">
				<div class="input-field col s12 mif_fields">
					<label for="mif_access_token">
						<?php esc_html_e( 'Access Token', 'easy-facebook-likebox' ); ?>
						<a class="tooltip" target="_blank" href="https://easysocialfeed.com/custom-facebook-feed/page-token/">(?)</a>
					</label>
					<input id="mif_access_token" name="access_token" required type="text">
				</div>
			</div>
			<input class="btn" value="<?php esc_html_e( 'Submit', 'easy-facebook-likebox' ); ?>" type="submit">
		</form>
	</div>
	<div class="row auth-row">
			<div class="efbl_all_pages col s12 " 
			<?php
			if ( ! esf_insta_has_connected_account() ) {
				?>
				style="display: none;" <?php } ?>>

			<?php
			if ( $mif_personal_connected_accounts && esf_insta_instagram_type() == 'personal' ) {

				foreach ( $mif_personal_connected_accounts as $personal_id => $mif_personal_connected_account ) {
					?>

					<ul class="collection with-header">
						<li class="collection-header">
							<h5><?php esc_html_e( 'Connected Instagram Account', 'easy-facebook-likebox' ); ?></h5>
							<a href="#mif-remove-at"
								class="esf-modal-trigger fta-remove-at-btn tooltipped"
								data-type="personal" data-position="left"
								data-delay="50"
								data-tooltip="<?php esc_html_e( 'Delete Access Token', 'easy-facebook-likebox' ); ?>"><span class="dashicons dashicons-trash"></span></a>
						</li>
						<li class="collection-item li-<?php esc_attr_e( $personal_id ); ?>">
							<div class="esf-bio-wrap">
							<span class="title"><?php esc_html_e( $mif_personal_connected_account['username'] ); ?></span>

							<p><?php esc_html_e( 'ID', 'easy-facebook-likebox' ); ?>
								: <?php esc_html_e( $personal_id ); ?> <span
										class="dashicons dashicons-admin-page efbl_copy_id "
										data-clipboard-text="<?php esc_attr_e( $personal_id ); ?>"></span>
							</p>
							</div>
						</li>
					</ul>

					<?php
				}
			} elseif ( isset( $fta_settings['plugins']['facebook']['approved_pages'] ) && ! empty( $fta_settings['plugins']['facebook']['approved_pages'] ) ) {


				?>

					<ul class="collection with-header">
						<li class="collection-header">
							<h5><?php esc_html_e( 'Connected Instagram Account', 'easy-facebook-likebox' ); ?></h5>
							<a href="#fta-remove-at"
								class="esf-modal-trigger fta-remove-at-btn tooltipped"
								data-position="left" data-delay="50"
								data-tooltip="<?php esc_html_e( 'Delete Access Token', 'easy-facebook-likebox' ); ?>"><span class="dashicons dashicons-trash"></span></a>
						</li>

						<?php
						foreach ( $fta_settings['plugins']['facebook']['approved_pages'] as $efbl_page ) {

							if ( isset( $efbl_page['instagram_connected_account'] ) ) {

								$fta_insta_connected_account = $efbl_page['instagram_connected_account'];


								if ( isset( $fta_insta_connected_account->ig_id ) && ! empty( $fta_insta_connected_account->ig_id ) ) {
										$insta_id        = $fta_insta_connected_account->id;
										$page_id         = $efbl_page['id'];
										$profile_pic_url = esf_insta_get_logo( $insta_id, $page_id );

									if ( ! $profile_pic_url ) {
										$profile_pic_url = $fta_insta_connected_account->profile_picture_url;
									}
									?>

									<li class="collection-item avatar fta_insta_connected_account li-<?php esc_attr_e( $fta_insta_connected_account->ig_id ); ?>">

										<a href="https://www.instagram.com/<?php esc_attr_e( $fta_insta_connected_account->username ); ?>"
											target="_blank">
											<img src="<?php echo esc_url( $profile_pic_url ); ?>"
												alt="" class="circle">
										</a>
										<div class="esf-bio-wrap">
										<span class="title"><?php esc_html_e( $fta_insta_connected_account->name ); ?></span>
										<p><?php esc_html_e( $fta_insta_connected_account->username ); ?>
											<br> <?php esc_html_e( 'ID', 'easy-facebook-likebox' ); ?>
											: <?php esc_html_e( $fta_insta_connected_account->id ); ?>
											<span class="dashicons dashicons-admin-page efbl_copy_id tooltipped"
												data-position="right"
												data-clipboard-text="<?php esc_attr_e( $fta_insta_connected_account->id ); ?>"
												data-delay="100"
												data-tooltip="<?php esc_html_e( 'Copy', 'easy-facebook-likebox' ); ?>"></span>
										</p>
										</div>
									</li>
									<?php
								}
							}
						}
						?>
					</ul>

					<?php

			}
			?>

		</div>
	</div>


</div>
<!-- Connection Type Selector Modal -->
<div id="mif-authentication-modal" class="insta-connection-type-modal esf-modal fadeIn">
	<div class="modal-content">
		<span class="mif-close-modal modal-close"><span class="dashicons dashicons-no-alt"></span></span>
		<div class="mif-modal-content">
			<h5><?php esc_html_e( 'What connection type do you need?', 'easy-facebook-likebox' ); ?></h5>
			
			<div class="insta-connection-cards">
				<!-- Business Basic Card -->
				<div class="insta-connection-card" data-type="basic">
					<div class="insta-card-header">
						<input type="radio" name="insta_connection_type" id="insta_basic_radio" value="basic" checked>
						<label for="insta_basic_radio">
							<strong><?php esc_html_e( 'Business Basic', 'easy-facebook-likebox' ); ?></strong>
						</label>
					</div>
					<div class="insta-card-subtitle">
						<?php esc_html_e( 'Connects via Instagram', 'easy-facebook-likebox' ); ?>
					</div>
					<ul class="insta-card-features">
						<li class="feature-available">
							<span class="dashicons dashicons-yes"></span>
							<?php esc_html_e( 'Requires Instagram Creator or Business account', 'easy-facebook-likebox' ); ?>
						</li>
						<li class="feature-available">
							<span class="dashicons dashicons-yes"></span>
							<?php esc_html_e( 'Display profile info, avatars, and posts', 'easy-facebook-likebox' ); ?>
						</li>
						<li class="feature-available">
							<span class="dashicons dashicons-yes"></span>
							<?php esc_html_e( 'Connect your Instagram account', 'easy-facebook-likebox' ); ?>
						</li>
						<li class="feature-unavailable">
							<span class="dashicons dashicons-no"></span>
							<?php esc_html_e( 'Does not require a Facebook page', 'easy-facebook-likebox' ); ?>
						</li>
						<li class="feature-unavailable">
							<span class="dashicons dashicons-no"></span>
							<?php esc_html_e( 'Does not display Hashtag or Mentions feeds', 'easy-facebook-likebox' ); ?>
						</li>
					</ul>
				</div>

				<!-- Business Advanced Card -->
				<div class="insta-connection-card" data-type="advanced">
					<div class="insta-card-header">
						<input type="radio" name="insta_connection_type" id="insta_advanced_radio" value="advanced">
						<label for="insta_advanced_radio">
							<strong><?php esc_html_e( 'Business Advanced', 'easy-facebook-likebox' ); ?></strong>
						</label>
					</div>
					<div class="insta-card-subtitle">
						<?php esc_html_e( 'Connects via Facebook', 'easy-facebook-likebox' ); ?>
					</div>
					<ul class="insta-card-features">
						<li class="feature-available">
							<span class="dashicons dashicons-yes"></span>
							<?php esc_html_e( 'Requires Instagram Creator or Business account', 'easy-facebook-likebox' ); ?>
						</li>
						<li class="feature-available">
							<span class="dashicons dashicons-yes"></span>
							<?php esc_html_e( 'Display profile info, avatars, and posts', 'easy-facebook-likebox' ); ?>
						</li>
						<li class="feature-available">
							<span class="dashicons dashicons-yes"></span>
							<?php esc_html_e( 'Connect multiple Instagram accounts', 'easy-facebook-likebox' ); ?>
						</li>
						<li class="feature-available">
							<span class="dashicons dashicons-yes"></span>
							<?php esc_html_e( 'Displays Hashtag or Mentions feeds', 'easy-facebook-likebox' ); ?>
						</li>
						<li class="feature-warning feature-requirement">
							<span class="dashicons dashicons-warning"></span>
							<div>
								<strong><?php esc_html_e( 'Requirements: Convert your Instagram to a Creator/Business account, then connect it to a Facebook page', 'easy-facebook-likebox' ); ?></strong>
								<span class="feature-requirement-note"><?php esc_html_e( 'If already done, you can proceed. See helpful links below for step-by-step guides.', 'easy-facebook-likebox' ); ?></span>
							</div>
						</li>
					</ul>
				</div>
			</div>

			<div class="insta-connection-actions">
				<a href="#insta-basic-connect-info" class="btn insta-proceed-btn" data-target="basic">
					<?php esc_html_e( 'Continue', 'easy-facebook-likebox' ); ?>
					<span class="dashicons dashicons-arrow-right-alt2"></span>
				</a>
			</div>

			<div class="insta-help-links">
				<p>
					<a href="https://help.instagram.com/502981923235522" target="_blank" rel="noopener">
						<span class="dashicons dashicons-external"></span>
						<?php esc_html_e( 'How to convert to a Professional Account', 'easy-facebook-likebox' ); ?>
					</a>
				</p>
				<p>
					<a href="https://www.facebook.com/business/help/connect-instagram-to-page" target="_blank" rel="noopener">
						<span class="dashicons dashicons-external"></span>
						<?php esc_html_e( 'How to connect Instagram to Facebook Page', 'easy-facebook-likebox' ); ?>
					</a>
				</p>
			</div>
		</div>
	</div>
</div>

<!-- Basic Connection Permissions Modal -->
<div id="insta-basic-connect-info" class="esf-modal insta-connect-modal fadeIn">
	<div class="modal-content">
		<span class="mif-close-modal modal-close"><span class="dashicons dashicons-no-alt"></span></span>
		<div class="mif-modal-content">
			<h6 class="insta-modal-title"><?php esc_html_e( 'Business Basic Connection', 'easy-facebook-likebox' ); ?></h6>
			
			<div class="insta-connect-actions insta-connect-actions-top">
				<a class="btn mif_auth_btn insta-connect-primary" href="<?php echo esc_url( $personal_auth_url ); ?>">
					<img src="<?php echo ESF_INSTA_PLUGIN_URL; ?>/admin/assets/images/insta-logo.png"/>
					<?php esc_html_e( 'Connect My Instagram Account', 'easy-facebook-likebox' ); ?>
				</a>
				<a href="#" class="insta-back-btn">
					<span class="dashicons dashicons-arrow-left-alt2"></span>
					<?php esc_html_e( 'Change Connection Type', 'easy-facebook-likebox' ); ?>
				</a>
			</div>

			<div class="insta-login-warning">
				<span class="dashicons dashicons-info"></span>
				<p><?php esc_html_e( 'Trouble connecting? Please ', 'easy-facebook-likebox' ); ?>
					<a href="https://www.instagram.com/" target="_blank"><?php esc_html_e( 'log in to instagram.com', 'easy-facebook-likebox' ); ?></a>
					<?php esc_html_e( ' with your Instagram account before clicking the connect button.', 'easy-facebook-likebox' ); ?>
				</p>
			</div>

			<div class="insta-permissions-section">
				<h6><?php esc_html_e( 'Permissions Needed', 'easy-facebook-likebox' ); ?></h6>
				<p class="insta-permissions-intro"><?php esc_html_e( 'This is the permission that would be granted once you connect your user account:', 'easy-facebook-likebox' ); ?></p>

				<ul class="insta-permissions-list">
					<li>
						<span class="insta-permission-icon"><span class="dashicons dashicons-admin-network"></span></span>
						<div class="insta-permission-content">
							<strong>instagram_business_basic</strong>
							<p><?php esc_html_e( 'This permission is used to retrieve the name, statistics, post content, and other information of the Instagram account associated with the account you authorized.', 'easy-facebook-likebox' ); ?></p>
						</div>
					</li>
				</ul>
			</div>

			<div class="insta-connect-description">
				<p><?php esc_html_e( 'This does not give us permission to manage your Instagram account, it simply allows the plugin to see your profile information and retrieve public content from the API.', 'easy-facebook-likebox' ); ?></p>
				<p class="insta-terms-note">
					<?php esc_html_e( 'Use of this plugin is subject to', 'easy-facebook-likebox' ); ?>
					<a href="https://developers.facebook.com/terms" target="_blank"><?php esc_html_e( 'Facebook\'s Platform Terms', 'easy-facebook-likebox' ); ?></a>
				</p>
			</div>
		</div>
	</div>
</div>

<!-- Advanced Connection Permissions Modal -->
<div id="insta-advanced-connect-info" class="esf-modal insta-connect-modal fadeIn">
	<div class="modal-content">
		<span class="mif-close-modal modal-close"><span class="dashicons dashicons-no-alt"></span></span>
		<div class="mif-modal-content">
			<h6 class="insta-modal-title"><?php esc_html_e( 'Business Advanced Connection', 'easy-facebook-likebox' ); ?></h6>
			
			<div class="insta-important-note">
				<span class="insta-note-icon"><span class="dashicons dashicons-warning"></span></span>
				<div class="insta-note-content">
					<strong><?php esc_html_e( 'Important:', 'easy-facebook-likebox' ); ?></strong>
					<p><?php esc_html_e( 'When connecting to Facebook, you will see an "Edit requested access" option. Please ensure that your Business Manager is selected and all Instagram pages associated with it have all permissions enabled. If any Business Manager or specific permissions are deselected, the plugin features will not work properly.', 'easy-facebook-likebox' ); ?></p>
					<p class="insta-reconnect-note">
						<strong><?php esc_html_e( 'Already connected?', 'easy-facebook-likebox' ); ?></strong>
						<?php esc_html_e( 'If you see "You previously logged into Easy Social Feed", you can manage your connection by deleting it from the "Connected Instagram Account" section above, or ', 'easy-facebook-likebox' ); ?>
						<a href="https://www.facebook.com/settings/?tab=business_tools" target="_blank" rel="noopener"><?php esc_html_e( 'manage it in Facebook Business Integrations', 'easy-facebook-likebox' ); ?></a>
						<?php esc_html_e( ' to add/remove accounts.', 'easy-facebook-likebox' ); ?>
					</p>
				</div>
			</div>

			<div class="insta-connect-actions insta-connect-actions-top">
				<a class="btn mif_auth_btn insta-connect-primary" href="<?php echo esc_url( $auth_url ); ?>">
					<img class="efb_icon left" src="<?php echo EFBL_PLUGIN_URL; ?>/admin/assets/images/facebook-icon.png"/>
					<?php esc_html_e( 'Connect My Instagram Account', 'easy-facebook-likebox' ); ?>
				</a>
				<a href="#" class="insta-back-btn">
					<span class="dashicons dashicons-arrow-left-alt2"></span>
					<?php esc_html_e( 'Change Connection Type', 'easy-facebook-likebox' ); ?>
				</a>
			</div>

			<div class="insta-permissions-section">
				<h6><?php esc_html_e( 'Permissions Needed', 'easy-facebook-likebox' ); ?></h6>
				<p class="insta-permissions-intro"><?php esc_html_e( 'These are the permissions that would be granted once you connect your user account:', 'easy-facebook-likebox' ); ?></p>

				<ul class="insta-permissions-list">
					<li>
						<span class="insta-permission-icon"><span class="dashicons dashicons-list-view"></span></span>
						<div class="insta-permission-content">
							<strong>pages_show_list</strong>
							<p><?php esc_html_e( 'This permission is used to retrieve the name of the Instagram accounts you have access to. This allows you to connect multiple Instagram accounts at once.', 'easy-facebook-likebox' ); ?></p>
						</div>
					</li>
					<li>
						<span class="insta-permission-icon"><span class="dashicons dashicons-format-image"></span></span>
						<div class="insta-permission-content">
							<strong>instagram_basic</strong>
							<p><?php esc_html_e( 'This permission allows us to show information about the Instagram account you use to connect to our plugin so that it can be displayed in the header above the feed and be used to identify which account was connected.', 'easy-facebook-likebox' ); ?></p>
						</div>
					</li>
					<li>
						<span class="insta-permission-icon"><span class="dashicons dashicons-admin-comments"></span></span>
						<div class="insta-permission-content">
							<strong>instagram_manage_comments</strong>
							<p><?php esc_html_e( 'This permission is used to show comments for your posts.', 'easy-facebook-likebox' ); ?></p>
						</div>
					</li>
					<li>
						<span class="insta-permission-icon"><span class="dashicons dashicons-chart-bar"></span></span>
						<div class="insta-permission-content">
							<strong>instagram_manage_insights</strong>
							<p><?php esc_html_e( 'This permission allows us to show information about your Instagram accounts such as the name, bio, and avatar.', 'easy-facebook-likebox' ); ?></p>
						</div>
					</li>
					<li>
						<span class="insta-permission-icon"><span class="dashicons dashicons-chart-line"></span></span>
						<div class="insta-permission-content">
							<strong>pages_read_engagement</strong>
							<p><?php esc_html_e( 'This permission allows us to see data related to the number of followers, read content of the page, and other metrics.', 'easy-facebook-likebox' ); ?></p>
						</div>
					</li>
					<li>
						<span class="insta-permission-icon"><span class="dashicons dashicons-businessman"></span></span>
						<div class="insta-permission-content">
							<strong>business_management</strong>
							<p><?php esc_html_e( 'This permission is used to retrieve the names of the Instagram accounts you have access to. It\'s required in combination with other permissions.', 'easy-facebook-likebox' ); ?></p>
						</div>
					</li>
				</ul>
			</div>

			<div class="insta-connect-description">
				<p><?php esc_html_e( 'This does not give us permission to manage your Instagram account or Facebook pages, it simply allows the plugin to see a list of them and retrieve their public content from the API.', 'easy-facebook-likebox' ); ?></p>
				<p class="insta-terms-note">
					<?php esc_html_e( 'Use of this plugin is subject to', 'easy-facebook-likebox' ); ?>
					<a href="https://developers.facebook.com/terms" target="_blank"><?php esc_html_e( 'Facebook\'s Platform Terms', 'easy-facebook-likebox' ); ?></a>
				</p>
			</div>
		</div>
	</div>
</div>

