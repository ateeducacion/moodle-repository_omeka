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
 * PHPUnit tests for the Omeka-S API client.
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
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/fixtures/fake_curl.php');

/**
 * Tests for {@see api_client}.
 */
final class api_client_test extends \advanced_testcase {
    /**
     * Reset Moodle state between tests.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
    }

    /**
     * Build a curl stub returning a JSON payload.
     *
     * @param mixed $payload Payload to encode.
     * @param int $httpcode HTTP status code.
     * @param array $headers Response headers.
     * @return fake_curl
     */
    private function make_curl($payload, int $httpcode = 200, array $headers = []): fake_curl {
        $curl = new fake_curl();
        $curl->body = is_string($payload) ? $payload : json_encode($payload);
        $curl->httpcode = $httpcode;
        $curl->response = $headers;
        return $curl;
    }

    /**
     * get_items() composes the URL using page, per_page and filters.
     */
    public function test_get_items_builds_correct_url(): void {
        $curl = $this->make_curl([['o:id' => 1]]);
        $client = new api_client('https://example.test/', null, null, 5, $curl);

        $client->get_items(2, 50, ['item_set_id' => 7, 'fulltext_search' => 'ada']);

        $this->assertStringStartsWith('https://example.test/api/items?', $curl->lasturl);
        parse_str(parse_url($curl->lasturl, PHP_URL_QUERY), $query);
        $this->assertSame('2', $query['page']);
        $this->assertSame('50', $query['per_page']);
        $this->assertSame('7', $query['item_set_id']);
        $this->assertSame('ada', $query['fulltext_search']);
    }

    /**
     * resource_class_term[] and resource_class_id[] array filters survive
     * http_build_query encoding and round-trip back to PHP-style arrays. This
     * is the wire format the listing_builder uses to push the configured
     * `acceptedclasses` filter into every items request server-side.
     */
    public function test_get_items_forwards_resource_class_array_filters(): void {
        $curl = $this->make_curl([]);
        $client = new api_client('https://example.test/', null, null, 5, $curl);

        $client->get_items(1, 20, [
            'resource_class_term' => ['lrmi:LearningResource', 'dctype:Image'],
            'resource_class_id' => [7, 8],
        ]);

        parse_str(parse_url($curl->lasturl, PHP_URL_QUERY), $query);
        $this->assertSame(['lrmi:LearningResource', 'dctype:Image'], $query['resource_class_term']);
        $this->assertSame(['7', '8'], $query['resource_class_id']);
    }

    /**
     * Omeka-S-Total-Results header is parsed when it lives in keyed headers.
     */
    public function test_total_header_keyed(): void {
        $curl = $this->make_curl([], 200, ['Omeka-S-Total-Results' => '42']);
        $client = new api_client('https://example.test', null, null, 5, $curl);

        $response = $client->get_items(1, 10);
        $this->assertSame(42, $response['total']);
    }

    /**
     * Omeka-S-Total-Results header is parsed when supplied as a raw header line.
     */
    public function test_total_header_raw_line(): void {
        $curl = $this->make_curl([], 200, ['HTTP/1.1 200 OK', 'Omeka-S-Total-Results: 18']);
        $client = new api_client('https://example.test', null, null, 5, $curl);

        $response = $client->get_items(1, 10);
        $this->assertSame(18, $response['total']);
    }

    /**
     * http_code >= 400 raises a moodle_exception.
     */
    public function test_http_error_throws(): void {
        $curl = $this->make_curl(['error' => 'nope'], 401);
        $client = new api_client('https://example.test', null, null, 5, $curl);
        $this->expectException(\moodle_exception::class);
        $client->get('/api/items');
    }

    /**
     * Invalid JSON raises a moodle_exception.
     */
    public function test_invalid_json_throws(): void {
        $curl = $this->make_curl('not json', 200);
        $client = new api_client('https://example.test', null, null, 5, $curl);
        $this->expectException(\moodle_exception::class);
        $client->get('/api/items');
    }

    /**
     * Transport errors raise a moodle_exception.
     */
    public function test_transport_error_throws(): void {
        $curl = new fake_curl();
        $curl->errno = 28;
        $curl->error = 'timeout';
        $client = new api_client('https://example.test', null, null, 5, $curl);
        $this->expectException(\moodle_exception::class);
        $client->get('/api/items');
    }

    /**
     * Auth keys are forwarded as query parameters.
     */
    public function test_auth_keys_appended(): void {
        $curl = $this->make_curl([], 200, []);
        $client = new api_client('https://example.test', 'me', 'secret', 5, $curl);

        $client->get('/api/items');

        parse_str(parse_url($curl->lasturl, PHP_URL_QUERY), $query);
        $this->assertSame('me', $query['key_identity']);
        $this->assertSame('secret', $query['key_credential']);
    }

    /**
     * Convenience helpers route through the expected endpoints.
     */
    public function test_endpoint_helpers(): void {
        $curl = $this->make_curl([]);
        $client = new api_client('https://example.test', null, null, 5, $curl);

        $client->get_item(99);
        $this->assertStringStartsWith('https://example.test/api/items/99', $curl->lasturl);

        $client->get_item_set(12);
        $this->assertStringStartsWith('https://example.test/api/item_sets/12', $curl->lasturl);

        $client->get_media(33);
        $this->assertStringStartsWith('https://example.test/api/media/33', $curl->lasturl);

        $client->get_media_for_item(42);
        $this->assertStringStartsWith('https://example.test/api/media?', $curl->lasturl);
        parse_str(parse_url($curl->lasturl, PHP_URL_QUERY), $query);
        $this->assertSame('42', $query['item_id']);
    }
}
