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
 * License mapping between Omeka-S values and Moodle license shortnames.
 *
 * @package   repository_omeka
 * @copyright 2026 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_omeka\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Map Omeka-S license strings or URIs to Moodle license codes.
 */
class license_mapper {
    /**
     * Return a Moodle license shortname and a normalised URL.
     *
     * @param string $value Omeka-S license value (text or URL).
     * @return array [string $shortname, string $url] where $shortname is 'unknown' when not recognised.
     */
    public static function map(string $value): array {
        $shortname = 'unknown';
        $url = '';
        $clean = trim($value);
        if ($clean === '') {
            return [$shortname, $url];
        }

        $lower = strtolower($clean);
        if (preg_match('#https?://[^\s]+#', $clean, $matches)) {
            $url = $matches[0];
        } else if (filter_var($clean, FILTER_VALIDATE_URL)) {
            $url = $clean;
        }

        if (strpos($lower, 'creativecommons.org') !== false) {
            $url = $url !== '' ? $url : $clean;
            if (strpos($lower, '/publicdomain/zero/') !== false || strpos($lower, 'cc0') !== false) {
                return ['cc-zero', $url];
            }
            if (preg_match('#/licenses/([a-z-]+)/#', $lower, $matches)) {
                $map = [
                    'by' => 'cc-by',
                    'by-sa' => 'cc-by-sa',
                    'by-nd' => 'cc-by-nd',
                    'by-nc' => 'cc-by-nc',
                    'by-nc-sa' => 'cc-by-nc-sa',
                    'by-nc-nd' => 'cc-by-nc-nd',
                ];
                if (isset($map[$matches[1]])) {
                    return [$map[$matches[1]], $url];
                }
            }
        }

        if (
            strpos($lower, 'cc0') !== false
            || strpos($lower, 'public domain') !== false
            || strpos($lower, 'zero') !== false
        ) {
            return ['cc-zero', $url !== '' ? $url : $clean];
        }

        if (strpos($lower, 'all rights reserved') !== false) {
            return ['allrightsreserved', $url];
        }

        if (strpos($lower, 'gpl') !== false || strpos($lower, 'gnu') !== false) {
            return ['gnugpl', $url];
        }

        if (strpos($lower, 'creative commons') !== false) {
            return ['cc', $url];
        }

        return [$shortname, $url];
    }
}
