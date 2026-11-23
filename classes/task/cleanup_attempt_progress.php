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
 * Removes retained attempt progress once the retention period has passed.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\task;

use core\task\scheduled_task;
use local_catquiz\local\progress_retention;
use local_catquiz\teststrategy\progress;

/**
 * Applies the configured retention period to local_catquiz_progress.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_attempt_progress extends scheduled_task {
    /** @var int Rows removed per run; keeps a large instance from hitting a timeout. */
    const BATCH = 500;

    /**
     * Returns the name shown in the scheduled task settings.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('cleanupprogress', 'local_catquiz');
    }

    /**
     * Removes rows older than the retention period.
     *
     * Idempotent: a second run finds nothing left to do. Rows of attempts that are
     * still running are never touched - only progress belonging to an attempt that
     * has ended may be removed, otherwise a learner would lose a running test.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $days = progress_retention::retention_days();
        $minimal = progress_retention::should_delete();

        if ($days <= 0 && !$minimal) {
            // Unlimited retention and nothing to sweep: saying so is cheaper than
            // scanning the table.
            return;
        }

        // In the data-sparing mode every finished attempt is swept, regardless of a
        // retention period - that is what "minimal" means. The deletion cannot happen
        // during finalisation itself: the feedback path loads the progress again
        // right afterwards, so a row removed there breaks the attempt.
        $cutoff = $minimal ? time() : time() - ($days * DAYSECS);

        /* The join is over the internal id now: the attemptid of local_catquiz_progress points at
           local_catquiz_attempts.id. The external attempt id of the component is read from the CAT
           attempt, because progress::delete() works from the component's point of view. */
        $sql = "SELECT a.id AS catattemptid, a.attemptid AS componentattemptid, a.component
                  FROM {local_catquiz_progress} p
                  JOIN {local_catquiz_attempts} a ON a.id = p.attemptid
                 WHERE a.endtime IS NOT NULL
                   AND a.endtime < :cutoff";

        $removed = 0;
        // Re-audit of 22.11.2026 (#159): rows whose removal failed in this run are not selected again in it - they wait
        // for the next run, and the loop does not turn on them.
        $held = [];
        while (true) {
            $select = $sql;
            $params = ['cutoff' => $cutoff];
            if ($held) {
                [$notin, $heldparams] = $DB->get_in_or_equal(array_keys($held), SQL_PARAMS_NAMED, 'held', false);
                $select .= " AND a.id $notin";
                $params += $heldparams;
            }
            $rows = $DB->get_records_sql($select, $params, 0, self::BATCH + 1);
            if (empty($rows)) {
                break;
            }

            foreach (array_slice($rows, 0, self::BATCH) as $row) {
                if ($this->remove_one($row)) {
                    $removed++;
                } else {
                    $held[(int) $row->catattemptid] = true;
                }
            }

            if (count($rows) <= self::BATCH) {
                break;
            }
        }

        if ($held) {
            // The ids and nothing else: no answer, no person.
            mtrace('local_catquiz: kept ' . count($held) . ' attempt progress row(s) after a failed removal, retried on the next '
                . 'run: CAT attempts ' . implode(', ', array_keys($held)) . '.');
        }
        if ($removed > 0) {
            mtrace("local_catquiz: removed $removed expired attempt progress row(s).");
        }
    }

    /**
     * Keeps what the page of the result needs from the progress, and removes it - both or neither (issue #159,
     * re-audit of 22.11.2026).
     *
     * One transaction: the progress goes only once what it held for the page is stored with the attempt. A failure
     * rolls both back; the progress stays and the next run tries again. A progress that cannot be read holds nothing
     * the page could read, and is removed (with its id in the log). Stored data of the attempt that cannot be read keep
     * the progress, the only readable source left (fail-closed, re-audit of 23.11.2026).
     *
     * @param \stdClass $row catattemptid, componentattemptid, component
     * @return bool Whether the progress was removed.
     */
    protected function remove_one(\stdClass $row): bool {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $kept = $this->preserve($row);
            if ($kept === [progress_retention::STORED_UNREADABLE]) {
                // Critical-friend re-audit of 23.11.2026: fail-closed - the progress is the only readable source left.
                $transaction->allow_commit();
                mtrace("local_catquiz: the stored data of CAT attempt {$row->catattemptid} cannot be read; its progress "
                    . 'is kept.');
                return false;
            }
            if ($kept === [progress_retention::UNREADABLE]) {
                // Decision of 22.11.2026, confirmed on 23.11.2026: nothing in it can be read or restored; the period
                // of retention applies. Removed, with the id in the log.
                mtrace("local_catquiz: the progress of CAT attempt {$row->catattemptid} could not be read; removed.");
            }
            if (in_array(progress_retention::NO_RESPONSES, $kept, true)) {
                mtrace("local_catquiz: the progress of CAT attempt {$row->catattemptid} holds no responses; no answer kept "
                    . 'from it (the page completes them from the question usage).');
            }
            // Through progress::delete() so the cache is cleared as well; a row removed behind the cache's back would
            // come back on the next read.
            $this->delete_progress($row);
            $transaction->allow_commit();
            return true;
        } catch (\Throwable $e) {
            try {
                $transaction->rollback($e);
            } catch (\Throwable $rolledback) {
                // The rollback rethrows what it was given; it is reported below.
                unset($rolledback);
            }
            mtrace("local_catquiz: the progress of CAT attempt {$row->catattemptid} was kept, its removal failed ("
                . get_class($e) . ').');
            return false;
        }
    }

    /**
     * Keeps what the page needs from the progress of the row (a method of its own, so a test can make it fail).
     *
     * @param \stdClass $row catattemptid, componentattemptid, component
     * @return string[] As progress_retention::preserve_for_display().
     */
    protected function preserve(\stdClass $row): array {
        return progress_retention::preserve_for_display((int) $row->catattemptid);
    }

    /**
     * Removes the progress of one attempt (a method of its own, so a test can make it fail).
     *
     * @param \stdClass $row catattemptid, componentattemptid, component
     */
    protected function delete_progress(\stdClass $row): void {
        progress::delete((int) $row->componentattemptid, $row->component);
    }
}
