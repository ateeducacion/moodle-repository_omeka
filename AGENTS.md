
# Correct use of phpcs and phpcbf with moodle-plugin-ci

To analyze and fix your Moodle plugin code according to Moodle standards, you should use the `phpcs` (PHP CodeSniffer) and `phpcbf` (PHP Code Beautifier and Fixer) tools included in the `moodle-plugin-ci` environment.

## Running phpcs

To analyze specific files or directories and check if they comply with Moodle standards, run:

```bash
../moodle-plugin-ci/vendor/bin/phpcs --standard=moodle ./path/to/file_or_directory.php
```

This will display any style errors and warnings detected according to the Moodle standard.

## Running phpcbf

To automatically fix style errors detected by `phpcs`, run:

```bash
../moodle-plugin-ci/vendor/bin/phpcbf --standard=moodle ./path/to/file_or_directory.php
```

This will modify the files to fix issues that can be automatically resolved.

## Notes

- Make sure you have installed `moodle-plugin-ci` using composer outside the Moodle directory, for example:
  ```bash
  php composer.phar create-project moodlehq/moodle-plugin-ci ../moodle-plugin-ci ^4
  ```
- If you use the `.phar` file, you can run:
  ```bash
  php moodle-plugin-ci.phar phpcs ./path/to/file_or_directory.php
  php moodle-plugin-ci.phar phpcbf ./path/to/file_or_directory.php
  ```
- See the official [moodle-plugin-ci documentation](https://github.com/moodlehq/moodle-plugin-ci) for more details and advanced options.
