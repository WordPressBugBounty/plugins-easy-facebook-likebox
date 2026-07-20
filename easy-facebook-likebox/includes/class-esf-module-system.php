<?php
/**
 * Shared legacy/modern module system switcher.
 *
 * Persists per-module mode in wp_options (`esf_{module}_use_new_system`) so
 * Instagram, Facebook, and future modules can reuse the same migration flow.
 *
 * @package Easy_Social_Feed
 * @since   6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESF_Module_System' ) ) {

	/**
	 * Cross-module legacy/modern system helpers.
	 *
	 * @since 6.9.0
	 */
	class ESF_Module_System {

		/**
		 * Allowed persisted mode values.
		 *
		 * @since 6.9.0
		 * @var array<int,string>
		 */
		const MODES = array( 'new', 'legacy' );

		/**
		 * Known modules and their admin page slugs.
		 *
		 * Facebook entries are placeholders for the future modern stack.
		 *
		 * @since 6.9.0
		 * @var array<string,array{legacy_admin_page:string,modern_admin_page:string}>
		 */
		const MODULES = array(
			'instagram' => array(
				'legacy_admin_page' => 'mif',
				'modern_admin_page' => 'esf-instagram',
			),
			'facebook'    => array(
				'legacy_admin_page' => 'easy-facebook-likebox',
				'modern_admin_page' => 'esf-facebook',
			),
		);

		/**
		 * Build the wp_options key for a module mode flag.
		 *
		 * @since 6.9.0
		 *
		 * @param string $module Module slug.
		 * @return string
		 */
		public static function get_option_key( $module ) {
			$module = sanitize_key( (string) $module );
			return 'esf_' . $module . '_use_new_system';
		}

		/**
		 * Build the wp_options key for a dismissed post-migration guidance banner.
		 *
		 * @since 6.9.0
		 *
		 * @param string $module Module slug.
		 * @return string
		 */
		public static function get_migration_guidance_dismiss_option_key( $module ) {
			$module = sanitize_key( (string) $module );
			return 'esf_' . $module . '_migration_guidance_dismissed';
		}

		/**
		 * Whether the post-migration welcome banner was dismissed for this site.
		 *
		 * @since 6.9.0
		 *
		 * @param string $module Module slug.
		 * @return bool
		 */
		public static function is_migration_guidance_dismissed( $module ) {
			$module = sanitize_key( (string) $module );
			if ( '' === $module ) {
				return false;
			}

			return (bool) get_option( self::get_migration_guidance_dismiss_option_key( $module ), false );
		}

		/**
		 * Persist dismissal of the post-migration welcome banner.
		 *
		 * @since 6.9.0
		 *
		 * @param string $module Module slug.
		 * @return bool
		 */
		public static function dismiss_migration_guidance( $module ) {
			$module = sanitize_key( (string) $module );
			if ( '' === $module ) {
				return false;
			}

			return (bool) update_option( self::get_migration_guidance_dismiss_option_key( $module ), '1' );
		}

		/**
		 * Clear the post-migration welcome banner dismissal (e.g. after switching to legacy).
		 *
		 * @since 6.9.0
		 *
		 * @param string $module Module slug.
		 * @return bool
		 */
		public static function reset_migration_guidance_dismiss( $module ) {
			$module = sanitize_key( (string) $module );
			if ( '' === $module ) {
				return false;
			}

			return delete_option( self::get_migration_guidance_dismiss_option_key( $module ) );
		}

		/**
		 * Build the wp_options key for a dismissed legacy-switch banner.
		 *
		 * @since 6.9.0
		 *
		 * @param string $module Module slug.
		 * @return string
		 */
		public static function get_legacy_switch_notice_dismiss_option_key( $module ) {
			$module = sanitize_key( (string) $module );
			return 'esf_' . $module . '_legacy_switch_notice_dismissed';
		}

		/**
		 * Whether the legacy-switch banner was dismissed for this site.
		 *
		 * @since 6.9.0
		 *
		 * @param string $module Module slug.
		 * @return bool
		 */
		public static function is_legacy_switch_notice_dismissed( $module ) {
			$module = sanitize_key( (string) $module );
			if ( '' === $module ) {
				return false;
			}

			return (bool) get_option( self::get_legacy_switch_notice_dismiss_option_key( $module ), false );
		}

		/**
		 * Persist dismissal of the legacy-switch banner.
		 *
		 * @since 6.9.0
		 *
		 * @param string $module Module slug.
		 * @return bool
		 */
		public static function dismiss_legacy_switch_notice( $module ) {
			$module = sanitize_key( (string) $module );
			if ( '' === $module ) {
				return false;
			}

			return (bool) update_option( self::get_legacy_switch_notice_dismiss_option_key( $module ), '1' );
		}

		/**
		 * Read the stored mode option, if set.
		 *
		 * @since 6.9.0
		 *
		 * @param string $module Module slug.
		 * @return string|null `new`, `legacy`, or null when unset.
		 */
		public static function get_mode( $module ) {
			$module = sanitize_key( (string) $module );
			if ( '' === $module ) {
				return null;
			}

			$mode = get_option( self::get_option_key( $module ), null );
			if ( ! is_string( $mode ) ) {
				return null;
			}

			$mode = sanitize_key( $mode );
			return in_array( $mode, self::MODES, true ) ? $mode : null;
		}

		/**
		 * Persist a module mode.
		 *
		 * @since 6.9.0
		 *
		 * @param string $module Module slug.
		 * @param string $mode   `new` or `legacy`.
		 * @return bool
		 */
		public static function set_mode( $module, $mode ) {
			$module = sanitize_key( (string) $module );
			$mode   = sanitize_key( (string) $mode );
			if ( '' === $module || ! in_array( $mode, self::MODES, true ) ) {
				return false;
			}

			return (bool) update_option( self::get_option_key( $module ), $mode );
		}

		/**
		 * Resolve whether the modern stack should load for a module.
		 *
		 * Fresh installs with no legacy data auto-switch to `new`. Existing legacy
		 * data keeps the site on legacy until the user migrates.
		 *
		 * @since 6.9.0
		 *
		 * @param string   $module          Module slug.
		 * @param callable $has_legacy_data   Zero-arg callback returning bool.
		 * @param array<string,mixed> $options Optional overrides:
		 *                                      `force_legacy_constant` => constant name.
		 * @return bool
		 */
		public static function resolve_use_new_system( $module, callable $has_legacy_data, array $options = array() ) {
			$mode = self::get_mode( $module );
			if ( 'new' === $mode ) {
				return true;
			}

			$force_constant = isset( $options['force_legacy_constant'] )
				? (string) $options['force_legacy_constant']
				: '';
			if ( '' !== $force_constant && defined( $force_constant ) && constant( $force_constant ) ) {
				return false;
			}

			if ( 'legacy' === $mode ) {
				return false;
			}

			if ( $has_legacy_data() ) {
				return false;
			}

			self::set_mode( $module, 'new' );
			return true;
		}

		/**
		 * Whether legacy data still needs a one-time migration.
		 *
		 * @since 6.9.0
		 *
		 * @param string   $module        Module slug.
		 * @param callable $has_legacy_data Zero-arg callback returning bool.
		 * @return bool
		 */
		public static function needs_migration( $module, callable $has_legacy_data ) {
			return $has_legacy_data() && 'new' !== self::get_mode( $module );
		}

		/**
		 * Whether the site may switch back to the legacy admin from the modern UI.
		 *
		 * Normally requires leftover legacy data (post-migration). Pass
		 * `$allow_without_legacy` true for the support URL escape hatch
		 * (`?esf_force_legacy=1`) so fresh modern installs can still open legacy.
		 *
		 * @since 6.9.0
		 *
		 * @param string   $module               Module slug.
		 * @param callable $has_legacy_data        Zero-arg callback returning bool.
		 * @param bool     $allow_without_legacy   When true, only require modern mode.
		 * @return bool
		 */
		public static function can_switch_to_legacy( $module, callable $has_legacy_data, $allow_without_legacy = false ) {
			if ( 'new' !== self::get_mode( $module ) ) {
				return false;
			}

			if ( $allow_without_legacy ) {
				return true;
			}

			return (bool) $has_legacy_data();
		}

		/**
		 * Effective mode label for API/admin UI.
		 *
		 * @since 6.9.0
		 *
		 * @param string   $module        Module slug.
		 * @param callable $has_legacy_data Zero-arg callback returning bool.
		 * @return string `new` or `legacy`.
		 */
		public static function get_effective_mode( $module, callable $has_legacy_data ) {
			$stored = self::get_mode( $module );
			if ( null !== $stored ) {
				return $stored;
			}

			return $has_legacy_data() ? 'legacy' : 'new';
		}

		/**
		 * Build migration status payload for REST/admin consumers.
		 *
		 * @since 6.9.0
		 *
		 * @param string   $module        Module slug.
		 * @param callable $has_legacy_data Zero-arg callback returning bool.
		 * @return array{
		 *     mode:string,
		 *     legacy_present:bool,
		 *     needs_migration:bool,
		 *     can_switch_to_legacy:bool,
		 *     using_modern:bool,
		 *     legacy_admin_url:string,
		 *     modern_admin_url:string,
		 *     migration_guidance_dismissed:bool,
		 *     legacy_switch_notice_dismissed:bool
		 * }
		 */
		public static function get_status( $module, callable $has_legacy_data ) {
			$module = sanitize_key( (string) $module );
			$config = self::get_module_config( $module );

			return array(
				'mode'                            => self::get_effective_mode( $module, $has_legacy_data ),
				'legacy_present'                  => (bool) $has_legacy_data(),
				'needs_migration'                 => self::needs_migration( $module, $has_legacy_data ),
				'can_switch_to_legacy'            => self::can_switch_to_legacy( $module, $has_legacy_data ),
				'using_modern'                    => 'new' === self::get_effective_mode( $module, $has_legacy_data )
					&& ! self::needs_migration( $module, $has_legacy_data ),
				'legacy_admin_url'                => self::get_admin_url( $module, 'legacy', $config ),
				'modern_admin_url'                => self::get_admin_url( $module, 'modern', $config ),
				'migration_guidance_dismissed'    => self::is_migration_guidance_dismissed( $module ),
				'legacy_switch_notice_dismissed'  => self::is_legacy_switch_notice_dismissed( $module ),
			);
		}

		/**
		 * Switch a migrated site back to the legacy admin UI.
		 *
		 * Legacy wp_options data is never deleted; only the mode flag changes.
		 * Fresh modern installs may pass `$options['force'] = true` (support URL).
		 *
		 * @since 6.9.0
		 *
		 * @param string               $module          Module slug.
		 * @param callable             $has_legacy_data Zero-arg callback returning bool.
		 * @param array<string,mixed>  $options         Optional. `force` => bool.
		 * @return true|WP_Error
		 */
		public static function switch_to_legacy( $module, callable $has_legacy_data, array $options = array() ) {
			$force = ! empty( $options['force'] );

			if ( ! self::can_switch_to_legacy( $module, $has_legacy_data, $force ) ) {
				return new WP_Error(
					'esf_module_switch_legacy_forbidden',
					__( 'Switching back to the legacy system is not available for this site.', 'easy-facebook-likebox' ),
					array( 'status' => 403 )
				);
			}

			if ( ! self::set_mode( $module, 'legacy' ) ) {
				return new WP_Error(
					'esf_module_switch_legacy_failed',
					__( 'Could not switch back to the legacy system. Please try again.', 'easy-facebook-likebox' ),
					array( 'status' => 500 )
				);
			}

			self::reset_migration_guidance_dismiss( $module );

			return true;
		}

		/**
		 * Mark a module as using the modern stack after migration completes.
		 *
		 * @since 6.9.0
		 *
		 * @param string $module Module slug.
		 * @return bool
		 */
		public static function switch_to_modern( $module ) {
			return self::set_mode( $module, 'new' );
		}

		/**
		 * Resolve module registry config.
		 *
		 * @since 6.9.0
		 *
		 * @param string $module Module slug.
		 * @return array{legacy_admin_page:string,modern_admin_page:string}|null
		 */
		public static function get_module_config( $module ) {
			$module = sanitize_key( (string) $module );
			if ( '' === $module || ! isset( self::MODULES[ $module ] ) ) {
				return null;
			}

			$config = self::MODULES[ $module ];
			if (
				! is_array( $config )
				|| empty( $config['legacy_admin_page'] )
				|| empty( $config['modern_admin_page'] )
			) {
				return null;
			}

			/**
			 * Filter module-system registry config.
			 *
			 * @since 6.9.0
			 *
			 * @param array{legacy_admin_page:string,modern_admin_page:string} $config Module config.
			 * @param string                                                   $module Module slug.
			 */
			$config = apply_filters( 'esf_module_system_config', $config, $module );

			return is_array( $config ) ? $config : null;
		}

		/**
		 * Build an admin URL for the legacy or modern page slug.
		 *
		 * @since 6.9.0
		 *
		 * @param string                                    $module Module slug.
		 * @param string                                    $which  `legacy` or `modern`.
		 * @param array{legacy_admin_page:string,modern_admin_page:string}|null $config Optional config.
		 * @return string
		 */
		public static function get_admin_url( $module, $which, $config = null ) {
			if ( null === $config ) {
				$config = self::get_module_config( $module );
			}

			$page = '';
			if ( is_array( $config ) ) {
				$page = 'legacy' === sanitize_key( (string) $which )
					? (string) $config['legacy_admin_page']
					: (string) $config['modern_admin_page'];
			}

			if ( '' === $page ) {
				return admin_url( 'admin.php' );
			}

			return admin_url( 'admin.php?page=' . rawurlencode( $page ) );
		}

		/**
		 * Module-specific resolve options (e.g. wp-config legacy override constants).
		 *
		 * @since 6.9.0
		 *
		 * @param string $module Module slug.
		 * @return array<string,mixed>
		 */
		private static function get_resolve_options( $module ) {
			$module  = sanitize_key( (string) $module );
			$options = array();

			if ( 'instagram' === $module ) {
				$options['force_legacy_constant'] = 'ESF_INSTAGRAM_USE_LEGACY';
			}

			/**
			 * Filter resolve options passed to {@see resolve_use_new_system()}.
			 *
			 * @since 6.9.0
			 *
			 * @param array<string,mixed> $options Resolve options.
			 * @param string              $module  Module slug.
			 */
			return (array) apply_filters( 'esf_module_system_resolve_options', $options, $module );
		}
	}
}
