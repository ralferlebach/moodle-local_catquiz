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
use local_catquiz\local\status;
use local_catquiz\testenvironment;
use mod_adaptivequiz\local\attempt;
use question_bank;
use question_engine;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/engine/tests/helpers.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * The standard error of an attempt from its end back to its answers (issue #163, re-audit "ab #147", 19.11.2026:
 * "Ende-/Resume-/Stop-Vertrag").
 *
 * An attempt is played to its end on the simulation pool and finalised as the activity does. Then, for every scale
 * measured:
 * - End: the standard error in the snapshot of the attempt is 1 / sqrt(sum of the Fisher information) of the questions
 *   answered with a fraction, each once, with the active parameters of the attempt's context in the scale and its
 *   subscales, at the final ability of the progress (for a scale without an answer in the last step: as its person
 *   parameters keep it, with four decimals) - recomputed here from the database in closed form (2PL), without the
 *   engine's models; the result row (local_catquiz_personparams) holds the same value.
 * - Resume: the same attempt played with every cache emptied before each question - as if it was resumed after every
 *   answer - ends with bit-identical standard errors, abilities and questions.
 * - Stop: an attempt stopped by the target precision has reached it on the root scale; the reason "se_max" in the
 *   result row is there exactly when the standard error is above the maximum of the test.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\teststrategy\preselect_task\addscalestandarderror
 * @covers \local_catquiz\local\attempt\attempt_finalizer
 */
final class standard_error_contract_test extends advanced_testcase {
    /** @var float The maximum standard error of the test. */
    private const SEMAX = 1.0;

    /** @var \stdClass The activity. */
    private \stdClass $adaptivequiz;

    /** @var int The root scale of the pool. */
    private int $rootid;

    /**
     * Imports the simulation pool and creates the activity.
     */
    public function setUp(): void {
        global $DB;
        parent::setUp();
        // The cold run is rolled back in a transaction of its own; it must be the outermost one.
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->adaptivequiz = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14, 'course' => $course->id,
        ]);
        $this->assertTrue($this->qformat('simulation.xml', $course)->importprocess());
        $importer = new testitemimporter();
        $importer->execute_testitems_csv_import(
            (object) ['delimiter_name' => 'semicolon', 'encoding' => null, 'dateparseformat' => null],
            file_get_contents(__DIR__ . '/../fixtures/simulation.csv')
        );
        $root = $DB->get_record('local_catquiz_catscales', ['parentid' => 0], '*', MUST_EXIST);
        $root->minscalevalue = -10.0;
        $root->maxscalevalue = 10.0;
        $DB->update_record('local_catquiz_catscales', $root);
        $this->rootid = (int) $root->id;
    }

    /**
     * An XML importer for a fixture file.
     *
     * @param string $filename
     * @param \stdClass $course
     * @return \qformat_xml
     */
    private function qformat(string $filename, \stdClass $course): \qformat_xml {
        $qformat = new \qformat_xml();
        $qformat->setContexts((new question_edit_contexts(context_course::instance($course->id)))->all());
        $qformat->setCourse($course);
        if (class_exists('\core_question\local\bank\question_bank_helper')) {
            $qbank = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
            $category = question_get_default_category(context_module::instance($qbank->id)->id, true);
        } else {
            $category = question_get_default_category(context_course::instance($course->id)->id, true);
        }
        // Moodle 4.5 has no default category yet; the file names its categories itself.
        if ($category) {
            $qformat->setCategory($category);
        }
        $qformat->setFilename(__DIR__ . '/../fixtures/' . $filename);
        $qformat->setRealfilename($filename);
        $qformat->setMatchgrades('error');
        $qformat->setCatfromfile(1);
        $qformat->setContextfromfile(1);
        $qformat->setStoponerror(1);
        $qformat->setCattofile(1);
        $qformat->setContexttofile(1);
        $qformat->set_display_progress(false);
        return $qformat;
    }

    /**
     * The settings of the test, on top of the fixture's test environment.
     *
     * @param int $strategy
     * @param float $semin The target precision.
     */
    private function settings(int $strategy, float $semin): void {
        $data = json_decode(file_get_contents(__DIR__ . '/../fixtures/testenvironment.json'));
        $data->catquiz_catscales = $this->rootid;
        $data->catscaleid = $this->rootid;
        foreach ([$this->rootid, ...catscale::get_subscale_ids($this->rootid)] as $scaleid) {
            $data->{'catquiz_subscalecheckbox_' . $scaleid} = true;
        }
        $data->componentid = '1';
        $data->component = 'mod_adaptivequiz';
        $data->catquiz_selectteststrategy = $strategy;
        $data->maxquestionsgroup->catquiz_minquestions = 8;
        $data->maxquestionsgroup->catquiz_maxquestions = 14;
        $data->maxquestionsscalegroup->catquiz_maxquestionspersubscale = 0;
        $data->maxquestionsscalegroup->catquiz_minquestionspersubscale = 0;
        $data->catquiz_standarderrorgroup->catquiz_standarderror_min = $semin;
        $data->catquiz_standarderrorgroup->catquiz_standarderror_max = self::SEMAX;
        $data->catquiz_pp_min_inc = 0.05;
        $data->catquiz_includepilotquestions = false;
        $data->json = json_encode($data);
        (new testenvironment($data))->save_or_update();
    }

    /**
     * Plays an attempt to its end, every question answered in its own slot, and finalises it as the activity does.
     *
     * @param bool $cold Empty every cache before each question: the attempt is resumed after every answer.
     * @return array The outcome, see outcome().
     */
    private function play(bool $cold): array {
        global $DB, $USER;
        \cache_helper::purge_all();
        catquiz_handler::prepare_attempt_caches();

        $cm = get_coursemodule_from_instance('adaptivequiz', $this->adaptivequiz->id);
        $quba = question_engine::make_questions_usage_by_activity('mod_adaptivequiz', context_module::instance($cm->id));
        $quba->set_preferred_behaviour('deferredfeedback');

        $attemptid = (int) (new attempt($this->adaptivequiz, $USER->id))->get_attempt()->id;
        $ids = [];
        $status = null;
        for ($i = 0; $i < 30; $i++) {
            if ($cold) {
                \cache_helper::purge_all();
            }
            $record = $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid], '*', MUST_EXIST);
            $attempt = new attempt($this->adaptivequiz, $record->userid);
            $result = catquiz_handler::fetch_question_id(1, 'mod_adaptivequiz', $attempt->get_attempt());
            if (empty($result[0])) {
                $status = $result[2] ?? null;
                break;
            }
            $ids[] = (int) $result[0];

            $slot = $quba->add_question(question_bank::load_question($result[0]));
            $quba->start_question($slot);
            $answer = $quba->get_correct_response($slot)['answer'];
            $right = $i % 3 !== 1;
            $quba->process_action($slot, ['answer' => $right ? $answer : ($answer >= 1 ? $answer - 1 : $answer + 1)]);
            $quba->finish_all_questions(time());
            (new question_answer_evaluation($quba))->perform($slot);
            question_engine::save_questions_usage_by_activity($quba);

            $record = $attempt->get_attempt();
            $record->timemodified = time();
            $record->questionsattempted = ($record->questionsattempted ?? 0) + 1;
            $DB->update_record('adaptivequiz_attempt', $record);
            if ($i === 0) {
                $attempt->set_quba_id($quba->get_id());
            }
        }
        if ($cold) {
            \cache_helper::purge_all();
        }
        attempt_finalizer::finalize($attemptid, time(), (string) $status);
        return $this->outcome($attemptid, $ids, (string) $status);
    }

    /**
     * Plays the attempt cold inside a transaction that is rolled back afterwards.
     *
     * @return array The outcome, see outcome().
     */
    private function play_cold_and_roll_back(): array {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $outcome = $this->play(true);
        try {
            $transaction->rollback(new \moodle_exception('rollback'));
        } catch (\moodle_exception $e) {
            $this->assertSame('rollback', $e->errorcode);
        }
        \cache_helper::purge_all();
        return $outcome;
    }

    /**
     * What the end of an attempt left: standard errors of the snapshot (measured scales), abilities of the progress,
     * result rows, the questions and the status, and the reference of every measured scale.
     *
     * @param int $attemptid
     * @param int[] $ids
     * @param string $status
     * @return array
     */
    private function outcome(int $attemptid, array $ids, string $status): array {
        global $DB;
        $row = $DB->get_record('local_catquiz_attempts', ['attemptid' => $attemptid], '*', MUST_EXIST);
        $json = json_decode((string) $row->json, true);
        $se = array_filter($json['se'] ?? [], fn($value) => (float) $value >= 0);
        $progress = progress::load_for_reading((int) $row->id);
        $abilities = $progress->get_abilities();
        $reference = [];
        foreach (array_keys($se) as $scaleid) {
            // A scale without a new answer in the last step is read back from its person parameters, which keep the
            // ability with four decimals: the reference at the ability and at the ability as stored.
            $reference[$scaleid] = [
                $this->reference($progress, (int) $scaleid, (float) $abilities[$scaleid]),
                $this->reference($progress, (int) $scaleid, round((float) $abilities[$scaleid], 4)),
            ];
        }
        $rows = [];
        foreach ($DB->get_records('local_catquiz_personparams', ['attemptid' => $row->id]) as $result) {
            $rows[(int) $result->catscaleid] = $result;
        }
        return [
            'se' => $se, 'abilities' => $abilities, 'rows' => $rows, 'ids' => $ids, 'status' => $status,
            'reference' => $reference, 'semin' => (float) $json['se_min'],
        ];
    }

    /**
     * The reference, read from the database and computed in closed form: 1 / sqrt(sum of a^2 P (1 - P)) over the
     * questions answered with a fraction - pilot questions left out - that have active parameters in the attempt's
     * context in the scale or one of its subscales, each question once.
     *
     * @param progress $progress
     * @param int $scaleid
     * @param float $ability
     * @return float
     */
    private function reference(progress $progress, int $scaleid, float $ability): float {
        global $DB;
        $scales = [$scaleid, ...catscale::get_subscale_ids($scaleid)];
        [$insql, $params] = $DB->get_in_or_equal($scales, SQL_PARAMS_NAMED);
        $params['contextid'] = $progress->get_contextid();
        $parameters = [];
        $sql = "SELECT p.id, i.componentid, p.difficulty, p.discrimination, p.model
                  FROM {local_catquiz_items} i
                  JOIN {local_catquiz_itemparams} p ON p.id = i.activeparamid
                 WHERE i.contextid = :contextid AND i.componentname = 'question' AND i.catscaleid $insql
              ORDER BY p.id";
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $this->assertSame('raschbirnbaum', $record->model, 'The simulation pool is 2PL.');
            $parameters[(int) $record->componentid] ??= [(float) $record->difficulty, (float) $record->discrimination];
        }
        $played = $progress->get_playedquestions();
        $information = 0.0;
        foreach ($progress->get_user_responses() as $questionid => $response) {
            if (
                !empty($played[$questionid]->is_pilot) || !isset($parameters[$questionid])
                    || !is_numeric($response['fraction'] ?? null)
            ) {
                continue;
            }
            [$b, $a] = $parameters[$questionid];
            $p = 1 / (1 + exp(-$a * ($ability - $b)));
            $information += $a ** 2 * $p * (1 - $p);
        }
        return 1 / sqrt(max(10 ** -6, $information));
    }

    /**
     * Strategies: CLASSIC and RELSUBS play to the maximum, FASTEST stops at the target precision.
     *
     * @return array
     */
    public static function cases(): array {
        return [
            'CLASSIC' => [LOCAL_CATQUIZ_STRATEGY_CLASSIC, status::ERROR_REACHED_MAXIMUM_QUESTIONS],
            'FASTEST' => [LOCAL_CATQUIZ_STRATEGY_FASTEST, status::STOP_TARGET_PRECISION],
            'RELSUBS' => [LOCAL_CATQUIZ_STRATEGY_RELSUBS, status::ERROR_REACHED_MAXIMUM_QUESTIONS],
        ];
    }

    /**
     * End, resume and stop of the standard error (#163).
     *
     * @param int $strategy
     * @param string $end The status the attempt ends with.
     * @dataProvider cases
     */
    public function test_end_resume_and_stop(int $strategy, string $end): void {
        $semin = 0.3;
        $this->settings($strategy, $semin);
        $cold = $this->play_cold_and_roll_back();
        $warm = $this->play(false);

        // End: the snapshot is the reference, and so is the result row.
        $this->assertNotEmpty($warm['se']);
        $this->assertArrayHasKey($this->rootid, $warm['se'], 'The root scale is measured.');
        foreach ($warm['se'] as $scaleid => $se) {
            [$exact, $stored] = $warm['reference'][$scaleid];
            $this->assertEqualsWithDelta(
                abs($exact - $se) <= abs($stored - $se) ? $exact : $stored,
                $se,
                1e-9,
                "Scale $scaleid: the snapshot, $se, against the reference at the reported ability, $exact, or at the ability "
                    . "as stored, $stored"
            );
            if (isset($warm['rows'][$scaleid])) {
                $row = $warm['rows'][$scaleid];
                $this->assertEqualsWithDelta($se, (float) $row->standarderror, 5e-5, "Scale $scaleid: result row");
                $reasons = $row->validationstatus === '' || $row->validationstatus === null
                    ? [] : explode(',', $row->validationstatus);
                $this->assertSame(
                    $se > self::SEMAX,
                    in_array(\local_catquiz\local\result\scale_result::REASON_SE_MAX, $reasons),
                    "Scale $scaleid: se_max is a reason exactly when the standard error is above the maximum"
                );
            }
        }
        $this->assertArrayHasKey($this->rootid, $warm['rows'], 'The root scale has its result row.');

        // Stop: the target precision ends the attempt only when the root has reached it, the maximum after 14 questions.
        $this->assertSame($end, $warm['status']);
        if ($end === status::STOP_TARGET_PRECISION) {
            $this->assertLessThanOrEqual($semin, $warm['se'][$this->rootid]);
            $this->assertLessThan(14, count($warm['ids']));
        } else {
            $this->assertCount(14, $warm['ids']);
        }
        $this->assertSame($semin, $warm['semin']);

        // Resume after every answer: the same questions, the same end, the same numbers to the bit.
        $this->assertSame($warm['ids'], $cold['ids']);
        $this->assertSame($warm['status'], $cold['status']);
        $this->assertSame($warm['se'], $cold['se']);
        $this->assertSame($warm['abilities'], $cold['abilities']);
    }

    /**
     * The read-only audit of stored results (cli/diagnose_standarderror.php): the rows of a finished attempt are equal
     * to the definition; a row with the standard error of the whole pool, as before #163, is named; nothing is written.
     */
    public function test_the_audit_of_stored_results(): void {
        global $DB;
        $this->settings(LOCAL_CATQUIZ_STRATEGY_FASTEST, 0.3);
        $outcome = $this->play(false);
        $contextid = (int) $DB->get_field('local_catquiz_attempts', 'contextid', ['attemptid' => $DB->get_field(
            'adaptivequiz_attempt',
            'id',
            [],
            IGNORE_MULTIPLE
        )]);
        $rows = count($outcome['rows']);
        $this->assertGreaterThan(1, $rows);

        $writes = $DB->perf_get_writes();
        $audit = \local_catquiz\local\integrity\standard_error_audit::check($contextid);
        $this->assertSame($writes, $DB->perf_get_writes(), 'Read-only.');
        $this->assertSame($rows, $audit['rows']);
        $this->assertSame($rows, $audit['equal'], json_encode($audit));
        $this->assertSame([], $audit['sample']);

        // A row as written before #163: the information of every question still available made it smaller.
        $root = $outcome['rows'][$this->rootid];
        $DB->set_field(
            'local_catquiz_personparams',
            'standarderror',
            round((float) $root->standarderror / 3, 4),
            ['id' => $root->id]
        );
        $audit = \local_catquiz\local\integrity\standard_error_audit::check($contextid);
        $this->assertSame(1, $audit['smaller']);
        $this->assertSame($rows - 1, $audit['equal']);
        $this->assertSame([(int) $root->id], $audit['sample']);
        $this->assertEqualsWithDelta(1 / 3, $audit['ratio']['p5'], 1e-3);
        $this->assertSame(0, \local_catquiz\local\integrity\standard_error_audit::check($contextid, time() + 60)['rows']);
    }
}
