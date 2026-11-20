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
 * Seeds an installed site for the attempt load test of mod_adaptivequiz #17 (tests/load/attempt_load.py).
 *
 * One course, a number of participants with a known password, one CATquiz activity over the Simulation scale
 * (422 questions with item parameters) whose attempts administer exactly --items questions. The attempt trace
 * (local_catquiz/attempttrace) is switched on: every request of an attempt leaves one row in local_catquiz_trace
 * with the host's and the CAT model's spans, memory and database queries, under the request id the host sends.
 *
 * Prints "export KEY='value'" lines for the load driver. Not for a production site: it switches the trace on.
 *
 * Usage (from the Moodle root): php local/catquiz/tests/load/seed_attempt_load.php [--users=8] [--items=160[,26...]]
 *     [--pool=2500] [--shortname=catquiz-load] [--userprefix=loaduser]
 *
 * With several lengths every length gets its activity, idnumber <short name without dashes><length>, and the lines
 * LOAD_CMID_<length> and LOAD_IDNUMBER_<length>; --pool grows the pool of the Simulation scale to that many items.
 *
 * A second run into the same site needs another --shortname and --userprefix: the activity then gets the idnumber
 * of the short name without its dashes (catquiz-load: catquizload), which analyse_attempt_load.php takes as --idnumber.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');
require_once(__DIR__ . '/../playwright/seed_lib.php');

[$options] = cli_get_params(
    ['users' => 8, 'items' => '160', 'strategy' => 'adaptive', 'shortname' => 'catquiz-load', 'userprefix' => 'loaduser',
        'pool' => 0, 'help' => false],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln("Seed the attempt load test (mod_adaptivequiz #17).\n  --users=N  participants (default 8)\n"
        . "  --items=N[,M...]  questions per attempt; one activity per length (default 160)\n"
        . "  --pool=N  candidates of the pool: synthetic true/false items with 2PL parameters added to the 422 of the\n"
        . "            Simulation scale up to N (default 0: the 422 only)\n  --strategy=adaptive|classic\n"
        . "  --shortname=S  short name of the course (default catquiz-load)\n  --userprefix=P  (default loaduser)");
    exit(0);
}
$numusers = max(1, (int) $options['users']);
$lengths = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $options['items'])))));
if (!$lengths) {
    cli_error('--items needs at least one length.');
}
$shortname = clean_param((string) $options['shortname'], PARAM_ALPHANUMEXT);
$idnumber = str_replace(['-', '_'], '', $shortname);
$userprefix = clean_param((string) $options['userprefix'], PARAM_ALPHANUM);
$strategy = $options['strategy'] === 'classic' ? LOCAL_CATQUIZ_STRATEGY_CLASSIC : LOCAL_CATQUIZ_STRATEGY_ALLSUBS;

// The question generator of Moodle loads question/engine/tests/helpers.php, whose classes extend PHPUnit's: a CLI
// script has no PHPUnit autoloader unless the composer one of the site is loaded (on Moodle 5.x it sits beside public/).
$autoloads = array_unique([($CFG->root ?? $CFG->dirroot) . '/vendor/autoload.php', $CFG->dirroot . '/vendor/autoload.php']);
foreach ($autoloads as $autoload) {
    if (is_readable($autoload)) {
        require_once($autoload);
        break;
    }
}
if (class_exists('\core\test\phpunit\phpunit_util')) {
    $generator = \core\test\phpunit\phpunit_util::get_data_generator();
} else {
    require_once($CFG->dirroot . '/lib/phpunit/classes/util.php');
    $generator = phpunit_util::get_data_generator();
}
// Moodle 5.x: the question bank migration of the install, before anything is created - without it no participant
// can start an attempt ("item bank not configured"). After the generator, as in seed_cat_path.php.
local_catquiz_seed_run_qbank_migration();
\core\session\manager::set_user(get_admin());

$course = $DB->get_record('course', ['shortname' => $shortname]);
if ($course) {
    cli_error("A course '{$shortname}' exists already; take another --shortname and --userprefix or delete it first.");
}
$course = $generator->create_course(['fullname' => 'CATquiz attempt load ' . $shortname, 'shortname' => $shortname]);
$catquiz = $generator->get_plugin_generator('local_catquiz');
$catquiz->create_catquiz_questions([
    'filepath' => 'local/catquiz/tests/fixtures/simulation.xml',
    'filename' => 'simulation.xml',
    'courseid' => $course->id,
]);
$catquiz->create_catquiz_importedcatscales(['filename' => 'simulation.csv']);
$scaleid = (int) $DB->get_field('local_catquiz_catscales', 'id', ['name' => 'Simulation', 'parentid' => 0], MUST_EXIST);

// Re-audit 19.11.2026 (load P0): a pool of --pool candidates - synthetic true/false items with 2PL parameters on the
// leaves of the scale, from a fixed seed, as cli/benchmark_attempts.php builds its 2,500-item pool.
$poolsize = (int) $options['pool'];
if ($poolsize > 0) {
    $contextid = (int) \local_catquiz\catscale::get_context_id($scaleid);
    $leaves = $DB->get_fieldset_sql(
        "SELECT s.id FROM {local_catquiz_catscales} s
          WHERE NOT EXISTS (SELECT 1 FROM {local_catquiz_catscales} c WHERE c.parentid = s.id)
            AND (s.id = :root OR s.parentid <> 0)",
        ['root' => $scaleid]
    );
    $template = $DB->get_record_sql(
        "SELECT q.* FROM {question} q JOIN {local_catquiz_items} i ON i.componentid = q.id
          WHERE i.contextid = :c ORDER BY q.id",
        ['c' => $contextid],
        IGNORE_MULTIPLE
    );
    $categoryid = (int) $DB->get_field_sql(
        'SELECT qbe.questioncategoryid FROM {question_bank_entries} qbe JOIN {question_versions} qv
              ON qv.questionbankentryid = qbe.id WHERE qv.questionid = ?',
        [$template->id]
    );
    $questions = $generator->get_plugin_generator('core_question');
    mt_srand(161);
    $now = time();
    for ($i = $DB->count_records('local_catquiz_items', ['contextid' => $contextid]); $i < $poolsize; $i++) {
        $question = $questions->create_question('truefalse', 'true', [
            'category' => $categoryid,
            'name' => 'Load ' . $i,
            'questiontext' => ['text' => 'Statement ' . $i, 'format' => FORMAT_HTML],
        ]);
        $itemid = $DB->insert_record('local_catquiz_items', (object) [
            'componentname' => 'question', 'componentid' => $question->id, 'catscaleid' => $leaves[$i % count($leaves)],
            'contextid' => $contextid, 'activeparamid' => null, 'lastupdated' => 0, 'status' => 0,
        ]);
        $paramid = $DB->insert_record('local_catquiz_itemparams', (object) [
            'itemid' => $itemid, 'componentid' => $question->id, 'componentname' => 'question', 'contextid' => $contextid,
            'model' => 'raschbirnbaum', 'difficulty' => mt_rand(-400, 400) / 100, 'discrimination' => mt_rand(50, 250) / 100,
            'guessing' => 0, 'json' => '{}', 'status' => 4, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->set_field('local_catquiz_items', 'activeparamid', $paramid, ['id' => $itemid]);
    }
}

// One activity per length. The activity's own limit must not end the attempt before the CAT settings do.
$activities = [];
foreach ($lengths as $numitems) {
    $activityidnumber = count($lengths) === 1 ? $idnumber : $idnumber . $numitems;
    $quiz = $generator->create_module('adaptivequiz', ['course' => $course->id, 'name' => 'Load ' . $numitems,
        'idnumber' => $activityidnumber, 'minimumquestions' => 1, 'maximumquestions' => $numitems]);
    $catquiz->create_catquiz_testsettings([
        'courseid' => $course->id,
        'adaptivecatquizid' => $quiz->id,
        'catmodel' => 'catquiz',
        'catscalesid' => $scaleid,
        'cateststrategyid' => $strategy,
        'catquiz_minquestions' => $numitems,
        'catquiz_maxquestions' => $numitems,
        'catquiz_minquestionspersubscale' => 1,
        'catquiz_standarderror_min' => 0.0,
        'catquiz_standarderror_max' => 1000.0,
    ]);
    $cm = get_coursemodule_from_instance('adaptivequiz', $quiz->id, $course->id, false, MUST_EXIST);
    $activities[$numitems] = ['cmid' => (int) $cm->id, 'idnumber' => $activityidnumber];
}

$password = 'Load!' . substr(sha1((string) random_int(0, PHP_INT_MAX)), 0, 10);
$usernames = [];
for ($i = 1; $i <= $numusers; $i++) {
    $user = $generator->create_user(['username' => $userprefix . $i, 'password' => $password]);
    $generator->enrol_user($user->id, $course->id, 'student');
    $usernames[] = $user->username;
}
set_config('attempttrace', 1, 'local_catquiz');
purge_all_caches();

$first = reset($lengths);
printf("export LOAD_BASE_URL='%s'\n", $CFG->wwwroot);
printf("export LOAD_CMID='%d'\n", $activities[$first]['cmid']);
printf("export LOAD_USERS='%s'\n", implode(',', $usernames));
printf("export LOAD_PASSWORD='%s'\n", $password);
printf("export LOAD_ITEMS='%d'\n", $first);
printf("export LOAD_IDNUMBER='%s'\n", $activities[$first]['idnumber']);
foreach ($activities as $numitems => $activity) {
    printf("export LOAD_CMID_%d='%d'\n", $numitems, $activity['cmid']);
    printf("export LOAD_IDNUMBER_%d='%s'\n", $numitems, $activity['idnumber']);
}
printf("export LOAD_POOL='%d'\n", $DB->count_records('local_catquiz_items', [
    'contextid' => (int) \local_catquiz\catscale::get_context_id($scaleid),
]));
