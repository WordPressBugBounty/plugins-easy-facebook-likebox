<?php
/**
 * Shared modern module feed WP_Widget base.
 *
 * Modules extend this with a stable `id_base`, shortcode/renderer, and feed list.
 * Keeping the same `id_base` as a legacy widget preserves sidebar placements.
 *
 * @package Easy_Social_Feed
 * @since   6.9.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_Widget', false ) ) {
	return;
}

/**
 * Abstract base for feed-id widgets across modern ESF modules.
 *
 * @since 6.9.6
 */
abstract class ESF_Feed_Widget extends WP_Widget {

	/**
	 * Module slug (instagram, youtube, …) for copy and admin links.
	 *
	 * @since 6.9.6
	 * @return string
	 */
	abstract protected function get_module_slug();

	/**
	 * Human-readable module label.
	 *
	 * @since 6.9.6
	 * @return string
	 */
	abstract protected function get_module_label();

	/**
	 * Admin URL for the module feeds screen.
	 *
	 * @since 6.9.6
	 * @return string
	 */
	abstract protected function get_feeds_admin_url();

	/**
	 * Whether the module frontend is ready to render.
	 *
	 * @since 6.9.6
	 * @return bool
	 */
	abstract protected function can_render();

	/**
	 * Feed choices for the widget form: feed_id => label.
	 *
	 * @since 6.9.6
	 * @return array<int,string>
	 */
	abstract protected function get_feed_choices();

	/**
	 * Render feed HTML for the given modern feed id.
	 *
	 * @since 6.9.6
	 *
	 * @param int $feed_id Feed id.
	 * @return string
	 */
	abstract protected function render_feed( $feed_id );

	/**
	 * Front-end display.
	 *
	 * @since 6.9.6
	 *
	 * @param array<string,mixed> $args     Widget display args.
	 * @param array<string,mixed> $instance Saved instance.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		if ( ! $this->can_render() ) {
			return;
		}

		$feed_id = isset( $instance['feed_id'] ) ? absint( $instance['feed_id'] ) : 0;
		if ( $feed_id <= 0 ) {
			return;
		}

		$title = isset( $instance['title'] ) ? apply_filters( 'widget_title', (string) $instance['title'] ) : '';

		$before_widget = isset( $args['before_widget'] ) ? (string) $args['before_widget'] : '';
		$after_widget  = isset( $args['after_widget'] ) ? (string) $args['after_widget'] : '';
		$before_title  = isset( $args['before_title'] ) ? (string) $args['before_title'] : '';
		$after_title   = isset( $args['after_title'] ) ? (string) $args['after_title'] : '';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme widget chrome.
		echo $before_widget;

		if ( '' !== $title ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme title chrome; title filtered above.
			echo $before_title . $title . $after_title;
		}

		// Feed HTML is produced by the module renderer (escaped internally).
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->render_feed( $feed_id );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $after_widget;
	}

	/**
	 * Admin form.
	 *
	 * @since 6.9.6
	 *
	 * @param array<string,mixed> $instance Saved instance.
	 * @return void
	 */
	public function form( $instance ) {
		$instance = wp_parse_args(
			(array) $instance,
			array(
				'title'   => '',
				'feed_id' => 0,
			)
		);

		$title   = isset( $instance['title'] ) ? (string) $instance['title'] : '';
		$feed_id = isset( $instance['feed_id'] ) ? absint( $instance['feed_id'] ) : 0;
		$feeds   = $this->get_feed_choices();
		$label   = $this->get_module_label();
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title:', 'easy-facebook-likebox' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $title ); ?>"
			/>
		</p>
		<?php if ( empty( $feeds ) ) : ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: module name */
						__( 'No %s feeds found. Create a feed in the admin, then select it here.', 'easy-facebook-likebox' ),
						$label
					)
				);
				?>
				<a href="<?php echo esc_url( $this->get_feeds_admin_url() ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: module name */
							__( 'Open %s feeds', 'easy-facebook-likebox' ),
							$label
						)
					);
					?>
				</a>
			</p>
		<?php else : ?>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'feed_id' ) ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: module name */
							__( 'Select %s feed:', 'easy-facebook-likebox' ),
							$label
						)
					);
					?>
				</label>
				<select
					class="widefat"
					id="<?php echo esc_attr( $this->get_field_id( 'feed_id' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'feed_id' ) ); ?>"
				>
					<option value="0"><?php esc_html_e( '— Select —', 'easy-facebook-likebox' ); ?></option>
					<?php foreach ( $feeds as $id => $name ) : ?>
						<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $feed_id, (int) $id ); ?>>
							<?php echo esc_html( (string) $name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="description">
				<a href="<?php echo esc_url( $this->get_feeds_admin_url() ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: module name */
							__( 'Manage %s feeds', 'easy-facebook-likebox' ),
							$label
						)
					);
					?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Sanitize instance on save.
	 *
	 * @since 6.9.6
	 *
	 * @param array<string,mixed> $new_instance New values.
	 * @param array<string,mixed> $old_instance Previous values.
	 * @return array<string,mixed>
	 */
	public function update( $new_instance, $old_instance ) {
		unset( $old_instance );

		$instance            = array();
		$instance['title']   = isset( $new_instance['title'] ) ? sanitize_text_field( (string) $new_instance['title'] ) : '';
		$instance['feed_id'] = isset( $new_instance['feed_id'] ) ? absint( $new_instance['feed_id'] ) : 0;

		return $instance;
	}
}
