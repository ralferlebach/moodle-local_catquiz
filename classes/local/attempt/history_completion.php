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
use question_engine;

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
            foreach ($slots as $slot => $answer) {
                if (isset($have[(string) $answer['questionid']])) {
                    continue;
                }
                $entries[] = ['row' => self::row_from_usage($answer, (int) $attempt->contextid), 'index' => null];
                $have[(string) $answer['questionid']] = true;
            }
            // The order of the slots; a row whose question is not in the usage keeps its place after the one before.
            $slotof = [];
            foreach ($slots as $slot => $answer) {
                $slotof[(string) $answer['questionid']] = $slot;
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
        // What the table shows of a row it did not have stored: the name of its scale and the title of its question.
        foreach ($entries as $i => $entry) {
            if ($entry['index'] !== null) {
                continue;
            }
            $row = $entry['row'];
            if (!isset($row['questionscale_name']) && !empty($row['questionscale'])) {
                $row['questionscale_name'] = (string) $DB->get_field(
                    'local_catquiz_catscales',
                    'name',
                    ['id' => (int) $row['questionscale']]
                );
            }
            if (!isset($row['questiontitle']) && !empty($row['id'])) {
                $row['questiontitle'] = (string) $DB->get_field('question', 'name', ['id' => (int) $row['id']]);
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
     * activity counted for the attempt says so, without loading it.
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
        require_once($CFG->libdir . '/questionlib.php');
        try {
            $quba = question_engine::load_questions_usage_by_activity((int) $activity->uniqueid);
        } catch (\Throwable $e) {
            return null;
        }
        $slots = [];
        foreach ($quba->get_slots() as $slot) {
            $qa = $quba->get_question_attempt($slot);
            $fraction = $qa->get_fraction();
            if ($fraction === null || !$qa->get_state()->is_finished()) {
                continue;
            }
            $question = $qa->get_question(false);
            $slots[(int) $slot] = [
                'slot' => (int) $slot,
                'questionid' => (int) $qa->get_question_id(),
                'name' => (string) ($question->name ?? ''),
                'idnumber' => (string) ($question->idnumber ?? ''),
                'fraction' => (float) $fraction,
                'responsesummary' => $qa->get_response_summary(),
                'questionattemptid' => (int) $qa->get_database_id(),
            ];
        }
        ksort($slots);
        return $slots;
    }

    /**
     * The row of an answer from the question usage and the item of the CAT context.
     *
     * @param array $answer As answered_slots() gives it.
     * @param int $contextid The CAT context of the attempt.
     * @return array
     */
    private static function row_from_usage(array $answer, int $contextid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/catquiz/lib.php');
        $item = $DB->get_record_sql(
            "SELECT i.catscaleid, p.difficulty, p.status, p.usable
               FROM {local_catquiz_items} i
          LEFT JOIN {local_catquiz_itemparams} p ON p.id = i.activeparamid
              WHERE i.componentid = :componentid AND i.componentname = 'question' AND i.contextid = :contextid",
            ['componentid' => $answer['questionid'], 'contextid' => $contextid],
            IGNORE_MULTIPLE
        );
        $productive = $item && $item->difficulty !== null && is_numeric($item->difficulty)
            && (int) $item->status >= LOCAL_CATQUIZ_STATUS_UPDATED_MANUALLY && (int) $item->usable === 1;
        $scaleid = $item ? (int) $item->catscaleid : 0;
        $scalename = $scaleid ? (string) $DB->get_field('local_catquiz_catscales', 'name', ['id' => $scaleid]) : '';
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
            'questionscale_name' => $scalename,
            'fisherinformation' => null,
            'personability_after' => null,
            'source' => self::SOURCE_QUESTIONUSAGE,
        ];
    }
}
