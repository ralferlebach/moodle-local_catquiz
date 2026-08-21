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
 * Seeds a large CAT-manager / statistics profile for the read-endpoint load
 * tests (JMeter / k6).
 *
 * It creates one CAT context, a small scale tree and a configurable number of
 * person-parameter rows (many users x scales) so that the CAT manager and
 * statistics pages have to aggregate over a realistic amount of data. Nothing
 * here depends on the PHPUnit/Behat data generators, so it runs against a plain
 * installed site.
 *
 * It prints `export KEY='value'` lines (BASE_URL, SCALEID, CONTEXTID, COURSEID)
 * that the load workflows read to parameterise the load plan. Authentication
 * uses the admin account created by admin/cli/install.php.
 *
 * Usage (from the Moodle root):
 *   php local/catquiz/tests/load/seed_large.php [--users=2000] [--scales=8]
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['users' => 2000, 'scales' => 8, 'help' => false],
    ['h' => 'help']
);

if ($options['help']) {
    cli_writeln("Seed a large CAT manager / statistics profile.\n");
    cli_writeln("  --users=N   Number of distinct persons (default 2000).");
    cli_writeln("  --scales=N  Number of subscales under the root (default 8).");
    exit(0);
}

$numusers = max(1, (int) $options['users']);
$numscales = max(1, (int) $options['scales']);
$now = time();

// 1. A CAT context that scopes the profile.
$contextid = $DB->insert_record('local_catquiz_catcontext', (object) [
    'parentid' => 0,
    'name' => 'Load test context',
    'description' => '',
    'descriptionformat' => FORMAT_PLAIN,
    'starttimestamp' => 0,
    'endtimestamp' => 0,
    'json' => json_encode(new stdClass()),
    'usermodified' => 2,
    'timecreated' => $now,
    'timemodified' => $now,
]);

// 2. A root scale plus a few subscales, all bound to the context above.
$rootid = $DB->insert_record('local_catquiz_catscales', (object) [
    'parentid' => 0,
    'label' => 'LOAD-root',
    'name' => 'Load test scale',
    'description' => '',
    'contextid' => $contextid,
    'minscalevalue' => -5,
    'maxscalevalue' => 5,
    'timecreated' => $now,
    'timemodified' => $now,
]);

$scaleids = [(int) $rootid];
for ($s = 1; $s <= $numscales; $s++) {
    $scaleids[] = (int) $DB->insert_record('local_catquiz_catscales', (object) [
        'parentid' => $rootid,
        'label' => 'LOAD-sub-' . $s,
        'name' => 'Load subscale ' . $s,
        'description' => '',
        'contextid' => $contextid,
        'minscalevalue' => -5,
        'maxscalevalue' => 5,
        'timecreated' => $now,
        'timemodified' => $now,
    ]);
}

// 3. Bulk person parameters: one finite ability per (user, scale). insert_records
// batches the write so a few hundred thousand rows stay fast.
$batch = [];
$flush = function () use (&$batch, $DB) {
    if ($batch) {
        $DB->insert_records('local_catquiz_personparams', $batch);
        $batch = [];
    }
};
$total = 0;
for ($u = 1; $u <= $numusers; $u++) {
    $userid = 1000000 + $u; // Synthetic ids; the pages aggregate, they don't join users.
    foreach ($scaleids as $scaleid) {
        $batch[] = (object) [
            'userid' => $userid,
            'catscaleid' => $scaleid,
            'contextid' => $contextid,
            'ability' => round(mt_rand(-400, 400) / 100, 4),
            'status' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $total++;
        if (count($batch) >= 500) {
            $flush();
        }
    }
}
$flush();

cli_writeln("# seeded $numusers users x " . count($scaleids) . " scales = $total person params", STDERR);

// 5. Make sure the admin account has a known password so the load plan can log
// in. The value comes from the LOAD_ADMIN_PASS env var (default Admin!23).
$adminpass = getenv('LOAD_ADMIN_PASS') ?: 'Admin!23';
$admin = get_admin();
if ($admin) {
    update_internal_user_password($admin, $adminpass);
}

// 6. Emit the parameters the load plan needs. BASE_URL comes from $CFG->wwwroot.
cli_writeln("export BASE_URL='" . addslashes($CFG->wwwroot) . "'");
cli_writeln("export ADMIN_USER='" . addslashes($admin->username ?? 'admin') . "'");
cli_writeln("export ADMIN_PASS='" . addslashes($adminpass) . "'");
cli_writeln("export CONTEXTID='" . $contextid . "'");
cli_writeln("export SCALEID='" . $rootid . "'");
cli_writeln("export COURSEID='1'");
