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

namespace local_catquiz\local\integrity;

use advanced_testcase;

/**
 * The read-only count of the polytomous items and responses that the old category mapping touched (#163).
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\local\integrity\category_fraction_check
 */
final class category_fraction_check_test extends advanced_testcase {
    /** @var int The CAT context. */
    private const CONTEXTID = 4711;

    /** @var int Next question id. */
    private int $question = 9100;

    /** @var int Next slot of the usage. */
    private int $slot = 1;

    /**
     * An item with active parameters.
     *
     * @param string $model
     * @param array $json
     * @param int $contextid
     * @param bool $active
     * @return int The question id.
     */
    private function item(string $model, array $json, int $contextid = self::CONTEXTID, bool $active = true): int {
        global $DB;
        $questionid = $this->question++;
        $itemid = (int) $DB->insert_record('local_catquiz_items', (object) [
            'componentname' => 'question', 'componentid' => $questionid, 'catscaleid' => 1,
            'contextid' => $contextid, 'lastupdated' => 0, 'status' => 0,
        ]);
        $paramid = (int) $DB->insert_record('local_catquiz_itemparams', (object) [
            'itemid' => $itemid, 'componentid' => $questionid, 'componentname' => 'question', 'contextid' => $contextid,
            'model' => $model, 'difficulty' => 0.1, 'discrimination' => 1.2, 'guessing' => 0, 'json' => json_encode($json),
            'status' => 4, 'timecreated' => 0, 'timemodified' => 0,
        ]);
        if ($active) {
            $DB->set_field('local_catquiz_items', 'activeparamid', $paramid, ['id' => $itemid]);
        }
        return $questionid;
    }

    /**
     * Graded responses to a question with the given fractions.
     *
     * @param int $questionid
     * @param array $fractions
     */
    private function responses(int $questionid, array $fractions): void {
        global $DB;
        foreach ($fractions as $fraction) {
            $qaid = (int) $DB->insert_record('question_attempts', (object) [
                'questionusageid' => 1, 'slot' => $this->slot++, 'behaviour' => 'deferredfeedback', 'questionid' => $questionid,
                'variant' => 1, 'maxmark' => 1, 'minfraction' => 0, 'maxfraction' => 1, 'flagged' => 0,
                'questionsummary' => '', 'rightanswer' => '', 'responsesummary' => '', 'timemodified' => 0,
            ]);
            $DB->insert_record('question_attempt_steps', (object) [
                'questionattemptid' => $qaid, 'sequencenumber' => 1, 'state' => 'gradedpartial', 'fraction' => $fraction,
                'timecreated' => 0, 'userid' => 2,
            ]);
        }
    }

    /**
     * Keys and responses whose rounding to three decimals lies below them count; other fractions, other contexts and
     * dichotomous models do not; nothing is written.
     */
    public function test_the_items_and_responses_concerned(): void {
        global $DB;
        $this->resetAfterTest();
        // Keys with seven decimals: a third rounds down, two thirds round up.
        $thirds = $this->item('grm', ['difficulties' => ['0.0000000' => 0, '0.3333333' => -0.5, '0.6666667' => 0.4,
            '1.0000000' => 1.0]]);
        // Keys with three decimals: not concerned themselves, but its responses with a third of the marks are.
        $pcm = $this->item('pcm', ['intercepts' => ['0.000' => 0, '0.333' => -0.2, '0.667' => 0.3, '1.000' => 0.8]]);
        $halves = $this->item('pcmgeneralized', ['intercepts' => ['0.0' => 0, '0.5' => -0.2, '1.0' => 0.3]]);
        $inactive = $this->item(
            'grmgeneralized',
            ['difficulties' => ['0.0' => 0, '0.8333333' => 0.2, '1.0' => 0.9]],
            self::CONTEXTID,
            false
        );
        $dichotomous = $this->item('raschbirnbaum', []);
        $elsewhere = $this->item(
            'grm',
            ['difficulties' => ['0.0' => 0, '0.5714286' => 0.1, '1.0' => 0.6]],
            self::CONTEXTID + 1
        );

        $this->responses($thirds, [0.3333333, 0.6666667, 1.0]);
        $this->responses($pcm, [0.3333333, 0.3333333, 0.0]);
        $this->responses($halves, [0.5, 1.0]);
        $this->responses($dichotomous, [0.3333333]);
        $this->responses($elsewhere, [0.5714286]);

        $writes = $DB->perf_get_writes();
        $result = category_fraction_check::check(self::CONTEXTID);
        $this->assertSame($writes, $DB->perf_get_writes(), 'Read-only.');

        $this->assertSame(4, $result['parameters']);
        $this->assertSame(2, $result['keys'], 'The keys of a third and of five sixths.');
        $this->assertSame(1, $result['activekeys']);
        $this->assertSame(3, $result['items']);
        $this->assertSame(3, $result['responses'], 'One third, once and twice.');
        $this->assertSame(2, $result['questions']);
        $this->assertSame([$thirds, $pcm], $result['samplequestions']);
        $this->assertNotContains($inactive, $result['samplequestions']);

        $all = category_fraction_check::check();
        $this->assertSame(5, $all['parameters']);
        $this->assertSame(3, $all['keys']);
        $this->assertSame(4, $all['responses']);
    }

    /**
     * The test of a fraction.
     */
    public function test_rounds_down(): void {
        foreach ([1 / 3, 0.3333333, 0.8333333, 0.5714286, 0.0833333] as $fraction) {
            $this->assertTrue(category_fraction_check::rounds_down($fraction), (string) $fraction);
        }
        foreach ([0.0, 0.5, 1.0, 0.333, 0.6666667, 0.1666667, 0.25] as $fraction) {
            $this->assertFalse(category_fraction_check::rounds_down($fraction), (string) $fraction);
        }
    }
}
