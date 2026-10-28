<?php
/**
 * Showcase stage primitive (featured player above a feed).
 *
 * Renders a module-agnostic player shell: poster facade and optional CTA.
 * The shared frontend script (`esf-showcase.js`) creates the iframe on the
 * first user gesture and swaps `src` when a `[data-esf-showcase-item]` is
 * clicked. Modules pass labels, URLs, and the initial item payload — no
 * hardcoded module strings live here.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\Primitives
 * @since 6.18.0
 */

namespace EasySocialFeed\Layouts\Primitives;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Showcase stage primitive.
 *
 * @since 6.18.0
 */
final class ShowcaseStage {

	/**
	 * Allowed aspect-ratio tokens.
	 *
	 * @since 6.18.0
	 * @var string[]
	 */
	private const ASPECT_RATIOS = array( '16:9', '21:9', '4:3', '1:1', '4:5', '3:4', '9:16' );

	/**
	 * Upper bound for the client-configurable player width (px).
	 *
	 * @since 6.18.0
	 * @var int
	 */
	private const MAX_WIDTH_LIMIT = 1600;

	/**
	 * Render the showcase stage.
	 *
	 * Args:
	 * - poster_url (string) optional — thumbnail shown before play
	 * - embed_url (string) optional — https embed URL (empty when blocked)
	 * - aspect_ratio (string) optional — e.g. 16:9 (default), 9:16
	 * - play_label (string) required — accessible play button label
	 * - item (array) optional — initial item payload (JSON on the stage)
	 * - cta_html (string) optional — trusted HTML shown under the player
	 * - allowed_hosts (string[]) required for playback — hostnames the module's
	 *   embeds may use. Without it the stage renders in its blocked state, so a
	 *   tampered payload can never inject an arbitrary iframe.
	 *
	 * The stage shows the player only: no title, "Now playing" line or
	 * "watch on" link under it. Extra args (e.g. title, watch_url) are ignored.
	 * - blocked (bool) optional — third-party embeds blocked (GDPR)
	 * - max_width (int) optional — player max width in px; 0 = automatic
	 * - align (string) optional — center (default) | left
	 * - radius (int) optional — corner radius in px; -1 = theme default
	 * - now_playing_label (string) optional — label for the "Now playing" badge
	 *   shown on the active feed item; empty disables it
	 *
	 * @since 6.18.0
	 *
	 * @param string              $module Module slug.
	 * @param array<string,mixed> $args   Render args.
	 * @return string Stage markup, or '' when required args are missing.
	 */
	public static function render( string $module, array $args ): string {
		$module = sanitize_key( $module );
		$play   = isset( $args['play_label'] ) ? trim( (string) $args['play_label'] ) : '';

		if ( '' === $module || '' === $play ) {
			return '';
		}

		$poster_url = isset( $args['poster_url'] ) ? esc_url_raw( (string) $args['poster_url'] ) : '';
		$embed_url  = isset( $args['embed_url'] ) ? esc_url_raw( (string) $args['embed_url'] ) : '';
		$blocked    = ! empty( $args['blocked'] );
		$cta_html   = isset( $args['cta_html'] ) ? (string) $args['cta_html'] : '';

		$hosts      = self::normalize_hosts( $args['allowed_hosts'] ?? array() );

		if ( '' !== $embed_url && ! self::is_allowed_embed_url( $embed_url, $hosts ) ) {
			$embed_url = '';
		}
		if ( $blocked ) {
			$embed_url = '';
		}

		$aspect = isset( $args['aspect_ratio'] ) ? (string) $args['aspect_ratio'] : '16:9';
		if ( ! in_array( $aspect, self::ASPECT_RATIOS, true ) ) {
			$aspect = '16:9';
		}
		$ratio_css = str_replace( ':', ' / ', $aspect );
		$ratio_num = self::ratio_to_number( $aspect );
		$orient    = $ratio_num < 1 ? 'portrait' : 'landscape';

		$max_width = isset( $args['max_width'] ) ? (int) $args['max_width'] : 0;
		$max_width = max( 0, min( self::MAX_WIDTH_LIMIT, $max_width ) );
		$align     = isset( $args['align'] ) && 'left' === $args['align'] ? 'left' : 'center';
		$radius    = isset( $args['radius'] ) ? (int) $args['radius'] : -1;
		$radius    = $radius < 0 ? -1 : min( 48, $radius );
		$now_label = isset( $args['now_playing_label'] ) ? trim( (string) $args['now_playing_label'] ) : '';

		$item_json = '';
		if ( isset( $args['item'] ) && is_array( $args['item'] ) ) {
			$item = $args['item'];
			if ( isset( $item['embed_url'] ) && is_string( $item['embed_url'] ) && ! self::is_allowed_embed_url( $item['embed_url'], $hosts ) ) {
				$item['embed_url'] = '';
			}
			if ( $blocked ) {
				$item['embed_url'] = '';
			}
			$encoded = wp_json_encode( $item );
			if ( is_string( $encoded ) && '' !== $encoded ) {
				$item_json = $encoded;
			}
		}

		$root_class  = 'esf-' . $module . '-feed__showcase';
		$stage_class = 'esf-' . $module . '-feed__showcase-stage';
		if ( $blocked || '' === $embed_url ) {
			$stage_class .= ' esf-' . $module . '-feed__showcase-stage--blocked';
		}

		$style = '--esf-showcase-ratio:' . $ratio_css . ';--esf-showcase-ratio-num:' . $ratio_num;
		if ( $max_width > 0 ) {
			$style .= ';--esf-showcase-user-max:' . $max_width . 'px';
		}
		if ( $radius >= 0 ) {
			$style .= ';--esf-showcase-radius:' . $radius . 'px';
		}

		ob_start();
		?>
		<div
			class="<?php echo esc_attr( $root_class ); ?>"
			data-esf-showcase="1"
			data-esf-showcase-orient="<?php echo esc_attr( $orient ); ?>"
			data-esf-showcase-align="<?php echo esc_attr( $align ); ?>"
			data-esf-showcase-hosts="<?php echo esc_attr( implode( ',', $hosts ) ); ?>"
			<?php if ( '' !== $now_label ) : ?>
				data-esf-showcase-now-label="<?php echo esc_attr( $now_label ); ?>"
			<?php endif; ?>
			style="<?php echo esc_attr( $style ); ?>"
		>
			<div
				class="<?php echo esc_attr( $stage_class ); ?>"
				<?php if ( '' !== $item_json ) : ?>
					data-esf-showcase-active="<?php echo esc_attr( $item_json ); ?>"
				<?php endif; ?>
			>
				<?php if ( '' !== $embed_url ) : ?>
					<button
						type="button"
						class="esf-<?php echo esc_attr( $module ); ?>-feed__showcase-facade"
						data-esf-showcase-play="1"
						aria-label="<?php echo esc_attr( $play ); ?>"
					>
						<?php if ( '' !== $poster_url ) : ?>
							<img
								class="esf-<?php echo esc_attr( $module ); ?>-feed__showcase-poster"
								src="<?php echo esc_url( $poster_url ); ?>"
								alt=""
								loading="lazy"
								decoding="async"
							/>
						<?php endif; ?>
						<span class="esf-<?php echo esc_attr( $module ); ?>-feed__showcase-play" aria-hidden="true">
							<svg width="68" height="48" viewBox="0 0 68 48" xmlns="http://www.w3.org/2000/svg" focusable="false">
								<path d="M66.52 7.74a8.33 8.33 0 0 0-5.85-5.91C55.57.86 34 0.86 34 0.86s-21.57 0-26.67.97A8.33 8.33 0 0 0 1.48 7.74 87.1 87.1 0 0 0 .5 24a87.1 87.1 0 0 0 .98 16.26 8.33 8.33 0 0 0 5.85 5.91c5.1.97 26.67.97 26.67.97s21.57 0 26.67-.97a8.33 8.33 0 0 0 5.85-5.91A87.1 87.1 0 0 0 67.5 24a87.1 87.1 0 0 0-.98-16.26z" fill="#FF0000"/>
								<path d="M27.4 16.2v15.6L45.2 24 27.4 16.2z" fill="#fff"/>
							</svg>
						</span>
					</button>
				<?php elseif ( '' !== $poster_url ) : ?>
					<div class="esf-<?php echo esc_attr( $module ); ?>-feed__showcase-facade esf-<?php echo esc_attr( $module ); ?>-feed__showcase-facade--static">
						<img
							class="esf-<?php echo esc_attr( $module ); ?>-feed__showcase-poster"
							src="<?php echo esc_url( $poster_url ); ?>"
							alt=""
							loading="lazy"
							decoding="async"
						/>
					</div>
				<?php endif; ?>
				<div class="esf-<?php echo esc_attr( $module ); ?>-feed__showcase-frame" data-esf-showcase-frame hidden></div>
			</div>
			<?php if ( '' !== $cta_html ) : ?>
				<div class="esf-<?php echo esc_attr( $module ); ?>-feed__showcase-meta">
					<?php echo $cta_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted HTML from caller. ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Convert a "W:H" token to its numeric width / height ratio.
	 *
	 * @since 6.18.0
	 *
	 * @param string $aspect Allowed aspect token.
	 * @return float
	 */
	private static function ratio_to_number( string $aspect ): float {
		$parts = explode( ':', $aspect );
		if ( 2 !== count( $parts ) || (float) $parts[1] <= 0 ) {
			return 16 / 9;
		}

		return round( (float) $parts[0] / (float) $parts[1], 4 );
	}

	/**
	 * Whether an embed URL is safe to put in an iframe.
	 *
	 * Only https URLs whose host is in the module-supplied allowlist are
	 * accepted so a compromised payload cannot inject arbitrary frames.
	 *
	 * @since 6.18.0
	 *
	 * @param string   $url   Candidate embed URL.
	 * @param string[] $hosts Hostnames the calling module allows.
	 * @return bool
	 */
	public static function is_allowed_embed_url( string $url, array $hosts = array() ): bool {
		$url = trim( $url );
		if ( '' === $url || 0 !== stripos( $url, 'https://' ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		$host    = strtolower( $host );
		$allowed = self::normalize_hosts( $hosts );

		/**
		 * Filter hosts allowed for showcase iframe embeds.
		 *
		 * @since 6.18.0
		 *
		 * @param string[] $allowed Allowed hostnames (lowercase), as supplied by the module.
		 * @param string   $url     Candidate URL.
		 */
		$allowed = apply_filters( 'esf_layouts_showcase_allowed_embed_hosts', $allowed, $url );
		if ( ! is_array( $allowed ) ) {
			return false;
		}

		return in_array( $host, $allowed, true );
	}

	/**
	 * Lowercase, de-duplicate and drop invalid entries from a hostname list.
	 *
	 * @since 6.18.0
	 *
	 * @param mixed $hosts Raw host list from the module.
	 * @return string[]
	 */
	private static function normalize_hosts( $hosts ): array {
		if ( ! is_array( $hosts ) ) {
			return array();
		}

		$clean = array();
		foreach ( $hosts as $host ) {
			$host = strtolower( trim( (string) $host ) );
			if ( '' !== $host && 1 === preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
				$clean[] = $host;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
