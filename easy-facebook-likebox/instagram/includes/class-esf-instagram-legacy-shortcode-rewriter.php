<?php
/**
 * Background rewriter: [my-instagram-feed] → [esf_instagram_feed id="N"] in post_content.
 *
 * Runs in small WP-Cron batches with a time budget so large sites never
 * block the migrate request or hit PHP timeouts. The modern shortcode alias
 * keeps feeds working while batches run (and forever afterward).
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.9.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_Legacy_Shortcode_Rewriter
 *
 * @since 6.9.6
 */
class ESF_Instagram_Legacy_Shortcode_Rewriter {

	/**
	 * WP-Cron hook (single events, self-chaining).
	 *
	 * @since 6.9.6
	 * @var string
	 */
	const CRON_HOOK = 'esf_instagram_rewrite_legacy_shortcodes';

	/**
	 * Progress / status option.
	 *
	 * @since 6.9.6
	 * @var string
	 */
	const STATE_OPTION = 'esf_instagram_shortcode_rewrite_state';

	/**
	 * Default posts per cron tick.
	 *
	 * @since 6.9.6
	 * @var int
	 */
	const DEFAULT_BATCH_SIZE = 25;

	/**
	 * Soft time budget per batch (seconds).
	 *
	 * @since 6.9.6
	 * @var float
	 */
	const DEFAULT_TIME_BUDGET = 4.0;

	/**
	 * Needle used in SQL LIKE (classic post_content only).
	 *
	 * @since 6.9.6
	 * @var string
	 */
	const CONTENT_NEEDLE = '[my-instagram-feed';

	/**
	 * Start (or resume) background rewriting after migrate.
	 *
	 * Does not scan the whole site in the current request — schedules cron
	 * and optionally processes one small batch immediately.
	 *
	 * @since 6.9.6
	 *
	 * @param bool $process_one_batch_now Whether to run a single batch inline.
	 * @return array{status:string,scheduled:bool,updated:int,scanned:int}
	 */
	public function start( $process_one_batch_now = true ) {
		$state = $this->get_state();
		if ( isset( $state['status'] ) && 'done' === $state['status'] ) {
			// Allow re-run after migrate on sites that gained new legacy embeds.
			$state = array();
		}

		if ( empty( $state['status'] ) || 'idle' === $state['status'] ) {
			$state = array(
				'status'     => 'running',
				'after_id'   => 0,
				'scanned'    => 0,
				'updated'    => 0,
				'started_at' => time(),
			);
			$this->save_state( $state );
		}

		$updated = 0;
		$scanned = 0;

		if ( $process_one_batch_now ) {
			$batch   = $this->run_batch();
			$updated = isset( $batch['updated'] ) ? (int) $batch['updated'] : 0;
			$scanned = isset( $batch['scanned'] ) ? (int) $batch['scanned'] : 0;
			$state   = $this->get_state();
		} else {
			$this->schedule_next( 1 );
		}

		$scheduled = ( isset( $state['status'] ) && 'running' === $state['status'] );

		return array(
			'status'    => isset( $state['status'] ) ? (string) $state['status'] : 'idle',
			'scheduled' => $scheduled,
			'updated'   => $updated,
			'scanned'   => $scanned,
		);
	}

	/**
	 * Process the next batch of matching posts.
	 *
	 * @since 6.9.6
	 * @return array{scanned:int,updated:int,done:bool}
	 */
	public function run_batch() {
		$state = $this->get_state();
		if ( isset( $state['status'] ) && 'done' === $state['status'] ) {
			return array(
				'scanned' => 0,
				'updated' => 0,
				'done'    => true,
			);
		}

		$batch_size = (int) apply_filters( 'esf_instagram_legacy_shortcode_rewrite_batch_size', self::DEFAULT_BATCH_SIZE );
		$batch_size = max( 1, min( 50, $batch_size ) );

		$budget = (float) apply_filters( 'esf_instagram_legacy_shortcode_rewrite_time_budget', self::DEFAULT_TIME_BUDGET );
		$budget = max( 1.0, min( 15.0, $budget ) );
		$start  = microtime( true );

		$after_id = isset( $state['after_id'] ) ? absint( $state['after_id'] ) : 0;
		$scanned  = isset( $state['scanned'] ) ? absint( $state['scanned'] ) : 0;
		$updated  = isset( $state['updated'] ) ? absint( $state['updated'] ) : 0;

		$ids = $this->query_candidate_post_ids( $after_id, $batch_size );
		if ( empty( $ids ) ) {
			$this->mark_done( $scanned, $updated );
			return array(
				'scanned' => 0,
				'updated' => 0,
				'done'    => true,
			);
		}

		$batch_updated = 0;
		$batch_scanned = 0;
		$last_id       = $after_id;
		$stopped_early = false;

		foreach ( $ids as $post_id ) {
			$post_id = absint( $post_id );
			if ( $post_id <= 0 ) {
				continue;
			}

			$last_id = $post_id;
			++$batch_scanned;

			if ( $this->rewrite_post( $post_id ) ) {
				++$batch_updated;
			}

			if ( ( microtime( true ) - $start ) >= $budget ) {
				$stopped_early = true;
				break;
			}
		}

		$scanned += $batch_scanned;
		$updated += $batch_updated;

		$has_more = $stopped_early || count( $ids ) >= $batch_size;
		if ( ! $has_more ) {
			// Confirm no further matches after the last ID we touched.
			$peek     = $this->query_candidate_post_ids( $last_id, 1 );
			$has_more = ! empty( $peek );
		}

		if ( ! $has_more ) {
			$this->mark_done( $scanned, $updated );
			return array(
				'scanned' => $batch_scanned,
				'updated' => $batch_updated,
				'done'    => true,
			);
		}

		$this->save_state(
			array(
				'status'     => 'running',
				'after_id'   => $last_id,
				'scanned'    => $scanned,
				'updated'    => $updated,
				'started_at' => isset( $state['started_at'] ) ? (int) $state['started_at'] : time(),
			)
		);

		$this->schedule_next( 2 );

		return array(
			'scanned' => $batch_scanned,
			'updated' => $batch_updated,
			'done'    => false,
		);
	}

	/**
	 * Rewrite a single post's content when it contains the legacy shortcode.
	 *
	 * @since 6.9.6
	 *
	 * @param int $post_id Post ID.
	 * @return bool True when content changed and was saved.
	 */
	public function rewrite_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! isset( $post->post_content ) ) {
			return false;
		}

		$content = (string) $post->post_content;
		if ( false === strpos( $content, self::CONTENT_NEEDLE ) ) {
			return false;
		}

		$new_content = $this->rewrite_content( $content );
		if ( $new_content === $content ) {
			return false;
		}

		/*
		 * Direct update avoids recursive save hooks / infinite loops and keeps
		 * batches light. post_modified is refreshed for cache busting.
		 */
		$update = array(
			'ID'           => $post_id,
			'post_content' => $new_content,
		);

		$result = wp_update_post( wp_slash( $update ), true );
		return ! is_wp_error( $result ) && (int) $result > 0;
	}

	/**
	 * Replace legacy shortcodes inside a content string.
	 *
	 * Public for unit tests.
	 *
	 * @since 6.9.6
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function rewrite_content( $content ) {
		$content = (string) $content;
		if ( '' === $content || false === strpos( $content, self::CONTENT_NEEDLE ) ) {
			return $content;
		}

		$pattern = '/\[my-instagram-feed((?:\s+[^\]]*)?)\]/i';

		return (string) preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$attr_string = isset( $matches[1] ) ? trim( (string) $matches[1] ) : '';
				$atts        = array();
				if ( '' !== $attr_string && function_exists( 'shortcode_parse_atts' ) ) {
					$parsed = shortcode_parse_atts( $attr_string );
					if ( is_array( $parsed ) ) {
						$atts = $parsed;
					}
				}

				$feed_id = function_exists( 'esf_instagram_resolve_legacy_shortcode_feed_id' )
					? (int) esf_instagram_resolve_legacy_shortcode_feed_id( $atts )
					: 0;

				if ( $feed_id <= 0 && function_exists( 'esf_instagram_get_migration_default_feed_id' ) ) {
					$feed_id = (int) esf_instagram_get_migration_default_feed_id();
				}

				if ( $feed_id <= 0 ) {
					// Keep original — alias will still render when possible.
					return $matches[0];
				}

				return '[esf_instagram_feed id="' . $feed_id . '"]';
			},
			$content
		);
	}

	/**
	 * Cron callback.
	 *
	 * @since 6.9.6
	 * @return void
	 */
	public static function handle_cron() {
		$rewriter = new self();
		$rewriter->run_batch();
	}

	/**
	 * Whether a rewrite job is in progress.
	 *
	 * @since 6.9.6
	 * @return bool
	 */
	public static function is_running() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) && isset( $state['status'] ) && 'running' === $state['status'];
	}

	/**
	 * @since 6.9.6
	 * @return array<string,mixed>
	 */
	public function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * @since 6.9.6
	 *
	 * @param array<string,mixed> $state State.
	 * @return void
	 */
	private function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * @since 6.9.6
	 *
	 * @param int $scanned Total scanned.
	 * @param int $updated Total updated.
	 * @return void
	 */
	private function mark_done( $scanned, $updated ) {
		$this->save_state(
			array(
				'status'      => 'done',
				'after_id'    => 0,
				'scanned'     => absint( $scanned ),
				'updated'     => absint( $updated ),
				'finished_at' => time(),
			)
		);
		$this->clear_scheduled();
	}

	/**
	 * Schedule the next single cron tick.
	 *
	 * @since 6.9.6
	 *
	 * @param int $delay_seconds Delay.
	 * @return void
	 */
	private function schedule_next( $delay_seconds = 2 ) {
		$delay_seconds = max( 1, (int) $delay_seconds );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay_seconds, self::CRON_HOOK );
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * @since 6.9.6
	 * @return void
	 */
	private function clear_scheduled() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Fetch next post IDs that still contain the legacy shortcode.
	 *
	 * Cursor-based (ID > after_id) avoids OFFSET drift and stays indexed-friendly.
	 *
	 * @since 6.9.6
	 *
	 * @param int $after_id Last processed ID.
	 * @param int $limit    Batch size.
	 * @return int[]
	 */
	private function query_candidate_post_ids( $after_id, $limit ) {
		global $wpdb;

		$after_id = absint( $after_id );
		$limit    = absint( $limit );
		if ( $limit <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$like = '%' . $wpdb->esc_like( self::CONTENT_NEEDLE ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE ID > %d
				AND post_type NOT IN ('revision','attachment','nav_menu_item','custom_css','customize_changeset','oembed_cache','user_request','wp_block','wp_template','wp_template_part','wp_global_styles','wp_navigation')
				AND post_status IN ('publish','draft','private','pending','future')
				AND post_content LIKE %s
				ORDER BY ID ASC
				LIMIT %d",
				$after_id,
				$like,
				$limit
			)
		);

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_map( 'absint', $ids );
	}
}
