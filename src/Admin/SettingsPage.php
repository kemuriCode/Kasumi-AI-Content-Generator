<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Admin;

use Kasumi\AIGenerator\Options;
use Kasumi\AIGenerator\Status\StatusStore;

use function __;
use function add_settings_field;
use function add_settings_section;
use function add_submenu_page;
use function admin_url;
use function checked;
use function current_time;
use function current_user_can;
use function date_i18n;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_textarea;
use function esc_url;
use function get_option;
use function human_time_diff;
use function printf;
use function register_setting;
use function selected;
use function settings_fields;
use function submit_button;
use function wp_create_nonce;
use function wp_enqueue_script;
use function wp_kses_post;
use function wp_localize_script;
use function wp_parse_args;
use function wp_strip_all_tags;

/**
 * Panel konfiguracyjny modułu AI Content.
 */
class SettingsPage {
	private const PAGE_SLUG = 'kasumi-ai-generator-ai-content';

	public function register_menu(): void {
		add_submenu_page(
			'options-general.php',
			__( 'AI Content', 'kasumi-ai-generator' ),
			__( 'AI Content', 'kasumi-ai-generator' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			Options::OPTION_GROUP,
			Options::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Options::class, 'sanitize' ),
				'default'           => Options::defaults(),
			)
		);

		$this->register_api_section();
		$this->register_content_section();
		$this->register_image_section();
		$this->register_comments_section();
		$this->register_misc_section();
		$this->register_diagnostics_section();
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'kasumi-ai-preview',
			KASUMI_AI_URL . 'assets/js/ai-preview.js',
			array( 'wp-api-fetch' ),
			KASUMI_AI_VERSION,
			true
		);

		wp_localize_script(
			'kasumi-ai-preview',
			'kasumiAiPreview',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'kasumi_ai_preview' ),
				'i18n'    => array(
					'loading' => __( 'Generowanie w toku…', 'kasumi-ai-generator' ),
					'error'   => __( 'Coś poszło nie tak. Spróbuj ponownie.', 'kasumi-ai-generator' ),
				),
			)
		);

		wp_enqueue_script(
			'kasumi-ai-admin-ui',
			KASUMI_AI_URL . 'assets/js/admin-ui.js',
			array( 'jquery-ui-tabs', 'jquery-ui-tooltip' ),
			KASUMI_AI_VERSION,
			true
		);

		wp_localize_script(
			'kasumi-ai-admin-ui',
			'kasumiAiAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'kasumi_ai_models' ),
				'i18n'    => array(
					'fetching' => __( 'Ładowanie modeli…', 'kasumi-ai-generator' ),
					'noModels' => __( 'Brak modeli', 'kasumi-ai-generator' ),
					'error'    => __( 'Nie udało się pobrać modeli.', 'kasumi-ai-generator' ),
				),
			)
		);

		wp_enqueue_style(
			'kasumi-ai-admin',
			KASUMI_AI_URL . 'assets/css/admin.css',
			array(),
			KASUMI_AI_VERSION
		);
	}

	private function register_api_section(): void {
		$section = 'kasumi_ai_api';

		add_settings_section(
			$section,
			__( 'Klucze API', 'kasumi-ai-generator' ),
			function (): void {
				printf(
					'<p>%s</p>',
					esc_html__( 'Dodaj klucze OpenAI i Pixabay wykorzystywane do generowania treści i grafik.', 'kasumi-ai-generator' )
				);
			},
			self::PAGE_SLUG
		);

		$this->add_field(
			'openai_api_key',
			__( 'OpenAI API Key', 'kasumi-ai-generator' ),
			$section,
			array(
				'type'        => 'password',
				'placeholder' => 'sk-***',
				'description' => sprintf(
					/* translators: %s is a link to the OpenAI dashboard. */
					__( 'Pobierz klucz w %s.', 'kasumi-ai-generator' ),
					sprintf(
						'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
						esc_url( 'https://platform.openai.com/account/api-keys' ),
						esc_html__( 'panelu OpenAI', 'kasumi-ai-generator' )
					)
				),
				'help'        => __( 'Umożliwia korzystanie z modeli GPT-4.1 / GPT-4o.', 'kasumi-ai-generator' ),
			)
		);

		$this->add_field(
			'openai_model',
			__( 'Model OpenAI', 'kasumi-ai-generator' ),
			$section,
			array(
				'type'      => 'model-select',
				'provider'  => 'openai',
				'help'      => __( 'Lista modeli z konta OpenAI (np. GPT-4.1, GPT-4o).', 'kasumi-ai-generator' ),
			)
		);

		$this->add_field(
			'ai_provider',
			__( 'Dostawca AI', 'kasumi-ai-generator' ),
			$section,
			array(
				'type'        => 'select',
				'choices'     => array(
					'openai' => __( 'Tylko OpenAI', 'kasumi-ai-generator' ),
					'gemini' => __( 'Tylko Google Gemini', 'kasumi-ai-generator' ),
					'auto'   => __( 'Automatyczny (OpenAI → Gemini)', 'kasumi-ai-generator' ),
				),
				'description' => __( 'W trybie automatycznym system próbuje najpierw OpenAI, a w razie braku odpowiedzi przełącza się na Gemini.', 'kasumi-ai-generator' ),
			)
		);

		$this->add_field(
			'pixabay_api_key',
			__( 'Pixabay API Key', 'kasumi-ai-generator' ),
			$section,
			array(
				'placeholder' => '12345678-abcdef...',
			)
		);

		$this->add_field(
			'gemini_api_key',
			__( 'Gemini API Key', 'kasumi-ai-generator' ),
			$section,
			array(
				'type'        => 'password',
				'placeholder' => 'AIza***',
				'description' => sprintf(
					/* translators: %s is a link to the Google AI Studio page. */
					__( 'Wygeneruj klucz w %s.', 'kasumi-ai-generator' ),
					sprintf(
						'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
						esc_url( 'https://aistudio.google.com/app/apikey' ),
						esc_html__( 'Google AI Studio', 'kasumi-ai-generator' )
					)
				),
				'help'        => __( 'Obsługuje modele Gemini 2.x flash/pro.', 'kasumi-ai-generator' ),
			)
		);

		$this->add_field(
			'system_prompt',
			__( 'System prompt', 'kasumi-ai-generator' ),
			$section,
			array(
				'type'        => 'textarea',
				'description' => __( 'Instrukcje przekazywane jako system prompt dla modeli OpenAI i Gemini.', 'kasumi-ai-generator' ),
			)
		);

		$this->add_field(
			'gemini_model',
			__( 'Model Gemini', 'kasumi-ai-generator' ),
			$section,
			array(
				'type'      => 'model-select',
				'provider'  => 'gemini',
				'description' => __( 'Wybierz model z Google Gemini (flash, pro, image).', 'kasumi-ai-generator' ),
				'help'        => __( 'Lista pobierana jest bezpośrednio z API na podstawie klucza.', 'kasumi-ai-generator' ),
			)
		);
	}

	private function register_content_section(): void {
		$section = 'kasumi_ai_content';

		add_settings_section(
			$section,
			__( 'Konfiguracja treści', 'kasumi-ai-generator' ),
			function (): void {
				printf(
					'<p>%s</p>',
					esc_html__( 'Ogólne ustawienia generowania wpisów, kategorii i harmonogramu.', 'kasumi-ai-generator' )
				);
			},
			self::PAGE_SLUG
		);

		$this->add_field(
			'topic_strategy',
			__( 'Strategia tematów', 'kasumi-ai-generator' ),
			$section,
			array(
				'description' => __( 'Krótka instrukcja na temat tematów artykułów.', 'kasumi-ai-generator' ),
			)
		);

		$this->add_field(
			'target_category',
			__( 'Kategoria docelowa', 'kasumi-ai-generator' ),
			$section,
			array(
				'description' => __( 'ID kategorii wpisów, które mają otrzymywać nowe treści.', 'kasumi-ai-generator' ),
			)
		);

		$this->add_field(
			'default_post_status',
			__( 'Status wpisów', 'kasumi-ai-generator' ),
			$section,
			array(
				'type'        => 'select',
				'choices'     => array(
					'draft'   => __( 'Szkic', 'kasumi-ai-generator' ),
					'publish' => __( 'Publikuj automatycznie', 'kasumi-ai-generator' ),
				),
				'description' => __( 'Określ czy wpis ma być szkicem czy publikacją.', 'kasumi-ai-generator' ),
			)
		);

		$this->add_field(
			'schedule_interval_hours',
			__( 'Interwał generowania (h)', 'kasumi-ai-generator' ),
			$section,
			array(
				'type'        => 'number',
				'min'         => 72,
				'description' => __( 'Wpisz docelową liczbę godzin (min. 72). System losuje publikację w przedziale 3‑7 dni i dopasuje ją do najlepszych godzin (np. 9:00, 11:30).', 'kasumi-ai-generator' ),
			)
		);

		$this->add_field(
			'word_count_min',
			__( 'Min. liczba słów', 'kasumi-ai-generator' ),
			$section,
			array(
				'type' => 'number',
				'min'  => 200,
			)
		);

		$this->add_field(
			'word_count_max',
			__( 'Maks. liczba słów', 'kasumi-ai-generator' ),
			$section,
			array(
				'type' => 'number',
				'min'  => 200,
			)
		);

		$this->add_field(
			'link_keywords',
			__( 'Słowa kluczowe do linkowania', 'kasumi-ai-generator' ),
			$section,
			array(
				'description' => __( 'Lista słów rozdzielona przecinkami wykorzystywana przy linkach wewnętrznych.', 'kasumi-ai-generator' ),
			)
		);

		$this->add_field(
			'enable_internal_linking',
			__( 'Włącz linkowanie wewnętrzne', 'kasumi-ai-generator' ),
			$section,
			array(
				'type' => 'checkbox',
			)
		);
	}

	private function register_image_section(): void {
		$section = 'kasumi_ai_images';

		add_settings_section(
			$section,
			__( 'Grafiki wyróżniające', 'kasumi-ai-generator' ),
			function (): void {
				printf(
					'<p>%s</p>',
					esc_html__( 'Parametry zdjęć Pixabay i nadpisów tworzonych przez Imagick.', 'kasumi-ai-generator' )
				);
			},
			self::PAGE_SLUG
		);

		$this->add_field(
			'enable_featured_images',
			__( 'Generuj grafiki wyróżniające', 'kasumi-ai-generator' ),
			$section,
			array(
				'type' => 'checkbox',
			)
		);

		$this->add_field(
			'image_generation_mode',
			__( 'Tryb generowania grafik', 'kasumi-ai-generator' ),
			$section,
			array(
				'type'        => 'select',
				'choices'     => array(
					'server' => __( 'Serwerowy (Pixabay + nakładka)', 'kasumi-ai-generator' ),
					'remote' => __( 'OpenAI Images API', 'kasumi-ai-generator' ),
				),
				'description' => __( 'W trybie serwerowym obrazy pochodzą z Pixabay i są modyfikowane lokalnie. Tryb OpenAI generuje obraz przez API (wymaga klucza OpenAI).', 'kasumi-ai-generator' ),
			)
		);

		$this->add_field(
			'image_server_engine',
			__( 'Silnik serwerowy', 'kasumi-ai-generator' ),
			$section,
			array(
				'type'        => 'select',
				'choices'     => array(
					'imagick' => __( 'Imagick (zalecany)', 'kasumi-ai-generator' ),
					'gd'      => __( 'Biblioteka GD', 'kasumi-ai-generator' ),
				),
				'description' => __( 'Używane tylko, gdy wybrano tryb serwerowy. Wybierz bibliotekę dostępna na Twoim hostingu.', 'kasumi-ai-generator' ),
			)
		);

		$this->add_field(
			'image_template',
			__( 'Szablon grafiki', 'kasumi-ai-generator' ),
			$section,
			array(
				'description' => __( 'Możesz odwołać się do {{title}} i {{summary}}.', 'kasumi-ai-generator' ),
			)
		);

		$this->add_field(
			'image_overlay_color',
			__( 'Kolor nakładki (HEX)', 'kasumi-ai-generator' ),
			$section
		);

		$this->add_field(
			'pixabay_query',
			__( 'Słowa kluczowe Pixabay', 'kasumi-ai-generator' ),
			$section
		);

		$this->add_field(
			'pixabay_orientation',
			__( 'Orientacja Pixabay', 'kasumi-ai-generator' ),
			$section,
			array(
				'type'    => 'select',
				'choices' => array(
					'horizontal' => __( 'Pozioma', 'kasumi-ai-generator' ),
					'vertical'   => __( 'Pionowa', 'kasumi-ai-generator' ),
				),
			)
		);
	}

	private function register_comments_section(): void {
		$section = 'kasumi_ai_comments';

		add_settings_section(
			$section,
			__( 'Komentarze AI', 'kasumi-ai-generator' ),
			function (): void {
				printf(
					'<p>%s</p>',
					esc_html__( 'Steruj liczbą i częstotliwością komentarzy generowanych przez AI.', 'kasumi-ai-generator' )
				);
			},
			self::PAGE_SLUG
		);

		$this->add_field(
			'comments_enabled',
			__( 'Generuj komentarze', 'kasumi-ai-generator' ),
			$section,
			array(
				'type' => 'checkbox',
			)
		);

		$this->add_field(
			'comment_frequency',
			__( 'Częstotliwość', 'kasumi-ai-generator' ),
			$section,
			array(
				'type'    => 'select',
				'choices' => array(
					'dense'  => __( 'Intensywnie po publikacji', 'kasumi-ai-generator' ),
					'normal' => __( 'Stałe tempo', 'kasumi-ai-generator' ),
					'slow'   => __( 'Sporadyczne komentarze', 'kasumi-ai-generator' ),
				),
			)
		);

		$this->add_field(
			'comment_min',
			__( 'Minimalna liczba komentarzy', 'kasumi-ai-generator' ),
			$section,
			array(
				'type' => 'number',
				'min'  => 1,
			)
		);

		$this->add_field(
			'comment_max',
			__( 'Maksymalna liczba komentarzy', 'kasumi-ai-generator' ),
			$section,
			array(
				'type' => 'number',
				'min'  => 1,
			)
		);

		$this->add_field(
			'comment_status',
			__( 'Status komentarzy', 'kasumi-ai-generator' ),
			$section,
			array(
				'type'    => 'select',
				'choices' => array(
					'approve' => __( 'Zatwierdzone', 'kasumi-ai-generator' ),
					'hold'    => __( 'Oczekujące', 'kasumi-ai-generator' ),
				),
			)
		);

		$this->add_field(
			'comment_author_prefix',
			__( 'Prefiks pseudonimu', 'kasumi-ai-generator' ),
			$section,
			array(
				'description' => __( 'Opcjonalne. Gdy puste, AI generuje dowolne pseudonimy (np. mix PL/EN).', 'kasumi-ai-generator' ),
			)
		);
	}

	private function register_misc_section(): void {
		$section = 'kasumi_ai_misc';

		add_settings_section(
			$section,
			__( 'Pozostałe', 'kasumi-ai-generator' ),
			function (): void {
				printf(
					'<p>%s</p>',
					esc_html__( 'Logowanie, tryb podglądu oraz powiadomienia.', 'kasumi-ai-generator' )
				);
			},
			self::PAGE_SLUG
		);

		$this->add_field(
			'enable_logging',
			__( 'Włącz logowanie zdarzeń', 'kasumi-ai-generator' ),
			$section,
			array(
				'type' => 'checkbox',
			)
		);

		$this->add_field(
			'status_logging',
			__( 'Pokaż status na stronie', 'kasumi-ai-generator' ),
			$section,
			array(
				'type' => 'checkbox',
			)
		);

		$this->add_field(
			'preview_mode',
			__( 'Tryb podglądu (bez publikacji)', 'kasumi-ai-generator' ),
			$section,
			array(
				'type'        => 'checkbox',
				'description' => __( 'W tym trybie AI generuje treści tylko do logów.', 'kasumi-ai-generator' ),
			)
		);

		$this->add_field(
			'debug_email',
			__( 'E-mail raportowy', 'kasumi-ai-generator' ),
			$section,
			array(
				'type'        => 'email',
				'description' => __( 'Adres otrzymujący krytyczne błędy modułu.', 'kasumi-ai-generator' ),
			)
		);
	}

	private function register_diagnostics_section(): void {
		$section = 'kasumi_ai_diag';

		add_settings_section(
			$section,
			__( 'Diagnostyka środowiska', 'kasumi-ai-generator' ),
			function (): void {
				printf(
					'<p>%s</p>',
					esc_html__( 'Sprawdź czy serwer spełnia wymagania wtyczki.', 'kasumi-ai-generator' )
				);
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'kasumi_ai_diag_report',
			__( 'Status serwera', 'kasumi-ai-generator' ),
			function (): void {
				$this->render_diagnostics();
			},
			self::PAGE_SLUG,
			$section
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kasumi AI – konfiguracja', 'kasumi-ai-generator' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Steruj integracjami API, harmonogramem generowania treści, komentarzy oraz grafik.', 'kasumi-ai-generator' ); ?></p>
			<div class="kasumi-support-card">
				<div class="kasumi-support-card__text">
					<p class="description" style="margin-top:0;"><?php esc_html_e( 'Kasumi rozwijam po godzinach – jeśli automatyzacja oszczędza Ci czas, możesz postawić mi symboliczną kawę.', 'kasumi-ai-generator' ); ?></p>
					<h2 style="margin:8px 0 12px;"><?php esc_html_e( 'Postaw kawę twórcy Kasumi', 'kasumi-ai-generator' ); ?></h2>
					<p style="margin:0;color:#404040;"><?php esc_html_e( 'Wspierasz koszty API, serwera i rozwój nowych modułów (bez reklam i paywalla).', 'kasumi-ai-generator' ); ?></p>
				</div>
				<div class="kasumi-support-card__actions">
					<p style="font-weight:600;margin-bottom:12px;"><?php esc_html_e( 'Dziękuję za każdą kawę!', 'kasumi-ai-generator' ); ?></p>
					<script type="text/javascript" src="https://cdnjs.buymeacoffee.com/1.0.0/button.prod.min.js" data-name="bmc-button" data-slug="kemuricodes" data-color="#FFDD00" data-emoji="" data-font="Inter" data-text="Postaw mi kawę ;)" data-outline-color="#000000" data-font-color="#000000" data-coffee-color="#ffffff"></script>
					<noscript>
						<a class="button button-primary" href="https://buymeacoffee.com/kemuricodes" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Otwórz Buy Me a Coffee', 'kasumi-ai-generator' ); ?></a>
					</noscript>
					<p style="margin-top:12px;font-size:12px;color:#6b7280;"><?php esc_html_e( 'Obsługiwane przez buymeacoffee.com', 'kasumi-ai-generator' ); ?></p>
				</div>
			</div>
			<script data-name="BMC-Widget" data-cfasync="false" src="https://cdnjs.buymeacoffee.com/1.0.0/widget.prod.min.js" data-id="kemuricodes" data-description="Support me on Buy me a coffee!" data-message="Dziękuję za każde wsparcie!!! <3" data-color="#FF813F" data-position="Right" data-x_margin="18" data-y_margin="18"></script>

			<div class="kasumi-overview-grid">
				<div class="card kasumi-about">
					<h2><?php esc_html_e( 'O wtyczce', 'kasumi-ai-generator' ); ?></h2>
					<p><?php esc_html_e( 'Kasumi automatyzuje generowanie wpisów WordPress, komentarzy i grafik AI. Wybierz dostawcę (OpenAI lub Gemini), skonfiguruj harmonogram i podglądaj efekty na żywo.', 'kasumi-ai-generator' ); ?></p>
					<ul>
						<li><?php esc_html_e( 'Autor: Marcin Dymek (KemuriCodes)', 'kasumi-ai-generator' ); ?></li>
						<li><?php esc_html_e( 'Kontakt: contact@kemuri.codes', 'kasumi-ai-generator' ); ?></li>
					</ul>
				</div>
				<div class="card kasumi-info-card">
					<h2><?php esc_html_e( 'Szybkie linki', 'kasumi-ai-generator' ); ?></h2>
					<ul>
						<li><a href="<?php echo esc_url( 'https://platform.openai.com/account/api-keys' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Panel OpenAI', 'kasumi-ai-generator' ); ?></a></li>
						<li><a href="<?php echo esc_url( 'https://aistudio.google.com/app/apikey' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Google AI Studio', 'kasumi-ai-generator' ); ?></a></li>
						<li><a href="mailto:contact@kemuri.codes"><?php esc_html_e( 'Wsparcie KemuriCodes', 'kasumi-ai-generator' ); ?></a></li>
					</ul>
				</div>
				<?php if ( Options::get( 'status_logging' ) ) : ?>
					<?php
					$status      = StatusStore::all();
					$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
					$now         = current_time( 'timestamp' );
					$next_run    = $status['next_post_run']
						? sprintf(
							'%s (%s)',
							esc_html( date_i18n( $date_format, (int) $status['next_post_run'] ) ),
							esc_html(
								sprintf(
									/* translators: %s – relative time */
									__( 'za %s', 'kasumi-ai-generator' ),
									human_time_diff( $now, (int) $status['next_post_run'] )
								)
							)
						)
						: esc_html__( 'Brak zaplanowanych zadań', 'kasumi-ai-generator' );
					$last_error  = $status['last_error']
						? esc_html( $status['last_error'] )
						: esc_html__( 'Brak błędów', 'kasumi-ai-generator' );
					?>
					<div class="card kasumi-ai-status">
						<h2><?php esc_html_e( 'Status modułu AI', 'kasumi-ai-generator' ); ?></h2>
						<ul>
							<li><?php esc_html_e( 'Ostatni post ID:', 'kasumi-ai-generator' ); ?> <strong><?php echo esc_html( (string) ( $status['last_post_id'] ?? '–' ) ); ?></strong></li>
							<li><?php esc_html_e( 'Ostatnie uruchomienie:', 'kasumi-ai-generator' ); ?> <strong><?php echo $status['last_post_time'] ? esc_html( date_i18n( $date_format, (int) $status['last_post_time'] ) ) : esc_html__( 'Brak', 'kasumi-ai-generator' ); ?></strong></li>
							<li><?php esc_html_e( 'Następne zadanie:', 'kasumi-ai-generator' ); ?> <strong><?php echo $next_run; ?></strong></li>
							<li><?php esc_html_e( 'Kolejka komentarzy:', 'kasumi-ai-generator' ); ?> <strong><?php echo esc_html( (string) ( $status['queued_comment_jobs'] ?? 0 ) ); ?></strong></li>
							<li><?php esc_html_e( 'Ostatni błąd:', 'kasumi-ai-generator' ); ?> <strong><?php echo $last_error; ?></strong></li>
						</ul>
					</div>
				<?php endif; ?>
			</div>

			<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
				<?php settings_fields( Options::OPTION_GROUP ); ?>
				<div id="kasumi-ai-tabs" class="kasumi-ai-tabs">
					<ul>
						<li><a href="#kasumi-tab-api"><?php esc_html_e( 'Integracje API', 'kasumi-ai-generator' ); ?></a></li>
						<li><a href="#kasumi-tab-content"><?php esc_html_e( 'Treści i harmonogram', 'kasumi-ai-generator' ); ?></a></li>
						<li><a href="#kasumi-tab-images"><?php esc_html_e( 'Grafiki AI', 'kasumi-ai-generator' ); ?></a></li>
						<li><a href="#kasumi-tab-comments"><?php esc_html_e( 'Komentarze AI', 'kasumi-ai-generator' ); ?></a></li>
						<li><a href="#kasumi-tab-advanced"><?php esc_html_e( 'Zaawansowane', 'kasumi-ai-generator' ); ?></a></li>
						<li><a href="#kasumi-tab-diagnostics"><?php esc_html_e( 'Diagnostyka', 'kasumi-ai-generator' ); ?></a></li>
					</ul>
					<div id="kasumi-tab-api" class="kasumi-tab-panel">
						<?php $this->render_section( 'kasumi_ai_api' ); ?>
					</div>
					<div id="kasumi-tab-content" class="kasumi-tab-panel">
						<?php $this->render_section( 'kasumi_ai_content' ); ?>
					</div>
					<div id="kasumi-tab-images" class="kasumi-tab-panel">
						<?php $this->render_section( 'kasumi_ai_images' ); ?>
					</div>
					<div id="kasumi-tab-comments" class="kasumi-tab-panel">
						<?php $this->render_section( 'kasumi_ai_comments' ); ?>
					</div>
					<div id="kasumi-tab-advanced" class="kasumi-tab-panel">
						<?php $this->render_section( 'kasumi_ai_misc' ); ?>
					</div>
					<div id="kasumi-tab-diagnostics" class="kasumi-tab-panel">
						<?php $this->render_section( 'kasumi_ai_diag' ); ?>
					</div>
				</div>
				<?php submit_button(); ?>
			</form>

			<details class="kasumi-preview-details">
				<summary><?php esc_html_e( 'Podgląd wygenerowanej treści i grafiki', 'kasumi-ai-generator' ); ?></summary>
				<div class="card kasumi-ai-preview-box">
					<p><?php esc_html_e( 'Wygeneruj przykładowy tekst lub obrazek, aby przetestować konfigurację bez publikacji.', 'kasumi-ai-generator' ); ?></p>
					<div class="kasumi-ai-preview-actions">
						<button type="button" class="button button-secondary" id="kasumi-ai-preview-text"><?php esc_html_e( 'Przykładowy tekst', 'kasumi-ai-generator' ); ?></button>
						<button type="button" class="button button-secondary" id="kasumi-ai-preview-image"><?php esc_html_e( 'Podgląd grafiki', 'kasumi-ai-generator' ); ?></button>
					</div>
					<div id="kasumi-ai-preview-output" class="kasumi-ai-preview-output" aria-live="polite"></div>
				</div>
			</details>
		</div>
		<?php
	}

	private function add_field( string $key, string $label, string $section, array $args = array() ): void {
		$defaults = array(
			'type'        => 'text',
			'description' => '',
			'choices'     => array(),
			'min'         => null,
			'placeholder' => '',
			'help'        => '',
		);

		$args = wp_parse_args( $args, $defaults );

		if ( ! empty( $args['help'] ) ) {
			$label .= sprintf(
				' <button type="button" class="kasumi-help dashicons dashicons-editor-help" data-kasumi-tooltip="%s" aria-label="%s"></button>',
				esc_attr( $args['help'] ),
				esc_attr( wp_strip_all_tags( $label ) )
			);
		}

		add_settings_field(
			'kasumi_ai_' . $key,
			wp_kses_post( $label ),
			function () use ( $key, $args ): void {
				$this->render_field( $key, $args );
			},
			self::PAGE_SLUG,
			$section
		);
	}

	private function render_field( string $key, array $args ): void {
		$value = Options::get( $key );
		$type  = $args['type'];

		switch ( $type ) {
			case 'textarea':
				printf(
					'<textarea name="%1$s[%2$s]" rows="3" class="large-text">%3$s</textarea>',
					esc_attr( Options::OPTION_NAME ),
					esc_attr( $key ),
					esc_textarea( (string) $value )
				);
				break;
			case 'select':
				printf(
					'<select name="%1$s[%2$s]">',
					esc_attr( Options::OPTION_NAME ),
					esc_attr( $key )
				);

				foreach ( $args['choices'] as $option_value => $label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( (string) $option_value ),
						selected( $value, $option_value, false ),
						esc_html( $label )
					);
				}

				echo '</select>';
				break;
			case 'checkbox':
				printf(
					'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>',
					esc_attr( Options::OPTION_NAME ),
					esc_attr( $key ),
					checked( ! empty( $value ), true, false ),
					esc_html__( 'Aktywne', 'kasumi-ai-generator' )
				);
				break;
			case 'model-select':
				$provider = $args['provider'] ?? 'openai';
				$current  = (string) $value;
				echo '<div class="kasumi-model-control" data-provider="' . esc_attr( $provider ) . '" data-autoload="1">';
				printf(
					'<select name="%1$s[%2$s]" data-kasumi-model="%3$s" data-current-value="%4$s" class="regular-text">',
					esc_attr( Options::OPTION_NAME ),
					esc_attr( $key ),
					esc_attr( $provider ),
					esc_attr( $current )
				);
				if ( $current ) {
					printf( '<option value="%1$s">%1$s</option>', esc_html( $current ) );
				} else {
					echo '<option value="">' . esc_html__( 'Wybierz model…', 'kasumi-ai-generator' ) . '</option>';
				}
				echo '</select>';
				printf(
					'<button type="button" class="button kasumi-refresh-models" data-provider="%s">%s</button>',
					esc_attr( $provider ),
					esc_html__( 'Odśwież listę', 'kasumi-ai-generator' )
				);
				echo '<span class="spinner kasumi-model-spinner" aria-hidden="true"></span>';
				echo '</div>';
				break;
			default:
				printf(
					'<input type="%5$s" class="regular-text" name="%1$s[%2$s]" value="%3$s" placeholder="%4$s" %6$s>',
					esc_attr( Options::OPTION_NAME ),
					esc_attr( $key ),
					esc_attr( (string) $value ),
					esc_attr( (string) $args['placeholder'] ),
					esc_attr( $type ),
					null !== $args['min'] ? 'min="' . esc_attr( (string) $args['min'] ) . '"' : ''
				);
		}

		if ( ! empty( $args['description'] ) ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses_post( $args['description'] )
			);
		}
	}

	/**
	 * Renderuje pojedynczą sekcję Settings API.
	 *
	 * @param string $section_id Section identifier.
	 */
	private function render_section( string $section_id ): void {
		global $wp_settings_sections, $wp_settings_fields;

		if ( empty( $wp_settings_sections[ self::PAGE_SLUG ][ $section_id ] ) ) {
			return;
		}

		$section = $wp_settings_sections[ self::PAGE_SLUG ][ $section_id ];

		if ( ! empty( $section['title'] ) ) {
			printf( '<h2>%s</h2>', esc_html( $section['title'] ) );
		}

		if ( ! empty( $section['callback'] ) ) {
			call_user_func( $section['callback'], $section );
		}

		if ( empty( $wp_settings_fields[ self::PAGE_SLUG ][ $section_id ] ) ) {
			return;
		}

		echo '<table class="form-table" role="presentation">';

		foreach ( (array) $wp_settings_fields[ self::PAGE_SLUG ][ $section_id ] as $field ) {
			echo '<tr>';
			echo '<th scope="row">';
			if ( ! empty( $field['args']['label_for'] ) ) {
				echo '<label for="' . esc_attr( $field['args']['label_for'] ) . '">' . wp_kses_post( $field['title'] ) . '</label>';
			} else {
				echo wp_kses_post( $field['title'] );
			}
			echo '</th><td>';
			call_user_func( $field['callback'], $field['args'] );
			echo '</td></tr>';
		}

		echo '</table>';
	}

	private function render_diagnostics(): void {
		$report = $this->get_environment_report();

		echo '<ul class="kasumi-diag-list">';
		foreach ( $report as $row ) {
			printf(
				'<li><strong>%s:</strong> %s</li>',
				esc_html( $row['label'] ),
				wp_kses_post( $row['value'] )
			);
		}
		echo '</ul>';
	}

	private function get_environment_report(): array {
		$php_ok = version_compare( PHP_VERSION, '8.1', '>=' );
		$rows   = array(
			array(
				'label' => __( 'Wersja PHP', 'kasumi-ai-generator' ),
				'value' => $php_ok
					? '<span class="kasumi-ok">' . esc_html( PHP_VERSION ) . '</span>'
					: '<span class="kasumi-error">' . esc_html( PHP_VERSION ) . '</span>',
			),
		);

		$extensions = array(
			'curl'     => extension_loaded( 'curl' ),
			'mbstring' => extension_loaded( 'mbstring' ),
		);

		foreach ( $extensions as $extension => $enabled ) {
			$rows[] = array(
				/* translators: %s is the PHP extension name. */
				'label' => sprintf( __( 'Rozszerzenie %s', 'kasumi-ai-generator' ), strtoupper( $extension ) ),
				'value' => $enabled
					? '<span class="kasumi-ok">' . esc_html__( 'dostępne', 'kasumi-ai-generator' ) . '</span>'
					: '<span class="kasumi-error">' . esc_html__( 'brak', 'kasumi-ai-generator' ) . '</span>',
			);
		}

		return $rows;
	}
}
