# Omeka-S Repository Plugin

This plugin allows you to connect Moodle with one or more Omeka-S instances, making it easy to integrate and access digital resources stored in Omeka-S from Moodle's file picker.

## Description

With this plugin, you can link Omeka-S resources directly in Moodle, allowing users to search, select, and reuse digital objects and collections managed in Omeka-S.

File searches are performed through the Omeka-S REST API, and results are paginated to avoid loading all items at once.
Navigation starts by displaying the available item sets, and upon selecting one, the items belonging to that set are listed.

## Installation

1. Copy the plugin into the `repository/omeka` folder of your Moodle installation.
2. Go to Site administration and complete the installation.
3. Create repository instances by specifying the URL of each Omeka-S installation, the site you want to display, and, if necessary, the API key data (`key_identity` and `key_credential`). These keys are optional for accessing public content.

## Dependencies

This plugin requires a working instance of [Omeka-S](https://omeka.org/s/) accessible from Moodle.

## Support

- For issues or suggestions, use the **Issues** section in the GitHub repository.

## License

This project is licensed under **GPL v3**.

## Author and Contact

Developed by the **Área de Tecnología Educativa** of the Government of the Canary Islands.

- **Email:** [ate.educacion@gobiernodecanarias.org](mailto:ate.educacion@gobiernodecanarias.org)
- **Web:** [www.gobiernodecanarias.org/educacion](https://www.gobiernodecanarias.org/educacion)
