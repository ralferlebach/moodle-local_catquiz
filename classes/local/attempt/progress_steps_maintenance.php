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

use cache;
use local_catquiz\teststrategy\progress;

/**
 * Checks the working state stored as head and events (issue #177), and writes it back in the format before (decision of
 * 21.11.2026: "Beides").
 *
 * The check reads only. The way back writes, for every progress in the format of events, the JSON as the code before
 * #177 wrote it - every played question in full, the responses with their texts - and deletes the events, both or
 * neither per attempt. What the events leave out is taken from where it lives: the parameters of a question from the
 * parameter row it was shown with (decision 6, a reference), the texts of an answer from the question usage (D).
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class progress_steps_maintenance {
    /** @var string The head names more or fewer events of a kind than are stored. */
    public const COUNT_MISMATCH = 'count_mismatch';

    /** @var string The numbers of the events are not 1, 2, 3 ... */
    public const SEQ_GAP = 'seq_gap';

    /** @var string The last event of the head (column or JSON) is not the last stored one. */
    public const LASTSEQ_MISMATCH = 'lastseq_mismatch';

    /** @var string The head or an event cannot be read. */
    public const UNREADABLE = 'unreadable';

    /** @var string An event holds a text of the question usage (D). */
    public const TEXTS = 'texts';

    /** @var string Events of an attempt whose head is not in the format of events (a way back that left them). */
    public const EVENTS_WITHOUT_EVENTS_HEAD = 'events_without_events_head';

    /** @var string Events of a CAT attempt without a head at all. */
    public const ORPHAN_EVENTS = 'orphan_events';

    /** @var string An event names a parameter row that does not exist any more (shown without difficulty; no error). */
    public const PARAMS_GONE = 'params_gone';

    /** @var string[] The findings that make the stored state wrong; PARAMS_GONE is reported, not counted as such. */
    public const ERRORS = [
        self::COUNT_MISMATCH, self::SEQ_GAP, self::LASTSEQ_MISMATCH, self::UNREADABLE, self::TEXTS,
        self::EVENTS_WITHOUT_EVENTS_HEAD, self::ORPHAN_EVENTS,
    ];

    /**
     * Checks the stored working states. Reads only.
     *
     * @param int[]|null $catattemptids Only these CAT attempts; null for all.
     * @return array 'heads' => [format => count], 'findings' => [finding => CAT attempt ids]
     */
    public static function check(?array $catattemptids = null): array {
        global $DB;
        $findings = array_fill_keys([...self::ERRORS, self::PARAMS_GONE], []);
        $heads = [];
        $withhead = [];
        foreach (self::heads($catattemptids) as $head) {
            $data = json_decode((string) $head->json);
            $format = $data instanceof \stdClass ? (int) ($data->progressformat ?? 0) : -1;
            $heads[$format] = ($heads[$format] ?? 0) + 1;
            $catattemptid = (int) $head->attemptid;
            $withhead[$catattemptid] = true;
            $rows = $DB->get_records(
                'local_catquiz_progress_steps',
                ['catattemptid' => $catattemptid],
                'seq ASC',
                'id, seq, eventtype, itemid, paramid, estimatesdelta, diagnostics'
            );
            if ($format !== progress::FORMAT_STEPS) {
                if ($format < 0) {
                    $findings[self::UNREADABLE][] = $catattemptid;
                }
                if ($rows) {
                    $findings[self::EVENTS_WITHOUT_EVENTS_HEAD][] = $catattemptid;
                }
                continue;
            }
            foreach (self::check_events($data, (int) $head->lastseq, $rows) as $finding) {
                $findings[$finding][] = $catattemptid;
            }
        }
        // Events whose head is gone - looked for only when all attempts are checked.
        if ($catattemptids === null) {
            $orphans = $DB->get_fieldset_sql(
                'SELECT DISTINCT s.catattemptid
                   FROM {local_catquiz_progress_steps} s
              LEFT JOIN {local_catquiz_progress} p ON p.attemptid = s.catattemptid
                  WHERE p.id IS NULL'
            );
            foreach ($orphans as $catattemptid) {
                $findings[self::ORPHAN_EVENTS][] = (int) $catattemptid;
            }
        }
        ksort($heads);
        return ['heads' => $heads, 'findings' => $findings];
    }

    /**
     * The findings of one head in the format of events against its events.
     *
     * @param \stdClass $head The JSON of the head.
     * @param int $columnlastseq local_catquiz_progress.lastseq
     * @param array $rows The events, by seq.
     * @return string[]
     */
    private static function check_events(\stdClass $head, int $columnlastseq, array $rows): array {
        global $DB;
        $findings = [];
        $expected = 1;
        $gap = false;
        $unreadable = false;
        $texts = false;
        $counts = ['shown' => 0, 'excluded' => 0, 'gaveup' => 0, 'later' => 0, 'history' => 0];
        $responses = [];
        $paramids = [];
        $last = 0;
        foreach ($rows as $row) {
            $gap = $gap || (int) $row->seq !== $expected++;
            $last = (int) $row->seq;
            $diagnostics = $row->diagnostics === null ? [] : json_decode((string) $row->diagnostics, true);
            $delta = $row->estimatesdelta === null ? [] : json_decode((string) $row->estimatesdelta, true);
            if (!is_array($diagnostics) || !is_array($delta)) {
                $unreadable = true;
                continue;
            }
            if (isset($diagnostics['r']) && array_intersect_key((array) $diagnostics['r'], array_flip(progress::RESPONSE_TEXTS))) {
                $texts = true;
            }
            switch ($row->eventtype) {
                case progress::EVENT_SHOWN:
                    $counts['shown']++;
                    break;
                case progress::EVENT_EXCLUDED:
                    $counts['excluded']++;
                    break;
                case progress::EVENT_GAVEUP:
                    $counts['gaveup']++;
                    if (isset($diagnostics['r'])) {
                        $responses[(string) $row->itemid] = true;
                    }
                    break;
                case progress::EVENT_ANSWER:
                    $responses[(string) $row->itemid] = true;
                    break;
                case progress::EVENT_LATER:
                    $counts['later']++;
                    break;
            }
            $counts['history'] += count((array) ($diagnostics['h'] ?? []));
            if (!empty($row->paramid)) {
                $paramids[(int) $row->paramid] = true;
            }
        }
        $headcounts = (array) ($head->counts ?? []);
        $counts['responses'] = count($responses);
        foreach ($counts as $kind => $count) {
            // A head written before later responses were logged (27.11.2026) has no count of them: none.
            if ((int) ($headcounts[$kind] ?? ($kind === 'later' ? 0 : -1)) !== $count) {
                $findings[] = self::COUNT_MISMATCH;
                break;
            }
        }
        if ($gap) {
            $findings[] = self::SEQ_GAP;
        }
        if ($last !== $columnlastseq || $last !== (int) ($head->lastseq ?? -1)) {
            $findings[] = self::LASTSEQ_MISMATCH;
        }
        if ($unreadable) {
            $findings[] = self::UNREADABLE;
        }
        if ($texts) {
            $findings[] = self::TEXTS;
        }
        if ($paramids) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($paramids));
            if ($DB->count_records_select('local_catquiz_itemparams', "id $insql", $params) < count($paramids)) {
                $findings[] = self::PARAMS_GONE;
            }
        }
        return $findings;
    }

    /**
     * Writes the working states in the format of events back in the format before #177 and deletes their events.
     *
     * Without $execute nothing is written; the result says what would be. Per attempt both or neither: the JSON of
     * the format before, the last event 0, no events. The cache of the working states is purged afterwards, so that
     * no request goes on with a state that counts events as stored.
     *
     * @param int[]|null $catattemptids Only these CAT attempts; null for all.
     * @param bool $execute
     * @return array 'attempts' => [CAT attempt id => [bytesbefore (head and events), bytesafter, events]],
     *      'failed' => [id => message].
     */
    public static function rollback(?array $catattemptids = null, bool $execute = false): array {
        global $DB;
        $result = ['attempts' => [], 'failed' => []];
        foreach (self::heads($catattemptids) as $head) {
            $data = json_decode((string) $head->json);
            if (!$data instanceof \stdClass || (int) ($data->progressformat ?? 0) !== progress::FORMAT_STEPS) {
                continue;
            }
            $catattemptid = (int) $head->attemptid;
            try {
                $eventbytes = (int) $DB->get_field_sql(
                    'SELECT SUM(' . $DB->sql_length('COALESCE(diagnostics, \'\')') . ' + '
                        . $DB->sql_length('COALESCE(estimatesdelta, \'\')') . ')
                       FROM {local_catquiz_progress_steps} WHERE catattemptid = ?',
                    [$catattemptid]
                );
                $events = $DB->count_records('local_catquiz_progress_steps', ['catattemptid' => $catattemptid]);
                $old = self::old_format($catattemptid, (string) $head->json);
                // The rows of the debug information go back to the column of the attempt, where the code before kept them.
                $debugrows = $old['debugrows'] ?? [];
                unset($old['debugrows']);
                $json = json_encode($old);
                $result['attempts'][$catattemptid] = [
                    'bytesbefore' => strlen((string) $head->json) + $eventbytes,
                    'bytesafter' => strlen($json),
                    'events' => $events,
                ];
                if ($execute) {
                    $transaction = $DB->start_delegated_transaction();
                    $DB->update_record('local_catquiz_progress', (object) ['id' => $head->id, 'json' => $json, 'lastseq' => 0]);
                    if ($debugrows && !$DB->get_field('local_catquiz_attempts', 'debug_info', ['id' => $catattemptid])) {
                        $DB->set_field('local_catquiz_attempts', 'debug_info', json_encode($debugrows), ['id' => $catattemptid]);
                    }
                    $DB->delete_records('local_catquiz_progress_steps', ['catattemptid' => $catattemptid]);
                    $transaction->allow_commit();
                }
            } catch (\Throwable $e) {
                if (isset($transaction) && !$transaction->is_disposed()) {
                    try {
                        $transaction->rollback($e);
                    } catch (\Throwable $ignored) {
                        // The rollback rethrows; the attempt is reported as failed, the others go on.
                        unset($ignored);
                    }
                }
                unset($result['attempts'][$catattemptid]);
                $result['failed'][$catattemptid] = $e->getMessage();
            }
            unset($transaction);
        }
        if ($execute && $result['attempts']) {
            cache::make('local_catquiz', 'adaptivequizattempt')->purge();
        }
        return $result;
    }

    /**
     * The working state of a CAT attempt as the code before #177 stored it.
     *
     * @param int $catattemptid
     * @param string $json The head.
     * @return array
     */
    public static function old_format(int $catattemptid, string $json): array {
        global $DB;
        $data = progress::stored_structure($catattemptid, $json);
        if ($data === null) {
            throw new \coding_exception("The working state of CAT attempt $catattemptid cannot be read.");
        }
        $played = (array) ($data['playedquestions'] ?? []);

        // The parameters each question was played with, from its parameter row (decision 6): what the selection read of
        // a played question before (model_item_param_list::from_array()).
        $paramids = array_values(array_unique(array_filter(array_map(fn($q) => (int) ($q['activeparamid'] ?? 0), $played))));
        $params = [];
        foreach (array_chunk($paramids, 1000) as $chunk) {
            $params += $DB->get_records_list(
                'local_catquiz_itemparams',
                'id',
                $chunk,
                '',
                'id, itemid, contextid, model, difficulty, discrimination, guessing, json, status'
            );
        }
        foreach ($played as $qid => $question) {
            $param = $params[(int) ($question['activeparamid'] ?? 0)] ?? null;
            if ($param === null) {
                continue;
            }
            foreach (['model', 'difficulty', 'discrimination', 'guessing', 'json', 'status', 'itemid', 'contextid'] as $field) {
                if (!array_key_exists($field, $question) || $question[$field] === null) {
                    $question[$field] = $param->$field === null ? null : (string) $param->$field;
                }
            }
            $question['difficulty'] = $question['difficulty'] ?? null;
            $played[$qid] = $question;
        }

        // The texts of the answers, from the question usage (D).
        $responses = (array) ($data['responses'] ?? []);
        $qaids = array_values(array_unique(array_filter(array_map(fn($r) => (int) ($r['questionattemptid'] ?? 0), $responses))));
        $texts = [];
        foreach (array_chunk($qaids, 1000) as $chunk) {
            $texts += $DB->get_records_list(
                'question_attempts',
                'id',
                $chunk,
                '',
                'id, questionusageid, questionid, questionsummary, rightanswer, responsesummary'
            );
        }
        foreach ($responses as $qid => $response) {
            $text = $texts[(int) ($response['questionattemptid'] ?? 0)] ?? null;
            if ($text !== null) {
                $response += [
                    'questionsummary' => $text->questionsummary,
                    'rightanswer' => $text->rightanswer,
                    'responsesummary' => $text->responsesummary,
                    'questionusageid' => (string) $text->questionusageid,
                ];
            }
            $responses[$qid] = $response;
        }

        $old = [
            'progressformat' => progress::FORMAT_COMPACT,
            'playedquestions' => (object) $played,
            'playedquestionsbyscale' => (object) array_map(
                fn($list) => array_map(fn($q) => is_array($q) ? ($q['id'] ?? null) : $q, (array) $list),
                (array) ($data['playedquestionsbyscale'] ?? [])
            ),
        ];
        foreach ($data as $key => $value) {
            if (in_array($key, ['progressformat', 'playedquestions', 'playedquestionsbyscale'], true)) {
                continue;
            }
            $old[$key] = $value;
        }
        $old['responses'] = (object) $responses;
        // A reference to a played question stays one; an object is the question in full.
        if (is_array($old['lastquestion'] ?? null) && isset($played[(string) ($old['lastquestion']['id'] ?? '')])) {
            $old['lastquestion'] = $old['lastquestion']['id'];
        }
        // As before #177: the history only once there is something in it.
        if (empty($old['answerhistory'])) {
            unset($old['answerhistory']);
        }
        foreach (['activescales', 'droppedscales', 'abilities', 'preattemptabilities', 'lockedscales'] as $key) {
            if (isset($old[$key]) && is_array($old[$key]) && $old[$key] !== [] && !array_is_list($old[$key])) {
                $old[$key] = (object) $old[$key];
            }
        }
        return $old;
    }

    /**
     * The heads to look at.
     *
     * @param int[]|null $catattemptids
     * @return \moodle_recordset|array
     */
    private static function heads(?array $catattemptids) {
        global $DB;
        if ($catattemptids === null) {
            return $DB->get_recordset_select(
                'local_catquiz_progress',
                'attemptid IS NOT NULL',
                [],
                'id ASC',
                'id, attemptid, json, lastseq'
            );
        }
        if (!$catattemptids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $catattemptids));
        return $DB->get_records_select(
            'local_catquiz_progress',
            "attemptid $insql",
            $params,
            'id ASC',
            'id, attemptid, json, lastseq'
        );
    }
}
