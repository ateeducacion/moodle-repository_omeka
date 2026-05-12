# Development Guide

This document covers the development, local testing and CI workflow for
`repository_omeka`. End-user installation and compatibility are documented in
[README.md](README.md).

> **Tip:** Do not deploy the plugin by cloning this repository into your Moodle
> tree. Use a release ZIP from the
> [Releases](https://github.com/ateeducacion/moodle-repository_omeka/releases)
> page (built by `release.yml`) or run `make package RELEASE=X.Y.Z` to produce
> one locally — both honour `.distignore` and ship only the files Moodle needs.

## Requirements

- Docker + Docker Compose
- GNU Make
- PHP 8.2+ and Composer (only needed for `make install-deps`)
- `rsync` and `zip` (used by `make package`)
- Node 22 recommended for `moodle-plugin-ci install` (see Local Testing below)

## Development environment (Docker)

A `docker-compose.yml` brings up a full Moodle + MariaDB stack with this plugin
bind-mounted at `repository/omeka`, plus an auto-configured "Omeka Sandbox"
repository instance pointing at `https://dev.omeka.org/omeka-s-sandbox`.

```bash
make upd            # Start Docker services in background (Moodle + MariaDB)
make up             # Start Docker services in foreground
make down           # Stop Docker services
make shell          # Open interactive shell inside the Moodle container
make pull           # Pull the latest images
make build          # (Re)build images
make clean          # Stop and remove containers, volumes and orphans
```

On first start the stack creates an `OMEKA01` demo course, enables
`repository_omeka`, and registers the sandbox instance via
`docker/configure-repository.php`.

### Environment variables

Copy `.env.dist` to `.env` (handled automatically by `make` targets) and tweak
ports, credentials or `OMEKA_BASEURL` as needed.

## Code quality

```bash
make install-deps   # composer install
make lint           # phplint + phpmd + phpcs
make phpcs          # Moodle CodeSniffer standard only
make phpcbf         # Auto-fix CodeSniffer violations
make phpmd          # PHP Mess Detector
make phpdoc         # Moodle PHPDoc checker
make mustache       # Mustache template lint
make validate       # Moodle plugin validation
make analyze        # Full static analysis (no tests)
```

`lint`, `phpcs`, `phpmd`, `phpdoc`, `mustache`, `validate` all delegate to
`moodle-plugin-ci`, which is installed on first use under `./ci/` (see CI
bootstrap below).

## Local Testing (Docker + moodle-plugin-ci)

The repository includes a lightweight, dockerised setup to run the plugin's
checks and PHPUnit locally without installing MariaDB on your host.

### Quick start

- `make test`: brings up a minimal MariaDB (`docker-compose.test.yml`, port
  `127.0.0.1:3307`), prepares a cached Moodle under `.ci/`, and runs PHPUnit
  via `moodle-plugin-ci`.
- `make check`: runs analysis (linters/validators) and tests.
- `make behat`: run Behat scenarios tagged `@repository_omeka` (uses the
  Chromedriver container declared in `docker-compose.yml`).

### Useful helpers

- `make test-up` / `make test-down`: start/stop the minimal DB.
- `make test-reset`: drop the CI database used by `make test` safely.
- `make ci-clean`: remove the cached Moodle and moodledata under `.ci/` if
  you want a fresh bootstrap.
- `make sync-plugin`: rsync the current source into the cached Moodle
  checkout (called automatically by `make phpunit` / `make behat`).

### Configuration knobs

Override per run, e.g. `make test CI_NO_INIT=`:

| Variable | Default | Purpose |
| --- | --- | --- |
| `TEST_DB_PORT` | `3307` | Host port for the test DB. |
| `MOODLE_REF` | `v5.0.1` | Moodle branch/tag fetched into `.ci/moodle-$MOODLE_REF`. |
| `CI_NODE_VERSION` | `22.12.0` | Node version used by `moodle-plugin-ci install`. |
| `CI_NO_INIT` | `1` | Skip Moodle core init (grunt) during install (avoid Node mismatches). Set empty to enable. |
| `CI_NO_PLUGIN_NODE` | `1` | Skip plugin Node tasks during install. Set empty to enable. |
| `CI_RESET_DB_ON_INSTALL` | `1` | Drop the CI DB before the first install to avoid "database exists" errors. |

### Node 22 tip (macOS/Homebrew)

Install `node@22` (`brew install node@22`). The Makefile will prefer Homebrew's
Node 22 for the install step automatically.

### Troubleshooting

- **"Node version not satisfied"** — make sure Node 22 is available (see
  above), or run with `CI_NO_INIT=` only when you have Node 22, or use
  `make ci-clean` then `make test` after adjusting Node.
- **"database exists"** — use `make test-reset` to drop the test DB, or
  `make ci-clean` to clear the cached environment.

## Continuous Integration

Every push and pull request runs a full matrix via GitHub Actions
(`.github/workflows/moodle-ci.yml`). See the
[Compatibility](README.md#compatibility) table in the README for the exact
Moodle / PHP / database combinations covered.

Each combination runs: PHP lint, PHP Mess Detector, Moodle Code Checker
(PHPCS), plugin validation, upgrade savepoints, Mustache lint, PHPUnit and
Behat.

Pull requests also trigger `pr-playground-preview.yml`, which appends a
Moodle Playground link to the PR description so reviewers can test the
changes in a live Moodle instance without any local setup.

## Releases & packaging

```bash
make package RELEASE=1.2.3   # Build repository_omeka-1.2.3.zip
```

`make package` stages the working tree into a temporary directory with
`rsync --exclude-from=.distignore`, then zips it as `omeka/` (the directory
name Moodle expects under `repository/`). `.distignore` excludes:

- Every dotfile / dotdir (`.git`, `.github`, `.ci`, `.env`, `.claude`,
  `.omc`, `.DS_Store`, …) via the `.*` pattern.
- Development tooling: `Makefile`, `docker-compose*.yml`, `composer.*`,
  `package*.json`, `phpcs.xml.dist`, `phpmd.xml`, `blueprint.json`.
- `ci/`, `docker/`, `node_modules/`, `vendor/`, `tests/`.
- Internal docs (`AGENTS.md`, `CLAUDE.md`, `DEVELOPMENT.md`, `CHANGELOG.md`).
- Previously built `repository_omeka-*.zip` artifacts.

`README.md` is kept inside the package on purpose.

### GitHub Release flow

Publishing a GitHub Release (tag `vX.Y.Z`) triggers
`.github/workflows/release.yml`, which runs `make package RELEASE=$TAG`
and attaches the resulting ZIP to the release. You can also trigger the
workflow manually from the Actions tab (`workflow_dispatch`) and supply
any release label.

## Commit & Pull Request guidelines

- Imperative mood, concise subject. Conventional Commits optional
  (`feat:`, `fix:`, `refactor:`, `test:`, `docs:`).
- PRs should describe intent, link related issues, and list manual testing
  steps. `make check` must pass before merging.
