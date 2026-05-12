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
 * Smoke tests for the public API of the repository.
 *
 * @package    repository_omeka
 * @category   test
 * @copyright  2025 Ernesto Serrano
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/repository/omeka/lib.php');

use repository_omeka\local\filetype_filter;
use repository_omeka\local\format_helper;
use repository_omeka\local\license_mapper;

/**
 * Backwards-compatible smoke tests exercising the public helpers via the new namespaced classes.
 */
final class repository_omeka_test extends advanced_testcase {
    /**
     * Reset Moodle state after each test.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
    }

    /**
     * Size parser handles common SI and IEC units.
     */
    public function test_size_parser_basic_units(): void {
        $this->assertSame(1024, format_helper::parse_human_size('1 KB'));
        $this->assertSame(1048576, format_helper::parse_human_size('1 MiB'));
        $this->assertSame(1500000, format_helper::parse_human_size('1.5 MB'));
    }

    /**
     * Mimetype guesser inspects URL extensions.
     */
    public function test_guess_mimetype_from_url(): void {
        $this->assertSame('image/jpeg', format_helper::guess_mimetype_from_filename('https://x/y/z/photo.JPG'));
        $this->assertSame('application/pdf', format_helper::guess_mimetype_from_filename('http://x/file.pdf?download=1'));
        $this->assertSame('application/zip', format_helper::guess_mimetype_from_filename('http://x/a/b/c/archive.zip'));
    }

    /**
     * License mapper recognises common Creative Commons URLs.
     */
    public function test_license_mapping(): void {
        [$short, $url] = license_mapper::map('https://creativecommons.org/licenses/by/4.0/');
        $this->assertSame('cc-by', $short);
        $this->assertNotEmpty($url);

        [$short, ] = license_mapper::map('CC0');
        $this->assertSame('cc-zero', $short);
    }

    /**
     * Instance filetype filter behaves correctly with "image" group token.
     */
    public function test_instance_filetype_filter(): void {
        $filter = new filetype_filter('image');
        $this->assertTrue($filter->is_allowed('foto.png', 'image/png'));
        $this->assertFalse($filter->is_allowed('doc.zip', 'application/zip'));
        $this->assertTrue($filter->is_allowed('x.jpg', ''));
    }
}
