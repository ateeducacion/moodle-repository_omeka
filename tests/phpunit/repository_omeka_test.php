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
use repository_omeka\local\ingester_classifier;
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

    /**
     * The repository must advertise FILE_INTERNAL (binary copy), FILE_EXTERNAL
     * (URL hot-link) **and** FILE_REFERENCE (alias). The reference mode is what
     * lets mod_file's filemanager show the "Make a copy / Create an alias"
     * radio — mod_file's MoodleQuickForm_filemanager is built with
     * `return_types = FILE_INTERNAL | FILE_REFERENCE | FILE_CONTROLLED_LINK`,
     * so without FILE_REFERENCE the intersection collapses to FILE_INTERNAL
     * only and the radio never appears.
     */
    public function test_supported_returntypes_includes_internal_external_and_reference(): void {
        $reflection = new \ReflectionClass(\repository_omeka::class);
        $method = $reflection->getMethod('supported_returntypes');
        $instance = $reflection->newInstanceWithoutConstructor();
        $returntypes = (int)$method->invoke($instance);
        $this->assertSame(FILE_INTERNAL, $returntypes & FILE_INTERNAL);
        $this->assertSame(FILE_EXTERNAL, $returntypes & FILE_EXTERNAL);
        $this->assertSame(FILE_REFERENCE, $returntypes & FILE_REFERENCE);
    }

    /**
     * get_link() strips the "linked:" marker so the URL Moodle stores is
     * the canonical external one.
     */
    public function test_get_link_strips_linked_marker(): void {
        $reflection = new \ReflectionClass(\repository_omeka::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $marked = ingester_classifier::mark_linked('https://www.youtube.com/embed/abc');
        $this->assertSame('https://www.youtube.com/embed/abc', $instance->get_link($marked));
        // Plain URLs (binary entries) round-trip unchanged.
        $plain = 'https://omeka.test/files/original/foo.jpg';
        $this->assertSame($plain, $instance->get_link($plain));
    }

    /**
     * get_file() refuses to download a linked source with a clear instruction
     * pointing the user at the "Create a link" option.
     */
    public function test_get_file_throws_on_linked_source(): void {
        $reflection = new \ReflectionClass(\repository_omeka::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $marked = ingester_classifier::mark_linked('https://www.youtube.com/embed/abc');
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/oEmbed|IIIF|external link|linked external/i');
        $instance->get_file($marked);
    }
}
