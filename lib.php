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
require_once("{$CFG->libdir}/filelib.php");

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
     * Extract the first textual value for one of the provided property terms from
     * an Omeka-S resource (item or media). Tries common JSON-LD shapes.
     *
     * @param array $resource Omeka-S resource array.
     * @param array $terms Preferred property terms (e.g. ['dcterms:license']).
     * @return string Empty string if not found.
     */
    private function omeka_first_prop_text(array $resource, array $terms): string {
        foreach ($terms as $term) {
            if (!isset($resource[$term]) || !is_array($resource[$term]) || empty($resource[$term])) {
                continue;
            }
            $v = $resource[$term][0];
            // Literal value.
            if (is_array($v) && isset($v['@value']) && is_scalar($v['@value'])) {
                return (string)$v['@value'];
            }
            // URI value.
            if (is_array($v) && isset($v['@id']) && is_scalar($v['@id'])) {
                return (string)$v['@id'];
            }
            // Linked resource with title/label.
            if (is_array($v) && isset($v['value_resource']['o:title'])) {
                return (string)$v['value_resource']['o:title'];
            }
            // Fallback if string provided directly.
            if (is_string($v)) {
                return $v;
            }
        }
        return '';
    }

    /**
     * Extract first date-like property and return as timestamp.
     * Looks at common terms if system dates are missing.
     *
     * @param array $resource
     * @return int
     */
    private function omeka_first_date_ts(array $resource): int {
        $candidates = ['dcterms:modified', 'schema:dateModified', 'dcterms:created', 'schema:dateCreated', 'dcterms:date'];
        $txt = $this->omeka_first_prop_text($resource, $candidates);
        return $this->parse_omeka_datetime($txt);
    }

    /**
     * Try to derive filesize from URL via HEAD if Omeka does not provide it.
     *
     * @param string $url
     * @return int Bytes or 0 if unknown.
     */
    private function resolve_filesize_from_url(string $url): int {
        if ($url === '') {
            return 0;
        }
        $opts = [
            'http' => [
                'method' => 'HEAD',
                'timeout' => 1.5,
                'ignore_errors' => true,
            ],
        ];
        $context = stream_context_create($opts);
        @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        foreach ($headers as $h) {
            if (stripos($h, 'Content-Length:') === 0) {
                $len = (int)trim(substr($h, strlen('Content-Length:')));
                if ($len > 0) {
                    return $len;
                }
            }
        }
        return 0;
    }

    /**
     * Guess a mimetype from a URL path extension when Omeka does not provide one.
     *
     * @param string $url
     * @return string
     */
    protected function guess_mimetype_from_url(string $url): string {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') {
            return '';
        }
        $map = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'jpe' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'tif' => 'image/tiff',
            'tiff' => 'image/tiff',
            'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4',
            'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'ogv' => 'video/ogg',
            'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'oga' => 'audio/ogg',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'html' => 'text/html',
            'htm' => 'text/html',
            'zip' => 'application/zip',
            'rar' => 'application/vnd.rar',
            '7z' => 'application/x-7z-compressed',
            'gz' => 'application/gzip',
            'tar' => 'application/x-tar',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        return $map[$ext] ?? '';
    }

    /**
     * Parse human-readable size (e.g. "12 MB", "1.2MiB") to bytes.
     *
     * @param string $text
     * @return int
     */
    protected function parse_human_size_to_bytes(string $text): int {
        $v = trim($text);
        if ($v === '') {
            return 0;
        }
        // Normalize decimal comma.
        $v = str_replace(',', '.', $v);
        if (!preg_match('/([0-9]+(?:\.[0-9]+)?)\s*([kmgtp]?i?b)?/i', $v, $m)) {
            return 0;
        }
        $num = (float)$m[1];
        $unit = strtolower($m[2] ?? '');
        $pow = 0;
        switch ($unit) {
            case 'kb':
            case 'kib':
                $pow = 1;
                break;
            case 'mb':
            case 'mib':
                $pow = 2;
                break;
            case 'gb':
            case 'gib':
                $pow = 3;
                break;
            case 'tb':
            case 'tib':
                $pow = 4;
                break;
            case 'pb':
            case 'pib':
                $pow = 5;
                break;
            default:
                $pow = 0;
                break; // Bytes or unknown.
        }
        // Mixed expectations: treat KB as 1024 (binary), MB+ as decimal unless using explicit 'i'.
        if ($unit === 'kb') {
            $base = 1024;
        } else {
            $base = (strpos($unit, 'i') !== false) ? 1024 : 1000;
        }
        return (int)round($num * pow($base, $pow));
    }

    /**
     * Parse instance setting "acceptedtypes" into a list of tokens.
     * Accepts a comma/space/semicolon separated list. Example: ".pdf, image, application/json".
     *
     * @param string $text
     * @return array list of tokens in lowercase (e.g. ['.pdf', 'image']).
     */
    private function parse_acceptedtypes_tokens(string $text): array {
        $s = trim($text);
        if ($s === '') {
            return [];
        }
        $s = str_replace([';', '\n', '\r'], ',', $s);
        $parts = preg_split('/[\s,]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $tokens[] = strtolower($p);
        }
        return array_values(array_unique($tokens));
    }

    /**
     * Return true if file (by filename/mimetype) passes instance accepted types filter.
     * When no filter is set, returns true.
     *
     * Supported tokens:
     * - extensions like ".pdf" or "pdf"
     * - mimetypes like "image/png" or "application/json"
     * - groups: image, audio, video, text, document, spreadsheet, presentation, archive, pdf, json, html, svg
     *
     * @param string $filename
     * @param string $mimetype
     * @return bool
     */
    protected function is_allowed_by_instance_types(string $filename, string $mimetype): bool {
        $raw = (string)$this->get_option('acceptedtypes');
        if ($raw === '' && isset($this->options) && is_array($this->options) && isset($this->options['acceptedtypes'])) {
            $raw = (string)$this->options['acceptedtypes'];
        }
        $tokens = $this->parse_acceptedtypes_tokens($raw);
        if (empty($tokens)) {
            return true;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mt = strtolower($mimetype);

        foreach ($tokens as $t) {
            if ($t === '*') {
                return true;
            }
            // Extension with or without leading dot.
            if ($t[0] === '.') {
                if ($ext !== '' && $ext === substr($t, 1)) {
                    return true;
                }
            } else if (strpos($t, '/') !== false) {
                // Exact mimetype match.
                if ($mt !== '' && $mt === $t) {
                    return true;
                }
            } else {
                // Group or bare extension.
                if ($ext !== '' && $ext === $t) {
                    return true;
                }
                switch ($t) {
                    case 'image':
                        if (strpos($mt, 'image/') === 0) {
                            return true;
                        }
                        if (in_array(
                            $ext,
                            ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'tif', 'tiff', 'svg', 'bmp', 'heic', 'heif', 'avif'],
                            true
                        )) {
                            return true;
                        }
                        break;
                    case 'audio':
                        if (strpos($mt, 'audio/') === 0) {
                            return true;
                        }
                        if (in_array($ext, ['mp3', 'ogg', 'oga', 'wav', 'flac', 'm4a', 'aac'], true)) {
                            return true;
                        }
                        break;
                    case 'video':
                        if (strpos($mt, 'video/') === 0) {
                            return true;
                        }
                        if (in_array($ext, ['mp4', 'm4v', 'webm', 'ogv', 'mov', 'avi', 'mkv'], true)) {
                            return true;
                        }
                        break;
                    case 'text':
                        if (strpos($mt, 'text/') === 0) {
                            return true;
                        }
                        if (in_array($ext, ['txt', 'csv', 'html', 'htm', 'json', 'md'], true)) {
                            return true;
                        }
                        break;
                    case 'pdf':
                        if ($ext === 'pdf' || $mt === 'application/pdf') {
                            return true;
                        }
                        break;
                    case 'json':
                        if ($ext === 'json' || $mt === 'application/json') {
                            return true;
                        }
                        break;
                    case 'html':
                        if ($ext === 'html' || $ext === 'htm' || $mt === 'text/html') {
                            return true;
                        }
                        break;
                    case 'svg':
                        if ($ext === 'svg' || $mt === 'image/svg+xml') {
                            return true;
                        }
                        break;
                    case 'document':
                        if (in_array($ext, ['pdf', 'doc', 'docx', 'odt', 'rtf'], true)) {
                            return true;
                        }
                        if (in_array(
                            $mt,
                            [
                                'application/pdf',
                                'application/msword',
                                'application/rtf',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.oasis.opendocument.text',
                            ],
                            true
                        )) {
                            return true;
                        }
                        break;
                    case 'spreadsheet':
                        if (in_array($ext, ['xls', 'xlsx', 'ods', 'csv'], true)) {
                            return true;
                        }
                        if (in_array(
                            $mt,
                            [
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'text/csv',
                                'application/vnd.oasis.opendocument.spreadsheet',
                            ],
                            true
                        )) {
                            return true;
                        }
                        break;
                    case 'presentation':
                        if (in_array($ext, ['ppt', 'pptx', 'odp'], true)) {
                            return true;
                        }
                        if (in_array(
                            $mt,
                            [
                                'application/vnd.ms-powerpoint',
                                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                                'application/vnd.oasis.opendocument.presentation',
                            ],
                            true
                        )) {
                            return true;
                        }
                        break;
                    case 'archive':
                        if (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'], true)) {
                            return true;
                        }
                        if (in_array(
                            $mt,
                            [
                                'application/zip',
                                'application/x-zip-compressed',
                                'application/x-rar-compressed',
                                'application/x-7z-compressed',
                                'application/gzip',
                                'application/x-tar',
                            ],
                            true
                        )) {
                            return true;
                        }
                        break;
                }
            }
        }
        return false;
    }

    /**
     * Map a free-form or URL license string to Moodle license shortname.
     * Returns an array with [shortname, url] when recognised.
     *
     * @param string $value License free-text or URL.
     * @return array [string $shortname, string $url]
     */
    protected function map_license_to_moodle(string $value): array {
        $short = 'unknown';
        $url = '';
        $v = trim($value);
        if ($v === '') {
            return [$short, $url];
        }
        $vlow = strtolower($v);
        // Extract URL if present inside the value.
        if (preg_match('#https?://[^\s]+#', $v, $m)) {
            $url = $m[0];
        } else if (filter_var($v, FILTER_VALIDATE_URL)) {
            $url = $v;
        }

        // Creative Commons variants.
        if (strpos($vlow, 'creativecommons.org') !== false) {
            $url = $url ?: $v;
            if (preg_match('#/licenses/([a-z-]+)/#', $vlow, $m)) {
                $code = $m[1];
                // Normalise cc code to Moodle shortnames.
                $map = [
                    'by' => 'cc-by',
                    'by-sa' => 'cc-by-sa',
                    'by-nd' => 'cc-by-nd',
                    'by-nc' => 'cc-by-nc',
                    'by-nc-sa' => 'cc-by-nc-sa',
                    'by-nc-nd' => 'cc-by-nc-nd',
                ];
                if (isset($map[$code])) {
                    $short = $map[$code];
                    return [$short, $url];
                }
            }
            if (strpos($vlow, '/publicdomain/zero/') !== false || strpos($vlow, 'cc0') !== false) {
                return ['cc-zero', $url ?: $v];
            }
        }

        // CC0 or Public Domain textual hints.
        if (strpos($vlow, 'cc0') !== false || strpos($vlow, 'public domain') !== false || strpos($vlow, 'zero') !== false) {
            return ['cc-zero', $url ?: $v];
        }

        // All rights reserved.
        if (strpos($vlow, 'all rights reserved') !== false) {
            return ['allrightsreserved', $url];
        }

        // Generic Creative Commons when exact variant unknown.
        if (strpos($vlow, 'creative commons') !== false) {
            return ['cc', $url];
        }

        // Fall back to unknown; keep discovered url.
        return [$short, $url];
    }

    /**
     * Build a file picker entry array from a media within an item.
     * Applies accepted types filter and derives metadata, dates, size, icon, and thumbnail.
     * Returns null if media is not listable.
     *
     * @param array $item Parent item data.
     * @param array $media Media data.
     * @param string $title Entry title to display.
     * @param string $fallbackthumb Fallback thumbnail URL from the item.
     * @return array|null
     */
    private function build_entry_from_media_in_item(array $item, array $media, string $title, string $fallbackthumb): ?array {
        global $OUTPUT;
        $fileurl = $media['o:original_url'] ?? ($media['o:source'] ?? '');
        if ($fileurl === '') {
            return null;
        }
        $filename = basename((string)parse_url($fileurl, PHP_URL_PATH));
        $mimetype = isset($media['o:media_type']) ? (string)$media['o:media_type'] : '';
        if ($mimetype === '') {
            $mimetype = $this->guess_mimetype_from_url($fileurl);
        }
        if (!$this->is_allowed_by_instance_types($filename, $mimetype)) {
            return null;
        }

        $thumb = $media['thumbnail_display_urls']['square']
            ?? ($media['thumbnail_display_urls']['medium'] ?? $fallbackthumb);

        $author = $this->omeka_first_prop_text(
            $media,
            ['dcterms:creator', 'dcterms:contributor', 'dcterms:publisher']
        );
        if ($author === '') {
            $author = $this->omeka_first_prop_text(
                $item,
                ['dcterms:creator', 'dcterms:contributor', 'dcterms:publisher']
            );
        }
        $licval = $this->omeka_first_prop_text(
            $media,
            ['dcterms:license', 'schema:license', 'dcterms:rights']
        );
        if ($licval === '') {
            $licval = $this->omeka_first_prop_text(
                $item,
                ['dcterms:license', 'schema:license', 'dcterms:rights']
            );
        }
        [$licshort, $licurl] = $this->map_license_to_moodle((string)$licval);
        $license = $licshort !== 'unknown' ? $licshort : (string)$licval;

        $datecreated = $this->parse_omeka_datetime($media['o:created'] ?? null);
        if (!$datecreated) {
            $datecreated = $this->omeka_first_date_ts($media);
        }
        if (!$datecreated) {
            $datecreated = $this->parse_omeka_datetime($item['o:created'] ?? null);
        }
        $datemodified = $this->parse_omeka_datetime($media['o:modified'] ?? null);
        if (!$datemodified) {
            $datemodified = $this->omeka_first_date_ts($media);
        }
        if (!$datemodified) {
            $datemodified = $this->parse_omeka_datetime($item['o:modified'] ?? null);
            if (!$datemodified) {
                $datemodified = $this->omeka_first_date_ts($item);
            }
        }

        $iconpix = $filename !== '' ? file_extension_icon($filename) : '';
        if ($iconpix === '' && $mimetype !== '') {
            $base = mimeinfo_from_type('icon', $mimetype);
            if (is_string($base) && $base !== '') {
                $iconpix = 'f/' . $base;
            }
        }
        if ($iconpix === '' || $iconpix === null) {
            $iconpix = 'f/unknown';
        }
        $iconurl = isset($OUTPUT) && is_object($OUTPUT) ? $OUTPUT->image_url($iconpix)->out(false) : '';

        $size = isset($media['o:size']) ? (int)$media['o:size'] : 0;
        if ($size <= 0) {
            $extent = $this->omeka_first_prop_text($media, ['dcterms:extent', 'schema:contentSize']);
            if ($extent !== '') {
                $parsed = $this->parse_human_size_to_bytes($extent);
                if ($parsed > 0) {
                    $size = $parsed;
                }
            }
        }

        return [
            'title' => $title,
            'source' => $fileurl,
            'filename' => $filename,
            'icon' => $iconurl,
            'thumbnail' => $thumb,
            'thumbnail_height' => 90,
            'thumbnail_width' => 90,
            'author' => $author,
            'license' => $license,
            'licenseurl' => $licurl,
            'mimetype' => $mimetype,
            'size' => $size,
            'filesize' => $size,
            'date' => $datemodified ?: $datecreated,
            'datecreated' => $datecreated,
            'datemodified' => $datemodified,
        ];
    }

    /**
     * Compute filename (with fallback by mimetype) and icon URL.
     *
     * @param string $fileurl
     * @param string $mimetype
     * @return array [$filename, $iconurl]
     */
    private function compute_filename_and_icon(string $fileurl, string $mimetype): array {
        global $OUTPUT;
        $filename = basename((string)parse_url($fileurl, PHP_URL_PATH));
        if ($filename === '' && $mimetype !== '') {
            $extbytype = [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/x-eps' => 'eps',
                'image/eps' => 'eps',
                'application/postscript' => 'eps',
                'video/mp4' => 'mp4',
                'video/webm' => 'webm',
                'video/ogg' => 'ogv',
                'audio/mpeg' => 'mp3',
                'audio/ogg' => 'ogg',
                'audio/wav' => 'wav',
                'text/plain' => 'txt',
                'text/csv' => 'csv',
                'application/json' => 'json',
                'text/html' => 'html',
                'application/zip' => 'zip',
                'application/x-zip-compressed' => 'zip',
                'application/x-7z-compressed' => '7z',
                'application/x-rar-compressed' => 'rar',
                'application/epub+zip' => 'epub',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
                'application/vnd.ms-powerpoint' => 'ppt',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                'application/vnd.ms-excel' => 'xls',
            ];
            $ext = $extbytype[$mimetype] ?? '';
            if ($ext !== '') {
                $filename = 'file.' . $ext;
            }
        }
        $iconpix = $filename !== '' ? file_extension_icon($filename) : '';
        if ($iconpix === '' && $mimetype !== '') {
            $base = mimeinfo_from_type('icon', $mimetype);
            if (is_string($base) && $base !== '') {
                $iconpix = 'f/' . $base;
            }
        }
        if ($iconpix === '' || $iconpix === null) {
            $iconpix = 'f/unknown';
        }
        $iconurl = '';
        if (isset($OUTPUT) && is_object($OUTPUT)) {
            $iconurl = $OUTPUT->image_url($iconpix)->out(false);
        }
        return [$filename, $iconurl];
    }

    /**
     * Parse ISO date/time (e.g., o:created / o:modified) to unix timestamp.
     * Returns 0 if input is empty or invalid.
     *
     * @param string|null $isodatetime
     * @return int
     */
    protected function parse_omeka_datetime($isodatetime): int {
        if (empty($isodatetime)) {
            return 0;
        }
        // Handle JSON-LD shapes like ['@value' => '...'] or ['value' => '...'].
        if (is_array($isodatetime)) {
            if (isset($isodatetime['@value']) && is_scalar($isodatetime['@value'])) {
                $isodatetime = (string)$isodatetime['@value'];
            } else if (isset($isodatetime['value']) && is_scalar($isodatetime['value'])) {
                $isodatetime = (string)$isodatetime['value'];
            } else if (isset($isodatetime['time']) && is_scalar($isodatetime['time'])) {
                $isodatetime = (string)$isodatetime['time'];
            } else {
                return 0;
            }
        }
        if (!is_string($isodatetime)) {
            return 0;
        }
        $t = @strtotime($isodatetime);
        return $t ? (int)$t : 0;
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
            // Root: if a specific site is configured, list items from that site.
            $siteid = (int)$this->get_option('siteid');
            if ($siteid) {
                // Use search with empty text to fetch items, respecting site_id filter.
                return $this->search("", $page, null);
            }
            // Otherwise, list item sets.
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
        global $OUTPUT;
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
            if (function_exists('connection_aborted') && connection_aborted()) {
                break;
            }
            $title = $item['o:title'] ?? ('Item ' . ($item['o:id'] ?? ''));

            // Hybrid mode: folder vs direct file based on accepted media count.
            $mediarefs = is_array($item['o:media'] ?? null) ? $item['o:media'] : [];
            $mediacount = count($mediarefs);
            if ($mediacount === 0) {
                continue; // Hide.
            }

            $fallbackthumb = '';
            if (!empty($item['thumbnail_display_urls']['square'])) {
                $fallbackthumb = $item['thumbnail_display_urls']['square'];
            } else if (!empty($item['thumbnail_display_urls']['medium'])) {
                $fallbackthumb = $item['thumbnail_display_urls']['medium'];
            }

            if ($mediacount === 1) {
                $mid = (int)($mediarefs[0]['o:id'] ?? 0);
                if (!$mid) {
                    continue;
                }
                $m = $this->api_request('/api/media/' . $mid);
                if (!$m) {
                    continue;
                }
                $entry = $this->build_entry_from_media_in_item($item, $m, $title, $fallbackthumb);
                if ($entry) {
                    $list[] = $entry;
                }
                continue;
            }

            $media = $this->api_request('/api/media', [ 'item_id' => (int)$item['o:id'], 'per_page' => 100 ]);
            $accepted = [];
            foreach (is_array($media) ? $media : [] as $m) {
                $fileurl = $m['o:original_url'] ?? ($m['o:source'] ?? '');
                if ($fileurl === '') {
                    continue;
                }
                $filename = basename((string)parse_url($fileurl, PHP_URL_PATH));
                $mimetype = isset($m['o:media_type']) ? (string)$m['o:media_type'] : '';
                if ($mimetype === '') {
                    $mimetype = $this->guess_mimetype_from_url($fileurl);
                }
                if ($this->is_allowed_by_instance_types($filename, $mimetype)) {
                    $accepted[] = $m;
                }
            }

            $ac = count($accepted);
            if ($ac === 0) {
                continue;
            } else if ($ac === 1) {
                $m = $accepted[0];
                $entry = $this->build_entry_from_media_in_item($item, $m, $title, $fallbackthumb);
                if ($entry) {
                    $list[] = $entry;
                }
                continue;
            }

            // More than one accepted → folder entry.
            $thumb = $fallbackthumb;
            if ($thumb === '' && !empty($accepted)) {
                $thumb = $accepted[0]['thumbnail_display_urls']['square']
                    ?? ($accepted[0]['thumbnail_display_urls']['medium'] ?? '');
            }
            $iconpix = 'f/folder';
            $iconurl = isset($OUTPUT) && is_object($OUTPUT) ? $OUTPUT->image_url($iconpix)->out(false) : '';
            $list[] = [
            'title' => $title,
            'path' => base64_encode('item:' . (string)$item['o:id']),
            'children' => [],
            'thumbnail' => $thumb,
            'thumbnail_height' => 90,
            'thumbnail_width' => 90,
            'icon' => $iconurl,
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
        global $OUTPUT;
        $perpage = 20;
        $params = [
        'page' => $page + 1,
        'per_page' => $perpage,
        'item_id' => $itemid,
        ];

        // Fetch item once to reuse its metadata (author/license) for all media.
        $item = $this->api_request('/api/items/' . $itemid);
        $itemtitle = $item['o:title'] ?? ('Item ' . $itemid);
        $itemsetid = $item['o:item_set'][0]['o:id'] ?? null;
        $defaultauthor = $this->omeka_first_prop_text($item, ['dcterms:creator', 'dcterms:contributor', 'dcterms:publisher']);
        $defaultlicense = $this->omeka_first_prop_text($item, ['dcterms:license', 'schema:license', 'dcterms:rights']);

        $media = $this->api_request('/api/media', $params);
        $total = (int)($this->get_header_value('Omeka-S-Total-Results') ?? 0);

        $list = [];
        foreach (is_array($media) ? $media : [] as $m) {
            if (function_exists('connection_aborted') && connection_aborted()) {
                break;
            }
            $title = $m['o:title'] ?? ('Media ' . $m['o:id']);
            $fileurl = $m['o:original_url'] ?? $m['o:source'] ?? '';
            if (!$fileurl) {
                continue;
            }
            $thumb = $m['thumbnail_display_urls']['square']
                ?? ($m['thumbnail_display_urls']['medium'] ?? '');
            // Compute provisional filename/mimetype to apply instance filter early when possible.
            $provisionalfilename = basename((string)parse_url($fileurl, PHP_URL_PATH));
            $provisionalmimetype = isset($m['o:media_type']) ? (string)$m['o:media_type'] : '';
            if ($provisionalmimetype === '') {
                $provisionalmimetype = $this->guess_mimetype_from_url($fileurl);
            }
            if (!$this->is_allowed_by_instance_types($provisionalfilename, $provisionalmimetype)) {
                continue;
            }
            $datecreated = $this->parse_omeka_datetime($m['o:created'] ?? null);
            if (!$datecreated) {
                $datecreated = $this->omeka_first_date_ts($m);
            }
            if (!$datecreated) {
                $datecreated = $this->parse_omeka_datetime($item['o:created'] ?? null);
            }
            $datemodified = $this->parse_omeka_datetime($m['o:modified'] ?? null);
            if (!$datemodified) {
                $datemodified = $this->omeka_first_date_ts($m);
            }
            if (!$datemodified) {
                $datemodified = $this->parse_omeka_datetime($item['o:modified'] ?? null);
                if (!$datemodified) {
                    $datemodified = $this->omeka_first_date_ts($item);
                }
            }
            $mimetype = isset($m['o:media_type']) ? (string)$m['o:media_type'] : '';
            if ($mimetype === '') {
                $mimetype = $this->guess_mimetype_from_url($fileurl);
            }
            $size = isset($m['o:size']) ? (int)$m['o:size'] : 0;
            if ($size <= 0) {
                $size = $this->resolve_filesize_from_url($fileurl);
            }
            if ($size <= 0) {
                $extent = $this->omeka_first_prop_text($m, ['dcterms:extent', 'schema:contentSize']);
                if ($extent !== '') {
                    $parsed = $this->parse_human_size_to_bytes($extent);
                    if ($parsed > 0) {
                        $size = $parsed;
                    }
                }
            }

            // Prefer media-provided metadata; fall back to item-level values.
            $author = $this->omeka_first_prop_text($m, ['dcterms:creator', 'dcterms:contributor', 'dcterms:publisher']);
            if ($author === '') {
                $author = $defaultauthor;
            }
            $license = $this->omeka_first_prop_text($m, ['dcterms:license', 'schema:license', 'dcterms:rights']);
            if ($license === '') {
                $license = $defaultlicense;
            }
            // Map to Moodle license shortname when possible.
            [$licshort, $licurl] = $this->map_license_to_moodle((string)$license);
            $license = $licshort !== 'unknown' ? $licshort : (string)$license;

            [$filename, $iconurl] = $this->compute_filename_and_icon($fileurl, $mimetype);

            $entry = [
                'title' => $title,
                'source' => $fileurl,          // This makes it a "file", ready to pick.
                'filename' => $filename,
                'icon' => $iconurl,
                'thumbnail' => $thumb,
                'thumbnail_height' => 90,
                'thumbnail_width' => 90,
                // Metadata displayed by Moodle file picker.
                'author' => $author,
                'license' => $license,
                'licenseurl' => $licurl,
                'mimetype' => $mimetype,
                'size' => $size,
                'filesize' => $size,
                'date' => $datemodified ?: $datecreated,
                'datecreated' => $datecreated,
                'datemodified' => $datemodified,
            ];
            $list[] = $entry;
        }

        // Breadcrumbs.
        $path = [
        [ 'name' => get_string('pluginname', 'repository_omeka'), 'path' => '' ],
        ];
        if ($itemsetid) {
            $itemset = $this->api_request('/api/item_sets/' . $itemsetid);
            $settitle = $itemset['o:title'] ?? ('Item set ' . $itemsetid);
            $path[] = [ 'name' => $settitle, 'path' => base64_encode('set:' . (string)$itemsetid) ];
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
     * @param string $searchtext Search expression.
     * @param int $page Page number (0-based).
     * @param int|null $itemsetid Optional item set filter.
     * @return array
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
            // Allow queries like "dcterms:title: foo" to map to a property filter.
            if (preg_match('/^([a-z0-9:_-]+)\\s*:\\s*(.+)$/i', $searchtext, $m)) {
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
        global $OUTPUT;
        $list = [];
        foreach ($items as $item) {
            if (function_exists('connection_aborted') && connection_aborted()) {
                break;
            }
            $title = $item['o:title'] ?? ('Item ' . ($item['o:id'] ?? ''));
            $mediaid = $item['o:primary_media']['o:id'] ?? ($item['o:media'][0]['o:id'] ?? null);
            if (!$mediaid) {
                continue;
            }
            $mediainfo = $this->api_request('/api/media/' . $mediaid);
            if (!$mediainfo) {
                continue;
            }
            $fileurl = $mediainfo['o:original_url'] ?? ($mediainfo['o:source'] ?? '');
            if (!$fileurl) {
                continue;
            }
            // Provisional filter by instance accepted types.
            $provisionalfilename = basename((string)parse_url($fileurl, PHP_URL_PATH));
            $provisionalmimetype = isset($mediainfo['o:media_type']) ? (string)$mediainfo['o:media_type'] : '';
            if ($provisionalmimetype === '') {
                $provisionalmimetype = $this->guess_mimetype_from_url($fileurl);
            }
            if (!$this->is_allowed_by_instance_types($provisionalfilename, $provisionalmimetype)) {
                continue;
            }
            $thumb = $mediainfo['thumbnail_display_urls']['square']
                ?? ($mediainfo['thumbnail_display_urls']['medium'] ?? '');
            // Item-level metadata (author/license) live on the item; file-level on media.
            $author = $this->omeka_first_prop_text($item, ['dcterms:creator', 'dcterms:contributor', 'dcterms:publisher']);
            $licval = $this->omeka_first_prop_text($item, ['dcterms:license', 'schema:license', 'dcterms:rights']);
            [$licshort, $licurl] = $this->map_license_to_moodle((string)$licval);
            $license = $licshort !== 'unknown' ? $licshort : (string)$licval;

            $datecreated = $this->parse_omeka_datetime($mediainfo['o:created'] ?? null);
            if (!$datecreated) {
                $datecreated = $this->omeka_first_date_ts($mediainfo);
            }
            if (!$datecreated) {
                $datecreated = $this->parse_omeka_datetime($item['o:created'] ?? null);
                if (!$datecreated) {
                    $datecreated = $this->omeka_first_date_ts($item);
                }
            }
            $datemodified = $this->parse_omeka_datetime($mediainfo['o:modified'] ?? null);
            if (!$datemodified) {
                $datemodified = $this->omeka_first_date_ts($mediainfo);
            }
            if (!$datemodified) {
                $datemodified = $this->parse_omeka_datetime($item['o:modified'] ?? null);
                if (!$datemodified) {
                    $datemodified = $this->omeka_first_date_ts($item);
                }
            }
            $mimetype = isset($mediainfo['o:media_type']) ? (string)$mediainfo['o:media_type'] : '';
            if ($mimetype === '') {
                $mimetype = $this->guess_mimetype_from_url($fileurl);
            }
            // Keep listing fast: use provided size only; avoid probing URLs here.
            $size = isset($mediainfo['o:size']) ? (int)$mediainfo['o:size'] : 0;

            [$filename, $iconurl] = $this->compute_filename_and_icon($fileurl, $mimetype);

            // Apply filter as late as well, using final filename/mimetype values.
            if (!$this->is_allowed_by_instance_types($filename, $mimetype)) {
                continue;
            }

            $list[] = [
                'title' => $title,
                'source' => $fileurl,
                'filename' => $filename,
                'icon' => $iconurl,
                'thumbnail' => $thumb,
                'thumbnail_height' => 90,
                'thumbnail_width' => 90,
                // Metadata displayed by Moodle file picker.
                'author' => $author,
                'license' => $license,
                'licenseurl' => $licurl,
                'mimetype' => $mimetype,
                'size' => $size,
                'filesize' => $size,
                'date' => $datemodified ?: $datecreated,
                'datecreated' => $datecreated,
                'datemodified' => $datemodified,
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
        // Permite todos los tipos; el filtrado fino se hace por instancia (acceptedtypes).
        return '*';
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

        // 1) Base URL (required).
        $mform->addElement('text', 'baseurl', get_string('baseurl', 'repository_omeka'));
        $mform->addRule('baseurl', get_string('required'), 'required', null, 'client');
        $mform->setType('baseurl', PARAM_URL);

        // 2) API key identity (optional).
        $mform->addElement('text', 'keyidentity', get_string('keyidentity', 'repository_omeka'));
        $mform->setType('keyidentity', PARAM_TEXT);
        $mform->addHelpButton('keyidentity', 'keyidentity', 'repository_omeka');

        // 3) API key credential (optional).
        $mform->addElement('text', 'keycredential', get_string('keycredential', 'repository_omeka'));
        $mform->setType('keycredential', PARAM_TEXT);
        $mform->addHelpButton('keycredential', 'keycredential', 'repository_omeka');

        // Compute existing values to pre-load the site select.
        $baseurl = optional_param('baseurl', '', PARAM_URL);
        $keyidentity = optional_param('keyidentity', '', PARAM_RAW);
        $keycredential = optional_param('keycredential', '', PARAM_RAW);
        // In repositoryinstance.php, the instance id is passed as 'edit'.
        $instanceid = optional_param('edit', 0, PARAM_INT);
        if (!$instanceid) {
            // Fallback for safety if some flows still pass 'id'.
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

        // 4) Optional Omeka-S site selector (populated via WS).
        $sites = self::fetch_sites($baseurl, $keyidentity, $keycredential);
        // Always include an 'All' option (localized) to allow selecting no site (use all public content).
        $alllabel = get_string('all');
        $siteoptions = ['' => $alllabel] + ($sites ?: []);
        $mform->addElement('select', 'siteid', get_string('site', 'repository_omeka'), $siteoptions);
        $mform->setType('siteid', PARAM_INT);
        $mform->addHelpButton('siteid', 'site', 'repository_omeka');
        $mform->disabledIf('siteid', 'baseurl', 'eq', '');
        if ($currentsiteid) {
            $mform->setDefault('siteid', $currentsiteid);
        }

        // Hidden fields to persist selected site's label and slug for admin listing/summary.
        $mform->addElement('hidden', 'sitelabel');
        $mform->setType('sitelabel', PARAM_TEXT);
        $mform->addElement('hidden', 'siteslug');
        $mform->setType('siteslug', PARAM_ALPHANUMEXT);

        // If we have no stored label/slug but have baseurl + siteid, try to fetch them for defaults.
        if ($currentsitelabel === '' && $currentsiteid && $baseurl) {
            $details = self::fetch_site_details(
                $baseurl,
                (string)$keyidentity,
                (string)$keycredential,
                (int)$currentsiteid
            );
            if (!empty($details)) {
                $currentsitelabel = $details['title'] ?? '';
                $currentsiteslug = $details['slug'] ?? '';
            }
        }
        if ($currentsitelabel !== '') {
            $mform->setDefault('sitelabel', $currentsitelabel);
        }
        if ($currentsiteslug !== '') {
            $mform->setDefault('siteslug', $currentsiteslug);
        }

        // 5) Optional accepted file types (uses Moodle core filetypes element).
        if (class_exists('core_form\filetypes_util')) {
            $mform->addElement('filetypes', 'acceptedtypes', 'Accepted file types');
            // Help removed.
        } else {
            // Fallback as plain text list if the element is not available.
            $mform->addElement('text', 'acceptedtypes', 'Accepted file types');
            $mform->setType('acceptedtypes', PARAM_RAW_TRIMMED);
            // Help removed.
        }

        // Init AMD to live-refresh the site select via AJAX (on load and on URL changes),
        // passing the localized label for the empty option.
        $PAGE->requires->js_call_amd('repository_omeka/omekasites', 'init', [$alllabel]);

        // Ensure defaults are set so fields are populated on edit and not disabled incorrectly.
        if ($baseurl) {
            $mform->setDefault('baseurl', $baseurl);
        }
        if ($keyidentity !== '') {
            $mform->setDefault('keyidentity', $keyidentity);
        }
        if ($keycredential !== '') {
            $mform->setDefault('keycredential', $keycredential);
        }
        if ($acceptedtypes !== '') {
            // The filetypes element also accepts a comma-separated string as default.
            $mform->setDefault('acceptedtypes', $acceptedtypes);
        }

    }


    /**
     * Save settings for repository instance.
     *
     * @param array $options settings
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
        // Normalise accepted types (string from filetypes element).
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
     * Fetch full sites info (id, title, slug, label) from Omeka-S.
     *
     * @param string $baseurl
     * @param string $keyidentity
     * @param string $keycredential
     * @return array[] Each: ['id'=>int, 'title'=>string, 'slug'=>string, 'label'=>string]
     */
    public static function fetch_sites_detailed(string $baseurl, string $keyidentity = '', string $keycredential = ''): array {
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
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $site) {
            if (!isset($site['o:id'])) {
                continue;
            }
            $id = (int)$site['o:id'];
            $title = (string)($site['o:title'] ?? ('Site ' . $id));
            $slug = (string)($site['o:slug'] ?? '');
            $label = $title . ($slug !== '' ? " ({$slug})" : '');
            $out[] = [ 'id' => $id, 'title' => $title, 'slug' => $slug, 'label' => $label ];
        }
        return $out;
    }

    /**
     * Fetch a single site's details (title, slug) from Omeka-S.
     *
     * @param string $baseurl
     * @param string $keyidentity
     * @param string $keycredential
     * @param int $siteid
     * @return array ['title'=>string, 'slug'=>string] or empty array on failure
     */
    public static function fetch_site_details(string $baseurl, string $keyidentity, string $keycredential, int $siteid): array {
        $baseurl = rtrim($baseurl, '/');
        if (!$baseurl || !$siteid) {
            return [];
        }
        $params = self::build_site_params($keyidentity, $keycredential);
        $url = $baseurl . '/api/sites/' . $siteid . (!empty($params) ? ('?' . http_build_query($params)) : '');
        $content = @file_get_contents($url);
        if ($content === false) {
            return [];
        }
        $site = json_decode($content, true);
        if (!is_array($site)) {
            return [];
        }
        return [
            'title' => (string)($site['o:title'] ?? ''),
            'slug' => (string)($site['o:slug'] ?? ''),
        ];
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

        // Safety: always apply site filter to items endpoint when instance has a site selected.
        if ($path === '/api/items') {
            $siteid = (int)$this->get_option('siteid');
            if ($siteid && !isset($params['site_id'])) {
                $params['site_id'] = $siteid;
            }
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
                'timeout' => 6,
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

// PHPUnit-only hooks to expose selected helpers for testing.
if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
    /**
     * Test-only hooks for exposing selected helpers without touching core repository internals.
     *
     * @package   repository_omeka
     * @category  test
     */
    class repository_omeka_testhooks extends repository_omeka {
        /**
         * Lightweight constructor for tests (does not call parent).
         *
         * @param int $repositoryid Unused in tests.
         * @param \context|null $context Context or system context.
         * @param array $options Options to simulate instance options.
         * @param bool $readonly Unused.
         * @param bool $ajax Unused.
         */
        public function __construct($repositoryid = 0, $context = null, array $options = [], $readonly = false, $ajax = false) {
            // Do NOT call parent constructor to avoid touching Moodle core repository internals in tests.
            $this->context = $context ?: \context_system::instance();
            $this->options = $options;
        }
        /**
         * Simple options accessor for tests only.
         *
         * @param string $config Option name or empty to get all.
         * @return mixed
         */
        public function get_option($config = '') {
            if ($config === '' || $config === null) {
                return $this->options ?? [];
            }
            return $this->options[$config] ?? null;
        }
        // phpcs:disable Generic.CodeAnalysis.UselessOverridingMethod.Found
        /**
         * Expose parse_human_size_to_bytes for tests.
         *
         * @param string $text Human-readable size text.
         * @return int Bytes.
         */
        public function parse_human_size_to_bytes(string $text): int {
            return parent::parse_human_size_to_bytes($text);
        }
        // phpcs:enable Generic.CodeAnalysis.UselessOverridingMethod.Found
        // phpcs:disable Generic.CodeAnalysis.UselessOverridingMethod.Found
        /**
         * Expose guess_mimetype_from_url for tests.
         *
         * @param string $url URL to inspect.
         * @return string Mimetype or empty.
         */
        public function guess_mimetype_from_url(string $url): string {
            return parent::guess_mimetype_from_url($url);
        }
        // phpcs:enable Generic.CodeAnalysis.UselessOverridingMethod.Found
        // phpcs:disable Generic.CodeAnalysis.UselessOverridingMethod.Found
        /**
         * Expose map_license_to_moodle for tests.
         *
         * @param string $value License text or URL.
         * @return array Two elements: [shortname, url].
         */
        public function map_license_to_moodle(string $value): array {
            return parent::map_license_to_moodle($value);
        }
        // phpcs:enable Generic.CodeAnalysis.UselessOverridingMethod.Found
        // phpcs:disable Generic.CodeAnalysis.UselessOverridingMethod.Found
        /**
         * Expose is_allowed_by_instance_types for tests.
         *
         * @param string $filename Filename.
         * @param string $mimetype Mimetype.
         * @return bool Whether allowed.
         */
        public function is_allowed_by_instance_types(string $filename, string $mimetype): bool {
            return parent::is_allowed_by_instance_types($filename, $mimetype);
        }
        // phpcs:enable Generic.CodeAnalysis.UselessOverridingMethod.Found
    }
}
