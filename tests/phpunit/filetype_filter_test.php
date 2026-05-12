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
 * PHPUnit tests for the filetype filter.
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
 * Tests for {@see filetype_filter}.
 */
final class filetype_filter_test extends \advanced_testcase {
    /**
     * Empty filter allows everything.
     */
    public function test_empty_filter_allows_everything(): void {
        $filter = new filetype_filter('');
        $this->assertTrue($filter->is_empty());
        $this->assertTrue($filter->is_allowed('anything.zip', 'application/zip'));
    }

    /**
     * Wildcard allows everything.
     */
    public function test_wildcard_allows_everything(): void {
        $filter = new filetype_filter('*');
        $this->assertTrue($filter->is_allowed('a.exe', 'application/octet-stream'));
    }

    /**
     * Extensions match with or without a leading dot.
     */
    public function test_extension_tokens(): void {
        $filter = new filetype_filter('.pdf, png');
        $this->assertTrue($filter->is_allowed('a.pdf', 'application/pdf'));
        $this->assertTrue($filter->is_allowed('image.PNG', null));
        $this->assertFalse($filter->is_allowed('a.zip', 'application/zip'));
    }

    /**
     * Bare mimetypes match exactly.
     */
    public function test_mimetype_tokens(): void {
        $filter = new filetype_filter('application/json');
        $this->assertTrue($filter->is_allowed('whatever', 'application/json'));
        $this->assertFalse($filter->is_allowed('a.pdf', 'application/pdf'));
    }

    /**
     * Group tokens match by extension or mimetype prefix.
     */
    public function test_group_tokens(): void {
        $filter = new filetype_filter('image, document');
        $this->assertTrue($filter->is_allowed('p.png', 'image/png'));
        $this->assertTrue($filter->is_allowed('x.jpg', ''));
        $this->assertTrue($filter->is_allowed('a.pdf', 'application/pdf'));
        $this->assertTrue($filter->is_allowed('a.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $this->assertFalse($filter->is_allowed('a.zip', 'application/zip'));
    }

    /**
     * Web image group only allows browser-renderable images.
     */
    public function test_web_image_group(): void {
        $filter = new filetype_filter('web_image');
        $this->assertTrue($filter->is_allowed('a.jpg', 'image/jpeg'));
        $this->assertFalse($filter->is_allowed('a.tif', 'image/tiff'));
    }

    /**
     * parse_tokens() normalises separators and case.
     */
    public function test_parse_tokens_normalisation(): void {
        $this->assertSame(['image', 'pdf'], filetype_filter::parse_tokens(" Image , PDF\nImage"));
        $this->assertSame([], filetype_filter::parse_tokens(''));
    }
}
