# AGENTS.md

This file provides guidance to AI coding agents when working with code in this repository.

## Project Overview

`repository_omeka` is a Moodle repository plugin that lets users browse and insert media items
from an Omeka-S instance directly through the Moodle file picker. It connects to the Omeka-S
REST API, parses JSON-LD responses, and maps item metadata to Moodle's repository interface.

**Component**: `repository_omeka`
**Moodle compatibility**: 4.2+
**License**: GNU GPL v3+

## Architecture

The plugin follows the standard Moodle repository pattern:

- `lib.php` — Main repository class (`repository_omeka`), implements all Moodle repository
  hooks: `get_listing()`, `search()`, `get_file()`, `print_login()`, `check_login()`,
  `get_link()`. This is the orchestrator; all business logic is delegated to `classes/local/`.
- `classes/local/api_client.php` — HTTP client wrapping `curl_easy` to call the Omeka-S REST
  API (`/api/items`, `/api/media`, `/api/sites`). Handles authentication via API key identity
  and credential query parameters.
- `classes/local/jsonld_parser.php` — Parses JSON-LD item responses from Omeka-S, extracting
  title, description, thumbnail, and media URLs.
- `classes/local/license_mapper.php` — Maps Omeka-S license strings to Moodle license
  identifiers.
- `classes/privacy/provider.php` — Moodle Privacy API implementation (null provider).
- `classes/external/list_sites.php` — External function exposing available Omeka-S sites for
  the JS AMD module used in the repository configuration UI.
- `ajax.php` — Entry point for AMD/AJAX calls (site listing for the settings form).
- `amd/src/` — AMD JavaScript modules for the file picker UI and settings form.

Omeka-S API endpoints used: `GET /api/items`, `GET /api/media`, `GET /api/sites`.

## Project Structure

```
repository_omeka/
  lib.php                        # Repository class (Moodle hooks)
  ajax.php                       # AJAX entry point
  version.php                    # Plugin version metadata
  classes/
    local/
      api_client.php             # Omeka-S HTTP client
      jsonld_parser.php          # JSON-LD response parser
      license_mapper.php         # License string mapping
    external/
      list_sites.php             # External function: list sites
    privacy/
      provider.php               # Privacy API (null provider)
  amd/
    src/                         # AMD JS source modules
    build/                       # Compiled AMD output (do not edit)
  lang/
    en/
      repository_omeka.php       # English language strings
  db/
    access.php                   # Capability definitions
    install.xml                  # Database schema
  tests/
    behat/
      repository_omeka.feature   # Behat scenarios
      behat_repository_omeka.php # Custom step definitions
    phpunit/
      repository_omeka_test.php  # PHPUnit tests
  pix/                           # Plugin icons
  Makefile                       # Development commands
  docker-compose.yml             # Local dev stack
  composer.json                  # PHP dependencies
```

## Build, Test, and Development Commands

The full development, local-testing and CI workflow lives in
[DEVELOPMENT.md](DEVELOPMENT.md). Quick reference for the targets you will use
most often:

```bash
make upd                     # Start Docker services in background (Moodle + MariaDB + Omeka sandbox)
make up                      # Start Docker services in foreground
make down                    # Stop Docker services
make shell                   # Open interactive shell in the Moodle container
make ci-deps                 # Install moodle-plugin-ci into ./ci (run once)
make lint                    # phplint + phpmd + phpcs
make phpcs                   # Moodle CodeSniffer standard only
make phpcbf                  # Auto-fix CodeSniffer violations
make phpmd                   # PHP Mess Detector
make test                    # PHPUnit via minimal DB stack
make behat                   # Behat scenarios tagged @repository_omeka
make check                   # Full CI suite: analysis + tests
make package RELEASE=X.Y.Z   # Build distributable ZIP (honours .distignore)
make clean                   # Remove containers, volumes, orphans
```

Run `make ci-deps` before any `make lint / test / behat / check` on a fresh
checkout. For the full list of targets, knobs (`TEST_DB_PORT`, `MOODLE_REF`,
`CI_NODE_VERSION`, …) and troubleshooting tips, read
[DEVELOPMENT.md](DEVELOPMENT.md).

## Coding Style & Naming Conventions

- **Standard**: Moodle PHP coding guidelines — 4 spaces, no tabs, Unix line endings.
- **Linting**: `make phpcs` (CodeSniffer, Moodle standard); `make phpcbf` to auto-fix.
- **Namespaces**: classes under `classes/local/` use `repository_omeka\local\<Classname>`;
  classes under `classes/external/` use `repository_omeka\external\<Classname>`.
- **Strings**: all UI strings in `lang/en/repository_omeka.php`; use
  `get_string('key', 'repository_omeka')`.
- **JS**: AMD modules in `amd/src/`; compiled output in `amd/build/` (do not commit
  hand-edited build files).
- **No direct `echo`**: use Moodle output functions or return HTML strings.

## Testing Guidelines

### PHPUnit

- Tests live in `tests/phpunit/*_test.php`.
- Namespace: `repository_omeka\local` (where applicable).
- Run all: `make test`
- Run a single file: set up CI first (`make ci-deps`), then
  `vendor/bin/phpunit tests/phpunit/repository_omeka_test.php`

### Behat

- Feature files in `tests/behat/`; all scenarios tagged `@repository_omeka`.
- Add `@javascript` to any scenario that requires a browser (file picker, AJAX, dynamic UI).
- Custom step definitions in `tests/behat/behat_repository_omeka.php` (class
  `behat_repository_omeka extends behat_base`).
- Docblock format for steps: `@Given /^regex$/`, `@When /^regex$/`, `@Then /^regex$/`.
- Run: `make behat`
- Behat uses a local Chrome/Chromedriver container (`make webdriver-up` is called automatically).

## Commit & Pull Request Guidelines

- Commit messages: imperative mood, concise. Conventional Commits optional
  (`feat:`, `fix:`, `refactor:`, `test:`, `docs:`).
- PRs: describe intent, link related issues, list manual testing steps.
- Each PR automatically generates a preview on Moodle Playground via
  `.github/workflows/pr-playground-preview.yml`.
- `make check` must pass before merging.

## Releases

- Publish a GitHub Release (tag `vX.Y.Z`) to trigger
  `.github/workflows/release.yml`. The workflow runs `make package RELEASE=$TAG`
  and uploads the resulting ZIP to the release. The same workflow can also be
  triggered manually from the Actions tab (`workflow_dispatch`).
- To build locally: `make package RELEASE=X.Y.Z`
  (creates `repository_omeka-X.Y.Z.zip`, staging the tree via
  `rsync --exclude-from=.distignore` so all dotfiles, `tests/`, `docker/`,
  `vendor/`, `node_modules/`, CI tooling and dev configs are stripped; the ZIP
  root is `omeka/` so it can be uploaded directly from
  _Site administration > Plugins > Install plugins_).
- See [DEVELOPMENT.md](DEVELOPMENT.md#releases--packaging) for the full
  packaging contract and the list of patterns honoured by `.distignore`.

## Security & Configuration Tips

- Do not commit secrets; copy `.env.dist` to `.env` for local overrides (`.env` is gitignored).
- Omeka-S API keys (`keyidentity` / `keycredential`) are optional for public content.
- Prefer environment variables in Docker Compose when testing against a real Omeka-S instance.
- Repository credentials are stored via Moodle's admin settings and never logged.

## External References

- Omeka-S REST API: https://omeka.org/s/docs/developer/api/rest_api/
- Moodle Repository Plugin API: https://moodledev.io/docs/apis/plugintypes/repository
- PR Preview Action: https://github.com/ateeducacion/action-moodle-playground-pr-preview
