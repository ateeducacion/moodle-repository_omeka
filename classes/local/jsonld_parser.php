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
 * Helpers to read Omeka-S JSON-LD resources.
 *
 * @package   repository_omeka
 * @copyright 2026 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_omeka\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Stateless helpers to extract values from Omeka-S resources.
 */
class jsonld_parser {
    /**
     * Return the first textual value for any of the given JSON-LD terms.
     *
     * @param array $resource Resource (item, media, ...).
     * @param array $terms Property terms ordered by preference.
     * @return string|null Null when not found.
     */
    public static function first_property_text(array $resource, array $terms): ?string {
        foreach ($terms as $term) {
            if (!isset($resource[$term]) || !is_array($resource[$term]) || empty($resource[$term])) {
                continue;
            }
            $value = $resource[$term][0];
            if (is_array($value)) {
                if (isset($value['@value']) && is_scalar($value['@value'])) {
                    return (string)$value['@value'];
                }
                if (isset($value['value_resource']['o:title'])) {
                    return (string)$value['value_resource']['o:title'];
                }
                if (isset($value['value_resource_title']) && is_scalar($value['value_resource_title'])) {
                    return (string)$value['value_resource_title'];
                }
                if (isset($value['@id']) && is_scalar($value['@id'])) {
                    return (string)$value['@id'];
                }
            } else if (is_string($value)) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Return the first date-like property as a unix timestamp.
     *
     * @param array $resource Resource.
     * @param array $terms Date property terms to inspect.
     * @return int|null Timestamp or null on miss.
     */
    public static function first_date_timestamp(
        array $resource,
        array $terms = [
            'dcterms:modified',
            'schema:dateModified',
            'dcterms:created',
            'schema:dateCreated',
            'dcterms:issued',
            'dcterms:date',
        ]
    ): ?int {
        $value = self::first_property_text($resource, $terms);
        if ($value === null || $value === '') {
            return null;
        }
        return self::parse_iso8601($value);
    }

    /**
     * Parse an ISO-8601 (or compatible) date string to a unix timestamp.
     *
     * @param string $value String value.
     * @return int|null Timestamp or null on miss.
     */
    public static function parse_iso8601(string $value): ?int {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false || $timestamp <= 0) {
            return null;
        }
        return (int)$timestamp;
    }

    /**
     * Return a display title for an Omeka-S resource.
     *
     * @param array $resource Resource.
     * @return string Title or "" when nothing useful is found.
     */
    public static function title(array $resource): string {
        if (isset($resource['o:title']) && is_string($resource['o:title']) && $resource['o:title'] !== '') {
            return $resource['o:title'];
        }
        $value = self::first_property_text($resource, ['dcterms:title']);
        return $value ?? '';
    }

    /**
     * Return the first description value.
     *
     * @param array $resource Resource.
     * @return string|null
     */
    public static function description(array $resource): ?string {
        return self::first_property_text($resource, ['dcterms:description', 'schema:description']);
    }

    /**
     * Return the first author value (creator/contributor/publisher).
     *
     * @param array $resource Resource.
     * @return string|null
     */
    public static function author(array $resource): ?string {
        return self::first_property_text(
            $resource,
            ['dcterms:creator', 'dcterms:contributor', 'dcterms:publisher']
        );
    }
}
