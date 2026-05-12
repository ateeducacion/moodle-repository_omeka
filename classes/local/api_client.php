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
 * Omeka-S API client.
 *
 * @package   repository_omeka
 * @copyright 2026 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_omeka\local;

/**
 * Thin HTTP client around the Omeka-S REST API.
 */
class api_client {
    /** @var string Base URL of the Omeka-S installation (no trailing slash). */
    private $baseurl;

    /** @var string|null Optional API key identity. */
    private $keyidentity;

    /** @var string|null Optional API key credential. */
    private $keycredential;

    /** @var int Timeout in seconds. */
    private $timeout;

    /** @var \curl|null Injected curl instance for testing. */
    private $curl;

    /**
     * Constructor.
     *
     * @param string $baseurl Omeka-S base URL.
     * @param string|null $keyidentity Optional API key identity.
     * @param string|null $keycredential Optional API key credential.
     * @param int $timeout Network timeout in seconds.
     * @param \curl|null $curl Optional curl instance for tests.
     */
    public function __construct(
        string $baseurl,
        ?string $keyidentity = null,
        ?string $keycredential = null,
        int $timeout = 10,
        ?\curl $curl = null
    ) {
        $this->baseurl = rtrim($baseurl, '/');
        $this->keyidentity = $keyidentity !== null && $keyidentity !== '' ? $keyidentity : null;
        $this->keycredential = $keycredential !== null && $keycredential !== '' ? $keycredential : null;
        $this->timeout = max(1, $timeout);
        $this->curl = $curl;
    }

    /**
     * Perform a GET request and decode the JSON body.
     *
     * @param string $path Path beginning with '/'.
     * @param array $params Query parameters.
     * @return array Keys: body (array), total (int|null), http_code (int).
     * @throws \moodle_exception When transport, HTTP, or JSON errors happen.
     */
    public function get(string $path, array $params = []): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if ($this->baseurl === '') {
            throw new \moodle_exception('repositoryomeka_apierror', 'repository_omeka', '', 'missing baseurl');
        }

        $params = $this->merge_auth_params($params);
        $url = $this->baseurl . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params, '', '&');
        }

        // The ignoresecurity flag bypasses Moodle's curl_security_helper which
        // calls gethostbynamel() to validate the host. That resolver is
        // unavailable in sandboxed PHP runtimes (e.g. Moodle Playground /
        // @php-wasm) and would otherwise reject every Omeka URL with "The URL
        // is blocked.". Safe here because the URL is always the
        // admin-configured Omeka baseurl.
        $curl = $this->curl ?: new \curl(['ignoresecurity' => true]);
        $curl->setHeader(['Accept: application/json']);
        $response = $curl->get($url, [], [
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_CONNECTTIMEOUT' => $this->timeout,
            'CURLOPT_FOLLOWLOCATION' => 1,
            'CURLOPT_MAXREDIRS' => 5,
        ]);

        $info = method_exists($curl, 'get_info') ? $curl->get_info() : [];
        $httpcode = (int)($info['http_code'] ?? 0);
        $errno = method_exists($curl, 'get_errno') ? (int)$curl->get_errno() : 0;

        if ($errno !== 0 || $response === false) {
            // Note: $curl->error is a property on Moodle's \curl wrapper, not a method.
            $err = isset($curl->error) && $curl->error !== '' ? (string)$curl->error : 'transport error';
            throw new \moodle_exception(
                'repositoryomeka_apierror',
                'repository_omeka',
                '',
                $url . ' -> ' . $err . ' (errno=' . $errno . ', http=' . $httpcode . ')'
            );
        }

        if ($httpcode >= 400) {
            throw new \moodle_exception(
                'repositoryomeka_apierror',
                'repository_omeka',
                '',
                $url . ' -> HTTP ' . $httpcode
            );
        }

        $body = json_decode((string)$response, true);
        if (!is_array($body)) {
            $raw = (string)$response;
            $preview = strlen($raw) > 200 ? substr($raw, 0, 200) . '…' : $raw;
            throw new \moodle_exception(
                'repositoryomeka_apierror',
                'repository_omeka',
                '',
                $url . ' -> invalid JSON (http=' . $httpcode . ', len=' . strlen($raw) . ', body=' . $preview . ')'
            );
        }

        $total = $this->parse_total_header($curl);

        return [
            'body' => $body,
            'total' => $total,
            'http_code' => $httpcode,
        ];
    }

    /**
     * List Omeka-S item sets.
     *
     * @param int $page Page number (1-based).
     * @param int $perpage Items per page.
     * @param int|null $siteid Optional site id.
     * @return array Same shape as {@see get()}.
     */
    public function get_item_sets(int $page, int $perpage, ?int $siteid = null): array {
        $params = ['page' => $page, 'per_page' => $perpage];
        if (!empty($siteid)) {
            $params['site_id'] = $siteid;
        }
        return $this->get('/api/item_sets', $params);
    }

    /**
     * List Omeka-S items.
     *
     * @param int $page Page (1-based).
     * @param int $perpage Items per page.
     * @param array $filters Additional filters (e.g. item_set_id, fulltext_search).
     * @return array Same shape as {@see get()}.
     */
    public function get_items(int $page, int $perpage, array $filters = []): array {
        $params = array_merge(['page' => $page, 'per_page' => $perpage], $filters);
        return $this->get('/api/items', $params);
    }

    /**
     * Retrieve a single item.
     *
     * @param int $id Item id.
     * @return array Same shape as {@see get()}.
     */
    public function get_item(int $id): array {
        return $this->get('/api/items/' . $id);
    }

    /**
     * Retrieve a single item set.
     *
     * @param int $id Item set id.
     * @return array Same shape as {@see get()}.
     */
    public function get_item_set(int $id): array {
        return $this->get('/api/item_sets/' . $id);
    }

    /**
     * Retrieve all media for an item with a single request (avoids N+1).
     *
     * @param int $itemid Item id.
     * @param int $perpage Items per page.
     * @return array Same shape as {@see get()}.
     */
    public function get_media_for_item(int $itemid, int $perpage = 100): array {
        return $this->get('/api/media', ['item_id' => $itemid, 'per_page' => $perpage]);
    }

    /**
     * Retrieve a single media.
     *
     * @param int $id Media id.
     * @return array Same shape as {@see get()}.
     */
    public function get_media(int $id): array {
        return $this->get('/api/media/' . $id);
    }

    /**
     * Retrieve a batch of media by id in a single API call.
     *
     * Omeka-S accepts an array of ids on the /api/media collection endpoint,
     * so we issue exactly one HTTP request instead of one per media.
     *
     * @param int[] $ids Media ids.
     * @return array Same shape as {@see get()}.
     */
    public function get_media_bulk(array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return ['body' => [], 'total' => 0, 'http_code' => 200];
        }
        return $this->get('/api/media', ['id' => $ids, 'per_page' => count($ids)]);
    }

    /**
     * List sites.
     *
     * @return array Same shape as {@see get()}.
     */
    public function get_sites(): array {
        return $this->get('/api/sites');
    }

    /**
     * Retrieve a single site.
     *
     * @param int $id Site id.
     * @return array Same shape as {@see get()}.
     */
    public function get_site(int $id): array {
        return $this->get('/api/sites/' . $id);
    }

    /**
     * Merge auth params (key_identity/key_credential) if configured.
     *
     * @param array $params Existing params.
     * @return array
     */
    private function merge_auth_params(array $params): array {
        if ($this->keyidentity !== null) {
            $params['key_identity'] = $this->keyidentity;
        }
        if ($this->keycredential !== null) {
            $params['key_credential'] = $this->keycredential;
        }
        return $params;
    }

    /**
     * Parse the Omeka-S-Total-Results header from a response.
     *
     * @param \curl $curl Curl instance used for the request.
     * @return int|null Total or null if missing.
     */
    private function parse_total_header(\curl $curl): ?int {
        $raw = method_exists($curl, 'getResponse') ? $curl->getResponse() : [];
        if (!is_array($raw)) {
            return null;
        }
        foreach ($raw as $key => $value) {
            if (is_string($key) && strcasecmp($key, 'Omeka-S-Total-Results') === 0) {
                return (int)$value;
            }
            if (is_int($key) && is_string($value) && stripos($value, 'Omeka-S-Total-Results:') === 0) {
                return (int)trim(substr($value, strlen('Omeka-S-Total-Results:')));
            }
        }
        return null;
    }
}
