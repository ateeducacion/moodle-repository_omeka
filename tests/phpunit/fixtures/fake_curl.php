<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Fake curl used by the API client tests.
 *
 * @package    repository_omeka
 * @category   test
 * @copyright  2026 Área de Tecnología Educativa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_omeka\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Stub curl that records its arguments and returns canned responses.
 *
 * Parent properties used directly:
 *  - {@see \curl::$response}: response headers, exposed via {@see \curl::getResponse()}.
 *  - {@see \curl::$error}, {@see \curl::$errno}: transport error simulation.
 */
class fake_curl extends \curl {
    /** @var string|false Response body to return from get(). */
    public $body = '';

    /** @var int Simulated HTTP status. */
    public $httpcode = 200;

    /** @var string Last requested URL. */
    public $lasturl = '';

    /** @var array Last options array passed to get(). */
    public $lastoptions = [];

    /**
     * Record URL/options and return the canned body.
     *
     * @param string $url URL.
     * @param array $params GET params (already encoded by the client).
     * @param array $options curl options.
     * @return string|false
     */
    public function get($url, $params = [], $options = []) {
        $this->lasturl = $url;
        $this->lastoptions = $options;
        if ((int)$this->errno !== 0) {
            return false;
        }
        return $this->body;
    }

    /**
     * Return the simulated HTTP info.
     *
     * @return array
     */
    public function get_info() {
        return ['http_code' => $this->httpcode];
    }

    /**
     * Return the simulated transport errno.
     *
     * @return int
     */
    public function get_errno() {
        return (int)$this->errno;
    }
}
