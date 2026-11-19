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

use local_catquiz\teststrategy\feedbackgenerator\debuginfo;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * The feedback generators work with the progress the selection has in hand (issue #168): no second load per
 * generator and answer, and never the progress of another attempt.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\teststrategy\feedbackgenerator
 */
final class feedbackgenerator_progress_test extends \advanced_testcase {
    /**
     * A progress of an attempt, without a database row.
     *
     * @param int $attemptid
     * @return progress
     */
    private static function progress(int $attemptid): progress {
        $progress = (new \ReflectionClass(progress::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(progress::class, 'attemptid');
        $property->setAccessible(true);
        $property->setValue($progress, $attemptid);
        return $progress;
    }

    /**
     * The progress of the selection is the one the generator uses; another attempt's never.
     */
    public function test_the_progress_of_the_selection_is_used(): void {
        $this->resetAfterTest();
        set_config('store_debug_info', 0, 'local_catquiz');
        $generator = new debuginfo(new feedbacksettings(LOCAL_CATQUIZ_STRATEGY_LOWESTSUB), new feedback_helper());
        $get = new \ReflectionMethod($generator, 'get_progress');
        $get->setAccessible(true);
        $held = new \ReflectionProperty(feedbackgenerator::class, 'progress');
        $held->setAccessible(true);

        $progress = self::progress(41);
        $generator->update_data(41, [], ['contextid' => 3, 'progress' => $progress]);
        $this->assertSame($progress, $get->invoke($generator), 'The progress in hand, not loaded again.');

        // The progress of attempt 41 handed in for attempt 43: not taken, and the one held before is dropped.
        $generator->update_data(43, [], ['contextid' => 3, 'progress' => $progress]);
        $this->assertNull($held->getValue($generator));

        // Without a progress handed in, the one of the attempt stays the generator's own (loaded on demand).
        $other = self::progress(43);
        $generator->update_data(43, [], ['contextid' => 3, 'progress' => $other]);
        $generator->update_data(43, [], ['contextid' => 3]);
        $this->assertSame($other, $held->getValue($generator));
    }
}
