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

use local_catquiz\local\progress_retention;

/**
 * The history of a finished attempt, completed for its display from what was stored and what the question usage holds
 * (issue #159, re-audit of 22.11.2026).
 *
 * Old data are old data: the history of an attempt finished before this version can lack rows. Up to issue #168 each
 * answer appended one row - the last response - to the stored data, and a row that was missed was never added later:
 * the answer a reload gave as wrong (catquiz::mark_last_question_failed() redirects without the feedback), an error
 * after the progress was saved, a request that ended between the two writes. The progress, the only other source,
 * is removed by the retention.
 *
 * What the display shows, in the order of the slots:
 *  - the rows stored with the attempt, as they are;
 *  - rows the retention copied from the progress before it was removed (retention_history) for answers the stored rows
 *    lack - while the progress is still there, the same rows from it, so that the page is the same before and after;
 *    with their scale, difficulty and information as the progress had them, without an ability (the progress keeps
 *    the last estimate, not the one after each answer);
 *  - for answers neither holds, a row from the question usage (an answered question of an attempt of mod_adaptivequiz)
 *    and the item of the attempt's CAT context: question, label, scale, difficulty, the given answer and its fraction.
 *    No ability and no information: they were estimated when the attempt ran, and are not estimated again now - they
 *    are shown as not recorded. Whether the item was a pilot item then depends on the number of its attempts then; a
 *    row is shown as productive only for an item whose parameters were confirmed and usable, else as a pilot item
 *    (to teachers only).
 *
 * Read only: nothing is written. A complete history - every attempt finished with this version - is returned as it is,
 * without reading the question usage.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class history_completion {
    /** @var string Where a completed row came from: copied from the progress by the retention. */
    const SOURCE_RETENTION = 'retention';

    /** @var string Where a completed row came from: the question usage and the item. */
    const SOURCE_QUESTIONUSAGE = 'questionusage';

    /**
     * The rows of the history of a finished attempt and the series of its other scales, completed.
     *
     * @param array $rows The stored rows (graphicalsummary_data).
     * @param array $otherscales The stored series of the other scales, aligned with the end of the rows.
     * @param int $catattemptid Id in local_catquiz_attempts.
     * @return array [rows, otherscales]
     */
    public static function complete(array $rows, array $otherscales, int $catattemptid): array {
        global $DB;
        $rows = array_values($rows);
        $attempt = $DB->get_record(
            'local_catquiz_attempts',
            ['id' => $catattemptid],
            'id, attemptid, component, contextid, scaleid, endtime, json, number_of_testitems_used'
        );
        if (!$attempt || empty($attempt->endtime)) {
            return [$rows, $otherscales];
        }
        // A history with a row for every answer the attempt counted is complete: nothing is read.
        if (count($rows) >= (int) $attempt->number_of_testitems_used) {
            return [$rows, $otherscales];
        }
        // Each row keeps the index it has in the stored rows, so that the series of the other scales stay with it.
        $entries = [];
        $have = [];
        foreach ($rows as $index => $row) {
            $entries[] = ['row' => $row, 'index' => $index];
            if (isset($row['id'])) {
                $have[(string) $row['id']] = true;
            }
        }

        $stored = json_decode((string) $attempt->json, true);
        foreach ((array) ($stored['retention_history'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['id']) || isset($have[(string) $row['id']])) {
                continue;
            }
            $entries[] = ['row' => $row + ['source' => self::SOURCE_RETENTION], 'index' => null];
            $have[(string) $row['id']] = true;
        }

        // While the progress is still there: the rows the retention would copy from it, so that the page is the same
        // before and after the progress is removed.
        $progressjson = $DB->get_field('local_catquiz_progress', 'json', ['attemptid' => $catattemptid]);
        $progress = $progressjson ? json_decode((string) $progressjson, true) : null;
        if (is_array($progress)) {
            $root = (string) ($stored['catscaleid'] ?? $attempt->scaleid);
            foreach (progress_retention::rows_from_progress($progress, $root, $have) as $row) {
                $entries[] = ['row' => $row + ['source' => self::SOURCE_RETENTION], 'index' => null];
                $have[(string) $row['id']] = true;
            }
        }

        $slots = self::answered_slots($attempt, count($entries));
        if ($slots !== null) {
            $missing = [];
            foreach ($slots as $slot => $answer) {
                if (!isset($have[(string) $answer['questionid']])) {
                    $missing[$slot] = $answer;
                    $have[(string) $answer['questionid']] = true;
                }
            }
            // The items of all missing answers in one query (issue #159: no query per row).
            $items = self::items(array_column($missing, 'questionid'), (int) $attempt->contextid);
            foreach ($missing as $answer) {
                $entries[] = ['row' => self::row_from_usage($answer, $items[$answer['questionid']] ?? null), 'index' => null];
            }
            // The order of the slots. A question given in more than one slot has its row at the first, as the history
            // keeps the first answer to a question; a row whose question is not in the usage keeps its place.
            $slotof = [];
            foreach ($slots as $slot => $answer) {
                $slotof[(string) $answer['questionid']] = $slotof[(string) $answer['questionid']] ?? $slot;
            }
            $position = 0;
            foreach ($entries as $i => $entry) {
                $id = (string) ($entry['row']['id'] ?? '');
                $position = isset($slotof[$id]) ? $slotof[$id] : $position;
                $entries[$i]['order'] = [$position, $i];
            }
            usort($entries, fn($a, $b) => $a['order'] <=> $b['order']);
        } else if (count($entries) > count($rows)) {
            // Rows copied by the retention, without reading the usage: by their slot, when every row has one.
            $numbered = array_filter(
                $entries,
                fn($entry) => isset($entry['row']['slot']) && is_numeric($entry['row']['slot'])
            );
            if (count($numbered) === count($entries)) {
                foreach ($entries as $i => $entry) {
                    $entries[$i]['order'] = [(int) $entry['row']['slot'], $i];
                }
                usort($entries, fn($a, $b) => $a['order'] <=> $b['order']);
            }
        }

        if (count($entries) === count($rows)) {
            return [$rows, $otherscales];
        }
        // What the table shows of a row it did not have stored: the name of its scale and the title of its question -
        // for all rows in two queries.
        $scaleids = [];
        $questionids = [];
        foreach ($entries as $entry) {
            if ($entry['index'] !== null) {
                continue;
            }
            if (!isset($entry['row']['questionscale_name']) && !empty($entry['row']['questionscale'])) {
                $scaleids[] = (int) $entry['row']['questionscale'];
            }
            if (!isset($entry['row']['questiontitle']) && !empty($entry['row']['id'])) {
                $questionids[] = (int) $entry['row']['id'];
            }
        }
        $scalenames = self::names('local_catquiz_catscales', $scaleids);
        $questionnames = self::names('question', $questionids);
        foreach ($entries as $i => $entry) {
            if ($entry['index'] !== null) {
                continue;
            }
            $row = $entry['row'];
            if (!isset($row['questionscale_name']) && !empty($row['questionscale'])) {
                $row['questionscale_name'] = $scalenames[(int) $row['questionscale']] ?? '';
            }
            if (!isset($row['questiontitle']) && !empty($row['id'])) {
                $row['questiontitle'] = $questionnames[(int) $row['id']] ?? '';
            }
            $row['personability_after'] = $row['personability_after'] ?? null;
            $entries[$i]['row'] = $row;
        }
        $completed = array_map(fn($entry) => $entry['row'], $entries);
        $series = [];
        foreach ($otherscales as $scaleid => $values) {
            $values = array_values((array) $values);
            $padded = array_pad($values, -count($rows), null);
            $series[$scaleid] = array_map(
                fn($entry) => $entry['index'] === null ? null : ($padded[$entry['index']] ?? null),
                $entries
            );
        }
        return [$completed, $series];
    }

    /**
     * The answered questions of the attempt's question usage, by slot - null when there is nothing to complete.
     *
     * The usage is read only when it can hold more answers than the history has rows: the number of questions the
     * activity counted for the attempt says so, without reading it. One query (issue #159, performance re-audit of
     * 22.11.2026): the question attempts with their last step and the question each slot used - the version that was
     * shown -, without loading the usage and the definition of every question.
     *
     * @param \stdClass $attempt Row of local_catquiz_attempts.
     * @param int $rows Rows the history has.
     * @return array|null Slot => [slot, questionid, name, idnumber, fraction, responsesummary, questionattemptid].
     */
    private static function answered_slots(\stdClass $attempt, int $rows): ?array {
        global $CFG, $DB;
        // The CAT attempt names its component by the module name (save_attempt_to_db(): modulename).
        if (!in_array($attempt->component, ['adaptivequiz', 'mod_adaptivequiz'], true)) {
            return null;
        }
        $activity = $DB->get_record(
            'adaptivequiz_attempt',
            ['id' => (int) $attempt->attemptid],
            'id, uniqueid, questionsattempted'
        );
        if (!$activity || empty($activity->uniqueid) || (int) $activity->questionsattempted <= $rows) {
            return null;
        }
        require_once($CFG->dirroot . '/question/engine/lib.php');
        // A finished question attempt has a fraction in its last step, and a state of the finished ones (gradedright,
        // gradedwrong, gradedpartial, mangr*): the same condition as question_attempt::get_fraction() and
        // question_state::is_finished().
        $records = $DB->get_recordset_sql(
            "SELECT qa.id, qa.slot, qa.questionid, qa.responsesummary, q.name, qbe.idnumber, qas.fraction, qas.state
               FROM {question_attempts} qa
               JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
               JOIN {question} q ON q.id = qa.questionid
          LEFT JOIN {question_versions} qv ON qv.questionid = q.id
          LEFT JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE qa.questionusageid = :usageid
                AND qas.sequencenumber = (SELECT MAX(qasm.sequencenumber)
                                            FROM {question_attempt_steps} qasm
                                           WHERE qasm.questionattemptid = qa.id)
           ORDER BY qa.slot",
            ['usageid' => (int) $activity->uniqueid]
        );
        $slots = [];
        foreach ($records as $record) {
            if ($record->fraction === null || !\question_state::get((string) $record->state)->is_finished()) {
                continue;
            }
            $slots[(int) $record->slot] = [
                'slot' => (int) $record->slot,
                'questionid' => (int) $record->questionid,
                'name' => (string) $record->name,
                'idnumber' => (string) ($record->idnumber ?? ''),
                'fraction' => (float) $record->fraction,
                'responsesummary' => $record->responsesummary,
                'questionattemptid' => (int) $record->id,
            ];
        }
        $records->close();
        return $slots;
    }

    /**
     * The items of the given questions in the CAT context, with their active parameters - in one query, and one more
     * for questions whose item is of another version of the same question.
     *
     * Known limit (decision 12 of 23.11.2026): the active parameters and the scale of today. Whether they held when the
     * attempt was played cannot be shown - items carry no time stamp, parameter rows are changed in place. In the test
     * context they do not change. Rows from the progress or the retention carry the values of the attempt itself.
     *
     * @param int[] $questionids
     * @param int $contextid
     * @return array Question id => record (catscaleid, difficulty, status, usable, scalename).
     */
    private static function items(array $questionids, int $contextid): array {
        global $DB;
        $questionids = array_values(array_unique(array_map('intval', $questionids)));
        if (!$questionids) {
            return [];
        }
        $select = "SELECT %s AS questionid, i.catscaleid, p.difficulty, p.status, p.usable, s.name AS scalename
                     FROM {local_catquiz_items} i
                LEFT JOIN {local_catquiz_itemparams} p ON p.id = i.activeparamid
                LEFT JOIN {local_catquiz_catscales} s ON s.id = i.catscaleid";
        [$insql, $params] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'q');
        $items = [];
        $records = $DB->get_recordset_sql(
            sprintf($select, 'i.componentid') . "
              WHERE i.componentname = 'question' AND i.contextid = :contextid AND i.componentid $insql
           ORDER BY i.id",
            $params + ['contextid' => $contextid]
        );
        foreach ($records as $record) {
            $items[(int) $record->questionid] = $items[(int) $record->questionid] ?? $record;
        }
        $records->close();
        $other = array_values(array_diff($questionids, array_keys($items)));
        if ($other) {
            // The question the slot used is a version whose item points to another version of the same entry.
            [$insql, $params] = $DB->get_in_or_equal($other, SQL_PARAMS_NAMED, 'v');
            $records = $DB->get_recordset_sql(
                sprintf($select, 'qv.questionid') . "
                  JOIN {question_versions} qvi ON qvi.questionid = i.componentid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qvi.questionbankentryid
                 WHERE i.componentname = 'question' AND i.contextid = :contextid AND qv.questionid $insql
              ORDER BY qvi.version DESC, i.id",
                $params + ['contextid' => $contextid]
            );
            foreach ($records as $record) {
                $items[(int) $record->questionid] = $items[(int) $record->questionid] ?? $record;
            }
            $records->close();
        }
        return $items;
    }

    /**
     * The names of the given ids of a table with a name column, in one query.
     *
     * @param string $table
     * @param int[] $ids
     * @return array Id => name.
     */
    private static function names(string $table, array $ids): array {
        global $DB;
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids);
        return array_map('strval', $DB->get_records_select_menu($table, "id $insql", $params, '', 'id, name'));
    }

    /**
     * The row of an answer from the question usage and the item of the CAT context.
     *
     * @param array $answer As answered_slots() gives it.
     * @param \stdClass|null $item As items() gives it, null when the question has no item in the context.
     * @return array
     */
    private static function row_from_usage(array $answer, ?\stdClass $item): array {
        global $CFG;
        require_once($CFG->dirroot . '/local/catquiz/lib.php');
        $productive = $item && $item->difficulty !== null && is_numeric($item->difficulty)
            && (int) $item->status >= LOCAL_CATQUIZ_STATUS_UPDATED_MANUALLY && (int) $item->usable === 1;
        $scaleid = $item ? (int) $item->catscaleid : 0;
        return [
            'id' => $answer['questionid'],
            'questionname' => $answer['idnumber'] !== '' ? $answer['idnumber'] : $answer['name'],
            'questiontitle' => $answer['name'],
            'lastresponse' => round($answer['fraction'], 2),
            'pilot' => !$productive,
            'slot' => $answer['slot'],
            'questionattemptid' => $answer['questionattemptid'],
            'responsesummary' => $answer['responsesummary'],
            'difficulty' => $item && is_numeric($item->difficulty) ? (float) $item->difficulty : null,
            'questionscale' => $scaleid ?: null,
            'questionscale_name' => $item ? (string) $item->scalename : '',
            'fisherinformation' => null,
            'personability_after' => null,
            'source' => self::SOURCE_QUESTIONUSAGE,
        ];
    }
}
