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

namespace local_catquiz\local\integrity;

use local_catquiz\catscale;
use local_catquiz\local\model\model_responses;
use local_catquiz\teststrategy\progress;

/**
 * The stored standard errors of finished attempts against the definition of #163, read-only (re-audit "ab #147",
 * 19.11.2026: "historische Altresultate lesend abgleichen").
 *
 * For every result row of an attempt (local_catquiz_personparams, resultsource current) the standard error is computed
 * again as the attempt computes it today: 1 / sqrt(sum of the Fisher information) of the questions answered with a
 * fraction, pilot questions left out, with the active parameters of the attempt's context in the scale and its
 * subscales, at the ability of the attempt (its progress; the row itself rounds it). The answers come from the
 * progress of the attempt, after the retention task from what was stored with the attempt. Nothing is written: no
 * row, no cache entry, no recalculation.
 *
 * A difference does not by itself say that the stored value is wrong. Rows written before 2026111707 summed the
 * information of every question still available (#163); rows written before this version could, for a subscale
 * without answers of both kinds, hold the standard error at an earlier ability; and parameters recalibrated since the
 * attempt give another value today. The report names counts and ratios, never a person.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class standard_error_audit {
    /** @var float Stored values keep four decimals, the ability as well: equal within this. */
    public const TOLERANCE = 0.0005;

    /** @var int Ids of result rows listed at most. */
    private const SAMPLE = 20;

    /**
     * Compares the stored standard errors of the finished attempts of a CAT context.
     *
     * @param int $contextid
     * @param int $from Only attempts that ended at or after this time (0: no lower bound).
     * @param int $to Only attempts that ended before this time (0: no upper bound).
     * @return array ['rows' => int, 'equal' => int, 'smaller' => int (stored below today's value), 'larger' => int,
     *               'unreadable' => int (no answers to compute from), 'ratio' => [p5, p50, p95] of stored / today,
     *               'bymonth' => [YYYY-MM => ['rows', 'equal', 'smaller', 'larger']], 'sample' => int[] row ids of
     *               differing rows]
     */
    public static function check(int $contextid, int $from = 0, int $to = 0): array {
        global $DB;
        $params = ['contextid' => $contextid, 'source' => 'current'];
        $window = '';
        if ($from) {
            $window .= ' AND a.endtime >= :fromtime';
            $params['fromtime'] = $from;
        }
        if ($to) {
            $window .= ' AND a.endtime < :totime';
            $params['totime'] = $to;
        }
        $rows = $DB->get_records_sql(
            "SELECT pp.id, pp.attemptid, pp.catscaleid, pp.ability, pp.standarderror, a.attemptid AS adaptiveattemptid,
                    a.component, a.userid, a.endtime
               FROM {local_catquiz_personparams} pp
               JOIN {local_catquiz_attempts} a ON a.id = pp.attemptid
              WHERE pp.contextid = :contextid AND pp.resultsource = :source AND a.endtime IS NOT NULL
                    AND pp.standarderror IS NOT NULL AND pp.ability IS NOT NULL $window
           ORDER BY pp.attemptid, pp.catscaleid",
            $params
        );
        $result = ['rows' => 0, 'equal' => 0, 'smaller' => 0, 'larger' => 0, 'unreadable' => 0, 'ratio' => [],
            'bymonth' => [], 'sample' => []];
        $ratios = [];
        $responses = [];
        foreach ($rows as $row) {
            $result['rows']++;
            $month = date('Y-m', (int) $row->endtime);
            $result['bymonth'][$month] ??= ['rows' => 0, 'equal' => 0, 'smaller' => 0, 'larger' => 0];
            $result['bymonth'][$month]['rows']++;
            $responses[$row->attemptid] ??= self::responses($row);
            [$answers, $abilities] = $responses[$row->attemptid] ?? [null, []];
            if ($answers === null) {
                $result['unreadable']++;
                continue;
            }
            $items = model_responses::create_from_array([(int) $row->userid => ['component' => $answers]])
                ->get_items_for_scale((int) $row->catscaleid, $contextid);
            // The ability of the attempt itself where its progress keeps it; the row rounds it.
            $ability = (float) ($abilities[$row->catscaleid] ?? $row->ability);
            $today = catscale::get_standarderror($ability, $items, INF);
            $stored = (float) $row->standarderror;
            if (!is_finite($today)) {
                $result['unreadable']++;
                continue;
            }
            $kind = abs($stored - $today) <= self::TOLERANCE * max(1.0, $today) ? 'equal'
                : ($stored < $today ? 'smaller' : 'larger');
            $result[$kind]++;
            $result['bymonth'][$month][$kind]++;
            if ($today > 0) {
                $ratios[] = $stored / $today;
            }
            if ($kind !== 'equal' && count($result['sample']) < self::SAMPLE) {
                $result['sample'][] = (int) $row->id;
            }
        }
        sort($ratios);
        $quantile = fn(float $q) => $ratios ? round($ratios[(int) floor($q * (count($ratios) - 1))], 4) : null;
        $result['ratio'] = ['p5' => $quantile(0.05), 'p50' => $quantile(0.5), 'p95' => $quantile(0.95)];
        ksort($result['bymonth']);
        return $result;
    }

    /**
     * The scored answers of an attempt, pilot questions left out - from its progress, or, after the retention task,
     * from what was stored with the attempt.
     *
     * @param \stdClass $row
     * @return array|null [question id => ['fraction' => float], scale id => ability]; null without answers to compute
     *                    from.
     */
    private static function responses(\stdClass $row): ?array {
        $progress = progress::load_for_reading((int) $row->attemptid)
            ?? progress::load_historical((int) $row->adaptiveattemptid, (string) $row->component);
        if ($progress === null) {
            return null;
        }
        $played = $progress->get_playedquestions();
        $scored = [];
        foreach ((array) $progress->get_user_responses() as $questionid => $response) {
            if (
                !empty($played[$questionid]->is_pilot) || !is_array($response) || !isset($response['fraction'])
                    || !is_numeric($response['fraction'])
            ) {
                continue;
            }
            $scored[$questionid] = $response;
        }
        return $scored ? [$scored, $progress->get_abilities()] : null;
    }
}
