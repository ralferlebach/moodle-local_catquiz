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
 * Data for the result views of the re-audit "ab #147" (19.11.2026), on the site of seed_cat_path.php, for
 * tests/view-results.spec.ts, tests/usage-chart-test20.spec.ts and tests/chart-series.spec.ts.
 *
 * Called by the specs, after tests/cat-attempt-path.spec.ts has played on the fresh population of the Simulation
 * scale: the attempts made here would change that population, so they are not made by the seed.
 *
 *   --results          Course C with its teacher: CAT tests of 20 and of 100 questions with three finished attempts of
 *                      other people each (the comparison group, #166), and a test of 8 questions with one finished
 *                      attempt per stored state of a result (#159): as written today, and as older versions left it -
 *                      without the scope of #168, without the comparison of #166 (ended before it), without the
 *                      history of the answers, without the standard error of the subscales, without abilities.
 *                      JSON: cmids, users, attempts by state.
 *   --retention        The real retention task (cleanup_attempt_progress, data-sparing mode). JSON: progress rows of
 *                      finished attempts before and after.
 *   --test20           Engine #160: test 20 of the export of 27.10.2026 reduced to its numbers - 17 persons, one
 *                      completed attempt each under "Infer lowest skill gap", no valid measurement of the root scale,
 *                      13 with a valid subscale - in a context of its own, and a page with its statistics. JSON.
 *   --visible=0|1 --cmid=N   hides or shows an activity.
 *   --break-half       Half of the item parameters disappear (kept in the data directory): a changed pool (#150).
 *   --restore-half     puts them back.
 *   --context-of --attemptid=N   JSON: the CAT context of an attempt of the activity.
 *
 * Not for a production site.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

[$options] = cli_get_params([
    'results' => false, 'retention' => false, 'test20' => false, 'visible' => -1, 'cmid' => 0,
    'break-half' => false, 'restore-half' => false, 'context-of' => false, 'attemptid' => 0,
]);
$keep = $CFG->dataroot . '/catpath_halfitemparams.json';
// No mail from enrolments and attempts of the test site.
$CFG->noemailever = true;

/**
 * The data generator outside PHPUnit.
 *
 * @return testing_data_generator
 */
function local_catquiz_results_generator(): testing_data_generator {
    global $CFG;
    if (class_exists('\core\test\phpunit\phpunit_util')) {
        return \core\test\phpunit\phpunit_util::get_data_generator();
    }
    require_once($CFG->dirroot . '/lib/phpunit/classes/util.php');
    return phpunit_util::get_data_generator();
}

if ($options['context-of']) {
    $contextid = $DB->get_field('local_catquiz_attempts', 'contextid', ['attemptid' => (int) $options['attemptid']], MUST_EXIST);
    echo json_encode(['contextid' => (int) $contextid]) . "\n";
    exit(0);
}
if ($options['break-half']) {
    $rows = $DB->get_records_select('local_catquiz_itemparams', 'MOD(id, 2) = 0');
    if (!file_exists($keep)) {
        file_put_contents($keep, json_encode(array_values($rows)));
    }
    $DB->delete_records_select('local_catquiz_itemparams', 'MOD(id, 2) = 0');
    purge_all_caches();
    echo json_encode(['removed' => count($rows), 'left' => $DB->count_records('local_catquiz_itemparams')]) . "\n";
    exit(0);
}
if ($options['restore-half']) {
    if (file_exists($keep)) {
        foreach (json_decode(file_get_contents($keep)) as $row) {
            if (!$DB->record_exists('local_catquiz_itemparams', ['id' => $row->id])) {
                $DB->insert_record_raw('local_catquiz_itemparams', $row, false, false, true);
            }
        }
        unlink($keep);
    }
    purge_all_caches();
    echo json_encode(['restored' => true, 'rows' => $DB->count_records('local_catquiz_itemparams')]) . "\n";
    exit(0);
}
if ((int) $options['visible'] >= 0) {
    set_coursemodule_visible((int) $options['cmid'], (int) $options['visible']);
    rebuild_course_cache((int) $DB->get_field('course_modules', 'course', ['id' => (int) $options['cmid']]), true);
    echo json_encode(['cmid' => (int) $options['cmid'], 'visible' => (int) $options['visible']]) . "\n";
    exit(0);
}
if ($options['retention']) {
    $finished = fn() => $DB->count_records_sql(
        'SELECT COUNT(1) FROM {local_catquiz_progress} p JOIN {local_catquiz_attempts} a ON a.id = p.attemptid
          WHERE a.endtime IS NOT NULL'
    );
    $before = $finished();
    set_config('progressretention', 'minimal', 'local_catquiz');
    ob_start();
    (new \local_catquiz\task\cleanup_attempt_progress())->execute();
    ob_end_clean();
    purge_all_caches();
    echo json_encode(['before' => $before, 'after' => $finished()]) . "\n";
    exit(0);
}

$generator = local_catquiz_results_generator();
\core\session\manager::set_user(get_admin());
$root = $DB->get_record('local_catquiz_catscales', ['name' => 'Simulation', 'parentid' => 0], '*', MUST_EXIST);
$password = (string) getenv('CATPATH_PASSWORD');
if ($password === '') {
    cli_error('CATPATH_PASSWORD is needed: the people log in with the password of the seed.');
}
$course = $DB->get_record('course', ['shortname' => 'catpath-c']);
if (!$course) {
    $course = $generator->create_course(['fullname' => 'CAT path C', 'shortname' => 'catpath-c', 'enablecompletion' => 1]);
}

// A person of course C with the password of the seed.
$user = function (string $name, string $role = 'student') use ($generator, $password, $course, $DB): stdClass {
    $existing = $DB->get_record('user', ['username' => 'catpath_' . $name]);
    $record = $existing ?: $generator->create_user(['username' => 'catpath_' . $name, 'password' => $password,
        'firstname' => 'Path', 'lastname' => $name]);
    $generator->enrol_user($record->id, $course->id, $role);
    return $record;
};

if ($options['test20']) {
    // Engine #160, as usage_chart_semantics_test::test20(): a context and scales of its own, so nothing of the
    // Simulation scale changes. Scales with two feedback ranges each; S2 with ranges of its own.
    $now = time();
    // As usage_chart_semantics_test: the attempts ended three days ago, after the results were stored with them. The
    // site is younger than that, so its boundary of stored results moves back (a test site; nothing ended before).
    set_config(\local_catquiz\local\result\result_provenance::CONFIG, $now - 400 * DAYSECS, 'local_catquiz');
    $contextid = (int) $DB->insert_record('local_catquiz_catcontext', (object) [
        'name' => 'Test 20 context', 'description' => '', 'descriptionformat' => FORMAT_HTML, 'starttimestamp' => 0,
        'endtimestamp' => $now + 365 * DAYSECS, 'timecreated' => $now, 'timemodified' => $now, 'usermodified' => 0,
    ]);
    $scale = fn(int $parent, string $name) => (int) $DB->insert_record('local_catquiz_catscales', (object) ([
        'parentid' => $parent, 'name' => $name, 'minscalevalue' => -5, 'maxscalevalue' => 5, 'timecreated' => $now,
        'timemodified' => $now,
    ] + ($parent ? [] : ['contextid' => $contextid])));
    $t20root = $scale(0, 'Test20Root');
    $subs = ['S1' => $scale($t20root, 'Test20S1'), 'S2' => $scale($t20root, 'Test20S2')];
    $quiz = $generator->get_plugin_generator('mod_adaptivequiz')->create_instance([
        'course' => $course->id, 'name' => 'Test 20', 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
        'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
    ]);
    $DB->set_field('adaptivequiz', 'catmodel', 'catquiz', ['id' => $quiz->id]);
    $settings = [
        'catquiz_catscales' => $t20root, 'catscaleid' => $t20root,
        'catquiz_selectteststrategy' => LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, 'catquiz_catcontext' => $contextid,
        'numberoffeedbackoptionsselect' => 2, 'name' => 'Test 20', 'componentid' => $quiz->id,
        'component' => 'mod_adaptivequiz', 'courseid' => $course->id,
    ];
    foreach ([$t20root, ...array_values($subs)] as $scaleid) {
        $settings['catquiz_subscalecheckbox_' . $scaleid] = true;
        $ranges = $scaleid === $subs['S2'] ? [1 => [-5, 2.5], 2 => [2.5, 5]] : [1 => [-5, 0], 2 => [0, 5]];
        foreach ($ranges as $j => [$lower, $upper]) {
            $settings["feedback_scaleid_limit_lower_{$scaleid}_{$j}"] = $lower;
            $settings["feedback_scaleid_limit_upper_{$scaleid}_{$j}"] = $upper;
            $settings["wb_colourpicker_{$scaleid}_{$j}"] = $j === 1 ? '3' : '6';
        }
    }
    $DB->insert_record('local_catquiz_tests', (object) [
        'componentid' => $quiz->id, 'component' => 'mod_adaptivequiz', 'catscaleid' => $t20root,
        'contextid' => $contextid, 'courseid' => $course->id, 'name' => 'Test 20', 'status' => 1,
        'json' => json_encode($settings), 'timecreated' => $now, 'timemodified' => $now,
    ]);
    // The 17 persons: 9 valid on S1 at -1.0; on S2 2 valid at 3.0, 1 at 2.0, 1 at exactly 0.0; 4 without a valid
    // result; the root scale measured for everybody, never validly. Ended after the results are stored (provenance).
    $people = [];
    foreach (array_fill(0, 9, -1.0) as $v) {
        $people[] = [$subs['S1'] => [$v, 1, 1]];
    }
    foreach ([3.0, 3.0, 2.0, 0.0] as $v) {
        $people[] = [$subs['S2'] => [$v, 1, 1]];
    }
    for ($i = 0; $i < 4; $i++) {
        $people[] = [$subs['S1'] => [2.0, 0, 1]];
    }
    // Attempt ids no attempt of the activity has, and none of an earlier call of this helper.
    $host = max(
        (int) $DB->get_field_sql('SELECT MAX(id) FROM {adaptivequiz_attempt}'),
        (int) $DB->get_field_sql('SELECT MAX(attemptid) FROM {local_catquiz_attempts}')
    ) + 100000;
    foreach ($people as $k => $rows) {
        // People of their own for every test 20: a second call (a retry) must not give them a second attempt.
        $person = $user("test20_{$contextid}_{$k}");
        // In the past, all on one day: the chart of the attempts per range of time counts them in one period.
        $end = $now - 3 * DAYSECS + $k * 60;
        $id = (int) $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => $person->id, 'scaleid' => $t20root, 'contextid' => $contextid, 'courseid' => $course->id,
            'attemptid' => $host + $k, 'component' => 'mod_adaptivequiz', 'instanceid' => $quiz->id,
            'teststrategy' => LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, 'status' => LOCAL_CATQUIZ_ATTEMPT_OK,
            'starttime' => $end - 600, 'endtime' => $end, 'personability_after_attempt' => 3.5,
            'json' => json_encode(['personabilities' => [$t20root => 3.5]]), 'debug_info' => '',
            'timecreated' => $end - 600, 'timemodified' => $end,
        ]);
        foreach ($rows + [$t20root => [0.7, 0, 0]] as $scaleid => [$ability, $isvalid, $isprimary]) {
            $DB->insert_record('local_catquiz_personparams', (object) [
                'userid' => $person->id, 'catscaleid' => $scaleid, 'contextid' => $contextid, 'attemptid' => $id,
                'ability' => $ability, 'isvalid' => $isvalid, 'isprimary' => $isprimary, 'resultsource' => 'current',
                'validationstatus' => $isvalid ? '' : \local_catquiz\local\result\scale_result::REASON_SE_MAX,
                'status' => 0, 'timecreated' => $end, 'timemodified' => $end,
            ]);
        }
    }
    $cm = get_coursemodule_from_instance('adaptivequiz', $quiz->id, $course->id, false, MUST_EXIST);
    $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Test 20 statistics',
        'content' => '<div data-catpath="test20">[catquizstatistics testid=' . $cm->id . ']</div>',
        'contentformat' => FORMAT_HTML]);
    $user('teacherc', 'editingteacher');
    purge_all_caches();
    echo json_encode(['page' => (int) $page->cmid, 'cmid' => (int) $cm->id, 'persons' => count($people),
        'root' => $t20root, 'subscales' => $subs]) . "\n";
    exit(0);
}

if (!$options['results']) {
    cli_error('Nothing to do: --results, --retention, --test20, --visible, --break-half, --restore-half or --context-of.');
}

$simulator = '\adaptivequizcatmodel_catquiz\local\monitoring\request_simulator';
$catquiz = $generator->get_plugin_generator('local_catquiz');
$test = function (string $name, int $strategy, int $length) use ($generator, $catquiz, $root, $course, $DB): stdClass {
    $instance = $generator->get_plugin_generator('mod_adaptivequiz')->create_instance([
        'course' => $course->id, 'name' => $name, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
        'minimumquestions' => 0, 'maximumquestions' => $length, 'grade' => 100,
        'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionvalidresult' => 1,
        'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
    ]);
    $catquiz->create_catquiz_testsettings([
        'courseid' => $course->id, 'adaptivecatquizid' => $instance->id, 'catscalesid' => $root->id,
        'cateststrategyid' => $strategy, 'catmodel' => 'catquiz', 'catquiz_minquestions' => $length,
        'catquiz_maxquestions' => $length, 'catquiz_minquestionspersubscale' => 0,
        'catquiz_maxquestionspersubscale' => 0, 'catquiz_standarderror_min' => 0.0001,
        'catquiz_standarderror_max' => 1.0, 'catquiz_includetimelimit' => 0,
        // Every scale reported, with one range and its written feedback: the result as a person sees it, with the
        // tab "Overview" and the comparison (without it every scale is "reporting disabled").
        'scalefeedbacktexts' => 1,
    ]);
    $quiz = $DB->get_record('adaptivequiz', ['id' => $instance->id], '*', MUST_EXIST);
    $quiz->cm = get_coursemodule_from_instance('adaptivequiz', $instance->id, $course->id, false, MUST_EXIST);
    $quiz->context = context_module::instance($quiz->cm->id);
    return $quiz;
};
$play = function (stdClass $quiz, stdClass $person, int $length, int $seed) use ($simulator, $DB): int {
    \core\session\manager::set_user($person);
    $simulator::play($quiz, $quiz->context, (int) $person->id, $length, fn(int $n) => ($n + $seed) % 3 !== 1);
    \core\session\manager::set_user(get_admin());
    return (int) $DB->get_field_sql(
        'SELECT MAX(id) FROM {adaptivequiz_attempt} WHERE instance = ? AND userid = ?',
        [$quiz->id, $person->id]
    );
};

$user('teacherc', 'editingteacher');
$out = ['course' => (int) $course->id];
// Engine #166: attempts of 20 and 100 answers, each with a comparison group of three.
foreach ([20, 100] as $length) {
    $quiz = $test("Path length $length", LOCAL_CATQUIZ_STRATEGY_CLASSIC, $length);
    $out["cmid$length"] = (int) $quiz->cm->id;
    for ($p = 0; $p < 3; $p++) {
        $play($quiz, $user("peer{$length}_$p"), $length, $p);
    }
    $user("len$length");
}
// Engine #159: one finished attempt per stored state, three more for a comparison group.
$quiz = $test('Path retention', LOCAL_CATQUIZ_STRATEGY_CLASSIC, 8);
$out['cmidretention'] = (int) $quiz->cm->id;
for ($p = 0; $p < 3; $p++) {
    $play($quiz, $user("rtpeer_$p"), 8, $p);
}
$states = ['current', 'pre168', 'prepeer', 'nohistory', 'nose', 'noabilities'];
$subscales = \local_catquiz\catscale::get_subscale_ids((int) $root->id);
foreach ($states as $k => $state) {
    $attemptid = $play($quiz, $user("rt_$state"), 8, $k);
    $row = $DB->get_record('local_catquiz_attempts', ['attemptid' => $attemptid], '*', MUST_EXIST);
    $json = json_decode((string) $row->json, true);
    switch ($state) {
        case 'pre168':
            // Before 2026111708 every snapshot was complete and had no scope.
            unset($json['feedbackscope']);
            break;
        case 'prepeer':
            // Ended before the comparison was stored with the result (#166): nothing computed afterwards.
            foreach (\local_catquiz\teststrategy\feedbackgenerator\comparetotestaverage::PEER_KEYS as $key) {
                unset($json[$key]);
            }
            break;
        case 'nohistory':
            unset($json['graphicalsummary_data']);
            break;
        case 'nose':
            foreach ($subscales as $scaleid) {
                unset($json['se'][$scaleid]);
            }
            break;
        case 'noabilities':
            unset($json['personabilities'], $json['customscalefeedback_abilities'], $json['updated_personabilities']);
            break;
    }
    $DB->set_field('local_catquiz_attempts', 'json', json_encode($json), ['id' => $row->id]);
    $host = $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid], 'id, instance, uniqueid', MUST_EXIST);
    $out['attempts'][$state] = ['attemptid' => $attemptid, 'catattemptid' => (int) $row->id,
        'contextid' => (int) $row->contextid, 'userid' => (int) $row->userid, 'instance' => (int) $host->instance,
        'uniqueid' => (int) $host->uniqueid];
}
// The comparison is stored with the result since now: the state "prepeer" ended before.
set_config('peercomparisonatendsince', time() + 1, 'local_catquiz');
$user('rt_other');
purge_all_caches();
echo json_encode($out) . "\n";
