<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace repository_omeka\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');                // External API base.
require_once($CFG->dirroot . '/repository/omeka/lib.php');      // Repository_omeka class.

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

/**
 * External function to list Omeka-S sites for configuration UI.
 *
 * @package    repository_omeka
 * @category   external
 * @copyright  2025 Área de Tecnología Educativa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_sites extends external_api {

    /**
     * Parameters definition for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'baseurl'       => new external_value(PARAM_URL, 'Omeka-S base URL'),
            'keyidentity'   => new external_value(PARAM_RAW, 'API key identity', VALUE_DEFAULT, ''),
            'keycredential' => new external_value(PARAM_RAW, 'API key credential', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Return the sites available in a given Omeka-S instance.
     *
     * @param string $baseurl Omeka-S base URL.
     * @param string $keyidentity Optional API key identity.
     * @param string $keycredential Optional API key credential.
     * @return array Structure suitable for a select element.
     */
    public static function execute(string $baseurl, string $keyidentity = '', string $keycredential = ''): array {
        require_capability('moodle/site:config', \context_system::instance());

        self::validate_parameters(self::execute_parameters(), [
            'baseurl' => $baseurl,
            'keyidentity' => $keyidentity,
            'keycredential' => $keycredential,
        ]);

        // Get detailed sites (id, label, title, slug).
        $sites = \repository_omeka::fetch_sites_detailed($baseurl, $keyidentity, $keycredential);

        $options = [];
        foreach ($sites as $site) {
            $options[] = [
                'value' => (int)($site['id'] ?? 0),
                'label' => (string)($site['label'] ?? ''),
                'title' => (string)($site['title'] ?? ''),
                'slug' => (string)($site['slug'] ?? ''),
            ];
        }

        return ['options' => $options];
    }

    /**
     * Returns structure definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'options' => new external_multiple_structure(
                new external_single_structure([
                    'value' => new external_value(PARAM_INT, 'Site ID'),
                    'label' => new external_value(PARAM_TEXT, 'Display label'),
                    'title' => new external_value(PARAM_TEXT, 'Site title', VALUE_DEFAULT, ''),
                    'slug' => new external_value(PARAM_ALPHANUMEXT, 'Site slug', VALUE_DEFAULT, ''),
                ])
            ),
        ]);
    }
}
