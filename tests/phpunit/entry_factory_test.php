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
 * PHPUnit tests for the entry factory (binary vs. linked media entries).
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
 * Tests for {@see entry_factory::build_media_entry()}.
 */
final class entry_factory_test extends \advanced_testcase {
    /**
     * Reset Moodle state between tests so the global $OUTPUT is available.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
    }

    /**
     * Binary media (upload ingester) produces an entry whose `source` is the
     * `o:original_url` without any marker — Moodle will hand the bytes to
     * {@see \repository_omeka::get_file()} for FILE_INTERNAL storage.
     */
    public function test_binary_upload_entry_source_is_original_url(): void {
        $item = ['o:id' => 1, 'o:title' => 'Photo'];
        $media = [
            'o:id' => 10,
            'o:ingester' => 'upload',
            'o:original_url' => 'https://omeka.test/files/original/foo.jpg',
            'o:media_type' => 'image/jpeg',
            'o:size' => 12345,
        ];
        $entry = entry_factory::build_media_entry($item, $media, new filetype_filter(''));
        $this->assertIsArray($entry);
        $this->assertSame('https://omeka.test/files/original/foo.jpg', $entry['source']);
        $this->assertFalse(ingester_classifier::is_linked((string)$entry['source']));
        $this->assertSame(12345, $entry['size']);
    }

    /**
     * oEmbed (YouTube) media produces a linked entry whose `source` carries
     * the "linked:" marker and points at the iframe URL extracted from
     * `data.html`. The picker must therefore offer this entry through
     * FILE_EXTERNAL only.
     */
    public function test_oembed_youtube_entry_is_linked_with_iframe_url(): void {
        $item = ['o:id' => 2, 'o:title' => 'Some video'];
        $media = [
            'o:id' => 20,
            'o:ingester' => 'oembed',
            'o:source' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'o:media_type' => null,
            'o:size' => null,
            'data' => [
                'type' => 'video',
                'html' => '<iframe width="560" height="315" '
                    . 'src="https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed" '
                    . 'frameborder="0" allowfullscreen></iframe>',
            ],
        ];
        $entry = entry_factory::build_media_entry($item, $media, new filetype_filter(''));
        $this->assertIsArray($entry);
        $this->assertTrue(ingester_classifier::is_linked((string)$entry['source']));
        $this->assertSame(
            'https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed',
            ingester_classifier::strip_marker((string)$entry['source'])
        );
        // No size advertised when Omeka-S reports null.
        $this->assertArrayNotHasKey('size', $entry);
        $this->assertArrayNotHasKey('filesize', $entry);
    }

    /**
     * IIIF presentation media produces a linked entry whose `source` is the
     * manifest URL — the only meaningful Moodle representation for IIIF.
     */
    public function test_iiif_presentation_entry_is_linked_manifest(): void {
        $item = ['o:id' => 3, 'o:title' => 'Manuscript'];
        $media = [
            'o:id' => 30,
            'o:ingester' => 'iiif_presentation',
            'o:source' => 'https://iiif.example.test/manuscripts/42/manifest.json',
            'o:media_type' => null,
        ];
        $entry = entry_factory::build_media_entry($item, $media, new filetype_filter(''));
        $this->assertIsArray($entry);
        $this->assertTrue(ingester_classifier::is_linked((string)$entry['source']));
        $this->assertSame(
            'https://iiif.example.test/manuscripts/42/manifest.json',
            ingester_classifier::strip_marker((string)$entry['source'])
        );
    }

    /**
     * URL ingester media is also linked; the canonical URL is `o:source`.
     */
    public function test_url_ingester_entry_is_linked(): void {
        $item = ['o:id' => 4, 'o:title' => 'External page'];
        $media = [
            'o:id' => 40,
            'o:ingester' => 'url',
            'o:source' => 'https://example.test/some-resource',
        ];
        $entry = entry_factory::build_media_entry($item, $media, new filetype_filter(''));
        $this->assertIsArray($entry);
        $this->assertTrue(ingester_classifier::is_linked((string)$entry['source']));
        $this->assertSame(
            'https://example.test/some-resource',
            ingester_classifier::strip_marker((string)$entry['source'])
        );
    }

    /**
     * Linked entries bypass the instance filetype filter, otherwise they
     * would always be hidden (oEmbed iframe URLs have no useful extension).
     */
    public function test_linked_entries_bypass_filetype_filter(): void {
        $item = ['o:id' => 5, 'o:title' => 'video'];
        $media = [
            'o:id' => 50,
            'o:ingester' => 'oembed',
            'o:source' => 'https://vimeo.com/12345',
            'data' => ['html' => '<iframe src="https://player.vimeo.com/video/12345"></iframe>'],
        ];
        // Filter restricted to PDFs; the linked oEmbed must still come through.
        $entry = entry_factory::build_media_entry($item, $media, new filetype_filter('pdf'));
        $this->assertIsArray($entry);
        $this->assertTrue(ingester_classifier::is_linked((string)$entry['source']));
    }

    /**
     * A linked media whose external URL is a javascript: URI (e.g. from a
     * compromised Omeka) is dropped entirely so it can never become a stored
     * FILE_EXTERNAL hyperlink (stored-XSS guard).
     */
    public function test_linked_media_with_dangerous_scheme_is_dropped(): void {
        $item = ['o:id' => 7, 'o:title' => 'evil'];
        $media = [
            'o:id' => 70,
            'o:ingester' => 'url',
            'o:source' => 'javascript:alert(document.cookie)',
        ];
        $this->assertNull(
            entry_factory::build_media_entry($item, $media, new filetype_filter(''))
        );
    }

    /**
     * Binary entries still respect the filetype filter (regression).
     */
    public function test_binary_entries_still_filtered(): void {
        $item = ['o:id' => 6, 'o:title' => 'zip'];
        $media = [
            'o:id' => 60,
            'o:ingester' => 'upload',
            'o:original_url' => 'https://omeka.test/files/original/foo.zip',
            'o:media_type' => 'application/zip',
        ];
        $entry = entry_factory::build_media_entry($item, $media, new filetype_filter('pdf'));
        $this->assertNull($entry);
    }
}
