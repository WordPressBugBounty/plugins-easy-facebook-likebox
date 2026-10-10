<?php
/**
 * Shared feed-cache helpers (stale-while-revalidate / pre-expiry refresh).
 *
 * Competitive gap P1-4: warm caches before they expire and serve the previous
 * copy when a visitor hits an already-expired row, so API latency stays off
 * the request path.
 *
 * @package Easy_Social_Feed
 * @since 6.9.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lead time (seconds) before expires_at when cron should re-warm a feed cache.
 *
 * Default: 30 minutes. Filterable per site.
 *
 * @since 6.9.5
 * @return int
 */
function esf_feed_cache_refresh_lead_seconds() {
	$lead = 30 * MINUTE_IN_SECONDS;

	/**
	 * Filter how early feed-cache cron may refresh before expires_at.
	 *
	 * @since 6.9.5
	 *
	 * @param int $lead Seconds before expiry. Default 1800.
	 */
	$lead = (int) apply_filters( 'esf_feed_cache_refresh_lead_seconds', $lead );

	return max( 0, $lead );
}

/**
 * Whether a cache row should be refreshed by background cron.
 *
 * Fresh rows whose expires_at is still beyond the lead window are skipped.
 * Missing or already-expired rows are due. Rows that expire within the lead
 * window are due (pre-expiry warm).
 *
 * @since 6.9.5
 *
 * @param string|null $expires_at MySQL UTC datetime from the cache table, or null if missing.
 * @param int|null    $lead_secs  Optional lead override; null uses {@see esf_feed_cache_refresh_lead_seconds()}.
 * @return bool True when cron should re-fetch.
 */
function esf_feed_cache_is_due_for_refresh( $expires_at, $lead_secs = null ) {
	if ( null === $expires_at || '' === $expires_at ) {
		return true;
	}

	$expires_ts = strtotime( (string) $expires_at . ' UTC' );
	if ( false === $expires_ts ) {
		return true;
	}

	$lead = null === $lead_secs ? esf_feed_cache_refresh_lead_seconds() : max( 0, (int) $lead_secs );
	$now  = time();

	return ( $expires_ts - $lead ) <= $now;
}

/**
 * Decode a cache_data JSON blob into an array (or null).
 *
 * @since 6.9.5
 *
 * @param mixed $raw JSON string or already-decoded value.
 * @return array|null
 */
function esf_feed_cache_decode_data( $raw ) {
	if ( is_array( $raw ) ) {
		return $raw;
	}
	if ( ! is_string( $raw ) || '' === $raw ) {
		return null;
	}
	$data = json_decode( $raw, true );
	return is_array( $data ) ? $data : null;
}

/**
 * Standard cache-duration → interval map used by module feed-cache crons.
 *
 * Keys are TTL seconds; values are suffix tokens for schedule slugs
 * (`esf_{module}_{suffix}`).
 *
 * @since 6.9.5
 * @return array<int,string>
 */
function esf_feed_cache_cron_interval_suffixes() {
	return array(
		3600   => '1h',
		43200  => '12h',
		86400  => '24h',
		604800 => '7d',
	);
}

/**
 * Build a module-scoped cron schedule slug for a cache duration.
 *
 * Example: `esf_feed_cache_cron_schedule_slug( 86400, 'facebook' )` → `esf_facebook_24h`.
 *
 * @since 6.9.5
 *
 * @param int                    $cache_duration_secs Cache TTL in seconds.
 * @param string                 $module_slug         Module key (`facebook`, `instagram`, …).
 * @param int                    $default_secs        Fallback TTL when `$cache_duration_secs` is unknown. Default 43200.
 * @param array<int,string>|null $suffixes            Optional duration→suffix map; null uses {@see esf_feed_cache_cron_interval_suffixes()}.
 * @return string
 */
function esf_feed_cache_cron_schedule_slug( $cache_duration_secs, $module_slug, $default_secs = 43200, $suffixes = null ) {
	$module_slug = sanitize_key( (string) $module_slug );
	if ( '' === $module_slug ) {
		$module_slug = 'feed';
	}

	$suffixes = is_array( $suffixes ) ? $suffixes : esf_feed_cache_cron_interval_suffixes();
	$secs     = (int) $cache_duration_secs;
	$suffix   = isset( $suffixes[ $secs ] ) ? (string) $suffixes[ $secs ] : '';

	if ( '' === $suffix ) {
		$default_secs = (int) $default_secs;
		$suffix       = isset( $suffixes[ $default_secs ] ) ? (string) $suffixes[ $default_secs ] : '12h';
	}

	return 'esf_' . $module_slug . '_' . $suffix;
}

/**
 * Register module-scoped WP-Cron recurrences for feed-cache refresh.
 *
 * Adds `esf_{module}_1h` / `_12h` / `_24h` / `_7d` (or a custom `$suffixes` map)
 * without overwriting an already-registered slug.
 *
 * @since 6.9.5
 *
 * @param array<string,array<string,mixed>> $schedules   Existing `cron_schedules` filter value.
 * @param string                            $module_slug Module key (`facebook`, `instagram`, …).
 * @param array<int,string>|null            $suffixes    Optional duration→suffix map.
 * @return array<string,array<string,mixed>>
 */
function esf_feed_cache_add_cron_schedules( $schedules, $module_slug, $suffixes = null ) {
	if ( ! is_array( $schedules ) ) {
		$schedules = array();
	}

	$module_slug = sanitize_key( (string) $module_slug );
	if ( '' === $module_slug ) {
		return $schedules;
	}

	$suffixes = is_array( $suffixes ) ? $suffixes : esf_feed_cache_cron_interval_suffixes();
	$labels   = array(
		'1h'  => __( 'Every Hour', 'easy-facebook-likebox' ),
		'3h'  => __( 'Every 3 Hours', 'easy-facebook-likebox' ),
		'6h'  => __( 'Every 6 Hours', 'easy-facebook-likebox' ),
		'12h' => __( 'Every 12 Hours', 'easy-facebook-likebox' ),
		'24h' => __( 'Every 24 Hours', 'easy-facebook-likebox' ),
		'7d'  => __( 'Every 7 Days', 'easy-facebook-likebox' ),
	);

	foreach ( $suffixes as $interval_secs => $suffix ) {
		$slug = 'esf_' . $module_slug . '_' . sanitize_key( (string) $suffix );
		if ( isset( $schedules[ $slug ] ) ) {
			continue;
		}
		$suffix_key         = (string) $suffix;
		$schedules[ $slug ] = array(
			'interval' => max( 1, (int) $interval_secs ),
			'display'  => isset( $labels[ $suffix_key ] )
				? $labels[ $suffix_key ]
				/* translators: %s: cron recurrence label suffix (e.g. "6h"). */
				: sprintf( __( 'Every %s', 'easy-facebook-likebox' ), $suffix_key ),
		);
	}

	return $schedules;
}

/**
 * Ensure a recurring feed-cache refresh event is scheduled on the given recurrence.
 *
 * No-ops when an event already exists with the same schedule slug. Replaces a
 * mismatched recurrence (e.g. after Settings → cache duration changes).
 *
 * @since 6.9.5
 *
 * @param string $hook          WP-Cron hook name (e.g. `esf_facebook_refresh_feed_cache`).
 * @param string $schedule_slug Recurrence slug from {@see esf_feed_cache_cron_schedule_slug()}.
 * @return void
 */
function esf_feed_cache_maybe_schedule_refresh( $hook, $schedule_slug ) {
	$hook          = (string) $hook;
	$schedule_slug = (string) $schedule_slug;
	if ( '' === $hook || '' === $schedule_slug ) {
		return;
	}

	$event = function_exists( 'wp_get_scheduled_event' )
		? wp_get_scheduled_event( $hook )
		: false;
	if ( is_object( $event ) && isset( $event->schedule ) && $event->schedule === $schedule_slug ) {
		return;
	}

	$timestamp = wp_next_scheduled( $hook );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
	}

	wp_schedule_event( time(), $schedule_slug, $hook );
}

/**
 * Force-unschedule then re-schedule a feed-cache refresh cron.
 *
 * Prefer this after Settings saves so the next tick uses the new TTL immediately.
 *
 * @since 6.9.5
 *
 * @param string $hook          WP-Cron hook name.
 * @param string $schedule_slug Recurrence slug from {@see esf_feed_cache_cron_schedule_slug()}.
 * @return void
 */
function esf_feed_cache_reschedule_refresh( $hook, $schedule_slug ) {
	$hook = (string) $hook;
	if ( '' === $hook ) {
		return;
	}

	$timestamp = wp_next_scheduled( $hook );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
	}

	esf_feed_cache_maybe_schedule_refresh( $hook, $schedule_slug );
}
