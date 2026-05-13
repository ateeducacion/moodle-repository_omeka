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
 * PHPUnit tests for the ingester classifier.
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
 * Tests for {@see ingester_classifier}.
 */
final class ingester_classifier_test extends \advanced_testcase {
    /**
     * Upload ingester is classified as binary.
     */
    public function test_upload_ingester_is_binary(): void {
        $media = [
            'o:ingester' => 'upload',
            'o:original_url' => 'https://omeka.test/files/original/foo.jpg',
            'o:media_type' => 'image/jpeg',
        ];
        $this->assertSame(ingester_classifier::TYPE_BINARY, ingester_classifier::classify_media($media));
    }

    /**
     * oEmbed ingester is classified as linked even when no original_url exists.
     */
    public function test_oembed_ingester_is_linked(): void {
        $media = [
            'o:ingester' => 'oembed',
            'o:source' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'data' => [
                'html' => '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" frameborder="0"></iframe>',
            ],
        ];
        $this->assertSame(ingester_classifier::TYPE_LINKED, ingester_classifier::classify_media($media));
    }

    /**
     * IIIF presentation ingester is classified as linked.
     */
    public function test_iiif_presentation_ingester_is_linked(): void {
        $media = [
            'o:ingester' => 'iiif_presentation',
            'o:source' => 'https://iiif.example.test/manifest.json',
        ];
        $this->assertSame(ingester_classifier::TYPE_LINKED, ingester_classifier::classify_media($media));
    }

    /**
     * URL ingester is classified as linked when the MIME hint is missing or
     * non-binary (this is the catch-all reference case).
     */
    public function test_url_ingester_without_binary_mime_is_linked(): void {
        $media = [
            'o:ingester' => 'url',
            'o:source' => 'https://example.test/page.html',
        ];
        $this->assertSame(ingester_classifier::TYPE_LINKED, ingester_classifier::classify_media($media));
    }

    /**
     * URL ingester with an image MIME type is binary — Omeka's `url` ingester
     * is a catch-all that often wraps a fully-downloadable binary (e.g. the
     * sandbox's Sherlock Holmes images stored as Wikipedia URLs). The MIME
     * type wins over the ingester taxonomy.
     */
    public function test_url_ingester_with_image_mime_is_binary(): void {
        $media = [
            'o:ingester' => 'url',
            'o:media_type' => 'image/png',
            'o:source' => 'https://upload.wikimedia.org/wikipedia/commons/d/dd/Arthur.png',
            'o:original_url' => 'https://omeka.test/files/original/abc.png',
        ];
        $this->assertSame(ingester_classifier::TYPE_BINARY, ingester_classifier::classify_media($media));
    }

    /**
     * Common binary MIME types short-circuit the ingester check entirely.
     *
     * @dataProvider binary_mime_provider
     * @param string $mime MIME type that must classify as binary.
     */
    public function test_binary_mime_types_short_circuit(string $mime): void {
        $media = [
            'o:ingester' => 'url',
            'o:media_type' => $mime,
            'o:source' => 'https://example.test/asset',
        ];
        $this->assertSame(ingester_classifier::TYPE_BINARY, ingester_classifier::classify_media($media));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function binary_mime_provider(): array {
        return [
            'image/jpeg' => ['image/jpeg'],
            'image/svg+xml' => ['image/svg+xml'],
            'video/mp4' => ['video/mp4'],
            'audio/mpeg' => ['audio/mpeg'],
            'application/pdf' => ['application/pdf'],
            'application/zip' => ['application/zip'],
            'application/msword' => ['application/msword'],
            'application/vnd.openxmlformats officedocs' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            'font/woff2' => ['font/woff2'],
        ];
    }

    /**
     * Non-binary MIME types fall back to the ingester check. text/html on
     * a `url` ingester (e.g. a SlideShare page wrapped via URL) stays linked.
     */
    public function test_text_html_mime_keeps_url_linked(): void {
        $media = [
            'o:ingester' => 'url',
            'o:media_type' => 'text/html',
            'o:source' => 'https://example.test/page',
        ];
        $this->assertSame(ingester_classifier::TYPE_LINKED, ingester_classifier::classify_media($media));
    }

    /**
     * Unknown ingester with original_url falls back to binary.
     */
    public function test_unknown_ingester_with_original_url_is_binary(): void {
        $media = [
            'o:ingester' => 'somenewthing',
            'o:original_url' => 'https://omeka.test/files/original/x.png',
        ];
        $this->assertSame(ingester_classifier::TYPE_BINARY, ingester_classifier::classify_media($media));
    }

    /**
     * Unknown ingester without original_url is treated as linked.
     */
    public function test_unknown_ingester_without_original_url_is_linked(): void {
        $media = [
            'o:ingester' => 'mystery',
            'o:source' => 'https://example.test/something',
        ];
        $this->assertSame(ingester_classifier::TYPE_LINKED, ingester_classifier::classify_media($media));
    }

    /**
     * extract_linked_url() prefers an oEmbed iframe src over o:source.
     */
    public function test_extract_linked_url_prefers_iframe_src(): void {
        $media = [
            'o:ingester' => 'oembed',
            'o:source' => 'https://www.youtube.com/watch?v=abc',
            'data' => [
                'html' => '<iframe src="https://www.youtube.com/embed/abc?feature=oembed" frameborder="0"></iframe>',
            ],
        ];
        $this->assertSame(
            'https://www.youtube.com/embed/abc?feature=oembed',
            ingester_classifier::extract_linked_url($media)
        );
    }

    /**
     * extract_linked_url() decodes HTML entities in the iframe src.
     */
    public function test_extract_linked_url_decodes_entities(): void {
        $media = [
            'o:ingester' => 'oembed',
            'data' => [
                'html' => '<iframe src="https://x.test/embed?a=1&amp;b=2"></iframe>',
            ],
        ];
        $this->assertSame(
            'https://x.test/embed?a=1&b=2',
            ingester_classifier::extract_linked_url($media)
        );
    }

    /**
     * extract_linked_url() falls back to o:source when no iframe is found.
     */
    public function test_extract_linked_url_falls_back_to_source(): void {
        $media = [
            'o:ingester' => 'iiif_presentation',
            'o:source' => 'https://iiif.example.test/manifest.json',
        ];
        $this->assertSame(
            'https://iiif.example.test/manifest.json',
            ingester_classifier::extract_linked_url($media)
        );
    }

    /**
     * mark_linked()/strip_marker() round-trip preserves the URL, is
     * idempotent, treats unmarked input as a no-op and short-circuits on
     * the empty string.
     */
    public function test_mark_and_strip_marker_roundtrip(): void {
        $url = 'https://www.youtube.com/embed/abc';
        $marked = ingester_classifier::mark_linked($url);
        $this->assertTrue(ingester_classifier::is_linked($marked));
        $this->assertSame($url, ingester_classifier::strip_marker($marked));
        // Idempotent.
        $this->assertSame($marked, ingester_classifier::mark_linked($marked));
        // Strip on a non-marked source is a no-op.
        $this->assertSame($url, ingester_classifier::strip_marker($url));
        // Empty input stays empty (no marker added).
        $this->assertSame('', ingester_classifier::mark_linked(''));
    }
}
