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
 * Spanish language strings for repository_omeka.
 *
 * @package   repository_omeka
 * @copyright 2025 Área de Tecnología Educativa
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['keyidentity'] = 'ID de la clave API';
$string['keyidentity_desc'] = 'Identificador de la clave API para este sitio de Omeka-S.';
$string['keyidentity_help'] = 'Primera parte de la clave API empleada para el acceso autenticado al sitio de Omeka-S. Déjala vacía para contenido público.';
$string['keycredential'] = 'Credencial de la clave API';
$string['keycredential_desc'] = 'Credencial de la clave API para este sitio de Omeka-S.';
$string['keycredential_help'] = 'Segunda parte de la clave API. Necesaria junto con el ID de clave para acceder a contenido protegido.';
$string['baseurl'] = 'URL base de Omeka-S';
$string['baseurl_desc'] = 'URL raíz del sitio Omeka-S para este repositorio (por ejemplo, https://ejemplo.com/omeka-s).';
$string['site'] = 'Sitio de Omeka-S';
$string['site_desc'] = 'Selecciona el sitio de Omeka-S que quieres explorar. Déjalo vacío para usar todos los ítems públicos.';
$string['site_help'] = 'Selecciona el sitio de Omeka-S desde el que se recuperarán los elementos.';
$string['acceptedtypes'] = 'Tipos de archivo aceptados (opcional)';
$string['acceptedtypes_help'] = 'Restringe los archivos a estos tipos. Deja vacío para permitir todos. Puedes introducir extensiones (p. ej., .pdf, .png), tipos MIME (p. ej., image/png) o grupos como image, audio, video, document, spreadsheet, presentation, archive.';
$string['cannotdownload'] = 'No se pudo descargar el archivo desde Omeka-S.';
$string['configplugin'] = 'Omeka-S';
$string['omeka:view'] = 'Usar Omeka-S en el selector de archivos';
$string['pluginname'] = 'Repositorio Omeka-S';
$string['privacy:metadata'] = 'El repositorio Omeka-S no almacena ni transmite tus datos a terceros.';
$string['search'] = 'Buscar archivos en Omeka-S';
$string['repositoryomeka_apierror'] = 'Error de la API de Omeka-S: {$a}';
$string['itemsetlabel'] = 'Colección {$a}';
$string['itemlabel'] = 'Ítem {$a}';
$string['medialabel'] = 'Media {$a}';
