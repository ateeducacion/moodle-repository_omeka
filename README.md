# Omeka-S Repository Plugin

[![Preview in Moodle Playground](https://raw.githubusercontent.com/ateeducacion/action-moodle-playground-pr-preview/refs/heads/main/assets/playground-preview-button.svg)](https://ateeducacion.github.io/moodle-playground/?blueprint-url=https://raw.githubusercontent.com/ateeducacion/moodle-repository_omeka/refs/heads/main/blueprint.json)

## Try in Moodle Playground

Click the badge above to open the `main` branch instantly in Moodle Playground with the plugin pre-installed.

Every pull request automatically generates a playground preview link appended to the PR description, so reviewers can test the changes in a live Moodle instance without any local setup.

This plugin allows you to connect Moodle with one or more Omeka-S instances, making it easy to integrate and access digital resources stored in Omeka-S from Moodle's file picker.

## Description

With this plugin, you can link Omeka-S resources directly in Moodle, allowing users to search, select, and reuse digital objects and collections managed in Omeka-S.

File searches are performed through the Omeka-S REST API, and results are paginated to avoid loading all items at once.
Navigation starts by displaying the available item sets, and upon selecting one, the items belonging to that set are listed.

## Installation

1. Download the latest ZIP from the [Releases](https://github.com/ateeducacion/moodle-repository_omeka/releases) page, or copy the plugin directory into `repository/omeka` inside your Moodle installation.
2. Go to Site administration and complete the installation.
3. Create repository instances by specifying the URL of each Omeka-S installation, the site you want to display, and, if necessary, the API key data (`key_identity` and `key_credential`). These keys are optional for accessing public content.

## Dependencies

This plugin requires a working instance of [Omeka-S](https://omeka.org/s/) accessible from Moodle.

## Local Testing (Docker + moodle-plugin-ci)

The repository includes a lightweight, dockerised setup to run the plugin's checks and PHPUnit locally without installing MySQL on your host.

- Quick start:
  - `make test`: brings up a minimal MariaDB (`docker-compose.test.yml`, port `127.0.0.1:3307`), prepares a cached Moodle under `.ci/`, and runs PHPUnit via `moodle-plugin-ci`.
  - `make check`: runs analysis (linters/validators) and tests.

- Useful helpers:
  - `make test-up` / `make test-down`: start/stop the minimal DB.
  - `make test-reset`: drop the CI database used by `make test` safely.
  - `make ci-clean`: remove the cached Moodle and moodledata under `.ci/` if you want a fresh bootstrap.

- Configuration knobs (override per run, e.g. `make test CI_NO_INIT=`):
  - `TEST_DB_PORT` (default `3307`): host port for the test DB.
  - `CI_NODE_VERSION` (default `22.12.0`): Node version used by `moodle-plugin-ci install` when it needs Node.
  - `CI_NO_INIT` (default `1`): skip Moodle core init (grunt) during install to avoid Node version mismatches. Set empty to enable init.
  - `CI_NO_PLUGIN_NODE` (default `1`): skip plugin Node tasks during install. Set empty to enable.
  - `CI_RESET_DB_ON_INSTALL` (default `1`): drop the CI DB before the first install to avoid "database exists" errors.

- Node 22 tip (macOS/Homebrew):
  - Install `node@22` (`brew install node@22`). The Makefile will prefer Homebrew's Node 22 for the install step automatically.

- Troubleshooting:
  - "Node version not satisfied": ensure Node 22 is available (see above) or run with `CI_NO_INIT=` to allow core init only when you have Node 22, or use `make ci-clean` then `make test` after adjusting Node.
  - "database exists": use `make test-reset` to drop the test DB, or `make ci-clean` to clear the cached environment.

## CI

Every push and pull request runs a full matrix via GitHub Actions (`moodle-ci.yml`):

- **Moodle branches:** 4.4 LTS, 4.5, 5.0
- **PHP versions:** 8.2, 8.3
- **Databases:** PostgreSQL, MariaDB
- **Steps per combination:** PHP lint, PHP Mess Detector, Moodle Code Checker (PHPCS), plugin validation, upgrade savepoints, Mustache lint, PHPUnit, Behat

Release ZIPs are built and attached to GitHub Releases automatically via `release.yml` on each published release.

## Support

- For issues or suggestions, use the **Issues** section in the GitHub repository.

## License

This project is licensed under **GPL v3**.

## Author and Contact

Developed by the **Área de Tecnología Educativa** of the Government of the Canary Islands.

- **Email:** [ate.educacion@gobiernodecanarias.org](mailto:ate.educacion@gobiernodecanarias.org)
- **Web:** [www.gobiernodecanarias.org/educacion](https://www.gobiernodecanarias.org/educacion)
