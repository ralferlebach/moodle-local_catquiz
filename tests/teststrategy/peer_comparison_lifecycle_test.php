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
use local_catquiz\teststrategy\feedbackgenerator\comparetotestaverage;
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
 * Issues #166 and #168: the comparison with the other people of a test leaves the answers - computed once, when the
 * attempt ends - and the stored data of the attempt keep their form.
 *
 * Real attempts on the simulation pool, with the feedback data written after every answer, as in a course: the
 * people before are the peers of the last one.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquiz\teststrategy\feedbackgenerator\comparetotestaverage
 * @covers     \local_catquiz\output\attemptfeedback
 * @covers     \local_catquiz\local\attempt\attempt_finalizer
 * @covers     \local_catquiz\catquiz
 */
final class peer_comparison_lifecycle_test extends advanced_testcase {
    /** @var \stdClass The activity. */
    private \stdClass $quiz;

    /** @var \stdClass */
    private \stdClass $course;

    /** @var int */
    private int $rootid;

    /** @var int */
    private int $contextid;

    /**
     * The simulation pool, a test with the classical strategy.
     */
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
     * Unsets the switch of the testing classes.
     */
    protected function tearDown(): void {
        putenv('CATQUIZ_TESTING_SKIP_FEEDBACK');
        parent::tearDown();
    }

    /**
     * Plays an attempt to its end, the feedback data written after every answer, and finalises it.
     *
     * @param \stdClass $user
     * @param int $seed Which answers are right.
     * @param bool $finalise
     * @return array ['attemptid' => int, 'peerwithanswers' => int, 'peeratend' => int, 'beforeend' => array]
     */
    private function play(\stdClass $user, int $seed, bool $finalise = true): array {
        global $DB;
        $this->setUser($user);
        catquiz_handler::prepare_attempt_caches();
        $cm = get_coursemodule_from_instance('adaptivequiz', $this->quiz->id);
        $quba = question_engine::make_questions_usage_by_activity('mod_adaptivequiz', context_module::instance($cm->id));
        $quba->set_preferred_behaviour('deferredfeedback');
        $attemptid = (int) (new attempt($this->quiz, $user->id))->get_attempt()->id;
        $peer = catquiz::$peercomparisons;
        for ($i = 0; $i < 30; $i++) {
            $record = $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid], '*', MUST_EXIST);
            $attempt = new attempt($this->quiz, $record->userid);
            $result = catquiz_handler::fetch_question_id((int) $this->quiz->id, 'mod_adaptivequiz', $attempt->get_attempt());
            if (empty($result[0])) {
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
        $peerwithanswers = catquiz::$peercomparisons - $peer;
        $beforeend = json_decode((string) $DB->get_field('local_catquiz_attempts', 'json', ['attemptid' => $attemptid]), true);
        $peer = catquiz::$peercomparisons;
        if ($finalise) {
            $DB->set_field('adaptivequiz_attempt', 'attemptstate', 'complete', ['id' => $attemptid]);
            $DB->set_field('adaptivequiz_attempt', 'timefinished', time(), ['id' => $attemptid]);
            attempt_finalizer::finalize($attemptid, time(), 'reachedmaximumquestions');
        }
        return [
            'attemptid' => $attemptid, 'peerwithanswers' => $peerwithanswers,
            'peeratend' => catquiz::$peercomparisons - $peer, 'beforeend' => $beforeend,
        ];
    }

    /**
     * The comparison the rule gives for an attempt, computed here independently of the generator.
     *
     * @param int $attemptid
     * @return array testaverageability, comparetotestaverage_has_enough_peers, comparetotestaverage_has_worse
     */
    private function expected(int $attemptid): array {
        global $DB;
        $progress = progress::load($attemptid, 'mod_adaptivequiz', $this->contextid);
        $ability = $progress->get_abilities()[$this->rootid];
        $userid = (int) $DB->get_field('adaptivequiz_attempt', 'userid', ['id' => $attemptid]);
        $count = catquiz::$peercomparisons;
        $stats = catquiz::get_peer_comparison_stats($this->contextid, $this->rootid, round($ability, 4), $userid);
        catquiz::$peercomparisons = $count;
        $average = (int) $stats->n > 0 ? (float) $stats->meanvalue : 0.0;
        return [
            'testaverageability' => sprintf('%.2f', max(-10.0, min(10.0, $average))),
            'comparetotestaverage_has_enough_peers' => (int) $stats->n >= comparetotestaverage::get_min_peers(),
            'comparetotestaverage_has_worse' => (int) $stats->lowercount > 0,
        ];
    }

    /**
     * No comparison with an answer; one, at the end; the stored values are those of the rule at the ability the
     * attempt ended with (issue #166).
     */
    public function test_the_comparison_is_computed_once_at_the_end(): void {
        global $DB;
        $users = [];
        for ($i = 0; $i < 5; $i++) {
            $users[] = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        }
        foreach ($users as $i => $user) {
            $run = $this->play($user, $i);
            $this->assertSame(0, $run['peerwithanswers'], "Attempt $i: no comparison with the answers.");
            $this->assertSame(1, $run['peeratend'], "Attempt $i: one comparison at the end.");
            // What the answers wrote: everything the attempt needs to go on and to show its result, no peers.
            foreach (comparetotestaverage::PEER_KEYS as $key) {
                $this->assertArrayNotHasKey($key, $run['beforeend'], "Attempt $i, before the end: $key.");
            }
            $kept = ['personabilities_abilities', 'userability', 'userabilityposition', 'colorbar', 'graphicalsummary_data'];
            foreach ($kept as $key) {
                $this->assertArrayHasKey($key, $run['beforeend'], "Attempt $i, before the end: $key.");
            }
            $stored = json_decode(
                (string) $DB->get_field('local_catquiz_attempts', 'json', ['attemptid' => $run['attemptid']]),
                true
            );
            foreach (comparetotestaverage::PEER_KEYS as $key) {
                $this->assertArrayHasKey($key, $stored, "Attempt $i, after the end: $key.");
            }
            $expected = $this->expected($run['attemptid']);
            foreach ($expected as $key => $value) {
                $this->assertSame($value, $stored[$key], "Attempt $i: $key as the rule gives it.");
            }
            // The rest of the data kept its form: the same keys as before the end, the comparison added.
            $this->assertSame(
                [],
                array_diff(array_keys($run['beforeend']), array_keys($stored)),
                'Nothing of the data before the end is lost.'
            );
        }
    }

    /**
     * The result page shows the comparison - from the stored attempt, also when the session holds the snapshot of
     * the last answer; an attempt that ended without one gets it when it is shown, stored once; a save of the feedback
     * data after the end keeps it (issues #166, #168).
     */
    public function test_the_comparison_on_the_result_page(): void {
        global $DB;
        $first = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $second = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->play($first, 0);
        $run = $this->play($second, 1);

        // The result page in the session of the attempt: the snapshot of the last answer lacks the comparison, the
        // area of the comparison has it - from the stored attempt.
        $feedback = new attemptfeedback($run['attemptid'], $this->contextid);
        $snapshot = $feedback->load_feedbackdata();
        $this->assertArrayNotHasKey('testaverageability', $snapshot, 'The snapshot of the session, from the last answer.');
        $area = null;
        foreach ($feedback->get_feedback_generators() as $generator) {
            if ($generator instanceof comparetotestaverage) {
                // A lazy tab is offered from the same data (has_data_for()), not only the content of the area.
                $this->assertTrue($generator->has_data_for($snapshot), 'The tab of the comparison is offered.');
                $area = $generator->get_feedback($snapshot);
            }
        }
        $flat = '';
        array_walk_recursive($area, function ($value) use (&$flat) {
            $flat .= is_scalar($value) ? ' ' . (string) $value : '';
        });
        $flat = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($flat)));
        $stored = json_decode((string) $DB->get_field('local_catquiz_attempts', 'json', ['attemptid' => $run['attemptid']]), true);
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($stored['comparisontext']))));
        $this->assertNotSame('', $text);
        $this->assertStringContainsString(\core_text::substr($text, 0, 40), $flat, 'The comparison is in its area.');

        // An attempt that ended before the comparison moved to the end, without one: nothing is computed for it
        // afterwards with the people of today (issue #159).
        $object = json_decode((string) $DB->get_field('local_catquiz_attempts', 'json', ['attemptid' => $run['attemptid']]));
        foreach (comparetotestaverage::PEER_KEYS as $key) {
            unset($object->$key);
        }
        $DB->set_field('local_catquiz_attempts', 'json', json_encode($object), ['attemptid' => $run['attemptid']]);
        set_config('peercomparisonatendsince', time() + 100, 'local_catquiz');
        $count = catquiz::$peercomparisons;
        $this->assertNull(attemptfeedback::peer_comparison_of($run['attemptid']));
        $this->assertSame($count, catquiz::$peercomparisons, 'Not computed.');
        $this->assertArrayNotHasKey('testaverageability', json_decode(
            (string) $DB->get_field('local_catquiz_attempts', 'json', ['attemptid' => $run['attemptid']]),
            true
        ), 'Nothing stored afterwards.');

        // An attempt that ended since, whose end added none (a failure at the end): computed when shown, stored once.
        set_config('peercomparisonatendsince', 0, 'local_catquiz');
        $again = attemptfeedback::peer_comparison_of($run['attemptid']);
        $this->assertSame($stored['testaverageability'], $again['testaverageability']);
        $this->assertSame($count + 1, catquiz::$peercomparisons);
        attemptfeedback::peer_comparison_of($run['attemptid']);
        $this->assertSame($count + 1, catquiz::$peercomparisons, 'Stored: not computed a second time.');

        // A save of the feedback data after the end keeps what the end added.
        $data = json_decode((string) $DB->get_field('local_catquiz_attempts', 'json', ['attemptid' => $run['attemptid']]), true);
        foreach (comparetotestaverage::PEER_KEYS as $key) {
            unset($data[$key]);
        }
        $data['progress'] = progress::load($run['attemptid'], 'mod_adaptivequiz', $this->contextid);
        $data['quizsettings'] = $data['progress']->get_quiz_settings();
        catquiz::save_attempt_to_db($data);
        $after = json_decode((string) $DB->get_field('local_catquiz_attempts', 'json', ['attemptid' => $run['attemptid']]), true);
        foreach (comparetotestaverage::PEER_KEYS as $key) {
            $this->assertSame($stored[$key], $after[$key], "Kept after a late save: $key.");
        }
    }

    /**
     * A running attempt shown before its end (a teacher's look) gets the comparison computed, but nothing is stored:
     * the end computes it (issue #166).
     */
    public function test_a_running_attempt_stores_no_comparison(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $run = $this->play($user, 2, false);
        $peer = attemptfeedback::peer_comparison_of($run['attemptid']);
        $this->assertNotNull($peer);
        $stored = json_decode((string) $DB->get_field('local_catquiz_attempts', 'json', ['attemptid' => $run['attemptid']]), true);
        $this->assertArrayNotHasKey('testaverageability', $stored);
    }
}
