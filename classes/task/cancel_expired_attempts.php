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
 * Class cancel_expired_attempts.
 *
 * @package local_catquiz
 * @author David Szkiba <david.szkiba@wunderbyte.at>
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\task;

use cache_helper;
use context_module;
use dml_exception;
use local_catquiz\catquiz;
use local_catquiz\local\attempt\expiry_policy;
use local_catquiz\local\status;
use mod_adaptivequiz\local\attempt\attempt_state;
use core\lock\lock_config;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/adaptivequiz/locallib.php");

/**
 * Closes open CAT quiz attempts whose time is over, by the effective expiry policy of their test (#178).
 *
 * The policy: expiry_policy (doc/issue-178-ablaufpolicy.md). An attempt is closed under the lock of its item
 * administration (the lock of mod_adaptivequiz the question requests hold): an attempt in a request right now - a last
 * answer, a resume - is left for the next run. Under the lock the attempt is read again; only an attempt still in
 * progress and still expired is closed, with the stop code "timelimit". The engine status is set only for an attempt
 * closed by this run: one completed by its own request in the meantime keeps its end.
 * Each decision is logged with the attempt, the activity, the limit and its source - no data of a person or an answer.
 *
 * @package local_catquiz
 * @author David Szkiba <david.szkiba@wunderbyte.at>
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cancel_expired_attempts extends \core\task\scheduled_task {
    /**
     * Allow some extra time before closing an expired attempt.
     * @var float
     */
    const BUFFER_TIME_FACTOR = expiry_policy::BUFFER_FACTOR;

    /**
     * Seconds the task waits for the lock of an attempt: none, a request holds it, the next run tries again.
     * @var int
     */
    public static int $locktimeout = 0;

    /**
     * Holds adaptivequiz records as stdClass entires.
     * @var array
     */
    private array $quizzes = [];

    /**
     * The policies of the activities with open attempts, by activity id.
     * @var expiry_policy[]
     */
    private array $policies = [];

    /**
     * Current timestamp
     * @var int
     */
    private int $currenttime;

    /**
     * Returns task name.
     * @return string
     */
    public function get_name() {
        return get_string('cancelexpiredattempts', 'local_catquiz');
    }

    /**
     * Cancel expired quiz attempts.
     *
     * @return void
     */
    public function execute() {
        global $DB, $CFG;
        mtrace("Running cancel_expired_attempts task.");
        $this->currenttime = time();
        $this->policies = [];

        $sql = <<<SQL
        SELECT aa.*
        FROM {adaptivequiz} a
        JOIN {adaptivequiz_attempt} aa
            ON a.id = aa.instance
        WHERE a.catmodel = 'catquiz'
            AND aa.attemptstate = :inprogress
        SQL;
        $params = ['inprogress' => attempt_state::IN_PROGRESS];
        if (!$records = $DB->get_records_sql($sql, $params)) {
            mtrace("No attempts are in progress. Exiting.");
            return;
        }

        $statusmessage = get_string('attemptclosedbytimelimit', 'local_catquiz');
        require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
        $decisions = [];
        foreach ($records as $record) {
            $decision = $this->decide($record, $statusmessage);
            $decisions[$decision] = ($decisions[$decision] ?? 0) + 1;
        }
        ksort($decisions);
        $summary = implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($decisions), $decisions));
        mtrace(sprintf(
            'Processed %d open attempts in %d seconds and marked %d as completed (%s)',
            count($records),
            time() - $this->currenttime,
            $decisions['closed'] ?? 0,
            $summary
        ));
    }

    /**
     * Decides about one open attempt and logs what it did unless it was kept.
     *
     * @param stdClass $record adaptivequiz_attempt record, as read at the start of the run
     * @param string $statusmessage
     * @return string closed, kept, unlimited, locked, ended, error
     */
    public function decide(stdClass $record, string $statusmessage): string {
        $policy = $this->policy_of((int) $record->instance);
        if ($policy->seconds === null) {
            return 'unlimited';
        }
        if (!$policy->expired((int) $record->timecreated, $this->currenttime)) {
            return 'kept';
        }
        $resource = 'adaptivequiz_itemadministration_' . (int) $record->instance . '_' . (int) $record->userid;
        $lock = lock_config::get_lock_factory('mod_adaptivequiz')->get_lock($resource, self::$locktimeout);
        if (!$lock) {
            $this->log($record, $policy, 'locked', 'a request holds the attempt; the next run tries again');
            return 'locked';
        }
        try {
            return $this->close_locked($record, $policy, $statusmessage);
        } catch (\Throwable $e) {
            // One attempt that cannot be closed - a broken record, a missing course module - does not stop the
            // others (mod_adaptivequiz #5).
            $this->log($record, $policy, 'error', $e->getMessage());
            return 'error';
        } finally {
            $lock->release();
        }
    }

    /**
     * Closes an attempt with the lock of its item administration held, if it is still open and expired.
     *
     * @param stdClass $record
     * @param expiry_policy $policy
     * @param string $statusmessage
     * @return string closed or ended
     */
    private function close_locked(stdClass $record, expiry_policy $policy, string $statusmessage): string {
        global $DB;
        $current = $DB->get_record('adaptivequiz_attempt', ['id' => $record->id]);
        if (!$current || $current->attemptstate !== attempt_state::IN_PROGRESS) {
            $this->log($record, $policy, 'ended', 'completed or removed by another request meanwhile');
            return 'ended';
        }
        if (!$this->complete($current, $statusmessage)) {
            $this->log($current, $policy, 'ended', 'completed by another request meanwhile');
            return 'ended';
        }
        $this->log($current, $policy, 'closed', 'time over');
        return 'closed';
    }

    /**
     * Writes one decision to the log of the task: the attempt, the activity, the limit and its source, run time and the
     * time since the last change. No user, no answer.
     *
     * @param stdClass $record
     * @param expiry_policy $policy
     * @param string $decision
     * @param string $reason
     */
    private function log(stdClass $record, expiry_policy $policy, string $decision, string $reason): void {
        mtrace(sprintf(
            '  attempt %d (activity %d): %s - %s; limit %s s (%s%s), run time %d s, last change %d s ago',
            (int) $record->id,
            (int) $record->instance,
            $decision,
            $reason,
            $policy->seconds === null ? 'none' : (string) $policy->seconds,
            $policy->source,
            $policy->note !== '' ? ', ' . $policy->note : '',
            $this->currenttime - (int) $record->timecreated,
            $this->currenttime - (int) ($record->timemodified ?? $record->timecreated)
        ));
    }

    /**
     * Closes one attempt with the completion every other end uses.
     *
     * By its id where the activity offers that (mod_adaptivequiz #5): an attempt without a question has
     * uniqueid 0, and several of those cannot be told apart by it.
     *
     * @param stdClass $record adaptivequiz_attempt record.
     * @param string $statusmessage
     * @return bool whether this call completed it (false: it was completed already)
     */
    private function complete(stdClass $record, string $statusmessage): bool {
        $quiz = $this->get_adaptivequiz($record->instance);
        $cm = get_coursemodule_from_instance('adaptivequiz', $record->instance, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        // The code of mod_adaptivequiz's stop_reason::TIMELIMIT (issue #20), written out: an older activity has
        // neither the class nor the parameter and ignores it.
        if (function_exists('adaptivequiz_complete_attempt_by_id')) {
            $completed = adaptivequiz_complete_attempt_by_id((int) $record->id, $quiz, $context, $statusmessage, 'timelimit');
        } else {
            adaptivequiz_complete_attempt(
                (int) $record->uniqueid,
                $quiz,
                $context,
                (int) $record->userid,
                (string) $record->standarderror,
                $statusmessage,
                'timelimit'
            );
            $completed = true;
        }
        if ($completed) {
            // Only the attempt this run closed: an attempt completed by its own request keeps its status (#178 AK03).
            catquiz::set_final_attempt_status($record->id, status::CLOSED_BY_TIMELIMIT);
        }
        cache_helper::purge_by_event('changesinquizattempts');
        return $completed;
    }

    /**
     * The policy of an activity, read once per run.
     *
     * @param int $adaptivequizid
     * @return expiry_policy
     */
    private function policy_of(int $adaptivequizid): expiry_policy {
        if (!isset($this->policies[$adaptivequizid])) {
            $this->policies[$adaptivequizid] = expiry_policy::for_activity($adaptivequizid);
        }
        return $this->policies[$adaptivequizid];
    }

    /**
     * Checks whether the given attempt exceeds the max attempt time of its test (expiry_policy).
     *
     * @param stdClass $record The attempt record
     * @return bool
     */
    public function exceeds_maxtime(stdClass $record): bool {
        $this->currenttime = $this->currenttime ?? time();
        return $this->policy_of((int) $record->instance)->expired((int) $record->timecreated, $this->currenttime);
    }

    /**
     * Returns an adaptivequiz with the given ID.
     *
     * @param int $id
     * @return stdClass
     * @throws dml_exception
     */
    private function get_adaptivequiz(int $id): stdClass {
        global $DB;
        if (array_key_exists($id, $this->quizzes)) {
            return $this->quizzes[$id];
        }

        $adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $id], '*', MUST_EXIST);
        $this->quizzes[$adaptivequiz->id] = $adaptivequiz;
        return $adaptivequiz;
    }
}
