<?php
/**
 * Admin View: Settings tab - Translation
 *
 * Displays frontend strings from Facebook and Instagram in a table
 * so users can override labels and copy globally.
 *
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$translation_strings = ESF_Translation_Strings::get_all_strings();
$saved_translations  = isset( $fta_settings['translation'] ) && is_array( $fta_settings['translation'] ) ? $fta_settings['translation'] : array();

?>
<div id="esf-settings-translation" class="col s12 efbl_tab_c slideLeft active">
	<h5><?php esc_html_e( 'Translation', 'easy-facebook-likebox' ); ?></h5>
	<p class="description esf-translation-intro">
		<?php esc_html_e( 'Change the text shown in your Facebook and Instagram feeds. Leave a field blank to use the default.', 'easy-facebook-likebox' ); ?>
	</p>

	<table class="widefat striped esf-translation-table" role="presentation">
		<thead>
			<tr>
				<th scope="col" class="esf-translation-col-default"><?php esc_html_e( 'Default', 'easy-facebook-likebox' ); ?></th>
				<th scope="col" class="esf-translation-col-input"><?php esc_html_e( 'Your text', 'easy-facebook-likebox' ); ?></th>
				<th scope="col" class="esf-translation-col-context"><?php esc_html_e( 'Where it\'s used', 'easy-facebook-likebox' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ( $translation_strings as $category_key => $category ) {
				$category_label = isset( $category['label'] ) ? $category['label'] : '';
				$strings       = isset( $category['strings'] ) ? $category['strings'] : array();
				if ( empty( $strings ) ) {
					continue;
				}
				?>
				<tr class="esf-translation-section-row">
					<td colspan="3" class="esf-translation-section">
						<span class="esf-translation-section-inner">
							<span class="esf-translation-section-label"><?php echo esc_html( $category_label ); ?></span>
							<button type="button" class="button button-primary button-small esf-save-translation-settings">
								<?php esc_html_e( 'Save All Settings', 'easy-facebook-likebox' ); ?>
							</button>
						</span>
					</td>
				</tr>
				<?php
				foreach ( $strings as $item ) {
					$key     = isset( $item['key'] ) ? $item['key'] : '';
					$default = isset( $item['default'] ) ? $item['default'] : '';
					$context = isset( $item['context'] ) ? $item['context'] : '';
					$value   = isset( $saved_translations[ $key ] ) && '' !== $saved_translations[ $key ] ? $saved_translations[ $key ] : '';
					if ( ! $key ) {
						continue;
					}
					?>
					<tr class="esf-translation-data-row">
						<td class="esf-translation-col-default">
							<label for="esf_translation_<?php echo esc_attr( $key ); ?>" class="screen-reader-text"><?php echo esc_html( $default ); ?></label>
							<?php echo esc_html( $default ); ?>
						</td>
						<td class="esf-translation-col-input">
							<input type="text"
								id="esf_translation_<?php echo esc_attr( $key ); ?>"
								name="esf_translation[<?php echo esc_attr( $key ); ?>]"
								class="large-text"
								value="<?php echo esc_attr( $value ); ?>"
								placeholder="<?php esc_attr_e( 'Leave blank for default', 'easy-facebook-likebox' ); ?>"
								aria-describedby="esf_translation_context_<?php echo esc_attr( $key ); ?>">
						</td>
						<td id="esf_translation_context_<?php echo esc_attr( $key ); ?>" class="esf-translation-col-context description">
							<?php echo esc_html( $context ); ?>
						</td>
					</tr>
					<?php
				}
			}
			?>
		</tbody>
	</table>

	<p class="submit">
		<button type="button" class="button button-primary esf-save-translation-settings">
			<?php esc_html_e( 'Save All Settings', 'easy-facebook-likebox' ); ?>
		</button>
	</p>
</div>
