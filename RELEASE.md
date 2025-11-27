# Jak zrobić release

## Sposób 1: Przez GitHub Actions (najprostszy) ⭐

1. Przejdź do GitHub → **Actions** → **Build and Release**
2. Kliknij **"Run workflow"**
3. Wybierz:
   - **Branch:** `main`
   - **Bump type:** `patch` (0.1.0 → 0.1.1) / `minor` (0.1.0 → 0.2.0) / `major` (0.1.0 → 1.0.0)
4. Kliknij **"Run workflow"**

Workflow automatycznie:
- ✅ Zwiększy wersję w plikach
- ✅ Utworzy tag (np. `v0.1.1`)
- ✅ Wygeneruje changelog z commitów
- ✅ Zbuduje paczkę ZIP
- ✅ Utworzy release na GitHubie

## Sposób 2: Lokalnie (ręcznie)

```bash
# 1. Zwiększ wersję
./scripts/version-bump.sh patch  # lub minor, major

# 2. Sprawdź nową wersję
grep "Version:" kasumi-ai-generator.php

# 3. Commit i push
git add kasumi-ai-generator.php readme.txt
git commit -m "chore: bump version to X.Y.Z"
git push

# 4. Utwórz tag
git tag -a "vX.Y.Z" -m "Release vX.Y.Z"
git push origin main --tags
```

GitHub automatycznie wykryje tag i zbuduje release!

## Format commitów (dla changelog)

Używaj Conventional Commits:
- `feat: dodano nową funkcję`
- `fix: naprawiono błąd`
- `perf: optymalizacja`
- `refactor: refaktoryzacja`
- `docs: dokumentacja`
- `test: testy`
- `chore: zmiany techniczne`

