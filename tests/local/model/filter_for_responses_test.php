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

/**
 * filter_for_responses() keeps the same items in the same order, with a set of answered ids (issue #170).
 *
 * Before, every item rebuilt the list of answered ids and searched it (in_array): items times answers.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_catquiz\local\model\model_item_param_list::class)]
final class filter_for_responses_test extends \advanced_testcase {
    /**
     * A list of items with the given question ids.
     *
     * @param array $ids
     * @return model_item_param_list
     */
    private static function items(array $ids): model_item_param_list {
        $list = new model_item_param_list();
        foreach ($ids as $id) {
            $list->add((new model_item_param($id, 'raschbirnbaum'))->set_parameters(['difficulty' => 0.0,
                'discrimination' => 1.0]));
        }
        return $list;
    }

    /**
     * The filter as it was before issue #170.
     *
     * @param model_item_param_list $list
     * @param model_responses $responses
     * @return array Remaining question ids in order.
     */
    private static function reference(model_item_param_list $list, model_responses $responses): array {
        $kept = [];
        foreach (array_keys($list->as_array()) as $itemid) {
            if (in_array($itemid, $responses->get_item_ids())) {
                $kept[] = $itemid;
            }
        }
        return $kept;
    }

    /**
     * 0 to 10,000 items, answers inside and outside the list: the same items in the same order.
     */
    public function test_same_items_kept(): void {
        mt_srand(170);
        foreach ([0, 1, 10, 100, 1000, 10000] as $n) {
            $ids = [];
            for ($i = 0; $i < $n; $i++) {
                $ids[] = 100000 + mt_rand(0, 9) + 10 * $i;
            }
            $answers = [];
            foreach ($ids as $id) {
                if (mt_rand(0, 9) === 0) {
                    $answers[$id] = ['fraction' => (float) mt_rand(0, 1)];
                }
            }
            // Answers to questions that are not in the list.
            $answers[5] = ['fraction' => 1.0];
            $answers[99999999] = ['fraction' => 0.0];
            $responses = model_responses::create_from_array([1 => ['component' => $answers]]);

            $expected = self::reference(self::items($ids), $responses);
            $list = self::items($ids);
            $this->assertSame($list, $list->filter_for_responses($responses));
            $this->assertSame($expected, array_keys($list->as_array()), "n = $n");
        }
    }

    /**
     * No answers leave nothing; every item answered leaves all.
     */
    public function test_edges(): void {
        $none = self::items([1, 2, 3])->filter_for_responses(model_responses::create_from_array([]));
        $this->assertCount(0, $none);
        $all = self::items([1, 2, 3])->filter_for_responses(model_responses::create_from_array([1 => ['component' => [
            1 => ['fraction' => 1.0], 2 => ['fraction' => 0.0], 3 => ['fraction' => 0.5],
        ]]]));
        $this->assertSame([1, 2, 3], array_keys($all->as_array()));
    }

    /**
     * The filter changes the list it is given - never the cached list (issue #170, re-audit 18.11.2026: mutable
     * caches). Two filters of the same cached list in one request, and a read after them, each see the whole list.
     */
    public function test_the_cached_list_is_not_changed(): void {
        $this->resetAfterTest();
        $ids = range(500001, 500050);
        $scales = [71, 72];
        $cache = \cache::make('local_catquiz', 'catquiz_item_params');
        $cache->set(sprintf('itemparams_%s_%s_%s', 33, '', hash('crc32', implode('_', $scales))), self::items($ids));

        $first = model_item_param_list::get(33, null, $scales)->filter_for_responses(
            model_responses::create_from_array([1 => ['component' => [500001 => ['fraction' => 1.0]]]])
        );
        $this->assertSame([500001], array_keys($first->as_array()));
        $second = model_item_param_list::get(33, null, $scales)->filter_for_responses(
            model_responses::create_from_array(
                [1 => ['component' => [500002 => ['fraction' => 0.0], 500003 => ['fraction' => 1.0]]]]
            )
        );
        $this->assertSame([500002, 500003], array_keys($second->as_array()), 'Not the remains of the first filter.');
        $this->assertSame($ids, array_keys(model_item_param_list::get(33, null, $scales)->as_array()), 'The cache holds all.');
    }
}
