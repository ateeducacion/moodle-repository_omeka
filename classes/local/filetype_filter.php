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
 * Filetype filter for the Omeka-S repository instance.
 *
 * @package   repository_omeka
 * @copyright 2026 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_omeka\local;


/**
 * Decide whether a file is allowed for a repository instance.
 *
 * A filter holds one or more constraint sets. A file is allowed only when
 * every non-empty constraint set contains at least one token that matches.
 * This lets the plugin compose the admin-level instance allowlist
 * (`acceptedtypes`) with Moodle's per-pick `accepted_types` (the array the
 * file-picker forwards from the form that opened it) into a single immutable
 * filter via {@see self::intersect()}.
 */
class filetype_filter {
    /**
     * @var array<int, string[]> Stack of constraint sets. Each inner array is a
     *      lowercase token list with the same OR-of-tokens semantics as the
     *      original single-string filter.
     */
    private $constraints;

    /**
     * Constructor.
     *
     * @param string $acceptedtypes Comma/space separated tokens. Empty string allows everything.
     */
    public function __construct(string $acceptedtypes) {
        $this->constraints = [self::parse_tokens($acceptedtypes)];
    }

    /**
     * Return a new filter that ANDs an extra constraint set on top of this one.
     *
     * Used to combine the per-instance `acceptedtypes` admin setting with
     * Moodle's per-pick `accepted_types` array. The instance is not mutated
     * so callers can keep a cached base filter and derive narrowed copies.
     *
     * @param array $tokens Picker-style token list (extensions like `.jpg`, group
     *                      aliases like `web_image`, MIME types like `image/png`,
     *                      or MIME wildcards like `image/*`). Empty array, `['*']`,
     *                      or any list containing `'*'` is treated as
     *                      "no extra constraint" and returns the receiver unchanged.
     * @return self
     */
    public function intersect(array $tokens): self {
        $normalised = self::tokens_from_array($tokens);
        if (empty($normalised) || in_array('*', $normalised, true)) {
            return $this;
        }
        $clone = clone $this;
        $clone->constraints[] = $normalised;
        return $clone;
    }

    /**
     * Whether any constraint set has tokens configured.
     *
     * @return bool
     */
    public function is_empty(): bool {
        foreach ($this->constraints as $tokens) {
            if (!empty($tokens)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the given filename / mimetype pair is allowed by every constraint set.
     *
     * @param string $filename Filename, may be empty.
     * @param string|null $mimetype Mimetype, may be empty/null.
     * @return bool
     */
    public function is_allowed(string $filename, ?string $mimetype): bool {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mt = strtolower((string)$mimetype);
        foreach ($this->constraints as $tokens) {
            if (empty($tokens)) {
                continue;
            }
            if (!self::any_token_matches($tokens, $ext, $mt)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Parse the raw "acceptedtypes" string into normalised tokens.
     *
     * @param string $text Raw text.
     * @return string[]
     */
    public static function parse_tokens(string $text): array {
        $clean = trim($text);
        if ($clean === '') {
            return [];
        }
        $clean = str_replace([';', "\n", "\r"], ',', $clean);
        $parts = preg_split('/[\s,]+/', $clean, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $tokens[] = strtolower($part);
        }
        return array_values(array_unique($tokens));
    }

    /**
     * Normalise a Moodle picker-style token array.
     *
     * Moodle passes `accepted_types` as either the string `'*'` or an array of
     * strings; the array may contain Moodle group aliases, extensions
     * (with or without leading dot), MIME types, or MIME wildcards. We flatten
     * to lowercase tokens, then run through {@see self::parse_tokens()} so a
     * single string entry like `'image, .pdf'` is also accepted.
     *
     * @param array $tokens
     * @return string[]
     */
    public static function tokens_from_array(array $tokens): array {
        $merged = [];
        foreach ($tokens as $token) {
            if (!is_string($token)) {
                continue;
            }
            foreach (self::parse_tokens($token) as $normalised) {
                $merged[] = $normalised;
            }
        }
        return array_values(array_unique($merged));
    }

    /**
     * Match any token in a constraint set against an (ext, mimetype) pair.
     *
     * @param string[] $tokens Lowercase tokens.
     * @param string $ext Lowercase extension (no leading dot).
     * @param string $mt Lowercase mimetype.
     * @return bool
     */
    private static function any_token_matches(array $tokens, string $ext, string $mt): bool {
        foreach ($tokens as $token) {
            if ($token === '*') {
                return true;
            }
            if ($token[0] === '.') {
                if ($ext !== '' && $ext === substr($token, 1)) {
                    return true;
                }
                continue;
            }
            if (strpos($token, '/') !== false) {
                // MIME type or `prefix/*` wildcard.
                if (substr($token, -2) === '/*') {
                    $prefix = substr($token, 0, -1);
                    if ($mt !== '' && strpos($mt, $prefix) === 0) {
                        return true;
                    }
                    continue;
                }
                if ($mt !== '' && $mt === $token) {
                    return true;
                }
                continue;
            }
            if ($ext !== '' && $ext === $token) {
                return true;
            }
            if (self::matches_group($token, $ext, $mt)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Match Moodle filetype group aliases (image, audio, video, document, ...).
     *
     * @param string $group Group name (lowercase).
     * @param string $ext Lowercase extension.
     * @param string $mt Lowercase mimetype.
     * @return bool
     */
    private static function matches_group(string $group, string $ext, string $mt): bool {
        $groups = self::groups();
        if (!isset($groups[$group])) {
            return false;
        }
        $rules = $groups[$group];
        if (isset($rules['mt']) && $mt !== '') {
            foreach ($rules['mt'] as $prefix) {
                if (substr($prefix, -1) === '/' && strpos($mt, $prefix) === 0) {
                    return true;
                }
                if ($mt === $prefix) {
                    return true;
                }
            }
        }
        if (isset($rules['ext']) && $ext !== '' && in_array($ext, $rules['ext'], true)) {
            return true;
        }
        return false;
    }

    /**
     * Mapping of group token to allowed extensions / mimetypes.
     *
     * @return array<string,array<string,array<string>>>
     */
    private static function groups(): array {
        return [
            'image' => [
                'mt' => ['image/'],
                'ext' => ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'tif', 'tiff', 'svg', 'bmp', 'heic', 'heif', 'avif'],
            ],
            'web_image' => [
                'mt' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'],
                'ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
            ],
            'audio' => [
                'mt' => ['audio/'],
                'ext' => ['mp3', 'ogg', 'oga', 'wav', 'flac', 'm4a', 'aac'],
            ],
            'video' => [
                'mt' => ['video/'],
                'ext' => ['mp4', 'm4v', 'webm', 'ogv', 'mov', 'avi', 'mkv'],
            ],
            'text' => [
                'mt' => ['text/'],
                'ext' => ['txt', 'csv', 'html', 'htm', 'json', 'md'],
            ],
            'pdf' => [
                'mt' => ['application/pdf'],
                'ext' => ['pdf'],
            ],
            'json' => [
                'mt' => ['application/json'],
                'ext' => ['json'],
            ],
            'html' => [
                'mt' => ['text/html'],
                'ext' => ['html', 'htm'],
            ],
            'svg' => [
                'mt' => ['image/svg+xml'],
                'ext' => ['svg'],
            ],
            'document' => [
                'mt' => [
                    'application/pdf',
                    'application/msword',
                    'application/rtf',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.oasis.opendocument.text',
                ],
                'ext' => ['pdf', 'doc', 'docx', 'odt', 'rtf'],
            ],
            'spreadsheet' => [
                'mt' => [
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'text/csv',
                    'application/vnd.oasis.opendocument.spreadsheet',
                ],
                'ext' => ['xls', 'xlsx', 'ods', 'csv'],
            ],
            'presentation' => [
                'mt' => [
                    'application/vnd.ms-powerpoint',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'application/vnd.oasis.opendocument.presentation',
                ],
                'ext' => ['ppt', 'pptx', 'odp'],
            ],
            'archive' => [
                'mt' => [
                    'application/zip',
                    'application/x-zip-compressed',
                    'application/x-rar-compressed',
                    'application/x-7z-compressed',
                    'application/gzip',
                    'application/x-tar',
                ],
                'ext' => ['zip', 'rar', '7z', 'tar', 'gz'],
            ],
        ];
    }
}
