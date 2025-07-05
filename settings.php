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
 * Plugin settings for repository_omeka.
 *
 * @package   repository_omeka
 * @copyright 2025 Área de Tecnología Educativa
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$settings->add(new admin_setting_configtext(
    'repository_omeka/baseurl',
    get_string('baseurl', 'repository_omeka'),
    get_string('baseurl_desc', 'repository_omeka'),
    '',
    PARAM_URL
));

$settings->add(new admin_setting_configtext(
    'repository_omeka/apikey',
    get_string('apikey', 'repository_omeka'),
    get_string('apikey_desc', 'repository_omeka'),
    '',
    PARAM_TEXT
));
