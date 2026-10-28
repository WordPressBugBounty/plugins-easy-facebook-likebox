<?php
/**
 * AccountSummary value object.
 *
 * Module-agnostic shape for an account header / post author.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\ValueObjects
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\ValueObjects;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AccountSummary value object.
 *
 * Magic property aliases (via {@see __get()} / {@see __isset()}) keep legacy
 * Twitter/YouTube layout property access working after the SharedRenderer migration.
 *
 * @since 6.9.0
 *
 * @property-read int    $id
 * @property-read string $name
 * @property-read string $display_name
 * @property-read string $channel_name
 * @property-read string $handle
 * @property-read string $username
 * @property-read string $avatar_url
 * @property-read string $profile_image_url
 * @property-read string $channel_thumbnail
 * @property-read string $profile_url
 * @property-read string $bio
 * @property-read string $description
 * @property-read string $website
 * @property-read int    $followers_count
 * @property-read int    $subscriber_count
 * @property-read int    $following_count
 * @property-read int    $tweet_count
 * @property-read int    $video_count
 * @property-read int    $view_count
 * @property-read bool   $is_verified
 * @property-read bool   $verified
 * @property-read string $channel_banner
 * @property-read string $channel_id
 * @property-read string $verified_type
 */
final class AccountSummary {

	/**
	 * @var int
	 */
	private $id;

	/**
	 * @var string
	 */
	private $name;

	/**
	 * @var string
	 */
	private $handle;

	/**
	 * @var string
	 */
	private $avatar_url;

	/**
	 * @var string
	 */
	private $profile_url;

	/**
	 * @var string
	 */
	private $bio;

	/**
	 * External profile website URL (Instagram bio link).
	 *
	 * @var string
	 */
	private $website;

	/**
	 * @var bool
	 */
	private $is_verified;

	/**
	 * Stat counters keyed by metric slug.
	 *
	 * @var array<string,int>
	 */
	private $stats;

	/**
	 * Optional string extras (module-specific meta such as location labels).
	 *
	 * @var array<string,string>
	 */
	private $extras;

	/**
	 * @param array<string,mixed> $data Raw account payload.
	 */
	public function __construct( array $data ) {
		$this->id          = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$this->name        = isset( $data['name'] ) ? (string) $data['name'] : '';
		$this->handle      = isset( $data['handle'] ) ? (string) $data['handle'] : '';
		$this->avatar_url  = isset( $data['avatar_url'] ) ? (string) $data['avatar_url'] : '';
		$this->profile_url = isset( $data['profile_url'] ) ? (string) $data['profile_url'] : '';
		$this->bio         = isset( $data['bio'] ) ? (string) $data['bio'] : '';
		$this->website     = isset( $data['website'] ) ? (string) $data['website'] : '';
		$this->is_verified = ! empty( $data['is_verified'] );
		$this->stats       = array();
		if ( isset( $data['stats'] ) && is_array( $data['stats'] ) ) {
			foreach ( $data['stats'] as $key => $value ) {
				if ( is_string( $key ) || is_int( $key ) ) {
					$this->stats[ (string) $key ] = (int) $value;
				}
			}
		}
		$this->extras = array();
		if ( isset( $data['extras'] ) && is_array( $data['extras'] ) ) {
			foreach ( $data['extras'] as $key => $value ) {
				if ( ( is_string( $key ) || is_int( $key ) ) && ( is_string( $value ) || is_numeric( $value ) ) ) {
					$this->extras[ (string) $key ] = (string) $value;
				}
			}
		}
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_handle(): string {
		return $this->handle;
	}

	public function get_avatar_url(): string {
		return $this->avatar_url;
	}

	public function get_profile_url(): string {
		return $this->profile_url;
	}

	public function get_bio(): string {
		return $this->bio;
	}

	public function get_website(): string {
		return $this->website;
	}

	public function is_verified(): bool {
		return $this->is_verified;
	}

	/**
	 * @return array<string,int>
	 */
	public function get_stats(): array {
		return $this->stats;
	}

	public function get_stat( string $key, int $default = 0 ): int {
		return isset( $this->stats[ $key ] ) ? (int) $this->stats[ $key ] : $default;
	}

	/**
	 * @return array<string,string>
	 */
	public function get_extras(): array {
		return $this->extras;
	}

	public function get_extra( string $key, string $default = '' ): string {
		return isset( $this->extras[ $key ] ) ? (string) $this->extras[ $key ] : $default;
	}

	/**
	 * Magic getter for backward compatibility with legacy code expecting direct property access.
	 *
	 * Maps old property names to the new getter methods:
	 * - display_name / channel_name -> name
	 * - username -> handle
	 * - profile_image_url / channel_thumbnail -> avatar_url
	 * - description -> bio
	 * - followers_count / subscriber_count -> stats['followers']
	 * - following_count -> stats['following']
	 * - tweet_count / video_count -> stats or extras
	 * - is_verified / verified -> is_verified
	 * - channel_banner -> extras banner_url / channel_banner
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( string $name ) {
		switch ( $name ) {
			case 'id':
				return $this->id;
			case 'name':
			case 'display_name':
			case 'channel_name':
				return $this->name;
			case 'handle':
			case 'username':
				return $this->handle;
			case 'avatar_url':
			case 'profile_image_url':
			case 'channel_thumbnail':
				return $this->avatar_url;
			case 'profile_url':
				return $this->profile_url;
			case 'bio':
			case 'description':
				return $this->bio;
			case 'website':
				return $this->website;
			case 'followers_count':
			case 'subscriber_count':
				return $this->get_stat( 'followers', 0 );
			case 'following_count':
				return $this->get_stat( 'following', 0 );
			case 'tweet_count':
				return $this->get_stat( 'tweets', 0 );
			case 'video_count':
				$from_stats = $this->get_stat( 'videos', 0 );
				if ( $from_stats > 0 ) {
					return $from_stats;
				}
				$from_extras = $this->get_extra( 'video_count', '' );
				return '' !== $from_extras ? (int) $from_extras : 0;
			case 'view_count':
				$from_stats = $this->get_stat( 'views', 0 );
				if ( $from_stats > 0 ) {
					return $from_stats;
				}
				$from_extras = $this->get_extra( 'view_count', '' );
				return '' !== $from_extras ? (int) $from_extras : 0;
			case 'is_verified':
			case 'verified':
				return $this->is_verified;
			case 'channel_banner':
				$banner = $this->get_extra( 'channel_banner', '' );
				return '' !== $banner ? $banner : $this->get_extra( 'banner_url', '' );
			default:
				return $this->get_extra( $name, '' );
		}
	}

	/**
	 * Magic isset for legacy property-style access (`isset( $account->channel_name )`).
	 *
	 * Without this, PHP's `isset()` always returns false for magic properties
	 * even when `__get()` would return a value — which broke YouTube header /
	 * lightbox channel fields after the AccountSummary migration.
	 *
	 * @param string $name Property name.
	 * @return bool
	 */
	public function __isset( string $name ): bool {
		switch ( $name ) {
			case 'id':
			case 'name':
			case 'display_name':
			case 'channel_name':
			case 'handle':
			case 'username':
			case 'avatar_url':
			case 'profile_image_url':
			case 'channel_thumbnail':
			case 'profile_url':
			case 'bio':
			case 'description':
			case 'website':
			case 'followers_count':
			case 'subscriber_count':
			case 'following_count':
			case 'tweet_count':
			case 'video_count':
			case 'view_count':
			case 'is_verified':
			case 'verified':
			case 'channel_banner':
			case 'channel_id':
			case 'verified_type':
				return true;
			default:
				return array_key_exists( $name, $this->extras );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'          => $this->id,
			'name'        => $this->name,
			'handle'      => $this->handle,
			'avatar_url'  => $this->avatar_url,
			'profile_url' => $this->profile_url,
			'bio'         => $this->bio,
			'website'     => $this->website,
			'is_verified' => $this->is_verified,
			'stats'       => $this->stats,
			'extras'      => $this->extras,
		);
	}
}
