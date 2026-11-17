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

namespace local_catquiz\teststrategy\preselect_task;

use advanced_testcase;
use local_catquiz\local\model\model_item_param_list;
use local_catquiz\local\model\model_model;
use local_catquiz\teststrategy\progress;

/**
 * The observed standard error rests on the questions answered and scored in the attempt (issue #163).
 *
 * Since 2026102408 model_responses::get_items_for_scale() returned every active item parameter of the scale, and
 * addscalestandarderror summed the information of all questions still available: the more candidates, the smaller
 * the standard error, independent of the answers. The standard error is 1 / sqrt(sum of the Fisher information of
 * the scored, non-pilot questions of the attempt in the scale and its subscales, in the attempt's context).
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_catquiz\teststrategy\preselect_task\addscalestandarderror::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_catquiz\local\model\model_responses::class)]
final class addscalestandarderror_test extends advanced_testcase {
    /** @var int CAT context of the attempt. */
    private const CONTEXTID = 5150;

    /** @var int Root scale. */
    private int $root;

    /** @var int Subscale. */
    private int $sub;

    /** @var int Next question id. */
    private int $nextquestion = 7000;

    /** @var array Parameters by question id: [difficulty, discrimination]. */
    private array $params = [];

    /**
     * A root scale with one subscale.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $scale = fn(int $parent, string $name) => (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'parentid' => $parent, 'name' => $name, 'label' => $name, 'contextid' => self::CONTEXTID,
            'minscalevalue' => -5, 'maxscalevalue' => 5, 'timecreated' => 0, 'timemodified' => 0,
        ]);
        $this->root = $scale(0, 'root');
        $this->sub = $scale($this->root, 'sub');
    }

    /**
     * An item with active 2PL parameters.
     *
     * @param int $scaleid
     * @param float $difficulty
     * @param float $discrimination
     * @param int $contextid
     * @param int|null $questionid
     * @param string $model
     * @param float $guessing
     * @param bool $active Whether the item gets active parameters at all.
     * @return int The question id.
     */
    private function item(
        int $scaleid,
        float $difficulty,
        float $discrimination,
        int $contextid = self::CONTEXTID,
        ?int $questionid = null,
        string $model = 'raschbirnbaum',
        float $guessing = 0.0,
        bool $active = true
    ): int {
        global $DB;
        $questionid = $questionid ?? $this->nextquestion++;
        $itemid = (int) $DB->insert_record('local_catquiz_items', (object) [
            'componentname' => 'question', 'componentid' => $questionid, 'catscaleid' => $scaleid,
            'contextid' => $contextid, 'lastupdated' => 0, 'status' => 0,
        ]);
        $paramid = (int) $DB->insert_record('local_catquiz_itemparams', (object) [
            'itemid' => $itemid, 'componentid' => $questionid, 'componentname' => 'question', 'contextid' => $contextid,
            'model' => $model, 'difficulty' => $difficulty, 'discrimination' => $discrimination,
            'guessing' => $guessing, 'json' => '{}', 'status' => 4, 'timecreated' => 0, 'timemodified' => 0,
        ]);
        if ($active) {
            $DB->set_field('local_catquiz_items', 'activeparamid', $paramid, ['id' => $itemid]);
        }
        if ($contextid === self::CONTEXTID) {
            $this->params[$questionid] = [$difficulty, $discrimination];
        }
        \cache_helper::purge_all();
        return $questionid;
    }

    /**
     * Unanswered candidates in both scales.
     *
     * @param int $count
     */
    private function candidates(int $count): void {
        for ($i = 0; $i < $count; $i++) {
            $this->item($i % 2 ? $this->root : $this->sub, ($i % 7) / 2 - 1.5, 0.8 + ($i % 3) / 4);
        }
    }

    /**
     * Runs the task for the given responses and played questions.
     *
     * @param array $responses question id => fraction (null: answered without a fraction)
     * @param int[] $pilots question ids played as pilot questions
     * @param float $ability
     * @return array Standard error by scale.
     */
    private function se(array $responses, array $pilots = [], float $ability = 0.3): array {
        $played = [];
        $userresponses = [];
        foreach ($responses as $questionid => $fraction) {
            $played[$questionid] = (object) ['id' => $questionid, 'is_pilot' => in_array($questionid, $pilots)];
            $userresponses[$questionid] = ['fraction' => $fraction];
        }
        $progress = $this->createMock(progress::class);
        $progress->method('get_user_responses')->willReturn($userresponses);
        $progress->method('get_playedquestions')->willReturn($played);
        $context = [
            'progress' => $progress, 'userid' => 2, 'contextid' => self::CONTEXTID,
            'person_ability' => [$this->root => $ability, $this->sub => $ability],
        ];
        (new addscalestandarderror())->run($context);
        return $context['se'];
    }

    /**
     * The reference: 1 / sqrt(sum of the information of the given questions), as catscale::get_standarderror().
     *
     * @param int[] $questionids
     * @param float $ability
     * @return float
     */
    private function reference(array $questionids, float $ability = 0.3): float {
        $model = model_model::get_instance('raschbirnbaum');
        $information = 0.0;
        foreach ($questionids as $questionid) {
            [$difficulty, $discrimination] = $this->params[$questionid];
            $information += $model->fisher_info(
                ['ability' => $ability],
                ['difficulty' => $difficulty, 'discrimination' => $discrimination, 'guessing' => 0.0]
            );
        }
        return 1 / sqrt(max(10 ** -6, $information));
    }

    /**
     * One answer and 0, 10 or 100 questions still available: the same standard error, that of the one answer
     * (AK1, AK2, DoD1).
     */
    public function test_available_questions_do_not_count(): void {
        $answered = $this->item($this->sub, 0.4, 1.3);
        $expected = $this->reference([$answered]);
        $before = $this->se([$answered => 1.0]);
        $this->assertEqualsWithDelta($expected, $before[$this->sub], 1e-12);
        $this->assertEqualsWithDelta($expected, $before[$this->root], 1e-12);

        foreach ([10, 90] as $more) {
            $this->candidates($more);
            $this->assertSame($before, $this->se([$answered => 1.0]), "with more candidates (+$more)");
        }
    }

    /**
     * A second scored answer adds its information; a pilot question and an answer without a fraction do not
     * (AK3).
     */
    public function test_only_scored_non_pilot_answers_count(): void {
        $first = $this->item($this->sub, 0.4, 1.3);
        $second = $this->item($this->root, -0.6, 0.9);
        $pilot = $this->item($this->sub, 0.1, 1.5);
        $unscored = $this->item($this->sub, 0.9, 1.1);
        $this->candidates(20);

        $one = $this->se([$first => 1.0]);
        $two = $this->se([$first => 1.0, $second => 0.0]);
        $this->assertEqualsWithDelta($this->reference([$first, $second]), $two[$this->root], 1e-12);
        $this->assertLessThan($one[$this->root], $two[$this->root]);
        // The second question is in the root scale, not in the subscale.
        $this->assertSame($one[$this->sub], $two[$this->sub]);

        $this->assertSame($two, $this->se([$first => 1.0, $second => 0.0, $pilot => 1.0], [$pilot]));
        $this->assertSame($two, $this->se([$first => 1.0, $second => 0.0, $unscored => null]));
    }

    /**
     * The parameters of the attempt's context only; a question answered whose item exists in another context only
     * adds nothing (AK4).
     */
    public function test_other_contexts_do_not_count(): void {
        $answered = $this->item($this->sub, 0.4, 1.3);
        $elsewhere = $this->item($this->sub, -1.0, 2.0, self::CONTEXTID + 1);
        // The same question in another context with other parameters.
        $this->item($this->sub, 2.5, 0.5, self::CONTEXTID + 1, $answered);

        $this->assertEqualsWithDelta(
            $this->reference([$answered]),
            $this->se([$answered => 1.0, $elsewhere => 1.0])[$this->root],
            1e-12
        );
    }

    /**
     * The list in the cache keeps every item: the task filters a copy (AK5).
     */
    public function test_cached_list_is_not_changed(): void {
        $answered = $this->item($this->sub, 0.4, 1.3);
        $this->candidates(30);
        $scales = [$this->root, $this->sub];
        $before = count(model_item_param_list::get(self::CONTEXTID, null, $scales));
        $this->se([$answered => 1.0]);
        $this->assertSame($before, count(model_item_param_list::get(self::CONTEXTID, null, $scales)));
        $this->assertSame(31, $before);
    }

    /**
     * Without a scored answer the standard error is not finite yet - no information, not that of the pool.
     */
    public function test_only_pilot_answers_give_no_information(): void {
        $pilot = $this->item($this->sub, 0.1, 1.5);
        $this->candidates(10);
        $se = $this->se([$pilot => 1.0], [$pilot]);
        $this->assertSame(INF, $se[$this->root]);
        $this->assertSame(INF, $se[$this->sub]);
    }

    /**
     * The Fisher information of one item in closed form, written down independently of the engine's models
     * (re-audit 15.11.2026, #163: an oracle per model).
     *
     * 1PL: P = 1 / (1 + exp(-(t - b))), I = P (1 - P).
     * 2PL: P = 1 / (1 + exp(-a (t - b))), I = a^2 P (1 - P).
     * 3PL: P = c + (1 - c) / (1 + exp(-a (t - b))), I = a^2 (1 - P) / P ((P - c) / (1 - c))^2.
     *
     * @param string $model
     * @param float $t
     * @param float $b
     * @param float $a
     * @param float $c
     * @return float
     */
    private static function closed_form(string $model, float $t, float $b, float $a, float $c): float {
        switch ($model) {
            case 'rasch':
                $p = 1 / (1 + exp(-($t - $b)));
                return $p * (1 - $p);
            case 'raschbirnbaum':
                $p = 1 / (1 + exp(-$a * ($t - $b)));
                return $a ** 2 * $p * (1 - $p);
            default:
                $p = $c + (1 - $c) / (1 + exp(-$a * ($t - $b)));
                return $a ** 2 * (1 - $p) / $p * (($p - $c) / (1 - $c)) ** 2;
        }
    }

    /**
     * The models of the oracle.
     *
     * @return array
     */
    public static function models(): array {
        return ['1PL' => ['rasch'], '2PL' => ['raschbirnbaum'], '3PL' => ['mixedraschbirnbaum']];
    }

    /**
     * The standard error of every scale against the closed form: answered items of the scale and its subscales, a
     * pilot, an item without active parameters and an unanswered candidate left out (#163, re-audit 15.11.2026).
     *
     * @param string $model
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('models')]
    public function test_standard_error_against_the_closed_form(string $model): void {
        $items = [
            [$this->root, -0.7, 1.3, 0.2],
            [$this->root, 0.4, 0.8, 0.1],
            [$this->sub, 1.1, 1.6, 0.25],
            [$this->sub, -1.9, 0.6, 0.0],
        ];
        $ids = [];
        foreach ($items as [$scale, $b, $a, $c]) {
            $ids[] = $this->item($scale, $b, $a, self::CONTEXTID, null, $model, $c);
        }
        $pilot = $this->item($this->sub, 0.0, 1.0, self::CONTEXTID, null, $model, 0.1);
        $inactive = $this->item($this->sub, 0.3, 1.0, self::CONTEXTID, null, $model, 0.1, false);
        $this->candidates(5);
        $ability = 0.35;

        $se = $this->se(array_fill_keys([...$ids, $pilot, $inactive], 1.0), [$pilot], $ability);

        $info = fn(array $subset) => array_sum(array_map(
            fn($i) => self::closed_form($model, $ability, $items[$i][1], $items[$i][2], $items[$i][3]),
            $subset
        ));
        // The root counts its own items and those of its subscale, the subscale only its own.
        $this->assertEqualsWithDelta(1 / sqrt($info([0, 1, 2, 3])), $se[$this->root], 1e-9);
        $this->assertEqualsWithDelta(1 / sqrt($info([2, 3])), $se[$this->sub], 1e-9);
    }

    /**
     * A question that is an item of the root and of its subscale counts once in the root (issue #104, H2).
     */
    public function test_a_question_in_two_scales_counts_once(): void {
        $question = $this->item($this->root, 0.2, 1.1);
        $this->item($this->sub, 0.2, 1.1, self::CONTEXTID, $question);

        $se = $this->se([$question => 1.0]);

        $this->assertEqualsWithDelta(
            1 / sqrt(self::closed_form('raschbirnbaum', 0.3, 0.2, 1.1, 0.0)),
            $se[$this->root],
            1e-9
        );
    }
}
