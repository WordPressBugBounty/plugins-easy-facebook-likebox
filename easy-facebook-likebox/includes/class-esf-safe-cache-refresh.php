<?php
/**
 * Shared safe cache refresh orchestrator.
 *
 * Powers Settings → Clear All Caches across modules: fetch from the live API
 * first, replace cache only on a valid payload, and keep existing rows when
 * the API fails or returns nothing. Supports an explicit force-flush escape
 * hatch when the admin confirms after a failed refresh.
 *
 * @package Easy_Social_Feed
 * @since   6.8.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry + REST helper for module cache refreshers.
 *
 * @since 6.8.2
 */
class ESF_Safe_Cache_Refresh {

	/**
	 * Registered module refreshers keyed by module slug.
	 *
	 * @var array<string,ESF_Module_Cache_Refresher>
	 */
	private static $providers = array();

	/**
	 * Register a module refresher (last registration wins for a slug).
	 *
	 * @since 6.8.2
	 * @param ESF_Module_Cache_Refresher $provider Module adapter.
	 * @return void
	 */
	public static function register( ESF_Module_Cache_Refresher $provider ) {
		$module = sanitize_key( (string) $provider->get_module() );
		if ( '' === $module ) {
			return;
		}
		self::$providers[ $module ] = $provider;
	}

	/**
	 * Whether a refresher is registered for a module.
	 *
	 * @since 6.8.2
	 * @param string $module Module slug.
	 * @return bool
	 */
	public static function has_provider( $module ) {
		$module = sanitize_key( (string) $module );
		return '' !== $module && isset( self::$providers[ $module ] );
	}

	/**
	 * Run a safe refresh for one module.
	 *
	 * @since 6.8.2
	 * @param string $module Module slug.
	 * @return array{
	 *     success:bool,
	 *     refreshed:int,
	 *     failed:int,
	 *     skipped:int,
	 *     kept:bool,
	 *     errors:array<int,string>,
	 *     deleted:int,
	 *     forced:bool
	 * }|WP_Error
	 */
	public static function refresh( $module ) {
		$module = sanitize_key( (string) $module );
		if ( '' === $module || ! isset( self::$providers[ $module ] ) ) {
			return new WP_Error(
				'esf_cache_refresh_unavailable',
				__( 'Safe cache refresh is not available for this module.', 'easy-facebook-likebox' ),
				array( 'status' => 501 )
			);
		}

		$result = self::$providers[ $module ]->refresh_all();
		if ( ! is_array( $result ) ) {
			$result = array();
		}

		return self::normalize_result( $result );
	}

	/**
	 * Hard-flush one module's cache (no API call).
	 *
	 * @since 6.8.2
	 * @param string $module Module slug.
	 * @return array{
	 *     success:bool,
	 *     refreshed:int,
	 *     failed:int,
	 *     skipped:int,
	 *     kept:bool,
	 *     errors:array<int,string>,
	 *     deleted:int,
	 *     forced:bool
	 * }|WP_Error
	 */
	public static function flush( $module ) {
		$module = sanitize_key( (string) $module );
		if ( '' === $module || ! isset( self::$providers[ $module ] ) ) {
			return new WP_Error(
				'esf_cache_refresh_unavailable',
				__( 'Safe cache refresh is not available for this module.', 'easy-facebook-likebox' ),
				array( 'status' => 501 )
			);
		}

		$raw = self::$providers[ $module ]->flush_all();

		return self::normalize_result(
			array(
				'success'   => (bool) $raw['success'],
				'refreshed' => 0,
				'failed'    => 0,
				'skipped'   => 0,
				'kept'      => false,
				'errors'    => array(),
				'deleted'   => max( 0, (int) $raw['deleted'] ),
				'forced'    => true,
			)
		);
	}

	/**
	 * Whether a REST clear-cache request asked for a hard flush.
	 *
	 * @since 6.8.2
	 * @param WP_REST_Request|null $request Request.
	 * @return bool
	 */
	public static function request_wants_force( $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return false;
		}

		$force = $request->get_param( 'force' );
		if ( null === $force ) {
			$json = $request->get_json_params();
			if ( is_array( $json ) && array_key_exists( 'force', $json ) ) {
				$force = $json['force'];
			}
		}

		return filter_var( $force, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * REST helper used by each module's settings clear-cache endpoint.
	 *
	 * Default: safe refresh. With `force=true`: hard flush after admin confirm.
	 *
	 * @since 6.8.2
	 * @param string               $module  Module slug.
	 * @param WP_REST_Request|null $request Optional request (reads `force`).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_clear_cache_request( $module, $request = null ) {
		$result = self::request_wants_force( $request )
			? self::flush( $module )
			: self::refresh( $module );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Aggregate per-target outcomes into the shared result shape.
	 *
	 * @since 6.8.2
	 *
	 * @param array<int,true|WP_Error> $outcomes Per-target results.
	 * @return array{
	 *     success:bool,
	 *     refreshed:int,
	 *     failed:int,
	 *     skipped:int,
	 *     kept:bool,
	 *     errors:array<int,string>,
	 *     deleted:int,
	 *     forced:bool
	 * }
	 */
	public static function summarize_outcomes( array $outcomes ) {
		$refreshed = 0;
		$failed    = 0;
		$errors    = array();

		foreach ( $outcomes as $outcome ) {
			if ( is_wp_error( $outcome ) ) {
				++$failed;
				$message = $outcome->get_error_message();
				if ( '' !== $message && ! in_array( $message, $errors, true ) ) {
					$errors[] = $message;
				}
				continue;
			}
			++$refreshed;
		}

		$total   = $refreshed + $failed;
		$success = ( 0 === $total ) || $refreshed > 0;

		return self::normalize_result(
			array(
				'success'   => $success,
				'refreshed' => $refreshed,
				'failed'    => $failed,
				'skipped'   => 0,
				'kept'      => $failed > 0,
				'errors'    => array_slice( $errors, 0, 5 ),
			)
		);
	}

	/**
	 * Normalize / fill defaults for a refresher result.
	 *
	 * `deleted` mirrors `refreshed` for older admin UIs that still read that key,
	 * unless a force flush already set an explicit deleted count.
	 *
	 * @since 6.8.2
	 * @param array<string,mixed> $result Raw result.
	 * @return array{
	 *     success:bool,
	 *     refreshed:int,
	 *     failed:int,
	 *     skipped:int,
	 *     kept:bool,
	 *     errors:array<int,string>,
	 *     deleted:int,
	 *     forced:bool
	 * }
	 */
	public static function normalize_result( array $result ) {
		$refreshed = isset( $result['refreshed'] ) ? max( 0, (int) $result['refreshed'] ) : 0;
		$failed    = isset( $result['failed'] ) ? max( 0, (int) $result['failed'] ) : 0;
		$skipped   = isset( $result['skipped'] ) ? max( 0, (int) $result['skipped'] ) : 0;
		$errors    = isset( $result['errors'] ) && is_array( $result['errors'] ) ? array_values( $result['errors'] ) : array();
		$kept      = ! empty( $result['kept'] ) || $failed > 0;
		$forced    = ! empty( $result['forced'] );
		$success   = array_key_exists( 'success', $result )
			? (bool) $result['success']
			: ( ( 0 === $refreshed + $failed ) || $refreshed > 0 );
		$deleted   = array_key_exists( 'deleted', $result )
			? max( 0, (int) $result['deleted'] )
			: $refreshed;

		return array(
			'success'   => $success,
			'refreshed' => $refreshed,
			'failed'    => $failed,
			'skipped'   => $skipped,
			'kept'      => $kept,
			'errors'    => $errors,
			'deleted'   => $deleted,
			'forced'    => $forced,
		);
	}

	/**
	 * Reset registry (tests only).
	 *
	 * @since 6.8.2
	 * @return void
	 */
	public static function reset_providers() {
		self::$providers = array();
	}
}
