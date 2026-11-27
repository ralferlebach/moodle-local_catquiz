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

namespace local_catquiz\teststrategy;

use advanced_testcase;
use context_course;
use context_module;
use core_question\local\bank\question_edit_contexts;
use local_catquiz\catquiz;
use local_catquiz\catquiz_handler;
use local_catquiz\catscale;
use local_catquiz\importer\testitemimporter;
use local_catquiz\local\attempt\attempt_finalizer;
use local_catquiz\local\question\question_answer_evaluation;
use local_catquiz\output\attemptfeedback;
use local_catquiz\testenvironment;
use mod_adaptivequiz\local\attempt;
use question_bank;
use question_engine;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquiz/lib.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

/**
 * Issue #168 AK2 (decision of 19.11.2026): with an answer only the feedback that records every answer is built - since
 * the decision of 21.11.2026 only the debug information; the history of the graphical summary is built at the end from
 * what the progress records with each answer. What describes the state of the attempt is built once
 * at its end - by the selection when it ends the attempt, else by the finalisation - and, for a running attempt, when
 * somebody looks at it, without storing it. The feedback at the end is the same as when everything was built with
 * every answer.
 *
 * Real attempts on the simulation pool through catquiz_handler, as in a course.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquiz\output\attemptfeedback
 * @covers     \local_catquiz\local\attempt\attempt_finalizer
 */
#[\PHPUnit\Framework\Attributes\CoversClass(attemptfeedback::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(attempt_finalizer::class)]
final class feedback_scope_test extends advanced_testcase {
    /** @var \stdClass The activity. */
    private \stdClass $quiz;

    /** @var \stdClass */
    private \stdClass $course;

    /** @var int */
    private int $rootid;

    /** @var int */
    private int $contextid;

    /** What only the end - or a view - builds: the state of the attempt. */
    private const STATE_KEYS = ['personabilities_abilities', 'customscalefeedback_abilities', 'userability'];

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        putenv('CATQUIZ_TESTING_SKIP_FEEDBACK');
        $this->course = $this->getDataGenerator()->create_course();
        $this->quiz = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14, 'course' => $this->course->id,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        $qformat = new \qformat_xml();
        $qformat->setContexts((new question_edit_contexts(context_course::instance($this->course->id)))->all());
        $qformat->setCourse($this->course);
        if (class_exists('\core_question\local\bank\question_bank_helper')) {
            $qbank = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($this->course, true);
            $category = question_get_default_category(context_module::instance($qbank->id)->id, true);
            if ($category) {
                $qformat->setCategory($category);
            }
        }
        $qformat->setFilename(__DIR__ . '/../fixtures/simulation.xml');
        $qformat->setRealfilename('simulation.xml');
        $qformat->setMatchgrades('error');
        $qformat->setCatfromfile(1);
        $qformat->setContextfromfile(1);
        $qformat->setStoponerror(1);
        $qformat->setCattofile(1);
        $qformat->setContexttofile(1);
        $qformat->set_display_progress(false);
        ob_start();
        $this->assertTrue($qformat->importprocess());
        ob_end_clean();
        (new testitemimporter())->execute_testitems_csv_import(
            (object) ['delimiter_name' => 'semicolon', 'encoding' => null, 'dateparseformat' => null],
            file_get_contents(__DIR__ . '/../fixtures/simulation.csv')
        );
        $root = $DB->get_record('local_catquiz_catscales', ['parentid' => 0], '*', MUST_EXIST);
        $root->minscalevalue = -10.0;
        $root->maxscalevalue = 10.0;
        $DB->update_record('local_catquiz_catscales', $root);
        $this->rootid = (int) $root->id;
        $this->contextid = (int) catscale::get_context_id($this->rootid);
        $data = json_decode(file_get_contents(__DIR__ . '/../fixtures/testenvironment.json'));
        $data->catquiz_catscales = $this->rootid;
        $data->catscaleid = $this->rootid;
        foreach ([$this->rootid, ...catscale::get_subscale_ids($this->rootid)] as $scaleid) {
            $data->{'catquiz_subscalecheckbox_' . $scaleid} = true;
        }
        $data->componentid = (string) $this->quiz->id;
        $data->component = 'mod_adaptivequiz';
        $data->catquiz_selectteststrategy = LOCAL_CATQUIZ_STRATEGY_CLASSIC;
        $data->maxquestionsgroup->catquiz_minquestions = 6;
        $data->maxquestionsgroup->catquiz_maxquestions = 6;
        $data->maxquestionsscalegroup->catquiz_maxquestionspersubscale = 0;
        $data->maxquestionsscalegroup->catquiz_minquestionspersubscale = 0;
        $data->catquiz_standarderrorgroup->catquiz_standarderror_min = 0.0001;
        $data->catquiz_standarderrorgroup->catquiz_standarderror_max = 1000.0;
        $data->catquiz_includepilotquestions = false;
        // Two feedback ranges on the root scale: the colour bar of the comparison.
        $data->numberoffeedbackoptionsselect = 2;
        foreach ([[-10, 0], [0, 10]] as $i => [$lower, $upper]) {
            $j = $i + 1;
            $data->{"feedback_scaleid_limit_lower_{$this->rootid}_{$j}"} = $lower;
            $data->{"feedback_scaleid_limit_upper_{$this->rootid}_{$j}"} = $upper;
            $data->{"wb_colourpicker_{$this->rootid}_{$j}"} = $j === 1 ? '3' : '6';
            $data->{"feedbacklegend_scaleid_{$this->rootid}_{$j}"} = "Range $j";
        }
        $data->json = json_encode($data);
        (new testenvironment($data))->save_or_update();
        // Every cache, the copies this request's cache instances keep included (static acceleration).
        \cache_helper::purge_all();
        \cache_factory::reset();
    }

    /**
     * Resets the seam and the switch of the testing classes.
     */
    protected function tearDown(): void {
        attemptfeedback::$everyanswer = false;
        putenv('CATQUIZ_TESTING_SKIP_FEEDBACK');
        parent::tearDown();
    }

    /**
     * Answers questions of a new attempt; the selection processes each answer when it chooses the next question.
     *
     * @param \stdClass $user
     * @param int $seed Which answers are right.
     * @param int $answers How many answers; 0 for "until the selection ends the attempt".
     * @return array ['attemptid' => int, 'ended' => bool] - ended: the selection had no further question.
     */
    private function play(\stdClass $user, int $seed, int $answers = 0): array {
        global $DB;
        $this->setUser($user);
        catquiz_handler::prepare_attempt_caches();
        $cm = get_coursemodule_from_instance('adaptivequiz', $this->quiz->id);
        $quba = question_engine::make_questions_usage_by_activity('mod_adaptivequiz', context_module::instance($cm->id));
        $quba->set_preferred_behaviour('deferredfeedback');
        $attemptid = (int) (new attempt($this->quiz, $user->id))->get_attempt()->id;
        $ended = false;
        for ($i = 0; $i < 30; $i++) {
            $record = $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid], '*', MUST_EXIST);
            $attempt = new attempt($this->quiz, $record->userid);
            // The request after the last answer: it processes that answer and chooses the next question.
            $result = catquiz_handler::fetch_question_id((int) $this->quiz->id, 'mod_adaptivequiz', $attempt->get_attempt());
            if (empty($result[0])) {
                $ended = true;
                break;
            }
            if ($answers && $i === $answers) {
                break;
            }
            $slot = $quba->add_question(question_bank::load_question($result[0]));
            $quba->start_question($slot);
            $answer = $quba->get_correct_response($slot)['answer'];
            $right = (($i + $seed) % 3) !== 1;
            $quba->process_action($slot, ['answer' => $right ? $answer : ($answer >= 1 ? $answer - 1 : $answer + 1)]);
            $quba->finish_all_questions(time());
            (new question_answer_evaluation($quba))->perform($slot);
            question_engine::save_questions_usage_by_activity($quba);
            $record = $attempt->get_attempt();
            $record->questionsattempted = ($record->questionsattempted ?? 0) + 1;
            $record->timemodified = time();
            $DB->update_record('adaptivequiz_attempt', $record);
            if ($i === 0) {
                $attempt->set_quba_id($quba->get_id());
            }
        }
        return ['attemptid' => $attemptid, 'ended' => $ended];
    }

    /**
     * Ends an attempt as the activity does (time limit, its own maximum) and finalises it.
     *
     * @param int $attemptid
     */
    private function end_by_the_activity(int $attemptid): void {
        global $DB;
        $DB->set_field('adaptivequiz_attempt', 'attemptstate', 'complete', ['id' => $attemptid]);
        $DB->set_field('adaptivequiz_attempt', 'timefinished', time(), ['id' => $attemptid]);
        $this->assertTrue(attempt_finalizer::finalize($attemptid, time(), 'timelimit'));
    }

    /**
     * The stored feedback data of an attempt.
     *
     * @param int $attemptid
     * @return array
     */
    private function stored(int $attemptid): array {
        global $DB;
        return json_decode((string) $DB->get_field('local_catquiz_attempts', 'json', ['attemptid' => $attemptid]), true);
    }

    /**
     * The validated result of an attempt as plain values.
     *
     * @param int $attemptid
     * @return array
     */
    private function validated(int $attemptid): array {
        $out = [];
        foreach (\local_catquiz\local\result\attempt_result_validator::validate($attemptid)->get_scale_results() as $id => $r) {
            $out[$id] = [$r->score, $r->standarderror, $r->n, $r->primary, $r->reportable, $r->valid, $r->rejectionreasons];
        }
        ksort($out);
        return $out;
    }

    /**
     * The history of the attempt as the progress records it: one entry per processed answer (issue #168).
     *
     * @param int $attemptid
     * @return array
     */
    private function answer_history(int $attemptid): array {
        global $DB;
        $row = $DB->get_record_sql(
            'SELECT a.id, p.json FROM {local_catquiz_progress} p JOIN {local_catquiz_attempts} a ON a.id = p.attemptid
              WHERE a.attemptid = ?',
            [$attemptid]
        );
        // Issue #177: the history is stored with the events; the stored structure reads either format.
        $stored = $row ? \local_catquiz\teststrategy\progress::stored_structure((int) $row->id, (string) $row->json) : null;
        return $stored['answerhistory'] ?? [];
    }

    /**
     * Rows of the history without what belongs to the one attempt: its question attempts, and the text of the answer
     * (the choices are shuffled per attempt). Compared as stored (JSON).
     *
     * @param array $rows
     * @return array
     */
    private static function rows(array $rows): array {
        // As stored: a view builds them in memory, where 0.0 is still a float.
        $rows = json_decode(json_encode($rows), true);
        return array_map(function ($row) {
            unset($row['questionattemptid'], $row['responsesummary']);
            return $row;
        }, $rows);
    }

    /**
     * With an answer no history in the feedback data (decision of 21.11.2026): the progress records the state after
     * each answer, compactly; the context of the selection is kept, the state of the attempt is not built.
     */
    public function test_with_an_answer_no_history_is_written(): void {
        $run = $this->play($this->getDataGenerator()->create_and_enrol($this->course, 'student'), 0, 3);
        $this->assertFalse($run['ended']);
        $stored = $this->stored($run['attemptid']);
        $this->assertSame(attemptfeedback::SCOPE_ANSWER, $stored[attemptfeedback::SCOPE_KEY]);
        $this->assertArrayNotHasKey('graphicalsummary_data', $stored, 'The history is built at the end.');
        $this->assertArrayNotHasKey('graphicalsummary_otherscales', $stored);
        $history = $this->answer_history($run['attemptid']);
        $this->assertCount(3, $history, 'One entry per processed answer, in the progress.');
        foreach ($history as $entry) {
            $this->assertSame(['q', 'a', 's'], array_keys($entry));
            $this->assertArrayHasKey($this->rootid, $entry['s']);
        }
        $this->assertArrayHasKey('se', $stored, 'The standard errors of the last selection are kept.');
        $this->assertNotEmpty($stored['se']);
        foreach (self::STATE_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $stored, "Not built with an answer: $key.");
        }
    }

    /**
     * A running attempt that somebody looks at is shown with its full feedback, built for the view from the progress
     * and the last snapshot - the same as when it was built with every answer - and nothing of it is stored.
     */
    public function test_a_running_attempt_is_shown_complete_without_storing(): void {
        global $DB;
        // Before: everything with every answer (the seam) - what the view has to show.
        attemptfeedback::$everyanswer = true;
        $before = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $old = $this->stored($this->play($before, 1, 4)['attemptid']);
        attemptfeedback::$everyanswer = false;
        $DB->delete_records('local_catquiz_personparams', ['userid' => $before->id]);
        \cache_helper::purge_all();

        $run = $this->play($this->getDataGenerator()->create_and_enrol($this->course, 'student'), 1, 4);
        $json = $DB->get_field('local_catquiz_attempts', 'json', ['attemptid' => $run['attemptid']]);
        $this->setAdminUser();
        $feedback = new attemptfeedback($run['attemptid'], $this->contextid);
        $data = $feedback->completed($feedback->load_feedbackdata(null, null, false));
        $this->assertSame(attemptfeedback::SCOPE_END, $data[attemptfeedback::SCOPE_KEY]);
        foreach (['personabilities_abilities', 'customscalefeedback_abilities', 'userability', 'se'] as $key) {
            $this->assertArrayHasKey($key, $data, "Built for the view: $key.");
            $this->assertEquals($old[$key], $data[$key], "The same $key as when it was built with every answer.");
        }
        // The history, built for the view from the progress, row by row as with every answer.
        $this->assertCount(4, $data['graphicalsummary_data']);
        $this->assertSame(self::rows($old['graphicalsummary_data']), self::rows($data['graphicalsummary_data']));
        $this->assertSame(
            $old['graphicalsummary_otherscales'],
            json_decode(json_encode($data['graphicalsummary_otherscales']), true)
        );
        $this->assertNotEmpty($feedback->get_feedback_for_attempt());
        $this->assertSame(
            $json,
            $DB->get_field('local_catquiz_attempts', 'json', ['attemptid' => $run['attemptid']]),
            'A view stores nothing.'
        );
    }

    /**
     * Ended by the activity: the finalisation builds the state once, before it validates - and the feedback and the
     * result are the same as when everything was built with every answer. A second finalisation changes nothing.
     */
    public function test_the_end_builds_it_once_and_as_before(): void {
        global $DB;
        // Before: everything with every answer (the seam).
        attemptfeedback::$everyanswer = true;
        $before = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $old = $this->play($before, 2, 4);
        attemptfeedback::$everyanswer = false;
        $this->end_by_the_activity($old['attemptid']);
        $oldstored = $this->stored($old['attemptid']);
        $oldresult = $this->validated($old['attemptid']);
        // The person of the first run is no peer and no prior of the second: the same population as for the first.
        $DB->delete_records('local_catquiz_personparams', ['userid' => $before->id]);
        \cache_helper::purge_all();

        // Now: the history with every answer, the rest at the end.
        $new = $this->play($this->getDataGenerator()->create_and_enrol($this->course, 'student'), 2, 4);
        $this->assertSame(attemptfeedback::SCOPE_ANSWER, $this->stored($new['attemptid'])[attemptfeedback::SCOPE_KEY]);
        // The status the end of the selection sets after the last snapshot (here: a technical error) is the attempt's;
        // completing the snapshot must not reset it (found by the adapter's stop matrix, 20.11.2026).
        \local_catquiz\catquiz::set_final_attempt_status($new['attemptid'], \local_catquiz\local\status::ERROR_GENERAL);
        $this->end_by_the_activity($new['attemptid']);
        $this->assertSame(
            \local_catquiz\local\status::to_int(\local_catquiz\local\status::ERROR_GENERAL),
            (int) $DB->get_field('local_catquiz_attempts', 'status', ['attemptid' => $new['attemptid']]),
            'The status of the CAT attempt is kept.'
        );
        $newstored = $this->stored($new['attemptid']);
        $this->assertSame(attemptfeedback::SCOPE_END, $newstored[attemptfeedback::SCOPE_KEY], 'Completed at the end.');
        foreach (['personabilities_abilities', 'customscalefeedback_abilities', 'primaryscale', 'se', 'userability'] as $key) {
            $this->assertArrayHasKey($key, $newstored, $key);
            $this->assertEquals($oldstored[$key], $newstored[$key], "The same $key as when it was built with every answer.");
        }
        // The history: built once at the end from the progress - the same rows as built with every answer.
        $this->assertCount(4, $newstored['graphicalsummary_data']);
        $this->assertSame(self::rows($oldstored['graphicalsummary_data']), self::rows($newstored['graphicalsummary_data']));
        $this->assertSame($oldstored['graphicalsummary_otherscales'], $newstored['graphicalsummary_otherscales']);
        $this->assertSame($oldstored['teststrategyname'], $newstored['teststrategyname']);
        $this->assertEquals($oldresult, $this->validated($new['attemptid']), 'The same result.');

        // Idempotent: a second finalisation and a second completion change nothing.
        $json = $DB->get_field('local_catquiz_attempts', 'json', ['attemptid' => $new['attemptid']]);
        $this->assertFalse(attempt_finalizer::finalize($new['attemptid'], time(), 'timelimit'));
        $this->assertFalse((new attemptfeedback($new['attemptid'], $this->contextid))->complete_at_end());
        $this->assertSame($json, $DB->get_field('local_catquiz_attempts', 'json', ['attemptid' => $new['attemptid']]));
    }

    /**
     * Ended by the selection (no further question): the selection builds everything once, before the finalisation.
     */
    public function test_the_end_of_the_selection_builds_it(): void {
        $run = $this->play($this->getDataGenerator()->create_and_enrol($this->course, 'student'), 0);
        $this->assertTrue($run['ended'], 'The selection ended the attempt (maximum of the test).');
        $stored = $this->stored($run['attemptid']);
        $this->assertSame(attemptfeedback::SCOPE_END, $stored[attemptfeedback::SCOPE_KEY]);
        foreach (self::STATE_KEYS as $key) {
            $this->assertArrayHasKey($key, $stored, "Built at the end: $key.");
        }
        $this->assertFalse(
            (new attemptfeedback($run['attemptid'], $this->contextid))->complete_at_end(),
            'Nothing left for the finalisation.'
        );
    }

    /**
     * Issue #159 (re-audit of 22.11.2026): the answer a reload gave as wrong gets its entry in the history.
     *
     * A reload after the time for a question ran out marks it as failed: the strategy stores the response in the
     * progress (add_playedquestion(), mark_lastquestion_failed(), save()) and redirects - there is no selection, and
     * nothing records the answer. The next answer is recorded by the selection that processes it. Before, only that
     * last answer got an entry; the failed one never had a row in the history, nor before #168. It is recorded now,
     * before the next one and without an ability (none was estimated between them); the history shows it.
     * RED before: four entries and the next one - the failed answer missing.
     */
    public function test_the_answer_a_reload_gave_gets_its_entry(): void {
        global $DB;
        $run = $this->play($this->getDataGenerator()->create_and_enrol($this->course, 'student'), 0, 4);
        $this->assertFalse($run['ended']);
        $this->assertCount(4, $this->answer_history($run['attemptid']));

        // The reload: the shown question is marked as failed, as check_break() does it.
        $progress = \local_catquiz\teststrategy\progress::load($run['attemptid'], 'mod_adaptivequiz', $this->contextid);
        $failed = $progress->get_last_question();
        $this->assertIsObject($failed, 'A question is shown.');
        $progress->add_playedquestion($failed)->mark_lastquestion_failed()->save();

        // The next answer, as the selection processes it: another question of the pool, answered right.
        $progress = \local_catquiz\teststrategy\progress::load($run['attemptid'], 'mod_adaptivequiz', $this->contextid);
        $played = $progress->get_playedquestions();
        $nextid = (int) $DB->get_field_sql(
            'SELECT MIN(componentid) FROM {local_catquiz_items} WHERE contextid = ? AND componentid NOT IN ('
                . implode(',', array_map('intval', array_keys($played))) . ')',
            [$this->contextid]
        );
        $next = clone $failed;
        $next->id = $nextid;
        $reflection = new \ReflectionClass($progress);
        $playedproperty = $reflection->getProperty('playedquestions');
        $playedproperty->setAccessible(true);
        $playedproperty->setValue($progress, $played + [$nextid => $next]);
        $responses = $reflection->getProperty('responses');
        $responses->setAccessible(true);
        $answer = [$nextid => ['questionid' => $nextid, 'fraction' => 1.0]];
        $responses->setValue($progress, $responses->getValue($progress) + $answer);

        $this->assertTrue($progress->record_answer_state(0.4242));
        $history = $progress->get_answer_history();
        $this->assertCount(6, $history, 'The failed answer and the next one.');
        $this->assertSame((string) $failed->id, (string) $history[4]['q'], 'The failed answer, in its place.');
        $this->assertNull($history[4]['a'], 'Without an ability: none was estimated.');
        $this->assertSame(array_keys($history[5]['s']), array_keys($history[4]['s']), 'The series of the scales stay aligned.');
        $this->assertSame([null], array_values(array_unique($history[4]['s'], SORT_REGULAR)));
        $this->assertSame((string) $nextid, (string) $history[5]['q']);
        $this->assertSame(0.42, $history[5]['a']);
        $this->assertFalse($progress->record_answer_state(0.1), 'Recorded once.');
    }
}
