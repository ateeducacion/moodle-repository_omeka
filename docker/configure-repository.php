<?php
// Registers the Omeka-S repository type as enabled+visible, allows course and
// user instances, and creates a system-context instance pointing at
// $OMEKA_BASEURL (defaults to the public sandbox). Idempotent — safe to run on
// every container boot.
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
$existing = $DB->get_record('repository_instances', [
    'typeid' => $typeid,
    'contextid' => $ctx->id,
]);
if (!$existing) {
    $inst = (object)[
        'name' => 'Omeka Sandbox',
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
        'baseurl' => $baseurl,
        'siteid' => '0',
        'keyidentity' => '',
        'keycredential' => '',
        'acceptedtypes' => '',
        'sitelabel' => '',
        'siteslug' => '',
    ];
    foreach ($cfgs as $name => $value) {
        $DB->insert_record('repository_instance_config', (object)[
            'instanceid' => $instanceid,
            'name' => $name,
            'value' => $value,
        ]);
    }
    printf("Omeka Sandbox instance created (typeid=%d, instanceid=%d) -> %s\n",
        $typeid, $instanceid, $baseurl);
} else {
    printf("Omeka Sandbox instance already exists (typeid=%d, instanceid=%d)\n",
        $typeid, (int)$existing->id);
}
