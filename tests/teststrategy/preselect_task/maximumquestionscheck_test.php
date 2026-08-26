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
 * Regression test for the maximum questions stop condition.
 *
 * The check used to rely solely on the `questionsattempted` counter kept on the
 * adaptivequiz attempt record. That counter can drift when an attempt is resumed
 * (the pending item is re-rendered without being counted again), and the drift
 * let the test administer one item MORE than configured - the Behat scenarios
 * "Resuming an interrupted attempt still finalises exactly once" and "Reloading
 * mid-attempt still yields exactly the configured length" both failed with a
 * fifth question at a configured maximum of four.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\teststrategy\preselect_task\maximumquestionscheck
 */

namespace local_catquiz\teststrategy\preselect_task;

use advanced_testcase;
use local_catquiz\local\status;
use stdClass;

/**
 * Guards the maximum questions stop condition.
 *
 * @package    local_catquiz
 */
final class maximumquestionscheck_test extends advanced_testcase {
    /**
     * Builds a duck-typed progress stub that reports a number of played questions.
     *
     * @param int $played Number of productive (non pilot) questions played.
     * @return object
     */
    private function progress_stub(int $played) {
        $questions = [];
        for ($i = 0; $i < $played; $i++) {
            $q = new stdClass();
            $q->is_pilot = false;
            $questions[] = $q;
        }
        return new class ($questions) {
            /** @var array */
            private array $questions;
            /**
             * Constructor.
             * @param array $questions
             */
            public function __construct(array $questions) {
                $this->questions = $questions;
            }
            /**
             * Returns itself, pilots are already excluded in the stub.
             * @return object
             */
            public function without_pilots() {
                return $this;
            }
            /**
             * Returns the played questions.
             * @return array
             */
            public function get_playedquestions() {
                return $this->questions;
            }
        };
    }

    /**
     * The attempt must stop as soon as the configured maximum is reached.
     *
     * @return void
     */
    public function test_stops_when_maximum_reached(): void {
        $this->resetAfterTest(true);

        $task = new maximumquestionscheck();
        $context = [
            'maximumquestions' => 4,
            'questionsattempted' => 4,
            'progress' => $this->progress_stub(4),
        ];
        $result = $task->run($context);
        $this->assertTrue($result->iserr());
        $this->assertEquals(status::ERROR_REACHED_MAXIMUM_QUESTIONS, $result->get_status());
    }

    /**
     * The stop must also trigger when the external counter lags behind the real
     * progress - exactly what happens after a resume.
     *
     * @return void
     */
    public function test_stops_even_when_external_counter_lags_behind(): void {
        $this->resetAfterTest(true);

        $task = new maximumquestionscheck();
        $context = [
            'maximumquestions' => 4,
            // Counter drifted by one during the resume ...
            'questionsattempted' => 3,
            // ... but four questions have really been played.
            'progress' => $this->progress_stub(4),
        ];
        $result = $task->run($context);
        $this->assertTrue(
            $result->iserr(),
            'A drifting questionsattempted counter must not allow a fifth question.'
        );
    }

    /**
     * Below the maximum the attempt continues.
     *
     * @return void
     */
    public function test_continues_below_maximum(): void {
        $this->resetAfterTest(true);

        $task = new maximumquestionscheck();
        $context = [
            'maximumquestions' => 4,
            'questionsattempted' => 3,
            'progress' => $this->progress_stub(3),
        ];
        $this->assertFalse($task->run($context)->iserr());
    }

    /**
     * A maximum of -1 means "no limit", and a missing progress falls back to the
     * external counter.
     *
     * @return void
     */
    public function test_unlimited_and_fallback(): void {
        $this->resetAfterTest(true);

        $task = new maximumquestionscheck();

        $unlimited = ['maximumquestions' => -1, 'questionsattempted' => 99];
        $this->assertFalse($task->run($unlimited)->iserr());

        $fallback = ['maximumquestions' => 4, 'questionsattempted' => 4];
        $this->assertTrue($task->run($fallback)->iserr());
    }
}
