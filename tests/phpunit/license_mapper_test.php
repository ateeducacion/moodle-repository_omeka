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
 * PHPUnit tests for the license mapper.
 *
 * @package    repository_omeka
 * @category   test
 * @copyright  2026 Área de Tecnología Educativa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_omeka\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/repository/omeka/lib.php');

/**
 * Tests for {@see license_mapper}.
 */
final class license_mapper_test extends \advanced_testcase {
    /**
     * Data provider for {@see test_map}.
     *
     * @return array[]
     */
    public static function map_provider(): array {
        return [
            'cc-by url' => ['https://creativecommons.org/licenses/by/4.0/', 'cc-by'],
            'cc-by-sa url' => ['https://creativecommons.org/licenses/by-sa/4.0/', 'cc-by-sa'],
            'cc-by-nc url' => ['https://creativecommons.org/licenses/by-nc/4.0/', 'cc-by-nc'],
            'cc-by-nc-sa url' => ['https://creativecommons.org/licenses/by-nc-sa/3.0/', 'cc-by-nc-sa'],
            'cc-by-nc-nd url' => ['https://creativecommons.org/licenses/by-nc-nd/2.0/', 'cc-by-nc-nd'],
            'cc-by-nd url' => ['https://creativecommons.org/licenses/by-nd/4.0/', 'cc-by-nd'],
            'cc0 url' => ['https://creativecommons.org/publicdomain/zero/1.0/', 'cc-zero'],
            'cc0 text' => ['CC0', 'cc-zero'],
            'public domain' => ['Public Domain', 'cc-zero'],
            'all rights' => ['All rights reserved', 'allrightsreserved'],
            'generic cc' => ['Creative Commons attribution-like', 'cc'],
            'gpl' => ['GNU GPL v3', 'gnugpl'],
            'gpl url' => ['https://www.gnu.org/licenses/gpl-3.0.en.html', 'gnugpl'],
            'empty' => ['', 'unknown'],
            'unknown text' => ['fancy license', 'unknown'],
            'whitespace' => ['   ', 'unknown'],
        ];
    }

    /**
     * Maps strings to Moodle license shortnames.
     *
     * @dataProvider map_provider
     * @param string $input License input.
     * @param string $expected Expected shortname.
     */
    public function test_map(string $input, string $expected): void {
        [$short, $url] = license_mapper::map($input);
        $this->assertSame($expected, $short);
        $this->assertIsString($url);
    }

    /**
     * Returns the discovered URL when one is present.
     */
    public function test_map_returns_url(): void {
        [$short, $url] = license_mapper::map('See https://example.test/license for details');
        $this->assertSame('unknown', $short);
        $this->assertSame('https://example.test/license', $url);
    }
}
