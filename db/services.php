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

/**
 * External function service declarations for repository_omeka.
 *
 * @package    repository_omeka
 * @category   external
 * @copyright  2025 Área de Tecnología Educativa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$functions = [
    'repository_omeka_list_sites' => [
        'classname'   => 'repository_omeka\\external\\list_sites',
        'methodname'  => 'execute',
        'description' => 'Return Omeka-S sites for a given baseurl and optional API keys.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'moodle/site:config',
    ],
];
