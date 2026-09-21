<?php
/**
 * Persist legacy shortcode/widget source → modern feed id aliases.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.9.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_Legacy_Feed_Map
 *
 * Built during migrate (and once for already-modern sites). Used by
 * `[my-instagram-feed]` so distinct account/hashtag embeds keep working.
 *
 * @since 6.9.6
 */
class ESF_Instagram_Legacy_Feed_Map {

	/**
	 * Option storing map keys → feed ids.
	 *
	 * @since 6.9.6
	 * @var string
	 */
	const OPTION_KEY = 'esf_instagram_legacy_feed_map';

	/**
	 * Flag once the map has been built on a modern install.
	 *
	 * @since 6.9.6
	 * @var string
	 */
	const DONE_OPTION = 'esf_instagram_legacy_feed_map_built';

	/**
	 * @var ESF_Instagram_Legacy_Source_Resolver
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @since 6.9.6
	 *
	 * @param ESF_Instagram_Legacy_Source_Resolver|null $resolver Optional resolver.
	 */
	public function __construct( $resolver = null ) {
		$this->resolver = $resolver instanceof ESF_Instagram_Legacy_Source_Resolver
			? $resolver
			: new ESF_Instagram_Legacy_Source_Resolver();
	}

	/**
	 * Whether a non-empty map is stored.
	 *
	 * @since 6.9.6
	 * @return bool
	 */
	public static function has_entries() {
		$map = get_option( self::OPTION_KEY, array() );
		return is_array( $map ) && ! empty( $map );
	}

	/**
	 * Read the stored map.
	 *
	 * @since 6.9.6
	 * @return array<string,int>
	 */
	public function get_map() {
		$map = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $map ) ) {
			return array();
		}

		$out = array();
		foreach ( $map as $key => $feed_id ) {
			$key     = sanitize_text_field( (string) $key );
			$feed_id = absint( $feed_id );
			if ( '' === $key || $feed_id <= 0 ) {
				continue;
			}
			$out[ $key ] = $feed_id;
		}

		return $out;
	}

	/**
	 * Persist map.
	 *
	 * @since 6.9.6
	 *
	 * @param array<string,int> $map Map.
	 * @return void
	 */
	public function save_map( array $map ) {
		update_option( self::OPTION_KEY, $map, false );
	}

	/**
	 * Remember one source → feed mapping.
	 *
	 * @since 6.9.6
	 *
	 * @param string $instagram_user_id IG user id.
	 * @param string $hashtag           Normalized hashtag.
	 * @param int    $feed_id           Feed id.
	 * @return void
	 */
	public function remember( $instagram_user_id, $hashtag, $feed_id ) {
		$feed_id = absint( $feed_id );
		$key     = $this->resolver->map_key( $instagram_user_id, $hashtag );
		if ( '' === $key || $feed_id <= 0 ) {
			return;
		}

		$map         = $this->get_map();
		$map[ $key ] = $feed_id;
		$this->save_map( $map );
	}

	/**
	 * Look up a feed id for legacy shortcode attributes.
	 *
	 * Does not create feeds on the public front end — only map + existing matches.
	 *
	 * @since 6.9.6
	 *
	 * @param array<string,mixed>                   $atts             Shortcode atts.
	 * @param int                                   $default_feed_id  Fallback.
	 * @param ESF_Instagram_Account_Repository|null $account_repo Optional.
	 * @param ESF_Instagram_Feed_Repository|null    $feed_repo    Optional.
	 * @return int
	 */
	public function resolve_shortcode_atts( $atts, $default_feed_id = 0, $account_repo = null, $feed_repo = null ) {
		$atts    = is_array( $atts ) ? $atts : array();
		$user_id = $this->resolver->extract_instagram_user_id( $atts );
		$hashtag = $this->resolver->normalize_hashtag( isset( $atts['hashtag'] ) ? $atts['hashtag'] : '' );
		$key     = $this->resolver->map_key( $user_id, $hashtag );

		$map = $this->get_map();
		if ( '' !== $key && isset( $map[ $key ] ) ) {
			$mapped = absint( $map[ $key ] );
			if ( $mapped > 0 ) {
				return $mapped;
			}
		}

		// Hashtag embed without user in map — try any key ending with this hashtag.
		if ( '' !== $hashtag ) {
			$suffix = '|h:' . $hashtag;
			foreach ( $map as $map_key => $feed_id ) {
				if ( substr( (string) $map_key, -strlen( $suffix ) ) === $suffix ) {
					$mapped = absint( $feed_id );
					if ( $mapped > 0 ) {
						return $mapped;
					}
				}
			}
		}

		if ( null === $feed_repo || null === $account_repo ) {
			if ( ! class_exists( 'ESF_Instagram_Feed_Repository', false )
				|| ! class_exists( 'ESF_Instagram_Account_Repository', false )
			) {
				return absint( $default_feed_id );
			}
			$feed_repo    = null === $feed_repo ? ESF_Instagram_Feed_Repository::get_instance() : $feed_repo;
			$account_repo = null === $account_repo ? ESF_Instagram_Account_Repository::get_instance() : $account_repo;
		}

		$result = $this->resolver->resolve(
			array(
				'user_id' => $user_id,
				'hashtag' => $hashtag,
			),
			$account_repo,
			$feed_repo,
			absint( $default_feed_id ),
			false
		);

		$feed_id = isset( $result['feed_id'] ) ? absint( $result['feed_id'] ) : 0;
		return $feed_id > 0 ? $feed_id : absint( $default_feed_id );
	}

	/**
	 * Build / refresh the map from accounts, existing feeds, and widgets.
	 *
	 * Creates a user-timeline feed per account when missing so multi-account
	 * shortcodes can resolve correctly.
	 *
	 * @since 6.9.6
	 *
	 * @param int                                   $default_feed_id Fallback feed.
	 * @param ESF_Instagram_Account_Repository|null $account_repo    Optional.
	 * @param ESF_Instagram_Feed_Repository|null    $feed_repo       Optional.
	 * @return array{mapped:int,created_feeds:int}
	 */
	public function build( $default_feed_id = 0, $account_repo = null, $feed_repo = null ) {
		$stats = array(
			'mapped'        => 0,
			'created_feeds' => 0,
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

		$default_feed_id = absint( $default_feed_id );
		if ( $default_feed_id <= 0 && function_exists( 'esf_instagram_get_migration_default_feed_id' ) ) {
			$default_feed_id = absint( esf_instagram_get_migration_default_feed_id() );
		}

		$map = $this->get_map();

		foreach ( $account_repo->get_all() as $account ) {
			if ( ! is_object( $account ) || empty( $account->id ) || empty( $account->instagram_user_id ) ) {
				continue;
			}

			$ig_id = preg_replace( '/[^0-9]/', '', (string) $account->instagram_user_id );
			$ig_id = is_string( $ig_id ) ? $ig_id : '';
			if ( '' === $ig_id ) {
				continue;
			}

			$result = $this->resolver->resolve(
				array( 'user_id' => $ig_id ),
				$account_repo,
				$feed_repo,
				$default_feed_id,
				true
			);

			$feed_id = isset( $result['feed_id'] ) ? absint( $result['feed_id'] ) : 0;
			if ( $feed_id <= 0 ) {
				continue;
			}
			if ( ! empty( $result['created'] ) ) {
				++$stats['created_feeds'];
			}

			$key = $this->resolver->map_key( $ig_id, '' );
			if ( '' !== $key ) {
				$map[ $key ] = $feed_id;
				++$stats['mapped'];
			}
		}

		// Index existing hashtag feeds.
		foreach ( $feed_repo->get_all() as $feed ) {
			if ( ! is_object( $feed ) || empty( $feed->id ) ) {
				continue;
			}
			$feed_type = isset( $feed->feed_type ) ? (string) $feed->feed_type : '';
			if ( 'hashtag' !== $feed_type ) {
				continue;
			}
			$tag = $this->resolver->normalize_hashtag( isset( $feed->source_id ) ? $feed->source_id : '' );
			if ( '' === $tag ) {
				continue;
			}

			$ig_id = '';
			if ( ! empty( $feed->account_id ) ) {
				$account = $account_repo->get_by_id( (int) $feed->account_id );
				if ( $account && is_object( $account ) && ! empty( $account->instagram_user_id ) ) {
					$ig_id = preg_replace( '/[^0-9]/', '', (string) $account->instagram_user_id );
					$ig_id = is_string( $ig_id ) ? $ig_id : '';
				}
			}

			$key = $this->resolver->map_key( $ig_id, $tag );
			if ( '' !== $key ) {
				$map[ $key ] = (int) $feed->id;
				++$stats['mapped'];
			}
		}

		// Widget instances (legacy attrs or already remapped feed_id).
		$widgets = get_option( 'widget_esf_instagram_feed', array() );
		if ( is_array( $widgets ) ) {
			foreach ( $widgets as $number => $instance ) {
				if ( ! is_numeric( $number ) || ! is_array( $instance ) ) {
					continue;
				}

				$feed_id = isset( $instance['feed_id'] ) ? absint( $instance['feed_id'] ) : 0;
				$user_id = $this->resolver->extract_instagram_user_id( $instance );
				$hashtag = $this->resolver->normalize_hashtag( isset( $instance['hashtag'] ) ? $instance['hashtag'] : '' );

				if ( $feed_id <= 0 ) {
					$result  = $this->resolver->resolve( $instance, $account_repo, $feed_repo, $default_feed_id, true );
					$feed_id = isset( $result['feed_id'] ) ? absint( $result['feed_id'] ) : 0;
					if ( ! empty( $result['created'] ) ) {
						++$stats['created_feeds'];
					}
					if ( '' === $user_id && ! empty( $result['instagram_user_id'] ) ) {
						$user_id = (string) $result['instagram_user_id'];
					}
				}

				$key = $this->resolver->map_key( $user_id, $hashtag );
				if ( '' !== $key && $feed_id > 0 ) {
					$map[ $key ] = $feed_id;
					++$stats['mapped'];
				}
			}
		}

		$this->save_map( $map );
		update_option( self::DONE_OPTION, '1', false );

		return $stats;
	}
}
