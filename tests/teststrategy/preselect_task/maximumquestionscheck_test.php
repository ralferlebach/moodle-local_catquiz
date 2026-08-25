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
 * Tests for the maximumquestionscheck preselect task.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\teststrategy\preselect_task;

use advanced_testcase;
use local_catquiz\local\status;
use local_catquiz\teststrategy\progress;

/**
 * Tests for the maximumquestionscheck preselect task.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\teststrategy\preselect_task\maximumquestionscheck
 */
final class maximumquestionscheck_test extends advanced_testcase {
    /**
     * Builds a progress object and injects the given number of non-pilot played
     * questions directly, bypassing the scale-hierarchy DB machinery of
     * add_playedquestion (not under test here).
     *
     * @param int $numplayed
     * @return progress
     */
    private function make_progress_with_played(int $numplayed): progress {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $progress = progress::load(4711, 'mod_adaptivequiz', 9, (object) []);

        $questions = [];
        for ($i = 1; $i <= $numplayed; $i++) {
            $questions[$i] = (object) ['id' => $i, 'is_pilot' => false];
        }
        $ref = new \ReflectionProperty(progress::class, 'playedquestions');
        $ref->setAccessible(true);
        $ref->setValue($progress, $questions);

        return $progress;
    }

    /**
     * When the mod_adaptivequiz attempt counter lags behind the resume-safe
     * progress tally (as it can after a resume/reload), the check must still
     * abort once the true number of played questions reaches the maximum.
     */
    public function test_aborts_on_resume_safe_count_when_counter_lags(): void {
        $this->resetAfterTest();
        $progress = $this->make_progress_with_played(4);

        $context = [
            'maximumquestions' => 4,
            // The cross-plugin counter lags by one after a resume.
            'questionsattempted' => 3,
            'progress' => $progress,
        ];

        $result = (new maximumquestionscheck())->run($context);

        $this->assertTrue($result->iserr(), 'Aborts on the resume-safe played-question count.');
        $this->assertEquals(status::ERROR_REACHED_MAXIMUM_QUESTIONS, $result->get_status());
    }

    /**
     * Below the maximum on both counters the check passes through.
     */
    public function test_does_not_abort_below_maximum(): void {
        $this->resetAfterTest();
        $progress = $this->make_progress_with_played(2);

        $context = [
            'maximumquestions' => 4,
            'questionsattempted' => 2,
            'progress' => $progress,
        ];

        $result = (new maximumquestionscheck())->run($context);

        $this->assertTrue($result->isok(), 'Two of four questions played: keep going.');
    }

    /**
     * A maximum of -1 means "no limit": never abort.
     */
    public function test_no_limit_never_aborts(): void {
        $this->resetAfterTest();
        $progress = $this->make_progress_with_played(99);

        $context = [
            'maximumquestions' => -1,
            'questionsattempted' => 99,
            'progress' => $progress,
        ];

        $result = (new maximumquestionscheck())->run($context);

        $this->assertTrue($result->isok(), 'No limit configured: the attempt is never stopped here.');
    }
}
