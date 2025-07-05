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
 * Repository omeka class
 *
 * @package   repository_omeka
 * @copyright 2025 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_omeka extends repository {

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
        return $this->search("", (int)$page);
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
    public function search($searchtext, $page = 0) {
        $items = $this->api_request('/api/items', [
            'search' => $searchtext,
            'page'   => $page + 1,
        ]);

        $list = [];
        if (is_array($items)) {
            foreach ($items as $item) {
                $title = $item['o:title'] ?? 'Item ' . $item['o:id'];
                $media = $item['o:media'][0] ?? null;
                if (!$media) {
                    continue;
                }

                $mediainfo = $this->api_request('/api/media/' . $media['o:id']);
                if (!$mediainfo) {
                    continue;
                }

                $fileurl = $mediainfo['o:original_url'] ?? $mediainfo['o:source'] ?? '';
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
        }

        return [
            'dynload' => true,
            'nologin' => true,
            'page' => (int)$page,
            'norefresh' => false,
            'nosearch' => false,
            'manage' => rtrim(get_config('repository_omeka', 'baseurl'), '/'),
            'list' => $list,
            'path' => [],
            'pages' => (count($list) < 20) ? $page : -1,
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
     * Helper to perform GET requests against the Omeka-S API.
     *
     * @param string $path API path starting with '/'.
     * @param array $params Query parameters.
     * @return array
     */
    private function api_request(string $path, array $params = []): array {
        $baseurl = rtrim((string)get_config('repository_omeka', 'baseurl'), '/');
        if (!$baseurl) {
            return [];
        }

        $apikey = trim((string)get_config('repository_omeka', 'apikey'));
        if ($apikey !== '') {
            $params['key_identity'] = $apikey;
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
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
}
