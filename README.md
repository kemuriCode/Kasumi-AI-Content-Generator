# Kasumi AI Content Generator

WordPress plugin that automates post, comment, and image generation using OpenAI and Google Gemini.

## Description

Kasumi automates WordPress content creation with AI. It generates SEO-friendly posts, featured images, and comments using OpenAI GPT models and Google Gemini.

## Requirements

- WordPress 6.0+
- PHP 8.1+
- PHP extensions: cURL, mbstring

## Installation

1. Download the plugin package
2. Upload to `/wp-content/plugins/`
3. Run `composer install` in the plugin directory
4. Activate the plugin in WordPress admin

## Building Package

Run `./scripts/build.sh` to create a distribution ZIP file. The script automatically excludes tests, dev dependencies, and configuration files.

## Author

Marcin Dymek (KemuriCodes)

## License

GPL-2.0-or-later
