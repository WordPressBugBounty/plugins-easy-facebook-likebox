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

/**
 * All registered frontend strings grouped by category.
 *
 * @var array<string, array{label:string,strings:array<int,array{key:string,default:string,context:string}>}>
 */
$translation_strings = ESF_Translation_Strings::get_all_strings();

/**
 * Saved custom translations indexed by string key.
 *
 * @var array<string, string>
 */
$saved_translations = isset( $fta_settings['translation'] ) && is_array( $fta_settings['translation'] ) ? $fta_settings['translation'] : array();

?>
<div id="esf-settings-translation" class="col s12 efbl_tab_c slideLeft active">
	<h5><?php esc_html_e( 'Translation', 'easy-facebook-likebox' ); ?></h5>
	<p class="description esf-translation-intro">
		<?php esc_html_e( 'Change the text shown in your Facebook and Instagram feeds. Leave a field blank to use the default.', 'easy-facebook-likebox' ); ?>
	</p>

	<?php
	// Show an Autofill notice when we can detect a locale. Access control (premium, API key, etc.)
	// is enforced in the AJAX handler.
	/** @var string $detected_locale Effective feed locale for API/AI features. */
	$detected_locale = function_exists( 'esf_get_effective_api_locale' ) ? esf_get_effective_api_locale() : '';

	/** @var string $locale_label Human-readable label for the detected locale (e.g. "French (France)"). */
	$locale_label = '';

	/**
	 * Locales for which the AI autofill banner has already been used.
	 *
	 * @var array<string, bool> $ai_used_locales
	 */
	$ai_used_locales = isset( $fta_settings['translation_ai_used'] ) && is_array( $fta_settings['translation_ai_used'] ) ? $fta_settings['translation_ai_used'] : array();
	if ( $detected_locale && function_exists( 'efbl_get_locales' ) ) {
		$all_locales = efbl_get_locales();
		if ( isset( $all_locales[ $detected_locale ] ) ) {
			$locale_label = $all_locales[ $detected_locale ];
		}
	}

	// UI notice is shown for each locale only once (and never for English US);
	// actual access control (premium check, API key, etc.) is enforced in the AJAX handler.
	if ( $detected_locale && 'en_US' !== $detected_locale && $locale_label && empty( $ai_used_locales[ $detected_locale ] ) && function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) :
		?>
		<div class="notice notice-info fs-notice fs-slug-easy-facebook-likebox esf-translation-autofill-notice" style="border-left-color:#23a455;" data-esf-locale="<?php echo esc_attr( $detected_locale ); ?>">
			<p>
				<?php
				printf(
					/* translators: 1: Language label, e.g. French (France). */
					esc_html__( 'We detected that your feed language is %1$s. Use AI to instantly translate and autofill this text into your language.', 'easy-facebook-likebox' ),
					esc_html( $locale_label )
				);
				?>
			</p>
			<p>
				<button type="button" class="button button-secondary esf-translation-autofill-button">
					<?php
					printf(
						/* translators: 1: Language label, e.g. French (France). */
						esc_html__( 'Autofill %1$s text', 'easy-facebook-likebox' ),
						esc_html( $locale_label )
					);
					?>
				</button>
			</p>
		</div>
	<?php endif; ?>

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
		<button type="button" class="button button-secondary esf-translation-reset-defaults">
			<?php esc_html_e( 'Reset to default English', 'easy-facebook-likebox' ); ?>
		</button>
	</p>
</div>
