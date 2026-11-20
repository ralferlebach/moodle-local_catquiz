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

use local_catquiz\local\model\model_raschmodel;

/**
 * Which polytomous items the category mapping before the re-audit "ab #147" (#163) touched. Read-only.
 *
 * Until then model_raschmodel::get_key_by_fractions() compared a fraction with the keys of the category parameters as
 * they were, the keys sanitised to PRECISION (3) decimals: a fraction whose rounding to three decimals lies below it -
 * 1/3 = 0.3333333, 5/6, 4/7 - fell in the next category. Two places met such fractions:
 * - Keys: an item whose category keys carry more decimals and round down ("0.3333333"). Its item information - and
 *   with it the selection and the standard error of an attempt - summed the probability of another category.
 * - Responses: Moodle keeps the fraction of a response with seven decimals. The answer path of an attempt rounds it to
 *   three (catquiz::get_last_response_for_attempt()), the calibration and every computation from model_responses do
 *   not: there a response with a third of the marks counted as one with two thirds.
 * Dichotomous models (1PL, 2PL, 3PL) have no categories and are not concerned. Nothing is changed or recomputed here:
 * the counts say whether a recalibration of the items named is worth considering.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class category_fraction_check {
    /** @var string[] The polytomous models and the key of their category parameters. */
    public const MODELS = [
        'grm' => 'difficulties',
        'grmgeneralized' => 'difficulties',
        'pcm' => 'intercepts',
        'pcmgeneralized' => 'intercepts',
    ];

    /** @var int Ids listed at most. */
    private const SAMPLE = 20;

    /**
     * Whether a fraction lies above its rounding to PRECISION decimals - the fractions the old mapping put in the
     * next category.
     *
     * @param float $fraction
     * @return bool
     */
    public static function rounds_down(float $fraction): bool {
        return $fraction - round($fraction, model_raschmodel::PRECISION) > 1e-9;
    }

    /**
     * Counts the items and responses concerned.
     *
     * @param int $contextid A CAT context; 0 for every context.
     * @return array ['parameters' => int (parameter rows of a polytomous model), 'keys' => int (rows with a key that
     *               rounds down), 'activekeys' => int (of these: the active parameters of an item), 'samplekeys' =>
     *               int[] (parameter ids), 'items' => int (items with active polytomous parameters), 'responses' =>
     *               int (graded steps of their questions whose fraction rounds down), 'questions' => int (questions
     *               with such a response), 'samplequestions' => int[] (question ids)]
     */
    public static function check(int $contextid = 0): array {
        global $DB;
        [$modelsql, $params] = $DB->get_in_or_equal(array_keys(self::MODELS), SQL_PARAMS_NAMED, 'model');
        $contextsql = '';
        if ($contextid) {
            $contextsql = ' AND p.contextid = :contextid';
            $params['contextid'] = $contextid;
        }
        $result = [
            'parameters' => 0, 'keys' => 0, 'activekeys' => 0, 'samplekeys' => [],
            'items' => 0, 'responses' => 0, 'questions' => 0, 'samplequestions' => [],
        ];

        $rows = $DB->get_recordset_sql(
            "SELECT p.id, p.model, p.json, i.id AS activeitem
               FROM {local_catquiz_itemparams} p
          LEFT JOIN {local_catquiz_items} i ON i.activeparamid = p.id
              WHERE p.model $modelsql $contextsql
           ORDER BY p.id",
            $params
        );
        foreach ($rows as $row) {
            $result['parameters']++;
            $categories = json_decode((string) $row->json, true)[self::MODELS[$row->model]] ?? [];
            $concerned = false;
            foreach (array_keys(is_array($categories) ? $categories : []) as $key) {
                $concerned = $concerned || (is_numeric($key) && self::rounds_down((float) $key));
            }
            if (!$concerned) {
                continue;
            }
            $result['keys']++;
            $result['activekeys'] += $row->activeitem ? 1 : 0;
            if (count($result['samplekeys']) < self::SAMPLE) {
                $result['samplekeys'][] = (int) $row->id;
            }
        }
        $rows->close();

        $itemcontextsql = $contextid ? ' AND i.contextid = :icontextid' : '';
        $itemparams = $params + ($contextid ? ['icontextid' => $contextid] : []);
        $items = "SELECT i.componentid
                    FROM {local_catquiz_items} i
                    JOIN {local_catquiz_itemparams} p ON p.id = i.activeparamid
                   WHERE i.componentname = 'question' AND p.model $modelsql $contextsql $itemcontextsql";
        $result['items'] = (int) $DB->count_records_sql("SELECT COUNT(1) FROM ($items) items", $itemparams);
        $steps = $DB->get_records_sql(
            "SELECT qa.questionid, COUNT(qas.id) AS steps
               FROM {question_attempt_steps} qas
               JOIN {question_attempts} qa ON qa.id = qas.questionattemptid
              WHERE qa.questionid IN ($items)
                AND qas.fraction IS NOT NULL
                AND qas.fraction - ROUND(qas.fraction, " . model_raschmodel::PRECISION . ") > 0.000000001
           GROUP BY qa.questionid
           ORDER BY qa.questionid",
            $itemparams
        );
        foreach ($steps as $questionid => $step) {
            $result['responses'] += (int) $step->steps;
            $result['questions']++;
            if (count($result['samplequestions']) < self::SAMPLE) {
                $result['samplequestions'][] = (int) $questionid;
            }
        }
        return $result;
    }
}
