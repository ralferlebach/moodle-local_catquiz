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

namespace local_catquiz\local\model;

use basic_testcase;

/**
 * The category of a response in the polytomous models (re-audit "ab #147", 19.11.2026, #163).
 *
 * Moodle stores the fraction of a response with seven decimals - a third of the marks is 0.3333333 - while the models
 * sanitise the keys of their category parameters to model_raschmodel::PRECISION (3) decimals. get_key_by_fractions()
 * compared the two as they were: 0.333 < 0.3333333 put the response in the next category. A response with a third of
 * the marks counted as one with two thirds, the probabilities of the categories no longer added up to 1, and the item
 * information - and with it the standard error of #163 - was that of other categories. Found with the closed-form
 * reference for all models (addscalestandarderror_test).
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\local\model\model_raschmodel
 */
final class polytomous_category_test extends basic_testcase {
    /**
     * The four polytomous models with their parameter key and slope, each with category fractions of three, four, six
     * and seven parts, written with Moodle's seven decimals and with the three of the models.
     *
     * @return array
     */
    public static function cases(): array {
        $models = [
            'GRM' => ['grm', 'difficulties', 1.0],
            'GGRM' => ['grmgeneralized', 'difficulties', 1.4],
            'PCM' => ['pcm', 'intercepts', 1.0],
            'GPCM' => ['pcmgeneralized', 'intercepts', 1.4],
        ];
        $cases = [];
        foreach ($models as $label => [$model, $key, $slope]) {
            foreach ([3, 4, 6, 7] as $parts) {
                foreach (['7 decimals' => 7, '3 decimals' => 3] as $written => $decimals) {
                    $cases["$label, $parts parts, keys with $written"] = [$model, $key, $slope, $parts, $decimals];
                }
            }
        }
        return $cases;
    }

    /**
     * The category parameters of an item: increasing thresholds (GRM) or step parameters (PCM).
     *
     * @param int $parts
     * @param int $decimals
     * @return array fraction as written => parameter
     */
    private static function parameters(int $parts, int $decimals): array {
        $parameters = [sprintf('%.' . $decimals . 'F', 0.0) => 0.0];
        for ($k = 1; $k <= $parts; $k++) {
            $parameters[sprintf('%.' . $decimals . 'F', $k / $parts)] = -1.2 + 2.1 * ($k - 1) / max(1, $parts - 1);
        }
        return $parameters;
    }

    /**
     * The probability of each category in closed form, independent of the models.
     *
     * @param string $model
     * @param float $t
     * @param float[] $parameters b_1..b_m or d_1..d_m
     * @param float $a
     * @return float[] P_0..P_m
     */
    private static function closed_form(string $model, float $t, array $parameters, float $a): array {
        if (str_starts_with($model, 'grm')) {
            $cumulative = [1.0];
            foreach ($parameters as $b) {
                $cumulative[] = 1 / (1 + exp(-$a * ($t - $b)));
            }
            $cumulative[] = 0.0;
            $p = [];
            for ($k = 0; $k < count($cumulative) - 1; $k++) {
                $p[] = $cumulative[$k] - $cumulative[$k + 1];
            }
            return $p;
        }
        $z = [0.0];
        foreach ($parameters as $d) {
            $z[] = end($z) + $a * ($t - $d);
        }
        $weights = array_map(fn($zk) => exp($zk - max($z)), $z);
        return array_map(fn($w) => $w / array_sum($weights), $weights);
    }

    /**
     * A response with k of n parts of the marks, as Moodle stores it (seven decimals), falls in category k: the
     * probabilities of the item's categories add up to 1 and are those of the closed form, at three abilities.
     *
     * @param string $model
     * @param string $key
     * @param float $slope
     * @param int $parts
     * @param int $decimals
     * @dataProvider cases
     */
    public function test_a_response_falls_in_its_category(
        string $model,
        string $key,
        float $slope,
        int $parts,
        int $decimals
    ): void {
        $class = '\\catmodel_' . $model . '\\' . $model;
        $parameters = self::parameters($parts, $decimals);
        $ip = [$key => $parameters, 'discrimination' => $slope];
        $values = array_slice(array_values($parameters), 1);
        foreach ([-2.4, 0.3, 1.9] as $ability) {
            $expected = self::closed_form($model, $ability, $values, $slope);
            $sum = 0.0;
            for ($k = 0; $k <= $parts; $k++) {
                // The fraction as question_attempt_steps.fraction holds it.
                $fraction = (float) sprintf('%.7F', $k / $parts);
                $p = $class::likelihood(['ability' => $ability], $ip, $fraction);
                $this->assertEqualsWithDelta($expected[$k], $p, 1e-12, "$model: $k/$parts at $ability");
                $sum += $p;
            }
            $this->assertEqualsWithDelta(1.0, $sum, 1e-12, "$model, $parts parts at $ability: the categories add up to 1");
        }
    }

    /**
     * The item information of the model is that of the closed form, I = sum_k (dP_k/dt)^2 / P_k, with the derivative
     * of the closed-form probabilities taken numerically.
     *
     * @param string $model
     * @param string $key
     * @param float $slope
     * @param int $parts
     * @param int $decimals
     * @dataProvider cases
     */
    public function test_the_information_of_the_item(
        string $model,
        string $key,
        float $slope,
        int $parts,
        int $decimals
    ): void {
        $parameters = self::parameters($parts, $decimals);
        $ip = [$key => $parameters, 'discrimination' => $slope];
        $values = array_slice(array_values($parameters), 1);
        $h = 1e-5;
        foreach ([-2.4, 0.3, 1.9] as $ability) {
            $p = self::closed_form($model, $ability, $values, $slope);
            $up = self::closed_form($model, $ability + $h, $values, $slope);
            $down = self::closed_form($model, $ability - $h, $values, $slope);
            $information = 0.0;
            foreach ($p as $k => $pk) {
                $information += (($up[$k] - $down[$k]) / (2 * $h)) ** 2 / $pk;
            }
            $this->assertEqualsWithDelta(
                $information,
                model_model::get_instance($model)->fisher_info(['ability' => $ability], $ip),
                1e-6 * max(1.0, $information),
                "$model, $parts parts at $ability"
            );
        }
    }
}
