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

use local_catquiz\teststrategy\feedbackgenerator\graphicalsummary;

/**
 * The end of a long attempt (performance re-audit of 22.11.2026, finalisation at 2.368 answers: 20 s).
 *
 * The counts by scale walked the list of the scale for every response (O(N²) per scale and feedback area), and the
 * history loaded the definition of every question for its title. Both are linear now; the counts are the same.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\teststrategy\progress
 * @covers \local_catquiz\teststrategy\feedbackgenerator\graphicalsummary
 */
final class attempt_end_cost_test extends \advanced_testcase {
    /**
     * A progress of $n answered questions: every one in scale 2 and its parent 1, every third also in scale 3, every
     * seventh a pilot item; the fraction alternates 1, 0, 0.5.
     *
     * @param int $n
     * @return progress
     */
    private static function progress(int $n): progress {
        $progress = (new \ReflectionClass(progress::class))->newInstanceWithoutConstructor();
        $played = [];
        $byscale = [1 => [], 2 => [], 3 => []];
        $responses = [];
        for ($i = 1; $i <= $n; $i++) {
            $q = (object) ['id' => (string) (100000 + $i), 'is_pilot' => $i % 7 === 0];
            $played[$q->id] = $q;
            $byscale[1][] = $q;
            $byscale[2][] = $q;
            if ($i % 3 === 0) {
                $byscale[3][] = $q;
            }
            $responses[$q->id] = ['fraction' => [1.0, 0.0, 0.5][$i % 3]];
        }
        // A response to a question of no scale list (the first one of another attempt, say): counted only overall.
        $responses['999'] = ['fraction' => 1.0];
        foreach (['playedquestions' => $played, 'playedquestionsbyscale' => $byscale, 'responses' => $responses] as $k => $v) {
            (new \ReflectionProperty(progress::class, $k))->setValue($progress, $v);
        }
        return $progress;
    }

    /**
     * The counts and shares by scale are what they were, and linear: 12.000 answers in milliseconds. RED before: the
     * list of the scale was walked for every response - 12.000 × 12.000 comparisons per scale, seconds per call.
     */
    public function test_the_counts_by_scale_are_linear(): void {
        $small = self::progress(21);
        $this->assertSame(19, $small->get_num_answered_productive_questions(), '21 - 3 pilots + the one of no scale.');
        $this->assertSame(18, $small->get_num_answered_productive_questions(2));
        $this->assertSame(6, $small->get_num_answered_productive_questions(3), '7 in scale 3, one (21) a pilot.');
        $this->assertSame(0, $small->get_num_answered_productive_questions(4));
        $this->assertNull($small->get_fraction_for_scale(4));
        // Scale 3: i = 3, 6, 9, 12, 15, 18 (all i % 3 = 0: fraction 1.0).
        $this->assertSame(1.0, $small->get_fraction_for_scale(3));
        // Scale 2: six productive items each with i % 3 = 0, 1, 2 (fractions 1, 0, 0.5): 9 / 18.
        $this->assertSame(0.5, $small->get_fraction_for_scale(2));

        $large = self::progress(12000);
        $start = microtime(true);
        foreach ([1, 2, 3] as $scaleid) {
            $large->get_num_answered_productive_questions($scaleid);
            $large->get_fraction_for_scale($scaleid);
        }
        $seconds = microtime(true) - $start;
        $this->assertLessThan(1.0, $seconds, sprintf('Six counts over 12.000 answers: %.2f s.', $seconds));
        $this->assertSame(12000 - 1714, $large->get_num_answered_productive_questions(1));
    }

    /**
     * The titles of the history come from the titles read at once for the rows, without a query per row; a question
     * without a title keeps its label. RED before: the definition of every question was loaded (a question that is
     * not in the question bank fell back to the label).
     */
    public function test_the_titles_of_the_history_are_read_at_once(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = (new \ReflectionClass(graphicalsummary::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(graphicalsummary::class, 'titles'))->setValue($generator, [900001 => 'Title', 900002 => '']);
        $title = new \ReflectionMethod(graphicalsummary::class, 'get_question_title');
        $before = $DB->perf_get_queries();
        $this->assertSame('Title', $title->invoke($generator, 900001, 'label'));
        $this->assertSame('label', $title->invoke($generator, 900002, 'label'));
        $this->assertSame(0, $DB->perf_get_queries() - $before);

        // The titles of real questions, in one query.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id, 'name' => "T$i"]);
            $ids[] = (int) $question->id;
        }
        $titles = new \ReflectionMethod(graphicalsummary::class, 'question_titles');
        $before = $DB->perf_get_queries();
        $read = $titles->invoke(null, [...$ids, 123456789]);
        $this->assertSame(1, $DB->perf_get_queries() - $before);
        $this->assertCount(30, $read);
        $this->assertSame('T7', $read[$ids[7]]);
    }

    /**
     * The slots of the usage for the history on the result page: one query, whatever the number of slots, and the same
     * as the usage says. RED before: the usage was loaded with the definition of every question (cold caches: some
     * 3.700 queries for 625 answers).
     */
    public function test_the_slots_of_the_usage_are_read_at_once(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/engine/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $context = \context_system::instance();
        $quba = \question_engine::make_questions_usage_by_activity('mod_adaptivequiz', $context);
        $quba->set_preferred_behaviour('deferredfeedback');
        $ids = [];
        for ($i = 0; $i < 40; $i++) {
            $question = $generator->create_question('truefalse', null, ['category' => $category->id, 'name' => "S$i"]);
            $ids[] = (int) $question->id;
        }
        // A question in two slots.
        foreach ([...$ids, $ids[3]] as $questionid) {
            $slot = $quba->add_question(\question_bank::load_question($questionid));
            $quba->start_question($slot);
        }
        \question_engine::save_questions_usage_by_activity($quba);
        $small = \question_engine::make_questions_usage_by_activity('mod_adaptivequiz', $context);
        $small->set_preferred_behaviour('deferredfeedback');
        $small->start_question($small->add_question(\question_bank::load_question($ids[0])));
        \question_engine::save_questions_usage_by_activity($small);

        // As many queries for 1 slot as for 41 (after the purge, the first read of a cache definition adds its own).
        $queries = [];
        foreach ([$small, $quba] as $usage) {
            \cache_helper::purge_all();
            $before = $DB->perf_get_queries();
            $map = graphicalsummary::slot_map_of_usage((int) $usage->get_id());
            $queries[] = $DB->perf_get_queries() - $before;
        }
        $this->assertSame($queries[0], $queries[1], 'No query per slot: ' . json_encode($queries));
        $this->assertLessThanOrEqual(3, $queries[1], 'A fixed budget: ' . json_encode($queries));

        $expected = [];
        $loaded = \question_engine::load_questions_usage_by_activity($quba->get_id());
        foreach ($loaded->get_slots() as $slot) {
            $qa = $loaded->get_question_attempt($slot);
            $expected[(int) $qa->get_question()->id][] = [
                'slot' => (int) $slot,
                'questionattemptid' => (int) $qa->get_database_id(),
                'name' => (string) $qa->get_question()->name,
            ];
        }
        $this->assertSame($expected, $map, 'What the usage says.');
        $this->assertCount(2, $map[$ids[3]]);
    }
}
