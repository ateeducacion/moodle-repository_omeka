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
 * Lib class
 *
 * @package   repository_omeka
 * @copyright 2025 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once("{$CFG->dirroot}/repository/lib.php");

/**
 * Repository omeka class.
 *
 * @package   repository_omeka
 * @copyright 2025 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
class repository_omeka extends repository {

    /** @var array Last response headers. */
    private $lastheaders = [];

    /**
     * Retrieve a header value from the last API request.
     *
     * @param string $name Header name.
     * @return string|null
     */
    private function get_header_value(string $name): ?string {
        foreach ($this->lastheaders as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }
        return null;
    }

    /**
     * Get file listing.
     *
     * @param string $encodedpath
     * @param string $page
     *
     * @return array
     *
     * @throws coding_exception
     * @throws dml_exception
     */
    public function get_listing($encodedpath = "", $page = "") {
        $path = $encodedpath !== '' ? base64_decode($encodedpath) : '';
        $page = (int)$page;

        if ($path === '') {
            // Root: list item sets.
            return $this->list_item_sets($page);
        }

        // Route decoding: set:<id> or item:<id>.
        if (preg_match('/^(set|item):(\d+)$/', $path, $m)) {
            $type = $m[1];
            $id = (int)$m[2];
            if ($type === 'set') {
                return $this->list_items_in_set($id, $page);
            }
            return $this->list_media_in_item($id, $page);
        }

        // Backward compatibility: numeric path == item set id.
        if (ctype_digit($path)) {
            return $this->list_items_in_set((int)$path, $page);
        }

        // Fallback to search in case of unexpected path.
        return $this->search("", $page, null);
    }


    /**
     * List items inside an item set.
     *
     * @param int $setid
     * @param int $page
     * @return array
     */
    private function list_items_in_set(int $setid, int $page = 0): array {
        $perpage = 20;
        $params = [
        'page' => $page + 1,
        'per_page' => $perpage,
        'item_set_id' => $setid,
        ];
        $siteid = (int)$this->get_option('siteid');
        if ($siteid) {
            $params['site_id'] = $siteid;
        }

        $items = $this->api_request('/api/items', $params);
        $total = (int)($this->get_header_value('Omeka-S-Total-Results') ?? 0);

        $list = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $title = $item['o:title'] ?? ('Item ' . $item['o:id']);
            $thumb = '';
            if (!empty($item['thumbnail_display_urls']['medium'])) {
                $thumb = $item['thumbnail_display_urls']['medium'];
            } else if (!empty($item['o:media'][0])) {
                // Try to fetch first media thumbnail as a fallback.
                $mediainfo = $this->api_request('/api/media/' . $item['o:media'][0]['o:id']);
                $thumb = $mediainfo['thumbnail_display_urls']['medium'] ?? '';
            }
            // Items behave like folders leading to their media.
            $list[] = [
            'title' => $title,
            'path' => base64_encode('item:' . (string)$item['o:id']),
            'children' => [],
            'thumbnail' => $thumb,
            'thumbnail_height' => 90,
            'thumbnail_width' => 90,
            ];
        }

        // Breadcrumbs.
        $itemset = $this->api_request('/api/item_sets/' . $setid);
        $settitle = $itemset['o:title'] ?? ('Item set ' . $setid);

        return [
        'dynload' => true,
        'nologin' => true,
        'page' => $page,
        'norefresh' => false,
        'nosearch' => false,
        'manage' => rtrim($this->get_option('baseurl'), '/'),
        'list' => $list,
        'path' => [
            [ 'name' => get_string('pluginname', 'repository_omeka'), 'path' => '' ],
            [ 'name' => $settitle, 'path' => base64_encode('set:' . (string)$setid) ],
        ],
        'pages' => (($page + 1) * $perpage < $total) ? -1 : $page,
        ];
    }

    /**
     * List media files of a given item.
     *
     * @param int $itemid
     * @param int $page
     * @return array
     */
    private function list_media_in_item(int $itemid, int $page = 0): array {
        $perpage = 20;
        $params = [
        'page' => $page + 1,
        'per_page' => $perpage,
        'item_id' => $itemid,
        ];

        $media = $this->api_request('/api/media', $params);
        $total = (int)($this->get_header_value('Omeka-S-Total-Results') ?? 0);

        $list = [];
        foreach (is_array($media) ? $media : [] as $m) {
            $title = $m['o:title'] ?? ('Media ' . $m['o:id']);
            $fileurl = $m['o:original_url'] ?? $m['o:source'] ?? '';
            if (!$fileurl) {
                continue;
            }
            $thumb = $m['thumbnail_display_urls']['medium'] ?? '';
            $list[] = [
            'title' => $title,
            'source' => $fileurl,          // This makes it a "file", ready to pick.
            'thumbnail' => $thumb,
            'thumbnail_height' => 90,
            'thumbnail_width' => 90,
            ];
        }

        // Breadcrumbs.
        $item = $this->api_request('/api/items/' . $itemid);
        $setid = $item['o:item_set'][0]['o:id'] ?? null;
        $itemtitle = $item['o:title'] ?? ('Item ' . $itemid);

        $path = [
        [ 'name' => get_string('pluginname', 'repository_omeka'), 'path' => '' ],
        ];
        if ($setid) {
            $itemset = $this->api_request('/api/item_sets/' . $setid);
            $settitle = $itemset['o:title'] ?? ('Item set ' . $setid);
            $path[] = [ 'name' => $settitle, 'path' => base64_encode('set:' . (string)$setid) ];
        }
        $path[] = [ 'name' => $itemtitle, 'path' => base64_encode('item:' . (string)$itemid) ];

        return [
        'dynload' => true,
        'nologin' => true,
        'page' => $page,
        'norefresh' => false,
        'nosearch' => false,
        'manage' => rtrim($this->get_option('baseurl'), '/'),
        'list' => $list,
        'path' => $path,
        'pages' => (($page + 1) * $perpage < $total) ? -1 : $page,
        ];
    }

    /**
     * Return search results.
     *
     * @param string $searchtext
     * @param int $page
     *
     * @return array|mixed
     *
     * @throws coding_exception
     * @throws dml_exception
     */
    public function search($searchtext, $page = 0, ?int $itemsetid = null) {
        $perpage = 20;
        $params = $this->build_search_params((string)$searchtext, (int)$page, $itemsetid);

        $items = $this->api_request('/api/items', $params);
        $total = (int)($this->get_header_value('Omeka-S-Total-Results') ?? 0);

        $list = $this->build_item_list(is_array($items) ? $items : []);
        $pathinfo = $itemsetid ? $this->build_pathinfo_for_itemset((int)$itemsetid) : [];

        return [
            'dynload' => true,
            'nologin' => true,
            'page' => (int)$page,
            'norefresh' => false,
            'nosearch' => false,
            'manage' => rtrim($this->get_option('baseurl'), '/'),
            'list' => $list,
            'path' => $pathinfo,
            'pages' => (($page + 1) * $perpage < $total) ? -1 : $page,
        ];
    }

    /**
     * Build search parameters for Omeka-S items endpoint.
     *
     * @param string $searchtext Search expression.
     * @param int $page Page number (0-based).
     * @param int|null $itemsetid Optional item set filter.
     * @return array
     */
    private function build_search_params(string $searchtext, int $page, ?int $itemsetid): array {
        $params = [
            'page' => $page + 1,
            'per_page' => 20,
        ];
        if ($searchtext !== '') {
            if (preg_match('/^([a-z0-9:_-]+)\s*:\\s*(.+)$/i', $searchtext, $m)) {
                $params['property'][] = [
                    'property' => $m[1],
                    'type' => 'in',
                    'text' => trim($m[2]),
                ];
            } else {
                $params['fulltext_search'] = $searchtext;
            }
        }
        $siteid = (int)$this->get_option('siteid');
        if ($siteid) {
            $params['site_id'] = $siteid;
        }
        if (!empty($itemsetid)) {
            $params['item_set_id'] = (int)$itemsetid;
        }
        return $params;
    }

    /**
     * Build repository browser entries for a list of Omeka items.
     *
     * @param array $items Items as returned by Omeka-S.
     * @return array
     */
    private function build_item_list(array $items): array {
        $list = [];
        foreach ($items as $item) {
            $title = $item['o:title'] ?? ('Item ' . ($item['o:id'] ?? ''));
            $media = $item['o:media'][0] ?? null;
            if (!$media) {
                continue;
            }
            $mediainfo = $this->api_request('/api/media/' . $media['o:id']);
            if (!$mediainfo) {
                continue;
            }
            $fileurl = $mediainfo['o:original_url'] ?? ($mediainfo['o:source'] ?? '');
            if (!$fileurl) {
                continue;
            }
            $thumb = $mediainfo['thumbnail_display_urls']['medium'] ?? '';
            $list[] = [
                'title' => $title,
                'source' => $fileurl,
                'thumbnail' => $thumb,
                'thumbnail_height' => 90,
                'thumbnail_width' => 90,
            ];
        }
        return $list;
    }

    /**
     * Build breadcrumb path info for an item set selection.
     *
     * @param int $itemsetid Item set id.
     * @return array
     */
    private function build_pathinfo_for_itemset(int $itemsetid): array {
        $itemset = $this->api_request('/api/item_sets/' . $itemsetid);
        $title = $itemset['o:title'] ?? ('Item set ' . $itemsetid);
        return [
            [
                'name' => get_string('pluginname', 'repository_omeka'),
                'path' => '',
            ],
            [
                'name' => $title,
                'path' => base64_encode((string)$itemsetid),
            ],
        ];
    }

    /**
     * Retrieve a paginated list of item sets.
     *
     * @param int $page Page number starting at 0.
     * @return array
     */
    private function list_item_sets(int $page = 0): array {
        $perpage = 20;
        $params = [
            'page' => $page + 1,
            'per_page' => $perpage,
        ];
        $siteid = (int)$this->get_option('siteid');
        if ($siteid) {
            $params['site_id'] = $siteid;
        }

        $sets = $this->api_request('/api/item_sets', $params);
        $total = (int)($this->get_header_value('Omeka-S-Total-Results') ?? 0);

        $list = [];
        if (is_array($sets)) {
            foreach ($sets as $set) {
                $title = $set['o:title'] ?? 'Item set ' . $set['o:id'];
                $list[] = [
                    'title' => $title,
                    'path' => base64_encode((string)$set['o:id']),
                    'children' => [],
                    'thumbnail' => '',
                    'thumbnail_height' => 90,
                    'thumbnail_width' => 90,
                ];
            }
        }

        return [
            'dynload' => true,
            'nologin' => true,
            'page' => (int)$page,
            'norefresh' => false,
            'nosearch' => false,
            'manage' => rtrim($this->get_option('baseurl'), '/'),
            'list' => $list,
            'path' => [],
            'pages' => (($page + 1) * $perpage < $total) ? -1 : $page,
        ];
    }

    /**
     * Function h5p_itens
     *
     * @param object $path
     * @param string $extension
     *
     * @return array
     * @throws coding_exception
     */
    // Puedes eliminar este método si no vas a usar H5P.

    /**
     * Downloads a file from external repository and saves it in temp dir
     *
     * @param string $source
     * @param string $filename
     *
     * @return array
     * @throws Exception
     */
    public function get_file($source, $filename = "") {
        $filename = $filename ?: basename(parse_url($source, PHP_URL_PATH));
        $tmpfile = $this->prepare_file($filename);
        $content = @file_get_contents($source);
        if ($content === false) {
            throw new \moodle_exception('cannotdownload', 'repository_omeka');
        }
        file_put_contents($tmpfile, $content);
        return ['path' => $tmpfile];
    }

    /**
     * Youtube plugin doesn't support global search
     */
    public function global_search() {
        return false;
    }

    /**
     * get type option name function
     *
     * This function is for module settings.
     *
     * @return array
     */
    public static function get_type_option_names() {
        // Puedes añadir aquí las opciones de configuración necesarias para Omeka-S.
        return array_merge(parent::get_type_option_names(), []);
    }

    /**
     * File types supported by the Omeka repository plugin
     *
     * @return array
     */
    public function supported_filetypes() {
        // Ajusta los tipos de archivo soportados según lo que permita Omeka-S.
        return [
            "image", "audio", "video", "document",
        ];
    }

    /**
     * Omeka repository only returns external links
     *
     * @return int
     */
    public function supported_returntypes() {
        return FILE_INTERNAL | FILE_REFERENCE;
    }

    /**
     * Add fields to the repository instance configuration form.
     *
     * @param \moodleform $mform
     */
    public static function instance_config_form($mform) {
        global $PAGE;

        $mform->addElement('text', 'baseurl', get_string('baseurl', 'repository_omeka'));
        $mform->addRule('baseurl', get_string('required'), 'required', null, 'client');
        $mform->setType('baseurl', PARAM_URL);

        $baseurl = optional_param('baseurl', '', PARAM_URL);
        $keyidentity = optional_param('keyidentity', '', PARAM_RAW);
        $keycredential = optional_param('keycredential', '', PARAM_RAW);
        $instanceid = optional_param('id', 0, PARAM_INT);

        if (!$baseurl && $instanceid) {
            $instance = repository::get_instance($instanceid);
            $baseurl = (string)$instance->get_option('baseurl');
            $keyidentity = (string)$instance->get_option('keyidentity');
            $keycredential = (string)$instance->get_option('keycredential');
        }

        // Initial options if available.
        $sites = self::fetch_sites($baseurl, $keyidentity, $keycredential);
        $siteoptions = $sites ?: ['' => get_string('choosedots')];

        $mform->addElement('select', 'siteid', get_string('site', 'repository_omeka'), $siteoptions);
        $mform->setType('siteid', PARAM_INT);
        $mform->addHelpButton('siteid', 'site', 'repository_omeka');
        $mform->disabledIf('siteid', 'baseurl', 'eq', '');

        $mform->addElement('text', 'keyidentity', get_string('keyidentity', 'repository_omeka'));
        $mform->setType('keyidentity', PARAM_TEXT);
        $mform->addHelpButton('keyidentity', 'keyidentity', 'repository_omeka');

        $mform->addElement('text', 'keycredential', get_string('keycredential', 'repository_omeka'));
        $mform->setType('keycredential', PARAM_TEXT);
        $mform->addHelpButton('keycredential', 'keycredential', 'repository_omeka');

        // Init AMD to live-refresh the site select via AJAX.
        $PAGE->requires->js_call_amd('repository_omeka/omekasites', 'init', []);

    }


    /**
     * Save settings for repository instance.
     *
     * @param array $options settings
     * @return bool
     */
    public function set_option($options = []) {
        $options['baseurl'] = clean_param($options['baseurl'], PARAM_URL);
        $options['siteid'] = clean_param($options['siteid'], PARAM_INT);
        $options['keyidentity'] = clean_param($options['keyidentity'], PARAM_TEXT);
        $options['keycredential'] = clean_param($options['keycredential'], PARAM_TEXT);
        return parent::set_option($options);
    }

    /**
     * Names of the plugin settings stored per instance.
     *
     * @return array
     */
    public static function get_instance_option_names() {
        return ['baseurl', 'siteid', 'keyidentity', 'keycredential'];
    }

    /**
     * Retrieve list of available sites from an Omeka-S instance.
     *
     * @param string $baseurl Base URL of Omeka-S.
     * @param string $keyidentity Optional API key identity.
     * @param string $keycredential Optional API key credential.
     * @return array siteid => label
     */
    public static function fetch_sites(string $baseurl, string $keyidentity = '', string $keycredential = ''): array {
        $baseurl = rtrim($baseurl, '/');
        if (!$baseurl) {
            return [];
        }
        $params = self::build_site_params($keyidentity, $keycredential);
        $url = $baseurl . '/api/sites' . (!empty($params) ? ('?' . http_build_query($params)) : '');
        $content = @file_get_contents($url);
        if ($content === false) {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? self::parse_sites($data) : [];
    }

    /**
     * Build request params for listing sites.
     *
     * @param string $keyidentity
     * @param string $keycredential
     * @return array
     */
    private static function build_site_params(string $keyidentity, string $keycredential): array {
        $params = [];
        if ($keyidentity !== '') {
            $params['key_identity'] = $keyidentity;
        }
        if ($keycredential !== '') {
            $params['key_credential'] = $keycredential;
        }
        return $params;
    }

    /**
     * Parse sites response into id => label map.
     *
     * @param array $data
     * @return array
     */
    private static function parse_sites(array $data): array {
        $list = [];
        foreach ($data as $site) {
            if (!isset($site['o:id'])) {
                continue;
            }
            $title = $site['o:title'] ?? ('Site ' . $site['o:id']);
            $slug = $site['o:slug'] ?? '';
            $label = $title . ($slug !== '' ? " ({$slug})" : '');
            $list[$site['o:id']] = $label;
        }
        return $list;
    }

    /**
     * Helper to perform GET requests against the Omeka-S API.
     *
     * @param string $path API path starting with '/'.
     * @param array $params Query parameters.
     * @return array
     */
    private function api_request(string $path, array $params = []): array {
        $baseurl = rtrim((string)$this->get_option('baseurl'), '/');
        if (!$baseurl) {
            return [];
        }

        $keyidentity = trim((string)$this->get_option('keyidentity'));
        if ($keyidentity !== '') {
            $params['key_identity'] = $keyidentity;
        }

        $keycredential = trim((string)$this->get_option('keycredential'));
        if ($keycredential !== '') {
            $params['key_credential'] = $keycredential;
        }

        $url = $baseurl . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $opts = [
            'http' => [
                'header' => "Accept: application/json\r\n",
                'timeout' => 10,
            ],
        ];

        $context = stream_context_create($opts);
        $content = @file_get_contents($url, false, $context);
        $this->lastheaders = $http_response_header ?? [];
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
}
