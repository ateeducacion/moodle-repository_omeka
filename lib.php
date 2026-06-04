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
use repository_omeka\local\ingester_classifier;
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
    /**
     * Default API timeout in seconds (used outside Moodle Playground).
     * Bumped from 10 to 30 because we now serve the picker out of the listing
     * builder which can issue 2-3 chained API calls (items + bulk media + the
     * occasional item-set metadata). 30s is enough for the slowest reasonable
     * round trip while still failing fast on a truly unreachable host.
     */
    const API_TIMEOUT = 30;

    /**
     * Higher API timeout used when the plugin runs inside Moodle Playground.
     * Each PHP curl call there flows through @php-wasm's `tcpOverFetch`, which
     * emulates a TCP socket with several round-trip `fetch()` calls
     * (handshake, data chunks, FIN). Combined with the service worker hop and
     * the upstream proxy, a single request can take noticeably longer than a
     * direct call — especially on the first request after boot, before the
     * fetch/SW pipelines are warm. 60s avoids the spurious "Connection timed
     * out after 10000 milliseconds" error users see when opening the picker
     * for the first time in a fresh playground tab.
     */
    const API_TIMEOUT_PLAYGROUND = 60;

    /**
     * File download timeout (used inside {@see get_file()}). Larger than the
     * API timeout because the picker can pull multi-megabyte binaries.
     */
    const DOWNLOAD_TIMEOUT = 120;

    /** Higher file download timeout used inside Moodle Playground. */
    const DOWNLOAD_TIMEOUT_PLAYGROUND = 180;

    /**
     * Hard ceiling (1 GiB) for a single media download in {@see get_file()}.
     * The media URL is built from untrusted Omeka content, so without a cap a
     * malicious or compromised host could stream an unbounded body and fill the
     * server's temp partition. Enforced both with CURLOPT_MAXFILESIZE (when the
     * host advertises a Content-Length) and a post-download size check.
     */
    const MAX_DOWNLOAD_BYTES = 1073741824;

    /**
     * Largest on-disk image (64 MiB) the Playground truncation check will read
     * fully into memory for a decode. Above this we trust the header-only
     * dimension read so a hostile file cannot spike memory.
     */
    const MAX_IMAGE_DECODE_BYTES = 67108864;

    /**
     * Largest image (in pixels) the Playground truncation check will fully
     * decode. A width*height above this is treated as a decompression bomb and
     * never expanded into an in-memory bitmap.
     */
    const MAX_IMAGE_PIXELS = 64000000;

    /**
     * Fallback CORS proxy used inside Moodle Playground when the playground
     * runtime did not export MOODLE_PLAYGROUND_PROXY_URL (older builds).
     * Never used in a normal Moodle install because the playground gate
     * (MOODLE_PLAYGROUND constant) is required. Stored as a bare endpoint
     * URL; the helper appends `?url=<encoded>` (or `&url=`) when building
     * the final request URL.
     */
    const PLAYGROUND_CORS_PROXY_FALLBACK = 'https://github-proxy.exelearning.dev/';

    /** @var api_client|null Lazily built API client. */
    private $client;

    /** @var listing_builder|null Lazily built listing builder. */
    private $listingbuilder;

    /** @var filetype_filter|null Lazily built filetype filter. */
    private $filter;

    /**
     * Wrap a URL with the Moodle Playground CORS proxy when running inside
     * Moodle Playground; return it unchanged in a normal Moodle install.
     *
     * The playground's @php-wasm tcpOverFetch otherwise tries a direct
     * fetch first and only falls back to its configured proxy when the
     * direct call fails by CORS, producing a noisy first-request CORS
     * error in the browser console even though the request eventually
     * succeeds. Emitting an already-proxied URL bypasses that path.
     *
     * Gated on the MOODLE_PLAYGROUND constant that the playground runtime
     * injects into Moodle's config.php; when the constant is absent the
     * helper short-circuits and the plugin behaves identically to before.
     *
     * @param string $url Absolute URL to wrap.
     * @return string Wrapped URL inside the playground, original otherwise.
     */
    public static function wrap_cors_proxy(string $url): string {
        $isplayground = defined('MOODLE_PLAYGROUND') && MOODLE_PLAYGROUND;
        $proxyurl = defined('MOODLE_PLAYGROUND_PROXY_URL')
            ? (string)MOODLE_PLAYGROUND_PROXY_URL
            : '';
        return self::wrap_url_with_proxy($url, $isplayground, $proxyurl);
    }

    /**
     * Pick the API timeout in seconds: the longer playground value when the
     * MOODLE_PLAYGROUND constant is defined, otherwise the standard one.
     *
     * @return int Timeout in seconds.
     */
    public static function get_api_timeout(): int {
        if (defined('MOODLE_PLAYGROUND') && MOODLE_PLAYGROUND) {
            return self::API_TIMEOUT_PLAYGROUND;
        }
        return self::API_TIMEOUT;
    }

    /**
     * Pick the binary file download timeout in seconds. Higher than the API
     * timeout because downloads stream more bytes; bumped further inside
     * Moodle Playground to absorb the tcpOverFetch overhead.
     *
     * @return int Timeout in seconds.
     */
    public static function get_download_timeout(): int {
        if (defined('MOODLE_PLAYGROUND') && MOODLE_PLAYGROUND) {
            return self::DOWNLOAD_TIMEOUT_PLAYGROUND;
        }
        return self::DOWNLOAD_TIMEOUT;
    }

    /**
     * Build the curl TLS/SSRF options for an outbound Omeka request.
     *
     * Strict on a normal server and relaxed only inside Moodle Playground,
     * matching how the rest of the ATE plugins (e.g. mod_exelearning) handle
     * the php-wasm runtime:
     *
     *  - Normal install: do NOT pass `ignoresecurity`, so Moodle's
     *    curl_security_helper stays active and blocks requests to loopback,
     *    link-local (cloud metadata, 169.254.169.254), private hosts and
     *    non-allowlisted ports; and verify the TLS chain
     *    (CURLOPT_SSL_VERIFYPEER/VERIFYHOST) because Moodle's curl wrapper
     *    otherwise defaults peer verification off. This restores SSRF and
     *    MITM protection on every outbound call, including the get_file()
     *    download whose URL comes from untrusted Omeka content.
     *  - Moodle Playground: the @php-wasm networking shim has no
     *    gethostbynamel() and cannot present a verifiable TLS chain, so the
     *    security helper rejects every host with "The URL is blocked." and TLS
     *    verification fails. There (and only there, gated on the
     *    MOODLE_PLAYGROUND constant the runtime injects) we relax both.
     *
     * @return array{construct: array, ssl: array} Constructor options for
     *     `new \curl(...)` plus per-request SSL setopt keys to merge into the
     *     request options.
     */
    public static function curl_security_options(): array {
        if (defined('MOODLE_PLAYGROUND') && MOODLE_PLAYGROUND) {
            // Playground: the wasm networking layer cannot verify TLS, so relax
            // verification and the SSRF blocklist (matches the runtime's shim).
            return ['construct' => ['ignoresecurity' => true], 'ssl' => []];
        }
        return [
            'construct' => [],
            'ssl' => ['CURLOPT_SSL_VERIFYPEER' => 1, 'CURLOPT_SSL_VERIFYHOST' => 2],
        ];
    }

    /**
     * Pure implementation of {@see wrap_cors_proxy()} that takes the playground
     * state as explicit parameters instead of reading the MOODLE_PLAYGROUND /
     * MOODLE_PLAYGROUND_PROXY_URL constants directly. Exposed so unit tests
     * can cover every gating case without resorting to runInSeparateProcess
     * (which re-boots Moodle core and trips unrelated PHP 8.5 deprecation
     * warnings that PHPUnit --fail-on-warning misreports as test failures).
     *
     * @param string $url Absolute URL to wrap.
     * @param bool $isplayground Whether the playground gate is active.
     * @param string $proxyurl Proxy URL configured by the playground (may be empty).
     * @return string Wrapped URL when the gate is active, original otherwise.
     */
    public static function wrap_url_with_proxy(string $url, bool $isplayground, string $proxyurl): string {
        if ($url === '' || !$isplayground) {
            return $url;
        }
        $proxy = $proxyurl !== '' ? $proxyurl : self::PLAYGROUND_CORS_PROXY_FALLBACK;
        // Both proxy endpoints (the playground's scoped `__playground_proxy__`
        // and the standalone github-proxy) expect the target URL in a `url`
        // query parameter. Append `?url=` or `&url=` depending on whether the
        // proxy URL already carries a query string.
        $separator = strpos($proxy, '?') === false ? '?' : '&';
        return $proxy . $separator . 'url=' . rawurlencode($url);
    }

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
                self::get_api_timeout()
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
                rtrim((string)$this->get_option('baseurl'), '/'),
                (string)$this->get_option('acceptedclasses'),
                $this->resolve_site_slug(),
                (string)$this->get_option('helpurl'),
                (string)$this->get_option('instancemessage'),
            );
        }
        return $this->listingbuilder;
    }

    /**
     * Resolve the Omeka-S site slug for the configured `siteid`.
     *
     * The slug is normally persisted by the instance form (via the
     * `omekasites` AMD module that copies `o:slug` into the hidden field),
     * but instances saved before that wiring landed — or via the WS-only
     * configuration helper — may have a `siteid` without a `siteslug`. In
     * that case we fetch it from `/api/sites/<id>` once and persist it,
     * so the contextual "manage" link reaches the correct public page
     * instead of falling back to the admin item index.
     *
     * @return string Slug, or empty string if the lookup failed / no site.
     */
    private function resolve_site_slug(): string {
        $slug = (string)$this->get_option('siteslug');
        if ($slug !== '') {
            return $slug;
        }
        $siteid = (int)$this->get_option('siteid');
        $baseurl = (string)$this->get_option('baseurl');
        if (!$siteid || $baseurl === '') {
            return '';
        }
        $details = self::fetch_site_details(
            $baseurl,
            (string)$this->get_option('keyidentity'),
            (string)$this->get_option('keycredential'),
            $siteid,
        );
        $slug = (string)($details['slug'] ?? '');
        if ($slug !== '') {
            $this->set_option(['siteslug' => $slug]);
        }
        return $slug;
    }

    /**
     * Return a cached filetype filter.
     *
     * The filter combines two independent allowlists:
     *
     *  - The instance-level `acceptedtypes` option set by the admin in the
     *    repository configuration form.
     *  - The per-pick `accepted_types` array Moodle forwards from the form
     *    that opened the file picker, exposed on `$this->options['mimetypes']`
     *    by the base {@see repository} class (so e.g. a `mod_resource`
     *    instance configured to accept only `web_image` narrows the listing
     *    even when the admin's `acceptedtypes` is broader).
     *
     * Empty / wildcard inputs short-circuit so the filter is a no-op when
     * neither side has configured restrictions.
     *
     * @return filetype_filter
     */
    private function get_filetype_filter(): filetype_filter {
        if ($this->filter === null) {
            $filter = new filetype_filter((string)$this->get_option('acceptedtypes'));
            $pickertypes = $this->options['mimetypes'] ?? null;
            if (is_array($pickertypes)) {
                $filter = $filter->intersect($pickertypes);
            }
            $this->filter = $filter;
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
     * @param string $source File URL (binary media) or "linked:<url>" marker
     *                       value for linked media (oEmbed/IIIF/url/html).
     * @param string $filename Optional desired filename.
     * @return array With key 'path' pointing at the temp file.
     */
    public function get_file($source, $filename = '') {
        // Linked media (oEmbed iframe, IIIF manifest, raw URL, HTML snippet)
        // cannot be downloaded as a binary file — the only meaningful Moodle
        // representation is a URL reference (FILE_EXTERNAL). We reach this
        // path only when the user picked the default "Make a copy" option;
        // surface a clear instruction instead of silently failing in curl.
        if (is_string($source) && ingester_classifier::is_linked($source)) {
            // Keep the user-facing message tight: pass the URL via $debuginfo
            // (5th argument) so it only renders when the site is in
            // developer-debug mode. End users see a clean instruction without
            // the long Omeka/oEmbed URL stretching the error dialog.
            throw new \moodle_exception(
                'linkedmedianotdownloadable',
                'repository_omeka',
                '',
                null,
                ingester_classifier::strip_marker($source)
            );
        }
        // Binary downloads must be plain http(s) resources. The source URL is
        // built from untrusted Omeka content, so a compromised or malicious
        // instance could otherwise hand us a file:// (local file disclosure) or
        // other non-HTTP scheme via o:original_url; reject it before curl.
        if (!is_string($source) || !ingester_classifier::is_http_url($source)) {
            throw new \moodle_exception('cannotdownload', 'repository_omeka');
        }
        $filename = $filename !== '' ? $filename : basename((string)parse_url((string)$source, PHP_URL_PATH));
        $tmpfile = $this->prepare_file($filename);
        // Keep Moodle's curl_security_helper (SSRF blocklist) and TLS
        // verification active in a normal install; only relax them inside
        // Moodle Playground. See curl_security_options().
        $security = self::curl_security_options();
        $curl = new \curl($security['construct']);
        $fp = fopen($tmpfile, 'w');
        if ($fp === false) {
            throw new \moodle_exception('cannotdownload', 'repository_omeka');
        }
        // Route the URL through the playground proxy when running inside
        // Moodle Playground (constant MOODLE_PLAYGROUND set) so tcpOverFetch
        // does not waste a direct CORS-failing first attempt; outside the
        // playground wrap_cors_proxy() returns $source unchanged.
        $proxied = self::wrap_cors_proxy((string)$source);
        $ok = $curl->download_one($proxied, [], [
            'file' => $fp,
            'CURLOPT_FOLLOWLOCATION' => 1,
            'CURLOPT_TIMEOUT' => self::get_download_timeout(),
            // Bound the transfer so an untrusted host cannot stream an unbounded
            // body to fill the temp partition (honoured when Content-Length is
            // advertised; the post-download size check below is the backstop).
            'CURLOPT_MAXFILESIZE' => self::MAX_DOWNLOAD_BYTES,
        ] + $security['ssl']);
        fclose($fp);
        $info = $curl->get_info();
        $httpcode = (int)($info['http_code'] ?? 0);
        $size = file_exists($tmpfile) ? (int)filesize($tmpfile) : 0;
        // A silent transport failure (e.g. tcpOverFetch dropping a chunked body in
        // the WASM runtime) leaves the file at zero bytes but does not set an
        // http_code. Without this check Moodle stores an empty file and the
        // file picker later 500s with "file not found" trying to generate a
        // thumbnail. Surface the failure with diagnostics so the picker shows
        // a real error instead.
        $head = $size > 0 ? bin2hex((string)file_get_contents($tmpfile, false, null, 0, 16)) : '';
        // Backstop for the size cap: CURLOPT_MAXFILESIZE only aborts up front
        // when the host advertises a Content-Length, so reject anything that
        // slipped through over the ceiling before we process it further.
        $toolarge = $size > self::MAX_DOWNLOAD_BYTES;
        // The WASM playground's tcpOverFetch can also truncate the chunked
        // body mid-stream and leave curl thinking the transfer was a clean 200
        // OK with a partial body. For image targets, validate the file is a
        // readable image; if not, treat it as a failed download.
        //
        // In a normal install we only read the header (getimagesize, O(1)
        // memory) which is enough to spot a broken/truncated header. The
        // expensive full decode (imagecreatefromstring allocates
        // width*height*~4 bytes and is a memory-DoS / decompression-bomb vector
        // on untrusted input) is reserved for the Playground, where the body
        // can be silently truncated and the runtime is single-user; even there
        // it is bounded by the size / pixel caps so a bomb cannot OOM the worker.
        $playground = defined('MOODLE_PLAYGROUND') && MOODLE_PLAYGROUND;
        $isimage = false;
        $ext = strtolower((string)pathinfo($tmpfile, PATHINFO_EXTENSION));
        $imageexts = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'];
        $isimagecandidate = in_array($ext, $imageexts, true);
        $caninspect = function_exists('getimagesize');
        if ($isimagecandidate && $caninspect && !$toolarge) {
            $dimensions = @getimagesize($tmpfile);
            $pixels = $dimensions !== false
                ? (int)($dimensions[0] ?? 0) * (int)($dimensions[1] ?? 0)
                : 0;
            if ($dimensions === false) {
                // Unreadable / truncated header: treat as a failed download.
                $isimage = false;
            } else if (
                $playground && function_exists('imagecreatefromstring')
                && $size <= self::MAX_IMAGE_DECODE_BYTES
                && $pixels > 0 && $pixels <= self::MAX_IMAGE_PIXELS
            ) {
                $bytes = (string)file_get_contents($tmpfile);
                $isimage = $bytes !== '' && @imagecreatefromstring($bytes) !== false;
            } else {
                // Production, or a file too large to decode safely: the header
                // read above is sufficient; never load the whole file into RAM.
                $isimage = true;
            }
        }
        $imageinvalid = $isimagecandidate && $caninspect && !$isimage && !$toolarge;
        if ($ok === false || $httpcode >= 400 || $size === 0 || $toolarge || $imageinvalid) {
            @unlink($tmpfile);
            $err = isset($curl->error) && $curl->error !== '' ? (string)$curl->error : 'transport failure';
            $reason = $toolarge ? 'file too large' : ($imageinvalid ? 'corrupted image' : $err);
            throw new \moodle_exception(
                'repositoryomeka_apierror',
                'repository_omeka',
                '',
                $source . ' -> download failed: ' . $reason
                    . ' (http=' . $httpcode . ', size=' . $size . ', head=' . $head . ')'
            );
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
        // FILE_INTERNAL: download a copy of the binary into Moodle (default
        // for Omeka filestore media — `upload`, `file_sideload`, ...).
        // FILE_EXTERNAL: store a URL reference so non-binary linked media
        // (oEmbed videos, IIIF manifests, `url` / `html` ingesters) can be
        // picked without trying to copy bytes that don't exist.
        // We deliberately do not advertise FILE_REFERENCE because Moodle would
        // then defer downloads to thumbnail/preview generation, which would
        // fail when the source host is third-party or unreachable from server
        // PHP (e.g. inside Moodle Playground / @php-wasm where outbound HTTPS
        // to non-CORS-open hosts is restricted).
        return FILE_INTERNAL | FILE_EXTERNAL;
    }

    /**
     * Resolve the public link for a FILE_EXTERNAL entry.
     *
     * Strips the "linked:" marker (added by {@see \repository_omeka\local\entry_factory})
     * so the URL Moodle stores is the canonical external one. Binary sources
     * (no marker) are returned unchanged for backwards compatibility.
     *
     * @param string $url Source value as handed back by the file picker.
     * @return string Public URL ready to be stored as the external link.
     */
    public function get_link($url) {
        $clean = ingester_classifier::strip_marker((string)$url);
        // The stored external link comes from untrusted Omeka content; never let
        // a javascript:/data:/file: value through as a hyperlink (stored XSS).
        // Only plain http(s) resources are valid external media links.
        return ingester_classifier::is_http_url($clean) ? $clean : '';
    }

    /**
     * Return the source information stored in files.source.
     *
     * Strips the "linked:" marker so the stored value is a clean URL —
     * useful when Moodle later displays the file's "Source" metadata.
     *
     * @param string $source Source value as handed back by the file picker.
     * @return string|null
     */
    public function get_file_source_info($source) {
        $clean = ingester_classifier::strip_marker((string)$source);
        // Same untrusted-URL guard as get_link(): only surface http(s) sources.
        return ingester_classifier::is_http_url($clean) ? $clean : '';
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
        $acceptedclasses = '';
        $currentsitelabel = '';
        $currentsiteslug = '';
        $helpurl = '';
        $instancemessage = '';
        if (!$baseurl && $instanceid) {
            $instance = repository::get_instance($instanceid);
            $baseurl = (string)$instance->get_option('baseurl');
            $keyidentity = (string)$instance->get_option('keyidentity');
            $keycredential = (string)$instance->get_option('keycredential');
            $currentsiteid = (int)$instance->get_option('siteid');
            $acceptedtypes = (string)$instance->get_option('acceptedtypes');
            $acceptedclasses = (string)$instance->get_option('acceptedclasses');
            $currentsitelabel = (string)$instance->get_option('sitelabel');
            $currentsiteslug = (string)$instance->get_option('siteslug');
            $helpurl = (string)$instance->get_option('helpurl');
            $instancemessage = (string)$instance->get_option('instancemessage');
        }

        $sites = self::fetch_sites($baseurl, $keyidentity, $keycredential);
        // Refetch when either the label or the slug is missing — instances
        // saved before the slug was reliably persisted should heal on the
        // next form open instead of needing manual re-selection.
        if (($currentsitelabel === '' || $currentsiteslug === '') && $currentsiteid && $baseurl) {
            $details = self::fetch_site_details($baseurl, $keyidentity, $keycredential, $currentsiteid);
            if ($currentsitelabel === '') {
                $currentsitelabel = $details['title'] ?? '';
            }
            if ($currentsiteslug === '') {
                $currentsiteslug = $details['slug'] ?? '';
            }
        }
        instance_form::add_site_selector($mform, $sites, $currentsiteid, $currentsitelabel, $currentsiteslug);
        instance_form::add_filetype_selector($mform, $acceptedtypes);
        instance_form::add_resource_class_field($mform, $acceptedclasses);
        instance_form::add_helpurl_field($mform, $helpurl);
        instance_form::add_instancemessage_field($mform, $instancemessage);

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
        if (isset($options['acceptedclasses'])) {
            $options['acceptedclasses'] = clean_param($options['acceptedclasses'], PARAM_RAW_TRIMMED);
        }
        if (isset($options['sitelabel'])) {
            $options['sitelabel'] = clean_param($options['sitelabel'], PARAM_TEXT);
        }
        if (isset($options['siteslug'])) {
            $options['siteslug'] = clean_param($options['siteslug'], PARAM_ALPHANUMEXT);
        }
        if (isset($options['helpurl'])) {
            $options['helpurl'] = clean_param($options['helpurl'], PARAM_URL);
        }
        if (isset($options['instancemessage'])) {
            $options['instancemessage'] = clean_param($options['instancemessage'], PARAM_TEXT);
        }
        return parent::set_option($options);
    }

    /**
     * Names of the plugin settings stored per instance.
     *
     * @return array
     */
    public static function get_instance_option_names() {
        return [
            'baseurl',
            'siteid',
            'keyidentity',
            'keycredential',
            'acceptedtypes',
            'acceptedclasses',
            'sitelabel',
            'siteslug',
            'helpurl',
            'instancemessage',
        ];
    }

    /**
     * Validate the instance configuration form input.
     *
     * @param \moodleform $mform Form (signature required by Moodle, unused here).
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
        return new api_client($baseurl, $keyidentity, $keycredential, self::get_api_timeout());
    }
}
