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

use advanced_testcase;
use stdClass;

/**
 * Tests the authoritative, idempotent attempt finaliser (Issue #5).
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\local\attempt\attempt_finalizer
 */
final class attempt_finalizer_test extends advanced_testcase {
    /**
     * A running attempt carries no end time until it is finalised.
     */
    public function test_running_attempt_has_null_endtime(): void {
        global $DB;
        $this->resetAfterTest();

        [, $catid] = $this->create_running_attempt(3);

        $catattempt = $DB->get_record('local_catquiz_attempts', ['id' => $catid]);
        $this->assertNull($catattempt->endtime);
    }

    /**
     * Finalising stamps the end time from the authoritative timefinished and
     * takes the final number of used items from the adaptive quiz attempt.
     */
    public function test_finalize_stamps_endtime_and_used_items(): void {
        global $DB;
        $this->resetAfterTest();

        [$adaptiveattemptid, $catid] = $this->create_running_attempt(7);
        $finishedat = 1700000000;

        $performed = attempt_finalizer::finalize($adaptiveattemptid, $finishedat, 'reason');

        $this->assertTrue($performed);
        $catattempt = $DB->get_record('local_catquiz_attempts', ['id' => $catid]);
        $this->assertEquals($finishedat, (int) $catattempt->endtime);
        $this->assertEquals(7, (int) $catattempt->number_of_testitems_used);
    }

    /**
     * Teeth test: finalising twice must not change the stored result. The
     * second call is a no-op and the end time stays at the first completion
     * time. Removing the idempotency guard in attempt_finalizer::finalize()
     * makes this assertion fail (the second, later timestamp would win).
     */
    public function test_finalize_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();

        [$adaptiveattemptid, $catid] = $this->create_running_attempt(4);
        $first = 1700000000;
        $second = 1800000000;

        $this->assertTrue(attempt_finalizer::finalize($adaptiveattemptid, $first, 'first'));
        $this->assertFalse(attempt_finalizer::finalize($adaptiveattemptid, $second, 'second'));

        $catattempt = $DB->get_record('local_catquiz_attempts', ['id' => $catid]);
        $this->assertEquals(
            $first,
            (int) $catattempt->endtime,
            'The end time must remain the first completion time.'
        );
    }

    /**
     * Finalising an adaptive quiz attempt that has no local CAT row is a no-op.
     */
    public function test_finalize_without_local_attempt_is_noop(): void {
        $this->resetAfterTest();

        $this->assertFalse(attempt_finalizer::finalize(999999, 1700000000, 'x'));
    }

    /**
     * A missing/zero timefinished never results in an empty stored end time.
     */
    public function test_finalize_falls_back_when_timefinished_missing(): void {
        global $DB;
        $this->resetAfterTest();

        [$adaptiveattemptid, $catid] = $this->create_running_attempt(2);

        $this->assertTrue(attempt_finalizer::finalize($adaptiveattemptid, 0, ''));

        $catattempt = $DB->get_record('local_catquiz_attempts', ['id' => $catid]);
        $this->assertNotEmpty($catattempt->endtime);
    }

    /**
     * Create a running adaptive quiz attempt together with its local CAT row
     * (endtime = null), returning [adaptivequiz_attempt.id, local attempt id].
     *
     * @param int $questionsattempted The number of questions attempted so far.
     * @return array{0: int, 1: int}
     */
    private function create_running_attempt(int $questionsattempted): array {
        global $DB;

        $now = time();

        $adaptiverecord = new stdClass();
        $adaptiverecord->instance = 1;
        $adaptiverecord->userid = 2;
        $adaptiverecord->uniqueid = 900 + $questionsattempted;
        $adaptiverecord->attemptstate = 'inprogress';
        $adaptiverecord->attemptstopcriteria = '';
        $adaptiverecord->questionsattempted = $questionsattempted;
        $adaptiverecord->difficultysum = 0;
        $adaptiverecord->standarderror = 1;
        $adaptiverecord->measure = 0;
        $adaptiverecord->timefinished = null;
        $adaptiverecord->timecreated = $now;
        $adaptiverecord->timemodified = $now;
        $adaptiveattemptid = $DB->insert_record('adaptivequiz_attempt', $adaptiverecord);

        $catrecord = new stdClass();
        $catrecord->userid = 2;
        $catrecord->attemptid = $adaptiveattemptid;
        $catrecord->component = 'mod_adaptivequiz';
        $catrecord->status = 0;
        $catrecord->number_of_testitems_used = 0;
        $catrecord->endtime = null;
        $catrecord->timecreated = $now;
        $catrecord->timemodified = $now;
        $catid = $DB->insert_record('local_catquiz_attempts', $catrecord);

        return [(int) $adaptiveattemptid, (int) $catid];
    }
}
