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
 * Lib class for the Omeka-S repository plugin.
 *
 * @package   repository_omeka
 * @copyright 2025 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once("{$CFG->dirroot}/repository/lib.php");
require_once("{$CFG->libdir}/filelib.php");

use repository_omeka\local\api_client;
use repository_omeka\local\filetype_filter;
use repository_omeka\local\instance_form;
use repository_omeka\local\listing_builder;

/**
 * Repository plugin for Omeka-S.
 *
 * Acts as a thin adapter between Moodle's repository API and the
 * helper classes under {@see \repository_omeka\local\*}.
 *
 * @package   repository_omeka
 * @copyright 2025 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_omeka extends repository {
    /** @var int Default API timeout in seconds. */
    const API_TIMEOUT = 10;

    /** @var api_client|null Lazily built API client. */
    private $client;

    /** @var listing_builder|null Lazily built listing builder. */
    private $listingbuilder;

    /** @var filetype_filter|null Lazily built filetype filter. */
    private $filter;

    /**
     * Return a cached API client built from the instance options.
     *
     * @return api_client
     */
    private function get_client(): api_client {
        if ($this->client === null) {
            $this->client = new api_client(
                (string)$this->get_option('baseurl'),
                (string)$this->get_option('keyidentity'),
                (string)$this->get_option('keycredential'),
                self::API_TIMEOUT
            );
        }
        return $this->client;
    }

    /**
     * Return a cached listing builder.
     *
     * @return listing_builder
     */
    private function get_listing_builder(): listing_builder {
        if ($this->listingbuilder === null) {
            $this->listingbuilder = new listing_builder(
                $this->get_client(),
                rtrim((string)$this->get_option('baseurl'), '/')
            );
        }
        return $this->listingbuilder;
    }

    /**
     * Return a cached filetype filter.
     *
     * @return filetype_filter
     */
    private function get_filetype_filter(): filetype_filter {
        if ($this->filter === null) {
            $this->filter = new filetype_filter((string)$this->get_option('acceptedtypes'));
        }
        return $this->filter;
    }

    /**
     * Get file listing for the file picker.
     *
     * @param string $encodedpath Base64-encoded path token.
     * @param string $page Page number (0-based).
     * @return array Listing array.
     */
    public function get_listing($encodedpath = '', $page = '') {
        $path = $encodedpath !== '' ? base64_decode($encodedpath) : '';
        $page = (int)$page;
        $siteid = (int)$this->get_option('siteid') ?: null;
        $builder = $this->get_listing_builder();
        $filter = $this->get_filetype_filter();

        if ($path === '') {
            if ($siteid) {
                return $builder->search('', $page, $siteid, $filter);
            }
            return $builder->list_item_sets($page, $siteid);
        }
        if (preg_match('/^(set|item):(\d+)$/', $path, $matches)) {
            $id = (int)$matches[2];
            if ($matches[1] === 'set') {
                return $builder->list_items_in_set($id, $page, $siteid, $filter);
            }
            return $builder->list_media_in_item($id, $page, $filter);
        }
        if (ctype_digit($path)) {
            return $builder->list_items_in_set((int)$path, $page, $siteid, $filter);
        }
        return $builder->search('', $page, $siteid, $filter);
    }

    /**
     * Search Omeka-S for matching items.
     *
     * @param string $searchtext Search expression.
     * @param int $page Page number (0-based).
     * @param int|null $itemsetid Optional item set filter.
     * @return array Listing array.
     */
    public function search($searchtext, $page = 0, ?int $itemsetid = null) {
        $siteid = (int)$this->get_option('siteid') ?: null;
        return $this->get_listing_builder()->search(
            (string)$searchtext,
            (int)$page,
            $siteid,
            $this->get_filetype_filter(),
            $itemsetid
        );
    }

    /**
     * Download a remote file from Omeka-S into a temp file.
     *
     * @param string $source File URL.
     * @param string $filename Optional desired filename.
     * @return array With key 'path' pointing at the temp file.
     */
    public function get_file($source, $filename = '') {
        $filename = $filename !== '' ? $filename : basename((string)parse_url((string)$source, PHP_URL_PATH));
        $tmpfile = $this->prepare_file($filename);
        $curl = new \curl();
        $fp = fopen($tmpfile, 'w');
        if ($fp === false) {
            throw new \moodle_exception('cannotdownload', 'repository_omeka');
        }
        $curl->download_one($source, [], [
            'file' => $fp,
            'CURLOPT_FOLLOWLOCATION' => 1,
            'CURLOPT_TIMEOUT' => self::API_TIMEOUT * 6,
        ]);
        fclose($fp);
        $info = $curl->get_info();
        if ((int)($info['http_code'] ?? 0) >= 400) {
            throw new \moodle_exception('cannotdownload', 'repository_omeka');
        }
        return ['path' => $tmpfile];
    }

    /**
     * The Omeka-S repository does not participate in global search.
     *
     * @return bool
     */
    public function global_search() {
        return false;
    }

    /**
     * Site-wide settings names.
     *
     * @return array
     */
    public static function get_type_option_names() {
        return parent::get_type_option_names();
    }

    /**
     * File types supported by the repository.
     *
     * @return string
     */
    public function supported_filetypes() {
        return '*';
    }

    /**
     * Return types supported by the repository.
     *
     * @return int
     */
    public function supported_returntypes() {
        return FILE_INTERNAL | FILE_REFERENCE;
    }

    /**
     * Whether this repository stores Moodle files.
     *
     * @return bool
     */
    public function has_moodle_files() {
        return false;
    }

    /**
     * Add fields to the repository instance configuration form.
     *
     * @param \moodleform|\MoodleQuickForm $mform Form to extend.
     */
    public static function instance_config_form($mform) {
        global $PAGE;

        instance_form::add_baseurl_field($mform);
        instance_form::add_keys_fields($mform);

        $baseurl = optional_param('baseurl', '', PARAM_URL);
        $keyidentity = optional_param('keyidentity', '', PARAM_RAW);
        $keycredential = optional_param('keycredential', '', PARAM_RAW);
        $instanceid = optional_param('edit', 0, PARAM_INT);
        if (!$instanceid) {
            $instanceid = optional_param('id', 0, PARAM_INT);
        }
        $currentsiteid = 0;
        $acceptedtypes = '';
        $currentsitelabel = '';
        $currentsiteslug = '';
        if (!$baseurl && $instanceid) {
            $instance = repository::get_instance($instanceid);
            $baseurl = (string)$instance->get_option('baseurl');
            $keyidentity = (string)$instance->get_option('keyidentity');
            $keycredential = (string)$instance->get_option('keycredential');
            $currentsiteid = (int)$instance->get_option('siteid');
            $acceptedtypes = (string)$instance->get_option('acceptedtypes');
            $currentsitelabel = (string)$instance->get_option('sitelabel');
            $currentsiteslug = (string)$instance->get_option('siteslug');
        }

        $sites = self::fetch_sites($baseurl, $keyidentity, $keycredential);
        if ($currentsitelabel === '' && $currentsiteid && $baseurl) {
            $details = self::fetch_site_details($baseurl, $keyidentity, $keycredential, $currentsiteid);
            $currentsitelabel = $details['title'] ?? '';
            $currentsiteslug = $details['slug'] ?? '';
        }
        instance_form::add_site_selector($mform, $sites, $currentsiteid, $currentsitelabel, $currentsiteslug);
        instance_form::add_filetype_selector($mform, $acceptedtypes);

        $PAGE->requires->js_call_amd('repository_omeka/omekasites', 'init', [get_string('all')]);

        if ($baseurl) {
            $mform->setDefault('baseurl', $baseurl);
        }
        if ($keyidentity !== '') {
            $mform->setDefault('keyidentity', $keyidentity);
        }
        if ($keycredential !== '') {
            $mform->setDefault('keycredential', $keycredential);
        }
    }

    /**
     * Save settings for repository instance.
     *
     * @param array $options Settings to persist.
     * @return bool
     */
    public function set_option($options = []) {
        if (isset($options['baseurl'])) {
            $options['baseurl'] = clean_param($options['baseurl'], PARAM_URL);
        }
        if (isset($options['siteid'])) {
            $options['siteid'] = clean_param($options['siteid'], PARAM_INT);
        }
        if (isset($options['keyidentity'])) {
            $options['keyidentity'] = clean_param($options['keyidentity'], PARAM_TEXT);
        }
        if (isset($options['keycredential'])) {
            $options['keycredential'] = clean_param($options['keycredential'], PARAM_TEXT);
        }
        if (isset($options['acceptedtypes']) && is_array($options['acceptedtypes'])) {
            if (isset($options['acceptedtypes']['filetypes'])) {
                $options['acceptedtypes'] = (string)$options['acceptedtypes']['filetypes'];
            } else {
                $options['acceptedtypes'] = implode(',', $options['acceptedtypes']);
            }
        }
        if (isset($options['acceptedtypes'])) {
            $options['acceptedtypes'] = clean_param($options['acceptedtypes'], PARAM_RAW_TRIMMED);
        }
        if (isset($options['sitelabel'])) {
            $options['sitelabel'] = clean_param($options['sitelabel'], PARAM_TEXT);
        }
        if (isset($options['siteslug'])) {
            $options['siteslug'] = clean_param($options['siteslug'], PARAM_ALPHANUMEXT);
        }
        return parent::set_option($options);
    }

    /**
     * Names of the plugin settings stored per instance.
     *
     * @return array
     */
    public static function get_instance_option_names() {
        return ['baseurl', 'siteid', 'keyidentity', 'keycredential', 'acceptedtypes', 'sitelabel', 'siteslug'];
    }

    /**
     * Validate the instance configuration form input.
     *
     * @param \moodleform $mform Form.
     * @param array $data Submitted data.
     * @param array $errors Errors accumulator.
     * @return array Updated errors array.
     */
    public static function instance_form_validation($mform, $data, $errors) {
        if (empty($data['baseurl'])) {
            $errors['baseurl'] = get_string('required');
        }
        return $errors;
    }

    /**
     * Fetch the detailed list of Omeka-S sites (id, title, slug, label).
     *
     * @param string $baseurl Omeka-S base URL.
     * @param string $keyidentity Optional API key identity.
     * @param string $keycredential Optional API key credential.
     * @return array<int,array{id:int,title:string,slug:string,label:string}>
     */
    public static function fetch_sites_detailed(string $baseurl, string $keyidentity = '', string $keycredential = ''): array {
        $client = self::build_anonymous_client($baseurl, $keyidentity, $keycredential);
        if ($client === null) {
            return [];
        }
        try {
            $response = $client->get_sites();
        } catch (\moodle_exception $e) {
            return [];
        }
        $out = [];
        foreach ($response['body'] ?? [] as $site) {
            if (!isset($site['o:id'])) {
                continue;
            }
            $id = (int)$site['o:id'];
            $title = (string)($site['o:title'] ?? ('Site ' . $id));
            $slug = (string)($site['o:slug'] ?? '');
            $out[] = [
                'id' => $id,
                'title' => $title,
                'slug' => $slug,
                'label' => $title . ($slug !== '' ? " ({$slug})" : ''),
            ];
        }
        return $out;
    }

    /**
     * Fetch a single site's details (title, slug) from Omeka-S.
     *
     * @param string $baseurl Omeka-S base URL.
     * @param string $keyidentity Optional API key identity.
     * @param string $keycredential Optional API key credential.
     * @param int $siteid Site id.
     * @return array{title?:string,slug?:string} Empty array on failure.
     */
    public static function fetch_site_details(string $baseurl, string $keyidentity, string $keycredential, int $siteid): array {
        $client = self::build_anonymous_client($baseurl, $keyidentity, $keycredential);
        if ($client === null || !$siteid) {
            return [];
        }
        try {
            $response = $client->get_site($siteid);
        } catch (\moodle_exception $e) {
            return [];
        }
        $site = $response['body'] ?? [];
        return [
            'title' => (string)($site['o:title'] ?? ''),
            'slug' => (string)($site['o:slug'] ?? ''),
        ];
    }

    /**
     * Fetch sites as a simple id => label map.
     *
     * @param string $baseurl Omeka-S base URL.
     * @param string $keyidentity Optional API key identity.
     * @param string $keycredential Optional API key credential.
     * @return array<int,string>
     */
    public static function fetch_sites(string $baseurl, string $keyidentity = '', string $keycredential = ''): array {
        $list = [];
        foreach (self::fetch_sites_detailed($baseurl, $keyidentity, $keycredential) as $site) {
            $list[$site['id']] = $site['label'];
        }
        return $list;
    }

    /**
     * Build a client for static helper methods (form prepopulation).
     *
     * @param string $baseurl Omeka-S base URL.
     * @param string $keyidentity Optional API key identity.
     * @param string $keycredential Optional API key credential.
     * @return api_client|null Null when baseurl is empty.
     */
    private static function build_anonymous_client(string $baseurl, string $keyidentity, string $keycredential): ?api_client {
        $baseurl = rtrim($baseurl, '/');
        if ($baseurl === '') {
            return null;
        }
        return new api_client($baseurl, $keyidentity, $keycredential, self::API_TIMEOUT);
    }
}
