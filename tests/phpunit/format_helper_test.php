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
 * PHPUnit tests for the format helper.
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
 * Tests for {@see format_helper}.
 */
final class format_helper_test extends \advanced_testcase {
    /**
     * Data provider for {@see test_parse_human_size}.
     *
     * @return array[]
     */
    public static function size_provider(): array {
        return [
            ['1.5MB', 1500000],
            ['1.5 MB', 1500000],
            ['2 GB', 2000000000],
            ['500KB', 512000],
            ['1024', 1024],
            ['1 MiB', 1048576],
            ['2 GiB', 2147483648],
            ['abc', null],
            ['', null],
            ['  ', null],
        ];
    }

    /**
     * parse_human_size() recognises common size formats.
     *
     * @dataProvider size_provider
     * @param string $input Input text.
     * @param int|null $expected Expected bytes.
     */
    public function test_parse_human_size(string $input, ?int $expected): void {
        $this->assertSame($expected, format_helper::parse_human_size($input));
    }

    /**
     * guess_mimetype_from_filename() supports plain names and full URLs.
     */
    public function test_guess_mimetype_from_filename(): void {
        $this->assertSame('image/jpeg', format_helper::guess_mimetype_from_filename('photo.JPG'));
        $this->assertSame('application/pdf', format_helper::guess_mimetype_from_filename('https://x/file.pdf?download=1'));
        $this->assertSame('application/zip', format_helper::guess_mimetype_from_filename('http://x/a/b/c/archive.zip'));
        $this->assertNull(format_helper::guess_mimetype_from_filename('noext'));
    }
}
