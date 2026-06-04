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
     * get_filetype_filter() intersects the admin's `acceptedtypes` option
     * with the per-pick `mimetypes` array forwarded by the file picker, so
     * forms that request a narrower set than the instance allows actually
     * see only the intersection.
     */
    public function test_get_filetype_filter_intersects_picker_mimetypes(): void {
        // Throwaway subclass stubs the base repository plumbing so we can
        // exercise the private get_filetype_filter() in isolation, without
        // booting an instance against the DB. Mirrors how the other smoke
        // tests reach private methods via reflection.
        $stub = new class extends \repository_omeka {
            // phpcs:ignore moodle.Commenting.MissingDocblock
            public function __construct() {
                $this->options = [
                    'acceptedtypes' => 'image, document',
                    'mimetypes' => ['web_image'],
                ];
            }
            // phpcs:ignore moodle.Commenting.MissingDocblock
            public function get_option($name = '') {
                return $this->options[$name] ?? null;
            }
        };
        $method = (new \ReflectionClass(\repository_omeka::class))->getMethod('get_filetype_filter');
        $method->setAccessible(true);
        $combined = $method->invoke($stub);

        // PDF is allowed by the base `document` group but rejected by `web_image`.
        $this->assertFalse($combined->is_allowed('a.pdf', 'application/pdf'));
        // JPEG passes both gates.
        $this->assertTrue($combined->is_allowed('a.jpg', 'image/jpeg'));
        // TIFF is in `image` but not in `web_image`, so the intersection rejects it.
        $this->assertFalse($combined->is_allowed('a.tif', 'image/tiff'));
    }

    /**
     * The repository must advertise both FILE_INTERNAL (binary copy) and
     * FILE_EXTERNAL (URL hot-link) so the picker can pick the right mode for
     * each entry — binary media defaults to internal, linked media (oEmbed,
     * IIIF, url, html) defaults to the external link.
     */
    public function test_supported_returntypes_includes_both(): void {
        $reflection = new \ReflectionClass(\repository_omeka::class);
        $method = $reflection->getMethod('supported_returntypes');
        $instance = $reflection->newInstanceWithoutConstructor();
        $returntypes = (int)$method->invoke($instance);
        $this->assertSame(FILE_INTERNAL, $returntypes & FILE_INTERNAL);
        $this->assertSame(FILE_EXTERNAL, $returntypes & FILE_EXTERNAL);
        // FILE_REFERENCE intentionally not advertised (see lib.php comment).
        $this->assertSame(0, $returntypes & FILE_REFERENCE);
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

    /**
     * Outside Moodle Playground the curl options keep Moodle's SSRF blocklist
     * active (no `ignoresecurity`) and enforce TLS chain verification, so the
     * plugin's outbound requests are protected by default.
     */
    public function test_curl_security_options_strict_outside_playground(): void {
        $opts = \repository_omeka::curl_security_options();
        $this->assertSame([], $opts['construct']);
        // The SSRF blocklist bypass is never requested in a normal install.
        $this->assertArrayNotHasKey('ignoresecurity', $opts['construct']);
        // TLS chain verification is enforced (Moodle's curl wrapper otherwise
        // defaults peer verification off).
        $this->assertSame(1, $opts['ssl']['CURLOPT_SSL_VERIFYPEER']);
        $this->assertSame(2, $opts['ssl']['CURLOPT_SSL_VERIFYHOST']);
    }

    /**
     * get_file() refuses to download a non-http(s) source (e.g. file://) before
     * it ever reaches curl, closing the local-file-disclosure / SSRF vector for
     * a malicious Omeka o:original_url.
     */
    public function test_get_file_rejects_non_http_source(): void {
        $reflection = new \ReflectionClass(\repository_omeka::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $this->expectException(\moodle_exception::class);
        $instance->get_file('file:///etc/passwd');
    }

    /**
     * get_link() neutralises a javascript:/data: URL coming from untrusted
     * Omeka content (stored-XSS guard) while passing http(s) links through.
     */
    public function test_get_link_rejects_dangerous_schemes(): void {
        $reflection = new \ReflectionClass(\repository_omeka::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $this->assertSame('', $instance->get_link(
            ingester_classifier::mark_linked('javascript:alert(document.cookie)')));
        $this->assertSame('', $instance->get_link('data:text/html;base64,PHNjcmlwdD4='));
        // A legitimate external link still round-trips unchanged.
        $this->assertSame(
            'https://www.youtube.com/embed/abc',
            $instance->get_link(ingester_classifier::mark_linked('https://www.youtube.com/embed/abc'))
        );
    }
}
