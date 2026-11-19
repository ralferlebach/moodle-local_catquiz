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
 * Reproducible benchmark of CAT attempts (issues #161, #168): real attempts, question by question, measured.
 *
 * Each step is what one request of a learner does: the answer to the last question is scored in its question usage,
 * the CAT engine selects the next question (catquiz_handler::fetch_question_id(): progress, ability, standard error,
 * strategy, feedback data, attempt row), and at the end the attempt is finalised (attempt_finalizer). Answers follow
 * the 2PL model with a true ability drawn per attempt from a fixed seed, so two runs give the same answers as long as
 * the engine selects the same questions.
 *
 * Measured per step: wall time, CPU time (getrusage), SQL time and number of queries, waiting time (wall - CPU - SQL:
 * locks, I/O, scheduling - the queue of the request), peak memory, the size of the stored attempt data (json,
 * debug_info) and the bytes written with it, and the spans of the engine (timeline: select_question,
 * update_attemptfeedback, progress_save, ...). Reported as p50/p95/p99/max, separately for the first and the following
 * questions, cold (every cache emptied before each step) or warm, and by length of the attempt (25/100/250/625).
 *
 *   Setup (creates a course, people and CAT tests on THIS site - only on a test copy):
 *     php local/catquiz/cli/benchmark_attempts.php --setup --confirm [--items=2500] [--users=40]
 *   Run:
 *     php local/catquiz/cli/benchmark_attempts.php --run --strategy=4 --attempts=3 --questions=26 \
 *        [--cold] [--workers=1] [--seed=4] [--json=FILE]
 *
 * The run deletes the earlier attempts of the benchmark test only (the activity the setup created), never others.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

use core_question\local\bank\question_edit_contexts;
use local_catquiz\catquiz_handler;
use local_catquiz\catscale;
use local_catquiz\importer\testitemimporter;
use local_catquiz\local\attempt\attempt_finalizer;
use local_catquiz\local\monitoring\timeline;
use local_catquiz\local\question\question_answer_evaluation;
use local_catquiz\testenvironment;
use mod_adaptivequiz\local\attempt;

[$options] = cli_get_params([
    'help' => false, 'setup' => false, 'confirm' => false, 'run' => false, 'items' => 2500, 'users' => 40,
    'strategy' => LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, 'attempts' => 3, 'questions' => 26, 'minquestions' => 20,
    'semin' => 0.05, 'cold' => false, 'workers' => 1, 'worker' => -1, 'seed' => 4, 'json' => '',
], ['h' => 'help']);

/** @var string Config of local_catquiz with the course, the tests and the pool of the setup. */
const LOCAL_CATQUIZ_BENCHMARK_STATE = 'benchmarkstate';

if ($options['help'] || (!$options['setup'] && !$options['run'])) {
    cli_writeln("Benchmark of CAT attempts (issues #161, #168). See the comment of this file.\n");
    cli_writeln('  --setup --confirm      Course, people, pool (--items, default 2500) and one test per strategy.');
    cli_writeln('  --run                  Plays --attempts attempts of --strategy (default 4, LOWESTSUB),');
    cli_writeln('                         --questions at most (default 26), --cold, --workers=N, --seed, --json=FILE.');
    exit(0);
}

/**
 * Imports the questions of a Moodle XML file into a course.
 *
 * @param stdClass $course
 * @param string $file
 */
function local_catquiz_benchmark_import(stdClass $course, string $file): void {
    $qformat = new qformat_xml();
    $qformat->setContexts((new question_edit_contexts(context_course::instance($course->id)))->all());
    $qformat->setCourse($course);
    if (class_exists('\core_question\local\bank\question_bank_helper')) {
        $qbank = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
        $category = question_get_default_category(context_module::instance($qbank->id)->id, true);
        if ($category) {
            $qformat->setCategory($category);
        }
    }
    $qformat->setFilename($file);
    $qformat->setRealfilename(basename($file));
    $qformat->setMatchgrades('error');
    $qformat->setCatfromfile(1);
    $qformat->setContextfromfile(1);
    $qformat->setStoponerror(1);
    $qformat->setCattofile(1);
    $qformat->setContexttofile(1);
    $qformat->set_display_progress(false);
    ob_start();
    $ok = $qformat->importprocess();
    ob_end_clean();
    if (!$ok) {
        cli_error('The import of the questions failed.');
    }
}

/**
 * The peer comparisons of this process so far (issue #166); 0 with a version that does not count them.
 *
 * @return int
 */
function local_catquiz_benchmark_peers(): int {
    return property_exists(\local_catquiz\catquiz::class, 'peercomparisons') ? \local_catquiz\catquiz::$peercomparisons : 0;
}

/**
 * Percentile of sorted values (nearest rank).
 *
 * @param float[] $values
 * @param float $p 0..1
 * @return float
 */
function local_catquiz_benchmark_percentile(array $values, float $p): float {
    if (!$values) {
        return 0.0;
    }
    sort($values);
    return (float) $values[max(0, (int) ceil($p * count($values)) - 1)];
}

if ($options['setup']) {
    if (!$options['confirm']) {
        cli_error('The setup creates a course, people, questions and tests on this site. Add --confirm on a test copy only.');
    }
    require_once($CFG->libdir . '/testing/generator/lib.php');
    \core\session\manager::set_user(get_admin());
    $generator = new testing_data_generator();
    $course = $generator->create_course(['fullname' => 'CATquiz benchmark', 'shortname' => 'catquizbench' . time()]);
    $fixtures = $CFG->dirroot . '/local/catquiz/tests/fixtures/';
    local_catquiz_benchmark_import($course, $fixtures . 'simulation.xml');
    (new testitemimporter())->execute_testitems_csv_import(
        (object) ['delimiter_name' => 'semicolon', 'encoding' => null, 'dateparseformat' => null],
        file_get_contents($fixtures . 'simulation.csv')
    );
    $root = $DB->get_record_sql(
        'SELECT * FROM {local_catquiz_catscales} WHERE parentid = 0 ORDER BY id DESC',
        [],
        IGNORE_MULTIPLE
    );
    $root->minscalevalue = -10.0;
    $root->maxscalevalue = 10.0;
    $DB->update_record('local_catquiz_catscales', $root);
    \cache_helper::purge_all();
    $contextid = (int) catscale::get_context_id((int) $root->id);

    // Synthetic true/false questions with Rasch-Birnbaum parameters, spread over the leaves, up to --items.
    $leaves = $DB->get_fieldset_sql(
        "SELECT s.id FROM {local_catquiz_catscales} s
          WHERE NOT EXISTS (SELECT 1 FROM {local_catquiz_catscales} c WHERE c.parentid = s.id)
            AND (s.id = :root OR s.parentid <> 0)",
        ['root' => $root->id]
    );
    $existing = $DB->count_records('local_catquiz_items', ['contextid' => $contextid]);
    $template = $DB->get_record_sql(
        "SELECT q.* FROM {question} q JOIN {local_catquiz_items} i ON i.componentid = q.id
          WHERE i.contextid = :c AND q.qtype = 'truefalse' ORDER BY q.id",
        ['c' => $contextid],
        IGNORE_MULTIPLE
    );
    mt_srand(161);
    $qtype = question_bank::get_qtype('truefalse');
    $categoryid = (int) $DB->get_field_sql(
        'SELECT qbe.questioncategoryid FROM {question_bank_entries} qbe JOIN {question_versions} qv
              ON qv.questionbankentryid = qbe.id WHERE qv.questionid = ?',
        [$template->id]
    );
    $categorycontext = (int) $DB->get_field('question_categories', 'contextid', ['id' => $categoryid]);
    $now = time();
    for ($i = $existing; $i < (int) $options['items']; $i++) {
        $form = (object) [
            'category' => $categoryid . ',' . $categorycontext, 'name' => 'Benchmark ' . $i,
            'questiontext' => ['text' => 'Statement ' . $i, 'format' => FORMAT_HTML],
            'generalfeedback' => ['text' => '', 'format' => FORMAT_HTML], 'correctanswer' => 1,
            'feedbacktrue' => ['text' => '', 'format' => FORMAT_HTML], 'feedbackfalse' => ['text' => '', 'format' => FORMAT_HTML],
            'defaultmark' => 1, 'penalty' => 1, 'status' => 'ready', 'idnumber' => null,
        ];
        $question = $qtype->save_question((object) ['category' => $categoryid, 'qtype' => 'truefalse', 'createdby' => 2], $form);
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
    \cache_helper::purge_all();

    $users = [];
    for ($i = 0; $i < (int) $options['users']; $i++) {
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');
        $users[] = (int) $user->id;
    }
    $tests = [];
    $strategies = [LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, LOCAL_CATQUIZ_STRATEGY_RELSUBS, LOCAL_CATQUIZ_STRATEGY_HIGHESTSUB,
        LOCAL_CATQUIZ_STRATEGY_CLASSIC];
    foreach ($strategies as $strategy) {
        $quiz = $generator->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14, 'course' => $course->id,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        $tests[$strategy] = (int) $quiz->id;
    }
    set_config('attempttrace', 1, 'local_catquiz');
    set_config(LOCAL_CATQUIZ_BENCHMARK_STATE, json_encode([
        'course' => (int) $course->id, 'root' => (int) $root->id, 'users' => $users, 'tests' => $tests,
    ]), 'local_catquiz');
    cli_writeln('Setup: ' . json_encode([
        'course' => $course->id,
        'root' => $root->id,
        'items' => $DB->count_records('local_catquiz_items', ['contextid' => $contextid]),
        'tests' => $tests,
    ]));
    exit(0);
}

$state = json_decode((string) get_config('local_catquiz', LOCAL_CATQUIZ_BENCHMARK_STATE), true);
if (!$state) {
    cli_error('No benchmark on this site: run --setup --confirm first.');
}
$strategy = (int) $options['strategy'];
$quizid = $state['tests'][$strategy] ?? null;
if (!$quizid) {
    cli_error('No benchmark test for strategy ' . $strategy);
}

// The settings of the test: the same for every run with the same arguments.
$fixtures = $CFG->dirroot . '/local/catquiz/tests/fixtures/';
\core\session\manager::set_user(get_admin());
$settings = json_decode(file_get_contents($fixtures . 'testenvironment.json'));
$settings->catquiz_catscales = $state['root'];
$settings->catscaleid = $state['root'];
foreach ([$state['root'], ...catscale::get_subscale_ids((int) $state['root'])] as $scaleid) {
    $settings->{'catquiz_subscalecheckbox_' . $scaleid} = true;
}
$settings->componentid = (string) $quizid;
$settings->component = 'mod_adaptivequiz';
$settings->courseid = $state['course'];
$settings->catquiz_selectteststrategy = $strategy;
$settings->maxquestionsgroup->catquiz_minquestions = min((int) $options['minquestions'], (int) $options['questions']);
$settings->maxquestionsgroup->catquiz_maxquestions = (int) $options['questions'];
$settings->maxquestionsscalegroup->catquiz_maxquestionspersubscale = 0;
$settings->maxquestionsscalegroup->catquiz_minquestionspersubscale = 0;
$settings->catquiz_standarderrorgroup->catquiz_standarderror_min = (float) $options['semin'];
$settings->catquiz_standarderrorgroup->catquiz_standarderror_max = 1.0;
$settings->catquiz_pp_min_inc = 0.05;
$settings->catquiz_includepilotquestions = false;
$settings->json = json_encode($settings);

$workers = max(1, (int) $options['workers']);
if ($options['worker'] < 0) {
    // The coordinator: settings, a clean test, then the workers - each a process of its own, as concurrent requests.
    (new testenvironment($settings))->save_or_update();
    $old = $DB->get_fieldset_select('adaptivequiz_attempt', 'id', 'instance = :i', ['i' => $quizid]);
    foreach (array_chunk($old, 500) as $chunk) {
        [$insql, $params] = $DB->get_in_or_equal($chunk);
        $catids = $DB->get_fieldset_select('local_catquiz_attempts', 'id', "attemptid $insql", $params);
        foreach (array_chunk($catids, 500) as $catchunk) {
            [$insql2, $params2] = $DB->get_in_or_equal($catchunk);
            $DB->delete_records_select('local_catquiz_progress', "attemptid $insql2", $params2);
            $DB->delete_records_select('local_catquiz_personparams', "attemptid $insql2", $params2);
        }
        $DB->delete_records_select('local_catquiz_attempts', "attemptid $insql", $params);
        $DB->delete_records_select('adaptivequiz_attempt', "id $insql", $params);
    }
    \cache_helper::purge_all();
    $steps = [];
    if ($workers === 1) {
        $options['worker'] = 0;
    } else {
        $processes = [];
        for ($w = 0; $w < $workers; $w++) {
            $out = make_request_directory() . '/worker.json';
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --run --worker=' . $w
                . ' --workers=' . $workers . ' --strategy=' . $strategy . ' --attempts=' . (int) $options['attempts']
                . ' --questions=' . (int) $options['questions'] . ' --minquestions=' . (int) $options['minquestions']
                . ' --semin=' . (float) $options['semin'] . ' --seed=' . (int) $options['seed']
                . ($options['cold'] ? ' --cold' : '') . ' --json=' . escapeshellarg($out);
            $processes[] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes, $out];
        }
        foreach ($processes as [$process, $pipes, $out]) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            proc_close($process);
            $steps = array_merge($steps, json_decode((string) @file_get_contents($out), true)['steps'] ?? []);
        }
    }
}

if ($options['worker'] >= 0) {
    $quiz = $DB->get_record('adaptivequiz', ['id' => $quizid], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('adaptivequiz', $quizid);
    $context = context_module::instance($cm->id);
    $steps = [];
    $hasreset = function_exists('memory_reset_peak_usage');
    for ($a = 0; $a < (int) $options['attempts']; $a++) {
        $number = (int) $options['worker'] * (int) $options['attempts'] + $a;
        $userid = $state['users'][$number % count($state['users'])];
        \core\session\manager::set_user($DB->get_record('user', ['id' => $userid]));
        $quba = question_engine::make_questions_usage_by_activity('mod_adaptivequiz', $context);
        $quba->set_preferred_behaviour('deferredfeedback');
        $attemptid = (int) (new attempt($quiz, $userid))->get_attempt()->id;
        mt_srand((int) $options['seed'] * 1000 + $number);
        $trueability = mt_rand(-200, 200) / 100;
        $previousbytes = 0;
        for ($i = 0; $i <= (int) $options['questions']; $i++) {
            if ($options['cold']) {
                \cache_helper::purge_all();
                \cache_factory::reset();
            }
            timeline::reset();
            if ($hasreset) {
                memory_reset_peak_usage();
            }
            $record = $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid], '*', MUST_EXIST);
            $attemptobject = new attempt($quiz, $record->userid);
            $queries = $DB->perf_get_queries();
            $peer = local_catquiz_benchmark_peers();
            $sqltime = $DB->perf_get_queries_time();
            $usage = getrusage();
            $start = microtime(true);
            $result = catquiz_handler::fetch_question_id((int) $quizid, 'mod_adaptivequiz', $attemptobject->get_attempt());
            $wall = (microtime(true) - $start) * 1000;
            $after = getrusage();
            $cpu = (($after['ru_utime.tv_sec'] - $usage['ru_utime.tv_sec']) * 1e6 + $after['ru_utime.tv_usec']
                - $usage['ru_utime.tv_usec'] + ($after['ru_stime.tv_sec'] - $usage['ru_stime.tv_sec']) * 1e6
                + $after['ru_stime.tv_usec'] - $usage['ru_stime.tv_usec']) / 1000;
            $sql = ($DB->perf_get_queries_time() - $sqltime) * 1000;
            $spans = [];
            foreach (timeline::get_spans() as $span) {
                if (($span['depth'] ?? 0) <= 2 && $span['ms'] !== null) {
                    $spans[$span['name']] = ($spans[$span['name']] ?? 0) + (float) $span['ms'];
                }
            }
            $row = $DB->get_record_sql(
                'SELECT LENGTH(json) AS jsonbytes, LENGTH(debug_info) AS debugbytes FROM {local_catquiz_attempts}
                  WHERE attemptid = ?',
                [$attemptid]
            );
            $bytes = $row ? (int) $row->jsonbytes + (int) $row->debugbytes : 0;
            $step = [
                'attempt' => $number, 'n' => $i + 1, 'wallms' => round($wall, 2), 'cpums' => round($cpu, 2),
                'sqlms' => round($sql, 2), 'waitms' => round(max(0, $wall - $cpu - $sql), 2),
                'queries' => $DB->perf_get_queries() - $queries,
                'peerqueries' => local_catquiz_benchmark_peers() - $peer,
                'peakkb' => (int) (memory_get_peak_usage() / 1024), 'jsonbytes' => $row ? (int) $row->jsonbytes : 0,
                'storedbytes' => $bytes, 'writtenbytes' => $bytes > 0 && $bytes !== $previousbytes ? $bytes : 0,
                'qid' => (int) ($result[0] ?? 0), 'spans' => array_map(fn($ms) => round($ms, 2), $spans),
            ];
            $previousbytes = $bytes;
            if (empty($result[0])) {
                $step['stop'] = substr((string) ($result[2] ?? $result[1] ?? ''), 0, 60);
                $finishstart = microtime(true);
                $DB->set_field('adaptivequiz_attempt', 'attemptstate', 'complete', ['id' => $attemptid]);
                $DB->set_field('adaptivequiz_attempt', 'timefinished', time(), ['id' => $attemptid]);
                $peer = local_catquiz_benchmark_peers();
                attempt_finalizer::finalize($attemptid, time(), (string) $step['stop']);
                $step['finalisems'] = round((microtime(true) - $finishstart) * 1000, 2);
                $step['finalisepeerqueries'] = local_catquiz_benchmark_peers() - $peer;
                $steps[] = $step;
                break;
            }
            $steps[] = $step;
            $slot = $quba->add_question(question_bank::load_question($result[0]));
            $quba->start_question($slot);
            $correct = $quba->get_correct_response($slot)['answer'];
            $parameters = $DB->get_record_sql(
                'SELECT p.difficulty, p.discrimination FROM {local_catquiz_items} i
                   JOIN {local_catquiz_itemparams} p ON p.id = i.activeparamid WHERE i.componentid = ?',
                [$result[0]],
                IGNORE_MULTIPLE
            );
            $discrimination = $parameters ? (float) $parameters->discrimination : 1.0;
            $difficulty = $parameters ? (float) $parameters->difficulty : 0.0;
            $right = mt_rand(0, 1000) / 1000 < 1 / (1 + exp(-$discrimination * ($trueability - $difficulty)));
            $quba->process_action($slot, ['answer' => $right ? $correct : ($correct >= 1 ? $correct - 1 : $correct + 1)]);
            $quba->finish_all_questions(time());
            (new question_answer_evaluation($quba))->perform($slot);
            question_engine::save_questions_usage_by_activity($quba);
            $attemptrecord = $attemptobject->get_attempt();
            $attemptrecord->timemodified = time();
            $attemptrecord->questionsattempted = ($attemptrecord->questionsattempted ?? 0) + 1;
            $DB->update_record('adaptivequiz_attempt', $attemptrecord);
            if ($i === 0) {
                $attemptobject->set_quba_id($quba->get_id());
            }
        }
    }
    if ($options['worker'] > 0 || $workers > 1) {
        file_put_contents((string) $options['json'], json_encode(['steps' => $steps]));
        exit(0);
    }
}

// The report.
$groups = ['first' => [], 'next' => []];
foreach ($steps as $step) {
    $groups[$step['n'] === 1 ? 'first' : 'next'][] = $step;
}
$report = [
    'meta' => [
        'strategy' => $strategy, 'attempts' => (int) $options['attempts'] * $workers, 'workers' => $workers,
        'questions' => (int) $options['questions'], 'cold' => (bool) $options['cold'], 'seed' => (int) $options['seed'],
        'items' => $DB->count_records('local_catquiz_items', ['contextid' => catscale::get_context_id((int) $state['root'])]),
        'catquiz' => get_config('local_catquiz', 'version'), 'php' => PHP_VERSION, 'db' => $DB->get_dbfamily(),
    ],
    'steps' => $steps,
];
cli_writeln(sprintf(
    "Strategy %d, %d attempt(s) x %d worker(s), at most %d questions, %s, %d items in the pool\n",
    $strategy,
    (int) $options['attempts'],
    $workers,
    (int) $options['questions'],
    $options['cold'] ? 'cold' : 'warm',
    $report['meta']['items']
));
$header = ['step', 'n', 'p50', 'p95', 'p99', 'max', 'cpu p95', 'sql p95', 'wait p95'];
cli_writeln(sprintf('%-6s %5s %9s %9s %9s %9s %9s %8s %9s', ...$header));
foreach ($groups as $name => $rows) {
    if (!$rows) {
        continue;
    }
    $wall = array_column($rows, 'wallms');
    cli_writeln(sprintf(
        '%-6s %5d %9.1f %9.1f %9.1f %9.1f %9.1f %8.1f %9.1f',
        $name,
        count($rows),
        local_catquiz_benchmark_percentile($wall, 0.5),
        local_catquiz_benchmark_percentile($wall, 0.95),
        local_catquiz_benchmark_percentile($wall, 0.99),
        max($wall),
        local_catquiz_benchmark_percentile(array_column($rows, 'cpums'), 0.95),
        local_catquiz_benchmark_percentile(array_column($rows, 'sqlms'), 0.95),
        local_catquiz_benchmark_percentile(array_column($rows, 'waitms'), 0.95)
    ));
    $report['summary'][$name] = [
        'n' => count($rows),
        'wall' => [
            'p50' => local_catquiz_benchmark_percentile($wall, 0.5),
            'p95' => local_catquiz_benchmark_percentile($wall, 0.95),
            'p99' => local_catquiz_benchmark_percentile($wall, 0.99),
            'max' => max($wall),
        ],
        'cpu_p95' => local_catquiz_benchmark_percentile(array_column($rows, 'cpums'), 0.95),
        'sql_p95' => local_catquiz_benchmark_percentile(array_column($rows, 'sqlms'), 0.95),
        'wait_p95' => local_catquiz_benchmark_percentile(array_column($rows, 'waitms'), 0.95),
        'queries_p95' => local_catquiz_benchmark_percentile(array_column($rows, 'queries'), 0.95),
        'peakkb_max' => max(array_column($rows, 'peakkb')),
    ];
}
// Spans of the engine: p95 per span.
$spannames = [];
foreach ($steps as $step) {
    $spannames += array_flip(array_keys($step['spans']));
}
cli_writeln("\nSpans (p95 ms over all steps that have them):");
foreach (array_keys($spannames) as $span) {
    $values = array_values(array_filter(array_map(fn($s) => $s['spans'][$span] ?? null, $steps), fn($v) => $v !== null));
    $report['spans'][$span] = local_catquiz_benchmark_percentile($values, 0.95);
    cli_writeln(sprintf('  %-40s %9.1f', $span, $report['spans'][$span]));
}
// The data of the attempt by its length (issue #168): at the step closest to each mark.
cli_writeln("\nStored attempt data by answers (issue #168):");
$header = ['answers', 'json bytes', 'stored bytes', 'written so far', 'step ms', 'peak KB'];
cli_writeln(sprintf('%8s %12s %14s %16s %12s %10s', ...$header));
foreach ([25, 100, 250, 625] as $mark) {
    $rows = array_values(array_filter($steps, fn($s) => $s['n'] === $mark + 1));
    if (!$rows) {
        continue;
    }
    $written = 0;
    foreach ($steps as $step) {
        if ($step['attempt'] === $rows[0]['attempt'] && $step['n'] <= $mark + 1) {
            $written += $step['writtenbytes'];
        }
    }
    $report['byanswers'][$mark] = [
        'jsonbytes' => $rows[0]['jsonbytes'], 'storedbytes' => $rows[0]['storedbytes'], 'writtenbytes' => $written,
        'wallms' => $rows[0]['wallms'], 'peakkb' => $rows[0]['peakkb'], 'sqlms' => $rows[0]['sqlms'],
        'feedbackms' => $rows[0]['spans']['strategy:update_attemptfeedback'] ?? null,
    ];
    cli_writeln(sprintf(
        '%8d %12d %14d %16d %12.1f %10d',
        $mark,
        $rows[0]['jsonbytes'],
        $rows[0]['storedbytes'],
        $written,
        $rows[0]['wallms'],
        $rows[0]['peakkb']
    ));
}
cli_writeln(sprintf(
    "\nPeer comparisons (issue #166): %d with the answers, %d at the end of the attempts.",
    array_sum(array_column($steps, 'peerqueries')),
    array_sum(array_map(fn($s) => $s['finalisepeerqueries'] ?? 0, $steps))
));
$stops = array_count_values(array_map(fn($s) => $s['stop'], array_filter($steps, fn($s) => isset($s['stop']))));
cli_writeln("\nStops: " . json_encode($stops));
if ($options['json'] !== '') {
    file_put_contents((string) $options['json'], json_encode($report, JSON_PRETTY_PRINT));
}
