<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Service;

use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Options;
use Kasumi\AIGenerator\Status\StatusStore;

use function __;
use function array_filter;
use function array_map;
use function current_time;
use function explode;
use function hash;
use function is_wp_error;
use function sanitize_title;
use function sprintf;
use function time;
use function trim;
use function update_post_meta;
use function wp_insert_post;
use function wp_json_encode;
use function wp_strip_all_tags;
use function wp_trim_words;

use const JSON_PRETTY_PRINT;

/**
 * Koordynuje generowanie postów, obrazków oraz komentarzy.
 */
class PostGenerator {
	public function __construct(
		private AiClient $ai_client,
		private FeaturedImageBuilder $image_builder,
		private LinkBuilder $link_builder,
		private CommentGenerator $comment_generator,
		private ContextResolver $context_resolver,
		private Logger $logger
	) {}

	public function generate(): ?int {
		$context     = $this->context_resolver->get_prompt_context();
		$user_prompt = $this->build_prompt( $context );

		$article = $this->ai_client->generate_article(
			array(
				'user_prompt'   => $user_prompt,
				'system_prompt' => Options::get( 'system_prompt' ),
			)
		);

		if ( ! is_array( $article ) || empty( $article['content'] ) ) {
			$this->logger->warning( 'OpenAI zwróciło pusty wynik, wpis nie został utworzony.' );

			return null;
		}

		if ( Options::get( 'preview_mode' ) ) {
			$this->logger->info(
				'Tryb podglądu AI – wygenerowano tekst bez zapisu.',
				array(
					'title' => $article['title'] ?? '',
				)
			);

			return null;
		}

		$article['content'] = $this->maybe_apply_internal_links( $article );

		$post_id = $this->create_post( $article );

		if ( ! $post_id ) {
			return null;
		}

		if ( Options::get( 'enable_featured_images' ) ) {
			$this->image_builder->build( $post_id, $article );
		}

		$this->comment_generator->schedule_for_post( $post_id, $article );

		StatusStore::merge(
			array(
				'last_post_id'   => $post_id,
				'last_post_time' => time(),
			)
		);

		return $post_id;
	}

	private function build_prompt( array $context ): string {
		$min  = (int) Options::get( 'word_count_min', 600 );
		$max  = (int) Options::get( 'word_count_max', 1200 );
		$goal = Options::get( 'topic_strategy', '' );

		return sprintf(
			"Strategia tematów: %s\nWymagana liczba słów: %d-%d.\nKontekst kategorii: %s\nOstatnie wpisy: %s\nZwróć poprawny JSON {\"title\",\"slug\",\"excerpt\",\"content\",\"summary\"}.",
			$goal,
			$min,
			$max,
			wp_json_encode( $context['categories'], JSON_PRETTY_PRINT ),
			wp_json_encode( $context['recent_posts'], JSON_PRETTY_PRINT )
		);
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function create_post( array $article ): ?int {
		$title   = wp_strip_all_tags( (string) ( $article['title'] ?? '' ) );
		$content = (string) ( $article['content'] ?? '' );

		$postarr = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => (string) ( $article['excerpt'] ?? wp_trim_words( wp_strip_all_tags( $content ), 40 ) ),
			'post_status'  => Options::get( 'default_post_status', 'draft' ),
			'post_name'    => sanitize_title( (string) ( $article['slug'] ?? $title ) ),
			'post_category' => $this->resolve_category(),
		);

		$result = wp_insert_post( $postarr, true );

		if ( is_wp_error( $result ) ) {
			$this->logger->error(
				'Nie udało się zapisać posta wygenerowanego przez AI.',
				array( 'error' => $result->get_error_message() )
			);

			return null;
		}

		update_post_meta(
			$result,
			'_kasumi_ai_content_meta',
			array(
				'generated_at' => current_time( 'mysql' ),
				'prompt_hash'  => hash( 'sha256', $title . ( $article['summary'] ?? '' ) ),
			)
		);

		$this->logger->info(
			'Utworzono wpis AI.',
			array(
				'post_id' => $result,
				'title'   => $title,
			)
		);

		return (int) $result;
	}

	private function get_link_keywords(): array {
		$list = (string) Options::get( 'link_keywords', '' );

		if ( empty( $list ) ) {
			return array();
		}

		return array_filter(
			array_map( 'trim', explode( ',', $list ) ),
			static fn( $keyword ) => '' !== $keyword
		);
	}

	private function resolve_category(): array {
		$category = (string) Options::get( 'target_category', '' );

		if ( empty( $category ) ) {
			return array();
		}

		return array( (int) $category );
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function maybe_apply_internal_links( array $article ): string {
		if ( ! Options::get( 'enable_internal_linking' ) ) {
			return (string) ( $article['content'] ?? '' );
		}

		$content = (string) ( $article['content'] ?? '' );

		if ( '' === trim( $content ) ) {
			return $content;
		}

		$candidates  = $this->get_link_candidates();
		$suggestions = $this->ai_client->suggest_internal_links(
			array(
				'title'   => $article['title'] ?? '',
				'excerpt' => $article['excerpt'] ?? '',
				'content' => wp_strip_all_tags( $content ),
			),
			$candidates,
			$this->get_link_keywords()
		);

		if ( empty( $suggestions ) ) {
			return $content;
		}

		return $this->link_builder->inject_links( $content, $suggestions );
	}

}
