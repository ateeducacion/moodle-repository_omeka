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
 * Tests for the Moodle Playground CORS proxy URL wrapper.
 *
 * @package    repository_omeka
 * @category   test
 * @copyright  2026 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/repository/omeka/lib.php');

/**
 * Verifies that the URL wrapper short-circuits outside the Moodle Playground
 * and routes through the configured proxy when the gate is active, falling
 * back to the hardcoded constant when the playground does not export the
 * proxy URL.
 *
 * Tests target the injectable {@see repository_omeka::wrap_url_with_proxy()}
 * impl so we don't need runInSeparateProcess + define() (which would re-boot
 * Moodle and surface unrelated PHP 8.5 core deprecations as test errors).
 * One test covers the public {@see repository_omeka::wrap_cors_proxy()}
 * entry point against the test environment's default state (no playground
 * constants defined).
 */
final class repository_omeka_cors_proxy_test extends advanced_testcase {
    /**
     * Reset Moodle state after each test.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
    }

    /**
     * In the standard test environment the playground constants are not
     * defined, so the public entry point must return the URL unchanged.
     */
    public function test_public_wrapper_is_inert_outside_playground(): void {
        $url = 'https://example.org/api/items?page=1&per_page=10';
        $this->assertSame($url, repository_omeka::wrap_cors_proxy($url));
    }

    /**
     * With the gate inactive the impl returns the URL unchanged regardless
     * of the proxy URL value.
     */
    public function test_impl_returns_url_unchanged_when_gate_inactive(): void {
        $url = 'https://example.org/api/items?page=1&per_page=10';
        $this->assertSame(
            $url,
            repository_omeka::wrap_url_with_proxy($url, false, 'https://proxy.example/?url=')
        );
    }

    /**
     * Empty URL is returned unchanged even when the gate is active.
     */
    public function test_impl_returns_empty_string_unchanged_when_gate_active(): void {
        $this->assertSame(
            '',
            repository_omeka::wrap_url_with_proxy('', true, 'https://proxy.example/?url=')
        );
    }

    /**
     * With the gate active and a non-empty proxy URL the wrapper concatenates
     * the proxy with rawurlencode-of-URL.
     */
    public function test_impl_uses_provided_proxy_url(): void {
        $url = 'https://example.org/api/items?page=1&per_page=10';
        $expected = 'https://proxy.example/?url=' . rawurlencode($url);
        $this->assertSame(
            $expected,
            repository_omeka::wrap_url_with_proxy($url, true, 'https://proxy.example/?url=')
        );
    }

    /**
     * With the gate active but an empty proxy URL the wrapper falls back to
     * the hardcoded PLAYGROUND_CORS_PROXY_FALLBACK constant — covers older
     * playground builds that don't export MOODLE_PLAYGROUND_PROXY_URL.
     */
    public function test_impl_falls_back_to_hardcoded_constant_when_proxy_url_empty(): void {
        $url = 'https://example.org/api/items?page=1';
        $expected = repository_omeka::PLAYGROUND_CORS_PROXY_FALLBACK . rawurlencode($url);
        $this->assertSame(
            $expected,
            repository_omeka::wrap_url_with_proxy($url, true, '')
        );
    }
}
