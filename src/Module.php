<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator;

use Kasumi\AIGenerator\Admin\ModelsController;
use Kasumi\AIGenerator\Admin\PreviewController;
use Kasumi\AIGenerator\Admin\SettingsPage;
use Kasumi\AIGenerator\Cron\Scheduler;
use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Service\AiClient;
use Kasumi\AIGenerator\Service\CommentGenerator;
use Kasumi\AIGenerator\Service\ContextResolver;
use Kasumi\AIGenerator\Service\FeaturedImageBuilder;
use Kasumi\AIGenerator\Service\LinkBuilder;
use Kasumi\AIGenerator\Service\PostGenerator;

/**
 * Bootstrap Kasumi AI generator.
 */
final class Module {
	private SettingsPage $settings_page;
	private Logger $logger;
	private Scheduler $scheduler;
	private PostGenerator $post_generator;
	private CommentGenerator $comment_generator;
	private PreviewController $preview_controller;
	private ModelsController $models_controller;
	private ContextResolver $context_resolver;

	public function __construct() {
		$this->settings_page = new SettingsPage();
		$this->logger        = new Logger();

		$ai_client            = new AiClient( $this->logger );
		$link_builder         = new LinkBuilder();
		$image_builder        = new FeaturedImageBuilder( $this->logger, $ai_client );
		$this->context_resolver = new ContextResolver();
		$this->comment_generator = new CommentGenerator( $ai_client, $this->logger );
		$this->post_generator    = new PostGenerator(
			$ai_client,
			$image_builder,
			$link_builder,
			$this->comment_generator,
			$this->context_resolver,
			$this->logger
		);
		$this->preview_controller = new PreviewController(
			$ai_client,
			$image_builder,
			$this->context_resolver,
			$this->logger
		);
		$this->models_controller = new ModelsController( $ai_client );

		$this->scheduler = new Scheduler(
			$this->post_generator,
			$this->comment_generator,
			$this->logger
		);
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this->settings_page, 'register_menu' ) );
		add_action( 'admin_init', array( $this->settings_page, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this->settings_page, 'enqueue_assets' ) );
		$this->scheduler->register();
		$this->preview_controller->register();
		$this->models_controller->register();
	}
}
