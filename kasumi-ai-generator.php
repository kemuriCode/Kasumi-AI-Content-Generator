<?php
/**
 * Plugin Name: Kasumi – Full AI Content Generator
 * Description: Automatyzuje generowanie wpisów, komentarzy i grafik przy użyciu OpenAI oraz Google Gemini.
 * Author: Marcin Dymek (KemuriCodes)
 * Version: 0.1.0
 * Text Domain: kasumi-ai-generator
 *
 * @package Kasumi\AIGenerator
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KASUMI_AI_VERSION', '0.1.0' );
define( 'KASUMI_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'KASUMI_AI_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Kasumi AI wymaga PHP 8.1 lub wyższej wersji. Zaktualizuj środowisko, aby aktywować wtyczkę.', 'kasumi-ai-generator' )
			);
		}
	);

	return;
}

$kasumi_autoload = KASUMI_AI_PATH . 'vendor/autoload.php';

if ( ! file_exists( $kasumi_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Brak katalogu vendor. Uruchom composer install w folderze wtyczki Kasumi.', 'kasumi-ai-generator' )
			);
		}
	);

	return;
}

require_once $kasumi_autoload;

use Kasumi\AIGenerator\Module;

add_action(
	'admin_init',
	static function (): void {
		if ( ! function_exists( 'extension_loaded' ) ) {
			return;
		}

		$missing = array();

		if ( ! extension_loaded( 'curl' ) ) {
			$missing[] = 'cURL';
		}

		if ( ! extension_loaded( 'mbstring' ) ) {
			$missing[] = 'mbstring';
		}

		if ( ! empty( $missing ) ) {
			add_action(
				'admin_notices',
				static function () use ( $missing ): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						wp_kses_post(
							sprintf(
								/* translators: %s list of extensions */
								__( 'Kasumi AI wymaga rozszerzeń PHP: %s. Skontaktuj się z administratorem serwera.', 'kasumi-ai-generator' ),
								implode( ', ', $missing )
							)
						)
					);
				}
			);
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain(
			'kasumi-ai-generator',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);

		( new Module() )->register();
	}
);
