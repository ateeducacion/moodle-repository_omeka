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
 * Factory for file picker entries.
 *
 * @package   repository_omeka
 * @copyright 2026 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_omeka\local;


/**
 * Build entries that Moodle's file picker expects from Omeka-S resources.
 */
class entry_factory {
    /**
     * Build an entry for a media (with parent item context).
     *
     * @param array $item Parent item (may be empty).
     * @param array $media Media resource.
     * @param filetype_filter $filter Filter applied to filenames/mimetypes.
     * @return array|null Entry array or null when not allowed / missing url.
     */
    public static function build_media_entry(array $item, array $media, filetype_filter $filter): ?array {
        $fileurl = $media['o:original_url'] ?? ($media['o:source'] ?? '');
        if ($fileurl === '') {
            return null;
        }
        $filename = basename((string)parse_url((string)$fileurl, PHP_URL_PATH));
        $mimetype = isset($media['o:media_type']) ? (string)$media['o:media_type'] : '';
        if ($mimetype === '') {
            $mimetype = (string)(format_helper::guess_mimetype_from_filename((string)$fileurl) ?? '');
        }
        if (!$filter->is_allowed($filename, $mimetype)) {
            return null;
        }

        $title = $media['o:title'] ?? null;
        if ($title === null || $title === '') {
            $title = $item['o:title'] ?? jsonld_parser::title($media);
        }
        if ($title === '' || $title === null) {
            $title = 'Media ' . ($media['o:id'] ?? '');
        }

        $thumb = $media['thumbnail_display_urls']['square']
            ?? ($media['thumbnail_display_urls']['medium']
            ?? ($item['thumbnail_display_urls']['square']
            ?? ($item['thumbnail_display_urls']['medium'] ?? '')));

        $author = jsonld_parser::author($media);
        if ($author === null || $author === '') {
            $author = jsonld_parser::author($item) ?? '';
        }
        $licvalue = jsonld_parser::first_property_text(
            $media,
            ['dcterms:license', 'schema:license', 'dcterms:rights']
        );
        if ($licvalue === null || $licvalue === '') {
            $licvalue = jsonld_parser::first_property_text(
                $item,
                ['dcterms:license', 'schema:license', 'dcterms:rights']
            ) ?? '';
        }
        [$licshort, $licurl] = license_mapper::map((string)$licvalue);
        $license = $licshort !== 'unknown' ? $licshort : (string)$licvalue;

        $datecreated = self::pick_timestamp([
            $media['o:created'] ?? null,
            $item['o:created'] ?? null,
        ]);
        if (!$datecreated) {
            $datecreated = jsonld_parser::first_date_timestamp($media);
        }
        $datemodified = self::pick_timestamp([
            $media['o:modified'] ?? null,
            $item['o:modified'] ?? null,
        ]);
        if (!$datemodified) {
            $datemodified = jsonld_parser::first_date_timestamp($media);
        }

        $size = isset($media['o:size']) ? (int)$media['o:size'] : 0;
        if ($size <= 0) {
            $extent = jsonld_parser::first_property_text($media, ['dcterms:extent', 'schema:contentSize']);
            if ($extent !== null) {
                $size = (int)(format_helper::parse_human_size($extent) ?? 0);
            }
        }

        return [
            'title' => $title,
            'source' => $fileurl,
            'filename' => $filename !== '' ? $filename : ('file-' . ($media['o:id'] ?? 'x')),
            'icon' => self::icon_url($filename, $mimetype),
            'thumbnail' => $thumb,
            'thumbnail_height' => 90,
            'thumbnail_width' => 90,
            'author' => $author,
            'license' => $license,
            'licenseurl' => $licurl,
            'mimetype' => $mimetype,
            'size' => $size,
            'filesize' => $size,
            'date' => $datemodified ?: ($datecreated ?: 0),
            'datecreated' => $datecreated ?: 0,
            'datemodified' => $datemodified ?: 0,
        ];
    }

    /**
     * Select the first non-empty timestamp from a list of date-like values.
     *
     * @param array $candidates Candidates (strings, arrays or null).
     * @return int|null Timestamp or null when no candidate could be parsed.
     */
    private static function pick_timestamp(array $candidates): ?int {
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            if (is_array($candidate)) {
                $candidate = $candidate['@value'] ?? ($candidate['value'] ?? ($candidate['time'] ?? null));
            }
            if (!is_string($candidate)) {
                continue;
            }
            $timestamp = jsonld_parser::parse_iso8601($candidate);
            if ($timestamp) {
                return $timestamp;
            }
        }
        return null;
    }

    /**
     * Resolve an icon URL for the file picker.
     *
     * @param string $filename Filename (may be empty).
     * @param string $mimetype Mimetype (may be empty).
     * @return string Icon URL or '' when Moodle output is unavailable.
     */
    private static function icon_url(string $filename, string $mimetype): string {
        global $OUTPUT;
        $iconpix = '';
        if ($filename !== '' && function_exists('file_extension_icon')) {
            $iconpix = (string)file_extension_icon($filename);
        }
        if ($iconpix === '' && $mimetype !== '' && function_exists('mimeinfo_from_type')) {
            $base = mimeinfo_from_type('icon', $mimetype);
            if (is_string($base) && $base !== '') {
                $iconpix = 'f/' . $base;
            }
        }
        if ($iconpix === '') {
            $iconpix = 'f/unknown';
        }
        if (isset($OUTPUT) && is_object($OUTPUT) && method_exists($OUTPUT, 'image_url')) {
            return $OUTPUT->image_url($iconpix)->out(false);
        }
        return '';
    }
}
