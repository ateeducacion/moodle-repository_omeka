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

/**
 * Repository omeka class
 *
 * @package   repository_omeka
 * @copyright 2025 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_omeka extends repository {

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
        // TODO: Implement Omeka-S API integration here.
        return $this->search("", 0);
    }

    /**
     * Return search results.
     *
     * @param string $searchtext
     * @param int $page
     *
     * @return array|mixed
     *
     * @throws coding_exception
     * @throws dml_exception
     */
    public function search($searchtext, $page = 0) {
        global $SESSION;

        $sessionkeyword = "ottflix_" . $this->id;

        if ($page && !$searchtext && isset($SESSION->{$sessionkeyword})) {
            $searchtext = $SESSION->{$sessionkeyword};
        }

        $SESSION->{$sessionkeyword} = $searchtext;

        $ret = [
            "dynload" => true,
            "nologin" => true,
            "page" => (int)$page,
            "norefresh" => false,
            "nosearch" => false,
            "manage" => "https://tuservidor-omeka-s/", // Cambia esta URL por la de tu Omeka-S.
            "list" => [],
            "path" => [],
        ];

        $path = null;
        $pathid = "";
        if ($p = optional_param("p", false, PARAM_RAW)) {
            /** @var object $path */
            $path = json_decode(base64_decode($p));
            if (isset($path->path_id)) {
                $pathid = $path->path_id;
            }
        }

        if (isset($path->h5p_id) || isset($path->scorm_id)) {
            if (isset($path->h5p_id)) {
                $lasttitle = "H5P´s";

                $ret["list"] = $this->h5p_itens($path, "h5p");
            } else if (isset($path->scorm_id)) {
                $lasttitle = "SCORM´s";

                $ret["list"] = $this->h5p_itens($path, "zip");
            }

            if (isset($lasttitle)) {
                $ret["path"] = (array)$path->path;
                $ret["path"][] = [
                    "name" => "{$lasttitle} => {$path->title}",
                    "path" => $p,
                ];
            }
        } else {
            // Search files.
            $generateh5p = $generatescorm = false;
            $extensions = optional_param_array("accepted_types", [], PARAM_TEXT);
            if ($extensions[0] == ".h5p") {
                $generateh5p = true;
                $extensions = ["Video", "Audio"];
            }
            if ($extensions[0] == ".zip" || $extensions[0] == ".imscc") {
                $generatescorm = true;
                $extensions = ["Video", "Audio"];
            }
            // TODO: Aquí deberás implementar la lógica para obtener los recursos de Omeka-S.
            // Por ahora, se deja vacío para que puedas implementar la integración real.
        }

        $ret["pages"] = (count($ret["list"]) < 20) ? $ret["page"] : -1;

        return $ret;
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
        // TODO: Implementar la descarga de archivos desde Omeka-S.
        throw new \moodle_exception("notimplemented", "repository_omeka");
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
     * file types supported by ottflix plugin
     *
     * @return array
     */
    public function supported_filetypes() {
        // Ajusta los tipos de archivo soportados según lo que permita Omeka-S.
        return [
            "image", "audio", "video", "document"
        ];
    }

    /**
     * ottflix plugin only return external links
     *
     * @return int
     */
    public function supported_returntypes() {
        return FILE_INTERNAL | FILE_REFERENCE;
    }
}
