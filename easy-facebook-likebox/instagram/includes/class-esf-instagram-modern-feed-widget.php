<?php
/**
 * Modern Instagram feed widget (same id_base as legacy for seamless migration).
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.9.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_Feed_Widget', false ) ) {
	return;
}

/**
 * Class ESF_Instagram_Modern_Feed_Widget
 *
 * Uses id_base `esf_instagram_feed` so existing Appearance → Widgets /
 * Customizer placements keep working after legacy → modern switch.
 *
 * @since 6.9.6
 */
class ESF_Instagram_Modern_Feed_Widget extends ESF_Feed_Widget {

	/**
	 * Register widget.
	 *
	 * @since 6.9.6
	 */
	public function __construct() {
		parent::__construct(
			'esf_instagram_feed',
			__( 'Easy Instagram Feed', 'easy-facebook-likebox' ),
			array(
				'description' => __( 'Display an Instagram feed from Easy Social Feed.', 'easy-facebook-likebox' ),
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_module_slug() {
		return 'instagram';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_module_label() {
		return __( 'Instagram', 'easy-facebook-likebox' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_feeds_admin_url() {
		return admin_url( 'admin.php?page=esf-instagram&screen=feeds' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function can_render() {
		return class_exists( 'ESF_Instagram_Renderer', false )
			|| shortcode_exists( 'esf_instagram_feed' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<int,string>
	 */
	protected function get_feed_choices() {
		if ( ! class_exists( 'ESF_Instagram_Feed_Repository', false ) ) {
			return array();
		}

		$rows    = ESF_Instagram_Feed_Repository::get_instance()->get_all( array( 'status' => 'active' ) );
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

		if ( class_exists( 'ESF_Instagram_Renderer', false ) ) {
			if ( function_exists( 'esf_instagram_flag_feed_script' ) ) {
				esf_instagram_flag_feed_script();
			}
			$renderer = new ESF_Instagram_Renderer();
			return $renderer->render( $feed_id, array(), true );
		}

		return do_shortcode( '[esf_instagram_feed id="' . $feed_id . '"]' );
	}
}
