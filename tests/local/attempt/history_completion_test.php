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

namespace local_catquiz\local\attempt;

use question_engine;

/**
 * The completion of old histories for their display (issue #159), performance re-audit of 22.11.2026: a fixed number of
 * queries whatever the number of missing rows (no query per row), the question version a slot used, a question given in
 * more than one slot.
 *
 * The attempt is built directly - a question usage with answered true/false questions and their items in a CAT
 * context -, so that 625 answers take seconds, not the minutes of a played attempt.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\local\attempt\history_completion
 */
final class history_completion_test extends \advanced_testcase {
    /** @var int The CAT context of the items. */
    private int $contextid = 0;

    /** @var int The scale of the items. */
    private int $scaleid = 0;

    /**
     * A finished CAT attempt whose question usage holds the given questions answered, slot by slot.
     *
     * @param int[] $questionids One per slot; a question may come twice.
     * @return int Id in local_catquiz_attempts.
     */
    private function attempt(array $questionids): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/engine/lib.php');
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id]);
        $context = \context_module::instance($activity->cmid);
        $quba = question_engine::make_questions_usage_by_activity('mod_adaptivequiz', $context);
        $quba->set_preferred_behaviour('deferredfeedback');
        foreach ($questionids as $i => $questionid) {
            $slot = $quba->add_question(\question_bank::load_question($questionid));
            $quba->start_question($slot);
            $quba->process_action($slot, ['answer' => $i % 2]);
        }
        $quba->finish_all_questions();
        question_engine::save_questions_usage_by_activity($quba);
        $attemptid = $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $activity->id, 'userid' => 2, 'uniqueid' => $quba->get_id(), 'attemptstate' => 'completed',
            'questionsattempted' => count($questionids), 'timecreated' => 1, 'timemodified' => 2,
        ]);
        return (int) $DB->insert_record('local_catquiz_attempts', (object) [
            'attemptid' => $attemptid, 'component' => 'adaptivequiz', 'contextid' => $this->contextid,
            'scaleid' => $this->scaleid, 'endtime' => 2, 'number_of_testitems_used' => count($questionids),
            'json' => '{}', 'timecreated' => 1, 'timemodified' => 2,
        ]);
    }

    /**
     * True/false questions with items and parameters in the CAT context.
     *
     * @param int $count
     * @return int[] Question ids.
     */
    private function questions(int $count): array {
        global $DB;
        $this->scaleid = (int) $DB->insert_record('local_catquiz_catscales', (object) ['name' => 'Scale', 'parentid' => 0]);
        $this->contextid = (int) $DB->insert_record('local_catquiz_catcontext', (object) ['name' => 'Context']);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $question = $generator->create_question('truefalse', null, ['category' => $category->id, 'name' => "Q$i"]);
            $this->item((int) $question->id, $i / 100);
            $ids[] = (int) $question->id;
        }
        return $ids;
    }

    /**
     * The item of a question with confirmed, usable parameters.
     *
     * @param int $questionid
     * @param float $difficulty
     */
    private function item(int $questionid, float $difficulty): void {
        global $DB;
        $itemid = $DB->insert_record('local_catquiz_items', (object) [
            'componentid' => $questionid, 'componentname' => 'question', 'catscaleid' => $this->scaleid,
            'contextid' => $this->contextid, 'status' => LOCAL_CATQUIZ_STATUS_UPDATED_MANUALLY,
        ]);
        $paramid = $DB->insert_record('local_catquiz_itemparams', (object) [
            'itemid' => $itemid, 'componentid' => $questionid, 'componentname' => 'question', 'contextid' => $this->contextid,
            'model' => 'rasch', 'difficulty' => $difficulty, 'status' => LOCAL_CATQUIZ_STATUS_UPDATED_MANUALLY, 'usable' => 1,
        ]);
        $DB->set_field('local_catquiz_items', 'activeparamid', $paramid, ['id' => $itemid]);
    }

    /**
     * A stored row of the history.
     *
     * @param int $questionid
     * @return array
     */
    private static function row(int $questionid): array {
        return ['id' => $questionid, 'questionname' => "stored $questionid", 'lastresponse' => 1, 'personability_after' => 0.1];
    }

    /**
     * The number of missing rows does not change the number of queries: 0, 1, 25, 100 and 625 missing answers of 625.
     * RED before: the items, the scale names and the question titles were read per missing row (plus the usage with
     * the definition of every question).
     */
    public function test_the_queries_do_not_grow_with_the_missing_rows(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/catquiz/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $ids = $this->questions(625);
        $catattemptid = $this->attempt($ids);
        $queries = [];
        foreach ([0, 1, 25, 100, 625] as $missing) {
            $stored = array_map(fn($id) => self::row($id), array_slice($ids, 0, 625 - $missing));
            \cache_helper::purge_all();
            $before = $DB->perf_get_queries();
            [$rows] = history_completion::complete($stored, [], $catattemptid);
            $queries[$missing] = $DB->perf_get_queries() - $before;
            $this->assertCount(625, $rows, "$missing missing: a row for every answer.");
            $order = array_map(fn($row) => (int) $row['id'], $rows);
            $this->assertSame($ids, $order, "$missing missing: in the order of the slots.");
            $completed = array_slice($rows, 625 - $missing);
            foreach ($completed as $row) {
                $this->assertSame(history_completion::SOURCE_QUESTIONUSAGE, $row['source']);
                $this->assertSame('Scale', $row['questionscale_name']);
                $this->assertFalse($row['pilot'], 'Confirmed, usable parameters: productive.');
                $this->assertNull($row['personability_after']);
            }
        }
        $this->assertLessThanOrEqual(1, $queries[0], 'Complete: only the attempt is read.');
        // The first completion after the purge reads one cache definition more (measured: 10, then 9, 9, 9).
        $withmissing = array_slice($queries, 1, null, true);
        $this->assertLessThanOrEqual(
            1,
            max($withmissing) - min($withmissing),
            'No growth with the missing rows: ' . json_encode($queries)
        );
        $this->assertLessThanOrEqual(10, max($withmissing), 'A fixed budget: ' . json_encode($queries));
    }

    /**
     * A question given in two slots has one row, at its first slot, with its first answer; the slot used the version
     * of the question that was shown, and the item of another version of it gives its parameters.
     */
    public function test_repeated_slots_and_versions(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/catquiz/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $ids = $this->questions(3);
        // A new version of the second question; the item stays with the first version.
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $data = \question_bank::load_question_data($ids[1]);
        $newversion = (int) $generator->update_question($data, null, ['name' => 'Q1 v2'])->id;
        $this->assertNotSame($ids[1], $newversion);
        $catattemptid = $this->attempt([$ids[0], $newversion, $ids[2], $ids[0]]);

        [$rows] = history_completion::complete([], [], $catattemptid);
        $order = array_map(fn($row) => (int) $row['id'], $rows);
        $this->assertSame([$ids[0], $newversion, $ids[2]], $order, 'One row per question.');
        $this->assertSame(1, $rows[0]['slot'], 'At the first slot.');
        $this->assertSame(0.0, (float) $rows[0]['lastresponse'], 'The first answer (false), not the one of slot 4.');
        $this->assertSame('Q1 v2', $rows[1]['questiontitle'], 'The version the slot used.');
        $this->assertEqualsWithDelta(0.01, $rows[1]['difficulty'], 1e-9, 'The item of the other version.');
        $this->assertFalse($rows[1]['pilot']);
        $this->assertSame(0, $DB->count_records('local_catquiz_items', ['componentid' => $newversion]), 'Nothing written.');
    }
}
