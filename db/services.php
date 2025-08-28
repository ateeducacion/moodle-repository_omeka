<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'repository_omeka_list_sites' => [
        'classname'   => 'repository_omeka\\external\\list_sites',
        'methodname'  => 'execute',
        'description' => 'Return Omeka-S sites for a given baseurl and optional API keys.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities'=> 'moodle/site:config',
    ],
];
