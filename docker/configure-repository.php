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
// Registers the Omeka-S repository type as enabled+visible, allows course and
// user instances, and creates two system-context instances against the public
// Omeka-S sandbox: one unscoped, one scoped to the `mallhistory` site (id 4)
// so manual testing exercises the single-site search path too. Idempotent —
// safe to run on every container boot.
//
// Invoked from docker-compose.yml's POST_CONFIGURE_COMMANDS. Kept as a real
// PHP file rather than `php -r '...'` because docker compose interpolates `$x`
// inside YAML env values before the container ever sees them.

if (!defined('CLI_SCRIPT')) {
    define('CLI_SCRIPT', true);
}

require('/var/www/html/config.php');

$baseurl = getenv('OMEKA_BASEURL') ?: 'https://dev.omeka.org/omeka-s-sandbox';
$now = time();

global $DB;

$row = $DB->get_record('repository', ['type' => 'omeka']);
if ($row) {
    $typeid = (int)$row->id;
    $DB->set_field('repository', 'visible', 1, ['id' => $typeid]);
} else {
    $max = (int)$DB->get_field_sql('SELECT MAX(sortorder) FROM {repository}');
    $rec = (object)[
        'type' => 'omeka',
        'visible' => 1,
        'sortorder' => $max + 1,
    ];
    $typeid = (int)$DB->insert_record('repository', $rec);
}

set_config('enablecourseinstances', 1, 'omeka');
set_config('enableuserinstances', 1, 'omeka');

$ctx = context_system::instance();

$instances = [
    [
        'name' => 'Omeka Sandbox',
        'baseurl' => $baseurl,
        'siteid' => '0',
        'siteslug' => '',
        'sitelabel' => '',
    ],
    [
        'name' => 'Omeka Sandbox mallhistory site',
        'baseurl' => $baseurl,
        'siteid' => '4',
        'siteslug' => 'mallhistory',
        'sitelabel' => 'Mall History Content',
    ],
];

foreach ($instances as $spec) {
    $existing = $DB->get_record('repository_instances', [
        'typeid' => $typeid,
        'contextid' => $ctx->id,
        'name' => $spec['name'],
    ]);
    if ($existing) {
        printf(
            "%s instance already exists (typeid=%d, instanceid=%d)\n",
            $spec['name'],
            $typeid,
            (int)$existing->id
        );
        continue;
    }

    $inst = (object)[
        'name' => $spec['name'],
        'typeid' => $typeid,
        'userid' => 0,
        'contextid' => $ctx->id,
        'username' => '',
        'password' => '',
        'timecreated' => $now,
        'timemodified' => $now,
        'readonly' => 0,
    ];
    $instanceid = (int)$DB->insert_record('repository_instances', $inst);
    $cfgs = [
        'baseurl' => $spec['baseurl'],
        'siteid' => $spec['siteid'],
        'keyidentity' => '',
        'keycredential' => '',
        'acceptedtypes' => '',
        'sitelabel' => $spec['sitelabel'],
        'siteslug' => $spec['siteslug'],
    ];
    foreach ($cfgs as $name => $value) {
        $DB->insert_record('repository_instance_config', (object)[
            'instanceid' => $instanceid,
            'name' => $name,
            'value' => $value,
        ]);
    }
    printf(
        "%s instance created (typeid=%d, instanceid=%d) -> %s siteid=%s\n",
        $spec['name'],
        $typeid,
        $instanceid,
        $spec['baseurl'],
        $spec['siteid']
    );
}
