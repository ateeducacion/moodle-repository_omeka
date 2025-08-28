<?php
namespace repository_omeka\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');                // External API base.
require_once($CFG->dirroot . '/repository/omeka/lib.php');      // repository_omeka class.

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

class list_sites extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'baseurl'       => new external_value(PARAM_URL, 'Omeka-S base URL'),
            'keyidentity'   => new external_value(PARAM_RAW, 'API key identity', VALUE_DEFAULT, ''),
            'keycredential' => new external_value(PARAM_RAW, 'API key credential', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(string $baseurl, string $keyidentity = '', string $keycredential = ''): array {
        require_capability('moodle/site:config', \context_system::instance());

        self::validate_parameters(self::execute_parameters(), [
            'baseurl' => $baseurl,
            'keyidentity' => $keyidentity,
            'keycredential' => $keycredential,
        ]);

        // Call the plugin helper in the global namespace.
        $sites = \repository_omeka::fetch_sites($baseurl, $keyidentity, $keycredential);

        $options = [];
        foreach ($sites as $id => $label) {
            $options[] = ['value' => (int)$id, 'label' => (string)$label];
        }

        return ['options' => $options];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'options' => new external_multiple_structure(
                new external_single_structure([
                    'value' => new external_value(PARAM_INT, 'Site ID'),
                    'label' => new external_value(PARAM_TEXT, 'Display label'),
                ])
            ),
        ]);
    }
}
