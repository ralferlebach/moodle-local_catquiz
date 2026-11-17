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
use local_catquiz\local\status;
use local_catquiz\teststrategy\strategy\relevantscales;

/**
 * Why the selection ends without a question: a typed end, never a general error (issue #172).
 *
 * The decision behind the strategy's last step, with the counts the progress reports. Pilot questions are not in
 * them: get_num_answered_productive_questions() leaves them out.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\teststrategy\strategy
 */
final class end_without_question_test extends advanced_testcase {
    /**
     * Minimum, productive answers, active scales, dropped scales => expected status.
     *
     * @return array
     */
    public static function cases(): array {
        return [
            'every scale done after the minimum (RELSUBS after 17)' => [12, 17, [], [3, 4, 5], status::STOP_SCALES_COMPLETE],
            'every scale done after the minimum (LOWESTSUB after 16)' => [12, 16, [], [3], status::STOP_SCALES_COMPLETE],
            'every scale done before the minimum' => [12, 11, [], [3, 4], status::ERROR_NO_REMAINING_QUESTIONS_BEFORE_MINIMUM],
            'active scale without an eligible question' => [12, 17, [7], [3], status::ERROR_NO_REMAINING_QUESTIONS],
            'pool empty, nothing dropped' => [12, 17, [], [], status::ERROR_NO_REMAINING_QUESTIONS],
            'pool empty before the minimum' => [12, 4, [7], [], status::ERROR_NO_REMAINING_QUESTIONS_BEFORE_MINIMUM],
            'no minimum, every scale done' => [0, 2, [], [1], status::STOP_SCALES_COMPLETE],
        ];
    }

    /**
     * The end for each situation.
     *
     * @param int $minimum
     * @param int $answered
     * @param array $active
     * @param array $dropped
     * @param string $expected
     * @dataProvider cases
     */
    public function test_the_end_is_typed(int $minimum, int $answered, array $active, array $dropped, string $expected): void {
        $progress = $this->createStub(progress::class);
        $progress->method('get_num_answered_productive_questions')->willReturn($answered);
        $progress->method('get_active_scales')->willReturn($active);
        $progress->method('get_dropped_scales')->willReturn($dropped);

        $strategy = (new \ReflectionClass(relevantscales::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(strategy::class, 'progress'))->setValue($strategy, $progress);
        (new \ReflectionProperty(strategy::class, 'context'))->setValue($strategy, ['minimumquestions' => $minimum]);

        $status = (new \ReflectionMethod(strategy::class, 'end_without_question'))->invoke($strategy);

        $this->assertSame($expected, $status);
        $this->assertNotSame(status::ERROR_GENERAL, $status);
        $this->assertGreaterThan(0, status::to_int($status), 'The end has a stored number of its own.');
    }
}
