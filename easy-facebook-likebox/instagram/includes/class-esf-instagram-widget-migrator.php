<?php
/**
 * Remap legacy Instagram widget instances to modern feed ids.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.9.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_Widget_Migrator
 *
 * Scans `widget_esf_instagram_feed` and assigns a modern `feed_id` per
 * instance (creating feeds when needed). Safe to run multiple times.
 *
 * @since 6.9.6
 */
class ESF_Instagram_Widget_Migrator {

	/**
	 * Option key for the WP widget instances (matches legacy / modern id_base).
	 *
	 * @since 6.9.6
	 * @var string
	 */
	const OPTION_KEY = 'widget_esf_instagram_feed';

	/**
	 * Flag once widgets have been remapped on a modern install.
	 *
	 * @since 6.9.6
	 * @var string
	 */
	const DONE_OPTION = 'esf_instagram_widgets_migrated';

	/**
	 * @var ESF_Instagram_Legacy_Source_Resolver
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @since 6.9.6
	 *
	 * @param ESF_Instagram_Legacy_Source_Resolver|null $resolver Optional shared resolver.
	 */
	public function __construct( $resolver = null ) {
		if ( ! class_exists( 'ESF_Instagram_Legacy_Source_Resolver', false ) && defined( 'ESF_INSTAGRAM_DIR' ) ) {
			require_once ESF_INSTAGRAM_DIR . 'includes/class-esf-instagram-legacy-source-resolver.php';
		}
		$this->resolver = $resolver instanceof ESF_Instagram_Legacy_Source_Resolver
			? $resolver
			: new ESF_Instagram_Legacy_Source_Resolver();
	}

	/**
	 * Remap all legacy widget instances to modern feed ids.
	 *
	 * @since 6.9.6
	 *
	 * @param int                                   $default_feed_id Fallback feed when account cannot be resolved.
	 * @param ESF_Instagram_Account_Repository|null $account_repo    Optional account repository (tests).
	 * @param ESF_Instagram_Feed_Repository|null    $feed_repo       Optional feed repository (tests).
	 * @return array{updated:int,created_feeds:int,skipped:int}
	 */
	public function migrate_widgets( $default_feed_id = 0, $account_repo = null, $feed_repo = null ) {
		$stats = array(
			'updated'       => 0,
			'created_feeds' => 0,
			'skipped'       => 0,
		);

		if ( null === $feed_repo ) {
			if ( ! class_exists( 'ESF_Instagram_Feed_Repository', false ) ) {
				return $stats;
			}
			$feed_repo = ESF_Instagram_Feed_Repository::get_instance();
		}

		if ( null === $account_repo ) {
			if ( ! class_exists( 'ESF_Instagram_Account_Repository', false ) ) {
				return $stats;
			}
			$account_repo = ESF_Instagram_Account_Repository::get_instance();
		}

		$widgets = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $widgets ) || empty( $widgets ) ) {
			update_option( self::DONE_OPTION, '1', false );
			return $stats;
		}

		$default_feed_id = absint( $default_feed_id );
		if ( $default_feed_id <= 0 && function_exists( 'esf_instagram_get_migration_default_feed_id' ) ) {
			$default_feed_id = absint( esf_instagram_get_migration_default_feed_id() );
		}

		$changed = false;

		foreach ( $widgets as $number => $instance ) {
			if ( ! is_numeric( $number ) || ! is_array( $instance ) ) {
				continue;
			}

			$existing_feed_id = isset( $instance['feed_id'] ) ? absint( $instance['feed_id'] ) : 0;
			if ( $existing_feed_id > 0 && $feed_repo->get_by_id( $existing_feed_id ) ) {
				++$stats['skipped'];
				continue;
			}

			$result  = $this->resolve_feed_for_instance( $instance, $account_repo, $feed_repo, $default_feed_id );
			$feed_id = isset( $result['feed_id'] ) ? absint( $result['feed_id'] ) : 0;

			if ( $feed_id <= 0 ) {
				++$stats['skipped'];
				continue;
			}

			if ( ! empty( $result['created'] ) ) {
				++$stats['created_feeds'];
			}

			$widgets[ $number ]['feed_id'] = $feed_id;
			++$stats['updated'];
			$changed = true;
		}

		if ( $changed ) {
			update_option( self::OPTION_KEY, $widgets, false );
		}

		update_option( self::DONE_OPTION, '1', false );

		return $stats;
	}

	/**
	 * Resolve or create a modern feed for one legacy widget instance.
	 *
	 * @since 6.9.6
	 *
	 * @param array<string,mixed>              $instance      Widget instance.
	 * @param ESF_Instagram_Account_Repository $account_repo  Accounts.
	 * @param ESF_Instagram_Feed_Repository    $feed_repo     Feeds.
	 * @param int                              $default_feed_id Fallback feed id.
	 * @return array{feed_id:int,created:bool}
	 */
	public function resolve_feed_for_instance( $instance, $account_repo, $feed_repo, $default_feed_id = 0 ) {
		$result = $this->resolver->resolve( $instance, $account_repo, $feed_repo, $default_feed_id, true );

		return array(
			'feed_id' => isset( $result['feed_id'] ) ? absint( $result['feed_id'] ) : 0,
			'created' => ! empty( $result['created'] ),
		);
	}

	/**
	 * Pull a single Instagram user id from a legacy widget instance.
	 *
	 * @since 6.9.6
	 *
	 * @param array<string,mixed> $instance Widget instance.
	 * @return string Digits-only id or empty.
	 */
	public function extract_instagram_user_id( $instance ) {
		return $this->resolver->extract_instagram_user_id( $instance );
	}
}
