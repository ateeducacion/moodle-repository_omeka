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
     * Returns one of two entry shapes depending on the media's ingester:
     *  - For binary media (Omeka-S filestore: `upload`, `file_sideload`, ...)
     *    `source` is the `o:original_url` and the picker hands the bytes to
     *    {@see \repository_omeka::get_file()} for FILE_INTERNAL storage.
     *  - For linked media (oEmbed, IIIF, url, html ingesters) `source` is the
     *    canonical external URL prefixed with the "linked:" marker; the
     *    repository advertises FILE_EXTERNAL so the picker stores a URL
     *    reference and never tries to copy the bytes through Moodle.
     *
     * @param array $item Parent item (may be empty).
     * @param array $media Media resource.
     * @param filetype_filter $filter Filter applied to filenames/mimetypes.
     * @return array|null Entry array or null when not allowed / missing url.
     */
    public static function build_media_entry(array $item, array $media, filetype_filter $filter): ?array {
        $kind = ingester_classifier::classify_media($media);
        $islinked = $kind === ingester_classifier::TYPE_LINKED;

        if ($islinked) {
            $fileurl = ingester_classifier::extract_linked_url($media);
            // A linked entry becomes a FILE_EXTERNAL hyperlink stored by Moodle;
            // a compromised Omeka could supply a javascript:/data: URL (stored
            // XSS). Only accept plain http(s) external links.
            if (!ingester_classifier::is_http_url((string)$fileurl)) {
                return null;
            }
        } else {
            $fileurl = $media['o:original_url'] ?? ($media['o:source'] ?? '');
        }
        if ($fileurl === '') {
            return null;
        }
        $filename = basename((string)parse_url((string)$fileurl, PHP_URL_PATH));
        $mimetype = isset($media['o:media_type']) ? (string)$media['o:media_type'] : '';
        if ($mimetype === '') {
            $mimetype = (string)(format_helper::guess_mimetype_from_filename((string)$fileurl) ?? '');
        }
        // For linked entries the filename/extension is rarely meaningful (e.g.
        // a YouTube iframe URL has no extension) so we keep the instance's
        // filetype filter limited to binary media — otherwise linked media
        // would always fail the extension check and be hidden from the picker.
        if (!$islinked && !$filter->is_allowed($filename, $mimetype)) {
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

        $sourcevalue = $islinked ? ingester_classifier::mark_linked((string)$fileurl) : (string)$fileurl;
        // Linked media (oEmbed, IIIF, url, html) often have a generic or empty
        // filename in their external URL; fall back to a synthetic one so the
        // file picker has something meaningful to show.
        if ($filename === '') {
            $filename = $islinked
                ? ($title !== '' ? clean_param((string)$title, PARAM_FILE) : 'link-' . ($media['o:id'] ?? 'x'))
                : 'file-' . ($media['o:id'] ?? 'x');
        }
        $entry = [
            'title' => $title,
            'source' => $sourcevalue,
            'filename' => $filename,
            'icon' => self::icon_url($filename, $mimetype),
            'thumbnail' => $thumb,
            'thumbnail_height' => 90,
            'thumbnail_width' => 90,
            'author' => $author,
            'license' => $license,
            'licenseurl' => $licurl,
            'mimetype' => $mimetype,
            'date' => $datemodified ?: ($datecreated ?: 0),
            'datecreated' => $datecreated ?: 0,
            'datemodified' => $datemodified ?: 0,
        ];
        // Omeka-S returns o:size = null for media ingested by URL (e.g. Wikimedia
        // images, oEmbed), so we only advertise size when it is actually known —
        // otherwise the file picker would render a misleading "0 bytes".
        if ($size > 0) {
            $entry['size'] = $size;
            $entry['filesize'] = $size;
        }
        return $entry;
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
