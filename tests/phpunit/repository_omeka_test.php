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
 * PHPUnit tests for the Omeka repository.
 *
 * @package    repository_omeka
 * @category   test
 * @copyright  2025 Ernesto Serrano
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/repository/omeka/lib.php');

/**
 * Test cases for repository_omeka.
 */
final class repository_omeka_test extends advanced_testcase {

    /**
     * Reset Moodle state after each test.
     */
    protected function setUp(): void {
        // Clean Moodle data and caches between tests.
        $this->resetAfterTest();
    }

    /**
     * Test size parser for basic units.
     */
    public function test_size_parser_basic_units(): void {
        $repo = new repository_omeka_testhooks(
            0,
            \context_system::instance(),
            ['baseurl' => 'http://example.test'], // Dummy base URL.
            false
        );

        $this->assertSame(1024, $repo->parse_human_size_to_bytes('1 KB'));
        $this->assertSame(1048576, $repo->parse_human_size_to_bytes('1 MiB'));
        $this->assertSame(1500000, $repo->parse_human_size_to_bytes('1.5 MB'));
    }

    /**
     * Test guessing mimetype from URL.
     */
    public function test_guess_mimetype_from_url(): void {
        $repo = new repository_omeka_testhooks(
            0,
            \context_system::instance(),
            ['baseurl' => 'http://x'],
            false
        );

        $this->assertSame('image/jpeg', $repo->guess_mimetype_from_url('https://x/y/z/photo.JPG'));
        $this->assertSame('application/pdf', $repo->guess_mimetype_from_url('http://x/file.pdf?download=1'));
        $this->assertSame('application/zip', $repo->guess_mimetype_from_url('http://x/a/b/c/archive.zip'));
    }

    /**
     * Test license mapping.
     */
    public function test_license_mapping(): void {
        $repo = new repository_omeka_testhooks(
            0,
            \context_system::instance(),
            ['baseurl' => 'http://x'],
            false
        );

        [$short, $url] = $repo->map_license_to_moodle('https://creativecommons.org/licenses/by/4.0/');
        $this->assertSame('cc-by', $short);
        $this->assertNotEmpty($url);

        [$short, $url] = $repo->map_license_to_moodle('CC0');
        $this->assertSame('cc-zero', $short);
    }

    /**
     * Test filtering of file types at instance level.
     */
    public function test_instance_filetype_filter(): void {
        // Only allow images.
        $repo = new repository_omeka_testhooks(
            0,
            \context_system::instance(),
            [
                'baseurl' => 'http://x',
                'acceptedtypes' => 'image', // Same format as the form.
            ],
            false
        );

        $this->assertTrue($repo->is_allowed_by_instance_types('foto.png', 'image/png'));
        $this->assertFalse($repo->is_allowed_by_instance_types('doc.zip', 'application/zip'));
        $this->assertTrue($repo->is_allowed_by_instance_types('x.jpg', '')); // Extension alone also works.
    }
}
