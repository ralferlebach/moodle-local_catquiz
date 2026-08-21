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

use local_catquiz\catquiz;
use local_catquiz\local\result\attempt_result_validator;
use local_catquiz\local\result\attemptscale_repository;

/**
 * Authoritative, idempotent finaliser for a CATquiz attempt (Issue #5).
 *
 * This is the single place where a CATquiz attempt is turned from "running"
 * into "finished". It is invoked from the authoritative status change in
 * mod_adaptivequiz (adaptivequiz_complete_attempt() via the
 * post_complete_attempt_callback catmodel hook), so finalisation no longer
 * depends on the attempt-finished page being reached. It is safe to call more
 * than once: a second call on an already-finalised attempt is a no-op.
 *
 * The end time is taken from the adaptive quiz attempt's immutable
 * timefinished (passed in as $finishedat), never from a session cache and
 * never from time() during a running attempt.
 *
 * Issues #7 (central result validation) and #9 (per-attempt scale results and
 * carry-over) hook into the marked extension point below; Issue #8 will set
 * resultstatus/resultvalid on the adaptivequiz_attempt from here.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_finalizer {
    /**
     * Finalise the CATquiz attempt that belongs to the given adaptive quiz attempt.
     *
     * @param int $adaptiveattemptid The mod_adaptivequiz attempt id (adaptivequiz_attempt.id).
     * @param int $finishedat Authoritative completion timestamp (adaptivequiz_attempt.timefinished).
     * @param string $stopreason The reason the attempt was stopped (for later result status).
     * @return bool True if this call performed the finalisation, false if it was a no-op.
     */
    public static function finalize(int $adaptiveattemptid, int $finishedat, string $stopreason = ''): bool {
        global $DB;

        // Locate the local CAT attempt row created during the attempt. If none
        // exists (for example an attempt that never produced a CAT row), there
        // is nothing to finalise.
        $catattempt = $DB->get_record('local_catquiz_attempts', ['attemptid' => $adaptiveattemptid]);
        if (!$catattempt) {
            return false;
        }

        // Idempotency guard: a finalised attempt carries a non-empty endtime.
        // Re-running finalisation must not change the stored result. Removing
        // this guard is what the teeth test detects.
        if (!empty($catattempt->endtime)) {
            return false;
        }

        // Defensive fallback: finalisation must never stamp an empty end time.
        // In the normal flow $finishedat is the immutable timefinished (> 0).
        if ($finishedat <= 0) {
            $finishedat = time();
        }

        $transaction = $DB->start_delegated_transaction();

        // The authoritative end time is stamped exactly once here; timecreated
        // is left untouched (it is only ever set on INSERT).
        $catattempt->endtime = $finishedat;
        // Take the final number of used test items from the adaptive quiz
        // attempt, which is authoritative at completion.
        $questionsattempted = $DB->get_field(
            'adaptivequiz_attempt',
            'questionsattempted',
            ['id' => $adaptiveattemptid]
        );
        if ($questionsattempted !== false) {
            $catattempt->number_of_testitems_used = (int) $questionsattempted;
        }
        $catattempt->timemodified = time();
        $DB->update_record('local_catquiz_attempts', $catattempt);

        // Issue #7 + #9: validate the attempt result centrally and persist one
        // history row per successfully tested scale. This runs exactly once (the
        // idempotency guard above prevents re-finalisation) and inside the same
        // transaction as the end-time stamp, so the attempt becomes
        // Completed -> Validated -> Persisted atomically.
        $result = attempt_result_validator::validate($adaptiveattemptid);
        $contextid = ($catattempt->contextid !== null) ? (int) $catattempt->contextid : null;
        attemptscale_repository::save_attempt_result(
            (int) $catattempt->id,
            (int) $catattempt->userid,
            $contextid,
            $result
        );

        // Issue #9: refresh the personparams "latest known state" snapshot only
        // now, and only for valid scales measured in this attempt - never from a
        // mere carry-over or an intermediate estimate.
        if ($contextid !== null) {
            foreach ($result->get_scale_results() as $scaleresult) {
                if ($scaleresult->valid && $scaleresult->score !== null) {
                    catquiz::update_person_param(
                        (int) $catattempt->userid,
                        $contextid,
                        $scaleresult->scaleid,
                        (float) $scaleresult->score
                    );
                }
            }
        }

        // Issue #8 will additionally set resultstatus/resultvalid on the
        // adaptivequiz_attempt from $result and $stopreason.
        unset($stopreason);

        $transaction->allow_commit();

        return true;
    }
}
