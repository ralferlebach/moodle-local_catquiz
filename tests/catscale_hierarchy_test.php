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
use local_catquiz\data\catscale_structure;

/**
 * Subscales and ancestors of a scale are the same as before, without a scan of all scales per node (issue #169).
 *
 * add_subscales() searched all scales for the children of every node; add_parentscales() searched all scales on
 * every level. Both now index the scales once. The tests compare every scale of deep, wide and random trees with
 * the previous implementations, kept below as the reference.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\catscale
 */
final class catscale_hierarchy_test extends basic_testcase {
    /**
     * Scales keyed by id as dataapi::get_all_catscales() has them.
     *
     * @param array $parents id => parent id, in the order of the database
     * @return array
     */
    private static function scales(array $parents): array {
        $all = [];
        foreach ($parents as $id => $parentid) {
            $all[$id] = new catscale_structure([
                'id' => $id, 'parentid' => $parentid, 'name' => "s$id", 'description' => '', 'minscalevalue' => -5,
                'maxscalevalue' => 5, 'timecreated' => 0, 'timemodified' => 0,
            ]);
        }
        return $all;
    }

    /**
     * Calls a private static method of catscale.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private static function call(string $method, array $args) {
        $m = new \ReflectionMethod(catscale::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    }

    /**
     * Subscales as before issue #169.
     *
     * @param int|null $parentid
     * @param array $all
     * @return array
     */
    private static function reference_subscales(?int $parentid, array $all): array {
        $result = [];
        if ($parentid === null) {
            return $result;
        }
        foreach ($all as $scale) {
            if (intval($scale->parentid) === $parentid) {
                $result = [...$result, $scale->id, ...self::reference_subscales(intval($scale->id), $all)];
            }
        }
        return $result;
    }

    /**
     * Ancestors as before issue #169.
     *
     * @param int $scaleid
     * @param array $all
     * @return array
     */
    private static function reference_parents(int $scaleid, array $all): array {
        foreach ($all as $scale) {
            if (intval($scale->id) === $scaleid && intval($scale->parentid) !== 0) {
                return [$scale->parentid, ...self::reference_parents(intval($scale->parentid), $all)];
            }
        }
        return [];
    }

    /**
     * Trees to compare: a chain, a broad fan, random trees of 10, 100 and 400 scales in shuffled database order.
     *
     * @return array
     */
    public static function trees(): array {
        $chain = [1 => 0];
        for ($i = 2; $i <= 120; $i++) {
            $chain[$i] = $i - 1;
        }
        $fan = [1 => 0];
        for ($i = 2; $i <= 400; $i++) {
            $fan[$i] = $i <= 20 ? 1 : 2 + ($i % 19);
        }
        $trees = ['chain 120' => [$chain], 'fan 400' => [$fan]];
        mt_srand(169);
        foreach ([10, 100, 400] as $n) {
            $parents = [1 => 0, 2 => 0];
            for ($i = 3; $i <= $n; $i++) {
                $parents[$i] = mt_rand(0, 9) === 0 ? 0 : mt_rand(1, $i - 1);
            }
            // The database order is not the order of the ids.
            $keys = array_keys($parents);
            shuffle($keys);
            $shuffled = [];
            foreach ($keys as $k) {
                $shuffled[$k] = $parents[$k];
            }
            $trees["random $n"] = [$shuffled];
        }
        return $trees;
    }

    /**
     * Every scale: the same subscales in the same order and the same ancestors as before (AK1, DoD1).
     *
     * @dataProvider trees
     * @param array $parents
     */
    public function test_same_subscales_and_ancestors(array $parents): void {
        $all = self::scales($parents);
        foreach (array_keys($all) as $id) {
            $this->assertSame(
                self::reference_subscales($id, $all),
                self::call('add_subscales', [$id, $all]),
                "subscales of $id"
            );
            $this->assertSame(
                self::reference_parents($id, $all),
                self::call('add_parentscales', [$id, $all]),
                "ancestors of $id"
            );
        }
        $this->assertSame([], self::call('add_subscales', [null, $all]));
        $this->assertSame([], self::call('add_parentscales', [999999, $all]));
    }

    /**
     * A cycle in the data - the previous code recursed until the stack ran out - ends the walk instead.
     */
    public function test_cycle_ends(): void {
        $all = self::scales([1 => 3, 2 => 1, 3 => 2, 4 => 0]);
        $this->assertSame([2, 3], self::call('add_subscales', [1, $all]));
        $this->assertSame([3, 2, 1], self::call('add_parentscales', [1, $all]));
    }
}
