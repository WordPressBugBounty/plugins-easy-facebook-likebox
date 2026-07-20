<?php
/**
 * Shared feed auto-naming helpers for module REST APIs.
 *
 * @package Easy_Social_Feed
 * @since   6.9.0
 */

/**
 * Class ESF_Feed_Name
 */
class ESF_Feed_Name {

	/**
	 * Read name mode from settings meta.
	 *
	 * @since 6.9.0
	 *
	 * @param array<string,mixed> $settings Feed settings array.
	 * @return string Either `auto` or `manual`.
	 */
	public static function get_name_mode( array $settings ) {
		$mode = isset( $settings['meta']['name_mode'] ) ? sanitize_text_field( (string) $settings['meta']['name_mode'] ) : 'auto';

		return in_array( $mode, array( 'auto', 'manual' ), true ) ? $mode : 'auto';
	}

	/**
	 * Write name mode metadata into settings.
	 *
	 * @since 6.9.0
	 *
	 * @param array<string,mixed> $settings   Feed settings array.
	 * @param string              $mode       Name mode (`auto`|`manual`).
	 * @param int                 $account_id Related account ID.
	 * @return array<string,mixed>
	 */
	public static function set_name_meta( array $settings, $mode, $account_id ) {
		if ( ! isset( $settings['meta'] ) || ! is_array( $settings['meta'] ) ) {
			$settings['meta'] = array();
		}

		$settings['meta']['name_mode']            = in_array( $mode, array( 'auto', 'manual' ), true ) ? $mode : 'auto';
		$settings['meta']['auto_name_account_id'] = max( 0, (int) $account_id );

		return $settings;
	}

	/**
	 * Whether a name change should switch the feed into manual naming mode.
	 *
	 * @since 6.9.0
	 *
	 * @param bool   $name_updated            Whether the request changed the feed name.
	 * @param string $name_mode               Current name mode.
	 * @param bool   $source_context_changed  Whether account/feed type/source changed.
	 * @return bool
	 */
	public static function should_switch_to_manual_mode( $name_updated, $name_mode, $source_context_changed ) {
		return $name_updated && ! ( 'auto' === $name_mode && $source_context_changed );
	}

	/**
	 * Apply auto naming when account, feed type, or source id changes.
	 *
	 * @since 6.9.0
	 *
	 * @param array<string,mixed> $data             Update payload (by reference).
	 * @param array<string,mixed> $next_settings    Next settings (by reference).
	 * @param string              $name_mode        Current name mode.
	 * @param object              $account          Account row.
	 * @param string              $next_feed_type   Resolved feed type.
	 * @param string              $next_source_id   Resolved source id.
	 * @param int                 $next_account     Next account ID.
	 * @param array<string,mixed> $config           Module naming config.
	 * @return string Updated name mode.
	 */
	public static function apply_auto_name_on_source_change(
		array &$data,
		array &$next_settings,
		$name_mode,
		$account,
		$next_feed_type,
		$next_source_id,
		$next_account,
		array $config
	) {
		if ( 'auto' !== $name_mode || empty( $config['source_context_changed'] ) ) {
			return $name_mode;
		}

		$data['name']     = self::build_auto_feed_name_for_source( $account, $next_feed_type, $next_source_id, $config );
		$data['settings'] = self::set_name_meta( $next_settings, 'auto', $next_account );

		return 'auto';
	}

	/**
	 * Build the default feed name from account username.
	 *
	 * @since 6.9.0
	 *
	 * @param object $account        Account row selected for the feed.
	 * @param string $fallback_label Fallback label when username is empty.
	 * @return string
	 */
	public static function build_username_feed_name( $account, $fallback_label ) {
		$username = '';
		if ( is_object( $account ) && isset( $account->username ) ) {
			$username = ltrim( sanitize_text_field( (string) $account->username ), '@' );
		}

		if ( '' === $username ) {
			return (string) $fallback_label;
		}

		return sprintf(
			/* translators: %s: account username */
			__( '@%s Feed', 'easy-facebook-likebox' ),
			$username
		);
	}

	/**
	 * Build the default feed name from the active source context.
	 *
	 * Modules without hashtag feeds can omit `hashtag_feed_type` and
	 * `normalize_hashtag` in the config.
	 *
	 * @since 6.9.0
	 *
	 * @param object              $account        Account row selected for the feed.
	 * @param string              $feed_type      Feed type.
	 * @param string              $source_id      Source id / hashtag.
	 * @param array<string,mixed> $config         Module naming config.
	 * @return string
	 */
	public static function build_auto_feed_name_for_source( $account, $feed_type, $source_id, array $config ) {
		$hashtag_type = isset( $config['hashtag_feed_type'] ) ? sanitize_key( (string) $config['hashtag_feed_type'] ) : 'hashtag';
		$feed_type    = sanitize_key( (string) $feed_type );

		if ( $hashtag_type === $feed_type ) {
			$normalize  = $config['normalize_hashtag'] ?? null;
			$normalized = '';
			if ( is_callable( $normalize ) ) {
				$normalized = sanitize_text_field( (string) call_user_func( $normalize, $source_id ) );
			}

			if ( '' !== $normalized ) {
				return sprintf(
					/* translators: %s: hashtag without # */
					__( '#%s Feed', 'easy-facebook-likebox' ),
					$normalized
				);
			}
		}

		$fallback = isset( $config['account_fallback'] )
			? (string) $config['account_fallback']
			: __( 'Feed', 'easy-facebook-likebox' );

		return self::build_username_feed_name( $account, $fallback );
	}
}
