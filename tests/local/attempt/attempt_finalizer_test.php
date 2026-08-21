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
use local_catquiz\teststrategy\progress;
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
     * Issue #9: finalising an attempt whose stored data carries per-scale
     * abilities persists one attemptscale row per measured scale and refreshes
     * the personparams snapshot for the valid primary scale.
     */
    public function test_finalize_persists_scale_results(): void {
        global $DB;
        $this->resetAfterTest();

        $now = time();
        $json = json_encode([
            'personabilities_abilities' => [
                5 => ['value' => 0.4, 'toreport' => true],
                6 => ['value' => 0.2, 'toreport' => true, 'excluded' => true,
                    'error' => ['nminscale' => ['nminscaledefined' => 5, 'nscalecurrent' => 1]]],
            ],
            'se' => [5 => 0.3, 6 => 0.9],
        ]);
        $adaptiveattemptid = $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => 1, 'userid' => 2, 'uniqueid' => 7777, 'attemptstate' => 'complete',
            'attemptstopcriteria' => '', 'questionsattempted' => 6, 'difficultysum' => 0,
            'standarderror' => 0.3, 'measure' => 0, 'timefinished' => null, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $catid = $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => 2, 'scaleid' => 5, 'contextid' => 9, 'attemptid' => $adaptiveattemptid,
            'component' => 'mod_adaptivequiz', 'status' => 0, 'number_of_testitems_used' => 6,
            'endtime' => null, 'json' => $json, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        // Without progress data N per scale is unknown, so measuredincurrentattempt
        // defaults to true and both reported scales are historised.
        $this->assertTrue(attempt_finalizer::finalize($adaptiveattemptid, $now + 5, 'reason'));

        $rows = $DB->get_records('local_catquiz_attemptscale', ['catattemptid' => $catid]);
        $byscale = [];
        foreach ($rows as $row) {
            $byscale[(int) $row->catscaleid] = $row;
        }
        $this->assertArrayHasKey(5, $byscale);
        $this->assertArrayHasKey(6, $byscale);
        $this->assertEquals(1, $byscale[5]->isvalid);
        $this->assertEquals(1, $byscale[5]->isprimary);
        $this->assertEquals(0, $byscale[6]->isvalid);

        // The personparams snapshot is refreshed for the valid primary scale.
        $snapshot = $DB->get_record(
            'local_catquiz_personparams',
            ['userid' => 2, 'contextid' => 9, 'catscaleid' => 5]
        );
        $this->assertNotFalse($snapshot);
        $this->assertEquals(0.4, (float) $snapshot->ability);

        // Issue #8: the validity verdict is exposed on the adaptivequiz attempt.
        $updated = $DB->get_record('adaptivequiz_attempt', ['id' => $adaptiveattemptid], 'resultvalid, resultstatus');
        $this->assertEquals(1, (int) $updated->resultvalid);
        $this->assertEquals('valid', $updated->resultstatus);
    }

    /**
     * Issue #9 (Phase 1): finalising an attempt in which a scale was NOT validly
     * measured resets that scale's personparams snapshot to the last valid value
     * from the history, so an intermediate/invalid estimate does not survive as
     * the cross-attempt state. When no valid history exists, the value is left
     * untouched.
     */
    public function test_finalize_reconciles_invalid_scale_to_last_valid(): void {
        global $DB;
        $this->resetAfterTest();

        $userid = 2;
        $contextid = 9;
        $now = time();

        // Prior valid history for scale 5 (score 0.7) from an earlier attempt,
        // and an intermediate estimate currently sitting in personparams (0.15,
        // as a during-attempt task would have written).
        $DB->insert_record('local_catquiz_attemptscale', (object) [
            'catattemptid' => 111, 'userid' => $userid, 'contextid' => $contextid, 'catscaleid' => 5,
            'score' => 0.7, 'standarderror' => 0.2, 'n' => 8, 'fraction' => 0.6,
            'isprimary' => 1, 'isvalid' => 1, 'resultsource' => 'current', 'validationstatus' => '',
            'timecreated' => $now - 1000,
        ]);
        $DB->insert_record('local_catquiz_personparams', (object) [
            'userid' => $userid, 'contextid' => $contextid, 'catscaleid' => 5, 'ability' => 0.15,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        // Current attempt: scale 5 is measured but INVALID (N below minimum).
        $json = json_encode([
            'personabilities_abilities' => [
                5 => ['value' => 0.15, 'toreport' => true, 'excluded' => true,
                    'error' => ['nminscale' => ['nminscaledefined' => 5, 'nscalecurrent' => 1]]],
            ],
            'se' => [5 => 0.9],
        ]);
        $adaptiveattemptid = $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => 1, 'userid' => $userid, 'uniqueid' => 8888, 'attemptstate' => 'complete',
            'attemptstopcriteria' => '', 'questionsattempted' => 1, 'difficultysum' => 0,
            'standarderror' => 0.9, 'measure' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => $userid, 'scaleid' => 5, 'contextid' => $contextid, 'attemptid' => $adaptiveattemptid,
            'component' => 'mod_adaptivequiz', 'status' => 0, 'endtime' => null, 'json' => $json,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $this->assertTrue(attempt_finalizer::finalize($adaptiveattemptid, $now + 5, 'reason'));

        // The invalid scale's snapshot is reset to the last valid value (0.7),
        // not left at the intermediate 0.15.
        $snapshot = $DB->get_record(
            'local_catquiz_personparams',
            ['userid' => $userid, 'contextid' => $contextid, 'catscaleid' => 5]
        );
        $this->assertEquals(0.7, (float) $snapshot->ability);
    }

    /**
     * Issue #9 (Phase 2): when a scale has no valid history but a pre-attempt
     * value was captured, an invalid attempt restores that exact pre-attempt
     * value (not the intermediate estimate, and not a default).
     */
    public function test_finalize_restores_exact_preattempt_value(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $userid = (int) $user->id;
        $contextid = 9;
        $now = time();

        // Intermediate estimate currently in personparams (a during-attempt write).
        $DB->insert_record('local_catquiz_personparams', (object) [
            'userid' => $userid, 'contextid' => $contextid, 'catscaleid' => 5, 'ability' => 0.15,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $json = json_encode([
            'personabilities_abilities' => [
                5 => ['value' => 0.15, 'toreport' => true, 'excluded' => true,
                    'error' => ['nminscale' => ['nminscaledefined' => 5, 'nscalecurrent' => 1]]],
            ],
            'se' => [5 => 0.9],
        ]);
        $adaptiveattemptid = $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => 1, 'userid' => $userid, 'uniqueid' => 9911, 'attemptstate' => 'complete',
            'attemptstopcriteria' => '', 'questionsattempted' => 1, 'difficultysum' => 0,
            'standarderror' => 0.9, 'measure' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => $userid, 'scaleid' => 5, 'contextid' => $contextid, 'attemptid' => $adaptiveattemptid,
            'component' => 'mod_adaptivequiz', 'status' => 0, 'endtime' => null, 'json' => $json,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        // Capture the pre-attempt value (0.55) on the attempt's progress. No
        // valid attempt-scale history exists for the scale.
        $progress = progress::load($adaptiveattemptid, 'mod_adaptivequiz', $contextid, (object) []);
        $progress->capture_preattempt_abilities([5 => 0.55]);
        $progress->save();

        $this->assertTrue(attempt_finalizer::finalize($adaptiveattemptid, $now + 5, 'reason'));

        $snapshot = $DB->get_record(
            'local_catquiz_personparams',
            ['userid' => $userid, 'contextid' => $contextid, 'catscaleid' => 5]
        );
        $this->assertEquals(0.55, (float) $snapshot->ability);
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
