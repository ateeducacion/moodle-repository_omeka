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
 * PHPUnit tests for the listing builder.
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
 * Tests for {@see listing_builder}.
 */
final class listing_builder_test extends \advanced_testcase {
    /**
     * Build a mock api_client that returns canned responses keyed by path.
     *
     * @param array<string,array> $byendpoint Map of endpoint key to response.
     * @return api_client
     */
    private function make_client(array $byendpoint): api_client {
        $mock = $this->getMockBuilder(api_client::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'get_item_sets', 'get_items', 'get_item', 'get_item_set',
                'get_media', 'get_media_bulk', 'get_media_for_item',
                'get_sites', 'get_site', 'get',
            ])
            ->getMock();

        $mock->method('get_item_sets')->willReturnCallback(function () use ($byendpoint) {
            return $byendpoint['item_sets'] ?? ['body' => [], 'total' => 0, 'http_code' => 200];
        });
        $mock->method('get_items')->willReturnCallback(function () use ($byendpoint) {
            return $byendpoint['items'] ?? ['body' => [], 'total' => 0, 'http_code' => 200];
        });
        $mock->method('get_item')->willReturnCallback(function () use ($byendpoint) {
            return $byendpoint['item'] ?? ['body' => [], 'total' => null, 'http_code' => 200];
        });
        $mock->method('get_item_set')->willReturnCallback(function () use ($byendpoint) {
            return $byendpoint['item_set'] ?? ['body' => [], 'total' => null, 'http_code' => 200];
        });
        $mock->method('get_media')->willReturnCallback(function () use ($byendpoint) {
            return $byendpoint['media'] ?? ['body' => [], 'total' => null, 'http_code' => 200];
        });
        $mock->method('get_media_bulk')->willReturnCallback(function () use ($byendpoint) {
            return $byendpoint['media_bulk'] ?? ['body' => [], 'total' => null, 'http_code' => 200];
        });
        $mock->method('get_media_for_item')->willReturnCallback(function () use ($byendpoint) {
            return $byendpoint['media_for_item'] ?? ['body' => [], 'total' => null, 'http_code' => 200];
        });
        $mock->method('get')->willReturnCallback(function (string $path) use ($byendpoint) {
            if (strpos($path, '/api/media') === 0) {
                return $byendpoint['media_for_item'] ?? $byendpoint['media'] ?? ['body' => [], 'total' => null, 'http_code' => 200];
            }
            return ['body' => [], 'total' => null, 'http_code' => 200];
        });
        return $mock;
    }

    /**
     * list_item_sets() turns item sets into folder entries.
     */
    public function test_list_item_sets_returns_folders(): void {
        $client = $this->make_client([
            'item_sets' => [
                'body' => [
                    ['o:id' => 1, 'o:title' => 'First'],
                    ['o:id' => 2, 'o:title' => 'Second'],
                ],
                'total' => 2,
                'http_code' => 200,
            ],
        ]);
        $builder = new listing_builder($client, 'https://example.test');

        $result = $builder->list_item_sets(0, null);

        $this->assertCount(2, $result['list']);
        $this->assertSame('First', $result['list'][0]['title']);
        $this->assertSame(base64_encode('set:1'), $result['list'][0]['path']);
        // No site slug configured → manage falls back to the admin item index.
        $this->assertSame('https://example.test/admin/item', $result['manage']);
        $this->assertTrue($result['dynload']);
        // No helpurl / acceptedclasses configured → keys are omitted so the
        // toolbar does not paint empty controls.
        $this->assertArrayNotHasKey('help', $result);
        $this->assertArrayNotHasKey('message', $result);
    }

    /**
     * Pagination reports -1 when more pages exist, otherwise the current page.
     */
    public function test_pagination(): void {
        $client = $this->make_client([
            'item_sets' => ['body' => [['o:id' => 1, 'o:title' => 'X']], 'total' => 100, 'http_code' => 200],
        ]);
        $builder = new listing_builder($client, '');
        $result = $builder->list_item_sets(0, null);
        $this->assertSame(-1, $result['pages']);

        $client2 = $this->make_client([
            'item_sets' => ['body' => [['o:id' => 1, 'o:title' => 'X']], 'total' => 1, 'http_code' => 200],
        ]);
        $builder2 = new listing_builder($client2, '');
        $result2 = $builder2->list_item_sets(0, null);
        $this->assertSame(0, $result2['pages']);
    }

    /**
     * search_filters() maps property-style queries to Omeka-S property filters.
     */
    public function test_search_filters(): void {
        $filters = listing_builder::search_filters('dcterms:title:Ada', null, null);
        $this->assertSame('dcterms:title', $filters['property'][0]['property']);
        $this->assertSame('Ada', $filters['property'][0]['text']);

        $filters = listing_builder::search_filters('plain text', 7, 12);
        $this->assertSame('plain text', $filters['fulltext_search']);
        $this->assertSame(7, $filters['site_id']);
        $this->assertSame(12, $filters['item_set_id']);
    }

    /**
     * Items without media are dropped from the listing.
     */
    public function test_search_drops_items_without_media(): void {
        $client = $this->make_client([
            'items' => [
                'body' => [
                    ['o:id' => 1, 'o:title' => 'No media'],
                    ['o:id' => 2, 'o:title' => 'Has media', 'o:primary_media' => ['o:id' => 99], 'o:media' => [['o:id' => 99]]],
                ],
                'total' => 2,
                'http_code' => 200,
            ],
            'media_bulk' => [
                'body' => [
                    [
                        'o:id' => 99,
                        'o:original_url' => 'https://example.test/a.png',
                        'o:media_type' => 'image/png',
                        'o:size' => 100,
                    ],
                ],
                'total' => 1,
                'http_code' => 200,
            ],
        ]);

        $builder = new listing_builder($client, '');
        $result = $builder->search('', 0, null, new filetype_filter(''));
        $this->assertCount(1, $result['list']);
        $this->assertSame('Has media', $result['list'][0]['title']);
        $this->assertSame('https://example.test/a.png', $result['list'][0]['source']);
    }

    /**
     * The filetype filter rejects items whose media is the wrong type.
     */
    public function test_filter_excludes_unwanted_types(): void {
        $client = $this->make_client([
            'items' => [
                'body' => [
                    ['o:id' => 1, 'o:title' => 'A pdf', 'o:primary_media' => ['o:id' => 10], 'o:media' => [['o:id' => 10]]],
                    ['o:id' => 2, 'o:title' => 'A zip', 'o:primary_media' => ['o:id' => 11], 'o:media' => [['o:id' => 11]]],
                ],
                'total' => 2,
                'http_code' => 200,
            ],
            'media_bulk' => [
                'body' => [
                    ['o:id' => 10, 'o:original_url' => 'https://x/a.pdf', 'o:media_type' => 'application/pdf', 'o:size' => 1],
                    ['o:id' => 11, 'o:original_url' => 'https://x/a.zip', 'o:media_type' => 'application/zip', 'o:size' => 1],
                ],
                'total' => 2,
                'http_code' => 200,
            ],
        ]);
        $builder = new listing_builder($client, '');
        $result = $builder->search('', 0, null, new filetype_filter('pdf'));
        $this->assertCount(1, $result['list']);
        $this->assertSame('A pdf', $result['list'][0]['title']);
    }

    /**
     * build_item_entries() must issue a single bulk media call, regardless of the items count.
     */
    public function test_search_makes_single_bulk_media_call(): void {
        $client = $this->getMockBuilder(api_client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_items', 'get_media', 'get_media_bulk', 'get_item_set', 'get'])
            ->getMock();
        $client->method('get_items')->willReturn([
            'body' => [
                ['o:id' => 1, 'o:title' => 'a', 'o:primary_media' => ['o:id' => 10]],
                ['o:id' => 2, 'o:title' => 'b', 'o:primary_media' => ['o:id' => 11]],
                ['o:id' => 3, 'o:title' => 'c', 'o:primary_media' => ['o:id' => 12]],
            ],
            'total' => 3,
            'http_code' => 200,
        ]);
        $client->expects($this->never())->method('get_media');
        $client->expects($this->once())
            ->method('get_media_bulk')
            ->with($this->equalTo([10, 11, 12]))
            ->willReturn([
                'body' => [
                    ['o:id' => 10, 'o:original_url' => 'https://x/a.png', 'o:media_type' => 'image/png', 'o:size' => 1],
                    ['o:id' => 11, 'o:original_url' => 'https://x/b.png', 'o:media_type' => 'image/png', 'o:size' => 1],
                    ['o:id' => 12, 'o:original_url' => 'https://x/c.png', 'o:media_type' => 'image/png', 'o:size' => 1],
                ],
                'total' => 3,
                'http_code' => 200,
            ]);

        $builder = new listing_builder($client, '');
        $result = $builder->search('', 0, null, new filetype_filter(''));
        $this->assertCount(3, $result['list']);
        $this->assertSame(['a', 'b', 'c'], array_column($result['list'], 'title'));
    }

    /**
     * When the instance is configured with an `acceptedclasses` list, every
     * items query gets the configured terms forwarded server-side as
     * `resource_class_term[]` (and numeric tokens as `resource_class_id[]`)
     * so the Omeka-S backend can apply the filter without merging or extra
     * round-trips.
     */
    public function test_apply_class_filter_threads_through_items_queries(): void {
        $client = $this->make_client([]);
        $captured = [];
        $client->expects($this->atLeastOnce())
            ->method('get_items')
            ->willReturnCallback(function ($page, $per, $filters) use (&$captured) {
                $captured[] = $filters;
                return ['body' => [], 'total' => 0, 'http_code' => 200];
            });

        $builder = new listing_builder(
            $client,
            '',
            'lrmi:LearningResource, dctype:Image, 7',
        );

        $builder->list_items_in_set(42, 0, null, new filetype_filter(''));
        $builder->search('', 0, null, new filetype_filter(''));

        $this->assertNotEmpty($captured);
        foreach ($captured as $filters) {
            $this->assertSame(
                ['lrmi:LearningResource', 'dctype:Image'],
                $filters['resource_class_term'] ?? null,
            );
            $this->assertSame([7], $filters['resource_class_id'] ?? null);
        }
    }

    /**
     * Empty `acceptedclasses` leaves the filter array untouched — the legacy
     * behaviour before the option existed.
     */
    public function test_no_class_filter_when_option_empty(): void {
        $client = $this->make_client([]);
        $captured = null;
        $client->expects($this->once())
            ->method('get_items')
            ->willReturnCallback(function ($page, $per, $filters) use (&$captured) {
                $captured = $filters;
                return ['body' => [], 'total' => 0, 'http_code' => 200];
            });

        $builder = new listing_builder($client, '');
        $builder->list_items_in_set(42, 0, null, new filetype_filter(''));

        $this->assertIsArray($captured);
        $this->assertArrayNotHasKey('resource_class_term', $captured);
        $this->assertArrayNotHasKey('resource_class_id', $captured);
    }

    /**
     * At the root listing, when a site slug is configured, `manage` points
     * at the public Omeka-S site landing page (`/s/<slug>/`).
     */
    public function test_manage_url_at_root_uses_site_slug(): void {
        $client = $this->make_client([
            'item_sets' => ['body' => [['o:id' => 1, 'o:title' => 'X']], 'total' => 1, 'http_code' => 200],
        ]);
        $builder = new listing_builder($client, 'https://example.test', '', 'mediateca');

        $result = $builder->list_item_sets(0, null);

        $this->assertSame('https://example.test/s/mediateca/', $result['manage']);
    }

    /**
     * Inside an item set, `manage` targets the matching public item-set
     * page so the user lands on the same collection they're browsing.
     */
    public function test_manage_url_inside_item_set(): void {
        $client = $this->make_client([
            'items' => ['body' => [], 'total' => 0, 'http_code' => 200],
            'item_set' => ['body' => ['o:id' => 42, 'o:title' => 'My set'], 'total' => null, 'http_code' => 200],
        ]);
        $builder = new listing_builder($client, 'https://example.test', '', 'mediateca');

        $result = $builder->list_items_in_set(42, 0, null, new filetype_filter(''));

        $this->assertSame('https://example.test/s/mediateca/item-set/42', $result['manage']);
    }

    /**
     * Inside a single item, `manage` deep-links to the public item page.
     */
    public function test_manage_url_inside_item(): void {
        $client = $this->make_client([
            'item' => [
                'body' => ['o:id' => 7, 'o:title' => 'An item', 'o:item_set' => []],
                'total' => null,
                'http_code' => 200,
            ],
            'media_for_item' => ['body' => [], 'total' => 0, 'http_code' => 200],
        ]);
        $builder = new listing_builder($client, 'https://example.test', '', 'mediateca');

        $result = $builder->list_media_in_item(7, 0, new filetype_filter(''));

        $this->assertSame('https://example.test/s/mediateca/item/7', $result['manage']);
    }

    /**
     * Without a site slug the manage link cannot target a public site page,
     * so we fall back to the admin item index rather than the bare baseurl.
     */
    public function test_manage_url_without_site_falls_back_to_admin(): void {
        $client = $this->make_client([
            'item_sets' => ['body' => [], 'total' => 0, 'http_code' => 200],
        ]);
        $builder = new listing_builder($client, 'https://example.test');

        $result = $builder->list_item_sets(0, null);

        $this->assertSame('https://example.test/admin/item', $result['manage']);
    }

    /**
     * A configured help URL is forwarded verbatim so the file picker
     * toolbar can render its Help button; an empty value omits the key so
     * Moodle hides the button instead of painting an `<a href="">`.
     */
    public function test_help_url_passthrough(): void {
        $client = $this->make_client([
            'item_sets' => ['body' => [], 'total' => 0, 'http_code' => 200],
        ]);
        $withhelp = new listing_builder(
            $client,
            'https://example.test',
            '',
            '',
            'https://docs.example.test/omeka',
        );
        $withouthelp = new listing_builder($client, 'https://example.test');

        $this->assertSame(
            'https://docs.example.test/omeka',
            $withhelp->list_item_sets(0, null)['help'],
        );
        $this->assertArrayNotHasKey('help', $withouthelp->list_item_sets(0, null));
    }

    /**
     * When `acceptedclasses` is configured, the listing emits a `message`
     * naming the active filter so the user understands why some expected
     * items are missing. Without the filter the key is omitted entirely.
     */
    public function test_message_emitted_when_resource_classes_set(): void {
        $client = $this->make_client([
            'item_sets' => ['body' => [], 'total' => 0, 'http_code' => 200],
        ]);
        $filtered = new listing_builder(
            $client,
            'https://example.test',
            'lrmi:LearningResource, 7',
        );
        $unfiltered = new listing_builder($client, 'https://example.test');

        $result = $filtered->list_item_sets(0, null);
        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('lrmi:LearningResource', $result['message']);
        $this->assertStringContainsString('7', $result['message']);

        $this->assertArrayNotHasKey('message', $unfiltered->list_item_sets(0, null));
    }
}
