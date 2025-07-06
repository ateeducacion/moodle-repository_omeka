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
 * Privacy Subsystem implementation for repository_omeka.
 *
 * PHP version 7.4 or later
 *
 * @package   Repository_Omeka
 * @author    Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @copyright 2025 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link      https://github.com/educacioncanarias/moodle-repository_omeka
 */

namespace repository_omeka\privacy;

/**
 * Privacy plugin providers.
 *
 * @package   Repository_Omeka
 * @author    Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @copyright 2025 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link      https://github.com/educacioncanarias/moodle-repository_omeka
 */
class Provider implements \core_privacy\local\metadata\null_provider {

    /**
     * Returns the reason why this plugin stores no data.
     *
     * @return string
     */
    /**
     * Returns the reason why this plugin stores no data.
     *
     * @return string
     */
    public static function getreason(): string {
        return get_string('privacy:metadata', 'repository_omeka');
    }
}
