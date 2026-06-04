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
 * Format and size helpers.
 *
 * @package   repository_omeka
 * @copyright 2026 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_omeka\local;


/**
 * Stateless helpers to parse human-readable sizes and guess mime types.
 */
class format_helper {
    /**
     * Parse human-readable size (e.g. "12 MB", "1.2 MiB") into bytes.
     *
     * @param string $text Source text.
     * @return int|null Bytes or null when the text cannot be parsed.
     */
    public static function parse_human_size(string $text): ?int {
        $clean = trim($text);
        if ($clean === '') {
            return null;
        }
        $clean = str_replace(',', '.', $clean);
        if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([kmgtp]?i?b)?$/i', $clean, $matches)) {
            return null;
        }
        $num = (float)$matches[1];
        $unit = strtolower($matches[2] ?? '');
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
                break;
        }
        if ($unit === 'kb') {
            $base = 1024;
        } else {
            $base = (strpos($unit, 'i') !== false) ? 1024 : 1000;
        }
        return (int)round($num * pow($base, $pow));
    }

    /**
     * Guess a mimetype from a filename or URL extension.
     *
     * @param string $filename Filename or URL.
     * @return string|null Mime type or null when unknown.
     */
    public static function guess_mimetype_from_filename(string $filename): ?string {
        $path = parse_url($filename, PHP_URL_PATH);
        $path = $path !== false && $path !== null ? $path : $filename;
        $ext = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
        if ($ext === '') {
            return null;
        }
        $map = self::extension_map();
        return $map[$ext] ?? null;
    }

    /**
     * Resolve the size of a remote file via HTTP HEAD using Moodle's curl wrapper.
     *
     * @param string $url Absolute URL.
     * @param \curl $curl Moodle curl instance.
     * @return int|null Bytes or null when unknown.
     */
    public static function resolve_filesize(string $url, \curl $curl): ?int {
        $url = trim($url);
        // Only probe plain http(s) URLs and do not follow redirects: this helper
        // runs against untrusted Omeka media URLs, so a 30x could otherwise turn
        // it into an SSRF probe of an internal host.
        if ($url === '' || !ingester_classifier::is_http_url($url)) {
            return null;
        }
        $curl->head($url, [
            'CURLOPT_NOBODY' => 1,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_TIMEOUT' => 5,
        ]);
        $info = method_exists($curl, 'get_info') ? $curl->get_info() : [];
        $size = isset($info['download_content_length']) ? (int)$info['download_content_length'] : 0;
        return $size > 0 ? $size : null;
    }

    /**
     * Static extension to mimetype map used by guess_mimetype_from_filename().
     *
     * @return array<string,string>
     */
    private static function extension_map(): array {
        return [
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
    }
}
