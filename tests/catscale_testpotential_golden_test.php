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

namespace local_catquiz;

use basic_testcase;
use local_catquiz\local\model\model_item_param;
use local_catquiz\local\model\model_item_param_list;

/**
 * The test potential - the information of the k most informative remaining items - against an independent reference
 * (issue #170, re-audit 15.11.2026): pools of 0 to 10000 items, k = 0, 1, n and more than n, two models; the same
 * value on every call, and the list of items is not changed.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\catscale
 */
final class catscale_testpotential_golden_test extends basic_testcase {
    /**
     * A deterministic pool: every third item Rasch, the others 2PL.
     *
     * @param int $n
     * @return array [model_item_param_list, array of [model, difficulty, discrimination]]
     */
    private static function pool(int $n): array {
        mt_srand(170 + $n);
        $list = new model_item_param_list();
        $raw = [];
        for ($i = 1; $i <= $n; $i++) {
            $b = mt_rand(-3000, 3000) / 1000;
            $a = mt_rand(300, 2500) / 1000;
            $model = $i % 3 === 0 ? 'rasch' : 'raschbirnbaum';
            $params = $model === 'rasch' ? ['difficulty' => $b] : ['difficulty' => $b, 'discrimination' => $a];
            $list->add((new model_item_param($i, $model))->set_parameters($params));
            $raw[$i] = [$model, $b, $a];
        }
        return [$list, $raw];
    }

    /**
     * The reference: the item information in closed form, sorted descending, the first k summed.
     *
     * @param array $raw
     * @param float $t
     * @param int $k
     * @return float
     */
    private static function reference(array $raw, float $t, int $k): float {
        $info = [];
        foreach ($raw as [$model, $b, $a]) {
            if ($model === 'rasch') {
                $p = 1 / (1 + exp(-($t - $b)));
                $info[] = $p * (1 - $p);
            } else {
                $p = 1 / (1 + exp(-$a * ($t - $b)));
                $info[] = $a ** 2 * $p * (1 - $p);
            }
        }
        rsort($info, SORT_NUMERIC);
        return $k < 1 ? 0.0 : array_sum(array_slice($info, 0, $k));
    }

    /**
     * Pool sizes.
     *
     * @return array
     */
    public static function sizes(): array {
        return ['0' => [0], '1' => [1], '10' => [10], '100' => [100], '1000' => [1000], '10000' => [10000]];
    }

    /**
     * Every k of interest, at three abilities.
     *
     * @param int $n
     * @dataProvider sizes
     */
    public function test_against_the_reference(int $n): void {
        [$list, $raw] = self::pool($n);
        $before = serialize($list);
        foreach ([-2.2, 0.0, 1.7] as $t) {
            foreach (array_unique([0, 1, $n, $n + 3, max(0, intdiv($n, 2))]) as $k) {
                $expected = self::reference($raw, $t, $k);
                $first = catscale::get_testpotential($t, $list, $k);
                $second = catscale::get_testpotential($t, $list, $k);
                $this->assertEqualsWithDelta($expected, $first, 1e-9 * max(1.0, $expected), "n=$n k=$k t=$t");
                $this->assertSame($first, $second, 'The same value on every call.');
            }
        }
        $this->assertSame($before, serialize($list), 'The list of items is not changed.');
    }

    /**
     * k above n sums every item; k = 0 and below is 0.
     */
    public function test_k_bounds(): void {
        [$list, $raw] = self::pool(25);
        $this->assertSame(0.0, catscale::get_testpotential(0.3, $list, 0));
        $this->assertSame(0.0, catscale::get_testpotential(0.3, $list, -4));
        $this->assertEqualsWithDelta(
            catscale::get_testinformation(0.3, $list),
            catscale::get_testpotential(0.3, $list, 1000),
            1e-12
        );
        $this->assertEqualsWithDelta(self::reference($raw, 0.3, 25), catscale::get_testpotential(0.3, $list, 25), 1e-12);
    }
}
