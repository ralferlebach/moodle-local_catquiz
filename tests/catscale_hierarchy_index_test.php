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

use local_catquiz\data\dataapi;

/**
 * Issue #169: the index of the scale hierarchy - built once per revision of the scales, invalidated with them.
 *
 * The walks used all scales on every call: a list of every scale and a map of all children, for the ancestors of
 * one item or the subscales of one scale. The index keeps parent and children maps beside the list of scales in the
 * cache 'catscales'; a change of the scales drops both.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquiz\catscale
 */
final class catscale_hierarchy_index_test extends \advanced_testcase {
    /**
     * Files a tree: parents given by position, in shuffled database order, in two contexts.
     *
     * @param int $n Number of scales.
     * @return int[] Position => scale id.
     */
    private function tree(int $n): array {
        global $DB;
        mt_srand(16900 + $n);
        $parents = [1 => 0, 2 => 0];
        for ($i = 3; $i <= $n; $i++) {
            $parents[$i] = mt_rand(0, 9) === 0 ? 0 : mt_rand(1, $i - 1);
        }
        $ids = [];
        foreach ($parents as $position => $parent) {
            $ids[$position] = (int) $DB->insert_record('local_catquiz_catscales', (object) [
                'parentid' => $parent === 0 ? 0 : $ids[$parent], 'name' => "h$position", 'contextid' => 1 + $position % 2,
                'minscalevalue' => -5, 'maxscalevalue' => 5, 'timecreated' => 0, 'timemodified' => 0,
            ]);
        }
        \cache_helper::purge_by_event('changesincatscales');
        return $ids;
    }

    /**
     * The walks as they were before the index: over all scales.
     *
     * @param int $id
     * @return array [subscales, ancestors]
     */
    private static function reference(int $id): array {
        $all = dataapi::get_all_catscales();
        $sub = function (int $parent) use (&$sub, $all): array {
            $result = [];
            foreach ($all as $scale) {
                if ((int) $scale->parentid === $parent) {
                    $result = [...$result, (int) $scale->id, ...$sub((int) $scale->id)];
                }
            }
            return $result;
        };
        $up = [];
        for ($s = $id; (int) $all[$s]->parentid !== 0; $s = (int) $all[$s]->parentid) {
            $up[] = (int) $all[$s]->parentid;
        }
        return [$sub($id), $up];
    }

    /**
     * Sizes: deep and broad trees up to more than 400 scales.
     *
     * @return array
     */
    public static function sizes(): array {
        return ['10 scales' => [10], '120 scales' => [120], '450 scales' => [450]];
    }

    /**
     * The index gives the same subscales in the same order and the same ancestors as the walk over all scales.
     *
     * @dataProvider sizes
     * @param int $n
     */
    public function test_the_index_gives_the_same_hierarchy(int $n): void {
        $this->resetAfterTest();
        $ids = $this->tree($n);
        foreach ($ids as $id) {
            [$subscales, $ancestors] = self::reference($id);
            $this->assertSame($subscales, catscale::get_subscale_ids($id), "subscales of $id");
            $this->assertSame($ancestors, catscale::get_ancestors($id), "ancestors of $id");
        }
        $this->assertSame([], catscale::get_subscale_ids(null));
        $this->assertSame([], catscale::get_ancestors(999999999));
    }

    /**
     * Built once: the walks of a whole tree read the database once at most, and a warm index not at all.
     */
    public function test_built_once_per_revision(): void {
        global $DB;
        $this->resetAfterTest();
        $ids = $this->tree(450);
        $reads = $DB->perf_get_reads();
        foreach ($ids as $id) {
            catscale::get_ancestors($id);
            catscale::get_subscale_ids($id);
        }
        $this->assertLessThanOrEqual(1, $DB->perf_get_reads() - $reads, 'One read of the scales for the whole tree.');
        $reads = $DB->perf_get_reads();
        foreach ($ids as $id) {
            catscale::get_ancestors($id);
        }
        $this->assertSame(0, $DB->perf_get_reads() - $reads, 'Warm: no read.');
    }

    /**
     * A change of the scales - a new scale, a scale moved, a scale deleted - is in the index at once.
     */
    public function test_a_change_of_the_scales_is_in_the_index(): void {
        global $DB;
        $this->resetAfterTest();
        $ids = $this->tree(30);
        catscale::get_subscale_ids($ids[1]);

        // Moved: the last scale under the first.
        $DB->set_field('local_catquiz_catscales', 'parentid', $ids[1], ['id' => $ids[30]]);
        \cache_helper::purge_by_event('changesincatscales');
        $this->assertContains($ids[30], catscale::get_subscale_ids($ids[1]));
        $this->assertSame($ids[1], catscale::get_ancestors($ids[30])[0]);
        $this->assertSame(self::reference($ids[1])[0], catscale::get_subscale_ids($ids[1]));

        // New, through the API of the plugin.
        $new = dataapi::create_catscale(new \local_catquiz\data\catscale_structure([
            'name' => 'new', 'parentid' => $ids[2], 'minscalevalue' => -5, 'maxscalevalue' => 5,
            'timecreated' => time(), 'timemodified' => time(),
        ]));
        $this->assertContains((int) $new, catscale::get_subscale_ids($ids[2]));

        // A purge of the list of scales alone drops the index with it.
        $DB->set_field('local_catquiz_catscales', 'parentid', 0, ['id' => $ids[30]]);
        \cache::make('local_catquiz', 'catscales')->purge();
        $this->assertNotContains($ids[30], catscale::get_subscale_ids($ids[1]));
        $this->assertSame([], catscale::get_ancestors($ids[30]));
    }

    /**
     * A cycle in the data is refused by the index as by the walk (issue #169).
     */
    public function test_a_cycle_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $ids = $this->tree(5);
        $DB->set_field('local_catquiz_catscales', 'parentid', $ids[1], ['id' => $ids[1]]);
        \cache_helper::purge_by_event('changesincatscales');
        $this->expectException(\moodle_exception::class);
        catscale::get_ancestors($ids[1]);
    }
}
