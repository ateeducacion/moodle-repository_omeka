# Repository Guidelines

## Project Structure & Module Organization
- Core: `lib.php`, `version.php`, `ajax.php`.
- PHP classes: `classes/` (namespaced `repository_omeka\…`).
- DB and privacy: `db/`, `classes/privacy/`.
- UI assets: `pix/`, `styles.scss|css`, `amd/` (JS AMD modules).
- Localization: `lang/`.
- Tests: `tests/behat/` (Behat steps/features).
- Dev tooling: `Makefile`, `docker-compose.yml`, `ci/` (local moodle-plugin-ci).

## Build, Test, and Development Commands
- `make upd` / `make down`: Start/stop Docker stack (Moodle, Omeka-S, DB).
- `make shell`: Open a shell in the Moodle container.
- `make ci-deps`: Install `ci/` helper (moodle-plugin-ci).
- `make check`: Run full CI suite (lint, PHPCS, validate, savepoints, mustache, PHPUnit, Behat).
- `make behat`: Run tagged Behat scenarios for this plugin.
- `make package VERSION=X.Y.Z`: Create a zip release.

## Coding Style & Naming Conventions
- Standard: Moodle PHP guidelines (4 spaces, no tabs).
- Linting: `./ci/bin/moodle-plugin-ci phpcs --standard=moodle`.
- Auto-fix: `../moodle-plugin-ci/vendor/bin/phpcbf --standard=moodle path.php` (or PHAR variant).
- Namespaces: `repository_omeka\…`; class files in `classes/` follow Moodle autoload rules.
- Strings in `lang/en/repository_omeka.php`; JS under `amd/src/` with AMD module names.

## Testing Guidelines
- Behat: place features/steps in `tests/behat/`; tag scenarios `@repository_omeka`.
- Run Behat: `make behat` (or `./ci/bin/moodle-plugin-ci behat --profile chrome`).
- PHPUnit: add tests under `tests/phpunit/*_test.php` if needed; run via `make check`.

## Commit & Pull Request Guidelines
- Messages: imperative, concise; optional Conventional Commits (e.g., `feat:`, `fix:`).
- PRs: describe intent, link issues, list testing steps; include screenshots for UI.
- Quality gates: `make check` must pass; keep diffs focused.

## Security & Configuration Tips
- Do not commit secrets; copy `.env.dist` to `.env` for local overrides.
- Omeka-S credentials are optional for public content; prefer environment variables in Docker when testing.
