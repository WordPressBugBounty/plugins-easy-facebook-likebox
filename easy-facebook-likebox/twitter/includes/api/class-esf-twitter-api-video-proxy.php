<?php
/**
 * Same-origin stream proxy for X video files.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter/API
 * @since 6.14.1
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Twitter_API_Video_Proxy
 *
 * @since 6.14.1
 */
class ESF_Twitter_API_Video_Proxy {

	/**
	 * Hosts allowed to be streamed (SSRF allowlist).
	 *
	 * @var array<int,string>
	 */
	const ALLOWED_HOSTS = array(
		'video.twimg.com',
		'video.twitter.com',
	);

	/**
	 * Query flag used by `<video src>` (avoids WP REST cookie-nonce 403).
	 *
	 * @var string
	 */
	const QUERY_FLAG = 'esf_tw_video';

	/**
	 * Register frontend stream hook + optional REST route.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		// init@20: stream before the theme; also works if template_redirect is skipped.
		add_action( 'init', array( __CLASS__, 'maybe_stream_query' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_stream_query' ), 0 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'allow_logged_in_video_rest' ), 101 );
	}

	/**
	 * Register the public video stream route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'esf/v1',
			'/twitter/media/video',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'stream' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'url' => array(
						'description'       => __( 'Remote X video URL.', 'easy-facebook-likebox' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
	}

	/**
	 * WP REST rejects cookie-authenticated requests without X-WP-Nonce.
	 * `<video src="/wp-json/...">` cannot send that header (admin preview 403).
	 *
	 * @param mixed $result Auth result.
	 * @return mixed
	 */
	public static function allow_logged_in_video_rest( $result ) {
		if ( ! is_wp_error( $result ) || 'rest_cookie_invalid_nonce' !== $result->get_error_code() ) {
			return $result;
		}

		$route = '';
		if ( isset( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		} elseif ( isset( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$route = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
		}

		if ( false === strpos( $route, '/twitter/media/video' ) ) {
			return $result;
		}

		return null;
	}

	/**
	 * Stream when `?esf_tw_video=1&u=` is requested on the front end.
	 *
	 * @return void
	 */
	public static function maybe_stream_query() {
		if ( ! isset( $_GET[ self::QUERY_FLAG ] ) || '1' !== (string) wp_unslash( $_GET[ self::QUERY_FLAG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return;
		}

		$url = isset( $_GET['u'] ) ? esc_url_raw( wp_unslash( (string) $_GET['u'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! self::is_allowed_video_url( $url ) ) {
			status_header( 400 );
			exit;
		}

		if ( ! function_exists( 'curl_init' ) ) {
			status_header( 501 );
			exit;
		}

		self::stream_remote( $url );
	}

	/**
	 * Whether a URL is a streamable X video CDN URL.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function is_allowed_video_url( $url ) {
		$url   = trim( (string) $url );
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return false;
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return false;
		}
		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( '' === $host || false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		return in_array( $host, self::ALLOWED_HOSTS, true );
	}

	/**
	 * Convert a remote X video URL into a same-origin playable URL.
	 *
	 * @param string $remote_url Remote CDN URL.
	 * @return string Proxy URL, or the original when it is not an X video.
	 */
	public static function get_playable_url( $remote_url ) {
		$remote_url = trim( (string) $remote_url );
		if ( '' === $remote_url || ! self::is_allowed_video_url( $remote_url ) ) {
			return $remote_url;
		}

		if ( ! function_exists( 'home_url' ) || ! function_exists( 'add_query_arg' ) ) {
			return $remote_url;
		}

		// Front-end query URL — `<video>` cannot send a REST nonce, so /wp-json 403s in wp-admin.
		return add_query_arg(
			array(
				self::QUERY_FLAG => '1',
				'u'              => $remote_url,
			),
			home_url( '/' )
		);
	}

	/**
	 * Stream the remote MP4. Exits after output (Range-aware, no body buffer).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void
	 */
	public static function stream( $request ) {
		$url = (string) $request->get_param( 'url' );
		if ( ! self::is_allowed_video_url( $url ) ) {
			return new WP_Error(
				'invalid_video_url',
				__( 'Invalid video URL.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error(
				'curl_missing',
				__( 'Video proxy requires cURL.', 'easy-facebook-likebox' ),
				array( 'status' => 501 )
			);
		}

		self::stream_remote( $url );
	}

	/**
	 * Stream a remote file to the client.
	 *
	 * @param string $url Remote HTTPS URL.
	 * @return void
	 */
	private static function stream_remote( $url ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$range = '';
		if ( isset( $_SERVER['HTTP_RANGE'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$range = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_RANGE'] ) );
		}

		$status_sent = false;
		$ch          = curl_init( $url );
		if ( false === $ch ) {
			status_header( 502 );
			exit;
		}

		$headers = array(
			'Accept: video/mp4,video/*,*/*;q=0.8',
		);
		if ( '' !== $range ) {
			$headers[] = 'Range: ' . $range;
		}

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_FOLLOWLOCATION  => true,
				CURLOPT_MAXREDIRS       => 3,
				CURLOPT_CONNECTTIMEOUT  => 10,
				CURLOPT_TIMEOUT         => 90,
				CURLOPT_REFERER         => '',
				CURLOPT_AUTOREFERER     => false,
				CURLOPT_USERAGENT       => 'Mozilla/5.0 (compatible; EasySocialFeed/Twitter)',
				CURLOPT_HTTPHEADER      => $headers,
				CURLOPT_SSL_VERIFYPEER  => true,
				CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
				CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
				CURLOPT_HEADERFUNCTION  => static function ( $curl, $header_line ) use ( &$status_sent ) {
					unset( $curl );
					$trimmed = trim( $header_line );
					if ( '' === $trimmed ) {
						return strlen( $header_line );
					}
					if ( preg_match( '/^HTTP\/\S+\s+(\d+)/', $trimmed, $match ) ) {
						status_header( (int) $match[1] );
						$status_sent = true;
						return strlen( $header_line );
					}
					if ( preg_match( '/^([^:]+):\s*(.+)$/', $trimmed, $match ) ) {
						$name = strtolower( $match[1] );
						$pass = array(
							'content-type',
							'content-length',
							'content-range',
							'accept-ranges',
							'cache-control',
							'etag',
							'last-modified',
						);
						if ( in_array( $name, $pass, true ) ) {
							header( $match[1] . ': ' . trim( $match[2] ), true );
						}
					}
					return strlen( $header_line );
				},
				CURLOPT_WRITEFUNCTION   => static function ( $curl, $data ) {
					unset( $curl );
					echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary video stream.
					return strlen( $data );
				},
			)
		);

		$ok = curl_exec( $ch );
		if ( false === $ok && ! $status_sent ) {
			status_header( 502 );
		}
		curl_close( $ch );
		exit;
	}
}
