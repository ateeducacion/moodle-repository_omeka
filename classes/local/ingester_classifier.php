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
 * Classify Omeka-S media by ingester to decide binary vs. linked entries.
 *
 * @package   repository_omeka
 * @copyright 2026 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_omeka\local;


/**
 * Stateless helpers to classify Omeka-S media into "binary" (downloadable
 * file in Omeka's filestore) vs. "linked" (external resource: oEmbed video,
 * IIIF manifest, URL ingest, HTML embed).
 *
 * Used by {@see entry_factory} to mark linked entries so that the file
 * picker offers them through FILE_EXTERNAL rather than trying to copy
 * non-binary bytes (which would otherwise hit Moodle's get_file() and
 * fail with apierror "0 bytes / invalid JSON").
 */
class ingester_classifier {
    /** @var string Source-value prefix used by {@see entry_factory} to mark
     *              linked entries so that {@see \repository_omeka::get_file()}
     *              and {@see \repository_omeka::get_link()} can recognise them
     *              after the file picker round-trips the source through the
     *              browser. */
    const LINKED_SOURCE_PREFIX = 'linked:';

    /** @var string Result of {@see classify_media()} for downloadable media. */
    const TYPE_BINARY = 'binary';

    /** @var string Result of {@see classify_media()} for linked external media. */
    const TYPE_LINKED = 'linked';

    /**
     * Ingesters that store a binary file in Omeka-S's own filestore and
     * therefore expose an `o:original_url` we can hot-download.
     *
     * @var string[]
     */
    const BINARY_INGESTERS = ['upload', 'file', 'fileSideload', 'file_sideload'];

    /**
     * Ingesters that link to an external resource (no binary in Omeka-S):
     * `oembed` (YouTube, Vimeo, SlideShare, ...), `iiif`/`iiif_presentation`
     * (IIIF image API / presentation manifest), `url` (catch-all URL ingest),
     * `html` (inline HTML snippet).
     *
     * @var string[]
     */
    const LINKED_INGESTERS = [
        'oembed', 'oEmbed',
        'iiif', 'iiif_presentation', 'IIIF', 'IIIFPresentation',
        'url',
        'html', 'HTML',
        'youtube',
    ];

    /**
     * MIME types that always identify a downloadable binary resource,
     * regardless of which Omeka-S ingester surfaced it. Used by
     * {@see classify_media()} to override the ingester-based heuristic.
     *
     * @var string[]
     */
    const BINARY_MIME_TYPES = [
        'application/pdf',
        'application/octet-stream',
        'application/zip',
        'application/x-zip-compressed',
        'application/gzip',
        'application/x-gzip',
        'application/x-tar',
        'application/x-7z-compressed',
        'application/x-rar-compressed',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'application/rtf',
        'application/epub+zip',
        'application/x-mobipocket-ebook',
    ];

    /**
     * Classify a media JSON resource as either binary or linked.
     *
     * The decision is MIME-type-first because Omeka's `url` ingester is a
     * catch-all: it can wrap a Wikimedia .png (real binary, fully
     * downloadable from `o:original_url`) just as easily as a Slideshare
     * embed page. The `o:ingester` field alone is therefore not a reliable
     * signal — `image/png` is binary whether it came from `upload`, `url`,
     * `fileSideload` or anything else.
     *
     * Order of decision:
     *  1. `o:media_type` matches a known binary content-type (image/*,
     *     video/*, audio/*, application/pdf, office docs, archives…)
     *     → BINARY.
     *  2. `o:ingester` is in {@see LINKED_INGESTERS} (oEmbed, IIIF, html…)
     *     and the MIME check above did not catch it → LINKED.
     *  3. `o:ingester` is in {@see BINARY_INGESTERS} (upload, fileSideload)
     *     → BINARY.
     *  4. Unknown ingester: trust the filestore — if `o:original_url` is
     *     non-empty Omeka has the bytes, otherwise it is a bare reference.
     *
     * @param array $media Media JSON resource as returned by /api/media.
     * @return string Either {@see TYPE_BINARY} or {@see TYPE_LINKED}.
     */
    public static function classify_media(array $media): string {
        $mime = isset($media['o:media_type']) ? strtolower(trim((string)$media['o:media_type'])) : '';
        if (self::is_binary_mime($mime)) {
            return self::TYPE_BINARY;
        }

        $ingestercmp = isset($media['o:ingester']) ? strtolower((string)$media['o:ingester']) : '';

        // Linked ingesters never expose a downloadable original URL.
        foreach (self::LINKED_INGESTERS as $known) {
            if ($ingestercmp === strtolower($known)) {
                return self::TYPE_LINKED;
            }
        }

        // Binary ingesters (upload, fileSideload) always do.
        foreach (self::BINARY_INGESTERS as $known) {
            if ($ingestercmp === strtolower($known)) {
                return self::TYPE_BINARY;
            }
        }

        // Unknown ingester: assume binary when Omeka-S advertises an original
        // file URL (the filestore is the source of truth); otherwise treat the
        // media as linked so we do not try to download a non-binary body.
        $originalurl = isset($media['o:original_url']) ? trim((string)$media['o:original_url']) : '';
        return $originalurl !== '' ? self::TYPE_BINARY : self::TYPE_LINKED;
    }

    /**
     * Whether a MIME type identifies a downloadable binary resource.
     *
     * Recognises image/video/audio top-level types plus a curated list of
     * document/archive types (PDFs, Office, OpenDocument, ZIP…).
     *
     * @param string $mime Lowercased MIME type (may be empty).
     * @return bool
     */
    private static function is_binary_mime(string $mime): bool {
        if ($mime === '') {
            return false;
        }
        if (
            strncmp($mime, 'image/', 6) === 0
            || strncmp($mime, 'video/', 6) === 0
            || strncmp($mime, 'audio/', 6) === 0
            || strncmp($mime, 'font/', 5) === 0
        ) {
            return true;
        }
        return in_array($mime, self::BINARY_MIME_TYPES, true);
    }

    /**
     * Extract the canonical external URL of a linked media.
     *
     * Tries, in order:
     *  - `data.html` parsed for the first iframe `src` (oEmbed responses).
     *  - `data.embed_url` (oEmbed responses that already extracted the iframe).
     *  - `data.url` or `data.@id` (IIIF manifests sometimes inline the URL).
     *  - `o:source` (the user-entered original URL — always present for the
     *    `url`, `iiif`, `iiif_presentation` ingesters).
     *  - `o:original_url` (last-resort fallback).
     *
     * @param array $media Media JSON resource.
     * @return string External URL, or '' when nothing usable was found.
     */
    public static function extract_linked_url(array $media): string {
        $data = isset($media['data']) && is_array($media['data']) ? $media['data'] : [];

        if (isset($data['html']) && is_string($data['html']) && $data['html'] !== '') {
            $iframesrc = self::extract_iframe_src($data['html']);
            if ($iframesrc !== '') {
                return $iframesrc;
            }
        }

        foreach (['embed_url', 'url', '@id'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        if (isset($media['o:source']) && is_string($media['o:source']) && $media['o:source'] !== '') {
            return $media['o:source'];
        }

        if (isset($media['o:original_url']) && is_string($media['o:original_url']) && $media['o:original_url'] !== '') {
            return $media['o:original_url'];
        }

        return '';
    }

    /**
     * Prefix a URL with the "linked:" marker used by
     * {@see \repository_omeka::get_file()} / {@see \repository_omeka::get_link()}
     * to recognise linked entries when the picker hands the source back.
     *
     * Idempotent: re-prefixing an already-prefixed value returns it unchanged.
     *
     * @param string $url External URL.
     * @return string Marked source value.
     */
    public static function mark_linked(string $url): string {
        if ($url === '') {
            return '';
        }
        if (self::is_linked($url)) {
            return $url;
        }
        return self::LINKED_SOURCE_PREFIX . $url;
    }

    /**
     * Whether the given source value carries the "linked:" marker.
     *
     * @param string $source Source value from a file picker entry.
     * @return bool
     */
    public static function is_linked(string $source): bool {
        return strncmp($source, self::LINKED_SOURCE_PREFIX, strlen(self::LINKED_SOURCE_PREFIX)) === 0;
    }

    /**
     * Strip the "linked:" marker from a source value.
     *
     * Safe to call on non-marked sources; returns the value unchanged.
     *
     * @param string $source Source value.
     * @return string URL without the marker.
     */
    public static function strip_marker(string $source): string {
        if (!self::is_linked($source)) {
            return $source;
        }
        return substr($source, strlen(self::LINKED_SOURCE_PREFIX));
    }

    /**
     * Whether a URL is a safe remote http(s) resource.
     *
     * Media and linked-media URLs come from untrusted Omeka content, so a
     * compromised instance could return a `javascript:` / `data:` URI (stored
     * XSS when Moodle later renders it as a hyperlink via
     * {@see \repository_omeka::get_link()}) or a `file://` URL (local file
     * disclosure when handed to curl in {@see \repository_omeka::get_file()}).
     * We only ever want plain http/https resources, so reject everything else.
     *
     * @param string $url Candidate URL.
     * @return bool True when the URL has an http/https scheme and a host.
     */
    public static function is_http_url(string $url): bool {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        return (string)parse_url($url, PHP_URL_HOST) !== '';
    }

    /**
     * Extract the first `src` attribute from an `<iframe>` tag in an HTML
     * snippet (typically the `data.html` field of an oEmbed media).
     *
     * Avoids loading a full DOM parser — these snippets are tiny one-iframe
     * fragments returned by oEmbed providers.
     *
     * @param string $html HTML snippet.
     * @return string The iframe src, or '' when not found.
     */
    private static function extract_iframe_src(string $html): string {
        if ($html === '' || stripos($html, '<iframe') === false) {
            return '';
        }
        if (preg_match('/<iframe\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/i', $html, $matches)) {
            $src = (string)$matches[2];
            // The oEmbed HTML may be HTML-encoded (e.g. "&amp;"); decode once.
            return html_entity_decode($src, ENT_QUOTES | ENT_HTML5);
        }
        return '';
    }
}
