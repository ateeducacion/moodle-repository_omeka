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
 * PHPUnit tests for the JSON-LD parser.
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
 * Tests for {@see jsonld_parser}.
 */
final class jsonld_parser_test extends \advanced_testcase {
    /**
     * Decode a fixture file into an associative array.
     *
     * @param string $name Fixture name (without .json).
     * @return array
     */
    private function read_json_fixture(string $name): array {
        $path = __DIR__ . '/../fixtures/jsonld/' . $name . '.json';
        $raw = file_get_contents($path);
        return json_decode((string)$raw, true);
    }

    /**
     * title()/description()/author() pull standard fields from a media fixture.
     */
    public function test_extracts_media_metadata(): void {
        $media = $this->read_json_fixture('media_image');

        $this->assertSame('Portrait of Ada', jsonld_parser::title($media));
        $this->assertSame('Portrait photograph.', jsonld_parser::description($media));
        $this->assertSame('Margaret Carpenter', jsonld_parser::author($media));
    }

    /**
     * first_property_text() supports @value, value_resource_title, and @id shapes.
     */
    public function test_first_property_text_shapes(): void {
        $resource = [
            'dcterms:title' => [['@value' => 'A title']],
            'dcterms:subject' => [['value_resource_title' => 'Linked title']],
            'dcterms:license' => [['@id' => 'https://creativecommons.org/licenses/by/4.0/']],
        ];

        $this->assertSame('A title', jsonld_parser::first_property_text($resource, ['dcterms:title']));
        $this->assertSame('Linked title', jsonld_parser::first_property_text($resource, ['dcterms:subject']));
        $this->assertSame(
            'https://creativecommons.org/licenses/by/4.0/',
            jsonld_parser::first_property_text($resource, ['dcterms:license'])
        );
        $this->assertNull(jsonld_parser::first_property_text($resource, ['dcterms:nope']));
    }

    /**
     * parse_iso8601() understands several common date shapes.
     */
    public function test_parse_iso8601_formats(): void {
        $this->assertSame(
            (new \DateTimeImmutable('2024-01-15T10:00:00+00:00'))->getTimestamp(),
            jsonld_parser::parse_iso8601('2024-01-15T10:00:00Z')
        );
        $date = jsonld_parser::parse_iso8601('2024-01-15');
        $this->assertIsInt($date);
        $tz = jsonld_parser::parse_iso8601('2024-01-15T10:00:00+02:00');
        $this->assertIsInt($tz);
        $this->assertNull(jsonld_parser::parse_iso8601('not a date'));
        $this->assertNull(jsonld_parser::parse_iso8601(''));
    }

    /**
     * first_date_timestamp() returns the first valid timestamp from a list of properties.
     */
    public function test_first_date_timestamp(): void {
        $resource = [
            'dcterms:date' => [['@value' => '2024-03-01T12:34:56+00:00']],
        ];
        $timestamp = jsonld_parser::first_date_timestamp($resource);
        $this->assertIsInt($timestamp);
        $this->assertSame(
            (new \DateTimeImmutable('2024-03-01T12:34:56+00:00'))->getTimestamp(),
            $timestamp
        );

        $this->assertNull(jsonld_parser::first_date_timestamp(['o:title' => 'no dates here']));
    }
}
