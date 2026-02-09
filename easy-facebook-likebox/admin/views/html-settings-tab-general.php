<?php
/**
 * Admin View: Settings tab - General
 *
 * General options including preserve settings on uninstall.
 *
 * @since 6.8.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$preserve_on_uninstall = isset( $fta_settings['preserve_settings_on_uninstall'] ) && $fta_settings['preserve_settings_on_uninstall'];
?>
<div id="esf-settings-general" class="col s12 efbl_tab_c slideLeft active">
	<h5><?php esc_html_e( 'General', 'easy-facebook-likebox' ); ?></h5>
	<p>
		<?php esc_html_e( 'Control what happens to your settings and cached data when the plugin is deleted or removed.', 'easy-facebook-likebox' ); ?>
	</p>

	<div class="row" style="margin-top: 20px;">
		<div class="col s12">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="esf_preserve_settings_on_uninstall">
								<?php esc_html_e( 'Preserve settings on uninstall', 'easy-facebook-likebox' ); ?>
							</label>
						</th>
						<td>
							<label for="esf_preserve_settings_on_uninstall">
								<input type="checkbox" name="preserve_settings_on_uninstall" id="esf_preserve_settings_on_uninstall" value="1" <?php checked( $preserve_on_uninstall ); ?> />
								<?php esc_html_e( 'Keep settings and data when the plugin is deleted', 'easy-facebook-likebox' ); ?>
							</label>
							<p class="description" style="margin-top: 10px;">
								<?php esc_html_e( 'When checked and saved, all plugin settings, cached data, and uploads will be kept when you delete or remove the plugin. When unchecked (default), uninstalling the plugin will remove all its data.', 'easy-facebook-likebox' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="submit">
				<button type="button" class="button button-primary esf-save-general-settings">
					<?php esc_html_e( 'Save Settings', 'easy-facebook-likebox' ); ?>
				</button>
			</p>
		</div>
	</div>
</div>
