<?php
/**
 * Shared WP-Cron helpers for ESF modules (token auto-refresh, etc.).
 *
 * Used by Instagram, YouTube, Twitter, and future modules (e.g. Facebook) so
 * the thirty-minute schedule and recurring job wiring stay consistent.
 *
 * @package Easy_Social_Feed
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the `thirty_minutes` cron schedule if missing.
 *
 * @since 6.8.0
 * @param array<string,array<string,mixed>> $schedules Existing schedules.
 * @return array<string,array<string,mixed>>
 */
function esf_cron_schedules_add_thirty_minutes( $schedules ) {
	if ( ! is_array( $schedules ) ) {
		$schedules = array();
	}
	if ( ! isset( $schedules['thirty_minutes'] ) ) {
		$schedules['thirty_minutes'] = array(
			'interval' => 1800,
			'display'  => __( 'Every 30 Minutes', 'easy-facebook-likebox' ),
		);
	}
	return $schedules;
}

/**
 * Attach a callback to a hook and schedule it on the `thirty_minutes` recurrence.
 *
 * @since 6.8.0
 * @param string   $hook_name Unique cron hook name (e.g. `esf_instagram_auto_refresh_tokens`).
 * @param callable $callback  Cron callback.
 * @return void
 */
function esf_cron_attach_thirty_minutes_recurring_job( $hook_name, $callback ) {
	$hook_name = (string) $hook_name;
	if ( '' === $hook_name || ! is_callable( $callback ) ) {
		return;
	}
	add_action( $hook_name, $callback );
	if ( ! wp_next_scheduled( $hook_name ) ) {
		wp_schedule_event( time(), 'thirty_minutes', $hook_name );
	}
}

/**
 * Clear the next scheduled event for a hook (single recurrence).
 *
 * @since 6.8.0
 * @param string $hook_name Cron hook name.
 * @return void
 */
function esf_cron_unschedule_recurring_job( $hook_name ) {
	$hook_name = (string) $hook_name;
	if ( '' === $hook_name ) {
		return;
	}
	$timestamp = wp_next_scheduled( $hook_name );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook_name );
	}
}

/**
 * Clear Twitter module cron jobs when the module is deactivated.
 *
 * Safe to call when Twitter classes are not loaded (uses hook names directly).
 *
 * @since 6.9.0
 * @return void
 */
function esf_twitter_teardown_scheduled_jobs() {
	if ( class_exists( 'ESF_Twitter_Main' ) ) {
		ESF_Twitter_Main::on_deactivation();
		return;
	}

	esf_cron_unschedule_recurring_job( 'esf_twitter_auto_refresh_tokens' );
	esf_cron_unschedule_recurring_job( 'esf_twitter_refresh_feed_cache' );
}
