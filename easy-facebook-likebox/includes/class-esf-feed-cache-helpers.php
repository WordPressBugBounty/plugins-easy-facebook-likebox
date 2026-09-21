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
