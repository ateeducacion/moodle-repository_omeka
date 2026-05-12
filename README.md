# Omeka-S Repository Plugin

[![Preview in Moodle Playground](https://raw.githubusercontent.com/ateeducacion/action-moodle-playground-pr-preview/refs/heads/main/assets/playground-preview-button.svg)](https://ateeducacion.github.io/moodle-playground/?blueprint-url=https://raw.githubusercontent.com/ateeducacion/moodle-repository_omeka/refs/heads/main/blueprint.json)

Moodle repository plugin that connects Moodle with one or more
[Omeka-S](https://omeka.org/s/) instances, so digital resources can be searched
and inserted from Moodle's file picker.

File searches use the Omeka-S REST API and results are paginated to avoid
loading every item at once. Navigation starts on the available item sets, and
upon selecting one the items belonging to that set are listed.

## Try in Moodle Playground

Click the badge above to open the `main` branch instantly in Moodle Playground
with the plugin pre-installed. Every pull request automatically generates a
playground preview link appended to the PR description, so reviewers can test
the changes in a live Moodle instance without any local setup.

You can also point the plugin at the public Omeka-S sandbox
(<https://dev.omeka.org/omeka-s-sandbox/>) — it is the default URL used by the
"Omeka Sandbox" repository instance pre-configured in the development
environment, and works as a zero-setup target for evaluating the plugin.

## Compatibility

The plugin's minimum required Moodle version is **Moodle 3.11**
(`version.php`: `$plugin->requires = 2021041900`). Every push and pull request
is verified through a CI matrix (`moodle-ci.yml`) on the following branches:

| Moodle branch         | PHP        | Status                              |
| --------------------- | ---------- | ----------------------------------- |
| 4.4.x (LTS)           | 8.2, 8.3   | Supported (verified in CI)          |
| 4.5.x (LTS)           | 8.2, 8.3   | Supported (verified in CI)          |
| 5.0.x                 | 8.2, 8.3   | Supported (verified in CI)          |

Older releases down to the declared minimum (Moodle 3.11) and newer releases
(5.1.x, 5.2.x) are expected to work but are not part of the CI matrix yet. If
you find an incompatibility please open an issue at
<https://github.com/ateeducacion/moodle-repository_omeka/issues>.

### Requirements

* **Moodle**: 3.11 or later (CI-verified on 4.4 LTS, 4.5 LTS and 5.0; expected
  to keep working on newer releases up to 5.2.x).
* **PHP**: 8.2 or 8.3 (CI matrix); any PHP supported by the Moodle release in
  use.
* **Database**: PostgreSQL or MariaDB (CI-verified); any database supported by
  Moodle should work.
* **Browser**: any modern, evergreen browser with JavaScript enabled.
* **Omeka-S**: a reachable [Omeka-S](https://omeka.org/s/) instance with the
  REST API exposed. API key data (`key_identity` and `key_credential`) is
  optional and only needed for accessing non-public content. For evaluation,
  you can target the public sandbox at
  <https://dev.omeka.org/omeka-s-sandbox/>.

## Installation

> **Recommended:** install from a
> [release ZIP](https://github.com/ateeducacion/moodle-repository_omeka/releases).
> Release ZIPs are produced by `release.yml` (or `make package RELEASE=X.Y.Z`)
> and only contain the files Moodle actually needs — no `tests/`, no Docker,
> no CI tooling, no hidden files.

### Installing via uploaded ZIP file (recommended)

1. Download the latest ZIP from the
   [Releases](https://github.com/ateeducacion/moodle-repository_omeka/releases)
   page.
2. Log in to your Moodle site as an admin and go to
   _Site administration > Plugins > Install plugins_.
3. Upload the ZIP file with the plugin code. The plugin type should be
   detected automatically (`repository`).
4. Check the plugin validation report and finish the installation.

### Installing manually

1. Download and extract the latest ZIP from the
   [Releases](https://github.com/ateeducacion/moodle-repository_omeka/releases)
   page.
2. Place the extracted contents in `{your/moodle/dirroot}/repository/omeka`.
3. Log in to your Moodle site as an admin and go to
   _Site administration > Notifications_ to complete the installation.

Alternatively, you can run

    $ php admin/cli/upgrade.php

to complete the installation from the command line.

## Configuration

1. Go to _Site administration > Plugins > Repositories > Manage repositories_
   and enable **Omeka**.
2. Create one repository instance per Omeka-S installation, specifying the
   instance URL, the site you want to display and, if you need access to
   non-public content, the API key data (`key_identity` and `key_credential`).
   These keys are **optional** for public content.
3. For a zero-setup evaluation you can point an instance at the public
   sandbox <https://dev.omeka.org/omeka-s-sandbox/> — no API key required.

## Development

For local development, Docker stack details, `moodle-plugin-ci` usage, CI
matrix and packaging, see [DEVELOPMENT.md](DEVELOPMENT.md).

## Support

For issues or suggestions, use the **Issues** section in the
[GitHub repository](https://github.com/ateeducacion/moodle-repository_omeka/issues).

## License

This project is licensed under **GPL v3**.

Copyright 2025-2026 Área de Tecnología Educativa.

## Author and Contact

Developed by the **Área de Tecnología Educativa** of the Government of the
Canary Islands.

- **Email:** [ate.educacion@gobiernodecanarias.org](mailto:ate.educacion@gobiernodecanarias.org)
- **Web:** [www.gobiernodecanarias.org/educacion](https://www.gobiernodecanarias.org/educacion)
