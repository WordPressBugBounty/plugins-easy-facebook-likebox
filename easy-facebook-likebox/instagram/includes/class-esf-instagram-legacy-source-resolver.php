<?php
/**
 * Resolve legacy Instagram sources (user / hashtag) onto modern feed ids.
 *
 * Shared by widget migration and legacy shortcode alias rendering.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.9.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_Legacy_Source_Resolver
 *
 * @since 6.9.6
 */
class ESF_Instagram_Legacy_Source_Resolver {

	/**
	 * Resolve or create a modern feed for a legacy source.
	 *
	 * @since 6.9.6
	 *
	 * @param array<string,mixed>              $source           Keys: user_id, hashtag, optional legacy settings.
	 * @param ESF_Instagram_Account_Repository $account_repo     Accounts.
	 * @param ESF_Instagram_Feed_Repository    $feed_repo        Feeds.
	 * @param int                              $default_feed_id  Fallback feed id.
	 * @param bool                             $allow_create     Whether to create a feed when none matches.
	 * @return array{feed_id:int,created:bool,instagram_user_id:string,hashtag:string}
	 */
	public function resolve( $source, $account_repo, $feed_repo, $default_feed_id = 0, $allow_create = true ) {
		$source  = is_array( $source ) ? $source : array();
		$hashtag = $this->normalize_hashtag( isset( $source['hashtag'] ) ? $source['hashtag'] : '' );
		$user_id = $this->extract_instagram_user_id( $source );
		$account = null;

		if ( '' !== $user_id ) {
			$account = $account_repo->get_by_instagram_user_id( $user_id );
		}

		if ( ( ! $account || ! is_object( $account ) ) && $default_feed_id > 0 ) {
			$default_feed = $feed_repo->get_by_id( absint( $default_feed_id ) );
			if ( $default_feed && is_object( $default_feed ) && ! empty( $default_feed->account_id ) ) {
				$account = $account_repo->get_by_id( (int) $default_feed->account_id );
			}
		}

		if ( ! $account || ! is_object( $account ) ) {
			$accounts = $account_repo->get_all();
			if ( ! empty( $accounts ) && is_object( $accounts[0] ) ) {
				$account = $accounts[0];
			}
		}

		$empty = array(
			'feed_id'           => absint( $default_feed_id ),
			'created'           => false,
			'instagram_user_id' => $user_id,
			'hashtag'           => $hashtag,
		);

		if ( ! $account || ! is_object( $account ) || empty( $account->id ) ) {
			return $empty;
		}

		if ( '' === $user_id && ! empty( $account->instagram_user_id ) ) {
			$user_id = preg_replace( '/[^0-9]/', '', (string) $account->instagram_user_id );
			$user_id = is_string( $user_id ) ? $user_id : '';
		}

		$account_id = (int) $account->id;
		$feed_type  = '' !== $hashtag ? 'hashtag' : 'user_timeline';
		$source_id  = '' !== $hashtag ? $hashtag : '';

		$existing = $this->find_matching_feed( $feed_repo, $account_id, $feed_type, $source_id );
		if ( $existing > 0 ) {
			return array(
				'feed_id'           => $existing,
				'created'           => false,
				'instagram_user_id' => $user_id,
				'hashtag'           => $hashtag,
			);
		}

		if ( ! $allow_create ) {
			return $empty;
		}

		$created = $this->create_feed( $source, $account, $feed_repo, $feed_type, $source_id );
		if ( $created > 0 ) {
			return array(
				'feed_id'           => $created,
				'created'           => true,
				'instagram_user_id' => $user_id,
				'hashtag'           => $hashtag,
			);
		}

		return $empty;
	}

	/**
	 * Digits-only Instagram user id from a legacy source / widget / shortcode atts.
	 *
	 * @since 6.9.6
	 *
	 * @param array<string,mixed> $source Source array.
	 * @return string
	 */
	public function extract_instagram_user_id( $source ) {
		if ( ! is_array( $source ) || ! isset( $source['user_id'] ) ) {
			return '';
		}

		$raw = $source['user_id'];
		if ( is_array( $raw ) ) {
			$raw = reset( $raw );
		}

		$id = preg_replace( '/[^0-9]/', '', (string) $raw );
		return is_string( $id ) ? $id : '';
	}

	/**
	 * Normalize hashtag (no leading #).
	 *
	 * @since 6.9.6
	 *
	 * @param mixed $hashtag Raw hashtag.
	 * @return string
	 */
	public function normalize_hashtag( $hashtag ) {
		$hashtag = trim( (string) $hashtag );
		if ( function_exists( 'esf_instagram_normalize_hashtag' ) ) {
			return (string) esf_instagram_normalize_hashtag( $hashtag );
		}
		return ltrim( strtolower( $hashtag ), '#' );
	}

	/**
	 * Stable map key for a legacy source.
	 *
	 * @since 6.9.6
	 *
	 * @param string $instagram_user_id Digits-only IG user id.
	 * @param string $hashtag           Normalized hashtag (optional).
	 * @return string
	 */
	public function map_key( $instagram_user_id, $hashtag = '' ) {
		$user = preg_replace( '/[^0-9]/', '', (string) $instagram_user_id );
		$user = is_string( $user ) ? $user : '';
		$tag  = $this->normalize_hashtag( $hashtag );

		if ( '' === $user && '' === $tag ) {
			return '';
		}

		if ( '' !== $tag ) {
			return 'u:' . $user . '|h:' . $tag;
		}

		return 'u:' . $user;
	}

	/**
	 * Find an existing feed for account + type + source.
	 *
	 * @since 6.9.6
	 *
	 * @param ESF_Instagram_Feed_Repository $feed_repo  Feeds.
	 * @param int                           $account_id Internal account id.
	 * @param string                        $feed_type  Feed type.
	 * @param string                        $source_id  Hashtag source (normalized).
	 * @return int Feed id or 0.
	 */
	public function find_matching_feed( $feed_repo, $account_id, $feed_type, $source_id ) {
		$rows = $feed_repo->get_by_account_id( (int) $account_id );
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || empty( $row->id ) ) {
				continue;
			}
			$row_type = isset( $row->feed_type ) ? (string) $row->feed_type : 'user_timeline';
			if ( $row_type !== $feed_type ) {
				continue;
			}
			$row_source = isset( $row->source_id ) ? (string) $row->source_id : '';
			if ( 'hashtag' === $feed_type ) {
				if ( $this->normalize_hashtag( $row_source ) !== $source_id ) {
					continue;
				}
			}
			return (int) $row->id;
		}

		return 0;
	}

	/**
	 * Create a modern feed from legacy source settings (best-effort).
	 *
	 * @since 6.9.6
	 *
	 * @param array<string,mixed>           $source    Legacy source / widget instance.
	 * @param object                        $account   Account row.
	 * @param ESF_Instagram_Feed_Repository $feed_repo Feeds.
	 * @param string                        $feed_type Feed type.
	 * @param string                        $source_id Source id.
	 * @return int New feed id or 0.
	 */
	public function create_feed( $source, $account, $feed_repo, $feed_type, $source_id ) {
		$account_id = (int) $account->id;
		$settings   = $this->get_default_settings_for_create();
		$source     = is_array( $source ) ? $source : array();

		$per_page = isset( $source['feeds_per_page'] ) ? absint( $source['feeds_per_page'] ) : 0;
		if ( $per_page > 0 ) {
			if ( ! isset( $settings['feed'] ) || ! is_array( $settings['feed'] ) ) {
				$settings['feed'] = array();
			}
			$settings['feed']['per_page'] = min( 50, max( 1, $per_page ) );
		}

		if ( isset( $source['links_new_tab'] ) && '' !== (string) $source['links_new_tab'] ) {
			if ( ! isset( $settings['feed'] ) || ! is_array( $settings['feed'] ) ) {
				$settings['feed'] = array();
			}
			$settings['feed']['links_new_tab'] = (bool) absint( $source['links_new_tab'] );
		}

		if ( isset( $source['load_more'] ) && '' !== (string) $source['load_more'] ) {
			if ( ! isset( $settings['feed'] ) || ! is_array( $settings['feed'] ) ) {
				$settings['feed'] = array();
			}
			$settings['feed']['load_more'] = (bool) absint( $source['load_more'] );
		}

		$caption_words = isset( $source['caption_words'] ) ? absint( $source['caption_words'] ) : 0;
		if ( $caption_words <= 0 && isset( $source['words_limit'] ) ) {
			$caption_words = absint( $source['words_limit'] );
		}
		if ( $caption_words > 0 ) {
			foreach ( array( 'half_width', 'full_width', 'masonry', 'carousel' ) as $layout_key ) {
				if ( isset( $settings['layout'][ $layout_key ] ) && is_array( $settings['layout'][ $layout_key ] ) ) {
					$settings['layout'][ $layout_key ]['caption_words'] = $caption_words;
				}
			}
		}

		$name = __( 'Instagram Feed', 'easy-facebook-likebox' );
		if ( class_exists( 'ESF_Feed_Name', false ) ) {
			$name     = ESF_Feed_Name::build_auto_feed_name_for_source(
				$account,
				$feed_type,
				$source_id,
				array(
					'hashtag_feed_type' => 'hashtag',
					'normalize_hashtag' => function_exists( 'esf_instagram_normalize_hashtag' )
						? 'esf_instagram_normalize_hashtag'
						: null,
					'account_fallback'  => $name,
					'username_field'    => 'username',
				)
			);
			$settings = ESF_Feed_Name::set_name_meta( $settings, 'auto', $account_id );
		}

		$feed_id = $feed_repo->create(
			array(
				'name'       => $name,
				'account_id' => $account_id,
				'feed_type'  => $feed_type,
				'source_id'  => $source_id,
				'settings'   => $settings,
			)
		);

		return is_int( $feed_id ) ? $feed_id : 0;
	}

	/**
	 * Default settings for feeds created from legacy sources.
	 *
	 * @since 6.9.6
	 * @return array<string,mixed>
	 */
	private function get_default_settings_for_create() {
		if ( class_exists( 'ESF_Instagram_Feed_Repository', false )
			&& is_callable( array( 'ESF_Instagram_Feed_Repository', 'get_default_settings' ) )
		) {
			return ESF_Instagram_Feed_Repository::get_default_settings();
		}

		return array(
			'layout' => array(),
			'feed'   => array(
				'per_page'      => 9,
				'links_new_tab' => true,
				'load_more'     => true,
			),
		);
	}
}
