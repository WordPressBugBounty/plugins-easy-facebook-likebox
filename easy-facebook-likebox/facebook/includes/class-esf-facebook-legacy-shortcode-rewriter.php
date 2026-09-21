<?php
/**
 * Background rewriter: legacy Facebook shortcodes → [esf_facebook_feed id="N"].
 *
 * @package Easy_Social_Feed
 * @subpackage Facebook
 * @since 6.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Facebook_Legacy_Shortcode_Rewriter
 *
 * Rewrites [efb_feed] in post_content in small WP-Cron batches with a time budget.
 * Leaves [efb_likebox] / [efb_pageplugin] unchanged — those render from inline
 * attributes via ESF_Facebook_Likebox_Renderer (no saved feed row).
 *
 * @since 6.7.9
 */
class ESF_Facebook_Legacy_Shortcode_Rewriter {

	/**
	 * WP-Cron hook (single events, self-chaining).
	 *
	 * @since 6.7.9
	 * @var string
	 */
	const CRON_HOOK = 'esf_facebook_rewrite_legacy_shortcodes';

	/**
	 * Progress / status option.
	 *
	 * @since 6.7.9
	 * @var string
	 */
	const STATE_OPTION = 'esf_facebook_shortcode_rewrite_state';

	/**
	 * Default posts per cron tick.
	 *
	 * @since 6.7.9
	 * @var int
	 */
	const DEFAULT_BATCH_SIZE = 25;

	/**
	 * Soft time budget per batch (seconds).
	 *
	 * @since 6.7.9
	 * @var float
	 */
	const DEFAULT_TIME_BUDGET = 4.0;

	/**
	 * SQL LIKE needles (classic post_content) — feed shortcodes only.
	 *
	 * @since 6.7.9
	 * @var string[]
	 */
	const CONTENT_NEEDLES = array( '[efb_feed' );

	/**
	 * Start (or resume) background rewriting after migrate.
	 *
	 * @since 6.7.9
	 * @param bool $process_one_batch_now Whether to run a single batch inline.
	 * @return array{status:string,scheduled:bool,updated:int,scanned:int}
	 */
	public function start( $process_one_batch_now = true ) {
		$state = $this->get_state();
		if ( isset( $state['status'] ) && 'done' === $state['status'] ) {
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
	 * @since 6.7.9
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

		$batch_size = (int) apply_filters( 'esf_facebook_legacy_shortcode_rewrite_batch_size', self::DEFAULT_BATCH_SIZE );
		$batch_size = max( 1, min( 50, $batch_size ) );

		$budget = (float) apply_filters( 'esf_facebook_legacy_shortcode_rewrite_time_budget', self::DEFAULT_TIME_BUDGET );
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
	 * Rewrite a single post's content when it contains a legacy shortcode.
	 *
	 * @since 6.7.9
	 * @param int $post_id Post ID.
	 * @return bool
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
		if ( ! $this->content_has_legacy_shortcode( $content ) ) {
			return false;
		}

		$new_content = $this->rewrite_content( $content );
		if ( $new_content === $content ) {
			return false;
		}

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $new_content,
				)
			),
			true
		);

		return ! is_wp_error( $result ) && (int) $result > 0;
	}

	/**
	 * Replace legacy shortcodes inside a content string.
	 *
	 * @since 6.7.9
	 * @param string $content Post content.
	 * @return string
	 */
	public function rewrite_content( $content ) {
		$content = (string) $content;
		if ( '' === $content || ! $this->content_has_legacy_shortcode( $content ) ) {
			return $content;
		}

		$pattern = '/\[(efb_feed)((?:\s+[^\]]*)?)\]/i';

		return (string) preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$attr_string = isset( $matches[2] ) ? trim( (string) $matches[2] ) : '';
				$atts        = array();
				if ( '' !== $attr_string && function_exists( 'shortcode_parse_atts' ) ) {
					$parsed = shortcode_parse_atts( $attr_string );
					if ( is_array( $parsed ) ) {
						$atts = $parsed;
					}
				}

				$feed_id = function_exists( 'esf_facebook_resolve_legacy_shortcode_feed_id' )
					? (int) esf_facebook_resolve_legacy_shortcode_feed_id( $atts )
					: 0;

				if ( $feed_id <= 0 && function_exists( 'esf_facebook_get_migration_default_feed_id' ) ) {
					$feed_id = (int) esf_facebook_get_migration_default_feed_id();
				}

				if ( $feed_id <= 0 ) {
					return $matches[0];
				}

				return '[esf_facebook_feed id="' . $feed_id . '"]';
			},
			$content
		);
	}

	/**
	 * Cron callback.
	 *
	 * @since 6.7.9
	 * @return void
	 */
	public static function handle_cron() {
		$rewriter = new self();
		$rewriter->run_batch();
	}

	/**
	 * Whether a rewrite job is in progress.
	 *
	 * @since 6.7.9
	 * @return bool
	 */
	public static function is_running() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) && isset( $state['status'] ) && 'running' === $state['status'];
	}

	/**
	 * @since 6.7.9
	 * @return array<string,mixed>
	 */
	public function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * @since 6.7.9
	 * @param array<string,mixed> $state State.
	 * @return void
	 */
	private function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * @since 6.7.9
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
	 * @since 6.7.9
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
	 * @since 6.7.9
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
	 * @since 6.7.9
	 * @param string $content Content.
	 * @return bool
	 */
	private function content_has_legacy_shortcode( $content ) {
		foreach ( self::CONTENT_NEEDLES as $needle ) {
			if ( false !== strpos( $content, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fetch next post IDs that still contain a legacy shortcode.
	 *
	 * @since 6.7.9
	 * @param int $after_id Last processed ID.
	 * @param int $limit    Batch size.
	 * @return int[]
	 */
	private function query_candidate_post_ids( $after_id, $limit ) {
		global $wpdb;

		$after_id = absint( $after_id );
		$limit    = absint( $limit );
		if ( $limit <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'esc_like' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return array();
		}

		$likes = array();
		foreach ( self::CONTENT_NEEDLES as $needle ) {
			$likes[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}

		$like_sql = implode( ' OR ', array_fill( 0, count( $likes ), 'post_content LIKE %s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dynamic LIKE clauses from fixed CONTENT_NEEDLES.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE ID > %d
				AND post_type NOT IN ('revision','attachment','nav_menu_item','custom_css','customize_changeset','oembed_cache','user_request','wp_block','wp_template','wp_template_part','wp_global_styles','wp_navigation')
				AND post_status IN ('publish','draft','private','pending','future')
				AND ( {$like_sql} )
				ORDER BY ID ASC
				LIMIT %d",
				array_merge( array( $after_id ), $likes, array( $limit ) )
			)
		);

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_map( 'absint', $ids );
	}
}
