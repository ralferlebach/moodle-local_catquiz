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
 * Tests strategy
 *
 * @package    local_catquiz
 * @author David Szkiba <david.szkiba@wunderbyte.at>
 * @copyright  2023 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz;

use advanced_testcase;
use context_course;
use context_module;
use core_question\local\bank\question_edit_contexts;
use local_catquiz\importer\testitemimporter;
use mod_adaptivequiz\local\attempt\attempt;
use mod_adaptivequiz\local\question\question_answer_evaluation;
use question_bank;
use question_engine;
use question_usage_by_activity;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/engine/tests/helpers.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');
require_once($CFG->dirroot . '/local/catquiz/tests/lib.php');


/**
 * Tests strategy
 *
 * @package    local_catquiz
 * @author David Szkiba <david.szkiba@wunderbyte.at>
 * @copyright  2023 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_catquiz\teststrategy\strategy
 */
class strategy_test extends advanced_testcase {

    /**
     * @var int The ID of the 'Mathematik' scale that is created during import of the item params
     */
    private int $catscaleid;

    /**
     * @var question_usage_by_activity $quba question_usage This is created so that we can simulate a quiz attempt.
     */
    private question_usage_by_activity $quba;

    /**
     * @var Stores the course we create for this test.
     */
    private \stdClass $course;

    /**
     * @var An instance of an adaptive quiz
     */
    private \stdClass $adaptivequiz;

    public function setUp(): void {
        $this->import('simulation.xml', 'simulation.csv');
        // Needed to simulate question answers.

        $cm = get_coursemodule_from_instance('adaptivequiz', $this->adaptivequiz->id);
        $context = context_module::instance($cm->id);
        $quba = question_engine::make_questions_usage_by_activity('mod_adaptivequiz', $context);
        $quba->set_preferred_behaviour('deferredfeedback');
        $this->quba = $quba;
    }

    public function test_import_worked() {
        global $DB;
        $questions = $DB->get_records('question');
        $this->assertNotEmpty($questions, 'No questions were imported');
        $itemparams = $DB->get_records('local_catquiz_itemparams');
        $this->assertNotEmpty($itemparams, 'No itemparams were imported');
    }

    /**
     * Check if a teststrategy returns the expected questions in the correct
     * order.
     *
     * This test simulates a full quiz attempt by repeatedly calling the
     * catquiz_handler::fetch_question_id() function. For each returned
     * question, we simulate a correct or incorrect response before getting the
     * next question.
     *
     * @param int $strategy The ID of the teststrategy.
     * @param array $questions The expected list of questions.
     * @param float $initialability The initial ability in the main scale.
     * @param float $initialse The initial standarderror in the main scale.
     *
     * TODO: add group large?
     * TODO: Use different testenvironment.json files for different teststrategies.
     * @dataProvider strategy_returns_expected_questions_provider
     */
    public function test_strategy_returns_expected_questions(
        int $strategy,
        array $questions,
        float $initialability = 0.0,
        float $initialse = 1.0
    ) {
        putenv('USE_TESTING_CLASS_FOR=local_catquiz\teststrategy\preselect_task\updatepersonability');
        putenv("CATQUIZ_TESTING_ABILITY=$initialability");
        putenv("CATQUIZ_TESTING_STANDARDERROR=$initialse");
        putenv("CATQUIZ_TESTING_SKIP_FEEDBACK=true");
        global $DB, $USER;
        $hasqubaid = false;
        $this
            ->createtestenvironment($strategy)
            ->save_or_update();

        catquiz_handler::prepare_attempt_caches();

        // This is needed so that the responses to the questions are indeed saved to the database.
        $this->preventResetByRollback();
        $attempt = attempt::create(1, $USER->id);
        $attemptid = $attempt->read_attempt_data()->id;
        foreach ($questions as $index => $expectedquestion) {
            $attempt = attempt::get_by_id($attemptid);
            $attemptdata = $attempt->read_attempt_data();
            $abilityrecord = $DB->get_record(
                'local_catquiz_personparams',
                ['userid' => $USER->id, 'catscaleid' => $this->catscaleid],
                'ability'
            );
            $ability = $abilityrecord ? $abilityrecord->ability : 0;
            $this->assertEqualsWithDelta(
                $expectedquestion['ability_before'],
                $ability,
                0.01,
                'Ability before fetch is not correct for question number ' . ($index + 1)
            );
            [$nextquestionid, $message] = catquiz_handler::fetch_question_id('1', 'mod_adaptivequiz', $attemptdata);
            $abilityrecord = $DB->get_record(
                'local_catquiz_personparams',
                ['userid' => $USER->id, 'catscaleid' => $this->catscaleid],
                'ability'
            );
            $ability = $abilityrecord ? $abilityrecord->ability : 0;
            $this->assertEqualsWithDelta(
                $expectedquestion['ability_after'],
                $ability,
                0.01,
                'Ability after fetch is not correct for question number ' . ($index + 1)
            );
            if ($expectedquestion['label'] === 'FINISH') {
                return;
            }
            if ($nextquestionid == 0) {
                throw new \Exception("Should not be 0");
            }

            $question = question_bank::load_question($nextquestionid);
            $this->assertEquals($expectedquestion['label'], $question->idnumber);
            $this->createresponse($question, $expectedquestion['is_correct_response']);
            $attempt->update_after_question_answered(time());
            if (!$hasqubaid) {
                $attempt->set_quba_id($this->quba->get_id());
                $hasqubaid = true;
            }
        }
    }

    /**
     * Data provider to test that the expected questions are returned.
     *
     * @return array
     */
    public static function strategy_returns_expected_questions_provider(): array {
        return [
            // The expected values for the radical CAT dataset are confirmed.
            'radical CAT 1' => [
                'strategy' => LOCAL_CATQUIZ_STRATEGY_FASTEST,
                'questions' => [
                    ['label' => 'SIMB01-18', 'is_correct_response' => false, 'ability_before' => 0.00, 'ability_after' => 0.00],
                    ['label' => 'SIMB02-00', 'is_correct_response' => false, 'ability_before' => 0.00, 'ability_after' => -0.39],
                    ['label' => 'SIMA06-09', 'is_correct_response' => false, 'ability_before' => -0.39, 'ability_after' => -0.71],
                    ['label' => 'SIMA04-00', 'is_correct_response' => false, 'ability_before' => -0.71, 'ability_after' => -1.07],
                    ['label' => 'SIMA02-02', 'is_correct_response' => false, 'ability_before' => -1.07, 'ability_after' => -1.35],
                    ['label' => 'SIMA04-10', 'is_correct_response' => false, 'ability_before' => -1.35, 'ability_after' => -1.54],
                    ['label' => 'SIMA02-19', 'is_correct_response' => false, 'ability_before' => -1.54, 'ability_after' => -1.77],
                    ['label' => 'SIMA05-04', 'is_correct_response' => false, 'ability_before' => -1.77, 'ability_after' => -1.99],
                    ['label' => 'SIMA02-08', 'is_correct_response' => false, 'ability_before' => -1.99, 'ability_after' => -2.25],
                    ['label' => 'SIMA02-17', 'is_correct_response' => false, 'ability_before' => -2.25, 'ability_after' => -2.33],
                    ['label' => 'SIMA05-03', 'is_correct_response' => false, 'ability_before' => -2.33, 'ability_after' => -2.61],
                    ['label' => 'SIMA05-07', 'is_correct_response' => false, 'ability_before' => -2.61, 'ability_after' => -2.81],
                    ['label' => 'SIMA02-04', 'is_correct_response' => false, 'ability_before' => -2.81, 'ability_after' => -2.97],
                    ['label' => 'SIMA05-00', 'is_correct_response' => false, 'ability_before' => -2.97, 'ability_after' => -3.07],
                    ['label' => 'SIMA01-19', 'is_correct_response' => false, 'ability_before' => -3.07, 'ability_after' => -3.14],
                    ['label' => 'SIMA01-16', 'is_correct_response' => true,  'ability_before' => -3.14, 'ability_after' => -3.22],
                    ['label' => 'SIMA01-12', 'is_correct_response' => false, 'ability_before' => -3.22, 'ability_after' => -3.48],
                    ['label' => 'SIMA01-13', 'is_correct_response' => true,  'ability_before' => -3.48, 'ability_after' => -3.69],
                    ['label' => 'SIMA01-18', 'is_correct_response' => true,  'ability_before' => -3.69, 'ability_after' => -3.61],
                    ['label' => 'SIMA01-14', 'is_correct_response' => true,  'ability_before' => -3.61, 'ability_after' => -3.54],
                    ['label' => 'SIMA03-03', 'is_correct_response' => true,  'ability_before' => -3.54, 'ability_after' => -3.48],
                    ['label' => 'SIMA03-13', 'is_correct_response' => true,  'ability_before' => -3.48, 'ability_after' => -3.43],
                    ['label' => 'SIMA03-16', 'is_correct_response' => true,  'ability_before' => -3.43, 'ability_after' => -3.40],
                    ['label' => 'SIMA01-17', 'is_correct_response' => true,  'ability_before' => -3.40, 'ability_after' => -3.36],
                    ['label' => 'SIMA01-06', 'is_correct_response' => true,  'ability_before' => -3.36, 'ability_after' => -3.33],
                    ['label' => 'FINISH',    'is_correct_response' => true,  'ability_before' => -3.33, 'ability_after' => -3.31],
                ],
            ],
            'radical CAT 2' => [
                'strategy' => LOCAL_CATQUIZ_STRATEGY_FASTEST,
                'questions' => [
                    ['label' => 'SIMB01-18', 'is_correct_response' => true,  'ability_before' => 0.00, 'ability_after' => 0.00],
                    ['label' => 'SIMC03-15', 'is_correct_response' => true,  'ability_before' => 0.00, 'ability_after' => 0.46],
                    ['label' => 'SIMB03-04', 'is_correct_response' => true,  'ability_before' => 0.46, 'ability_after' => 0.85],
                    ['label' => 'SIMB03-06', 'is_correct_response' => true,  'ability_before' => 0.85, 'ability_after' => 1.22],
                    ['label' => 'SIMB03-11', 'is_correct_response' => true,  'ability_before' => 1.22, 'ability_after' => 1.55],
                    ['label' => 'SIMB02-12', 'is_correct_response' => true,  'ability_before' => 1.55, 'ability_after' => 1.64],
                    ['label' => 'SIMB02-07', 'is_correct_response' => true,  'ability_before' => 1.64, 'ability_after' => 2.15],
                    ['label' => 'SIMB04-03', 'is_correct_response' => true,  'ability_before' => 2.15, 'ability_after' => 2.59],
                    ['label' => 'SIMB04-06', 'is_correct_response' => true,  'ability_before' => 2.59, 'ability_after' => 2.92],
                    ['label' => 'SIMC10-09', 'is_correct_response' => true,  'ability_before' => 2.92, 'ability_after' => 3.14],
                    ['label' => 'SIMC10-00', 'is_correct_response' => true,  'ability_before' => 3.14, 'ability_after' => 3.31],
                    ['label' => 'SIMC10-01', 'is_correct_response' => true,  'ability_before' => 3.31, 'ability_after' => 3.46],
                    ['label' => 'SIMC05-17', 'is_correct_response' => true,  'ability_before' => 3.46, 'ability_after' => 3.57],
                    ['label' => 'SIMC06-14', 'is_correct_response' => true,  'ability_before' => 3.57, 'ability_after' => 3.70],
                    ['label' => 'SIMC07-08', 'is_correct_response' => true,  'ability_before' => 3.70, 'ability_after' => 3.82],
                    ['label' => 'SIMC05-03', 'is_correct_response' => true,  'ability_before' => 3.82, 'ability_after' => 3.93],
                    ['label' => 'SIMC06-04', 'is_correct_response' => true,  'ability_before' => 3.93, 'ability_after' => 4.06],
                    ['label' => 'SIMC06-17', 'is_correct_response' => true,  'ability_before' => 4.06, 'ability_after' => 4.20],
                    ['label' => 'SIMC09-10', 'is_correct_response' => true,  'ability_before' => 4.20, 'ability_after' => 4.28],
                    ['label' => 'SIMC09-16', 'is_correct_response' => true,  'ability_before' => 4.28, 'ability_after' => 4.46],
                    ['label' => 'SIMC08-12', 'is_correct_response' => false, 'ability_before' => 4.46, 'ability_after' => 4.62],
                    ['label' => 'SIMC08-11', 'is_correct_response' => false, 'ability_before' => 4.62, 'ability_after' => 4.74],
                    ['label' => 'SIMC09-05', 'is_correct_response' => true,  'ability_before' => 4.74, 'ability_after' => 4.66],
                    ['label' => 'SIMC08-18', 'is_correct_response' => true,  'ability_before' => 4.66, 'ability_after' => 4.70],
                    ['label' => 'SIMC08-16', 'is_correct_response' => false, 'ability_before' => 4.70, 'ability_after' => 4.76],
                    ['label' => 'FINISH'   , 'is_correct_response' => false, 'ability_before' => 4.76, 'ability_after' => 4.73],
                ],
            ],
            'radical CAT 3' => [
                'strategy' => LOCAL_CATQUIZ_STRATEGY_FASTEST,
                'questions' => [
                    ['label' => 'SIMB01-18', 'is_correct_response' => true,  'ability_before' => 0.00, 'ability_after' => 0.00],
                    ['label' => 'SIMC03-15', 'is_correct_response' => true,  'ability_before' => 0.00, 'ability_after' => 0.46],
                    ['label' => 'SIMB03-04', 'is_correct_response' => true,  'ability_before' => 0.46, 'ability_after' => 0.85],
                    ['label' => 'SIMB03-06', 'is_correct_response' => true,  'ability_before' => 0.85, 'ability_after' => 1.22],
                    ['label' => 'SIMB03-11', 'is_correct_response' => true,  'ability_before' => 1.22, 'ability_after' => 1.55],
                    ['label' => 'SIMB02-12', 'is_correct_response' => false, 'ability_before' => 1.55, 'ability_after' => 1.64],
                    ['label' => 'SIMB01-01', 'is_correct_response' => false, 'ability_before' => 1.64, 'ability_after' => 1.76],
                    ['label' => 'SIMB03-05', 'is_correct_response' => true,  'ability_before' => 1.76, 'ability_after' => 1.62],
                    ['label' => 'SIMB02-09', 'is_correct_response' => false, 'ability_before' => 1.62, 'ability_after' => 1.68],
                    ['label' => 'SIMA02-13', 'is_correct_response' => true,  'ability_before' => 1.68, 'ability_after' => 1.63],
                    ['label' => 'SIMC03-12', 'is_correct_response' => false, 'ability_before' => 1.63, 'ability_after' => 1.65],
                    ['label' => 'SIMB01-04', 'is_correct_response' => true,  'ability_before' => 1.65, 'ability_after' => 1.53],
                    ['label' => 'SIMB03-12', 'is_correct_response' => true,  'ability_before' => 1.53, 'ability_after' => 1.54],
                    ['label' => 'SIMC03-13', 'is_correct_response' => true,  'ability_before' => 1.54, 'ability_after' => 1.56],
                    ['label' => 'SIMB03-14', 'is_correct_response' => true,  'ability_before' => 1.56, 'ability_after' => 1.58],
                    ['label' => 'SIMB01-11', 'is_correct_response' => true,  'ability_before' => 1.58, 'ability_after' => 1.60],
                    ['label' => 'SIMB03-08', 'is_correct_response' => false, 'ability_before' => 1.60, 'ability_after' => 1.61],
                    ['label' => 'SIMB03-16', 'is_correct_response' => true,  'ability_before' => 1.61, 'ability_after' => 1.56],
                    ['label' => 'FINISH',    'is_correct_response' => null,  'ability_before' => 1.56, 'ability_after' => 1.57],
                ],
            ],
            'radical CAT 4' => [
                'strategy' => LOCAL_CATQUIZ_STRATEGY_FASTEST,
                'questions' => [
                    ['label' => 'SIMB01-18', 'is_correct_response' => true,  'ability_before' => 0.00, 'ability_after' => 0.00],
                    ['label' => 'SIMC03-15', 'is_correct_response' => true,  'ability_before' => 0.00, 'ability_after' => 0.46],
                    ['label' => 'SIMB03-04', 'is_correct_response' => false, 'ability_before' => 0.46, 'ability_after' => 0.85],
                    ['label' => 'SIMB03-10', 'is_correct_response' => true,  'ability_before' => 0.85, 'ability_after' => 0.76],
                    ['label' => 'SIMB01-04', 'is_correct_response' => true,  'ability_before' => 0.76, 'ability_after' => 0.88],
                    ['label' => 'SIMB03-16', 'is_correct_response' => false, 'ability_before' => 0.88, 'ability_after' => 1.06],
                    ['label' => 'SIMB01-11', 'is_correct_response' => true,  'ability_before' => 1.06, 'ability_after' => 0.95],
                    ['label' => 'SIMA02-13', 'is_correct_response' => false, 'ability_before' => 0.95, 'ability_after' => 1.03],
                    ['label' => 'SIMB03-12', 'is_correct_response' => false, 'ability_before' => 1.03, 'ability_after' => 0.98],
                    ['label' => 'SIMC02-03', 'is_correct_response' => true,  'ability_before' => 0.98, 'ability_after' => 0.93],
                    ['label' => 'SIMB02-03', 'is_correct_response' => false, 'ability_before' => 0.93, 'ability_after' => 0.98],
                    ['label' => 'SIMC03-11', 'is_correct_response' => true,  'ability_before' => 0.98, 'ability_after' => 0.93],
                    ['label' => 'SIMC03-13', 'is_correct_response' => true,  'ability_before' => 0.93, 'ability_after' => 0.94],
                    ['label' => 'SIMB03-11', 'is_correct_response' => false, 'ability_before' => 0.94, 'ability_after' => 0.98],
                    ['label' => 'SIMB03-09', 'is_correct_response' => false, 'ability_before' => 0.98, 'ability_after' => 0.96],
                    ['label' => 'SIMC03-18', 'is_correct_response' => false, 'ability_before' => 0.96, 'ability_after' => 0.92],
                    ['label' => 'SIMB03-18', 'is_correct_response' => false, 'ability_before' => 0.92, 'ability_after' => 0.88],
                    ['label' => 'SIMB03-07', 'is_correct_response' => true,  'ability_before' => 0.88, 'ability_after' => 0.85],
                    ['label' => 'SIMC03-14', 'is_correct_response' => true,  'ability_before' => 0.85, 'ability_after' => 0.87],
                    ['label' => 'SIMB01-06', 'is_correct_response' => true,  'ability_before' => 0.87, 'ability_after' => 0.88],
                    ['label' => 'SIMB03-06', 'is_correct_response' => false, 'ability_before' => 0.88, 'ability_after' => 0.90],
                    ['label' => 'FINISH',    'is_correct_response' => null,  'ability_before' => 0.90, 'ability_after' => 0.90],
                ],
            ],
            'radical CAT 5' => [
                'strategy' => LOCAL_CATQUIZ_STRATEGY_FASTEST,
                'questions' => [
                    ['label' => 'SIMB01-18', 'is_correct_response' => true,  'ability_before' => 0.00, 'ability_after' => 0.00],
                    ['label' => 'SIMC03-15', 'is_correct_response' => true,  'ability_before' => 0.00, 'ability_after' => 0.46],
                    ['label' => 'SIMB03-04', 'is_correct_response' => true,  'ability_before' => 0.46, 'ability_after' => 0.85],
                    ['label' => 'SIMB03-06', 'is_correct_response' => true,  'ability_before' => 0.85, 'ability_after' => 1.22],
                    ['label' => 'SIMB03-11', 'is_correct_response' => true,  'ability_before' => 1.22, 'ability_after' => 1.55],
                    ['label' => 'SIMB02-12', 'is_correct_response' => true,  'ability_before' => 1.55, 'ability_after' => 1.64],
                    ['label' => 'SIMB02-07', 'is_correct_response' => true,  'ability_before' => 1.64, 'ability_after' => 2.15],
                    ['label' => 'SIMB04-03', 'is_correct_response' => true,  'ability_before' => 2.15, 'ability_after' => 2.59],
                    ['label' => 'SIMB04-06', 'is_correct_response' => true,  'ability_before' => 2.59, 'ability_after' => 2.92],
                    ['label' => 'SIMC10-09', 'is_correct_response' => true,  'ability_before' => 2.92, 'ability_after' => 3.14],
                    ['label' => 'SIMC10-00', 'is_correct_response' => true,  'ability_before' => 3.14, 'ability_after' => 3.31],
                    ['label' => 'SIMC10-01', 'is_correct_response' => true,  'ability_before' => 3.31, 'ability_after' => 3.46],
                    ['label' => 'SIMC05-17', 'is_correct_response' => false, 'ability_before' => 3.46, 'ability_after' => 3.57],
                    ['label' => 'SIMC10-12', 'is_correct_response' => false, 'ability_before' => 3.57, 'ability_after' => 3.60],
                    ['label' => 'SIMB04-08', 'is_correct_response' => true,  'ability_before' => 3.60, 'ability_after' => 3.50],
                    ['label' => 'SIMC07-15', 'is_correct_response' => true,  'ability_before' => 3.50, 'ability_after' => 3.55],
                    ['label' => 'SIMC06-14', 'is_correct_response' => false, 'ability_before' => 3.55, 'ability_after' => 3.61],
                    ['label' => 'SIMC05-11', 'is_correct_response' => false, 'ability_before' => 3.61, 'ability_after' => 3.57],
                    ['label' => 'SIMC04-17', 'is_correct_response' => true,  'ability_before' => 3.57, 'ability_after' => 3.52],
                    ['label' => 'SIMC07-08', 'is_correct_response' => true,  'ability_before' => 3.52, 'ability_after' => 3.58],
                    ['label' => 'SIMC07-06', 'is_correct_response' => true,  'ability_before' => 3.58, 'ability_after' => 3.64],
                    ['label' => 'SIMC10-16', 'is_correct_response' => true,  'ability_before' => 3.64, 'ability_after' => 3.68],
                    ['label' => 'SIMC06-13', 'is_correct_response' => true,  'ability_before' => 3.68, 'ability_after' => 3.72],
                    ['label' => 'SIMC05-03', 'is_correct_response' => false, 'ability_before' => 3.72, 'ability_after' => 3.77],
                    ['label' => 'SIMC06-09', 'is_correct_response' => false, 'ability_before' => 3.77, 'ability_after' => 3.75],
                    ['label' => 'FINISH',    'is_correct_response' => null,  'ability_before' => 3.75, 'ability_after' => 3.72],
                ],
            ],
            /* 'moderate CAT' => [
            //    'strategy' => LOCAL_CATQUIZ_STRATEGY_BALANCED,
            //    'questions' => [
            //        [
            //            'label' => 'SIMA01-00',
            //            'is_correct_response' => true,
            //            'ability_before' => 0,
            //            'ability_after' => 0.0,
            //        ],
            //        [
            //            'label' => 'SIMA01-01',
            //            'is_correct_response' => false,
            //            'ability_before' => 0.0,
            //            'ability_after' => 0.0,
            //        ],
            //        [
            //            'label' => 'SIMA01-02',
            //            'is_correct_response' => true,
            //            'ability_before' => 0.0,
            //            'ability_after' => -4.4793,
            //        ],
            //    ],
            //],
            */
            // phpcs:enable
            'Infer lowest skillgap' => [
                'strategy' => LOCAL_CATQUIZ_STRATEGY_LOWESTSUB,
                'questions' => [
                    [
                        'label' => 'SIMB01-18',
                        'is_correct_response' => false,
                        'ability_before' => 0,
                        'ability_after' => 0.0,
                    ],
                    [
                        'label' => 'SIMA06-15',
                        'is_correct_response' => false,
                        'ability_before' => 0,
                        'ability_after' => -0.67,
                    ],
                    [
                        'label' => 'SIMA02-02',
                        'is_correct_response' => false,
                        'ability_before' => -0.67,
                        'ability_after' => -1.3,
                    ],
                    [
                        'label' => 'SIMA02-19',
                        'is_correct_response' => false,
                        'ability_before' => -1.3,
                        'ability_after' => -1.86,
                    ],
                    [
                        'label' => 'SIMA02-17',
                        'is_correct_response' => false,
                        'ability_before' => -1.86,
                        'ability_after' => -2.33,
                    ],
                    [
                        'label' => 'SIMA06-02',
                        'is_correct_response' => false,
                        'ability_before' => -2.33,
                        'ability_after' => -3.06,
                    ],
                    [
                        'label' => 'SIMB02-00',
                        'is_correct_response' => false,
                        'ability_before' => -3.06,
                        'ability_after' => -3.06,
                    ],
                    [
                        'label' => 'SIMB01-17',
                        'is_correct_response' => false,
                        'ability_before' => -3.06,
                        'ability_after' => -3.06,
                    ],
                    [
                        'label' => 'SIMB01-12',
                        'is_correct_response' => false,
                        'ability_before' => -3.06,
                        'ability_after' => -3.06,
                    ],
                    [
                        'label' => 'SIMA02-04',
                        'is_correct_response' => false,
                        'ability_before' => -3.06,
                        'ability_after' => -3.06,
                    ],
                    [
                        'label' => 'SIMB02-02',
                        'is_correct_response' => false,
                        'ability_before' => -3.06,
                        'ability_after' => -3.39,
                    ],
                    [
                        'label' => 'SIMA01-13',
                        'is_correct_response' => true,
                        'ability_before' => -3.39,
                        'ability_after' => -3.39,
                    ],
                    [
                        'label' => 'SIMA01-16',
                        'is_correct_response' => true,
                        'ability_before' => -3.39,
                        'ability_after' => -3.41,
                    ],
                    [
                        'label' => 'SIMA01-19',
                        'is_correct_response' => false,
                        'ability_before' => -3.41,
                        'ability_after' => -3.24,
                    ],
                    [
                        'label' => 'SIMA01-06',
                        'is_correct_response' => true,
                        'ability_before' => -3.24,
                        'ability_after' => -3.35,
                    ],
                    [
                        'label' => 'SIMA03-13',
                        'is_correct_response' => true,
                        'ability_before' => -3.35,
                        'ability_after' => -3.31,
                    ],
                    [
                        'label' => 'SIMA03-03',
                        'is_correct_response' => true,
                        'ability_before' => -3.31,
                        'ability_after' => -3.27,
                    ],
                    [
                        'label' => 'SIMA03-16',
                        'is_correct_response' => true,
                        'ability_before' => -3.27,
                        'ability_after' => -3.21,
                    ],
                    [
                        'label' => 'SIMA05-00',
                        'is_correct_response' => false,
                        'ability_before' => -3.21,
                        'ability_after' => -3.15,
                    ],
                    [
                        'label' => 'SIMA05-07',
                        'is_correct_response' => false,
                        'ability_before' => -3.15,
                        'ability_after' => -3.21,
                    ],
                    [
                        'label' => 'SIMA05-15',
                        'is_correct_response' => false,
                        'ability_before' => -3.21,
                        'ability_after' => -3.25,
                    ],
                    [
                        'label' => 'SIMA01-07',
                        'is_correct_response' => false,
                        'ability_before' => -3.25,
                        'ability_after' => -3.29,
                    ],
                    [
                        'label' => 'SIMA01-12',
                        'is_correct_response' => false,
                        'ability_before' => -3.29,
                        'ability_after' => -3.31,
                    ],
                    [
                        'label' => 'SIMA01-14',
                        'is_correct_response' => true,
                        'ability_before' => -3.31,
                        'ability_after' => -3.45,
                    ],
                    [
                        'label' => 'SIMA03-19',
                        'is_correct_response' => true,
                        'ability_before' => -3.45,
                        'ability_after' => -3.41,
                    ],
                    [
                        'label' => 'FINISH',
                        'is_correct_response' => false,
                        'ability_before' => -3.41,
                        'ability_after' => -3.38,
                    ],
                ],
                'initial_ability' => 0.02,
                'initial_se' => 2.97,
            ],
            // phpcs:disable
            //'Infer greatest strength' => [
            //    'strategy' => LOCAL_CATQUIZ_STRATEGY_HIGHESTSUB,
            //    'questions' => [
            //        [
            //            'label' => 'SIMB01-18',
            //            'is_correct_response' => true,
            //            'ability_before' => 0,
            //            'ability_after' => 0.0,
            //        ],
            //        [
            //            'label' => 'SIMA01-15',
            //            'is_correct_response' => false,
            //            'ability_before' => 0,
            //            'ability_after' => 2.5,
            //        ],
            //        [
            //            'label' => 'SIMA02-03',
            //            'is_correct_response' => true,
            //            'ability_before' => 2.5,
            //            'ability_after' => 0.5539,
            //        ],
            //    ],
            //],
            //'Infer all subscales' => [
            //    'strategy' => LOCAL_CATQUIZ_STRATEGY_ALLSUBS,
            //    'questions' => [
            //        [
            //            'label' => 'SIMB01-18',
            //            'is_correct_response' => true,
            //            'ability_before' => 0,
            //            'ability_after' => 0.0,
            //        ],
            //        [
            //            'label' => 'SIMB02-07',
            //            'is_correct_response' => false,
            //            'ability_before' => 0,
            //            'ability_after' => 2.5,
            //        ],
            //        [
            //            'label' => 'SIMB03-06',
            //            'is_correct_response' => true,
            //            'ability_before' => 2.5,
            //            'ability_after' => 1.1569,
            //        ],
            //    ],
            //],
            //'Classical test' => [
            //    'strategy' => LOCAL_CATQUIZ_STRATEGY_CLASSIC,
            //    'questions' => [
            //        [
            //            'label' => 'SIMA01-00',
            //            'is_correct_response' => true,
            //            'ability_before' => 0,
            //            'ability_after' => 0.0,
            //        ],
            //        [
            //            'label' => 'SIMA01-01',
            //            'is_correct_response' => true,
            //            'ability_before' => 0,
            //            'ability_after' => 0.02,
            //        ],
            //        [
            //            'label' => 'SIMA01-02',
            //            'is_correct_response' => false,
            //            'ability_before' => 0.02,
            //            'ability_after' => 0.02,
            //        ],
            //        [
            //            'label' => 'SIMA01-03',
            //            'is_correct_response' => true,
            //            'ability_before' => 0.02,
            //            'ability_after' => -3.94,
            //        ],
            //    ],
            //],
            // phpcs:enable
        ];
    }

    /**
     * Test if the correct person ability is calculated, given a set of responses.
     * This does not test a specific strategy but just that the overall value is correct.
     * @dataProvider given_responses_lead_to_expected_abilities_provider
     *
     * @param int $strategy The test strategy to use
     * @param array $responsepattern The given responses
     * @param float $abilityafter The expected ability
     * @return void
     */
    public function test_given_responses_lead_to_expected_abilities(
        int $strategy,
        array $responsepattern,
        float $abilityafter
    ) {
        $this->markTestIncomplete('Calculated value is not yet correct');
        global $DB, $USER;
        $this
            ->createtestenvironment($strategy)
            ->save_or_update();

        catquiz_handler::prepare_attempt_caches();

        // This is needed so that the responses to the questions are indeed saved to the database.
        $this->preventResetByRollback();
        $attemptdata = (object)[
            'instance' => 1,
            'questionsattempted' => 0,
            'id' => 1,
        ];
        foreach ($responsepattern as $label => $iscorrect) {
            [$nextquestionid, $message] = catquiz_handler::fetch_question_id('1', 'mod_adaptivequiz', $attemptdata);
            $question = question_bank::load_question($nextquestionid);
            $this->assertEquals($label, $question->idnumber);
            $this->createresponse($question, $iscorrect);
            $attemptdata->questionsattempted++;
        }
        $abilityrecord = $DB->get_record(
            'local_catquiz_personparams',
            ['userid' => $USER->id, 'catscaleid' => $this->catscaleid],
            'ability'
        );

        $ability = $abilityrecord ? $abilityrecord->ability : 0;
        $this->assertEquals(
            $abilityafter,
            $ability,
            'Ability after fetch is not correct'
        );
    }

    /**
     * Data provider to test that the expected questions are returned.
     *
     * @return array
     */
    public static function given_responses_lead_to_expected_abilities_provider(): array {
        global $CFG;
        $responsepattern = loadresponsesdata(
            $CFG->dirroot . '/local/catquiz/tests/fixtures/responses.2PL.csv'
        );
        return [
            'Classical test' => [
                'strategy' => LOCAL_CATQUIZ_STRATEGY_CLASSIC,
                'response_pattern' => $responsepattern,
                'ability_after' => 0.123,
            ],
        ];
    }

    /**
     * Create a response for the given question and save it in the database.
     *
     * @param mixed $question The question
     * @param bool $iscorrect Shows if the response is correct or not
     *
     * @return void
     */
    private function createresponse($question, $iscorrect): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $slot = $this->quba->add_question($question);
        $this->quba->start_question($slot);

        $time = time();
        $response = $correctresponse = $this->quba->get_correct_response($slot)['answer'];

        // Choose another valid but incorrect response.
        if (! $iscorrect) {
            if ($correctresponse >= 1) {
                $response = $correctresponse - 1;
            } else {
                $response = $correctresponse + 1;
            }
        }
        $this->quba->process_action($slot, ['answer' => $response]);
        $this->quba->finish_all_questions($time);

        // When performing answer evaluation.
        $evaluationresult = (new question_answer_evaluation($this->quba))->perform($slot);
        question_engine::save_questions_usage_by_activity($this->quba);
    }

    /**
     * Parse a json file to create a test environment that will be used for the attempt.
     *
     * @param int $strategyid
     * @return testenvironment
     */
    private function createtestenvironment(int $strategyid): testenvironment {
        global $DB;
        $catscale = $DB->get_record('local_catquiz_catscales', ['parentid' => 0]);
        $this->catscaleid = $catscale->id;
        $json = file_get_contents(__DIR__ . '/../fixtures/testenvironment.json');
        $jsondata = json_decode($json);
        $jsondata->catquiz_catscales = $this->catscaleid;
        $jsondata->catscaleid = $this->catscaleid;

        // Include all subscales in the test.
        foreach ([$catscale->id, ...catscale::get_subscale_ids($catscale->id)] as $scaleid) {
            $propertyname = sprintf('catquiz_subscalecheckbox_%d', $scaleid);
            $jsondata->$propertyname = true;
        }
        $jsondata->componentid = '1';
        $jsondata->component = 'mod_adaptivequiz';
        $jsondata->catquiz_selectteststrategy = $strategyid;
        $jsondata->maxquestionsgroup->catquiz_maxquestions = 25;
        $jsondata->maxquestionsgroup->catquiz_minquestions = 500;
        $jsondata->maxquestionsscalegroup->catquiz_maxquestionspersubscale = 25;
        $jsondata->json = json_encode($jsondata);
        $testenvironment = new testenvironment($jsondata);
        return $testenvironment;
    }

    /**
     * Import both questions and item params from fixture files.
     *
     * @param string $questionsfile The path to an XML questions file.
     * @param string $itemparamsfile The path to a CSV itemparams file.
     *
     * @return void
     */
    private function import(string $questionsfile, string $itemparamsfile): void {
        $this->resetAfterTest(true);

        // Import questions.
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();

        $this->adaptivequiz = $this->getDataGenerator()
            ->get_plugin_generator('mod_adaptivequiz')
            ->create_instance([
                'highestlevel' => 10,
                'lowestlevel' => 1,
                'standarderror' => 14,
                'course' => $this->course->id,
            ]);
        $qformat = $this->create_qformat($questionsfile, $this->course);
        $imported = $qformat->importprocess();
        $this->assertTrue($imported);

        $this->import_itemparams($itemparamsfile);
    }


    /**
     * Import the item params from the given CSV file
     *
     * @param string $filename The name of the itemparams file.
     *
     * @return void
     */
    private function import_itemparams($filename) {
        global $DB;
        $questions = $DB->get_records('question');
        if (! $questions) {
            exit('No questions were imported');
        }
        $importer = new testitemimporter();
        $content = file_get_contents(__DIR__ . '/../fixtures/' . $filename);
        $importer->execute_testitems_csv_import(
            (object) [
                'delimiter_name' => 'semicolon',
                'encoding' => null,
                'dateparseformat' => null,
            ],
            $content
        );
    }

    /**
     * Create a new qformat object so that we can import questions.
     *
     * NOTE: copied from qformat_xml_import_export_test.php
     *
     * Create object qformat_xml for test.
     * @param string $filename with name for testing file.
     * @param \stdClass $course
     * @return \qformat_xml XML question format object.
     */
    private function create_qformat($filename, $course) {
        $qformat = new \qformat_xml();
        $qformat->setContexts((new question_edit_contexts(context_course::instance($course->id)))->all());
        $qformat->setCourse($course);
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

}
