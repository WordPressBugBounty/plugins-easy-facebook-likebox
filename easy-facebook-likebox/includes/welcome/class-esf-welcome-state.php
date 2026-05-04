<?php
/**
 * Welcome wizard state helper.
 *
 * Encapsulates persistence of the first-run wizard state inside the existing
 * `fta_settings` option. Keeping a dedicated helper class lets the REST
 * controller, the page-render redirect and any future dashboard integrations
 * share a single source of truth without each one re-implementing sanitisation.
 *
 * Stored shape (under fta_settings['welcome']):
 *   - completed       (bool)   Whether the user has finished or dismissed the wizard.
 *   - current_step    (int)    1..4 — the step the user last viewed.
 *   - chosen_modules  (array)  Module slugs the user toggled ON in step 2.
 *   - completed_at    (int)    Unix timestamp set when completed flips to true.
 *
 * @package    Easy_Social_Feed
 * @subpackage Welcome
 * @since      6.8.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_Welcome_State' ) ) {

	/**
	 * Class ESF_Welcome_State
	 *
	 * @since 6.8.0
	 */
	class ESF_Welcome_State {

		/**
		 * Total number of wizard steps.
		 *
		 * @since 6.8.0
		 * @var int
		 */
		const TOTAL_STEPS = 4;

		/**
		 * Allowed module slugs the wizard knows about.
		 *
		 * @since 6.8.0
		 * @var string[]
		 */
		const ALLOWED_MODULES = array( 'facebook', 'instagram', 'youtube', 'twitter' );

		/**
		 * Get the wizard state from the fta_settings option.
		 *
		 * Always returns a fully-populated array with safe defaults so callers
		 * never need to guard against missing keys.
		 *
		 * @since 6.8.0
		 * @return array{completed:bool,current_step:int,chosen_modules:string[],completed_at:int}
		 */
		public static function get_state() {
			$fta_settings = get_option( 'fta_settings', array() );
			$raw          = array();

			if ( is_array( $fta_settings ) && isset( $fta_settings['welcome'] ) && is_array( $fta_settings['welcome'] ) ) {
				$raw = $fta_settings['welcome'];
			}

			return self::normalize( $raw );
		}

		/**
		 * Persist a partial state update.
		 *
		 * Unknown keys are dropped, values are sanitised, and the merged result
		 * is written back to `fta_settings['welcome']` in a single update_option
		 * call.
		 *
		 * @since 6.8.0
		 * @param array $partial Partial state to merge in.
		 * @return array Updated, normalised state.
		 */
		public static function update_state( $partial ) {
			if ( ! is_array( $partial ) ) {
				$partial = array();
			}

			$current = self::get_state();
			$next    = $current;

			if ( array_key_exists( 'current_step', $partial ) ) {
				$next['current_step'] = self::sanitize_step( $partial['current_step'] );
			}

			if ( array_key_exists( 'chosen_modules', $partial ) ) {
				$next['chosen_modules'] = self::sanitize_modules( $partial['chosen_modules'] );
			}

			if ( array_key_exists( 'completed', $partial ) ) {
				$completed = (bool) $partial['completed'];

				// Capture timestamp the first time completed flips to true.
				if ( $completed && ! $current['completed'] ) {
					$next['completed_at'] = time();
				}

				$next['completed'] = $completed;
			}

			$fta_settings = get_option( 'fta_settings', array() );
			if ( ! is_array( $fta_settings ) ) {
				$fta_settings = array();
			}

			$fta_settings['welcome'] = $next;
			update_option( 'fta_settings', $fta_settings );

			return $next;
		}

		/**
		 * Convenience: has the wizard been completed at least once?
		 *
		 * @since 6.8.0
		 * @return bool
		 */
		public static function is_completed() {
			$state = self::get_state();
			return ! empty( $state['completed'] );
		}

		/**
		 * Default state (used when nothing has been persisted yet).
		 *
		 * @since 6.8.0
		 * @return array{completed:bool,current_step:int,chosen_modules:string[],completed_at:int}
		 */
		public static function defaults() {
			return array(
				'completed'      => false,
				'current_step'   => 1,
				'chosen_modules' => array(),
				'completed_at'   => 0,
			);
		}

		/**
		 * Normalise a raw stored array into the canonical state shape.
		 *
		 * @since 6.8.0
		 * @param array $raw Raw state from storage.
		 * @return array{completed:bool,current_step:int,chosen_modules:string[],completed_at:int}
		 */
		private static function normalize( $raw ) {
			$defaults = self::defaults();

			return array(
				'completed'      => isset( $raw['completed'] ) ? (bool) $raw['completed'] : $defaults['completed'],
				'current_step'   => isset( $raw['current_step'] ) ? self::sanitize_step( $raw['current_step'] ) : $defaults['current_step'],
				'chosen_modules' => isset( $raw['chosen_modules'] ) ? self::sanitize_modules( $raw['chosen_modules'] ) : $defaults['chosen_modules'],
				'completed_at'   => isset( $raw['completed_at'] ) ? (int) $raw['completed_at'] : $defaults['completed_at'],
			);
		}

		/**
		 * Clamp a step value to the allowed [1..TOTAL_STEPS] range.
		 *
		 * @since 6.8.0
		 * @param mixed $value Raw step value.
		 * @return int
		 */
		private static function sanitize_step( $value ) {
			$step = (int) $value;
			if ( $step < 1 ) {
				$step = 1;
			}
			if ( $step > self::TOTAL_STEPS ) {
				$step = self::TOTAL_STEPS;
			}
			return $step;
		}

		/**
		 * Filter and de-duplicate a module slug list against the allow-list.
		 *
		 * @since 6.8.0
		 * @param mixed $value Raw modules input.
		 * @return string[]
		 */
		private static function sanitize_modules( $value ) {
			if ( ! is_array( $value ) ) {
				return array();
			}

			$clean = array();
			foreach ( $value as $slug ) {
				if ( ! is_string( $slug ) ) {
					continue;
				}
				$slug = sanitize_key( $slug );
				if ( in_array( $slug, self::ALLOWED_MODULES, true ) ) {
					$clean[ $slug ] = true;
				}
			}

			return array_keys( $clean );
		}
	}
}
