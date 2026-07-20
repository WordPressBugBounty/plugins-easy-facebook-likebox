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
		);
	}
}
