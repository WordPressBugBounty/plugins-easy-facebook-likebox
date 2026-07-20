<?php
/**
 * PostMedia value object.
 *
 * Single media attachment on a post (photo / video / carousel item).
 *
 * @package Easy_Social_Feed
 * @subpackage Layouts\ValueObjects
 * @since 6.9.0
 */

namespace EasySocialFeed\Layouts\ValueObjects;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PostMedia {

	const TYPE_PHOTO = 'photo';
	const TYPE_VIDEO = 'video';
	const TYPE_REEL  = 'reel';

	/**
	 * @var string
	 */
	private $type;

	/**
	 * @var string
	 */
	private $url;

	/**
	 * @var string
	 */
	private $preview_url;

	/**
	 * @var string
	 */
	private $video_url;

	/**
	 * @var int
	 */
	private $width;

	/**
	 * @var int
	 */
	private $height;

	/**
	 * @var string
	 */
	private $alt;

	/**
	 * Whether the tile should render a CSS poster surface instead of an image.
	 *
	 * @var bool
	 */
	private $is_placeholder_poster;

	/**
	 * @param array<string,mixed> $data Raw media payload.
	 */
	public function __construct( array $data ) {
		$type = isset( $data['type'] ) ? (string) $data['type'] : self::TYPE_PHOTO;
		if ( ! in_array( $type, array( self::TYPE_PHOTO, self::TYPE_VIDEO, self::TYPE_REEL ), true ) ) {
			$type = self::TYPE_PHOTO;
		}
		$this->type        = $type;
		$this->url         = isset( $data['url'] ) ? (string) $data['url'] : '';
		$this->preview_url = isset( $data['preview_url'] ) ? (string) $data['preview_url'] : '';
		$this->video_url   = isset( $data['video_url'] ) ? (string) $data['video_url'] : '';
		$this->width       = isset( $data['width'] ) ? (int) $data['width'] : 0;
		$this->height      = isset( $data['height'] ) ? (int) $data['height'] : 0;
		$this->alt         = isset( $data['alt'] ) ? (string) $data['alt'] : '';
		$this->is_placeholder_poster = ! empty( $data['is_placeholder_poster'] );
	}

	public function get_type(): string {
		return $this->type;
	}

	public function is_photo(): bool {
		return self::TYPE_PHOTO === $this->type;
	}

	public function is_video(): bool {
		return self::TYPE_VIDEO === $this->type || self::TYPE_REEL === $this->type;
	}

	public function get_url(): string {
		return $this->url;
	}

	public function get_preview_url(): string {
		return $this->preview_url;
	}

	public function get_video_url(): string {
		return $this->video_url;
	}

	/**
	 * Best display URL: prefers preview for video, raw URL for photo.
	 */
	public function get_display_url(): string {
		if ( $this->is_photo() ) {
			return '' !== $this->url ? $this->url : $this->preview_url;
		}
		return '' !== $this->preview_url ? $this->preview_url : $this->url;
	}

	public function get_width(): int {
		return $this->width;
	}

	public function get_height(): int {
		return $this->height;
	}

	public function get_alt(): string {
		return $this->alt;
	}

	/**
	 * Whether the grid tile should use a theme-aware CSS poster instead of an image.
	 */
	public function has_placeholder_poster(): bool {
		return $this->is_placeholder_poster;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'type'                  => $this->type,
			'url'                   => $this->url,
			'preview_url'           => $this->preview_url,
			'video_url'             => $this->video_url,
			'width'                 => $this->width,
			'height'                => $this->height,
			'alt'                   => $this->alt,
			'is_placeholder_poster' => $this->is_placeholder_poster,
		);
	}
}
