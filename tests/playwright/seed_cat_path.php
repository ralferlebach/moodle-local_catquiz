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
 * Seeds the integrated browser path of the re-audit 19.11.2026 (Playwright P0): CAT attempts through host, CAT model
 * and engine, in a real browser (tests/cat-attempt-path.spec.ts, tests/shortcode-id-lists.spec.ts).
 *
 * Course A: completion on; one activity per strategy (FASTEST, ALLSUBS, LOWESTSUB, HIGHESTSUB, CLASSIC, RELSUBS; 8
 * questions each, "valid result" as completion rule); the two replays of engine #172 (RELSUBS ends after 17,
 * LOWESTSUB after 16 with all scales of the set complete); one RELSUBS activity for the technical error before and
 * one after the minimum; one hidden activity; a page with the statistics shortcodes over 1, 3, 25 and 100 tests and
 * over both courses. Course B: its own teacher, its tests. 100 CAT tests in all (82 in A, 18 in B), each with
 * finished attempts of server-side people where the statistics need them - real attempts through the CAT model's
 * request simulator, not rows written by hand.
 *
 * Prints "export KEY='value'" lines (CATPATH_*). Not for a production site.
 *
 * Usage (from the Moodle root): php local/catquiz/tests/playwright/seed_cat_path.php
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once(__DIR__ . '/seed_lib.php');

if (class_exists('\core\test\phpunit\phpunit_util')) {
    $generator = \core\test\phpunit\phpunit_util::get_data_generator();
} else {
    require_once($CFG->dirroot . '/lib/phpunit/classes/util.php');
    $generator = phpunit_util::get_data_generator();
}
\core\session\manager::set_user(get_admin());
$simulator = '\adaptivequizcatmodel_catquiz\local\monitoring\request_simulator';
if (!class_exists($simulator)) {
    cli_error('The CAT model adaptivequizcatmodel_catquiz with its request simulator is needed.');
}
if ($DB->record_exists('course', ['shortname' => 'catpath-a'])) {
    cli_error('The CAT path is seeded already (course catpath-a).');
}

// Moodle 5.0+: question banks live in qbank activities; until the migration tasks have run, view.php tells a
// participant that the item bank is not configured - no "Start attempt" (seed_lib.php).
local_catquiz_seed_run_qbank_migration();

set_config('enablecompletion', 1);
filter_set_global_state('shortcodes', TEXTFILTER_ON);
$password = 'Path!' . substr(sha1((string) random_int(0, PHP_INT_MAX)), 0, 10);

$coursea = $generator->create_course(['fullname' => 'CAT path A', 'shortname' => 'catpath-a', 'enablecompletion' => 1]);
$courseb = $generator->create_course(['fullname' => 'CAT path B', 'shortname' => 'catpath-b', 'enablecompletion' => 1]);
$catquiz = $generator->get_plugin_generator('local_catquiz');
$catquiz->create_catquiz_questions([
    'filepath' => 'local/catquiz/tests/fixtures/simulation.xml', 'filename' => 'simulation.xml', 'courseid' => $coursea->id,
]);
$catquiz->create_catquiz_importedcatscales(['filename' => 'simulation.csv']);
$root = $DB->get_record('local_catquiz_catscales', ['name' => 'Simulation', 'parentid' => 0], '*', MUST_EXIST);
$root->minscalevalue = -10.0;
$root->maxscalevalue = 10.0;
$DB->update_record('local_catquiz_catscales', $root);

$users = [];
$user = function (string $name, array $courses, string $role = 'student') use ($generator, $password, &$users) {
    $record = $generator->create_user(['username' => 'catpath_' . $name, 'password' => $password,
        'firstname' => 'Path', 'lastname' => $name]);
    foreach ($courses as $course) {
        $generator->enrol_user($record->id, $course->id, $role);
    }
    $users[$name] = $record;
    return $record;
};
$user('teachera', [$coursea], 'editingteacher');
$user('teacherb', [$courseb], 'editingteacher');

// A CAT test of the Simulation scale: course, name, strategy, settings, the names of the scales selected (empty:
// all), visibility. Returns the adaptivequiz with its context and cm.
$test = function (
    stdClass $course,
    string $name,
    int $strategy,
    array $settings = [],
    array $scales = [],
    bool $visible = true
) use (
    $generator,
    $catquiz,
    $root,
    $DB
): stdClass {
    $length = $settings['catquiz_maxquestions'] ?? 8;
    $instance = $generator->get_plugin_generator('mod_adaptivequiz')->create_instance([
        'course' => $course->id, 'name' => $name, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
        'minimumquestions' => 0, 'maximumquestions' => $length, 'visible' => $visible ? 1 : 0, 'grade' => 100,
        'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionvalidresult' => 1,
        'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
    ]);
    $catquiz->create_catquiz_testsettings($settings + [
        'courseid' => $course->id,
        'adaptivecatquizid' => $instance->id,
        'catscalesid' => $root->id,
        'cateststrategyid' => $strategy,
        'catmodel' => 'catquiz',
        'catquiz_minquestions' => $length,
        'catquiz_maxquestions' => $length,
        'catquiz_minquestionspersubscale' => 0,
        'catquiz_maxquestionspersubscale' => 0,
        'catquiz_standarderror_min' => 0.0001,
        'catquiz_standarderror_max' => 1.0,
        'catquiz_includetimelimit' => 0,
        'numberoffeedbackoptions' => 2,
    ]);
    if ($scales) {
        $settingsrow = $DB->get_record(
            'local_catquiz_tests',
            ['componentid' => $instance->id, 'component' => 'mod_adaptivequiz'],
            '*',
            MUST_EXIST
        );
        $json = json_decode($settingsrow->json);
        foreach ($DB->get_records('local_catquiz_catscales', null, 'id', 'id, name') as $scale) {
            $json->{'catquiz_subscalecheckbox_' . $scale->id} = in_array($scale->name, $scales, true);
        }
        $DB->set_field('local_catquiz_tests', 'json', json_encode($json), ['id' => $settingsrow->id]);
    }
    $quiz = $DB->get_record('adaptivequiz', ['id' => $instance->id], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('adaptivequiz', $instance->id, $course->id, false, MUST_EXIST);
    $quiz->cm = $cm;
    $quiz->context = context_module::instance($cm->id);
    return $quiz;
};

$strategies = [
    'fastest' => LOCAL_CATQUIZ_STRATEGY_FASTEST, 'allsubs' => LOCAL_CATQUIZ_STRATEGY_ALLSUBS,
    'lowestsub' => LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, 'highestsub' => LOCAL_CATQUIZ_STRATEGY_HIGHESTSUB,
    'classic' => LOCAL_CATQUIZ_STRATEGY_CLASSIC, 'relsubs' => LOCAL_CATQUIZ_STRATEGY_RELSUBS,
];
$out = [];
$alltests = [];
foreach ($strategies as $key => $strategy) {
    $quiz = $test($coursea, 'Path ' . strtoupper($key), $strategy);
    $out['CATPATH_CMID_' . strtoupper($key)] = $quiz->cm->id;
    $alltests[] = $quiz;
    $user($key, [$coursea]);
}
// Engine #172 replays: the shape of the two production attempts (12 to 25, 3 to 5 per subscale, a scale set).
$replay = ['catquiz_minquestions' => 12, 'catquiz_maxquestions' => 25, 'catquiz_minquestionspersubscale' => 3,
    'catquiz_maxquestionspersubscale' => 5];
$quiz = $test(
    $coursea,
    'Path replay RELSUBS',
    LOCAL_CATQUIZ_STRATEGY_RELSUBS,
    $replay,
    ['Simulation', 'SimC', 'SimC01', 'SimC02', 'SimC03', 'SimC04', 'SimC05']
);
$out['CATPATH_CMID_REPLAY_RELSUBS'] = $quiz->cm->id;
$alltests[] = $quiz;
$quiz = $test(
    $coursea,
    'Path replay LOWESTSUB',
    LOCAL_CATQUIZ_STRATEGY_LOWESTSUB,
    $replay,
    ['Simulation', 'SimB', 'SimB01', 'SimB02', 'SimB03', 'SimB04']
);
$out['CATPATH_CMID_REPLAY_LOWESTSUB'] = $quiz->cm->id;
$alltests[] = $quiz;
foreach (['before', 'after'] as $when) {
    $quiz = $test(
        $coursea,
        'Path error ' . $when,
        LOCAL_CATQUIZ_STRATEGY_RELSUBS,
        $replay,
        ['Simulation', 'SimC', 'SimC01', 'SimC02', 'SimC03', 'SimC04', 'SimC05']
    );
    $out['CATPATH_CMID_ERROR_' . strtoupper($when)] = $quiz->cm->id;
    $alltests[] = $quiz;
}
// The two lengths of the production cases of engine #174 (LTI): lowest skill gap after 13, relevant subscales after 17.
$quiz = $test($coursea, 'Path length 13', LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, ['catquiz_maxquestions' => 13]);
$out['CATPATH_CMID_LEN13'] = $quiz->cm->id;
$alltests[] = $quiz;
$quiz = $test($coursea, 'Path length 17', LOCAL_CATQUIZ_STRATEGY_RELSUBS, ['catquiz_maxquestions' => 17]);
$out['CATPATH_CMID_LEN17'] = $quiz->cm->id;
$alltests[] = $quiz;
$quiz = $test($coursea, 'Path hidden', LOCAL_CATQUIZ_STRATEGY_CLASSIC, [], [], false);
$out['CATPATH_CMID_HIDDEN'] = $quiz->cm->id;
$alltests[] = $quiz;
foreach (
    ['replayrel', 'replaylow', 'len13', 'len17', 'errorbefore', 'errorafter', 'hidden', 'reload', 'double', 'twotabs',
        'mobile'] as $name
) {
    $user($name, [$coursea]);
}

// Up to 100 CAT tests for the statistics shortcodes: the rest of A, 18 in B. Finished attempts of server-side
// people in some of them, so the statistics have something to show in both courses.
$courseatests = $alltests;
for ($i = count($alltests); $i < 82; $i++) {
    $courseatests[] = $test($coursea, 'Statistics A' . $i, LOCAL_CATQUIZ_STRATEGY_CLASSIC);
}
$coursebtests = [];
for ($i = 0; $i < 18; $i++) {
    $coursebtests[] = $test($courseb, 'Statistics B' . $i, LOCAL_CATQUIZ_STRATEGY_CLASSIC);
}
$played = 0;
$statsattempts = [];
foreach ([[$coursea, array_slice($courseatests, 20, 3)], [$courseb, array_slice($coursebtests, 0, 3)]] as [$course, $tests]) {
    foreach ($tests as $j => $quiz) {
        for ($p = 0; $p < 3; $p++) {
            $person = $user("stats{$course->id}_{$j}_{$p}", [$course]);
            \core\session\manager::set_user($person);
            $simulator::play($quiz, $quiz->context, (int) $person->id, 8, fn(int $n) => ($n + $p) % 3 !== 1);
            $played++;
            $statsattempts[$course->shortname] ??= (int) $DB->get_field_sql(
                'SELECT MAX(id) FROM {adaptivequiz_attempt} WHERE instance = ? AND userid = ?',
                [$quiz->id, $person->id]
            );
        }
    }
}
\core\session\manager::set_user(get_admin());

// The statistics page of course A: lists of 1, 3, 25 and 100 tests, both courses, and the feedback of one attempt.
$cmids = array_map(fn($q) => (int) $q->cm->id, array_merge($courseatests, $coursebtests));
$statsa = array_map(fn($q) => (int) $q->cm->id, array_slice($courseatests, 20, 3));
$statsb = array_map(fn($q) => (int) $q->cm->id, array_slice($coursebtests, 0, 3));
$sections = [
    'one' => [$statsa[0]],
    'three' => $statsa,
    'twentyfive' => array_merge($statsa, array_slice($cmids, 0, 22)),
    'hundred' => $cmids,
];
$content = '';
foreach ($sections as $key => $list) {
    $content .= "<h3 id=\"catpath-{$key}\">List {$key}</h3>\n<div data-catpath=\"{$key}\">[catquizstatistics testid="
        . implode(',', array_unique($list)) . "]</div>\n";
}
$content .= "<h3 id=\"catpath-courses\">Courses</h3>\n<div data-catpath=\"courses\">[catquizstatistics courseid="
    . $coursea->id . ',' . $courseb->id . " globalscale={$root->id}]</div>\n";
$content .= "<h3 id=\"catpath-onlyb\">Only B</h3>\n<div data-catpath=\"onlyb\">[catquizstatistics testid="
    . implode(',', $statsb) . "]</div>\n";
$page = $generator->create_module('page', ['course' => $coursea->id, 'name' => 'CAT path statistics',
    'content' => $content, 'contentformat' => FORMAT_HTML]);
// The same lists on a page of course B, for the teacher of B.
$pageb = $generator->create_module('page', ['course' => $courseb->id, 'name' => 'CAT path statistics B',
    'content' => "<div data-catpath=\"onlyb\">[catquizstatistics testid=" . implode(',', $statsb) . "]</div>\n"
        . "<div data-catpath=\"courses\">[catquizstatistics courseid={$coursea->id},{$courseb->id}"
        . " globalscale={$root->id}]</div>\n",
    'contentformat' => FORMAT_HTML]);

set_config('attempttrace', 0, 'local_catquiz');
purge_all_caches();

$out += [
    'CATPATH_BASE_URL' => $CFG->wwwroot,
    'CATPATH_PASSWORD' => $password,
    'CATPATH_DIRROOT' => $CFG->dirroot,
    'CATPATH_COURSE_A' => $coursea->id,
    'CATPATH_COURSE_B' => $courseb->id,
    'CATPATH_PAGE_CMID' => $page->cmid,
    'CATPATH_PAGEB_CMID' => $pageb->cmid,
    'CATPATH_SCALEID' => $root->id,
    'CATPATH_TESTS' => count($cmids),
    'CATPATH_PLAYED' => $played,
    'CATPATH_STATS_ATTEMPT_A' => $statsattempts['catpath-a'] ?? 0,
    'CATPATH_STATS_ATTEMPT_B' => $statsattempts['catpath-b'] ?? 0,
];
foreach ($out as $key => $value) {
    printf("export %s='%s'\n", $key, $value);
}
