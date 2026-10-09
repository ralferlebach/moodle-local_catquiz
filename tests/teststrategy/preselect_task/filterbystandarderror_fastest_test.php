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

namespace local_catquiz\teststrategy\preselect_task;

use advanced_testcase;
use local_catquiz\local\status;
use local_catquiz\teststrategy\progress;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * In the CAT strategy a subscale that has enough information does not end the test (issue #134).
 *
 * filter_for_cat() ended the whole test as soon as any updated scale - also a subscale - met its
 * dropping criterion. The minimum number of questions protects only the root, so attempts stopped
 * after 5 to 14 items with a minimum of 15, all with "no further questions".
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(filterbystandarderror::class)]
final class filterbystandarderror_fastest_test extends advanced_testcase {
    /**
     * Runs the filter after five answers in subscale S of root R.
     *
     * @param float $rootse Standard error of the root after the answer.
     * @param int $minimum Minimum number of questions of the test.
     * @return array{0: \local_catquiz\local\result, 1: progress, 2: int, 3: int} Result, progress, R, S.
     */
    private function after_five_answers_in_the_subscale(float $rootse, int $minimum): array {
        global $DB;

        $root = (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'name' => 'R', 'description' => '', 'parentid' => 0, 'contextid' => 1, 'minmaxgroup' => '',
            'minscalevalue' => -5, 'maxscalevalue' => 5, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $sub = (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'name' => 'S', 'description' => '', 'parentid' => $root, 'contextid' => 1, 'minmaxgroup' => '',
            'minscalevalue' => -5, 'maxscalevalue' => 5, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        \cache::make('local_catquiz', 'catscales')->purge();

        $progress = (new \ReflectionClass(progress::class))->newInstanceWithoutConstructor();
        $played = [];
        $responses = [];
        foreach (range(1, 5) as $i) {
            $q = (object) ['id' => 7100 + $i, 'componentid' => 7100 + $i, 'model' => 'rasch', 'status' => 1, 'difficulty' => 0.0,
                'catscaleid' => $sub, 'is_pilot' => false, 'fisherinformation' => []];
            $played[$q->id] = $q;
            $responses[$q->id] = ['questionid' => $q->id, 'fraction' => 1.0];
        }
        $state = [
            'playedquestions' => $played,
            'playedquestionsbyscale' => [$sub => $played, $root => $played],
            'responses' => $responses,
            'lastquestion' => end($played),
            'isfirstquestion' => false,
            'hasnewresponse' => true,
            'activescales' => [$root, $sub],
        ];
        foreach ($state as $name => $value) {
            $property = new \ReflectionProperty($progress, $name);
            $property->setAccessible(true);
            $property->setValue($progress, $value);
        }

        $context = [
            'progress' => $progress,
            'teststrategy' => LOCAL_CATQUIZ_STRATEGY_FASTEST,
            'catscaleid' => $root,
            'minimumquestions' => $minimum,
            'min_attempts_per_scale' => 0,
            'max_attempts_per_scale' => -1,
            // The subscale has all the information it needs: small error, ability no longer moving.
            'se' => [$sub => 0.2, $root => $rootse],
            'se_min' => 0.35,
            'prev_ability' => [$sub => 0.50, $root => 0.10],
            'person_ability' => [$sub => 0.51, $root => 0.11],
            'pp_min_inc' => 0.05,
            'userid' => 2,
            'contextid' => 1,
            'questions' => [],
        ];
        $result = (new filterbystandarderror())->run($context);

        return [$result, $progress, $root, $sub];
    }

    /**
     * A subscale meeting its criterion before the minimum is dropped; the test goes on.
     */
    public function test_a_finished_subscale_does_not_end_the_test(): void {
        $this->resetAfterTest();

        [$result, $progress, $root, $sub] = $this->after_five_answers_in_the_subscale(0.5, 15);

        $this->assertFalse($result->iserr(), 'The whole test ended on a subscale.');
        $this->assertNotContains($sub, $progress->get_active_scales(), 'The finished subscale was not dropped.');
        $this->assertContains($root, $progress->get_active_scales());
    }

    /**
     * Before the minimum, the root is protected even when its own criterion is met.
     */
    public function test_the_root_is_protected_before_the_minimum(): void {
        $this->resetAfterTest();

        [$result] = $this->after_five_answers_in_the_subscale(0.2, 15);

        $this->assertFalse($result->iserr());
    }

    /**
     * Past the minimum, the root meeting its criterion ends the test as before.
     */
    public function test_the_root_ends_the_test_past_the_minimum(): void {
        $this->resetAfterTest();

        [$result] = $this->after_five_answers_in_the_subscale(0.2, 3);

        $this->assertTrue($result->iserr());
        $this->assertSame(status::ERROR_NO_REMAINING_QUESTIONS, $result->get_status());
    }
    /**
     * Running out of questions before the minimum has its own status, not a regular end (FASTEST-004).
     */
    public function test_running_out_before_the_minimum_has_its_own_status(): void {
        $this->resetAfterTest();
        [, $progress] = $this->after_five_answers_in_the_subscale(0.5, 15);

        $before = ['progress' => $progress, 'minimumquestions' => 15, 'questions' => [], 'pilot_questions' => []];
        $after = ['progress' => $progress, 'minimumquestions' => 3, 'questions' => [], 'pilot_questions' => []];

        $this->assertSame(
            status::ERROR_NO_REMAINING_QUESTIONS_BEFORE_MINIMUM,
            (new noremainingquestions())->run($before)->get_status()
        );
        $this->assertSame(status::ERROR_NO_REMAINING_QUESTIONS, (new noremainingquestions())->run($after)->get_status());
    }
}
