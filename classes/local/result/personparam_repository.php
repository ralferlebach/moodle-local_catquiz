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

namespace local_catquiz\local\result;

use stdClass;

/**
 * Persistence for per-attempt, per-scale CAT results (Issue #9).
 *
 * Historical truth: one row per finalised attempt and successfully tested
 * scale, written exclusively by the finaliser after validation. Prior values
 * from earlier attempts serve only as start values; N, fraction and SE are
 * never accumulated across attempts.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class personparam_repository {
    /** @var string The table name. */
    private const TABLE = 'local_catquiz_personparams';

    /** @var string Validation status of an intermediate estimate of a running attempt (issue #164). */
    public const PROVISIONAL = 'provisional';

    /**
     * Issue #164: the SQL condition that a row of local_catquiz_personparams is a reference value - a value that may
     * be a start value of a later attempt (carry-over) or stand for a person in a peer group.
     *
     * Not a reference value is an intermediate estimate of an attempt:
     * - a row marked provisional (written while an attempt runs, since 2026111704 / 2026111703);
     * - from older data: a 'current' row without an attempt (written while an attempt ran, before the marking) or a
     *   'current' row of an attempt without standard error (inherited or reconciled during the attempt; a result
     *   of the finaliser always has one).
     * - a 'prior' row without an earlier measured value of the same person, context and scale: the start value of an
     *   attempt carried over as its result for a scale it did not measure validly - when nothing had ever been
     *   measured there, that start value was the default ability (CLARA 17.11.2026: the 10,129 pairs of a valid
     *   'prior' and an invalid 'current' row). A 'prior' row after a measured value repeats that value and counts.
     * Final results, 'legacy' and 'recalibration' rows are reference values, and so is a row without resultsource
     * (written before the column existed). The data are not changed; the rule is applied when reading. See
     * doc/ergebnisvertrag-164.md.
     *
     * @param string $alias Alias of local_catquiz_personparams; '' for a query on the table without alias.
     * @return string
     */
    public static function reference_sql(string $alias = ''): string {
        $a = $alias === '' ? '' : "$alias.";
        $o = $alias === '' ? '{' . self::TABLE . '}.' : "$alias.";
        // The inner alias must differ from every alias a caller uses for the table.
        $m = 'pprefmeasured';
        return "(COALESCE({$a}validationstatus, '') <> '" . self::PROVISIONAL . "'"
            . " AND NOT (COALESCE({$a}resultsource, '') = 'current'"
            . " AND ({$a}attemptid IS NULL OR {$a}standarderror IS NULL))"
            . " AND (COALESCE({$a}resultsource, '') <> 'prior' OR EXISTS ("
            . "SELECT 1 FROM {" . self::TABLE . "} $m"
            . " WHERE $m.userid = {$o}userid AND $m.catscaleid = {$o}catscaleid"
            . " AND ($m.contextid = {$o}contextid OR ($m.contextid IS NULL AND {$o}contextid IS NULL))"
            . " AND $m.id < {$o}id AND " . self::measured_sql($m) . ")))";
    }

    /**
     * Issue #164: the admissible values - the one definition of carry-over and peer population (F1/F2).
     *
     * A valid measurement of its scale (scale_result::is_valid_measurement(): isvalid = 1, or not valid only for
     * reasons that say nothing about the measurement - not the reported scale, reporting off, hidden) that is a
     * reference value (reference_sql()). Of these, the newest row of a person, context and scale is the person's
     * value: the start value of the next attempt (get_latest_valid(), get_latest_valid_by_scale()) and the person in
     * the peer group (peer_population::sql() through scale_result::valid_measurement_sql()).
     *
     * @param string $alias Alias of local_catquiz_personparams; '' for a query on the table without alias.
     * @return string
     */
    public static function admissible_sql(string $alias = ''): string {
        $a = $alias === '' ? '' : "$alias.";
        return "(({$a}isvalid = 1 OR {$a}validationstatus IN (" . self::display_only_list() . ")) AND "
            . self::reference_sql($alias) . ")";
    }

    /**
     * The stored strings of display reasons only, as an SQL list: the validator writes the reasons in a fixed
     * order, so these are the non-empty subsequences of scale_result::display_only_reasons(). Constants of the code,
     * quoted here so that the rule needs no parameters.
     *
     * @return string
     */
    private static function display_only_list(): string {
        $reasons = scale_result::display_only_reasons();
        $count = count($reasons);
        $strings = [];
        for ($mask = 1; $mask < (1 << $count); $mask++) {
            $subset = [];
            foreach ($reasons as $i => $reason) {
                if ($mask & (1 << $i)) {
                    $subset[] = $reason;
                }
            }
            $strings[] = "'" . str_replace("'", "''", implode(',', $subset)) . "'";
        }
        return implode(', ', $strings);
    }

    /**
     * A measured value (issue #164): a valid measurement that is a reference value and not itself a carried-over
     * 'prior'.
     *
     * @param string $alias Alias of local_catquiz_personparams.
     * @return string
     */
    private static function measured_sql(string $alias): string {
        return "($alias.isvalid = 1 OR $alias.validationstatus IN (" . self::display_only_list() . "))"
            . " AND COALESCE($alias.validationstatus, '') <> '" . self::PROVISIONAL . "'"
            . " AND COALESCE($alias.resultsource, '') <> 'prior'"
            . " AND NOT (COALESCE($alias.resultsource, '') = 'current'"
            . " AND ($alias.attemptid IS NULL OR $alias.standarderror IS NULL))";
    }

    /**
     * The rule of reference_sql() on a row in PHP.
     *
     * @param stdClass $row
     * @return bool
     */
    public static function is_reference(stdClass $row): bool {
        if ((string) ($row->validationstatus ?? '') === self::PROVISIONAL) {
            return false;
        }
        if (
            (string) ($row->resultsource ?? '') === 'current'
                && (($row->attemptid ?? null) === null || ($row->standarderror ?? null) === null)
        ) {
            return false;
        }
        if ((string) ($row->resultsource ?? '') === 'prior') {
            global $DB;
            $m = 'pprefmeasured';
            $params = ['userid' => (int) $row->userid, 'catscaleid' => (int) $row->catscaleid, 'id' => (int) $row->id];
            $context = "$m.contextid IS NULL";
            if (($row->contextid ?? null) !== null) {
                $context = "$m.contextid = :contextid";
                $params['contextid'] = (int) $row->contextid;
            }
            return $DB->record_exists_sql(
                "SELECT 1 FROM {" . self::TABLE . "} $m
                  WHERE $m.userid = :userid AND $m.catscaleid = :catscaleid AND $context AND $m.id < :id
                    AND " . self::measured_sql($m),
                $params
            );
        }
        return true;
    }

    /**
     * Files the intermediate estimate of a running attempt for one scale (issue #164).
     *
     * One row per attempt and scale, updated with every step: the running attempt reads it again at the next
     * answer (get_provisional()), nobody else reads it (isvalid 0, provisional). The finaliser turns it into the
     * result of a measured scale and removes what is left (discard_provisional()), so a finished attempt keeps no
     * intermediate rows and an unfinished one at most one per scale.
     *
     * @param int $userid
     * @param int $contextid
     * @param int $catscaleid
     * @param float $ability
     * @param int|null $catattemptid local_catquiz_attempts.id; null only outside an attempt (tests, simulations).
     */
    public static function save_provisional(
        int $userid,
        int $contextid,
        int $catscaleid,
        float $ability,
        ?int $catattemptid
    ): void {
        global $DB;
        $now = time();
        $conditions = ['userid' => $userid, 'contextid' => $contextid, 'catscaleid' => $catscaleid,
            'validationstatus' => self::PROVISIONAL];
        $select = 'userid = :userid AND contextid = :contextid AND catscaleid = :catscaleid'
            . ' AND validationstatus = :validationstatus AND attemptid ' . ($catattemptid === null ? 'IS NULL' : '= :attemptid');
        if ($catattemptid !== null) {
            $conditions['attemptid'] = $catattemptid;
        }
        $existing = $DB->get_records_select(self::TABLE, $select, $conditions, 'id DESC', 'id');
        if ($existing) {
            $id = (int) array_key_first($existing);
            $DB->update_record(self::TABLE, (object) ['id' => $id, 'ability' => $ability, 'timemodified' => $now]);
            if (count($existing) > 1) {
                [$insql, $inparams] = $DB->get_in_or_equal(array_slice(array_keys($existing), 1));
                $DB->delete_records_select(self::TABLE, "id $insql", $inparams);
            }
            return;
        }
        $DB->insert_record(self::TABLE, (object) [
            'userid' => $userid,
            'contextid' => $contextid,
            'catscaleid' => $catscaleid,
            'attemptid' => $catattemptid,
            'ability' => $ability,
            'isprimary' => 0,
            'isvalid' => 0,
            'resultsource' => 'current',
            'validationstatus' => self::PROVISIONAL,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * The intermediate estimates of a running attempt, by scale - only its own: same attempt, person and context.
     *
     * @param int|null $catattemptid
     * @param int $userid
     * @param int $contextid
     * @param int[] $catscaleids
     * @return stdClass[] scale id => row
     */
    public static function get_provisional(?int $catattemptid, int $userid, int $contextid, array $catscaleids): array {
        global $DB;
        if (!$catscaleids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $catscaleids), SQL_PARAMS_NAMED, 'scale');
        $params += ['userid' => $userid, 'contextid' => $contextid, 'vs' => self::PROVISIONAL];
        $select = "userid = :userid AND contextid = :contextid AND validationstatus = :vs AND catscaleid $insql AND attemptid ";
        if ($catattemptid === null) {
            $select .= 'IS NULL';
        } else {
            $select .= '= :attemptid';
            $params['attemptid'] = $catattemptid;
        }
        $out = [];
        foreach ($DB->get_records_select(self::TABLE, $select, $params, 'id ASC') as $row) {
            $out[(int) $row->catscaleid] = $row;
        }
        return $out;
    }

    /**
     * Removes the intermediate estimates of an attempt; called by the finaliser once the results are written.
     *
     * @param int $catattemptid
     * @return int Number of rows removed.
     */
    public static function discard_provisional(int $catattemptid): int {
        global $DB;
        $params = ['attemptid' => $catattemptid, 'vs' => self::PROVISIONAL];
        $count = $DB->count_records_select(self::TABLE, 'attemptid = :attemptid AND validationstatus = :vs', $params);
        $DB->delete_records_select(self::TABLE, 'attemptid = :attemptid AND validationstatus = :vs', $params);
        return $count;
    }

    /**
     * Persist the validated per-scale results of a finalised attempt.
     *
     * Writes exactly one row per scale that was measured in this attempt
     * (unique on attemptid + catscaleid, so re-finalisation is idempotent).
     * Scales that only carry a prior value (not measured in this attempt) are
     * not written, so N/fraction/SE are never carried over.
     *
     * @param int $catattemptid local_catquiz_attempts.id
     * @param int $userid
     * @param int|null $contextid
     * @param attempt_result $result
     * @param bool $older True when an older attempt is written again (revalidation): a range set now is
     *      marked as taken later, not recorded at finalisation.
     * @return void
     */
    public static function save_attempt_result(
        int $catattemptid,
        int $userid,
        ?int $contextid,
        attempt_result $result,
        bool $older = false
    ): void {
        global $DB;

        $now = time();
        foreach ($result->get_scale_results() as $scaleresult) {
            // Only successfully tested (measured in this attempt) scales are
            // historised. Carry-over-only scales are not persisted here.
            if (!$scaleresult->measuredincurrentattempt) {
                continue;
            }

            $record = (object) [
                'attemptid' => $catattemptid,
                'userid' => $userid,
                'contextid' => $contextid,
                'catscaleid' => $scaleresult->scaleid,
                'ability' => $scaleresult->score,
                'standarderror' => $scaleresult->standarderror,
                'n' => $scaleresult->n,
                'fraction' => $scaleresult->fraction,
                'isprimary' => $scaleresult->primary ? 1 : 0,
                'isvalid' => $scaleresult->valid ? 1 : 0,
                'resultsource' => 'current',
                'validationstatus' => implode(',', $scaleresult->rejectionreasons),
                'timecreated' => $now,
                'timemodified' => $now,
            ];

            $existing = $DB->get_record(
                self::TABLE,
                ['attemptid' => $catattemptid, 'catscaleid' => $scaleresult->scaleid],
                'id, rangelower, rangeupper, rangebackfilled'
            );
            // Issue #137: the range the result was measured on, frozen with it. A row written again -
            // a revalidation, say - keeps the range of its finalisation; only a row without one gets
            // today's, and only because it is being written now.
            if ($existing && is_numeric($existing->rangelower) && is_numeric($existing->rangeupper)) {
                $record->rangelower = $existing->rangelower;
                $record->rangeupper = $existing->rangeupper;
                $record->rangebackfilled = (int) $existing->rangebackfilled;
            } else {
                [$record->rangelower, $record->rangeupper] = self::current_range((int) $scaleresult->scaleid);
                $record->rangebackfilled = $older && $record->rangelower !== null ? 1 : 0;
            }
            if ($existing) {
                $record->id = $existing->id;
                $DB->update_record(self::TABLE, $record);
            } else {
                $DB->insert_record(self::TABLE, $record);
            }
        }
    }

    /**
     * The ability range of a scale as it stands now: [lower, upper], or [null, null] when there is none.
     *
     * @param int $scaleid
     * @return array
     */
    public static function current_range(int $scaleid): array {
        try {
            $range = (new \local_catquiz\catscale($scaleid))->get_ability_range();
        } catch (\Throwable $e) {
            return [null, null];
        }
        if (!is_numeric($range['minscalevalue'] ?? null) || !is_numeric($range['maxscalevalue'] ?? null)) {
            return [null, null];
        }
        return [(float) $range['minscalevalue'], (float) $range['maxscalevalue']];
    }

    /**
     * All result rows for a CATquiz attempt, indexed by scale id.
     *
     * @param int $catattemptid local_catquiz_attempts.id
     * @return stdClass[]
     */
    public static function get_for_attempt(int $catattemptid): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['attemptid' => $catattemptid], '', '*');
    }

    /**
     * The most recent valid result for a user/context/scale, used as the
     * carry-over start value for a following attempt.
     *
     * @param int $userid
     * @param int $contextid
     * @param int $catscaleid
     * @return stdClass|null
     */
    public static function get_latest_valid(int $userid, int $contextid, int $catscaleid): ?stdClass {
        global $DB;
        // Issue #164: an admissible value - a valid measurement that is a reference value, the same rule as the
        // peer population (admissible_sql()).
        $records = $DB->get_records_select(
            self::TABLE,
            'userid = :userid AND contextid = :contextid AND catscaleid = :catscaleid AND ' . self::admissible_sql(),
            ['userid' => $userid, 'contextid' => $contextid, 'catscaleid' => $catscaleid],
            'id DESC',
            '*',
            0,
            1
        );
        return $records ? reset($records) : null;
    }

    /**
     * The latest valid person parameter of each of the given scales, in one query (issue #165).
     *
     * The same rule as get_latest_valid() - the row with the highest id among the valid rows of the user, the context
     * and the scale -, for several scales at once. Scales without such a row are missing from the result.
     *
     * @param int $userid
     * @param int $contextid
     * @param int[] $catscaleids
     * @return stdClass[] Rows by scale id.
     */
    public static function get_latest_valid_by_scale(int $userid, int $contextid, array $catscaleids): array {
        global $DB;
        $catscaleids = array_values(array_unique(array_map('intval', $catscaleids)));
        if (!$catscaleids) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($catscaleids, SQL_PARAMS_NAMED, 'scale');
        $sql = "SELECT p.*
                  FROM {" . self::TABLE . "} p
                  JOIN (SELECT MAX(r.id) AS id
                          FROM {" . self::TABLE . "} r
                         WHERE r.userid = :userid AND r.contextid = :contextid AND r.catscaleid $insql
                           AND " . self::admissible_sql('r') . "
                      GROUP BY r.catscaleid) latest ON latest.id = p.id";
        $result = [];
        foreach ($DB->get_records_sql($sql, ['userid' => $userid, 'contextid' => $contextid] + $inparams) as $row) {
            $result[(int) $row->catscaleid] = $row;
        }
        return $result;
    }

    /**
     * The most recent valid primary scale for a user/context, used to
     * prioritise re-testing the last primary scale in a following attempt.
     *
     * @param int $userid
     * @param int $contextid
     * @return stdClass|null
     */
    public static function get_last_primary(int $userid, int $contextid): ?stdClass {
        global $DB;
        $records = $DB->get_records_select(
            self::TABLE,
            'userid = :userid AND contextid = :contextid AND isprimary = 1 AND isvalid = 1 AND '
                . self::reference_sql(),
            ['userid' => $userid, 'contextid' => $contextid],
            'id DESC',
            '*',
            0,
            1
        );
        return $records ? reset($records) : null;
    }
}
