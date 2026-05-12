<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Helpers for the repository instance configuration form.
 *
 * @package   repository_omeka
 * @copyright 2026 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_omeka\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Stateless UI helpers that build the instance form fields.
 */
class instance_form {
    /**
     * Add the base URL field.
     *
     * @param \MoodleQuickForm $mform Form.
     * @return void
     */
    public static function add_baseurl_field(\MoodleQuickForm $mform): void {
        $mform->addElement('text', 'baseurl', get_string('baseurl', 'repository_omeka'));
        $mform->addRule('baseurl', get_string('required'), 'required', null, 'client');
        $mform->setType('baseurl', PARAM_URL);
    }

    /**
     * Add the API key identity / credential fields.
     *
     * @param \MoodleQuickForm $mform Form.
     * @return void
     */
    public static function add_keys_fields(\MoodleQuickForm $mform): void {
        $mform->addElement('text', 'keyidentity', get_string('keyidentity', 'repository_omeka'));
        $mform->setType('keyidentity', PARAM_TEXT);
        $mform->addHelpButton('keyidentity', 'keyidentity', 'repository_omeka');

        $mform->addElement('text', 'keycredential', get_string('keycredential', 'repository_omeka'));
        $mform->setType('keycredential', PARAM_TEXT);
        $mform->addHelpButton('keycredential', 'keycredential', 'repository_omeka');
    }

    /**
     * Add the Omeka-S site selector and its hidden label/slug fields.
     *
     * @param \MoodleQuickForm $mform Form.
     * @param array $sites Map of id => label.
     * @param int $currentsiteid Pre-selected site id.
     * @param string $currentsitelabel Pre-selected site label.
     * @param string $currentsiteslug Pre-selected site slug.
     * @return void
     */
    public static function add_site_selector(
        \MoodleQuickForm $mform,
        array $sites,
        int $currentsiteid,
        string $currentsitelabel,
        string $currentsiteslug
    ): void {
        $alllabel = get_string('all');
        $siteoptions = ['' => $alllabel] + ($sites ?: []);
        $mform->addElement('select', 'siteid', get_string('site', 'repository_omeka'), $siteoptions);
        $mform->setType('siteid', PARAM_INT);
        $mform->addHelpButton('siteid', 'site', 'repository_omeka');
        $mform->disabledIf('siteid', 'baseurl', 'eq', '');
        if ($currentsiteid) {
            $mform->setDefault('siteid', $currentsiteid);
        }

        $mform->addElement('hidden', 'sitelabel');
        $mform->setType('sitelabel', PARAM_TEXT);
        $mform->addElement('hidden', 'siteslug');
        $mform->setType('siteslug', PARAM_ALPHANUMEXT);

        if ($currentsitelabel !== '') {
            $mform->setDefault('sitelabel', $currentsitelabel);
        }
        if ($currentsiteslug !== '') {
            $mform->setDefault('siteslug', $currentsiteslug);
        }
    }

    /**
     * Add the file type selector (filetypes element if available, plain text otherwise).
     *
     * @param \MoodleQuickForm $mform Form.
     * @param string $current Current value.
     * @return void
     */
    public static function add_filetype_selector(\MoodleQuickForm $mform, string $current): void {
        if (class_exists('core_form\filetypes_util')) {
            $mform->addElement('filetypes', 'acceptedtypes', get_string('acceptedtypes', 'repository_omeka'));
        } else {
            $mform->addElement('text', 'acceptedtypes', get_string('acceptedtypes', 'repository_omeka'));
            $mform->setType('acceptedtypes', PARAM_RAW_TRIMMED);
        }
        if ($current !== '') {
            $mform->setDefault('acceptedtypes', $current);
        }
    }
}
