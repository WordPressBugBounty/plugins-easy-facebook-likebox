<?php
/**
 * Modern Facebook feed widget (same id_base as legacy for seamless migration).
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_Feed_Widget', false ) ) {
	return;
}

/**
 * Class ESF_Facebook_Modern_Feed_Widget
 *
 * Uses id_base `easy_facebook_feed` so existing Appearance → Widgets /
 * Customizer placements keep working after legacy → modern switch.
 *
 * @since 6.7.9
 */
class ESF_Facebook_Modern_Feed_Widget extends ESF_Feed_Widget {

	/**
	 * Register widget.
	 *
	 * @since 6.7.9
	 */
	public function __construct() {
		parent::__construct(
			'easy_facebook_feed',
			__( 'Easy Facebook Feed', 'easy-facebook-likebox' ),
			array(
				'description' => __( 'Display a Facebook feed from Easy Social Feed.', 'easy-facebook-likebox' ),
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_module_slug() {
		return 'facebook';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_module_label() {
		return __( 'Facebook', 'easy-facebook-likebox' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_feeds_admin_url() {
		return admin_url( 'admin.php?page=esf-facebook&screen=feeds' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function can_render() {
		return class_exists( 'ESF_Facebook_Renderer', false )
			|| shortcode_exists( 'esf_facebook_feed' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<int,string>
	 */
	protected function get_feed_choices() {
		if ( ! class_exists( 'ESF_Facebook_Feed_Repository', false ) ) {
			return array();
		}

		$rows    = ESF_Facebook_Feed_Repository::get_instance()->get_all(
			array(
				'status'       => 'active',
				'content_type' => 'feed',
			)
		);
		$choices = array();

		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || empty( $row->id ) ) {
				continue;
			}
			$id   = (int) $row->id;
			$name = isset( $row->name ) ? trim( (string) $row->name ) : '';
			if ( '' === $name ) {
				$name = sprintf(
					/* translators: %d: feed id */
					__( 'Feed #%d', 'easy-facebook-likebox' ),
					$id
				);
			}
			$choices[ $id ] = $name;
		}

		return $choices;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int $feed_id Feed id.
	 * @return string
	 */
	protected function render_feed( $feed_id ) {
		$feed_id = absint( $feed_id );
		if ( $feed_id <= 0 ) {
			return '';
		}

		if ( class_exists( 'ESF_Facebook_Renderer', false ) ) {
			if ( function_exists( 'esf_facebook_flag_feed_script' ) ) {
				esf_facebook_flag_feed_script();
			}
			$renderer = new ESF_Facebook_Renderer();
			return $renderer->render( $feed_id, array(), true );
		}

		return do_shortcode( '[esf_facebook_feed id="' . $feed_id . '"]' );
	}
}

/**
 * Class ESF_Facebook_Modern_Likebox_Widget
 *
 * Inline Page Plugin settings (legacy parity). Does not use saved feed rows.
 * Same id_base as legacy page plugin widget (`easy_facebook_page_plugin`).
 *
 * @since 6.7.9
 */
class ESF_Facebook_Modern_Likebox_Widget extends WP_Widget {

	/**
	 * Register widget.
	 *
	 * @since 6.7.9
	 */
	public function __construct() {
		parent::__construct(
			'easy_facebook_page_plugin',
			__( 'Easy Facebook Likebox (page plugin)', 'easy-facebook-likebox' ),
			array(
				'description' => __( 'Display Facebook\'s official Page Plugin (Like Box). Configure the Page URL and options here, or use the Like Box tab shortcode generator.', 'easy-facebook-likebox' ),
			)
		);
	}

	/**
	 * Front-end output.
	 *
	 * @param array $args     Widget args.
	 * @param array $instance Saved settings.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		$instance = is_array( $instance ) ? $instance : array();
		$title    = isset( $instance['title'] ) ? apply_filters( 'widget_title', $instance['title'] ) : '';

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! empty( $title ) ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( class_exists( 'ESF_Facebook_Likebox_Renderer', false ) ) {
			echo ( new ESF_Facebook_Likebox_Renderer() )->render_from_legacy_atts( $instance ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Admin form.
	 *
	 * @param array $instance Saved settings.
	 * @return void
	 */
	public function form( $instance ) {
		$defaults = array(
			'title'        => '',
			'fb_appid'     => '',
			'fanpage_url'  => '',
			'box_width'    => 340,
			'box_height'   => 500,
			'show_stream'  => 0,
			'hide_cover'   => 0,
			'responsive'   => 1,
			'hide_cta'     => 0,
			'small_header' => 0,
			'show_faces'   => 0,
			'locale'       => 'en_US',
		);

		if ( function_exists( 'efl_fs' ) && efl_fs()->can_use_premium_code__premium_only() ) {
			if ( efl_fs()->is_plan( 'facebook_premium', true ) || efl_fs()->is_plan( 'combo_premium', true ) ) {
				$defaults['efbl_tabs_timeline'] = 0;
				$defaults['efbl_tabs_events']   = 0;
				$defaults['efbl_tabs_messages'] = 0;
			}
		}

		$instance = wp_parse_args( (array) $instance, $defaults );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'easy-facebook-likebox' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( (string) $instance['title'] ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'fanpage_url' ) ); ?>"><?php esc_html_e( 'Facebook Page URL:', 'easy-facebook-likebox' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'fanpage_url' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'fanpage_url' ) ); ?>" type="url" value="<?php echo esc_attr( (string) $instance['fanpage_url'] ); ?>">
			<small><?php esc_html_e( 'Full URL of your Facebook Page.', 'easy-facebook-likebox' ); ?></small>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'box_width' ) ); ?>"><?php esc_html_e( 'Width (px):', 'easy-facebook-likebox' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'box_width' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'box_width' ) ); ?>" type="number" min="180" max="500" value="<?php echo esc_attr( (string) $instance['box_width'] ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'box_height' ) ); ?>"><?php esc_html_e( 'Height (px):', 'easy-facebook-likebox' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'box_height' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'box_height' ) ); ?>" type="number" min="70" max="1000" value="<?php echo esc_attr( (string) $instance['box_height'] ); ?>">
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'responsive' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'responsive' ) ); ?>" value="1" <?php checked( ! empty( $instance['responsive'] ) ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'responsive' ) ); ?>"><?php esc_html_e( 'Responsive', 'easy-facebook-likebox' ); ?></label>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_faces' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_faces' ) ); ?>" value="1" <?php checked( ! empty( $instance['show_faces'] ) ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_faces' ) ); ?>"><?php esc_html_e( 'Show faces', 'easy-facebook-likebox' ); ?></label>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_stream' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_stream' ) ); ?>" value="1" <?php checked( ! empty( $instance['show_stream'] ) ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_stream' ) ); ?>"><?php esc_html_e( 'Show posts stream', 'easy-facebook-likebox' ); ?></label>
		</p>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=esf-facebook&screen=likebox' ) ); ?>"><?php esc_html_e( 'Open Like Box shortcode generator', 'easy-facebook-likebox' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Sanitize widget settings.
	 *
	 * @param array $new_instance New settings.
	 * @param array $old_instance Old settings.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		$instance                 = array();
		$instance['title']        = sanitize_text_field( (string) ( $new_instance['title'] ?? '' ) );
		$instance['fanpage_url']  = esc_url_raw( (string) ( $new_instance['fanpage_url'] ?? '' ) );
		$instance['box_width']    = max( 180, min( 500, (int) ( $new_instance['box_width'] ?? 340 ) ) );
		$instance['box_height']   = max( 70, min( 1000, (int) ( $new_instance['box_height'] ?? 500 ) ) );
		$instance['responsive']   = ! empty( $new_instance['responsive'] ) ? 1 : 0;
		$instance['show_faces']   = ! empty( $new_instance['show_faces'] ) ? 1 : 0;
		$instance['show_stream']  = ! empty( $new_instance['show_stream'] ) ? 1 : 0;
		$instance['hide_cover']   = ! empty( $new_instance['hide_cover'] ) ? 1 : 0;
		$instance['small_header'] = ! empty( $new_instance['small_header'] ) ? 1 : 0;
		$instance['hide_cta']     = ! empty( $new_instance['hide_cta'] ) ? 1 : 0;
		$instance['fb_appid']     = sanitize_text_field( (string) ( $new_instance['fb_appid'] ?? '' ) );
		$instance['locale']       = sanitize_text_field( (string) ( $new_instance['locale'] ?? 'en_US' ) );

		return $instance;
	}
}
