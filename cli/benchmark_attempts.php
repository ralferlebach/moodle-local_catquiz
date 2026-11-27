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
 * Measured per step: wall time, CPU time (getrusage), SQL time, number of queries and of writes (DML), waiting time
 * (wall - CPU - SQL: locks, I/O, scheduling - the queue of the request), peak memory, the size of the stored attempt
 * data (json, debug_info) and the bytes written with it, and the spans of the engine (timeline: select_question,
 * update_attemptfeedback, progress_save, ...). Reported as p50/p95/p99/max, separately for the first and the following
 * questions, cold (every cache emptied before each step) or warm, and by length of the attempt (25/100/250/625).
 *
 *   Setup (creates a course, people and CAT tests on THIS site - only on a test copy):
 *     php local/catquiz/cli/benchmark_attempts.php --setup --confirm [--items=2500] [--users=40]
 *   Run:
 *     php local/catquiz/cli/benchmark_attempts.php --run --strategy=4 --attempts=3 --questions=26 \
 *        [--cold] [--workers=1] [--seed=4] [--json=FILE] [--everyanswer] [--hostend]
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
    'everyanswer' => false, 'hostend' => false,
], ['h' => 'help']);

// Issue #168 AK2 (decision 19.11.2026): per answer only the generators with a history part; --everyanswer runs every
// generator per answer as before - the comparison on the same code.
\local_catquiz\output\attemptfeedback::$everyanswer = (bool) $options['everyanswer'];

/** @var string Config of local_catquiz with the course, the tests and the pool of the setup. */
const LOCAL_CATQUIZ_BENCHMARK_STATE = 'benchmarkstate';

if ($options['help'] || (!$options['setup'] && !$options['run'])) {
    cli_writeln("Benchmark of CAT attempts (issues #161, #168). See the comment of this file.\n");
    cli_writeln('  --setup --confirm      Course, people, pool (--items, default 2500) and one test per strategy.');
    cli_writeln('  --run                  Plays --attempts attempts of --strategy (default 4, LOWESTSUB),');
    cli_writeln('                         --questions at most (default 26), --cold, --workers=N, --seed, --json=FILE.');
    cli_writeln('  --everyanswer          Every feedback generator per answer, as before #168 AK2 (comparison).');
    cli_writeln('  --hostend              The end as the activity ends an attempt: the question usage finished and');
    cli_writeln('                         saved, then mod_adaptivequiz completes it (result, grades, event, finaliser).');
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
    // Any question of the imported pool names the category the synthetic ones go to (the fixture has no true/false
    // question; a site restored from a dump with enough items never needed it).
    $template = $DB->get_record_sql(
        "SELECT q.* FROM {question} q JOIN {local_catquiz_items} i ON i.componentid = q.id
          WHERE i.contextid = :c ORDER BY q.id",
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
    // Issue #177 (golden, AK4): all six strategies of the selection.
    $strategies = [LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, LOCAL_CATQUIZ_STRATEGY_RELSUBS, LOCAL_CATQUIZ_STRATEGY_HIGHESTSUB,
        LOCAL_CATQUIZ_STRATEGY_CLASSIC, LOCAL_CATQUIZ_STRATEGY_FASTEST, LOCAL_CATQUIZ_STRATEGY_ALLSUBS];
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
            if ($DB->get_manager()->table_exists('local_catquiz_progress_steps')) {
                // Issue #177: the events of the working state, where the version has them.
                $DB->delete_records_select('local_catquiz_progress_steps', "catattemptid $insql2", $params2);
            }
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
                . ($options['cold'] ? ' --cold' : '') . ($options['everyanswer'] ? ' --everyanswer' : '')
                . ($options['hostend'] ? ' --hostend' : '')
                . ' --json=' . escapeshellarg($out);
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
        // Issue #177 (DoD6): the bytes the database writes per step - the write-ahead log of PostgreSQL, the redo log of
        // MariaDB/MySQL -, all tables; and the events of the working state, where the version has them.
        $dbwritten = function () use ($DB): ?int {
            try {
                if ($DB->get_dbfamily() === 'postgres') {
                    return (int) $DB->get_field_sql("SELECT pg_wal_lsn_diff(pg_current_wal_lsn(), '0/0')::bigint");
                }
                if ($DB->get_dbfamily() === 'mysql') {
                    $row = $DB->get_record_sql("SHOW GLOBAL STATUS LIKE 'Innodb_os_log_written'");
                    return $row ? (int) $row->value : null;
                }
            } catch (\Throwable $e) {
                return null;
            }
            return null;
        };
        $haseventtable = $DB->get_manager()->table_exists('local_catquiz_progress_steps');
        $lastevent = 0;
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
            $walbefore = $dbwritten();
            $queries = $DB->perf_get_queries();
            $writes = $DB->perf_get_writes();
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
                // The encodings of the stored data lie deeper; they are taken by name (issue #168, re-audit 22.11.2026).
                $deep = in_array($span['name'], ['progress:json_encode', 'feedback:json_encode'], true);
                if ((($span['depth'] ?? 0) <= 2 || $deep) && $span['ms'] !== null) {
                    $spans[$span['name']] = ($spans[$span['name']] ?? 0) + (float) $span['ms'];
                }
            }
            $row = $DB->get_record_sql(
                'SELECT LENGTH(json) AS jsonbytes, LENGTH(debug_info) AS debugbytes FROM {local_catquiz_attempts}
                  WHERE attemptid = ?',
                [$attemptid]
            );
            $bytes = $row ? (int) $row->jsonbytes + (int) $row->debugbytes : 0;
            // Issue #168: the progress is written with every answer as well - it is part of what an answer writes.
            $progressbytes = (int) $DB->get_field_sql(
                'SELECT LENGTH(p.json) FROM {local_catquiz_progress} p
                   JOIN {local_catquiz_attempts} a ON a.id = p.attemptid
                  WHERE a.attemptid = ?',
                [$attemptid]
            );
            // Issue #177: the head as it is written with every save (JSON and settings), how often it was saved in the step,
            // and the events the step appended.
            $walafter = $dbwritten();
            $head = $DB->get_record_sql(
                'SELECT p.id, LENGTH(p.json) AS jsonbytes, LENGTH(p.quizsettings) AS settingsbytes, a.id AS catattemptid
                   FROM {local_catquiz_progress} p
                   JOIN {local_catquiz_attempts} a ON a.id = p.attemptid
                  WHERE a.attemptid = ?',
                [$attemptid]
            );
            $saves = 0;
            foreach (timeline::get_spans() as $span) {
                if ($span['name'] === 'progress:json_encode') {
                    $saves++;
                }
            }
            $events = ['n' => 0, 'textbytes' => 0];
            if ($haseventtable && $head) {
                $events = (array) $DB->get_record_sql(
                    'SELECT COUNT(1) AS n, COALESCE(SUM(' . $DB->sql_length('COALESCE(diagnostics, \'\')') . ' + '
                        . $DB->sql_length('COALESCE(estimatesdelta, \'\')') . '), 0) AS textbytes, MAX(id) AS maxid
                       FROM {local_catquiz_progress_steps} WHERE catattemptid = ? AND id > ?',
                    [$head->catattemptid, $lastevent]
                );
                $lastevent = max($lastevent, (int) ($events['maxid'] ?? 0));
            }
            $headbytes = $head ? (int) $head->jsonbytes + (int) $head->settingsbytes : 0;
            $step = [
                'attempt' => $number, 'n' => $i + 1, 'wallms' => round($wall, 2), 'cpums' => round($cpu, 2),
                'sqlms' => round($sql, 2), 'waitms' => round(max(0, $wall - $cpu - $sql), 2),
                'queries' => $DB->perf_get_queries() - $queries, 'writes' => $DB->perf_get_writes() - $writes,
                'peerqueries' => local_catquiz_benchmark_peers() - $peer,
                'peakkb' => (int) (memory_get_peak_usage() / 1024), 'jsonbytes' => $row ? (int) $row->jsonbytes : 0,
                'storedbytes' => $bytes, 'writtenbytes' => $bytes > 0 && $bytes !== $previousbytes ? $bytes : 0,
                'progressbytes' => $progressbytes,
                'headbytes' => $headbytes, 'progresssaves' => $saves, 'headwritten' => $saves * $headbytes,
                'events' => (int) $events['n'], 'eventtextbytes' => (int) $events['textbytes'],
                'debugbytes' => $row ? (int) $row->debugbytes : 0,
                'walbytes' => $walbefore !== null && $walafter !== null ? $walafter - $walbefore : null,
                'qid' => (int) ($result[0] ?? 0), 'spans' => array_map(fn($ms) => round($ms, 2), $spans),
            ];
            $previousbytes = $bytes;
            // Issue #177 (golden, AK4): the estimates after the step - the measure and standard error the activity keeps and
            // the abilities of the attempt by scale -, read after the measurement of the step.
            $after = $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid], 'measure, standarderror, questionsattempted');
            $step['measure'] = $after ? (float) $after->measure : null;
            $step['se'] = $after ? (float) $after->standarderror : null;
            $step['answered'] = $after ? (int) $after->questionsattempted : null;
            if ($head) {
                $step['abilities'] = array_map(
                    fn($pp) => [(float) $pp->ability, $pp->standarderror === null ? null : (float) $pp->standarderror],
                    $DB->get_records(
                        'local_catquiz_personparams',
                        ['attemptid' => $head->catattemptid],
                        'catscaleid',
                        'catscaleid, ability, standarderror'
                    )
                );
            }
            if (empty($result[0])) {
                $step['stop'] = substr((string) ($result[2] ?? $result[1] ?? ''), 0, 60);
                timeline::reset();
                $finishstart = microtime(true);
                $finishwal = $dbwritten();
                $peer = local_catquiz_benchmark_peers();
                $endqueries = $DB->perf_get_queries();
                $endwrites = $DB->perf_get_writes();
                if ($options['hostend']) {
                    // Critical-friend re-audit of 23.11.2026: the end of the whole request - the question usage, the
                    // completion by the activity (result snapshot, grades, event) and, through the CAT model, the
                    // finaliser.
                    timeline::span('end:quba_finish', function () use ($quba) {
                        $quba->finish_all_questions();
                        question_engine::save_questions_usage_by_activity($quba);
                    });
                    $record = $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid], '*', MUST_EXIST);
                    // The benchmark drives the engine itself, so its test names no CAT model; the activity reaches
                    // the finaliser through the one of the engine.
                    $catquiz = clone $quiz;
                    $catquiz->catmodel = 'catquiz';
                    timeline::span('end:host_complete', fn() => adaptivequiz_complete_attempt_record(
                        $record,
                        $catquiz,
                        $context,
                        (int) $record->userid,
                        (string) $step['stop']
                    ));
                } else {
                    $DB->set_field('adaptivequiz_attempt', 'attemptstate', 'complete', ['id' => $attemptid]);
                    $DB->set_field('adaptivequiz_attempt', 'timefinished', time(), ['id' => $attemptid]);
                    attempt_finalizer::finalize($attemptid, time(), (string) $step['stop']);
                }
                $step['finalisems'] = round((microtime(true) - $finishstart) * 1000, 2);
                $finishwalafter = $dbwritten();
                $step['finalisewalbytes'] = $finishwal !== null && $finishwalafter !== null ? $finishwalafter - $finishwal : null;
                $step['finalisequeries'] = $DB->perf_get_queries() - $endqueries;
                $step['finalisewrites'] = $DB->perf_get_writes() - $endwrites;
                $step['finalisepeakkb'] = (int) (memory_get_peak_usage() / 1024);
                $step['finalisepeerqueries'] = local_catquiz_benchmark_peers() - $peer;
                // Issue #177 (AK9): the stored data of the ended attempt - the final snapshot and the debug data.
                $final = $DB->get_record_sql(
                    'SELECT ' . $DB->sql_length('COALESCE(json, \'\')') . ' AS jsonbytes, '
                        . $DB->sql_length('COALESCE(debug_info, \'\')') . ' AS debugbytes
                       FROM {local_catquiz_attempts} WHERE attemptid = ?',
                    [$attemptid]
                );
                $step['finaljsonbytes'] = $final ? (int) $final->jsonbytes : null;
                $step['finaldebugbytes'] = $final ? (int) $final->debugbytes : null;
                // Each part of the end on its own (finalizer:*, end:*); the comparison with the other people (#166).
                $step['endspans'] = [];
                foreach (timeline::get_spans() as $span) {
                    if (preg_match('/^(finalizer|end|adapter):/', $span['name']) && $span['ms'] !== null) {
                        $step['endspans'][$span['name']] = round(
                            ($step['endspans'][$span['name']] ?? 0) + (float) $span['ms'],
                            2
                        );
                    }
                    if ($span['name'] === 'finalizer:peer_comparison' && $span['ms'] !== null) {
                        $step['peerms'] = round((float) $span['ms'], 2);
                    }
                }
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
        'everyanswer' => (bool) $options['everyanswer'],
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
$header = ['answers', 'json bytes', 'stored bytes', 'written so far', 'DML step', 'DML so far', 'step ms', 'peak KB',
    'progress bytes', 'progress so far'];
cli_writeln(sprintf('%8s %12s %14s %16s %10s %12s %12s %10s %15s %16s', ...$header));
foreach ([25, 100, 250, 625] as $mark) {
    $rows = array_values(array_filter($steps, fn($s) => $s['n'] === $mark + 1));
    if (!$rows) {
        continue;
    }
    $written = 0;
    $dml = 0;
    $progresswritten = 0;
    foreach ($steps as $step) {
        if ($step['attempt'] === $rows[0]['attempt'] && $step['n'] <= $mark + 1) {
            $written += $step['writtenbytes'];
            $progresswritten += $step['progressbytes'] ?? 0;
            $dml += $step['writes'] ?? 0;
        }
    }
    $report['byanswers'][$mark] = [
        'jsonbytes' => $rows[0]['jsonbytes'], 'storedbytes' => $rows[0]['storedbytes'], 'writtenbytes' => $written,
        'writes' => $rows[0]['writes'] ?? null, 'writessofar' => $dml,
        'wallms' => $rows[0]['wallms'], 'peakkb' => $rows[0]['peakkb'], 'sqlms' => $rows[0]['sqlms'],
        'feedbackms' => $rows[0]['spans']['strategy:update_attemptfeedback'] ?? null,
        'progressbytes' => $rows[0]['progressbytes'] ?? 0, 'progresswritten' => $progresswritten,
    ];
    cli_writeln(sprintf(
        '%8d %12d %14d %16d %10d %12d %12.1f %10d %15d %16d',
        $mark,
        $rows[0]['jsonbytes'],
        $rows[0]['storedbytes'],
        $written,
        $rows[0]['writes'] ?? 0,
        $dml,
        $rows[0]['wallms'],
        $rows[0]['peakkb'],
        $rows[0]['progressbytes'] ?? 0,
        $progresswritten
    ));
}
// Issue #168 (re-audit of 22.11.2026): the cost of a step by the number of answers before it - over the ten steps up
// to the mark, so that one slow step does not stand for the length: wall, CPU, SQL, queries, DML, memory and the spans
// of the feedback and of the encoding of the stored data and the progress.
cli_writeln("\nCost of a step by answers (issue #168; the ten steps up to the mark, all attempts):");
$header = ['answers', 'wall p50', 'wall p95', 'cpu p95', 'sql p95', 'queries', 'DML', 'peak KB', 'feedback p95',
    'fb json p95', 'prog json p95', 'prog save p95'];
cli_writeln(sprintf('%8s %9s %9s %9s %9s %8s %6s %9s %13s %12s %14s %14s', ...$header));
foreach ([25, 100, 250, 625] as $mark) {
    $window = array_values(array_filter($steps, fn($s) => $s['n'] > $mark - 9 && $s['n'] <= $mark + 1));
    if (count($window) < 10 || !array_filter($steps, fn($s) => $s['n'] === $mark + 1)) {
        continue;
    }
    $col = fn($key) => array_map(fn($s) => (float) ($s[$key] ?? 0), $window);
    $span = fn($name) => array_map(fn($s) => (float) ($s['spans'][$name] ?? 0), $window);
    $row = [
        'wall_p50' => local_catquiz_benchmark_percentile($col('wallms'), 0.5),
        'wall_p95' => local_catquiz_benchmark_percentile($col('wallms'), 0.95),
        'cpu_p95' => local_catquiz_benchmark_percentile($col('cpums'), 0.95),
        'sql_p95' => local_catquiz_benchmark_percentile($col('sqlms'), 0.95),
        'queries_p50' => local_catquiz_benchmark_percentile($col('queries'), 0.5),
        'writes_p50' => local_catquiz_benchmark_percentile($col('writes'), 0.5),
        'peakkb_max' => max($col('peakkb')),
        'feedback_p95' => local_catquiz_benchmark_percentile($span('strategy:update_attemptfeedback'), 0.95),
        'feedbackjson_p95' => local_catquiz_benchmark_percentile($span('feedback:json_encode'), 0.95),
        'progressjson_p95' => local_catquiz_benchmark_percentile($span('progress:json_encode'), 0.95),
        'progresssave_p95' => local_catquiz_benchmark_percentile($span('strategy:progress_save'), 0.95),
    ];
    $report['stepcost'][$mark] = $row;
    cli_writeln(sprintf(
        '%8d %9.1f %9.1f %9.1f %9.1f %8d %6d %9d %13.1f %12.2f %14.2f %14.1f',
        $mark,
        $row['wall_p50'],
        $row['wall_p95'],
        $row['cpu_p95'],
        $row['sql_p95'],
        $row['queries_p50'],
        $row['writes_p50'],
        $row['peakkb_max'],
        $row['feedback_p95'],
        $row['feedbackjson_p95'],
        $row['progressjson_p95'],
        $row['progresssave_p95']
    ));
}
cli_writeln(sprintf(
    "\nPeer comparisons (issue #166): %d with the answers, %d at the end of the attempts.",
    array_sum(array_column($steps, 'peerqueries')),
    array_sum(array_map(fn($s) => $s['finalisepeerqueries'] ?? 0, $steps))
));
$peerms = array_values(array_filter(array_map(fn($s) => $s['peerms'] ?? null, $steps), fn($v) => $v !== null));
$finalisems = array_values(array_filter(array_map(fn($s) => $s['finalisems'] ?? null, $steps), fn($v) => $v !== null));
if ($finalisems) {
    $report['end'] = [
        'n' => count($finalisems),
        'finalise_p50' => local_catquiz_benchmark_percentile($finalisems, 0.5),
        'finalise_p95' => local_catquiz_benchmark_percentile($finalisems, 0.95),
        'peer_n' => count($peerms),
        'peer_p50' => $peerms ? local_catquiz_benchmark_percentile($peerms, 0.5) : null,
        'peer_p95' => $peerms ? local_catquiz_benchmark_percentile($peerms, 0.95) : null,
        'peer_max' => $peerms ? max($peerms) : null,
    ];
    cli_writeln(sprintf(
        'End of the attempts: %d finalised, p50 %.1f ms, p95 %.1f ms; peer comparison (span) %d, p50 %s ms, p95 %s ms.',
        $report['end']['n'],
        $report['end']['finalise_p50'],
        $report['end']['finalise_p95'],
        $report['end']['peer_n'],
        json_encode($report['end']['peer_p50']),
        json_encode($report['end']['peer_p95'])
    ));
}
// Critical-friend re-audit of 23.11.2026: the end of an attempt in its parts - the last step (in which the selection
// ends and the feedback at the end is built), then the finalisation with its spans.
$ends = array_values(array_filter($steps, fn($s) => isset($s['finalisems'])));
if ($ends) {
    $report['endparts'] = [];
    $part = function (string $name, array $values) use (&$report) {
        $report['endparts'][$name] = [
            'p50' => local_catquiz_benchmark_percentile($values, 0.5),
            'p95' => local_catquiz_benchmark_percentile($values, 0.95),
            'max' => max($values),
        ];
        cli_writeln(sprintf('  %-36s %9.1f %9.1f %9.1f', $name, ...array_values($report['endparts'][$name])));
    };
    cli_writeln("\nEnd of the attempts in parts (ms; p50, p95, max):" . ($options['hostend'] ? ' completed by the activity' : ''));
    $part('last step (wall)', array_map(fn($s) => (float) $s['wallms'], $ends));
    $part('finalisation (wall)', array_map(fn($s) => (float) $s['finalisems'], $ends));
    $names = [];
    foreach ($ends as $end) {
        $names += array_flip(array_keys($end['endspans'] ?? []));
    }
    ksort($names);
    foreach (array_keys($names) as $name) {
        $part($name, array_map(fn($s) => (float) ($s['endspans'][$name] ?? 0), $ends));
    }
    cli_writeln(sprintf(
        '  finalisation: queries p95 %d, writes p95 %d, peak memory %d KB',
        local_catquiz_benchmark_percentile(array_map(fn($s) => (float) ($s['finalisequeries'] ?? 0), $ends), 0.95),
        local_catquiz_benchmark_percentile(array_map(fn($s) => (float) ($s['finalisewrites'] ?? 0), $ends), 0.95),
        max(array_map(fn($s) => (int) ($s['finalisepeakkb'] ?? 0), $ends))
    ));
}
$stops = array_count_values(array_map(fn($s) => $s['stop'], array_filter($steps, fn($s) => isset($s['stop']))));
cli_writeln("\nStops: " . json_encode($stops));
if ($options['json'] !== '') {
    file_put_contents((string) $options['json'], json_encode($report, JSON_PRETTY_PRINT));
}
