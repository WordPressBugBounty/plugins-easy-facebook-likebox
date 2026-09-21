<?php
/**
 * Facebook Legacy-to-Modern Migrator
 *
 * Copies approved_pages into esf_facebook_accounts / esf_facebook_pages,
 * builds default feeds + legacy feed map, remaps widgets. Never deletes fta_settings.
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Migrator
 *
 * @since 6.7.9
 */
class ESF_Facebook_Migrator {

	use ESF_Facebook_Singleton;

	/**
	 * Mutex option (Unix timestamp). Stale after LOCK_TTL_SECONDS.
	 *
	 * @since 6.7.9
	 * @var string
	 */
	const LOCK_OPTION = 'esf_facebook_migrate_lock';

	/**
	 * Max lock lifetime before it is considered stale.
	 *
	 * @since 6.7.9
	 * @var int
	 */
	const LOCK_TTL_SECONDS = 120;

	/**
	 * Default wall-clock budget (seconds) for outbound Graph probes in one migrate() run.
	 *
	 * @since 6.7.9
	 * @var float
	 */
	const DEFAULT_NETWORK_TIME_BUDGET = 20.0;

	/**
	 * Monotonic deadline for outbound Graph calls, or null outside a run.
	 *
	 * @since 6.7.9
	 * @var float|null
	 */
	private $network_deadline = null;

	/**
	 * Attempt to acquire the migration lock.
	 *
	 * @since 6.7.9
	 * @return bool
	 */
	private function acquire_migration_lock() {
		$existing = get_option( self::LOCK_OPTION, 0 );
		$existing = is_numeric( $existing ) ? (int) $existing : 0;

		if ( $existing > 0 && ( time() - $existing ) < self::LOCK_TTL_SECONDS ) {
			return false;
		}

		update_option( self::LOCK_OPTION, time(), false );
		return true;
	}

	/**
	 * Release the migration lock.
	 *
	 * @since 6.7.9
	 * @return void
	 */
	private function release_migration_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Result when migrate() is skipped because another run holds the lock.
	 *
	 * @since 6.7.9
	 * @return array<string,mixed>
	 */
	private function migration_already_running_result() {
		return array(
			'migrated'          => 0,
			'skipped'           => 0,
			'default_feed_id'   => (int) get_option( 'esf_facebook_migration_default_feed_id', 0 ),
			'widgets_updated'   => 0,
			'widget_feeds'      => 0,
			'map_entries'       => 0,
			'map_feeds'         => 0,
			'rewrite_status'    => '',
			'rewrite_updated'   => 0,
			'rewrite_scheduled' => false,
			'already_running'   => true,
		);
	}

	/**
	 * Filterable network time budget (clamped 5–60s).
	 *
	 * @since 6.7.9
	 * @return float
	 */
	private function network_time_budget() {
		$budget = apply_filters( 'esf_facebook_migrate_network_time_budget', self::DEFAULT_NETWORK_TIME_BUDGET );
		$budget = is_numeric( $budget ) ? (float) $budget : self::DEFAULT_NETWORK_TIME_BUDGET;

		return max( 5.0, min( 60.0, $budget ) );
	}

	/**
	 * Whether the network budget for the current run is spent.
	 *
	 * @since 6.7.9
	 * @return bool
	 */
	private function network_budget_exceeded() {
		return null !== $this->network_deadline && microtime( true ) >= $this->network_deadline;
	}

	/**
	 * Resolve Facebook user id from legacy fta_settings author blob.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed> $fta_settings Full fta_settings option.
	 * @return string
	 */
	private function resolve_facebook_user_id( $fta_settings ) {
		$author = isset( $fta_settings['plugins']['facebook']['author'] )
			? $fta_settings['plugins']['facebook']['author']
			: null;
		if ( is_object( $author ) && isset( $author->id ) ) {
			$id = sanitize_text_field( (string) $author->id );
			if ( '' !== $id ) {
				return $id;
			}
		}
		if ( is_array( $author ) && isset( $author['id'] ) ) {
			$id = sanitize_text_field( (string) $author['id'] );
			if ( '' !== $id ) {
				return $id;
			}
		}
		return 'legacy';
	}

	/**
	 * Classify a Graph probe response for migration token health.
	 *
	 * @since 6.7.9
	 * @param array<string,mixed>|WP_Error $response wp_remote_get() result.
	 * @return string `valid`, `invalid`, or `unknown`.
	 */
	private function classify_token_probe_response( $response ) {
		if ( class_exists( 'ESF_Meta_Graph_Error' ) ) {
			return ESF_Meta_Graph_Error::classify_token_probe_response( $response );
		}

		if ( is_wp_error( $response ) ) {
			return 'unknown';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), false );

		if ( $code >= 200 && $code < 300 && is_object( $body ) && ! isset( $body->error ) ) {
			return 'valid';
		}

		if ( ! is_object( $body ) || ! isset( $body->error ) ) {
			return 'unknown';
		}

		$err_code = isset( $body->error->code ) ? (int) $body->error->code : 0;
		if ( in_array( $err_code, array( 102, 190, 463, 467 ), true ) ) {
			return 'invalid';
		}

		return 'unknown';
	}

	/**
	 * Probe a page access token against Graph /{page-id}?fields=id.
	 *
	 * @since 6.7.9
	 * @param string $page_access_token Page token.
	 * @param string $page_id           Facebook Page id.
	 * @return string
	 */
	private function probe_page_token( $page_access_token, $page_id ) {
		$page_access_token = trim( (string) $page_access_token );
		$page_id           = preg_replace( '/[^0-9]/', '', (string) $page_id );
		if ( '' === $page_access_token || '' === $page_id ) {
			return 'invalid';
		}

		$url = add_query_arg(
			array(
				'fields'       => 'id',
				'access_token' => $page_access_token,
			),
			sprintf( 'https://graph.facebook.com/v18.0/%s', rawurlencode( $page_id ) )
		);

		return $this->classify_token_probe_response(
			wp_remote_get( $url, array( 'timeout' => 15 ) )
		);
	}

	/**
	 * Migrate legacy Facebook data into modern tables.
	 *
	 * Idempotent; never deletes fta_settings. Guarded by mutex + network budget.
	 *
	 * @since 6.7.9
	 * @return array<string,mixed>
	 */
	public function migrate() {
		if ( ! $this->acquire_migration_lock() ) {
			return $this->migration_already_running_result();
		}

		try {
			return $this->run_migration();
		} finally {
			$this->release_migration_lock();
			$this->network_deadline = null;
		}
	}

	/**
	 * Migration body while lock is held.
	 *
	 * @since 6.7.9
	 * @return array<string,mixed>
	 */
	private function run_migration() {
		$this->network_deadline = microtime( true ) + $this->network_time_budget();

		$fta_settings = get_option( 'fta_settings', array() );
		if ( ! is_array( $fta_settings ) ) {
			$fta_settings = array();
		}

		$migrated = 0;
		$skipped  = 0;
		$user_id  = (int) get_current_user_id();

		$account_repo = ESF_Facebook_Account_Repository::get_instance();
		$page_repo    = ESF_Facebook_Page_Repository::get_instance();

		$facebook_uid        = $this->resolve_facebook_user_id( $fta_settings );
		$legacy_events_token = isset( $fta_settings['plugins']['facebook']['access_token'] )
			? sanitize_text_field( (string) $fta_settings['plugins']['facebook']['access_token'] )
			: '';

		$pages = isset( $fta_settings['plugins']['facebook']['approved_pages'] )
			? $fta_settings['plugins']['facebook']['approved_pages']
			: array();

		$has_oauth_page_tokens = false;
		if ( is_array( $pages ) ) {
			foreach ( $pages as $legacy_page ) {
				if ( is_object( $legacy_page ) ) {
					$legacy_page = json_decode( wp_json_encode( $legacy_page ), true );
				}
				if ( ! is_array( $legacy_page ) ) {
					continue;
				}
				$page_token = isset( $legacy_page['access_token'] )
					? sanitize_text_field( (string) $legacy_page['access_token'] )
					: '';
				if ( '' !== $page_token ) {
					$has_oauth_page_tokens = true;
					break;
				}
			}
		}

		$auth_oauth  = class_exists( 'ESF_Manual_Access_Token' ) ? ESF_Manual_Access_Token::AUTH_OAUTH : 'oauth';
		$auth_manual = class_exists( 'ESF_Manual_Access_Token' ) ? ESF_Manual_Access_Token::AUTH_MANUAL : 'manual';

		$auth_source = $auth_oauth;
		if ( ! $has_oauth_page_tokens && '' !== $legacy_events_token ) {
			$auth_source = $auth_manual;
		}

		$account_user_token = ( ! $has_oauth_page_tokens && '' !== $legacy_events_token ) ? $legacy_events_token : null;

		$account_id = $account_repo->upsert(
			array(
				'user_id'             => $user_id > 0 ? $user_id : 0,
				'facebook_user_id'    => $facebook_uid,
				'facebook_user_token' => $account_user_token,
				'auth_source'         => $auth_source,
				'status'              => 'active',
			)
		);

		if ( false === $account_id || (int) $account_id <= 0 ) {
			++$skipped;
		}

		$page_row_ids = array();

		if ( is_array( $pages ) && false !== $account_id && (int) $account_id > 0 ) {
			foreach ( $pages as $legacy_key => $legacy_page ) {
				if ( is_object( $legacy_page ) ) {
					$legacy_page = json_decode( wp_json_encode( $legacy_page ), true );
				}
				if ( ! is_array( $legacy_page ) ) {
					++$skipped;
					continue;
				}

				$page_id = '';
				if ( isset( $legacy_page['id'] ) ) {
					$page_id = sanitize_text_field( (string) $legacy_page['id'] );
				}
				if ( '' === $page_id && is_scalar( $legacy_key ) ) {
					$page_id = sanitize_text_field( (string) $legacy_key );
				}
				if ( '' === $page_id ) {
					++$skipped;
					continue;
				}

				$page_token = isset( $legacy_page['access_token'] )
					? sanitize_text_field( (string) $legacy_page['access_token'] )
					: '';

				if ( '' === $page_token && ! $has_oauth_page_tokens && '' !== $legacy_events_token ) {
					$page_token = $legacy_events_token;
				}

				if ( '' === $page_token ) {
					$token_status = 'invalid';
				} elseif ( $this->network_budget_exceeded() ) {
					$token_status = 'unknown';
				} else {
					$token_status = $this->probe_page_token( $page_token, $page_id );
				}

				$status = 'active';
				if ( 'invalid' === $token_status ) {
					$status = 'invalid';
				}

				$row_id = $page_repo->upsert(
					array(
						'account_id'          => (int) $account_id,
						'page_id'             => $page_id,
						'page_name'           => isset( $legacy_page['name'] )
							? sanitize_text_field( (string) $legacy_page['name'] )
							: $page_id,
						'username'            => isset( $legacy_page['username'] )
							? sanitize_text_field( (string) $legacy_page['username'] )
							: null,
						'category'            => isset( $legacy_page['category'] )
							? sanitize_text_field( (string) $legacy_page['category'] )
							: null,
						'fan_count'           => isset( $legacy_page['fan_count'] ) ? (int) $legacy_page['fan_count'] : 0,
						'page_access_token'   => '' !== $page_token ? $page_token : null,
						'events_access_token' => ( $has_oauth_page_tokens && '' !== $legacy_events_token ) ? $legacy_events_token : null,
						'events_token_set_at' => ( $has_oauth_page_tokens && '' !== $legacy_events_token )
							? current_time( 'mysql', true )
							: null,
						'status'              => $status,
					)
				);

				if ( false === $row_id || (int) $row_id <= 0 ) {
					++$skipped;
					continue;
				}

				// Enrich from Graph (avatar, cover, website, about) while budget remains.
				// Migration can run from the legacy bootstrap before ESF_Facebook_Main loads classes.
				if ( 'active' === $status && '' !== $page_token && ! $this->network_budget_exceeded() ) {
					$this->ensure_picture_deps_loaded();
					if ( class_exists( 'ESF_Facebook_Graph_Client', false ) ) {
						$profile = ESF_Facebook_Graph_Client::fetch_page_profile( $page_id, $page_token );
						if ( ! is_wp_error( $profile ) && is_array( $profile ) ) {
							$patch = array( 'page_id' => $page_id );
							foreach ( array( 'profile_image_url', 'cover_photo_url', 'website', 'about', 'page_link', 'username', 'category', 'page_name' ) as $key ) {
								if ( ! empty( $profile[ $key ] ) && is_string( $profile[ $key ] ) ) {
									$patch[ $key ] = $profile[ $key ];
								}
							}
							if ( isset( $profile['fan_count'] ) && (int) $profile['fan_count'] > 0 ) {
								$patch['fan_count'] = (int) $profile['fan_count'];
							}

							if ( count( $patch ) > 1 ) {
								$page_repo->upsert( $patch );

								if ( class_exists( 'ESF_Facebook_Local_Media', false ) ) {
									$fresh_page = $page_repo->get_by_page_id( $page_id );
									if ( $fresh_page ) {
										if ( ! empty( $patch['profile_image_url'] ) ) {
											ESF_Facebook_Local_Media::localize_page_avatar_from_row( $fresh_page );
										}
										if ( ! empty( $patch['cover_photo_url'] ) ) {
											ESF_Facebook_Local_Media::localize_page_cover_from_row( $fresh_page );
										}
									}
								}
							}
						}
					}
				}

				++$migrated;
				$page_row_ids[ $page_id ] = (int) $row_id;

				if ( ! empty( $legacy_page['username'] ) ) {
					$username = sanitize_text_field( (string) $legacy_page['username'] );
					if ( '' !== $username ) {
						$page_row_ids[ $username ] = (int) $row_id;
					}
				}
			}
		}

		$this->ensure_feed_repository_loaded();

		$default_feed_id = $this->create_default_feeds_for_pages( $page_row_ids );
		if ( $default_feed_id > 0 ) {
			update_option( 'esf_facebook_migration_default_feed_id', $default_feed_id, false );
		}

		$widget_stats  = $this->migrate_legacy_widgets( $default_feed_id );
		$map_stats     = $this->build_legacy_feed_map( $default_feed_id );
		$rewrite_stats = $this->schedule_legacy_shortcode_rewrite();

		// fta_settings is intentionally left intact for rollback / switch_to_legacy.
		if ( class_exists( 'ESF_Module_System' ) ) {
			ESF_Module_System::switch_to_modern( 'facebook' );
		} else {
			update_option( 'esf_facebook_use_new_system', 'new' );
		}

		return array(
			'migrated'                => $migrated,
			'skipped'                 => $skipped,
			'default_feed_id'         => $default_feed_id,
			'widgets_updated'         => isset( $widget_stats['updated'] ) ? (int) $widget_stats['updated'] : 0,
			'widget_feeds'            => isset( $widget_stats['created_feeds'] ) ? (int) $widget_stats['created_feeds'] : 0,
			'map_entries'             => isset( $map_stats['mapped'] ) ? (int) $map_stats['mapped'] : 0,
			'map_feeds'               => isset( $map_stats['created_feeds'] ) ? (int) $map_stats['created_feeds'] : 0,
			'rewrite_status'          => isset( $rewrite_stats['status'] ) ? (string) $rewrite_stats['status'] : '',
			'rewrite_updated'         => isset( $rewrite_stats['updated'] ) ? (int) $rewrite_stats['updated'] : 0,
			'rewrite_scheduled'       => ! empty( $rewrite_stats['scheduled'] ),
			'network_budget_exceeded' => $this->network_budget_exceeded(),
		);
	}

	/**
	 * Ensure feed repository class is available.
	 *
	 * @since 6.7.9
	 * @return void
	 */
	private function ensure_feed_repository_loaded() {
		if ( class_exists( 'ESF_Facebook_Feed_Repository', false ) ) {
			return;
		}
		$dir = $this->facebook_dir();
		if ( '' === $dir ) {
			return;
		}
		require_once $dir . 'includes/models/class-esf-facebook-feed-repository.php';
	}

	/**
	 * Ensure Graph client + local media are available for profile enrichment.
	 *
	 * Legacy → modern migration runs on admin_init before ESF_Facebook_Main
	 * requires these classes, so load them on demand. Used for avatar, cover,
	 * website, and other Page profile fields via fetch_page_profile().
	 *
	 * @since 6.7.9
	 * @return void
	 */
	private function ensure_picture_deps_loaded() {
		$dir = $this->facebook_dir();
		if ( '' === $dir ) {
			return;
		}

		if ( ! class_exists( 'ESF_Facebook_Graph_Client', false ) ) {
			$file = $dir . 'includes/class-esf-facebook-graph-client.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}

		if ( ! class_exists( 'ESF_Facebook_Local_Media', false ) ) {
			$file = $dir . 'includes/class-esf-facebook-local-media.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	}

	/**
	 * Facebook module directory with trailing slash.
	 *
	 * @since 6.7.9
	 * @return string
	 */
	private function facebook_dir() {
		if ( defined( 'ESF_FACEBOOK_DIR' ) ) {
			return ESF_FACEBOOK_DIR;
		}
		if ( defined( 'EFBL_PLUGIN_DIR' ) ) {
			return EFBL_PLUGIN_DIR;
		}
		return '';
	}

	/**
	 * Create one timeline feed per migrated page; return first feed id as default.
	 *
	 * @since 6.7.9
	 * @param array<string,int> $page_row_ids Map of page_id/username → internal pages.id.
	 * @return int
	 */
	private function create_default_feeds_for_pages( array $page_row_ids ) {
		if ( ! class_exists( 'ESF_Facebook_Feed_Repository', false ) ) {
			return 0;
		}

		$feed_repo   = ESF_Facebook_Feed_Repository::get_instance();
		$existing_id = (int) get_option( 'esf_facebook_migration_default_feed_id', 0 );
		if ( $existing_id > 0 && $feed_repo->get_by_id( $existing_id ) ) {
			// Still ensure every page has a feed.
			$this->ensure_feeds_for_unique_page_rows( $feed_repo, $page_row_ids );
			return $existing_id;
		}

		$first_feed_id = $this->ensure_feeds_for_unique_page_rows( $feed_repo, $page_row_ids );
		if ( $first_feed_id > 0 ) {
			return $first_feed_id;
		}

		$feeds = $feed_repo->get_all( array( 'content_type' => 'feed' ) );
		if ( ! empty( $feeds ) && is_object( $feeds[0] ) && ! empty( $feeds[0]->id ) ) {
			return (int) $feeds[0]->id;
		}

		return 0;
	}

	/**
	 * Ensure a timeline feed exists for each unique page row id.
	 *
	 * @since 6.7.9
	 * @param ESF_Facebook_Feed_Repository $feed_repo    Feed repository.
	 * @param array<string,int>            $page_row_ids Page aliases → row id.
	 * @return int First feed id created or found.
	 */
	private function ensure_feeds_for_unique_page_rows( $feed_repo, array $page_row_ids ) {
		$unique    = array_unique( array_map( 'absint', array_values( $page_row_ids ) ) );
		$first_id  = 0;
		$page_repo = class_exists( 'ESF_Facebook_Page_Repository', false )
			? ESF_Facebook_Page_Repository::get_instance()
			: null;

		foreach ( $unique as $row_id ) {
			$row_id = (int) $row_id;
			if ( $row_id <= 0 ) {
				continue;
			}

			$existing = $feed_repo->get_by_page_id( $row_id );
			$feed_id  = 0;
			if ( is_array( $existing ) ) {
				foreach ( $existing as $feed ) {
					if ( ! is_object( $feed ) || empty( $feed->id ) ) {
						continue;
					}
					$content_type = isset( $feed->content_type ) ? (string) $feed->content_type : 'feed';
					if ( 'feed' === $content_type ) {
						$feed_id = (int) $feed->id;
						break;
					}
				}
			}

			if ( $feed_id <= 0 ) {
				$page_name = sprintf(
					/* translators: %d: page row id */
					__( 'Facebook Feed #%d', 'easy-facebook-likebox' ),
					$row_id
				);
				if ( $page_repo ) {
					$page = $page_repo->get_by_id( $row_id );
					if ( $page && ! empty( $page->page_name ) ) {
						$page_name = sprintf(
							/* translators: %s: Facebook Page name */
							__( '%s Feed', 'easy-facebook-likebox' ),
							(string) $page->page_name
						);
					}
				}

				$created = $feed_repo->create(
					array(
						'name'         => $page_name,
						'page_id'      => $row_id,
						'content_type' => 'feed',
						'feed_type'    => 'timeline',
						'settings'     => ESF_Facebook_Feed_Repository::get_default_settings(),
					)
				);
				$feed_id = is_int( $created ) ? $created : 0;
			}

			if ( $feed_id > 0 && 0 === $first_id ) {
				$first_id = $feed_id;
			}
		}

		return $first_id;
	}

	/**
	 * Remap legacy widget instances to modern feed ids.
	 *
	 * @since 6.7.9
	 * @param int $default_feed_id Fallback feed id.
	 * @return array{updated:int,created_feeds:int,skipped:int}
	 */
	private function migrate_legacy_widgets( $default_feed_id ) {
		$empty = array(
			'updated'       => 0,
			'created_feeds' => 0,
			'skipped'       => 0,
		);

		$dir = $this->facebook_dir();
		if ( '' === $dir ) {
			return $empty;
		}

		if ( ! class_exists( 'ESF_Facebook_Widget_Migrator', false ) ) {
			require_once $dir . 'includes/class-esf-facebook-widget-migrator.php';
		}

		if ( ! class_exists( 'ESF_Facebook_Widget_Migrator', false ) ) {
			return $empty;
		}

		return ( new ESF_Facebook_Widget_Migrator() )->migrate_widgets( (int) $default_feed_id );
	}

	/**
	 * Build fanpage_id → feed_id aliases for legacy shortcodes/blocks.
	 *
	 * @since 6.7.9
	 * @param int $default_feed_id Fallback feed id.
	 * @return array{mapped:int,created_feeds:int}
	 */
	private function build_legacy_feed_map( $default_feed_id ) {
		$empty = array(
			'mapped'        => 0,
			'created_feeds' => 0,
		);

		$dir = $this->facebook_dir();
		if ( '' === $dir ) {
			return $empty;
		}

		if ( ! class_exists( 'ESF_Facebook_Legacy_Feed_Map', false ) ) {
			require_once $dir . 'includes/class-esf-facebook-legacy-feed-map.php';
		}

		if ( ! class_exists( 'ESF_Facebook_Legacy_Feed_Map', false ) ) {
			return $empty;
		}

		return ( new ESF_Facebook_Legacy_Feed_Map() )->build( (int) $default_feed_id );
	}

	/**
	 * Queue background rewrite of classic post_content shortcodes.
	 *
	 * @since 6.7.9
	 * @return array{status:string,scheduled:bool,updated:int,scanned:int}
	 */
	private function schedule_legacy_shortcode_rewrite() {
		$empty = array(
			'status'    => 'idle',
			'scheduled' => false,
			'updated'   => 0,
			'scanned'   => 0,
		);

		$dir = $this->facebook_dir();
		if ( '' === $dir ) {
			return $empty;
		}

		if ( ! class_exists( 'ESF_Facebook_Legacy_Shortcode_Rewriter', false ) ) {
			require_once $dir . 'includes/class-esf-facebook-legacy-shortcode-rewriter.php';
		}

		if ( ! class_exists( 'ESF_Facebook_Legacy_Shortcode_Rewriter', false ) ) {
			return $empty;
		}

		return ( new ESF_Facebook_Legacy_Shortcode_Rewriter() )->start( true );
	}
}
