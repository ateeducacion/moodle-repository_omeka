<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Builds the listing arrays consumed by Moodle's file picker.
 *
 * @package   repository_omeka
 * @copyright 2026 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_omeka\local;


/**
 * Glue between the API client and the data structures Moodle expects.
 */
class listing_builder {
    /** @var int Page size used when paginating Omeka-S responses. */
    const PER_PAGE = 20;

    /** @var api_client */
    private $client;

    /** @var string Repository base URL used to build the "manage" link. */
    private $manageurl;

    /** @var string Omeka-S site slug for the configured site, when known. */
    private $siteslug;

    /** @var string Optional URL for the toolbar "help" button. */
    private $helpurl;

    /** @var string[] Resource-class terms / ids configured at instance level. */
    private $resourceclasses;

    /**
     * Constructor.
     *
     * @param api_client $client Omeka-S API client.
     * @param string $manageurl Repository base URL (used to build the
     *     contextual "manage" link).
     * @param string $resourceclasses Comma/space separated list of Omeka-S
     *     resource-class terms (`lrmi:LearningResource`) or numeric ids.
     *     Forwarded to every `/api/items` request as `resource_class_term[]=…`
     *     (or `resource_class_id[]=…` for purely numeric entries) so the
     *     filtering happens server-side. Empty string means "no filter",
     *     matching the previous behaviour.
     * @param string $siteslug Omeka-S site slug for the configured site.
     *     When non-empty, the "manage" link targets the public Omeka-S site
     *     pages (`/s/<slug>/…`) the user is browsing; when empty, it falls
     *     back to the admin item index.
     * @param string $helpurl Optional URL used for the file picker toolbar
     *     "help" button. Empty string means "do not render the button".
     */
    public function __construct(
        api_client $client,
        string $manageurl = '',
        string $resourceclasses = '',
        string $siteslug = '',
        string $helpurl = ''
    ) {
        $this->client = $client;
        $this->manageurl = rtrim($manageurl, '/');
        $this->resourceclasses = self::parse_resource_classes($resourceclasses);
        $this->siteslug = trim($siteslug);
        $this->helpurl = trim($helpurl);
    }

    /**
     * Build the root listing of item sets.
     *
     * @param int $page Page (0-based).
     * @param int|null $siteid Optional site id.
     * @return array Listing array.
     */
    public function list_item_sets(int $page, ?int $siteid = null): array {
        $response = $this->client->get_item_sets($page + 1, self::PER_PAGE, $siteid ?: null);
        $list = [];
        foreach ($response['body'] ?? [] as $set) {
            $id = isset($set['o:id']) ? (int)$set['o:id'] : 0;
            if (!$id) {
                continue;
            }
            $title = $set['o:title'] ?? ('Item set ' . $id);
            $list[] = [
                'title' => $title,
                'path' => base64_encode('set:' . $id),
                'children' => [],
                'thumbnail' => '',
                'thumbnail_height' => 90,
                'thumbnail_width' => 90,
            ];
        }
        return $this->wrap($list, $page, $response['total'] ?? null, []);
    }

    /**
     * Build a listing of items inside an item set.
     *
     * @param int $itemsetid Item set id.
     * @param int $page Page (0-based).
     * @param int|null $siteid Optional site id.
     * @param filetype_filter $filter Filetype filter.
     * @return array Listing array.
     */
    public function list_items_in_set(
        int $itemsetid,
        int $page,
        ?int $siteid,
        filetype_filter $filter
    ): array {
        $filters = ['item_set_id' => $itemsetid];
        if (!empty($siteid)) {
            $filters['site_id'] = $siteid;
        }
        $filters = $this->apply_class_filter($filters);
        $response = $this->client->get_items($page + 1, self::PER_PAGE, $filters);
        $list = $this->build_item_entries($response['body'] ?? [], $filter);

        $set = $this->safe_get(function () use ($itemsetid) {
            return $this->client->get_item_set($itemsetid);
        });
        $settitle = $set['body']['o:title'] ?? ('Item set ' . $itemsetid);
        $pathinfo = [
            ['name' => get_string('pluginname', 'repository_omeka'), 'path' => ''],
            ['name' => $settitle, 'path' => base64_encode('set:' . $itemsetid)],
        ];

        return $this->wrap($list, $page, $response['total'] ?? null, $pathinfo, $itemsetid);
    }

    /**
     * Build a listing of media of a specific item.
     *
     * @param int $itemid Item id.
     * @param int $page Page (0-based).
     * @param filetype_filter $filter Filetype filter.
     * @return array Listing array.
     */
    public function list_media_in_item(int $itemid, int $page, filetype_filter $filter): array {
        $itemresponse = $this->safe_get(function () use ($itemid) {
            return $this->client->get_item($itemid);
        });
        $item = $itemresponse['body'] ?? [];
        $itemtitle = $item['o:title'] ?? ('Item ' . $itemid);
        $itemsetid = isset($item['o:item_set'][0]['o:id']) ? (int)$item['o:item_set'][0]['o:id'] : null;

        $mediaresp = $this->client->get('/api/media', [
            'item_id' => $itemid,
            'page' => $page + 1,
            'per_page' => self::PER_PAGE,
        ]);

        $list = [];
        foreach ($mediaresp['body'] ?? [] as $media) {
            $entry = entry_factory::build_media_entry($item, $media, $filter);
            if ($entry !== null) {
                $list[] = $entry;
            }
        }

        $pathinfo = [['name' => get_string('pluginname', 'repository_omeka'), 'path' => '']];
        if ($itemsetid) {
            $set = $this->safe_get(function () use ($itemsetid) {
                return $this->client->get_item_set($itemsetid);
            });
            $settitle = $set['body']['o:title'] ?? ('Item set ' . $itemsetid);
            $pathinfo[] = ['name' => $settitle, 'path' => base64_encode('set:' . $itemsetid)];
        }
        $pathinfo[] = ['name' => $itemtitle, 'path' => base64_encode('item:' . $itemid)];

        return $this->wrap($list, $page, $mediaresp['total'] ?? null, $pathinfo, $itemsetid, $itemid);
    }

    /**
     * Build a listing for a search request.
     *
     * @param string $text Search text.
     * @param int $page Page (0-based).
     * @param int|null $siteid Optional site id.
     * @param filetype_filter $filter Filetype filter.
     * @param int|null $itemsetid Optional item set filter.
     * @return array Listing array.
     */
    public function search(
        string $text,
        int $page,
        ?int $siteid,
        filetype_filter $filter,
        ?int $itemsetid = null
    ): array {
        $filters = self::search_filters($text, $siteid, $itemsetid);
        $filters = $this->apply_class_filter($filters);
        $response = $this->client->get_items($page + 1, self::PER_PAGE, $filters);
        $list = $this->build_item_entries($response['body'] ?? [], $filter);
        $pathinfo = [];
        if ($itemsetid) {
            $set = $this->safe_get(function () use ($itemsetid) {
                return $this->client->get_item_set($itemsetid);
            });
            $settitle = $set['body']['o:title'] ?? ('Item set ' . $itemsetid);
            $pathinfo = [
                ['name' => get_string('pluginname', 'repository_omeka'), 'path' => ''],
                ['name' => $settitle, 'path' => base64_encode((string)$itemsetid)],
            ];
        }
        return $this->wrap($list, $page, $response['total'] ?? null, $pathinfo, $itemsetid);
    }

    /**
     * Build filters for the Omeka-S items endpoint from a search expression.
     *
     * @param string $text Search expression.
     * @param int|null $siteid Optional site id.
     * @param int|null $itemsetid Optional item set id.
     * @return array
     */
    public static function search_filters(string $text, ?int $siteid, ?int $itemsetid): array {
        $filters = [];
        $text = trim($text);
        if ($text !== '') {
            if (preg_match('/^([a-z0-9:_-]+)\s*:\s*(.+)$/i', $text, $matches)) {
                $filters['property'][] = [
                    'property' => $matches[1],
                    'type' => 'in',
                    'text' => trim($matches[2]),
                ];
            } else {
                $filters['fulltext_search'] = $text;
            }
        }
        if (!empty($siteid)) {
            $filters['site_id'] = $siteid;
        }
        if (!empty($itemsetid)) {
            $filters['item_set_id'] = $itemsetid;
        }
        return $filters;
    }

    /**
     * Build entries for a list of items.
     *
     * @param array $items Items as returned by the API.
     * @param filetype_filter $filter Filetype filter.
     * @return array
     */
    private function build_item_entries(array $items, filetype_filter $filter): array {
        $orderedids = [];
        $itembyid = [];
        foreach ($items as $item) {
            $mediaid = $item['o:primary_media']['o:id'] ?? ($item['o:media'][0]['o:id'] ?? null);
            if (!$mediaid) {
                continue;
            }
            $mid = (int)$mediaid;
            if (!isset($itembyid[$mid])) {
                $orderedids[] = $mid;
                $itembyid[$mid] = $item;
            }
        }
        if ($orderedids === []) {
            return [];
        }
        $response = $this->safe_get(function () use ($orderedids) {
            return $this->client->get_media_bulk($orderedids);
        });
        $mediabyid = [];
        foreach ($response['body'] ?? [] as $media) {
            $mid = isset($media['o:id']) ? (int)$media['o:id'] : 0;
            if ($mid !== 0) {
                $mediabyid[$mid] = $media;
            }
        }
        $list = [];
        foreach ($orderedids as $mid) {
            if (!isset($mediabyid[$mid])) {
                continue;
            }
            $entry = entry_factory::build_media_entry($itembyid[$mid], $mediabyid[$mid], $filter);
            if ($entry !== null) {
                $list[] = $entry;
            }
        }
        return $list;
    }

    /**
     * Wrap a list of entries with the metadata Moodle expects.
     *
     * The `manage` field is built per-context so the button on the file
     * picker toolbar links to the Omeka-S page the user is currently
     * browsing instead of the bare site root. `help` and `message` are
     * only present when there's something useful to render, so Moodle
     * hides the toolbar slots instead of painting empty controls.
     *
     * @param array $list Entries.
     * @param int $page Page (0-based).
     * @param int|null $total Total results, when known.
     * @param array $pathinfo Breadcrumb path.
     * @param int|null $itemsetid Current item-set context (null at root).
     * @param int|null $itemid Current item context (null outside an item).
     * @return array
     */
    private function wrap(
        array $list,
        int $page,
        ?int $total,
        array $pathinfo,
        ?int $itemsetid = null,
        ?int $itemid = null
    ): array {
        $pages = $page;
        if ($total !== null && (($page + 1) * self::PER_PAGE) < $total) {
            $pages = -1;
        }
        $result = [
            'dynload' => true,
            'nologin' => true,
            'page' => $page,
            'norefresh' => false,
            'nosearch' => false,
            'manage' => $this->build_manage_url($itemsetid, $itemid),
            'list' => $list,
            'path' => $pathinfo,
            'pages' => $pages,
        ];
        if ($this->helpurl !== '') {
            $result['help'] = $this->helpurl;
        }
        if (!empty($this->resourceclasses)) {
            $result['message'] = get_string(
                'filteringbyclass',
                'repository_omeka',
                implode(', ', $this->resourceclasses),
            );
        }
        return $result;
    }

    /**
     * Build the URL pointed at by the file picker toolbar "manage" button.
     *
     * The Omeka-S admin login is rarely what the picker user wants — they
     * are usually a course editor browsing the public site. When a site
     * slug is configured we link to the matching public page (item, item
     * set, or site root); otherwise we fall back to the admin item index
     * because that is still more useful than the raw `baseurl`.
     *
     * @param int|null $itemsetid Optional item-set id for the current view.
     * @param int|null $itemid Optional item id for the current view.
     * @return string
     */
    private function build_manage_url(?int $itemsetid = null, ?int $itemid = null): string {
        if ($this->siteslug === '') {
            return $this->manageurl . '/admin/item';
        }
        $base = $this->manageurl . '/s/' . $this->siteslug;
        if ($itemid) {
            return $base . '/item/' . $itemid;
        }
        if ($itemsetid) {
            return $base . '/item-set/' . $itemsetid;
        }
        return $base . '/';
    }

    /**
     * Execute an API call, swallowing moodle_exception to keep the listing alive.
     *
     * @param callable $fn Callable returning the API response.
     * @return array
     */
    private function safe_get(callable $fn): array {
        try {
            return $fn();
        } catch (\moodle_exception $e) {
            return ['body' => [], 'total' => null, 'http_code' => 0];
        }
    }

    /**
     * Merge the instance-configured `acceptedclasses` filter into an items query.
     *
     * Numeric tokens become `resource_class_id[]` (Omeka-S internal id);
     * everything else (typically vocab-prefixed terms like
     * `lrmi:LearningResource`) becomes `resource_class_term[]`. Both forms
     * accept array values, so a single request handles N classes without
     * client-side merging or extra round-trips.
     *
     * @param array $filters Filters destined for {@see api_client::get_items}.
     * @return array
     */
    private function apply_class_filter(array $filters): array {
        if (empty($this->resourceclasses)) {
            return $filters;
        }
        $ids = [];
        $terms = [];
        foreach ($this->resourceclasses as $token) {
            if (ctype_digit($token)) {
                $ids[] = (int)$token;
            } else {
                $terms[] = $token;
            }
        }
        if (!empty($ids)) {
            $filters['resource_class_id'] = array_values(array_unique($ids));
        }
        if (!empty($terms)) {
            $filters['resource_class_term'] = array_values(array_unique($terms));
        }
        return $filters;
    }

    /**
     * Normalise the raw `acceptedclasses` instance option into a token list.
     *
     * @param string $text Raw option value.
     * @return string[] Lowercase token list (with case preserved for the
     *     colon-prefixed local part of vocab terms — Omeka-S accepts both
     *     `dctype:Image` and `dctype:image`, but we keep the user's casing
     *     so the API matches with case-sensitive vocabulary backends).
     */
    private static function parse_resource_classes(string $text): array {
        $clean = trim($text);
        if ($clean === '') {
            return [];
        }
        $clean = str_replace([';', "\n", "\r"], ',', $clean);
        $parts = preg_split('/[\s,]+/', $clean, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $tokens[] = $part;
            }
        }
        return array_values(array_unique($tokens));
    }
}
