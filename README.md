# Kasumi-AI-Content-Generator

Automatyzuje generowanie wpisów, komentarzy i grafik przy użyciu OpenAI oraz Google Gemini.

## Opis

Kasumi – Full AI Content Generator to wtyczka WordPress, która wykorzystuje sztuczną inteligencję do automatycznego generowania treści na stronach WordPress. Wtyczka obsługuje zarówno OpenAI jak i Google Gemini.

## Gutenberg-ready output

- Markdown wygenerowany przez AI jest mapowany na natywne bloki `core/*` (nagłówki, akapity, listy, cytaty, obrazy, kod, separatory).
- Nietypowe fragmenty trafiają do bloku `core/html`, więc nadal można je edytować po stronie Gutenberga bez utraty formatowania.
- Jeżeli konwersja na bloki się nie powiedzie, logger zapisze ostrzeżenie i włączy się bezpieczny fallback do czystego HTML.

### Jak przetestować bloki

Po utworzeniu wpisu możesz zweryfikować wygenerowaną strukturę bloków poleceniem WP-CLI:

```bash
wp eval 'print_r( parse_blocks( get_post(123)->post_content ) );'
```

(Podmień `123` na ID świeżo wygenerowanego wpisu).

## Wymagania

- WordPress 5.8+
- PHP 8.1+
- Rozszerzenia PHP: cURL, mbstring

## Instalacja

1. Pobierz paczkę wtyczki
2. Prześlij folder wtyczki do `/wp-content/plugins/`
3. Uruchom `composer install` w katalogu wtyczki
4. Aktywuj wtyczkę w panelu administracyjnym WordPress

## Wymagane zależności

```bash
composer install
```

## Budowanie paczki ZIP

### Lokalnie

Aby utworzyć paczkę ZIP wtyczki gotową do dystrybucji:

```bash
./scripts/build.sh
```

Skrypt utworzy plik `kasumi-ai-generator.zip` w katalogu głównym wtyczki.

**Uwaga:** Skrypt automatycznie wyklucza:
- Testy (`tests/`, `phpunit.xml.dist`)
- Zależności deweloperskie (Composer `--no-dev`)
- Pliki konfiguracyjne (`.git`, `.env`, itp.)

### Automatyczny release na GitHub

Release jest automatycznie budowany przez GitHub Actions przy tworzeniu nowego tagu. Użyj `./scripts/version-bump.sh` do zwiększenia wersji i utwórz tag, a workflow automatycznie zbuduje paczkę ZIP i utworzy release.

## Autor

Marcin Dymek (KemuriCodes)

## Licencja

GPL-2.0-or-later

