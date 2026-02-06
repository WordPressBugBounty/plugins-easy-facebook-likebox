<?php
/**
 * Admin View: Settings tab - GDPR
 *
 * @since 6.8.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$gdpr_setting        = isset( $fta_settings['gdpr'] ) ? $fta_settings['gdpr'] : 'auto';
$consent_plugin_info = ESF_GDPR_Integrations::get_consent_plugin_info();

?>
<div id="esf-settings-gdpr" class="col s12 efbl_tab_c slideLeft active">
	<h5><?php esc_html_e( 'GDPR', 'easy-facebook-likebox' ); ?></h5>
	<p>
		<?php esc_html_e( 'Control how Facebook and Instagram feeds respect visitor consent. This applies to all feeds (Facebook and Instagram).', 'easy-facebook-likebox' ); ?>
	</p>

	<div class="row" style="margin-top: 20px;">
		<div class="col s12">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="esf_gdpr">
								<?php esc_html_e( 'GDPR mode', 'easy-facebook-likebox' ); ?>
								<span class="dashicons dashicons-info esf-gdpr-help-icon" style="font-size: 16px; width: 16px; height: 16px; color: #666; cursor: help; margin-left: 5px;" aria-label="<?php esc_attr_e( 'Learn more about GDPR options', 'easy-facebook-likebox' ); ?>"></span>
							</label>
						</th>
						<td>
							<select name="gdpr" id="esf_gdpr" class="esf-gdpr-select" style="min-width: 200px;">
								<option value="auto" <?php selected( $gdpr_setting, 'auto' ); ?>>
									<?php esc_html_e( 'Automatic', 'easy-facebook-likebox' ); ?>
								</option>
								<option value="yes" <?php selected( $gdpr_setting, 'yes' ); ?>>
									<?php esc_html_e( 'Always Enabled', 'easy-facebook-likebox' ); ?>
								</option>
								<option value="no" <?php selected( $gdpr_setting, 'no' ); ?>>
									<?php esc_html_e( 'Disabled', 'easy-facebook-likebox' ); ?>
								</option>
							</select>

							<?php if ( $consent_plugin_info['detected'] ) : ?>
								<p class="esf-gdpr-plugin-detected esf-gdpr-plugin-success">
									<span class="dashicons dashicons-yes-alt"></span>
									<?php echo esc_html( $consent_plugin_info['name'] ); ?>
								</p>
							<?php else : ?>
								<p class="esf-gdpr-plugin-detected esf-gdpr-plugin-warning">
									<span class="dashicons dashicons-warning"></span>
									<?php
									/* translators: %s: link to "supported GDPR / Cookie plugin" */
									printf(
										wp_kses_post(
											__(
												'No compatible consent plugin detected. Automatic mode will have no effect until you install and configure a %s.',
												'easy-facebook-likebox'
											)
										),
										'<a href="javascript:void(0);" class="esf-supported-plugins-link">' . esc_html__( 'supported GDPR / Cookie plugin', 'easy-facebook-likebox' ) . '</a>'
									);
									?>
								</p>
								<div class="esf-supported-plugins-list" style="display:none; margin-top:8px; font-size:12px;">
									<p style="margin:0 0 4px 0;">
										<strong><?php esc_html_e( 'Supported GDPR / cookie consent plugins:', 'easy-facebook-likebox' ); ?></strong>
									</p>
									<ul style="margin:0 0 0 18px; list-style:disc;">
										<?php
										$gdpr_plugins_list = ESF_GDPR_Integrations::get_supported_plugins_display_list();
										foreach ( $gdpr_plugins_list as $gdpr_plugin_name ) {
											echo '<li>' . esc_html( $gdpr_plugin_name ) . '</li>';
										}
										?>
									</ul>
								</div>
							<?php endif; ?>

							<p class="description esf-gdpr-mode-description" data-mode="auto" style="margin-top: 10px; display: none;">
								<?php esc_html_e( 'Automatic: Feeds will respect your consent plugin. Features (images, videos, popups, Load More) are limited until visitors give consent, then automatically enabled.', 'easy-facebook-likebox' ); ?>
							</p>
							<p class="description esf-gdpr-mode-description" data-mode="yes" style="margin-top: 10px; display: none;">
								<?php esc_html_e( 'Always Enabled: GDPR restrictions are always on for all visitors. External media and Load More remain disabled, even if visitors give consent.', 'easy-facebook-likebox' ); ?>
							</p>
							<p class="description esf-gdpr-mode-description" data-mode="no" style="margin-top: 10px; display: none;">
								<?php esc_html_e( 'Disabled: GDPR controls are turned off. All images, videos, popups, and Load More will always load normally for visitors.', 'easy-facebook-likebox' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="submit">
				<button type="button" class="button button-primary esf-save-gdpr-settings">
					<?php esc_html_e( 'Save Settings', 'easy-facebook-likebox' ); ?>
				</button>
			</p>
		</div>
	</div>
</div>

<div id="esf-gdpr-tooltip-template" style="display:none;">
	<p>
		<strong><?php esc_html_e( 'If set to "Always Enabled":', 'easy-facebook-likebox' ); ?></strong><br/>
		<?php esc_html_e( 'Prevents all images and videos from being loaded directly from Facebook/Instagram to stop external requests. Some plugin features will be disabled or limited for all visitors.', 'easy-facebook-likebox' ); ?>
	</p>
	<p style="margin-top: 8px;">
		<strong><?php esc_html_e( 'If set to "Disabled":', 'easy-facebook-likebox' ); ?></strong><br/>
		<?php esc_html_e( 'The plugin will load and display images and videos directly from Facebook/Instagram for everyone, without applying additional GDPR restrictions.', 'easy-facebook-likebox' ); ?>
	</p>
	<p style="margin-top: 8px;">
		<strong><?php esc_html_e( 'If set to "Automatic":', 'easy-facebook-likebox' ); ?></strong><br/>
		<?php esc_html_e( 'The plugin will only load images and videos directly from Facebook/Instagram after consent has been given by a supported GDPR / cookie consent plugin.', 'easy-facebook-likebox' ); ?>
	</p>
</div>
