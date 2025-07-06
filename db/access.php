<?php
/**
 * Plugin capabilities.
 *
 * PHP version 7.4 or later
 *
 * @category  Access
 * @package   Repository_Omeka
 * @author    Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @copyright 2025 Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link      https://github.com/educacioncanarias/moodle-repository_omeka
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'repository/omeka:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_USER,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
