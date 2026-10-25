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

namespace local_catquiz\local\statistics;

use local_catquiz\catscale;

/**
 * The attempts a statistic is built from: one definition for every chart (issue #88).
 *
 * An attempt belongs to the population when
 * - it was taken on the root scale of the requested scale (local_catquiz_attempts.scaleid holds the root
 *   scale; a subscale is selected through its root and reported with its own values),
 * - it is completed: its end time is set. The finalisation stamps it exactly once; a running attempt has
 *   none. The status column is no criterion - a running attempt is saved with status "OK" after every
 *   answer, and the stop reasons share their numbers with the attempt states,
 * - it lies in the scope: course, test (adaptivequiz.id), CAT context, and a period of end times with
 *   both bounds included (starttime <= endtime <= endtime of the period),
 * - optionally, it is the first or the last completed attempt of its person in the scope. First and
 *   last are decided over the whole history of the scope, the period filters afterwards: a person whose
 *   first attempt lies before the period has no first attempt in it.
 *
 * A completed attempt without an answer (aborted) belongs to the population: it took place. It has no
 * result. The result of an attempt for a scale is the value stored at finalisation
 * (local_catquiz_personparams, resultsource "current", attemptid = local_catquiz_attempts.id) when it is
 * valid, otherwise none. An attempt finalised before results were stored that way (legacy) counts with
 * the value it carries, as valid - it was never judged (the rule of learning_progress_series).
 *
 * Deliberate differences, named here and tested (doc/statistics-population.md):
 * - In SQL the legacy value exists for the root scale only (personability_after_attempt); the value of a
 *   subscale lives in the JSON of a legacy attempt and is read in PHP only.
 * - "No attempt" counts the people who could take the test (eligible_population), not attempts.
 * - The peer comparison of the feedback excludes the person compared.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_population {
    /** @var string Every completed attempt of the scope. */
    public const ALL = 'all';

    /** @var string The first completed attempt of each person in the scope. */
    public const FIRST = 'first';

    /** @var string The last completed attempt of each person in the scope. */
    public const LAST = 'last';

    /** @var int Attempts per query when stored results are read. */
    private const CHUNK = 1000;

    /** @var int The requested scale: its values are reported. */
    private int $scaleid;

    /** @var int The root scale of the requested one: attempts are selected by it. */
    private int $rootscaleid;

    /** @var int|null */
    private ?int $courseid;

    /** @var int|null adaptivequiz.id */
    private ?int $testid;

    /** @var int|null */
    private ?int $contextid;

    /** @var int|null Lower bound of the end time, included. */
    private ?int $starttime;

    /** @var int|null Upper bound of the end time, included. */
    private ?int $endtime;

    /** @var string One of ALL, FIRST, LAST. */
    private string $selection;

    /** @var array Attempts loaded by attempts(), by field list. */
    private array $loaded = [];

    /**
     * Constructor.
     *
     * @param int $scaleid The requested scale; a subscale selects through its root scale.
     * @param int|null $courseid
     * @param int|null $testid adaptivequiz.id
     * @param int|null $contextid CAT context, or null for every context of the scale.
     * @param int|null $starttime Lower bound of the end time (included), or null.
     * @param int|null $endtime Upper bound of the end time (included), or null.
     * @param string $selection ALL, FIRST or LAST.
     */
    public function __construct(
        int $scaleid,
        ?int $courseid = null,
        ?int $testid = null,
        ?int $contextid = null,
        ?int $starttime = null,
        ?int $endtime = null,
        string $selection = self::ALL
    ) {
        if (!in_array($selection, [self::ALL, self::FIRST, self::LAST], true)) {
            throw new \coding_exception('Unknown attempt selection: ' . $selection);
        }
        $this->scaleid = $scaleid;
        $this->rootscaleid = self::root_of($scaleid);
        $this->courseid = $courseid ?: null;
        $this->testid = $testid ?: null;
        $this->contextid = $contextid ?: null;
        $this->starttime = $starttime;
        $this->endtime = $endtime;
        $this->selection = $selection;
    }

    /**
     * The root scale of a scale.
     *
     * @param int $scaleid
     * @return int
     */
    public static function root_of(int $scaleid): int {
        global $DB;
        $parentid = $DB->get_field('local_catquiz_catscales', 'parentid', ['id' => $scaleid]);
        if (empty($parentid)) {
            return $scaleid;
        }
        return (int) catscale::get_ancestors($scaleid, 3)['mainscale'];
    }

    /**
     * The SQL condition of a completed attempt.
     *
     * @param string $alias Alias of local_catquiz_attempts; empty for the unqualified column.
     * @return string
     */
    public static function completed_sql(string $alias = 'a'): string {
        $column = $alias === '' ? 'endtime' : "$alias.endtime";
        return "$column IS NOT NULL AND $column > 0";
    }

    /**
     * The requested scale.
     *
     * @return int
     */
    public function get_scaleid(): int {
        return $this->scaleid;
    }

    /**
     * The root scale the attempts are selected by.
     *
     * @return int
     */
    public function get_rootscaleid(): int {
        return $this->rootscaleid;
    }

    /**
     * The selection: ALL, FIRST or LAST.
     *
     * @return string
     */
    public function get_selection(): string {
        return $this->selection;
    }

    /**
     * The condition selecting the attempts of the population.
     *
     * @param string $alias Alias of local_catquiz_attempts.
     * @param string $prefix Prefix of the parameter names; distinct for every occurrence in one statement.
     * @param array|null $userids Only these people, or null for everybody. An empty list selects nothing.
     * @return array [sql, params]
     */
    public function where(string $alias = 'a', string $prefix = 'ap', ?array $userids = null): array {
        global $DB;

        [$sql, $params] = $this->scope_where($alias, $prefix, true);
        if ($this->selection !== self::ALL) {
            // First or last over the whole history of the scope: the period filters afterwards.
            $other = $alias . 'x';
            [$scope, $scopeparams] = $this->scope_where($other, $prefix . 'x', false);
            $before = $this->selection === self::FIRST ? '<' : '>';
            $sql .= " AND NOT EXISTS (
                        SELECT 1
                          FROM {local_catquiz_attempts} $other
                         WHERE $other.userid = $alias.userid
                           AND $scope
                           AND ($other.endtime $before $alias.endtime
                                OR ($other.endtime = $alias.endtime AND $other.id $before $alias.id))
                      )";
            $params += $scopeparams;
        }
        if ($userids !== null) {
            if (!$userids) {
                $sql .= ' AND 1 = 0';
            } else {
                [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED, $prefix . 'u');
                $sql .= " AND $alias.userid $insql";
                $params += $inparams;
            }
        }
        return [$sql, $params];
    }

    /**
     * The scope without the selection of first or last.
     *
     * @param string $alias
     * @param string $prefix
     * @param bool $withperiod
     * @return array [sql, params]
     */
    private function scope_where(string $alias, string $prefix, bool $withperiod): array {
        $sql = "$alias.scaleid = :{$prefix}root AND " . self::completed_sql($alias);
        $params = [$prefix . 'root' => $this->rootscaleid];
        if ($this->courseid) {
            $sql .= " AND $alias.courseid = :{$prefix}course";
            $params[$prefix . 'course'] = $this->courseid;
        }
        if ($this->testid) {
            $sql .= " AND $alias.instanceid = :{$prefix}test";
            $params[$prefix . 'test'] = $this->testid;
        }
        if ($this->contextid) {
            $sql .= " AND $alias.contextid = :{$prefix}context";
            $params[$prefix . 'context'] = $this->contextid;
        }
        if ($withperiod && $this->starttime !== null) {
            $sql .= " AND $alias.endtime >= :{$prefix}start";
            $params[$prefix . 'start'] = $this->starttime;
        }
        if ($withperiod && $this->endtime !== null) {
            $sql .= " AND $alias.endtime <= :{$prefix}end";
            $params[$prefix . 'end'] = $this->endtime;
        }
        return [$sql, $params];
    }

    /**
     * The valid result of an attempt for the requested scale, as an SQL expression; NULL when there is none.
     *
     * Stored result of the finalisation when the attempt has stored results; for a legacy attempt the
     * value of the attempt on its root scale. A legacy subscale value has no SQL expression (see the class).
     *
     * @param string $alias Alias of local_catquiz_attempts.
     * @param string $prefix Prefix of the parameter names.
     * @return array [sql, params]
     */
    public function value_sql(string $alias = 'a', string $prefix = 'apv'): array {
        $stored = "(SELECT MAX({$prefix}p.ability)
                      FROM {local_catquiz_personparams} {$prefix}p
                     WHERE {$prefix}p.attemptid = $alias.id
                       AND {$prefix}p.resultsource = :{$prefix}src
                       AND {$prefix}p.catscaleid = :{$prefix}scale
                       AND {$prefix}p.isvalid = 1)";
        $legacy = $this->scaleid === $this->rootscaleid ? "$alias.personability_after_attempt" : 'NULL';
        $sql = "CASE WHEN EXISTS (SELECT 1
                                    FROM {local_catquiz_personparams} {$prefix}x
                                   WHERE {$prefix}x.attemptid = $alias.id
                                     AND {$prefix}x.resultsource = :{$prefix}src2)
                     THEN $stored
                     ELSE $legacy
                 END";
        return [$sql, [
            $prefix . 'src' => 'current',
            $prefix . 'src2' => 'current',
            $prefix . 'scale' => $this->scaleid,
        ]];
    }

    /**
     * The attempts of the population, in the order of their end time.
     *
     * Every record carries `value` (the valid result for the requested scale or null) and `primaryvalid`
     * (whether the reported scale of the attempt has a valid result).
     *
     * @param string $fields Columns of local_catquiz_attempts, alias a; id, userid, scaleid, endtime and json
     *                       are always read.
     * @param array|null $userids See where().
     * @return array
     */
    public function attempts(string $fields = '', ?array $userids = null): array {
        global $DB;

        $columns = 'a.id, a.userid, a.scaleid, a.contextid, a.courseid, a.instanceid, a.attemptid, '
            . 'a.starttime, a.endtime, a.json, a.personability_after_attempt, a.status, a.timemodified';
        if ($fields !== '') {
            $columns .= ', ' . $fields;
        }
        $key = $columns . '|' . ($userids === null ? '*' : implode(',', $userids));
        if (isset($this->loaded[$key])) {
            return $this->loaded[$key];
        }
        [$where, $params] = $this->where('a', 'ap', $userids);
        $records = [];
        $decoded = [];
        // Never debug_info: it can hold the full trace of an attempt and no chart reads it. A recordset
        // holds a database resource until it is closed.
        $recordset = $DB->get_recordset_sql(
            "SELECT $columns FROM {local_catquiz_attempts} a WHERE $where ORDER BY a.endtime ASC, a.id ASC",
            $params
        );
        foreach ($recordset as $record) {
            // Only what the charts read stays in memory.
            $json = json_decode((string) ($record->json ?? ''));
            $decoded[(int) $record->id] = (object) [
                'personabilities_abilities' => $json->personabilities_abilities ?? null,
                'personabilities' => $json->personabilities ?? null,
            ];
            $record->json = json_encode($decoded[(int) $record->id]);
            $records[(int) $record->id] = $record;
        }
        $recordset->close();

        $stored = $this->stored_results(array_keys($records));
        foreach ($records as $id => $record) {
            $json = $decoded[$id];
            if (isset($stored[$id])) {
                $row = $stored[$id]['scales'][$this->scaleid] ?? null;
                $record->value = ($row && (int) $row->isvalid === 1 && $row->ability !== null) ? (float) $row->ability : null;
                $record->primaryvalid = $stored[$id]['primaryvalid'];
                $record->legacy = false;
            } else {
                $record->value = self::legacy_value($record, $json, $this->scaleid, $this->rootscaleid);
                // Never judged: valid, like its value.
                $record->primaryvalid = true;
                $record->legacy = true;
            }
        }
        $this->loaded[$key] = array_values($records);
        return $this->loaded[$key];
    }

    /**
     * The value of a legacy attempt (no stored results) for a scale.
     *
     * @param \stdClass $record
     * @param mixed $json Decoded JSON of the attempt.
     * @param int $scaleid
     * @param int $rootscaleid
     * @return float|null
     */
    private static function legacy_value(\stdClass $record, $json, int $scaleid, int $rootscaleid): ?float {
        if ($scaleid === $rootscaleid && isset($record->personability_after_attempt)) {
            return (float) $record->personability_after_attempt;
        }
        if (isset($json->personabilities->$scaleid) && is_numeric($json->personabilities->$scaleid)) {
            return (float) $json->personabilities->$scaleid;
        }
        return null;
    }

    /**
     * The results stored at finalisation, by attempt.
     *
     * @param int[] $ids local_catquiz_attempts.id
     * @return array attemptid => ['scales' => [scaleid => row], 'primaryvalid' => bool]
     */
    private function stored_results(array $ids): array {
        global $DB;

        $stored = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'apsr');
            $rows = $DB->get_recordset_select(
                'local_catquiz_personparams',
                "attemptid $insql AND resultsource = :source",
                $params + ['source' => 'current'],
                'id',
                'id, attemptid, catscaleid, ability, isvalid, isprimary'
            );
            foreach ($rows as $row) {
                $id = (int) $row->attemptid;
                $stored[$id] = $stored[$id] ?? ['scales' => [], 'primaryvalid' => false];
                $stored[$id]['scales'][(int) $row->catscaleid] = $row;
                if ((int) $row->isprimary === 1 && (int) $row->isvalid === 1) {
                    $stored[$id]['primaryvalid'] = true;
                }
            }
            $rows->close();
        }
        return $stored;
    }

    /**
     * One valid value per person: the latest (or first) valid result among the attempts of the population.
     *
     * @param array $attempts Records of attempts().
     * @param string $rule 'last' or 'first'.
     * @return array userid => value
     */
    public static function value_per_person(array $attempts, string $rule = 'last'): array {
        $values = [];
        foreach ($attempts as $attempt) {
            if ($attempt->value === null) {
                continue;
            }
            $userid = (int) $attempt->userid;
            if ($rule === 'first' && array_key_exists($userid, $values)) {
                continue;
            }
            $values[$userid] = (float) $attempt->value;
        }
        return $values;
    }
}
