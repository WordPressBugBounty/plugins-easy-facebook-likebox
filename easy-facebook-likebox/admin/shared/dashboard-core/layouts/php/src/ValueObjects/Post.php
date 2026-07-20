<?php
/**
 * Default PostItem implementation.
 *
 * Modules can either reuse this class or implement their own PostItem.
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\ValueObjects
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\ValueObjects;

use EasySocialFeed\Layouts\Contracts\PostItem;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Post implements PostItem {

	/**
	 * @var string
	 */
	private $id;

	/**
	 * @var string
	 */
	private $permalink;

	/**
	 * @var string
	 */
	private $created_at;

	/**
	 * @var string
	 */
	private $text_html;

	/**
	 * @var AccountSummary|null
	 */
	private $author;

	/**
	 * @var PostMedia[]
	 */
	private $media;

	/**
	 * @var array<string,int>
	 */
	private $metrics;

	/**
	 * @var array<string,mixed>
	 */
	private $extras;

	/**
	 * @param array<string,mixed> $data Post payload.
	 */
	public function __construct( array $data ) {
		$this->id         = isset( $data['id'] ) ? (string) $data['id'] : '';
		$this->permalink  = isset( $data['permalink'] ) ? (string) $data['permalink'] : '';
		$this->created_at = isset( $data['created_at'] ) ? (string) $data['created_at'] : '';
		$this->text_html  = isset( $data['text_html'] ) ? (string) $data['text_html'] : '';

		$author = isset( $data['author'] ) ? $data['author'] : null;
		if ( $author instanceof AccountSummary ) {
			$this->author = $author;
		} elseif ( is_array( $author ) ) {
			$this->author = new AccountSummary( $author );
		} else {
			$this->author = null;
		}

		$this->media = array();
		if ( isset( $data['media'] ) && is_array( $data['media'] ) ) {
			foreach ( $data['media'] as $entry ) {
				if ( $entry instanceof PostMedia ) {
					$this->media[] = $entry;
				} elseif ( is_array( $entry ) ) {
					$this->media[] = new PostMedia( $entry );
				}
			}
		}

		$this->metrics = array();
		if ( isset( $data['metrics'] ) && is_array( $data['metrics'] ) ) {
			foreach ( $data['metrics'] as $key => $value ) {
				if ( is_string( $key ) || is_int( $key ) ) {
					$this->metrics[ (string) $key ] = (int) $value;
				}
			}
		}

		$this->extras = isset( $data['extras'] ) && is_array( $data['extras'] ) ? $data['extras'] : array();
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_permalink(): string {
		return $this->permalink;
	}

	public function get_created_at(): string {
		return $this->created_at;
	}

	public function get_text_html(): string {
		return $this->text_html;
	}

	public function get_author(): ?AccountSummary {
		return $this->author;
	}

	/**
	 * @return PostMedia[]
	 */
	public function get_media(): array {
		return $this->media;
	}

	/**
	 * @return array<string,int>
	 */
	public function get_metrics(): array {
		return $this->metrics;
	}

	public function get_metric( string $key, int $default = 0 ): int {
		return isset( $this->metrics[ $key ] ) ? (int) $this->metrics[ $key ] : $default;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_extras(): array {
		return $this->extras;
	}

	public function get_extra( string $key, $default = null ) {
		return array_key_exists( $key, $this->extras ) ? $this->extras[ $key ] : $default;
	}

	public function has_media(): bool {
		return ! empty( $this->media );
	}

	public function get_primary_media(): ?PostMedia {
		return empty( $this->media ) ? null : $this->media[0];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'         => $this->id,
			'permalink'  => $this->permalink,
			'created_at' => $this->created_at,
			'text_html'  => $this->text_html,
			'author'     => $this->author ? $this->author->to_array() : null,
			'media'      => array_map(
				static function ( PostMedia $m ) {
					return $m->to_array();
				},
				$this->media
			),
			'metrics'    => $this->metrics,
			'extras'     => $this->extras,
		);
	}
}
