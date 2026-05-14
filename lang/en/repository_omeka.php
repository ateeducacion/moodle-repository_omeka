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
 * English language strings for repository_omeka.
 *
 * @package   repository_omeka
 * @copyright 2025 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['keyidentity'] = 'API key ID';
$string['keyidentity_desc'] = 'Identifier part of the API key for this Omeka-S site.';
$string['keyidentity_help'] = 'First part of the API key for authenticated access to the Omeka-S site.';
$string['keycredential'] = 'API key credential';
$string['keycredential_desc'] = 'Credential part of the API key for this Omeka-S site.';
$string['keycredential_help'] = 'Second part of the API key used with the ID to access protected content.';
$string['baseurl'] = 'Omeka-S base URL';
$string['baseurl_desc'] = 'Root URL of the Omeka-S site for this repository (e.g. https://example.com/omeka-s).';
$string['site'] = 'Omeka-S site';
$string['site_desc'] = 'Select the Omeka-S site to browse. Leave empty to use all public items.';
$string['site_help'] = 'Choose which Omeka-S site to fetch items from.';
$string['acceptedtypes'] = 'Accepted file types (optional)';
$string['acceptedtypes_help'] = 'Restrict files to these types. Leave empty to allow all. You can enter extensions (e.g., .pdf, .png), MIME types (e.g., image/png) or type groups such as image, audio, video, document, spreadsheet, presentation, archive.';
$string['acceptedclasses'] = 'Accepted resource classes (optional)';
$string['acceptedclasses_help'] = 'Restrict the listing to Omeka-S items of these resource classes. Leave empty to list every item. You can enter vocabulary-prefixed terms (e.g. <code>lrmi:LearningResource, dctype:Image, bibo:Document</code>) or numeric class ids. Multiple values are combined with OR and forwarded to <code>/api/items</code> as <code>resource_class_term[]</code> / <code>resource_class_id[]</code>, so the filtering happens server-side without extra requests.';
$string['helpurl'] = 'Help URL (optional)';
$string['helpurl_help'] = 'URL opened by the file picker toolbar Help button. Leave empty to hide the button. Useful to point users at your institution\'s support page or the plugin documentation.';
$string['instancemessage'] = 'Toolbar message (optional)';
$string['instancemessage_help'] = 'Short text shown in the file picker toolbar message slot. Leave empty to hide the message. Use it to explain which subset of Omeka-S items this repository exposes (curated collection, accepted classes, etc.).';
$string['cannotdownload'] = 'Unable to download the file from Omeka-S.';
$string['linkedmedianotdownloadable'] = 'This Omeka-S resource is a linked external item (oEmbed video, IIIF manifest or URL) and cannot be copied into Moodle. In the file picker, please choose "Create a link" to insert it as an external link.';
$string['configplugin'] = 'Omeka-S';
$string['omeka:view'] = 'Use Omeka-S in the File Picker';
$string['pluginname'] = 'Omeka-S Repository';
$string['privacy:metadata'] = 'Omeka-S repository does not store or transmit your data to third parties.';
$string['search'] = 'Search files in Omeka-S';
$string['repositoryomeka_apierror'] = 'Omeka-S API error: {$a}';
$string['itemsetlabel'] = 'Item set {$a}';
$string['itemlabel'] = 'Item {$a}';
$string['medialabel'] = 'Media {$a}';
