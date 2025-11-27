<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Options;

use const ABSPATH;
use function __;
use function array_rand;
use function base64_encode;
use function class_exists;
use function lcfirst;
use function explode;
use function extension_loaded;
use function imagecolorallocate;
use function imagecolorallocatealpha;
use function imagecreatefromstring;
use function imagedestroy;
use function imagefilledrectangle;
use function imagejpeg;
use function imagestring;
use function imagesx;
use function imagesy;
use function imagewebp;
use function implode;
use function is_array;
use function is_wp_error;
use function json_decode;
use function ob_get_clean;
use function ob_start;
use function set_post_thumbnail;
use function sprintf;
use function strip_tags;
use function time;
use function update_post_meta;
use function wordwrap;
use function wp_generate_attachment_metadata;
use function wp_insert_attachment;
use function wp_strip_all_tags;
use function wp_trim_words;
use function wp_update_attachment_metadata;
use function wp_upload_bits;

/**
 * Buduje grafiki wyróżniające.
 */
class FeaturedImageBuilder {
	private Client $http_client;

	public function __construct(
		private Logger $logger,
		private AiClient $ai_client,
		?Client $http_client = null
	) {
		$this->http_client = $http_client ?: new Client(
			array(
				'timeout' => 15,
			)
		);
	}

	/**
	 * @param array<string, mixed> $article
	 */
	public function build( int $post_id, array $article ): ?int {
		$blob = $this->generate_image_blob( $article, true );

		if ( ! $blob ) {
			return null;
		}

		return $this->persist_attachment( $post_id, $blob, $article );
	}

	/**
	 * @param array<string, mixed> $article
	 */
	public function preview( array $article ): ?string {
		$blob = $this->generate_image_blob( $article, false );

		if ( ! $blob ) {
			return null;
		}

		return 'data:image/webp;base64,' . base64_encode( $blob );
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function generate_image_blob( array $article, bool $respect_toggle = true ): ?string {
		if ( $respect_toggle && ! Options::get( 'enable_featured_images' ) ) {
			return null;
		}

		$mode = (string) Options::get( 'image_generation_mode', 'server' );

		return 'remote' === $mode
			? $this->generate_remote_image( $article )
			: $this->generate_server_image( $article );
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function generate_remote_image( array $article ): ?string {
		$binary = $this->ai_client->generate_remote_image( $article );

		if ( empty( $binary ) ) {
			$this->logger->warning( 'OpenAI Images API nie zwróciło grafiki.' );

			return null;
		}

		return $binary;
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function generate_server_image( array $article ): ?string {
		$engine = (string) Options::get( 'image_server_engine', 'imagick' );

		if ( 'imagick' === $engine && ! class_exists( Imagick::class ) ) {
			$this->logger->warning( 'Imagick nie jest dostępny – użyję biblioteki GD.' );
			$engine = 'gd';
		}

		if ( 'gd' === $engine && ! extension_loaded( 'gd' ) ) {
			$this->logger->warning( 'Biblioteka GD nie jest dostępna na serwerze.' );

			return null;
		}

		$image_url = $this->fetch_pixabay_url();

		if ( ! $image_url ) {
			$this->logger->warning( 'Brak zdjęć Pixabay do wygenerowania grafiki.' );

			return null;
		}

		$binary = $this->download_image( $image_url );

		if ( ! $binary ) {
			return null;
		}

		return 'gd' === $engine
			? $this->process_with_gd( $binary, $article )
			: $this->process_with_imagick( $binary, $article );
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function process_with_imagick( string $binary, array $article ): ?string {
		try {
			$imagick = new Imagick();
			$imagick->readImageBlob( $binary );
			$imagick->setImageColorspace( Imagick::COLORSPACE_SRGB );
			$this->apply_overlay( $imagick, (string) Options::get( 'image_overlay_color', '1b1f3b' ) );
			$this->annotate_caption( $imagick, $article );
			$imagick->setImageFormat( 'webp' );

			return $imagick->getImageBlob();
		} catch ( \Throwable $throwable ) {
			$this->logger->error(
				'Nie udało się przetworzyć grafiki AI (Imagick).',
				array( 'exception' => $throwable->getMessage() )
			);
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function process_with_gd( string $binary, array $article ): ?string {
		$canvas = imagecreatefromstring( $binary );

		if ( false === $canvas ) {
			return null;
		}

		$width    = imagesx( $canvas );
		$height   = imagesy( $canvas );
		$hex      = $this->hex_to_rgb( (string) Options::get( 'image_overlay_color', '1b1f3b' ) );
		$overlay  = imagecolorallocatealpha( $canvas, $hex['r'], $hex['g'], $hex['b'], 110 );

		imagefilledrectangle( $canvas, 0, (int) ( $height * 0.65 ), $width, $height, $overlay );

		$caption   = $this->build_caption( $article );
		$lines     = explode( "\n", wordwrap( $caption, 30 ) );
		$lineCount = count( $lines );
		$startY    = max( 12, $height - ( $lineCount * 18 ) - 18 );
		$white     = imagecolorallocate( $canvas, 255, 255, 255 );

		foreach ( $lines as $line ) {
			imagestring( $canvas, 5, 20, $startY, $line, $white );
			$startY += 18;
		}

		ob_start();
		if ( function_exists( 'imagewebp' ) ) {
			imagewebp( $canvas );
		} else {
			imagejpeg( $canvas, null, 90 );
		}
		$blob = ob_get_clean();
		imagedestroy( $canvas );

		return $blob ?: null;
	}

	private function fetch_pixabay_url(): ?string {
		$api_key = Options::get( 'pixabay_api_key', '' );

		if ( empty( $api_key ) ) {
			return null;
		}

		$query       = Options::get( 'pixabay_query', 'qr code' );
		$orientation = Options::get( 'pixabay_orientation', 'horizontal' );

		try {
			$response = $this->http_client->get(
				'https://pixabay.com/api/',
				array(
					'query' => array(
						'key'         => $api_key,
						'q'           => $query,
						'image_type'  => 'photo',
						'orientation' => $orientation,
						'safesearch'  => 'true',
						'per_page'    => 20,
					),
				)
			);
		} catch ( GuzzleException $exception ) {
			$this->logger->warning(
				'Pixabay API jest nieosiągalne.',
				array( 'exception' => $exception->getMessage() )
			);

			return null;
		}

		$data = json_decode( (string) $response->getBody(), true );

		if ( empty( $data['hits'] ) || ! is_array( $data['hits'] ) ) {
			return null;
		}

		$hit = $data['hits'][ array_rand( $data['hits'] ) ];

		return $hit['largeImageURL'] ?? $hit['webformatURL'] ?? null;
	}

	private function download_image( string $url ): ?string {
		try {
			$response = $this->http_client->get( $url );
		} catch ( GuzzleException $exception ) {
			$this->logger->warning(
				'Nie udało się pobrać pliku Pixabay.',
				array( 'exception' => $exception->getMessage() )
			);

			return null;
		}

		return (string) $response->getBody();
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function persist_attachment( int $post_id, string $blob, array $article ): ?int {
		$filename = sprintf( 'kasumi-ai-%d-%d.webp', $post_id, time() );
		$upload   = wp_upload_bits( $filename, null, $blob );

		if ( ! empty( $upload['error'] ) ) {
			$this->logger->error(
				'Błąd zapisu grafiki AI w katalogu upload.',
				array( 'error' => $upload['error'] )
			);

			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/webp',
				'post_title'     => wp_strip_all_tags( ( $article['title'] ?? '' ) . ' – grafika Kasumi AI' ),
				'post_status'    => 'inherit',
				'guid'           => $upload['url'],
			),
			$upload['file'],
			$post_id
		);

		if ( is_wp_error( $attachment_id ) ) {
			$this->logger->error(
				'Nie można utworzyć załącznika grafiki AI.',
				array( 'error' => $attachment_id->get_error_message() )
			);

			return null;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $this->build_alt_text( $article ) );
		set_post_thumbnail( $post_id, $attachment_id );

		return $attachment_id;
	}

	private function apply_overlay( Imagick $imagick, string $color ): void {
		$overlay = new Imagick();
		$overlay->newImage( $imagick->getImageWidth(), $imagick->getImageHeight(), new ImagickPixel( '#' . $color ) );
		$overlay->setImageAlpha( 0.35 );
		$imagick->compositeImage( $overlay, Imagick::COMPOSITE_OVER, 0, 0 );
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function annotate_caption( Imagick $imagick, array $article ): void {
		$caption = $this->build_caption( $article );

		$draw = new ImagickDraw();
		$draw->setFillColor( new ImagickPixel( '#ffffff' ) );
		$draw->setFontSize( max( 40, (int) ( $imagick->getImageWidth() / 20 ) ) );
		$draw->setFontWeight( 600 );
		$draw->setGravity( Imagick::GRAVITY_SOUTHWEST );
		$draw->setTextKerning( 1.2 );
		$imagick->annotateImage( $draw, 48, 48, 0, strip_tags( $caption ) );
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function build_alt_text( array $article ): string {
		$title   = wp_strip_all_tags( (string) ( $article['title'] ?? '' ) );
		$summary = wp_trim_words(
			wp_strip_all_tags( (string) ( $article['summary'] ?? $article['excerpt'] ?? '' ) ),
			16
		);

		if ( '' === $title ) {
			return __( 'Grafika wyróżniająca Kasumi AI', 'kasumi-ai-generator' );
		}

		if ( '' === $summary ) {
			return sprintf(
				/* translators: %s is the post title. */
				__( '%s – grafika wyróżniająca Kasumi AI', 'kasumi-ai-generator' ),
				$title
			);
		}

		return sprintf(
			/* translators: 1: post title, 2: article summary. */
			__( '%1$s – grafika do artykułu o %2$s', 'kasumi-ai-generator' ),
			$title,
			lcfirst( $summary )
		);
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function build_caption( array $article ): string {
		$template = (string) Options::get( 'image_template', 'Kasumi AI – {{title}}' );

		return strtr(
			$template,
			array(
				'{{title}}'   => (string) ( $article['title'] ?? '' ),
				'{{summary}}' => (string) ( $article['summary'] ?? $article['excerpt'] ?? '' ),
			)
		);
	}

	/**
	 * @return array{r:int,g:int,b:int}
	 */
	private function hex_to_rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$int = hexdec( $hex );

		return array(
			'r' => ( $int >> 16 ) & 255,
			'g' => ( $int >> 8 ) & 255,
			'b' => $int & 255,
		);
	}
}
